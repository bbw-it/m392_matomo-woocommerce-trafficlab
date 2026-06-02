"""Flask-Steuerpult für den Traffic-Generator (Modul 392)."""
import os
import random
import threading
import time

from flask import Flask, jsonify, render_template, request

import generator

app = Flask(__name__)


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
    "drip_per_hour": _initial_drip_per_hour(),
    "totals": {"visits": 0, "purchases": 0, "revenue": 0.0},
    "last_log": [],
    "history": [],  # [{"t": epoch, "visits": kum, "purchases": kum}] für das Aktivitäts-Chart
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
            STATE["history"] = STATE["history"][-120:]  # ~10 min bei 5s-Takt
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
            "totals": STATE["totals"],
            "log": STATE["last_log"],
            "history": STATE["history"],
        })


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
    _log(f"{count} Käufe erzwungen – EUR {s['revenue']:.2f}")
    return jsonify(s)


@app.route("/api/backfill", methods=["POST"])
def backfill():
    days = int(request.form.get("days", 730))

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
        per_hour = STATE["drip_per_hour"]
        rate = STATE["conversion_rate"]
    if changed:
        _log("Live-Tropf eingestellt: " + ", ".join(changed))
    return jsonify({
        "drip_per_hour": per_hour,
        "conversion_rate": rate,
        "purchases_per_hour": round(per_hour * rate, 1),
    })


def _maybe_auto_seed():
    if os.environ.get("TRAFFIC_AUTO_SEED", "true").lower() != "true":
        return
    days = int(os.environ.get("TRAFFIC_BACKFILL_DAYS", "730"))

    def _progress(done, total, running):
        _log(f"Auto-Seed … {done}/{total} Tage ({running['visits']} Besuche, "
             f"{running['purchases']} Käufe)")

    def seed():
        # Auf Matomo-Bereitschaft warten (installiert + gueltiger Token),
        # statt blind 20 s zu schlafen. Verhindert das Rennen mit matomo-init,
        # damit der historische Backfill (braucht token_auth) zuverlaessig landet.
        _log(f"Auto-Seed: warte auf Matomo-Bereitschaft; befülle danach {days} Tage "
             f"(~24 Monate) Historie ...")
        if not generator.wait_for_ready(timeout=600):
            _log("Auto-Seed-Fehler: Matomo nicht rechtzeitig bereit (Timeout).")
            return
        for attempt in range(1, 4):
            try:
                s = generator.backfill(days, conversion_rate=STATE["conversion_rate"],
                                       progress=_progress)
                _accumulate(s)
                _log(f"Auto-Seed: {days} Tage Historie befüllt ({s['visits']} Besuche)")
                return
            except Exception as exc:
                _log(f"Auto-Seed-Versuch {attempt}/3 fehlgeschlagen: {exc}")
                time.sleep(5)
        _log("Auto-Seed-Fehler: Backfill nach 3 Versuchen aufgegeben.")

    threading.Thread(target=seed, daemon=True).start()


threading.Thread(target=_drip_worker, daemon=True).start()
threading.Thread(target=_history_worker, daemon=True).start()
_maybe_auto_seed()

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8092)
