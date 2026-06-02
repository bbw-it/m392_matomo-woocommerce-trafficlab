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

# Verbindungs-Pool: deutlich schneller beim 24-Monats-Backfill (zehntausende Requests).
SESSION = requests.Session()

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


def _load_catalog():
    with open(CATALOG_PATH, encoding="utf-8") as f:
        return json.load(f)


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


def _base_params(catalog, visitor_id, ua, when=None, urlref=""):
    params = {
        "idsite": ID_SITE, "rec": 1, "apiv": 1,
        "_id": visitor_id, "rand": random.randint(1, 10_000_000),
        "ua": ua, "lang": "de-DE",
        "res": random.choice(["1920x1080", "1440x900", "390x844", "412x915"]),
        "url": _base_url(catalog) + "/", "urlref": urlref,
    }
    if when is not None:
        params["cdt"] = when.strftime("%Y-%m-%d %H:%M:%S")
        token = _read_token()
        if token:
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
    # Kanalabhaengige Conversion (Social konvertiert ueberdurchschnittlich).
    eff_cr = min(0.95, conversion_rate * channel["mult"])

    pages = 0
    # Einstiegs-Referrer traegt nur die ERSTE Aktion des Besuchs (Matomo nutzt den
    # Referrer der Einstiegsseite zur Kanal-Zuordnung); Folgeaktionen sind intern.
    _entry = {"ref": channel["ref"]}

    def mkparams():
        p = _base_params(catalog, visitor_id, ua, when, urlref=_entry["ref"])
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
        _send(p)
        pages += 1
        when = _advance(when)

    # --- Kaufpfad -----------------------------------------------------------
    purchased = force_purchase or (random.random() < eff_cr)
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
    elif viewed_products and random.random() < 0.20:
        # Abgebrochener Warenkorb (Cart-Update ohne ec_id).
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


def _day_volume(d, days, base_per_day, day):
    """Besuchszahl eines historischen Tages: Wachstums-Trend + Wochen-Saisonalität.

    `d` ist der Abstand zu heute (days..1), `day` das Datum. Über die 24 Monate
    wächst der Shop von ~40% auf ~130% des Basiswerts; Fr/Sa etwas mehr Traffic,
    So etwas weniger – plus zufälliges Tagesrauschen.
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
    noise = random.uniform(0.8, 1.2)
    return max(1, int(round(base_per_day * trend * season * noise)))


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
