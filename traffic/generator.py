"""Erzeugt Besuche und E-Commerce-Ereignisse über die Matomo-Tracking-API.

Liest Produkte aus catalog.json, damit Tracking-Daten und Shop konsistent sind.
Die URLs, SKUs (`wc_<id>`), Produktnamen und die Kategorie „Kosmetik" entsprechen
exakt dem realen WooCommerce-Shop, damit Matomo dieselbe Struktur zeigt.
Kennt keine Web-Belange – wird von app.py aufgerufen.
"""
import json
import os
import random
import threading
import time
import uuid
from datetime import datetime, timedelta
from urllib.parse import quote

import requests

MATOMO_URL = os.environ.get("MATOMO_INTERNAL_URL", "http://matomo")
ID_SITE = int(os.environ.get("MATOMO_ID_SITE", "1"))
CATALOG_PATH = os.environ.get("CATALOG_PATH", "/seed/catalog.json")
TOKEN_FILE = os.environ.get("MATOMO_TOKEN_FILE", "/token/token_auth")
SHOP_BASE_DEFAULT = os.environ.get("SHOP_BASE_URL", "http://localhost:8090")
# Interne WordPress-URL fuer den Produkt-Sync (Container-Netz).
WP_INTERNAL_URL = os.environ.get("WORDPRESS_INTERNAL_URL", "http://wordpress").rstrip("/")


# --- A/B-Test ---------------------------------------------------------------
# Jeder Besuch wird einer Variante (A/B) zugewiesen und in der Matomo-Custom-
# Dimension 1 ("AB-Variante") getrackt. Variante B konvertiert leicht besser
# (AB_CONV_FACTOR_B) – so zeigt der A/B-Bericht einen echten Unterschied.
def _ab_float(name, default):
    raw = (os.environ.get(name) or "").split("#", 1)[0].strip()
    try:
        return float(raw)
    except ValueError:
        return default


AB_ENABLED = (os.environ.get("M392_AB_TEST_ENABLED", "true").split("#", 1)[0].strip().lower()
              == "true")
AB_SPLIT_B = _ab_float("M392_AB_SPLIT_B", 50.0)          # Prozent der Besuche -> Shop-Variante
AB_CONV_FACTOR_B = _ab_float("M392_AB_CONV_FACTOR_B", 1.25)  # Conversion-Multiplikator Shop-Variante
AB_DIMENSION = 1                                          # Matomo Custom-Dimension-Index

# Varianten-Namen = Dimensionswerte in Matomo (sauber lesbar) + Shop-Landing-URL.
VARIANT_A = "Original"            # /shop/
VARIANT_B = "Shop-Variante"       # /shop-variante/


def _pick_variant():
    """Besuchs-Variante wählen (Split AB_SPLIT_B; oder None, wenn A/B deaktiviert)."""
    if not AB_ENABLED:
        return None
    return VARIANT_B if (random.random() * 100.0) < AB_SPLIT_B else VARIANT_A


# Im gekoppelten Modus konvertiert der Backfill nicht (Käufe kommen aus den
# gespiegelten Bestellungen). Damit die Shop-Variante trotzdem die bessere
# Conversion-Rate zeigt, werden die BESTELLUNGEN leicht häufiger der Shop-Variante
# zugeordnet – exakt so, dass bei gleichem Besuchs-Split CR_B/CR_A = AB_CONV_FACTOR_B.
#   p_o = f·p_v / ((1-p_v) + f·p_v)
_p_v = max(0.0, min(1.0, AB_SPLIT_B / 100.0))
AB_ORDER_SPLIT_B = (100.0 * AB_CONV_FACTOR_B * _p_v / ((1.0 - _p_v) + AB_CONV_FACTOR_B * _p_v)
                    if AB_ENABLED else 0.0)


def _pick_order_variant():
    """Bestellungs-Variante wählen (leicht zugunsten der Shop-Variante gewichtet,
    damit deren Conversion-Rate um AB_CONV_FACTOR_B höher ausfällt)."""
    if not AB_ENABLED:
        return None
    return VARIANT_B if (random.random() * 100.0) < AB_ORDER_SPLIT_B else VARIANT_A


def _shop_landing_path(variant):
    """Shop-Einstiegs-URL je Variante."""
    return "/shop-variante/" if variant == VARIANT_B else "/shop/"

# Verbindungs-Pool pro Thread: requests.Session ist NICHT thread-sicher, und
# Backfill + Live-Tropf laufen in eigenen Threads. Thread-lokale Sessions behalten
# den Pool-Vorteil (viele Requests beim Backfill) ohne geteilten Zustand.
_THREAD_LOCAL = threading.local()


def _session():
    s = getattr(_THREAD_LOCAL, "session", None)
    if s is None:
        s = requests.Session()
        _THREAD_LOCAL.session = s
    return s

# Live-Produkte des Shops (gecacht), damit Matomo-Daten und realer Shop
# deckungsgleich bleiben und neu angelegte Produkte automatisch beruecksichtigt
# werden. Faellt bei Nichterreichbarkeit auf catalog.json zurueck.
_PRODUCT_CACHE = {"ts": 0.0, "data": None}
_PRODUCT_TTL = 300.0


def _fetch_live_products(force=False):
    """Holt die Live-Produktliste vom Shop (gecacht ~5 min; force=True erneuert
    den Cache sofort – z. B. via Sync im Produkte-Tab). None bei Fehler."""
    now = time.time()
    cached = _PRODUCT_CACHE["data"]
    if not force and cached is not None and (now - _PRODUCT_CACHE["ts"]) < _PRODUCT_TTL:
        return cached
    try:
        r = _session().get(f"{WP_INTERNAL_URL}/wp-json/m392/v1/products", timeout=10)
        if r.status_code == 200:
            prods = r.json().get("products", [])
            if prods:
                _PRODUCT_CACHE["data"] = prods
                _PRODUCT_CACHE["ts"] = now
                return prods
    except (requests.RequestException, ValueError):
        pass
    return cached  # ggf. None -> Aufrufer nutzt catalog.json

# Akquise-Kanaele: bestimmen den Einstiegs-Referrer (urlref der ersten Aktion)
# UND einen Conversion-Multiplikator. Social Media ist bewusst der staerkste
# Verkaufskanal (hoher Besuchsanteil + ueberdurchschnittliche Conversion). Die
# Multiplikatoren sind so normiert, dass der gewichtete Schnitt = 1 bleibt – die
# im Dashboard eingestellte Conversion-Rate stimmt also im Mittel weiterhin.
_RAW_CHANNELS = [
    # name, Besuchsanteil, roher Conv.-Mult., kind, gewichtete Einstiege.
    #   kind="ref"      -> Eintrag ist eine Referrer-URL (urlref; Bericht „Webseiten"
    #                      bzw. „Suchmaschinen"/„Soziale Netzwerke" je nach Domain)
    #   kind="campaign" -> Eintrag ist ein Kampagnenname (Bericht „Kampagnen")
    ("social",     0.33, 2.00, "ref", [
        ("https://www.instagram.com/", 0.40), ("https://l.instagram.com/", 0.10),
        ("https://www.facebook.com/",  0.18), ("https://www.pinterest.com/", 0.20),
        ("https://www.tiktok.com/",    0.12)]),
    ("search",     0.24, 0.85, "ref", [
        ("https://www.google.com/",   0.80), ("https://www.bing.com/",   0.09),
        ("https://duckduckgo.com/",   0.06), ("https://www.ecosia.org/", 0.05)]),
    ("direct",     0.18, 0.75, "ref", [("", 1.0)]),
    # Newsletter realistisch als Kampagne (statt Fake-Webseite) -> Bericht „Kampagnen".
    ("newsletter", 0.11, 1.20, "campaign", [
        ("Newsletter Juni-Aktion", 0.45), ("Newsletter Neuheiten", 0.32),
        ("Newsletter Pflege-Tipps", 0.23)]),
    # Verweis-Webseiten: diverse, realistische Quellen mit EINER klar dominanten
    # (naturkosmetik-magazin.de) und einem langen Schwanz. (Fiktive Domains.)
    ("referral",   0.14, 0.55, "ref", [
        ("https://naturkosmetik-magazin.de/", 0.34),
        ("https://www.beautyjunkies.de/",     0.17),
        ("https://inci-check.de/",            0.13),
        ("https://www.vegan-leben.de/",       0.11),
        ("https://schminktipps.de/",          0.09),
        ("https://oeko-ratgeber.de/",         0.07),
        ("https://spartipps.de/",             0.06),
        ("https://kosmetik-forum.de/",        0.03)]),
]
_CH_AVG = (sum(w * m for _, w, m, _, _ in _RAW_CHANNELS)
           / sum(w for _, w, m, _, _ in _RAW_CHANNELS))
CHANNELS = [{"name": n, "weight": w, "mult": m / _CH_AVG, "kind": k, "entries": e}
            for n, w, m, k, e in _RAW_CHANNELS]
_CH_WEIGHTS = [c["weight"] for c in CHANNELS]
USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile",
    "Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130 Mobile Safari/537.36",
]

# --- Geografie -------------------------------------------------------------
# Verkaufsländer DE/CH/AT (können bestellen) + ~5 % „übriges Europa" (sehen
# Produkte an, legen in den Warenkorb, können aber NICHT bestellen – WooCommerce
# akzeptiert nur DE/CH/AT). Anteile unter den Käufer:innen ≈ DE 65 / CH 20 / AT 15.
# (weight, country, lang, can_purchase, [(stadt, region-code)])
_GEO = [
    (0.62, "de", "de-DE", True, [("Berlin", "BE"), ("Hamburg", "HH"), ("München", "BY"),
                                 ("Köln", "NW"), ("Stuttgart", "BW"),
                                 ("Leipzig", "SN"), ("Frankfurt am Main", "HE")]),
    (0.19, "ch", "de-CH", True, [("Zürich", "ZH"), ("Genève", "GE"), ("Basel", "BS"), ("Bern", "BE"),
                                 ("Lausanne", "VD"), ("Winterthur", "ZH")]),
    (0.14, "at", "de-AT", True, [("Wien", "9"), ("Graz", "6"), ("Linz", "4"), ("Salzburg", "5"),
                                 ("Innsbruck", "7")]),
]
_GEO_WEIGHTS = [w for w, *_ in _GEO]
_EU_OTHER = [("fr", "fr-FR", "Paris"), ("it", "it-IT", "Milano"), ("nl", "nl-NL", "Amsterdam"),
             ("be", "nl-BE", "Antwerpen"), ("es", "es-ES", "Madrid"), ("pl", "pl-PL", "Warszawa"),
             ("se", "sv-SE", "Stockholm")]
_EU_OTHER_WEIGHT = 0.05


def _pick_geo():
    """Land/Sprache/Stadt eines Besuchs + ob das Land bestellen darf."""
    if random.random() < _EU_OTHER_WEIGHT:
        c, lang, city = random.choice(_EU_OTHER)
        return {"country": c, "lang": lang, "city": city, "region": "", "can_purchase": False}
    _w, country, lang, can_purchase, cities = random.choices(_GEO, weights=_GEO_WEIGHTS, k=1)[0]
    city, region = random.choice(cities)
    return {"country": country, "lang": lang, "city": city, "region": region, "can_purchase": can_purchase}


def _perf():
    """Realistische Seitenleistungs-Timings (ms) für *Verhalten → Leistung*."""
    return {
        "pf_net": random.randint(10, 90), "pf_srv": random.randint(60, 380),
        "pf_tfr": random.randint(8, 70), "pf_dm1": random.randint(90, 520),
        "pf_dm2": random.randint(40, 260), "pf_onl": random.randint(20, 180),
    }


# --- Ereignisse (Verhalten → Ereignisse) -----------------------------------
# (Gewicht, Kategorie, Aktion, name_art)  name_art: None|product|social|sort|video
_EVENTS = [
    (0.26, "Newsletter", "Anmeldung", None),
    (0.22, "Produkt", "Auf Wunschliste", "product"),
    (0.18, "Social Share", "Teilen", "social"),
    (0.16, "Video", "Play", "video"),
    (0.10, "Produkt", "Größentabelle geöffnet", None),
    (0.08, "Filter", "Sortierung geändert", "sort"),
]
_EVENT_WEIGHTS = [w for w, *_ in _EVENTS]
_SOCIAL = ["Instagram", "Facebook", "Pinterest", "TikTok", "WhatsApp"]
_SORTS = ["Beliebtheit", "Preis aufsteigend", "Bewertung", "Neuheiten"]
_VIDEOS = ["Anwendung: Reinigung", "Routine-Tipp: Feuchtigkeit", "Tutorial: Make-up"]

# --- Inhalte (Verhalten → Inhalte: Banner/Promo-Blöcke) --------------------
# (Inhaltsname, Inhaltsteil/Asset, Ziel-URL-Pfad)
_CONTENT = [
    ("Hero: Naturkosmetik-Aktion", "banner-aktion.jpg", "/shop/"),
    ("Promo: Gratisversand ab 50 €", "banner-versand.jpg", "/shop/"),
    ("Neu im Sortiment", "banner-neu.jpg", "/product-category/gesichtspflege/"),
    ("Bestseller-Empfehlung", "block-bestseller.jpg", "/product-category/gesichtsreinigung/"),
    ("Newsletter-Block", "block-newsletter", "/danke/"),
]


# Beliebtheits-Gewichte (0..100 je SKU), gesteuert über den Produkte-Tab des
# Dashboards. Überschreiben die popularity aus catalog.json und wirken damit auf
# Produktansichten UND Warenkörbe. Atomare Dict-Zuweisung -> kein Lock nötig.
_WEIGHTS = {}


def set_weight_overrides(weights):
    """Dashboard-Gewichte übernehmen ({sku: 0..100}; leer = Katalog-Gewichtung)."""
    global _WEIGHTS
    _WEIGHTS = dict(weights or {})


def _apply_weights(catalog):
    """Gewichte auf die popularity anwenden. Gewicht 0 = Ladenhüter (praktisch
    nie); ein kleiner Floor verhindert eine Null-Gesamtsumme bei random.choices."""
    if not _WEIGHTS:
        return catalog
    for pr in catalog.get("products", []):
        w = _WEIGHTS.get(pr.get("sku"))
        if w is not None:
            pr["popularity"] = max(0.05, float(w))
    return catalog


def _load_catalog(apply_weights=True):
    """catalog.json (Meta) + Live-Produkte aus WooCommerce.

    Produkte/Kategorien werden – wenn der Shop erreichbar ist – live uebernommen
    (echte SKU `wc_<id>`, voller Name, echter Preis, echte Kategorie). So stimmen
    die Matomo-Daten exakt mit dem Shop ueberein und neue Produkte sind automatisch
    dabei. Die `popularity` (Bestseller-Gewichtung) kommt aus catalog.json je SKU
    (unbekannte/neue Produkte erhalten einen mittleren Default) und wird – falls
    gesetzt – von den Dashboard-Gewichten (Produkte-Tab) überschrieben. Faellt der
    Sync aus, gelten die statischen catalog.json-Produkte.
    """
    with open(CATALOG_PATH, encoding="utf-8") as f:
        catalog = json.load(f)

    live = _fetch_live_products()
    if not live:
        return _apply_weights(catalog) if apply_weights else catalog

    pops = {p.get("sku"): p.get("popularity") for p in catalog.get("products", [])}
    known = sorted(v for v in pops.values() if isinstance(v, (int, float)))
    default_pop = known[len(known) // 2] if known else 20      # Median bekannter Produkte

    products, cats = [], {}
    for p in live:
        sku = p.get("sku")
        products.append({
            "sku": sku,
            "slug": p.get("slug", ""),
            "name": p.get("name", ""),
            "price": float(p.get("price") or 0),
            "category": p.get("category", ""),
            "category_slug": p.get("category_slug", ""),
            "popularity": pops.get(sku) or default_pop,
        })
        if p.get("category"):
            cats[p["category"]] = {"name": p["category"], "slug": p.get("category_slug", "")}

    catalog = dict(catalog)
    catalog["products"] = products
    if cats:
        catalog["categories"] = list(cats.values())
    return _apply_weights(catalog) if apply_weights else catalog


def product_overview(fresh=False):
    """Produktliste für den Produkte-Tab des Dashboards: Stammdaten, bisherige
    WooCommerce-Verkäufe und das Default-Gewicht (aus der Katalog-Gewichtung
    abgeleitet, stärkstes Produkt = 100; relative Verhältnisse bleiben erhalten).

    fresh=True umgeht den Produkt-Cache: Verkaufszahlen sind sofort aktuell und
    NEUE WordPress-Produkte erscheinen unmittelbar – auch im Traffic, denn der
    erneuerte Cache gilt ebenso für die Besuchs-/Warenkorb-Generierung."""
    live = _fetch_live_products(force=fresh) or []
    base = _load_catalog(apply_weights=False)
    sales = {p.get("sku"): int(p.get("total_sales") or 0) for p in live}
    prods = base.get("products", [])
    maxp = max((float(pr.get("popularity") or 1) for pr in prods), default=1.0)
    out = []
    for pr in prods:
        sku = pr.get("sku")
        if not sku:
            continue
        out.append({
            "sku": sku,
            "name": pr.get("name", ""),
            "price": float(pr.get("price") or 0),
            "category": pr.get("category", ""),
            "total_sales": sales.get(sku, 0),
            "default_weight": max(1, int(round(float(pr.get("popularity") or 1) / maxp * 100))),
        })
    return out


def _base_url(catalog):
    return (catalog.get("shop_base_url") or SHOP_BASE_DEFAULT).rstrip("/")


def _category_list(catalog):
    """Kategorien immer als Liste von {name, slug} normalisieren."""
    out = []
    for c in catalog.get("categories", []):
        if isinstance(c, dict):
            out.append({"name": c.get("name", ""), "slug": c.get("slug", "")})
        else:  # Rückwärtskompatibilität: einfacher String
            out.append({"name": c, "slug": str(c).lower().replace(" ", "-")})
    return out


def _read_token():
    try:
        with open(TOKEN_FILE, encoding="utf-8") as f:
            return f.read().strip()
    except OSError:
        return ""


def matomo_installed():
    """True, wenn Matomo erreichbar UND installiert ist (Tracker antwortet)."""
    try:
        resp = _session().get(f"{MATOMO_URL}/matomo.php", timeout=10)
        return resp.status_code in (200, 204)
    except requests.RequestException:
        return False


def token_ready():
    """True, wenn ein plausibler 32-stelliger Hex-Token vorliegt."""
    token = _read_token()
    return bool(token) and len(token) == 32 and all(c in "0123456789abcdef" for c in token)


def wait_for_ready(timeout=300, interval=3):
    """Wartet, bis Matomo installiert ist UND ein gueltiger Token vorliegt."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        if matomo_installed() and token_ready():
            return True
        time.sleep(interval)
    return False


def _base_params(catalog, visitor_id, ua, when=None, urlref="", geo=None):
    params = {
        "idsite": ID_SITE, "rec": 1, "apiv": 1,
        "_id": visitor_id, "rand": random.randint(1, 10_000_000),
        "ua": ua, "lang": (geo or {}).get("lang", "de-DE"),
        "res": random.choice(["1920x1080", "1440x900", "390x844", "412x915"]),
        "url": _base_url(catalog) + "/", "urlref": urlref,
    }
    token = _read_token()
    if when is not None:
        params["cdt"] = when.strftime("%Y-%m-%d %H:%M:%S")
        if token:
            params["token_auth"] = token
    # Land/Region/Stadt setzen (Override braucht token_auth – im Backfill vorhanden;
    # im Live-Tropf ohne Token nähert Matomo das Land über `lang` an).
    if geo and token:
        params["country"] = geo["country"]
        if geo.get("region"):
            params["region"] = geo["region"]
        if geo.get("city"):
            params["city"] = geo["city"]
        params["token_auth"] = token
    return params


def _send(params):
    # Kampagnen-Einstieg: pk_campaign an die Landing-URL haengen (Matomo erkennt
    # Kampagnen aus der URL und strippt den Parameter aus dem Seitenbericht).
    camp = params.pop("__campaign", None)
    if camp:
        sep = "&" if "?" in params.get("url", "") else "?"
        params["url"] = params.get("url", "") + sep + "pk_campaign=" + quote(camp)
    # Kleiner Retry: unter Last (grosser Backfill) liefert Matomo gelegentlich
    # transiente 4xx/5xx – einmal kurz warten und erneut versuchen.
    last = None
    for attempt in range(3):
        try:
            resp = _session().get(f"{MATOMO_URL}/matomo.php", params=params, timeout=20)
            resp.raise_for_status()
            return resp
        except requests.RequestException as exc:
            last = exc
            time.sleep(0.4 * (attempt + 1))
    raise last


def _advance(when):
    """Verschiebt den historischen Zeitstempel um ein paar Sekunden (oder None)."""
    if when is None:
        return None
    return when + timedelta(seconds=random.randint(20, 90))


def _search_keywords(catalog):
    """Such-Stichwoerter: kuratierte Liste aus catalog, sonst aus Produktnamen abgeleitet."""
    if catalog.get("search_terms"):
        return list(catalog["search_terms"])
    words = set()
    for cat in _category_list(catalog):
        words.add(cat["name"].lower())
    for pr in catalog.get("products", []):
        for token in pr["name"].replace("-", " ").split():
            token = token.strip().lower()
            if len(token) >= 4:
                words.add(token)
    return sorted(words)


def _search_result_count(catalog, keyword):
    """Grobe Trefferzahl: Produkte, deren Name/Kategorie/Slug das Wort enthaelt."""
    kw = keyword.lower()
    if kw in ("kosmetik", "cosmetics", "gesichtspflege"):
        return len(catalog.get("products", []))
    count = 0
    for pr in catalog.get("products", []):
        haystack = (pr["name"] + " " + pr.get("category", "") + " " + pr.get("slug", "")).lower()
        if kw in haystack:
            count += 1
    return count


def _pick_channel():
    """Akquise-Kanal samt gewichtetem Einstieg (Referrer-URL oder Kampagne) waehlen."""
    c = random.choices(CHANNELS, weights=_CH_WEIGHTS, k=1)[0]
    entries = c["entries"]
    pick = random.choices([e[0] for e in entries], weights=[e[1] for e in entries], k=1)[0]
    return {"name": c["name"], "kind": c["kind"], "ref": pick, "mult": c["mult"]}


def simulate_visit(catalog, when=None, force_purchase=False, conversion_rate=0.04,
                   defer_purchase=False):
    """Ein kompletter Besucherpfad. Gibt dict mit Kennzahlen zurück.

    defer_purchase=True (Live-Tropf): Bei einem Kauf wird die E-Commerce-
    Conversion NICHT sofort gesendet, sondern als `pending` zurückgegeben
    (Warenkorb + vorbereitete Tracking-Parameter desselben Besuchs). Der
    Aufrufer legt zuerst die echte WooCommerce-Bestellung mit GENAU diesem
    Warenkorb an und schließt dann mit complete_purchase(...) ab – so zeigen
    Matomo und WooCommerce dieselbe Bestellung (Artikel + Produktumsatz);
    `revenue` bleibt in dem Fall 0 und wird erst beim Abschluss verbucht."""
    visitor_id = uuid.uuid4().hex[:16]
    ua = random.choice(USER_AGENTS)
    base = _base_url(catalog)
    products = catalog["products"]
    categories = _category_list(catalog)
    channel = _pick_channel()
    geo = _pick_geo()
    # Erzwungene Käufe müssen aus einem Verkaufsland (DE/CH/AT) kommen.
    while force_purchase and not geo["can_purchase"]:
        geo = _pick_geo()
    # Kanalabhaengige Conversion (Social konvertiert ueberdurchschnittlich).
    eff_cr = min(0.95, conversion_rate * channel["mult"])
    # A/B-Variante zuweisen; die Shop-Variante konvertiert leicht besser.
    variant = _pick_variant()
    if variant == VARIANT_B:
        eff_cr = min(0.95, eff_cr * AB_CONV_FACTOR_B)

    pages = 0
    # Einstiegs-Referrer traegt nur die ERSTE Aktion des Besuchs (Matomo nutzt den
    # Referrer der Einstiegsseite zur Kanal-Zuordnung); Folgeaktionen sind intern.
    _entry = {"ref": channel["ref"], "kind": channel["kind"]}

    def mkparams():
        if _entry["kind"] == "campaign" and _entry["ref"]:
            # Kampagne: kein Webseiten-Referrer; der Kampagnenname wird in _send
            # als pk_campaign an die URL der ersten Aktion gehaengt -> Bericht
            # „Akquisition -> Kampagnen".
            p = _base_params(catalog, visitor_id, ua, when, urlref="", geo=geo)
            p["__campaign"] = _entry["ref"]
        else:
            p = _base_params(catalog, visitor_id, ua, when, urlref=_entry["ref"], geo=geo)
        _entry["ref"] = ""
        if variant:
            p["dimension%d" % AB_DIMENSION] = variant
        return p

    # --- Shop-Einstieg (A/B): Original /shop/ vs. Shop-Variante /shop-variante/
    # Die Landing-Variante ist das, was wir A/B-testen; die übrigen Schritte sind
    # für beide Varianten gleich (Vergleich per Dimension/Segment).
    if variant:
        p = mkparams()
        p["url"] = f"{base}{_shop_landing_path(variant)}"
        p["action_name"] = "Shop: " + variant
        p.update(_perf())
        _send(p)
        pages += 1
        when = _advance(when)

    # --- Optionaler Einstieg ueber eine On-Site-Suche (~30%) ----------------
    if random.random() < 0.30:
        keyword = random.choice(_search_keywords(catalog))
        count = _search_result_count(catalog, keyword)
        p = mkparams()
        p["url"] = f"{base}/?s={keyword.replace(' ', '+')}&post_type=product"
        # KEIN action_name: Matomo nutzt die Suche als Aktion.
        p["search"] = keyword
        p["search_count"] = count
        if count > 0 and random.random() < 0.5 and categories:
            p["search_cat"] = random.choice(categories)["name"]
        _send(p)
        pages += 1
        when = _advance(when)

    # --- 1-2 Kategorie-Ansichten (mit _pkc) ---------------------------------
    cat_views = random.sample(categories, min(random.randint(1, 2), len(categories))) if categories else []
    for cat in cat_views:
        p = mkparams()
        p["url"] = f"{base}/product-category/{cat['slug']}/"
        p["action_name"] = f"Kategorie: {cat['name']}"
        p["_pkc"] = cat["name"]
        p.update(_perf())
        _send(p)
        pages += 1
        when = _advance(when)

    # --- 1-4 Produkt-Ansichten (gewichtet nach popularity, mit _pks/_pkn/_pkc/_pkp)
    weights = [pr.get("popularity", 1) for pr in products]
    n_products = random.randint(1, 4)
    viewed_products = []
    for _ in range(n_products):
        pr = random.choices(products, weights=weights, k=1)[0]
        viewed_products.append(pr)
        p = mkparams()
        p["url"] = f"{base}/product/{pr['slug']}/"
        p["action_name"] = pr["name"]
        p["_pks"] = pr["sku"]
        p["_pkn"] = pr["name"]
        p["_pkc"] = pr["category"]
        p["_pkp"] = pr["price"]
        p.update(_perf())
        _send(p)
        pages += 1
        when = _advance(when)

    # --- Funnel: Produkt → Warenkorb → Kasse → Kauf -------------------------
    # Käufer:innen durchlaufen den ganzen Trichter; Nicht-Käufer brechen unterwegs
    # ab. „Übriges Europa" kann NICHT bestellen (nur DE/CH/AT), legt aber öfter in
    # den Korb – sichtbarer Abbruch. Die Schritte (/cart/, /checkout/, /order-
    # received/) füllen die Funnel-Ziele in Matomo; die Conversion-Rate bleibt eff_cr.
    can_buy = geo.get("can_purchase", True)
    purchased = (force_purchase or (random.random() < eff_cr)) and can_buy
    revenue = 0.0
    pending = None
    if viewed_products:
        if purchased:
            reach_cart = reach_checkout = True
        else:
            reach_cart = random.random() < (0.70 if not can_buy else 0.30)
            reach_checkout = reach_cart and (random.random() < 0.45)

        if reach_cart:
            if purchased and random.random() < 0.7:
                pool = viewed_products
            else:
                pool = viewed_products or products
            cart = random.choices(pool, weights=[pr.get("popularity", 1) for pr in pool],
                                  k=random.randint(1, 3))
            items, subtotal = [], 0.0
            for pr in cart:
                qty = random.randint(1, 2)
                subtotal += pr["price"] * qty
                items.append([pr["sku"], pr["name"], pr["category"], pr["price"], qty])

            # Schritt 2: Warenkorb als PAGEVIEW (damit das URL-Ziel /cart/ matcht;
            # Matomo wertet URL-Ziele nur für Seitenaufrufe aus, nicht für E-Commerce).
            p = mkparams()
            p["url"] = f"{base}/cart/"
            p["action_name"] = "Warenkorb"
            _send(p)
            pages += 1
            when = _advance(when)
            if not purchased:
                # Zusätzlich Cart-Update (E-Commerce) → „abgebrochene Warenkörbe".
                c = mkparams()
                c["url"] = f"{base}/cart/"
                c["idgoal"] = 0
                c["ec_items"] = json.dumps(items, ensure_ascii=False)
                c["revenue"] = round(subtotal, 2)
                _send(c)

            if reach_checkout:
                # Schritt 3: Kasse (Pageview → Funnel-Ziel)
                p = mkparams()
                p["url"] = f"{base}/checkout/"
                p["action_name"] = "Kasse"
                p.update(_perf())
                _send(p)
                pages += 1
                when = _advance(when)

                if purchased:
                    # Schritt 4: Kauf – Pageview (→ Funnel-Ziel) + E-Commerce-Bestellung.
                    # Umsatz = PRODUKTUMSATZ ohne Versand (= WC-„Bruttoumsatz"), damit
                    # Matomo-Gesamteinnahmen und WooCommerce dieselben Zahlen zeigen
                    # (gleiche Konvention wie die gebackene Fixture).
                    p = mkparams()
                    p["url"] = f"{base}/checkout/order-received/"
                    p["action_name"] = "Bestellbestätigung"
                    _send(p)
                    pages += 1
                    o = mkparams()
                    o["url"] = f"{base}/checkout/order-received/"
                    o["idgoal"] = 0
                    o["ec_id"] = uuid.uuid4().hex[:12]
                    if defer_purchase:
                        # Conversion aufschieben: erst die echte WC-Bestellung mit
                        # diesem Warenkorb anlegen, dann complete_purchase(...).
                        pending = {"params": o, "items": items,
                                   "subtotal": round(subtotal, 2)}
                    else:
                        revenue = round(subtotal, 2)
                        o["revenue"] = revenue
                        o["ec_st"] = round(subtotal, 2)
                        o["ec_items"] = json.dumps(items, ensure_ascii=False)
                        _send(o)

    # --- Gelegentlicher PDF-Download (Blog: INCI-Leitfaden) -----------------
    # ~3,5 % der Besuche laden den Leitfaden herunter. Matomo verbucht das als
    # Datei-Download → loest das Ziel „PDF-Download: INCI-Leitfaden" aus.
    pdf = catalog.get("pdf_download_url")
    if pdf and random.random() < 0.035:
        p = mkparams()
        p["url"] = catalog.get("blog_url") or (base + "/")
        p["action_name"] = "Naturkosmetik verstehen: Was steckt wirklich drin?"
        p["download"] = pdf
        _send(p)
        pages += 1

    # --- Gelegentliche Kontaktanfrage (Kontaktformular -> Danke-Seite) -------
    # ~2 % der Besuche senden das Kontaktformular ab: erst die Kontaktseite,
    # dann die Bestaetigungsseite /danke/. Matomo verbucht den Aufruf von
    # /danke/ als Ziel „Kontaktanfrage (Danke-Seite)".
    thankyou = catalog.get("thankyou_url")
    if thankyou and not purchased and random.random() < 0.02:
        contact = catalog.get("contact_url") or (base + "/kontakt/")
        p = mkparams(); p["url"] = contact; p["action_name"] = "Kontakt"
        _send(p); pages += 1
        when = _advance(when)
        p = mkparams(); p["url"] = thankyou; p["action_name"] = "Vielen Dank"
        _send(p); pages += 1

    # --- Content-Impression/-Klick (Verhalten → Inhalte) --------------------
    # ~45 % der Besuche sehen einen Promo-/Hero-Block; ein Teil davon klickt.
    if random.random() < 0.45:
        name, piece, target = random.choice(_CONTENT)
        p = mkparams()
        p["url"] = base + "/"
        p["c_n"] = name
        p["c_p"] = piece
        p["c_t"] = base + target
        if random.random() < 0.18:          # Interaktion (Klick) auf den Block
            p["c_i"] = "click"
        _send(p)

    # --- Ereignisse (Verhalten → Ereignisse) --------------------------------
    # 0–2 sinnvolle Interaktionen je Besuch (Newsletter, Wunschliste, Teilen …).
    for _ in range(random.choices([0, 1, 2], weights=[0.5, 0.38, 0.12])[0]):
        _w, e_cat, e_act, kind = random.choices(_EVENTS, weights=_EVENT_WEIGHTS, k=1)[0]
        when = _advance(when)
        p = mkparams()
        p["url"] = base + "/"
        p["e_c"] = e_cat
        p["e_a"] = e_act
        if kind == "product" and viewed_products:
            p["e_n"] = random.choice(viewed_products)["name"]
        elif kind == "social":
            p["e_n"] = random.choice(_SOCIAL)
        elif kind == "sort":
            p["e_n"] = random.choice(_SORTS)
        elif kind == "video":
            p["e_n"] = random.choice(_VIDEOS); p["e_v"] = random.randint(5, 120)
        _send(p)

    return {"pages": pages, "purchase": purchased, "revenue": revenue, "pending": pending}


def generate_visits(count, conversion_rate=0.04, when=None, defer_purchases=False):
    catalog = _load_catalog()
    summary = {"visits": 0, "purchases": 0, "revenue": 0.0}
    pending = []
    for _ in range(count):
        r = simulate_visit(catalog, when=when, conversion_rate=conversion_rate,
                           defer_purchase=defer_purchases)
        summary["visits"] += 1
        summary["purchases"] += 1 if r["purchase"] else 0
        summary["revenue"] = round(summary["revenue"] + r["revenue"], 2)
        if r.get("pending"):
            pending.append(r["pending"])
    if defer_purchases:
        summary["pending"] = pending
    return summary


def generate_orders(count, when=None, defer_purchases=False):
    """Erzwingt `count` Käufe (jeweils mit vorausgehendem Besuch).

    Jeder erzwungene Kauf hat einen Besuch – daher zählt `visits` mit, damit das
    Dashboard manuelle Käufe korrekt als Besuche verbucht (Conversion bleibt stimmig).
    """
    catalog = _load_catalog()
    summary = {"visits": 0, "purchases": 0, "revenue": 0.0}
    pending = []
    for _ in range(count):
        r = simulate_visit(catalog, when=when, force_purchase=True,
                           defer_purchase=defer_purchases)
        summary["visits"] += 1
        summary["purchases"] += 1
        summary["revenue"] = round(summary["revenue"] + r["revenue"], 2)
        if r.get("pending"):
            pending.append(r["pending"])
    if defer_purchases:
        summary["pending"] = pending
    return summary


def complete_purchase(pending, revenue=None):
    """Schließt einen aufgeschobenen Kauf ab: sendet die E-Commerce-Conversion
    im selben Besuch (gleiche Besucher-ID/Parameter wie der Kauf-Trichter).

    `revenue` = echter Produktumsatz der angelegten WooCommerce-Bestellung
    (ohne Versand); ohne Wert gilt der simulierte Warenkorb als Fallback
    (z. B. wenn keine WC-Bestellungen angelegt werden). Gibt den verbuchten
    Umsatz zurück."""
    o = dict(pending["params"])
    rev = round(float(revenue if revenue is not None else pending["subtotal"]), 2)
    o["revenue"] = rev
    o["ec_st"] = round(sum(float(it[3]) * int(it[4]) for it in pending["items"]), 2)
    o["ec_items"] = json.dumps(pending["items"], ensure_ascii=False)
    _send(o)
    return rev


def track_ecommerce_order(ts, revenue, items, catalog=None):
    """Spiegelt eine bestehende (WooCommerce-)Bestellung als Matomo-E-Commerce-
    Conversion – damit Matomo dieselben Bestellungen/Umsätze zeigt wie der Shop.

    `ts`      = Bestell-Zeitstempel (Epoch-Sekunden)
    `revenue` = Produktumsatz OHNE Versand (= WC-Bruttoumsatz; bewusst ohne Versand,
                damit Matomo-„Gesamteinnahmen" = WooCommerce-„Bruttoumsatz")
    `items`   = [[sku, name, kategorie, preis, menge], ...] (Matomo-ec_items-Format)

    Erzeugt den vollständigen Trichter-Besuch (Produkt → Warenkorb → Kasse → Kauf),
    trägt eine A/B-Variante und sendet die E-Commerce-Bestellung. So füllt jede
    gespiegelte Bestellung dieselben Funnel-Schritte wie ein echter Kaufbesuch und
    ist einer Quelle/Variante zugeordnet (*Akquise*/*E-Commerce*/*Ziele*).
    """
    if catalog is None:
        catalog = _load_catalog()
    when = datetime.fromtimestamp(int(ts))
    visitor_id = uuid.uuid4().hex[:16]
    ua = random.choice(USER_AGENTS)
    geo = _pick_geo()
    while not geo.get("can_purchase", True):
        geo = _pick_geo()
    channel = _pick_channel()
    base = _base_url(catalog)
    variant = _pick_order_variant()      # Bestellungen leicht zugunsten der Shop-Variante
    # sku -> slug für realistische Produkt-URLs (sonst generisch /product/).
    slug_by_sku = {p.get("sku"): p.get("slug") for p in catalog.get("products", [])}

    first = {"ref": ("" if channel["kind"] == "campaign" else channel["ref"]),
             "camp": channel["ref"] if channel["kind"] == "campaign" else ""}

    def mk(url, action, ref_entry=False):
        p = _base_params(catalog, visitor_id, ua, when=when,
                         urlref=(first["ref"] if ref_entry else ""), geo=geo)
        p["url"] = url
        p["action_name"] = action
        if ref_entry and first["camp"]:
            p["__campaign"] = first["camp"]
        if variant:
            p["dimension%d" % AB_DIMENSION] = variant
        return p

    # Schritt 0: Shop-Einstieg (A/B-Variante) – trägt Referrer/Kampagne
    prod_entry = True
    if variant:
        _send(mk(f"{base}{_shop_landing_path(variant)}", "Shop: " + variant, ref_entry=True))
        when = _advance(when)
        prod_entry = False
    # Schritt 1: Produktansicht
    sku0 = items[0][0] if items else None
    slug0 = slug_by_sku.get(sku0) or "produkt"
    _send(mk(f"{base}/product/{slug0}/", items[0][1] if items else "Produkt", ref_entry=prod_entry))
    when = _advance(when)
    # Schritt 2: Warenkorb (Pageview → Funnel-Ziel /cart/)
    _send(mk(f"{base}/cart/", "Warenkorb"))
    when = _advance(when)
    # Schritt 3: Kasse (Pageview → Funnel-Ziel)
    _send(mk(f"{base}/checkout/", "Kasse"))
    when = _advance(when)
    # Schritt 4: Kauf – Pageview (→ Funnel-Ziel) + separate E-Commerce-Bestellung
    _send(mk(f"{base}/checkout/order-received/", "Bestellbestätigung"))
    q = mk(f"{base}/checkout/order-received/", "Bestellbestätigung")
    q["idgoal"] = 0
    q["ec_id"] = uuid.uuid4().hex[:12]
    q["revenue"] = round(float(revenue), 2)
    if items:
        q["ec_items"] = json.dumps(items, ensure_ascii=False)
        q["ec_st"] = round(sum(float(it[3]) * int(it[4]) for it in items), 2)
    _send(q)


def _trend_season(d, days, day):
    """Deterministischer Tagesfaktor: Wachstums-Trend × Wochen-Saisonalität.

    `d` ist der Abstand zu heute (days..1), `day` das Datum. Über das Backfill-Fenster
    wächst der Shop von ~40% auf ~130% des Basiswerts; Fr/Sa etwas mehr Traffic,
    So etwas weniger. Dieselbe Kurve formt sowohl die Matomo-Besuche als auch die
    zeitliche Verteilung der echten WooCommerce-Bestellungen.
    """
    frac = (days - d) / max(1, days)          # 0 (ältester Tag) .. ~1 (heute)
    trend = 0.4 + 0.9 * frac
    weekday = day.weekday()                    # 0=Mo .. 6=So
    if weekday in (4, 5):                       # Fr/Sa
        season = 1.15
    elif weekday == 6:                          # So
        season = 0.85
    else:
        season = 1.0
    return trend * season


def _day_volume(d, days, base_per_day, day):
    """Besuchszahl eines historischen Tages (Trend × Saison × Tagesrauschen)."""
    noise = random.uniform(0.8, 1.2)
    return max(1, int(round(base_per_day * _trend_season(d, days, day) * noise)))


def history_order_dates(days, count):
    """`count` Bestell-Zeitstempel (Epoch-Sekunden), über die letzten `days` Tage
    verteilt – mit demselben Wachstums-Trend und Wochenrhythmus wie der Matomo-
    Backfill. So entspricht der zeitliche Verlauf der echten WooCommerce-
    Bestellungen dem Zeitraum und Trend der Matomo-Historie.
    """
    if count <= 0 or days <= 0:
        return []
    now = datetime.now()
    day_list, weights = [], []
    for d in range(days, 0, -1):
        day = now - timedelta(days=d)
        day_list.append(day)
        weights.append(_trend_season(d, days, day))
    chosen = random.choices(day_list, weights=weights, k=count)
    stamps = []
    for day in chosen:
        when = day.replace(hour=random.randint(8, 22),
                           minute=random.randint(0, 59),
                           second=random.randint(0, 59))
        stamps.append(int(when.timestamp()))
    return stamps


def backfill(days, base_per_day=14, conversion_rate=0.04, progress=None):
    """Füllt die letzten `days` Tage mit historischen Daten (benötigt token_auth).

    Erzeugt einen realistischen Verlauf mit Wachstums-Trend und Wochen-Saisonalität.
    `progress(done_days, total_days, total)` wird (falls gesetzt) periodisch
    aufgerufen, damit langlaufende 24-Monats-Backfills im UI sichtbar bleiben.
    """
    catalog = _load_catalog()
    total = {"visits": 0, "purchases": 0, "revenue": 0.0, "skipped": 0}
    now = datetime.now()
    for idx, d in enumerate(range(days, 0, -1), start=1):
        day = now - timedelta(days=d)
        volume = _day_volume(d, days, base_per_day, day)
        for _ in range(volume):
            when = day.replace(hour=random.randint(8, 22),
                               minute=random.randint(0, 59),
                               second=random.randint(0, 59))
            # Resilient: ein transienter Fehler bei EINEM Besuch darf den ganzen
            # (zehntausende Treffer langen) Backfill nicht abbrechen – überspringen,
            # aber zählen ("skipped"), damit systematische Probleme sichtbar werden.
            try:
                r = simulate_visit(catalog, when=when, conversion_rate=conversion_rate)
            except requests.RequestException:
                total["skipped"] += 1
                continue
            total["visits"] += 1
            total["purchases"] += 1 if r["purchase"] else 0
            total["revenue"] = round(total["revenue"] + r["revenue"], 2)
        if progress and (idx % 10 == 0 or idx == days):
            progress(idx, days, total)
    return total
