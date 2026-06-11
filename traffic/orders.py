"""Echte WooCommerce-Bestellungen über den Order-API-Endpunkt anlegen.

Spricht das mu-plugin `m392-order-api.php` im WordPress-Container an (interner
HTTP-Aufruf). Realismus/Logik der Bestellungen liegt serverseitig in PHP – hier
nur das Auslösen. Fehler werden geschluckt (Bestellungen sind „nice to have",
dürfen den Matomo-Traffic nie blockieren).
"""
import os
import threading
import time

import requests

WP_URL = os.environ.get("WORDPRESS_INTERNAL_URL", "http://wordpress").rstrip("/")
API_KEY = os.environ.get("M392_ORDER_API_KEY", "m392-order-secret")
ENABLED = os.environ.get("TRAFFIC_CREATE_WC_ORDERS", "true").lower() == "true"

_THREAD_LOCAL = threading.local()


def _session():
    s = getattr(_THREAD_LOCAL, "session", None)
    if s is None:
        s = requests.Session()
        _THREAD_LOCAL.session = s
    return s


def ping():
    """Status des Order-Endpunkts (bereit?, Produkt-/Bestellanzahl) oder None."""
    try:
        r = _session().get(f"{WP_URL}/wp-json/m392/v1/ping", timeout=10)
        return r.json() if r.status_code == 200 else None
    except (requests.RequestException, ValueError):
        return None


def wait_for_wordpress(timeout=600, interval=5):
    """Wartet, bis WooCommerce bereit ist UND Produkte existieren (Fixture restored)."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        s = ping()
        # Erst seeden, wenn der Shop VOLLSTÄNDIG eingerichtet ist (Produkte +
        # Kategorien/Gutschein/Verkaufsländer) – sonst fehlt z. B. der Gutschein.
        if s and s.get("ready") and s.get("provisioned", True):
            return s
        time.sleep(interval)
    return None


def create_orders(count, days_back=0, dates=None, returning_rate=None, carts=None):
    """Legt echte Bestellungen an.

    Rückgabe: `{"count", "revenue", "returning", "details", "orders_detail", "error"}`.
    `error` ist `None` bei Erfolg bzw. wenn nichts versucht wurde (deaktiviert /
    count<=0), sonst der Fehlertext (WC nicht erreichbar o. ä.). Konsument:innen
    sollten die Keys mit `.get(...)` lesen.

    Mit `carts` (je Bestellung eine Liste `[sku, menge]`) enthalten die Bestellungen
    EXAKT diese Artikel – so legt der Live-Tropf Bestellungen mit den in Matomo
    getrackten Warenkörben an. `orders_detail` liefert dafür je angelegter
    Bestellung Zeitstempel, Produktumsatz, Artikel und Status zurück.

    Mit `dates` (Liste von Epoch-Sekunden) wird je Zeitstempel eine Bestellung
    angelegt und auf dieses Datum datiert – so spiegelt die Bestell-Historie den
    Matomo-Zeitraum wider. Ohne `dates` werden `count` Bestellungen
    zufällig innerhalb der letzten `days_back` Tage angelegt. `returning_rate`
    (0..100 %) steuert den Anteil wiederkehrender Bestandskund:innen.

    `revenue` ist die Summe der „Umsatz"-Bestellungen dieses Batches (bezahlt bzw.
    in Abwicklung) – Grundlage für das Seeding nach Monatsumsatz-Richtwert.
    """
    empty = {"count": 0, "revenue": 0.0, "returning": 0, "details": [],
             "orders_detail": [], "error": None}
    if not ENABLED:
        return empty
    payload = {"days_back": int(days_back)}
    if carts:
        payload["carts"] = [[[str(sku), int(qty)] for sku, qty in cart] for cart in carts]
        payload["count"] = len(payload["carts"])
    elif dates:
        payload["dates"] = [int(t) for t in dates]
        payload["count"] = len(payload["dates"])
    else:
        payload["count"] = int(count)
    if payload["count"] <= 0:
        return empty
    if returning_rate is not None:
        payload["returning_rate"] = int(round(returning_rate))
    try:
        r = _session().post(
            f"{WP_URL}/wp-json/m392/v1/orders",
            headers={"X-M392-Key": API_KEY},
            json=payload,
            timeout=180,
        )
        r.raise_for_status()
        j = r.json()
        return {"count": int(j.get("count", 0)), "revenue": float(j.get("revenue", 0.0)),
                "returning": int(j.get("returning", 0)), "details": j.get("details", []) or [],
                "orders_detail": j.get("orders_detail", []) or [], "error": None}
    except (requests.RequestException, ValueError) as exc:
        return dict(empty, error=str(exc))


def get_weights():
    """Persistierte Beliebtheits-Gewichte ({sku: 0..100}) aus WordPress lesen."""
    try:
        r = _session().get(f"{WP_URL}/wp-json/m392/v1/weights", timeout=10)
        return dict(r.json().get("weights") or {}) if r.status_code == 200 else {}
    except (requests.RequestException, ValueError):
        return {}


def set_weights(weights):
    """Beliebtheits-Gewichte in WordPress persistieren (Merge). True bei Erfolg."""
    try:
        r = _session().post(
            f"{WP_URL}/wp-json/m392/v1/weights",
            headers={"X-M392-Key": API_KEY},
            json={"weights": {str(k): int(v) for k, v in (weights or {}).items()}},
            timeout=15,
        )
        return r.status_code == 200
    except (requests.RequestException, ValueError):
        return False


def revenue_sum():
    """Produktumsatz-Summe der „Umsatz"-Bestellungen (für die idempotente
    Richtwert-Seed-Prüfung). Separater Endpunkt, damit der /ping-Readiness-Check
    leichtgewichtig bleibt. 0.0 bei Fehler."""
    try:
        r = _session().get(f"{WP_URL}/wp-json/m392/v1/orders-revenue", timeout=30)
        return float(r.json().get("revenue", 0.0)) if r.status_code == 200 else 0.0
    except (requests.RequestException, ValueError):
        return 0.0
