"""Echte WooCommerce-Bestellungen über den Order-API-Endpunkt anlegen.

Spricht das mu-plugin `m392-order-api.php` im WordPress-Container an (interner
HTTP-Aufruf). Realismus/Logik der Bestellungen liegt serverseitig in PHP – hier
nur das Auslösen. Fehler werden geschluckt (Bestellungen sind „nice to have",
dürfen den Matomo-Traffic nie blockieren).
"""
import os
import time

import requests

WP_URL = os.environ.get("WORDPRESS_INTERNAL_URL", "http://wordpress").rstrip("/")
API_KEY = os.environ.get("M392_ORDER_API_KEY", "m392-order-secret")
ENABLED = os.environ.get("TRAFFIC_CREATE_WC_ORDERS", "true").lower() == "true"

SESSION = requests.Session()


def ping():
    """Status des Order-Endpunkts (bereit?, Produkt-/Bestellanzahl) oder None."""
    try:
        r = SESSION.get(f"{WP_URL}/wp-json/m392/v1/ping", timeout=10)
        return r.json() if r.status_code == 200 else None
    except (requests.RequestException, ValueError):
        return None


def wait_for_wordpress(timeout=600, interval=5):
    """Wartet, bis WooCommerce bereit ist UND Produkte existieren (Fixture restored)."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        s = ping()
        if s and s.get("ready"):
            return s
        time.sleep(interval)
    return None


def create_orders(count, days_back=0):
    """Legt `count` echte Bestellungen an; gibt Anzahl angelegter zurück (0 bei Fehler)."""
    if not ENABLED or count <= 0:
        return 0
    try:
        r = SESSION.post(
            f"{WP_URL}/wp-json/m392/v1/orders",
            headers={"X-M392-Key": API_KEY},
            json={"count": int(count), "days_back": int(days_back)},
            timeout=120,
        )
        r.raise_for_status()
        return int(r.json().get("count", 0))
    except (requests.RequestException, ValueError):
        return 0
