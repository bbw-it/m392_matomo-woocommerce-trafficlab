"""Erzeugt Besuche und E-Commerce-Ereignisse über die Matomo-Tracking-API.

Liest Produkte aus catalog.json, damit Tracking-Daten und Shop konsistent sind.
Die URLs, SKUs (`wc_<id>`), Produktnamen und die Kategorie „Kosmetik" entsprechen
exakt dem realen WooCommerce-Shop, damit Matomo dieselbe Struktur zeigt.
Kennt keine Web-Belange – wird von app.py aufgerufen.
"""
import json
import os
import random
import time
import uuid
from datetime import datetime, timedelta

import requests

MATOMO_URL = os.environ.get("MATOMO_INTERNAL_URL", "http://matomo")
ID_SITE = int(os.environ.get("MATOMO_ID_SITE", "1"))
CATALOG_PATH = os.environ.get("CATALOG_PATH", "/seed/catalog.json")
TOKEN_FILE = os.environ.get("MATOMO_TOKEN_FILE", "/token/token_auth")
SHOP_BASE_DEFAULT = os.environ.get("SHOP_BASE_URL", "http://localhost:8090")
# Interne WordPress-URL fuer den Produkt-Sync (Container-Netz).
WP_INTERNAL_URL = os.environ.get("WORDPRESS_INTERNAL_URL", "http://wordpress").rstrip("/")

# Verbindungs-Pool: deutlich schneller beim 24-Monats-Backfill (zehntausende Requests).
SESSION = requests.Session()

# Live-Produkte des Shops (gecacht), damit Matomo-Daten und realer Shop
# deckungsgleich bleiben und neu angelegte Produkte automatisch beruecksichtigt
# werden. Faellt bei Nichterreichbarkeit auf catalog.json zurueck.
_PRODUCT_CACHE = {"ts": 0.0, "data": None}
_PRODUCT_TTL = 300.0


def _fetch_live_products():
    """Holt die Live-Produktliste vom Shop (gecacht ~5 min). None bei Fehler."""
    now = time.time()
    cached = _PRODUCT_CACHE["data"]
    if cached is not None and (now - _PRODUCT_CACHE["ts"]) < _PRODUCT_TTL:
        return cached
    try:
        r = SESSION.get(f"{WP_INTERNAL_URL}/wp-json/m392/v1/products", timeout=10)
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
    # name,        Besuchsanteil, roher Conv.-Mult., Referrer (von Matomo erkannt)
    ("social",     0.34, 2.00, ["https://www.instagram.com/", "https://l.instagram.com/",
                                 "https://www.facebook.com/", "https://www.pinterest.com/",
                                 "https://www.tiktok.com/"]),
    ("search",     0.26, 0.85, ["https://www.google.com/", "https://www.bing.com/"]),
    ("direct",     0.22, 0.75, [""]),
    ("newsletter", 0.10, 1.20, ["https://newsletter.example.com/"]),
    ("referral",   0.08, 0.50, ["https://www.beautyblog.example/", "https://magazin.example/"]),
]
_CH_AVG = (sum(w * m for _, w, m, _ in _RAW_CHANNELS)
           / sum(w for _, w, m, _ in _RAW_CHANNELS))
CHANNELS = [{"name": n, "weight": w, "mult": m / _CH_AVG, "refs": r}
            for n, w, m, r in _RAW_CHANNELS]
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
    (0.62, "de", "de-DE", True, [("Berlin", "Berlin"), ("Hamburg", "Hamburg"), ("München", "Bayern"),
                                 ("Köln", "Nordrhein-Westfalen"), ("Stuttgart", "Baden-Württemberg"),
                                 ("Leipzig", "Sachsen"), ("Frankfurt am Main", "Hessen")]),
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


def _load_catalog():
    """catalog.json (Meta) + Live-Produkte aus WooCommerce.

    Produkte/Kategorien werden – wenn der Shop erreichbar ist – live uebernommen
    (echte SKU `wc_<id>`, voller Name, echter Preis, echte Kategorie). So stimmen
    die Matomo-Daten exakt mit dem Shop ueberein und neue Produkte sind automatisch
    dabei. Die `popularity` (Bestseller-Gewichtung) kommt weiterhin aus catalog.json
    je SKU; unbekannte/neue Produkte erhalten einen mittleren Default. Faellt der
    Sync aus, gelten die statischen catalog.json-Produkte.
    """
    with open(CATALOG_PATH, encoding="utf-8") as f:
        catalog = json.load(f)

    live = _fetch_live_products()
    if not live:
        return catalog

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
    return catalog


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
        resp = SESSION.get(f"{MATOMO_URL}/matomo.php", timeout=10)
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
    resp = SESSION.get(f"{MATOMO_URL}/matomo.php", params=params, timeout=15)
    resp.raise_for_status()
    return resp


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
    """Akquise-Kanal samt Einstiegs-Referrer und Conversion-Multiplikator waehlen."""
    c = random.choices(CHANNELS, weights=_CH_WEIGHTS, k=1)[0]
    return {"name": c["name"], "ref": random.choice(c["refs"]), "mult": c["mult"]}


def simulate_visit(catalog, when=None, force_purchase=False, conversion_rate=0.04):
    """Ein kompletter Besucherpfad. Gibt dict mit Kennzahlen zurück."""
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

    pages = 0
    # Einstiegs-Referrer traegt nur die ERSTE Aktion des Besuchs (Matomo nutzt den
    # Referrer der Einstiegsseite zur Kanal-Zuordnung); Folgeaktionen sind intern.
    _entry = {"ref": channel["ref"]}

    def mkparams():
        p = _base_params(catalog, visitor_id, ua, when, urlref=_entry["ref"], geo=geo)
        _entry["ref"] = ""
        return p

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

    # --- Kaufpfad -----------------------------------------------------------
    # „Übriges Europa" kann NICHT bestellen (WooCommerce akzeptiert nur DE/CH/AT) –
    # diese Besuche legen aber öfter in den Warenkorb (sichtbarer Abbruch).
    can_buy = geo.get("can_purchase", True)
    purchased = (force_purchase or (random.random() < eff_cr)) and can_buy
    revenue = 0.0
    if purchased:
        if viewed_products and random.random() < 0.7:
            pool = viewed_products
        else:
            pool = products
        cart = random.choices(pool, weights=[pr.get("popularity", 1) for pr in pool],
                              k=random.randint(1, 3))
        items, subtotal = [], 0.0
        for pr in cart:
            qty = random.randint(1, 2)
            subtotal += pr["price"] * qty
            items.append([pr["sku"], pr["name"], pr["category"], pr["price"], qty])
        shipping = 0.0 if subtotal >= 50 else 4.90
        revenue = round(subtotal + shipping, 2)
        p = mkparams()
        p["url"] = f"{base}/checkout/order-received/"
        p["action_name"] = "Bestellbestätigung"
        p["idgoal"] = 0
        p["ec_id"] = uuid.uuid4().hex[:12]
        p["revenue"] = revenue
        p["ec_st"] = round(subtotal, 2)
        p["ec_sh"] = shipping
        p["ec_items"] = json.dumps(items, ensure_ascii=False)
        _send(p)
        pages += 1
    elif viewed_products and random.random() < (0.70 if not can_buy else 0.20):
        # Abgebrochener Warenkorb (Cart-Update ohne ec_id). „Übriges Europa" landet
        # hier häufig: sieht Produkte, legt in den Korb – kann dann aber nicht bestellen.
        cart = random.sample(viewed_products, min(random.randint(1, 2), len(viewed_products)))
        items, subtotal = [], 0.0
        for pr in cart:
            qty = random.randint(1, 2)
            subtotal += pr["price"] * qty
            items.append([pr["sku"], pr["name"], pr["category"], pr["price"], qty])
        p = mkparams()
        p["url"] = f"{base}/cart/"
        p["action_name"] = "Warenkorb"
        p["idgoal"] = 0
        p["ec_items"] = json.dumps(items, ensure_ascii=False)
        p["revenue"] = round(subtotal, 2)
        _send(p)
        pages += 1

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

    return {"pages": pages, "purchase": purchased, "revenue": revenue}


def generate_visits(count, conversion_rate=0.04, when=None):
    catalog = _load_catalog()
    summary = {"visits": 0, "purchases": 0, "revenue": 0.0}
    for _ in range(count):
        r = simulate_visit(catalog, when=when, conversion_rate=conversion_rate)
        summary["visits"] += 1
        summary["purchases"] += 1 if r["purchase"] else 0
        summary["revenue"] = round(summary["revenue"] + r["revenue"], 2)
    return summary


def generate_orders(count, when=None):
    """Erzwingt `count` Käufe (jeweils mit vorausgehendem Besuch)."""
    catalog = _load_catalog()
    summary = {"purchases": 0, "revenue": 0.0}
    for _ in range(count):
        r = simulate_visit(catalog, when=when, force_purchase=True)
        summary["purchases"] += 1
        summary["revenue"] = round(summary["revenue"] + r["revenue"], 2)
    return summary


def _trend_season(d, days, day):
    """Deterministischer Tagesfaktor: Wachstums-Trend × Wochen-Saisonalität.

    `d` ist der Abstand zu heute (days..1), `day` das Datum. Über die 24 Monate
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
    total = {"visits": 0, "purchases": 0, "revenue": 0.0}
    now = datetime.now()
    for idx, d in enumerate(range(days, 0, -1), start=1):
        day = now - timedelta(days=d)
        volume = _day_volume(d, days, base_per_day, day)
        for _ in range(volume):
            when = day.replace(hour=random.randint(8, 22),
                               minute=random.randint(0, 59),
                               second=random.randint(0, 59))
            r = simulate_visit(catalog, when=when, conversion_rate=conversion_rate)
            total["visits"] += 1
            total["purchases"] += 1 if r["purchase"] else 0
            total["revenue"] = round(total["revenue"] + r["revenue"], 2)
        if progress and (idx % 30 == 0 or idx == days):
            progress(idx, days, total)
    return total
