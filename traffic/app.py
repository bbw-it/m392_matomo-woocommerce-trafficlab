"""Flask-Steuerpult für den Traffic-Generator (Modul 392)."""
import os
import random
import threading
import time

from flask import Flask, jsonify, render_template, request

import generator
import orders

app = Flask(__name__)


@app.after_request
def _no_browser_cache(resp):
    """Browser-Caching der Lab-UI unterbinden.

    Die gesamte Oberfläche (HTML + CSS + JS) steckt inline in index.html. Ohne
    diese Header cached der Browser die Seite und zeigt nach einem Image-Rebuild
    (z. B. via install.sh) weiterhin die ALTE Version – die neuen visuellen
    Anpassungen „verschwinden" scheinbar. no-store erzwingt bei jedem Aufruf
    frisches HTML, sodass ein Rebuild sofort sichtbar ist.
    """
    resp.headers["Cache-Control"] = "no-store, no-cache, must-revalidate, max-age=0"
    resp.headers["Pragma"] = "no-cache"
    resp.headers["Expires"] = "0"
    return resp



def _env_float(name, default=0.0):
    """Float aus .env lesen, robust gegen versehentliche Inline-Kommentare."""
    raw = os.environ.get(name)
    if raw is None:
        return default
    raw = raw.split("#", 1)[0].strip()
    if not raw:
        return default
    try:
        return float(raw)
    except ValueError:
        return default


def _initial_drip_per_hour():
    """Besucher/Stunde aus .env lesen (mit Rückwärtskompatibilität)."""
    if os.environ.get("TRAFFIC_DRIP_VISITS_PER_HOUR"):
        return int(float(os.environ["TRAFFIC_DRIP_VISITS_PER_HOUR"]))
    if os.environ.get("TRAFFIC_DRIP_VISITS_PER_MIN"):  # Legacy: pro Minute -> pro Stunde
        return int(float(os.environ["TRAFFIC_DRIP_VISITS_PER_MIN"]) * 60)
    return 120


STATE = {
    "live_drip": os.environ.get("TRAFFIC_LIVE_DRIP", "true").lower() == "true",
    "conversion_rate": float(os.environ.get("TRAFFIC_CONVERSION_RATE", "0.04")),
    # Anteil wiederkehrender Kund:innen (0..1) – steuert, wie oft Bestellungen
    # bestehenden WooCommerce-Kund:innen zugeordnet werden statt neuen.
    "returning_rate": float(os.environ.get("TRAFFIC_RETURNING_RATE", "0.08")),
    "drip_per_hour": _initial_drip_per_hour(),
    "totals": {"visits": 0, "purchases": 0, "revenue": 0.0},
    "last_log": [],
    "history": [],  # [{"t": epoch, "visits": kum, "purchases": kum}] für das Aktivitäts-Chart
    # Startbefüllung: Status für reset.sh/„fertig?"-Abfrage. off|running|done|error
    "seed": {"history": "off", "orders": "off"},
    # Feiner Fortschritt je Phase (done/total) für die Live-Anzeige in reset.sh.
    "progress": {"history": {"done": 0, "total": 0},
                 "orders": {"done": 0, "total": 0}},
}
LOCK = threading.Lock()

DRIP_MIN_PER_HOUR = 6
DRIP_MAX_PER_HOUR = 1200

# Organischer "Tropf": Besuche kommen in kleinen Schueben (mal mehrere Gaeste
# gleichzeitig), mit zufaelligen Pausen dazwischen – kein striktes Intervall.
# Schubgroesse: meist 1, gelegentlich mehrere. _BURST_MEAN haelt zusammen mit den
# exponentiell verteilten Pausen den langfristigen Schnitt bei ~Besucher/Stunde.
_BURST_WEIGHTS = [(1, 0.62), (2, 0.22), (3, 0.10), (4, 0.045), (5, 0.015)]
_BURST_SIZES = [b for b, _ in _BURST_WEIGHTS]
_BURST_PROBS = [w for _, w in _BURST_WEIGHTS]
_BURST_MEAN = sum(b * w for b, w in _BURST_WEIGHTS)


def _log(msg):
    ts = time.strftime("%H:%M:%S")
    with LOCK:
        STATE["last_log"].insert(0, {"t": ts, "msg": msg})
        STATE["last_log"] = STATE["last_log"][:30]


def _set_seed(key, value):
    with LOCK:
        STATE["seed"][key] = value


def _set_progress(key, done, total):
    with LOCK:
        STATE["progress"][key] = {"done": int(done), "total": int(total)}


def _accumulate(summary):
    with LOCK:
        t = STATE["totals"]
        t["visits"] += summary.get("visits", 0)
        t["purchases"] += summary.get("purchases", 0)
        t["revenue"] = round(t["revenue"] + summary.get("revenue", 0.0), 2)


def _drip_worker():
    # Erst lostropfen, wenn Matomo erreichbar/installiert ist; sonst laufen die
    # ersten Besuche ins Leere, bevor matomo-init fertig ist.
    generator.wait_for_ready(timeout=600)
    while True:
        if not STATE["live_drip"]:
            time.sleep(1.0)          # pausiert: schnell wieder aufnehmbar
            continue
        per_hour = max(1, STATE["drip_per_hour"])
        # Schub: meist 1 Besuch, gelegentlich mehrere Gaeste "gleichzeitig".
        burst = random.choices(_BURST_SIZES, weights=_BURST_PROBS)[0]
        try:
            s = generator.generate_visits(burst, conversion_rate=STATE["conversion_rate"])
            _accumulate(s)
            # Käufe zusätzlich als ECHTE WooCommerce-Bestellungen anlegen (Live).
            if s.get("purchases"):
                res = orders.create_orders(s["purchases"], days_back=0,
                                           returning_rate=STATE["returning_rate"] * 100)
                if res["count"]:
                    _log(f"{res['count']} Bestellung(en) im Shop angelegt (Live)")
        except Exception as exc:
            _log(f"Drip-Fehler: {exc}")
        # Exponentiell verteilte Pause (Poisson-Ankuenfte): mal Schlag auf Schlag,
        # mal laengere Ruhe. Erwartungswert haelt den Schnitt bei ~per_hour/Std.
        mean_gap = (3600.0 / per_hour) * _BURST_MEAN
        gap = random.expovariate(1.0 / mean_gap)
        time.sleep(max(0.3, min(gap, 4.0 * mean_gap)))


def _history_worker():
    """Nimmt regelmäßig einen Schnappschuss der Summen für das Live-Chart."""
    while True:
        with LOCK:
            STATE["history"].append({
                "t": time.time(),
                "visits": STATE["totals"]["visits"],
                "purchases": STATE["totals"]["purchases"],
            })
            STATE["history"] = STATE["history"][-740:]  # ~1 h bei 5s-Takt (für 1-Std-Chart)
        time.sleep(5)


@app.route("/")
def index():
    return render_template("index.html")


@app.route("/api/status")
def status():
    with LOCK:
        per_hour = STATE["drip_per_hour"]
        rate = STATE["conversion_rate"]
        return jsonify({
            "live_drip": STATE["live_drip"],
            "conversion_rate": rate,
            "drip_per_hour": per_hour,
            "purchases_per_hour": round(per_hour * rate, 1),
            "drip_bounds": {"min": DRIP_MIN_PER_HOUR, "max": DRIP_MAX_PER_HOUR},
            "returning_rate": STATE["returning_rate"],
            "totals": STATE["totals"],
            "log": STATE["last_log"],
            "history": STATE["history"],
            "seed": STATE["seed"],
            "progress": STATE["progress"],
        })


@app.route("/api/ready")
def ready():
    """Fertig-Signal für reset.sh: HTTP 200, wenn die Startbefüllung (Historie +
    Bestellungen) abgeschlossen/aus ist – sonst 202 (läuft noch). Der Body zeigt
    den Fortschritt im Klartext, damit Shell-Skripte ihn einfach anzeigen können.
    """
    with LOCK:
        seed = dict(STATE["seed"])
        prog = {k: dict(v) for k, v in STATE["progress"].items()}
        t = dict(STATE["totals"])
    done = all(v in ("done", "off", "error") for v in seed.values())

    def phase(key, label):
        st = seed[key]
        if st == "off":
            return None
        if st == "done":
            return f"{label} ✓"          # ✓
        if st == "error":
            return f"{label} ⚠"          # ⚠
        p = prog[key]
        if p["total"] > 0:
            return f"{label} {p['done']}/{p['total']}"
        return f"{label}: bereite vor"

    parts = [s for s in (phase("history", "Historie"),
                         phase("orders", "Bestellungen")) if s]
    summary = " · ".join(parts) if parts else "bereit"
    # Während des Backfills die laufende Seed-Besuchszahl zeigen (klettert mit),
    # sonst die Gesamtsumme. Ohne das stünde hier nur der Live-Tropf (~wenige).
    seed_visits = prog.get("history", {}).get("visits", 0)
    visits = max(seed_visits, t["visits"])
    summary += f" · {visits} Besuche"
    body = summary + "\n"
    return (body, 200 if done else 202, {"Content-Type": "text/plain; charset=utf-8"})


@app.route("/api/generate-visits", methods=["POST"])
def gen_visits():
    count = int(request.form.get("count", 50))
    s = generator.generate_visits(count, conversion_rate=STATE["conversion_rate"])
    _accumulate(s)
    _log(f"{count} Besuche erzeugt – {s['purchases']} Käufe, EUR {s['revenue']:.2f}")
    return jsonify(s)


@app.route("/api/generate-orders", methods=["POST"])
def gen_orders():
    count = int(request.form.get("count", 10))
    s = generator.generate_orders(count)
    _accumulate({"purchases": s["purchases"], "revenue": s["revenue"]})
    res = orders.create_orders(count, days_back=0,                  # echte WooCommerce-Bestellungen
                               returning_rate=STATE["returning_rate"] * 100)
    made = res["count"]
    extra = f" · {made} Shop-Bestellungen" if made else ""
    _log(f"{count} Käufe erzwungen – EUR {s['revenue']:.2f}{extra}")
    return jsonify(s)


@app.route("/api/backfill", methods=["POST"])
def backfill():
    days = int(request.form.get("days", 180))

    def _progress(done, total, running):
        _log(f"Backfill … {done}/{total} Tage ({running['visits']} Besuche, "
             f"{running['purchases']} Käufe)")

    s = generator.backfill(days, conversion_rate=STATE["conversion_rate"], progress=_progress)
    _accumulate(s)
    _log(f"Backfill {days} Tage fertig – {s['visits']} Besuche, {s['purchases']} Käufe")
    return jsonify(s)


@app.route("/api/toggle-drip", methods=["POST"])
def toggle_drip():
    with LOCK:
        STATE["live_drip"] = not STATE["live_drip"]
        state = STATE["live_drip"]
    _log(f"Live-Tropf {'aktiviert' if state else 'pausiert'}")
    return jsonify({"live_drip": state})


@app.route("/api/set-drip", methods=["POST"])
def set_drip():
    """Live-Tropf parametrisieren: Besucher/Stunde und/oder Conversion-Rate."""
    changed = []
    with LOCK:
        if request.form.get("visitors_per_hour") not in (None, ""):
            v = int(float(request.form["visitors_per_hour"]))
            STATE["drip_per_hour"] = max(DRIP_MIN_PER_HOUR, min(DRIP_MAX_PER_HOUR, v))
            changed.append(f"{STATE['drip_per_hour']} Besucher/Std")
        if request.form.get("conversion_rate") not in (None, ""):
            STATE["conversion_rate"] = max(0.0, min(1.0, float(request.form["conversion_rate"])))
            changed.append(f"{STATE['conversion_rate'] * 100:.1f}% Conversion")
        if request.form.get("returning_rate") not in (None, ""):
            STATE["returning_rate"] = max(0.0, min(1.0, float(request.form["returning_rate"])))
            changed.append(f"{STATE['returning_rate'] * 100:.0f}% Wiederkehrer")
        per_hour = STATE["drip_per_hour"]
        rate = STATE["conversion_rate"]
        ret = STATE["returning_rate"]
    if changed:
        _log("Live-Tropf eingestellt: " + ", ".join(changed))
    return jsonify({
        "drip_per_hour": per_hour,
        "conversion_rate": rate,
        "returning_rate": ret,
        "purchases_per_hour": round(per_hour * rate, 1),
    })


def _maybe_auto_seed():
    if os.environ.get("TRAFFIC_AUTO_SEED", "true").lower() != "true":
        return
    monthly = _env_float("TRAFFIC_AVG_MONTHLY_REVENUE", 0.0)
    cr = max(0.001, STATE["conversion_rate"])
    # Kopplung nur, wenn auch wirklich Bestellungen erzeugt werden (sonst käme gar
    # kein E-Commerce in Matomo an, weil der Backfill dann nicht mehr konvertiert).
    if monthly > 0 and orders.ENABLED:
        # Gekoppelter Modus: Die E-Commerce-Conversions kommen aus den gespiegelten
        # WooCommerce-Bestellungen (track_ecommerce_order) – NICHT aus dem Backfill.
        # Der Backfill liefert daher nur die NICHT kaufenden Besuche (conversion=0),
        # und zwar so viele, dass die Conversion-Rate insgesamt realistisch bleibt:
        #   Besuche/Tag ≈ Bestellungen/Tag × (1/CR − 1).
        # Fenster = Bestellfenster, damit Besuche und Bestellungen denselben Zeitraum
        # abdecken. Ø-Bestellwert nur grob geschätzt (skaliert nur die Besucherzahl,
        # nicht den Umsatz – der kommt exakt aus den Bestellungen).
        days = int(_env_float("TRAFFIC_SEED_ORDERS_DAYS", _env_float("TRAFFIC_BACKFILL_DAYS", 180)))
        aov_est = 28.0   # Ø-Produktumsatz/Bestellung (ohne Versand); nur Besucher-Skalierung
        orders_per_day = (monthly / 30.0) / aov_est
        base_per_day = max(1, int(round(orders_per_day * (1.0 / cr - 1.0))))
        backfill_conv = 0.0
    else:
        days = int(_env_float("TRAFFIC_BACKFILL_DAYS", 180))
        base_per_day = 14
        backfill_conv = STATE["conversion_rate"]
    _set_seed("history", "running")

    def _progress(done, total, running):
        # Laufende Seed-Besuche mitführen, damit der „Besuche"-Zähler in /api/ready
        # WÄHREND des Backfills klettert (die Summen werden erst am Ende verbucht).
        with LOCK:
            STATE["progress"]["history"] = {"done": int(done), "total": int(total),
                                            "visits": int(running.get("visits", 0))}
        # Log gedrosselt (~alle 30 Tage), damit das Log nicht überläuft.
        if total <= 0 or done % 30 == 0 or done >= total:
            _log(f"Auto-Seed … {done}/{total} Tage ({running['visits']} Besuche, "
                 f"{running['purchases']} Käufe)")

    def seed():
        # Auf Matomo-Bereitschaft warten (installiert + gueltiger Token),
        # statt blind 20 s zu schlafen. Verhindert das Rennen mit matomo-init,
        # damit der historische Backfill (braucht token_auth) zuverlaessig landet.
        _log(f"Auto-Seed: warte auf Matomo-Bereitschaft; befülle danach {days} Tage "
             f"Historie ({base_per_day} Besuche/Tag) ...")
        if not generator.wait_for_ready(timeout=600):
            _log("Auto-Seed-Fehler: Matomo nicht rechtzeitig bereit (Timeout).")
            _set_seed("history", "error")
            return
        for attempt in range(1, 4):
            try:
                s = generator.backfill(days, base_per_day=base_per_day,
                                       conversion_rate=backfill_conv, progress=_progress)
                _accumulate(s)
                _log(f"Auto-Seed: {days} Tage Historie befüllt ({s['visits']} Besuche)")
                _set_seed("history", "done")
                return
            except Exception as exc:
                _log(f"Auto-Seed-Versuch {attempt}/3 fehlgeschlagen: {exc}")
                time.sleep(5)
        _log("Auto-Seed-Fehler: Backfill nach 3 Versuchen aufgegeben.")
        _set_seed("history", "error")

    threading.Thread(target=seed, daemon=True).start()


def _create_dated_batches(dates, made_offset, total, ret_pct, mirror=False, catalog=None):
    """Legt Bestellungen zu den (sortierten) `dates` in Häppchen von 20 an
    (schont WooCommerce). Gibt `(angelegte_Anzahl, summierter_Umsatz)` zurück und
    aktualisiert die Fortschrittsanzeige fortlaufend.

    `mirror=True`: jede Umsatz-Bestellung wird zusätzlich als Matomo-E-Commerce-
    Conversion gespiegelt (gleiches Datum/Umsatz/Artikel) → Matomo = WooCommerce."""
    made, revenue = 0, 0.0
    for i in range(0, len(dates), 20):
        res = orders.create_orders(0, dates=dates[i:i + 20], returning_rate=ret_pct)
        made += res["count"]
        revenue += res["revenue"]
        if mirror:
            for d in res.get("details", []):
                try:
                    generator.track_ecommerce_order(d["ts"], d["revenue"],
                                                    d.get("items", []), catalog=catalog)
                except Exception as exc:
                    _log(f"Matomo-Spiegelung übersprungen: {exc}")
        _set_progress("orders", made_offset + made, total)
    return made, revenue


def _seed_orders_by_count(status, days, target, ret_pct):
    """Klassischer Modus: feste Zielanzahl Bestellungen (TRAFFIC_SEED_ORDERS)."""
    existing = int(status.get("orders", 0))
    need = max(0, target - existing)
    if need == 0:
        _log(f"Bestellungen: bereits {existing} vorhanden – kein Seed nötig.")
        _set_progress("orders", 1, 1)
        _set_seed("orders", "done")
        return
    _set_progress("orders", 0, need)
    dates = sorted(generator.history_order_dates(days, need))
    made, _ = _create_dated_batches(dates, 0, need, ret_pct)
    _log(f"Bestellungen … {made}/{need} angelegt")
    _log(f"Bestellungen: {made} realistische Bestellungen erzeugt "
         f"(verteilt über ~{max(1, round(days / 30))} Monate, passend zur Matomo-Historie).")
    _set_seed("orders", "done")


def _seed_orders_by_revenue(status, days, monthly, ret_pct):
    """Richtwert-Modus: so viele Bestellungen anlegen, dass der Monatsumsatz der
    generierten Bestellungen etwa `monthly` (EUR) entspricht.

    Vorgehen (kalibrierend, daher unabhängig von Preisen/Warenkorb-Logik):
      1. Zielumsatz = monthly × Monate; bereits vorhandenen Umsatz abziehen.
      2. Kleine Kalibrier-Charge anlegen und den Ø-Bestellwert daraus messen.
      3. Restanzahl = Restumsatz / Ø-Bestellwert; verteilt über das Fenster anlegen.
    Idempotent: füllt nur bis zum Zielumsatz auf (nutzt den Ping-Umsatz)."""
    cat = generator._load_catalog()      # einmal laden, für die Matomo-Spiegelung
    months = max(0.1, days / 30.0)
    total_target = monthly * months
    existing_rev = float(status.get("revenue", 0.0))
    remaining = total_target - existing_rev
    months_lbl = max(1, round(days / 30))
    if remaining <= 0.05 * total_target:
        _log(f"Bestellungen: Umsatz-Richtwert bereits erreicht "
             f"(~EUR {existing_rev:.0f} / Ziel EUR {total_target:.0f}).")
        _set_progress("orders", 1, 1)
        _set_seed("orders", "done")
        return

    # 1) Kalibrierung: kleine, über das Fenster verteilte Charge → Ø-Bestellwert.
    #    Chargengröße grob am Restumsatz ausrichten (ROUGH_AOV nur zur Dimensionierung),
    #    damit kleine Richtwerte nicht durch eine zu große Kalibrier-Charge überschossen
    #    werden. Der tatsächliche Ø-Wert wird danach gemessen, nicht geschätzt.
    ROUGH_AOV = 30.0
    cal_n = max(5, min(20, int(round(remaining / ROUGH_AOV))))
    _set_progress("orders", 0, 0)
    _log(f"Bestellungen: kalibriere Ø-Bestellwert (Umsatz-Richtwert "
         f"EUR {monthly:.0f}/Monat, Ziel ~EUR {total_target:.0f} über {months_lbl} Monate) ...")
    cal_dates = sorted(generator.history_order_dates(days, cal_n))
    cal_made, cal_rev = _create_dated_batches(cal_dates, 0, len(cal_dates), ret_pct,
                                              mirror=True, catalog=cat)
    aov = (cal_rev / cal_made) if cal_made else 0.0
    if aov <= 0:
        _log("Bestell-Seed-Fehler: Ø-Bestellwert nicht ermittelbar.")
        _set_seed("orders", "error")
        return
    _log(f"Bestellungen: Ø-Bestellwert ~EUR {aov:.2f} (aus {cal_made} Bestellungen).")

    # 2) Restbedarf in Bestellungen schätzen und anlegen (Sicherheitsdeckel 6000).
    made, revenue = cal_made, cal_rev
    need = int(round(max(0.0, remaining - cal_rev) / aov))
    need = max(0, min(need, 6000))
    if need > 0:
        total_n = made + need
        dates = sorted(generator.history_order_dates(days, need))
        m2, r2 = _create_dated_batches(dates, made, total_n, ret_pct, mirror=True, catalog=cat)
        made += m2
        revenue += r2
        _log(f"Bestellungen … {made}/{total_n} angelegt")

    total_rev = existing_rev + revenue
    _log(f"Bestellungen: {made} Bestellungen erzeugt (auch in Matomo gespiegelt) – "
         f"~EUR {total_rev:.0f} Gesamtumsatz über {months_lbl} Monate "
         f"(Ø ~EUR {total_rev / months:.0f}/Monat, Richtwert EUR {monthly:.0f}).")
    _set_seed("orders", "done")


def _maybe_seed_orders():
    """Startseed: echte WooCommerce-Bestellungen anlegen, die den ZEITRAUM der
    Matomo-Historie widerspiegeln – verteilt über die letzten ~24 Monate mit
    demselben Wachstums-Trend/Wochenrhythmus wie der Backfill. Idempotent.

    Zwei Modi:
      • TRAFFIC_AVG_MONTHLY_REVENUE > 0 → Richtwert: Bestellmenge so wählen, dass
        der Monatsumsatz etwa dem Wert entspricht (hat Vorrang).
      • sonst → feste Anzahl Bestellungen (TRAFFIC_SEED_ORDERS, klassisch)."""
    if not orders.ENABLED:
        return
    # Bestellfenster = Matomo-Backfill-Fenster, damit beide denselben Zeitraum
    # abdecken (separat überschreibbar via TRAFFIC_SEED_ORDERS_DAYS).
    days = int(_env_float("TRAFFIC_SEED_ORDERS_DAYS",
                          _env_float("TRAFFIC_BACKFILL_DAYS", 180)))
    monthly = _env_float("TRAFFIC_AVG_MONTHLY_REVENUE", 0.0)
    target_count = int(_env_float("TRAFFIC_SEED_ORDERS", 120))
    revenue_mode = monthly > 0
    if not revenue_mode and target_count <= 0:
        return
    ret_pct = STATE["returning_rate"] * 100
    _set_seed("orders", "running")

    def seed():
        _log("Bestellungen: warte auf WooCommerce-Bereitschaft ...")
        status = orders.wait_for_wordpress(timeout=600)
        if not status:
            _log("Bestell-Seed-Fehler: WooCommerce nicht rechtzeitig bereit.")
            _set_seed("orders", "error")
            return
        if revenue_mode:
            _seed_orders_by_revenue(status, days, monthly, ret_pct)
        else:
            _seed_orders_by_count(status, days, target_count, ret_pct)

    threading.Thread(target=seed, daemon=True).start()


threading.Thread(target=_drip_worker, daemon=True).start()
threading.Thread(target=_history_worker, daemon=True).start()
_maybe_auto_seed()
_maybe_seed_orders()

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8092)
