"""Erzeugt Besuche und E-Commerce-Ereignisse über die Matomo-Tracking-API.

Liest Produkte aus catalog.json, damit Tracking-Daten und Shop konsistent sind.
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

PAGES = [
    ("/", "Startseite"),
    ("/shop/", "Shop"),
    ("/kategorie/bekleidung/", "Kategorie: Bekleidung"),
    ("/kategorie/elektronik/", "Kategorie: Elektronik"),
    ("/warenkorb/", "Warenkorb"),
    ("/kasse/", "Kasse"),
]
REFERRERS = [
    "https://www.google.com/", "https://www.bing.com/",
    "https://www.instagram.com/", "https://newsletter.example.com/",
    "", "",
]
USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile",
    "Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130 Mobile Safari/537.36",
]


def _load_catalog():
    with open(CATALOG_PATH, encoding="utf-8") as f:
        return json.load(f)


def _read_token():
    try:
        with open(TOKEN_FILE, encoding="utf-8") as f:
            return f.read().strip()
    except OSError:
        return ""


def matomo_installed():
    """True, wenn Matomo erreichbar UND installiert ist (Tracker antwortet)."""
    try:
        # /matomo.php liefert bei installiertem Matomo 200/204; der Installer
        # leitet stattdessen auf die Installationsseite um bzw. liefert 5xx.
        resp = requests.get(f"{MATOMO_URL}/matomo.php", timeout=10)
        return resp.status_code in (200, 204)
    except requests.RequestException:
        return False


def token_ready():
    """True, wenn ein plausibler 32-stelliger Hex-Token vorliegt."""
    token = _read_token()
    return bool(token) and len(token) == 32 and all(c in "0123456789abcdef" for c in token)


def wait_for_ready(timeout=300, interval=3):
    """Wartet, bis Matomo installiert ist UND ein gueltiger Token vorliegt.

    Gibt True zurueck, sobald beide Bedingungen erfuellt sind, sonst False nach
    Ablauf des Timeouts. So vermeidet der Auto-Seed das Rennen mit matomo-init.
    """
    deadline = time.time() + timeout
    while time.time() < deadline:
        if matomo_installed() and token_ready():
            return True
        time.sleep(interval)
    return False


def _base_params(visitor_id, ua, when=None):
    params = {
        "idsite": ID_SITE, "rec": 1, "apiv": 1,
        "_id": visitor_id, "rand": random.randint(1, 10_000_000),
        "ua": ua, "lang": "de-CH",
        "res": random.choice(["1920x1080", "1440x900", "390x844", "412x915"]),
        "url": "http://localhost/", "urlref": random.choice(REFERRERS),
    }
    if when is not None:
        params["cdt"] = when.strftime("%Y-%m-%d %H:%M:%S")
        token = _read_token()
        if token:
            params["token_auth"] = token
    return params


def _send(params):
    resp = requests.get(f"{MATOMO_URL}/matomo.php", params=params, timeout=15)
    resp.raise_for_status()
    return resp


def _advance(when):
    """Verschiebt den historischen Zeitstempel um ein paar Sekunden (oder None)."""
    if when is None:
        return None
    return when + timedelta(seconds=random.randint(20, 90))


def _search_keywords(catalog):
    """Leitet such-taugliche Stichwoerter aus Produktnamen/Kategorien ab."""
    words = set()
    for cat in catalog.get("categories", []):
        words.add(cat.lower())
    for pr in catalog.get("products", []):
        words.add(pr["category"].lower())
        # Einzelne Woerter aus dem Produktnamen (laenger als 3 Zeichen),
        # Bindestriche aufgeloest -> z.B. "Bio-Baumwoll-T-Shirt".
        for token in pr["name"].replace("-", " ").split():
            token = token.strip().lower()
            if len(token) >= 4:
                words.add(token)
    return sorted(words)


def _search_result_count(catalog, keyword):
    """Grobe Anzahl Treffer: Produkte, deren Name/Kategorie das Wort enthaelt."""
    kw = keyword.lower()
    count = 0
    for pr in catalog.get("products", []):
        haystack = (pr["name"] + " " + pr["category"]).lower()
        if kw in haystack:
            count += 1
    return count


def simulate_visit(catalog, when=None, force_purchase=False, conversion_rate=0.04):
    """Ein kompletter Besucherpfad. Gibt dict mit Kennzahlen zurück."""
    visitor_id = uuid.uuid4().hex[:16]
    ua = random.choice(USER_AGENTS)
    products = catalog["products"]
    categories = catalog.get("categories", [])

    pages = 0

    # --- Optionaler Einstieg ueber eine On-Site-Suche (~30%) ----------------
    if random.random() < 0.30:
        keyword = random.choice(_search_keywords(catalog))
        count = _search_result_count(catalog, keyword)
        p = _base_params(visitor_id, ua, when)
        p["url"] = f"http://localhost:8090/?s={keyword}&post_type=product"
        # KEIN action_name: Matomo nutzt die Suche als Aktion.
        p["search"] = keyword
        p["search_count"] = count
        if count > 0 and random.random() < 0.5:
            # Optional eine Kategorie als Such-Kontext mitschicken.
            p["search_cat"] = random.choice(categories) if categories else ""
        _send(p)
        pages += 1
        when = _advance(when)

    # --- 1-3 Kategorie-Ansichten (mit _pkc) ---------------------------------
    cat_views = random.sample(categories, min(random.randint(1, 3), len(categories))) if categories else []
    for cat in cat_views:
        p = _base_params(visitor_id, ua, when)
        slug = cat.lower().replace(" ", "-")
        p["url"] = f"http://localhost:8090/kategorie/{slug}/"
        p["action_name"] = f"Kategorie: {cat}"
        p["_pkc"] = cat
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
        p = _base_params(visitor_id, ua, when)
        slug = pr["sku"].lower()
        p["url"] = f"http://localhost:8090/produkt/{slug}/"
        p["action_name"] = pr["name"]
        p["_pks"] = pr["sku"]
        p["_pkn"] = pr["name"]
        p["_pkc"] = pr["category"]
        p["_pkp"] = pr["price"]
        _send(p)
        pages += 1
        when = _advance(when)

    # --- Kaufpfad (Conversion-Logik unveraendert) ---------------------------
    purchased = force_purchase or (random.random() < conversion_rate)
    revenue = 0.0
    if purchased:
        if viewed_products and random.random() < 0.7:
            base = viewed_products
        else:
            base = products
        cart = random.choices(base, weights=[pr.get("popularity", 1) for pr in base],
                              k=random.randint(1, 3))
        items, subtotal = [], 0.0
        for pr in cart:
            qty = random.randint(1, 2)
            subtotal += pr["price"] * qty
            items.append([pr["sku"], pr["name"], pr["category"], pr["price"], qty])
        shipping = 0.0 if subtotal >= 50 else 7.90
        revenue = round(subtotal + shipping, 2)
        p = _base_params(visitor_id, ua, when)
        p["url"] = "http://localhost:8090/kasse/bestellbestaetigung/"
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
        p = _base_params(visitor_id, ua, when)
        p["url"] = "http://localhost:8090/warenkorb/"
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


def backfill(days, visits_per_day=40, conversion_rate=0.04):
    """Füllt die letzten `days` Tage mit historischen Daten (benötigt token_auth)."""
    total = {"visits": 0, "purchases": 0, "revenue": 0.0}
    now = datetime.now()
    for d in range(days, 0, -1):
        day = now - timedelta(days=d)
        for _ in range(random.randint(int(visits_per_day * 0.6), int(visits_per_day * 1.4))):
            when = day.replace(hour=random.randint(8, 22),
                               minute=random.randint(0, 59),
                               second=random.randint(0, 59))
            r = simulate_visit(_load_catalog(), when=when, conversion_rate=conversion_rate)
            total["visits"] += 1
            total["purchases"] += 1 if r["purchase"] else 0
            total["revenue"] = round(total["revenue"] + r["revenue"], 2)
    return total
