"""Flask-Steuerpult für den Traffic-Generator (Modul 392)."""
import os
import threading
import time

from flask import Flask, jsonify, render_template, request

import generator

app = Flask(__name__)

STATE = {
    "live_drip": os.environ.get("TRAFFIC_LIVE_DRIP", "true").lower() == "true",
    "conversion_rate": float(os.environ.get("TRAFFIC_CONVERSION_RATE", "0.04")),
    "drip_per_min": int(os.environ.get("TRAFFIC_DRIP_VISITS_PER_MIN", "3")),
    "totals": {"visits": 0, "purchases": 0, "revenue": 0.0},
    "last_log": [],
}
LOCK = threading.Lock()


def _log(msg):
    with LOCK:
        STATE["last_log"].insert(0, msg)
        STATE["last_log"] = STATE["last_log"][:20]


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
        if STATE["live_drip"]:
            try:
                s = generator.generate_visits(1, conversion_rate=STATE["conversion_rate"])
                _accumulate(s)
            except Exception as exc:
                _log(f"Drip-Fehler: {exc}")
        per_min = max(1, STATE["drip_per_min"])
        time.sleep(max(1.0, 60.0 / per_min))


@app.route("/")
def index():
    return render_template("index.html", state=STATE)


@app.route("/api/status")
def status():
    with LOCK:
        return jsonify({
            "live_drip": STATE["live_drip"],
            "conversion_rate": STATE["conversion_rate"],
            "totals": STATE["totals"],
            "log": STATE["last_log"],
        })


@app.route("/api/generate-visits", methods=["POST"])
def gen_visits():
    count = int(request.form.get("count", 50))
    s = generator.generate_visits(count, conversion_rate=STATE["conversion_rate"])
    _accumulate(s)
    _log(f"{count} Besuche erzeugt → {s['purchases']} Käufe, CHF {s['revenue']:.2f}")
    return jsonify(s)


@app.route("/api/generate-orders", methods=["POST"])
def gen_orders():
    count = int(request.form.get("count", 10))
    s = generator.generate_orders(count)
    _accumulate({"purchases": s["purchases"], "revenue": s["revenue"]})
    _log(f"{count} Käufe erzwungen → CHF {s['revenue']:.2f}")
    return jsonify(s)


@app.route("/api/backfill", methods=["POST"])
def backfill():
    days = int(request.form.get("days", 28))
    s = generator.backfill(days, conversion_rate=STATE["conversion_rate"])
    _accumulate(s)
    _log(f"Backfill {days} Tage → {s['visits']} Besuche, {s['purchases']} Käufe")
    return jsonify(s)


@app.route("/api/toggle-drip", methods=["POST"])
def toggle_drip():
    with LOCK:
        STATE["live_drip"] = not STATE["live_drip"]
        state = STATE["live_drip"]
    _log(f"Live-Tropf {'aktiviert' if state else 'pausiert'}")
    return jsonify({"live_drip": state})


@app.route("/api/set-conversion", methods=["POST"])
def set_conversion():
    with LOCK:
        STATE["conversion_rate"] = max(0.0, min(1.0, float(request.form.get("rate", 0.04))))
        rate = STATE["conversion_rate"]
    return jsonify({"conversion_rate": rate})


def _maybe_auto_seed():
    if os.environ.get("TRAFFIC_AUTO_SEED", "true").lower() != "true":
        return
    days = int(os.environ.get("TRAFFIC_BACKFILL_DAYS", "28"))

    def seed():
        # Auf Matomo-Bereitschaft warten (installiert + gueltiger Token),
        # statt blind 20 s zu schlafen. Verhindert das Rennen mit matomo-init,
        # damit der historische Backfill (braucht token_auth) zuverlaessig landet.
        _log("Auto-Seed: warte auf Matomo-Bereitschaft (Installation + Token) ...")
        if not generator.wait_for_ready(timeout=600):
            _log("Auto-Seed-Fehler: Matomo nicht rechtzeitig bereit (Timeout).")
            return
        # Ein paar Wiederholungen, falls die allererste Anfrage noch hakt.
        for attempt in range(1, 4):
            try:
                s = generator.backfill(days, conversion_rate=STATE["conversion_rate"])
                _accumulate(s)
                _log(f"Auto-Seed: {days} Tage Historie befüllt ({s['visits']} Besuche)")
                return
            except Exception as exc:
                _log(f"Auto-Seed-Versuch {attempt}/3 fehlgeschlagen: {exc}")
                time.sleep(5)
        _log("Auto-Seed-Fehler: Backfill nach 3 Versuchen aufgegeben.")

    threading.Thread(target=seed, daemon=True).start()


threading.Thread(target=_drip_worker, daemon=True).start()
_maybe_auto_seed()

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8092)
