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
        # Erst seeden, wenn der Shop VOLLSTÄNDIG eingerichtet ist (Produkte +
        # Kategorien/Gutschein/Verkaufsländer) – sonst fehlt z. B. der Gutschein.
        if s and s.get("ready") and s.get("provisioned", True):
            return s
        time.sleep(interval)
    return None


def create_orders(count, days_back=0, dates=None, returning_rate=None):
    """Legt echte Bestellungen an; gibt `{"count", "revenue"}` zurück (0/0.0 bei Fehler).

    Mit `dates` (Liste von Epoch-Sekunden) wird je Zeitstempel eine Bestellung
    angelegt und auf dieses Datum datiert – so spiegelt die Bestell-Historie den
    Matomo-Zeitraum (~24 Monate) wider. Ohne `dates` werden `count` Bestellungen
    zufällig innerhalb der letzten `days_back` Tage angelegt. `returning_rate`
    (0..100 %) steuert den Anteil wiederkehrender Bestandskund:innen.

    `revenue` ist die Summe der „Umsatz"-Bestellungen dieses Batches (bezahlt bzw.
    in Abwicklung) – Grundlage für das Seeding nach Monatsumsatz-Richtwert.
    """
    if not ENABLED:
        return {"count": 0, "revenue": 0.0}
    payload = {"days_back": int(days_back)}
    if dates:
        payload["dates"] = [int(t) for t in dates]
        payload["count"] = len(payload["dates"])
    else:
        payload["count"] = int(count)
    if payload["count"] <= 0:
        return {"count": 0, "revenue": 0.0}
    if returning_rate is not None:
        payload["returning_rate"] = int(round(returning_rate))
    try:
        r = SESSION.post(
            f"{WP_URL}/wp-json/m392/v1/orders",
            headers={"X-M392-Key": API_KEY},
            json=payload,
            timeout=180,
        )
        r.raise_for_status()
        j = r.json()
        return {"count": int(j.get("count", 0)), "revenue": float(j.get("revenue", 0.0))}
    except (requests.RequestException, ValueError):
        return {"count": 0, "revenue": 0.0}
