#!/usr/bin/env bash
# ===========================================================================
#  M392 Matomo Lab – Installation
#
#  Richtet die GESAMTE Lehrumgebung von Grund auf ein:
#    1. stoppt einen evtl. laufenden Stack und loescht alle Docker-Volumes
#       (DB, Matomo, Token) – fuer eine reproduzierbare Installation
#    2. leert den WordPress-Bind-Mount (wordpress/www)  → frische Core-Dateien
#    3. baut + startet den Stack (docker compose up -d --build)
#    4. wartet, bis Shop + Matomo erreichbar sind
#    5. wartet, bis die Historie (TRAFFIC_BACKFILL_DAYS Tage) + Bestellungen
#       und archiviert Matomo → Berichte stimmen SOFORT (kein Nachladen)
#
#  wp-init spielt die Fixture ein (sauberer Shop, OHNE Bestellungen),
#  matomo-init richtet Matomo + Ziele ein, das Traffic Lab seedet Historie
#  + echte Bestellungen/Kund:innen. Mit --no-wait kehrt das Skript schon nach
#  Schritt 4 zurueck (Befuellung laeuft dann im Hintergrund weiter).
#
#  ACHTUNG: Eine bereits vorhandene Installation wird vollstaendig ersetzt –
#  alle bisherigen Demodaten (Bestellungen, Kund:innen, Matomo-Historie, von
#  Hand in wordpress/www abgelegte Dateien) gehen dabei verloren.
#
#  Aufruf:
#    ./install.sh           interaktiv, wartet auf Befuellung + archiviert
#    ./install.sh -y        ohne Rueckfrage (z. B. fuer Skripte)
#    ./install.sh --no-wait nicht auf Befuellung warten (schnell zurueck)
#    ./install.sh --help    Hilfe
# ===========================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

ASSUME_YES=0
WAIT_SEED=1
for arg in "$@"; do
  case "$arg" in
    -y|--yes)      ASSUME_YES=1 ;;
    --no-wait)     WAIT_SEED=0 ;;
    -h|--help)
      awk 'NR>=2 && /^#/ {sub(/^# ?/,""); print; next} NR>=2 {exit}' "$0"
      exit 0 ;;
    *) echo "Unbekannte Option: $arg (siehe --help)"; exit 1 ;;
  esac
done

# Docker Compose v2 (Plugin) bevorzugt, sonst docker-compose.
if docker compose version >/dev/null 2>&1; then
  DC=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
  DC=(docker-compose)
else
  echo "FEHLER: weder 'docker compose' noch 'docker-compose' gefunden." >&2
  exit 1
fi

# Port aus .env lesen (mit Fallback), nur fuer die Ausgabe/Warte-Checks.
read_env() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' " ; }
WP_PORT="$(read_env WORDPRESS_PORT)"; WP_PORT="${WP_PORT:-8090}"
MATOMO_PORT="$(read_env MATOMO_PORT)"; MATOMO_PORT="${MATOMO_PORT:-8091}"
TRAFFIC_PORT="$(read_env TRAFFIC_PORT)"; TRAFFIC_PORT="${TRAFFIC_PORT:-8092}"

# Backfill-Fenster aus .env lesen, damit die Meldungen zum tatsaechlich
# gesetzten Wert passen (statt fixem „6 Monate"). Robust gegen Inline-
# Kommentare: nur die fuehrenden Ziffern behalten, sonst Fallback 180.
BACKFILL_DAYS="$(read_env TRAFFIC_BACKFILL_DAYS)"; BACKFILL_DAYS="${BACKFILL_DAYS%%[!0-9]*}"
BACKFILL_DAYS="${BACKFILL_DAYS:-180}"
BACKFILL_MONTHS=$(( (BACKFILL_DAYS + 15) / 30 )); [ "$BACKFILL_MONTHS" -lt 1 ] && BACKFILL_MONTHS=1
HIST_LABEL="~${BACKFILL_DAYS} Tage (~${BACKFILL_MONTHS} Monate) Historie"

echo "============================================================"
echo "  M392 Matomo Lab – INSTALLATION"
echo "============================================================"
echo "  Richtet die Lehrumgebung frisch ein und startet sie."
echo "  Eine bestehende Installation wird dabei zurueckgesetzt –"
echo "  unwiderruflich entfernt werden:"
echo "    • alle Docker-Volumes (Datenbank, Matomo, API-Token)"
echo "    • der Inhalt von wordpress/www (WordPress-Dateien/Uploads)"
echo "    • alle Demodaten (Bestellungen, Kund:innen, Matomo-Historie)"
echo "============================================================"

if [ "$ASSUME_YES" -ne 1 ]; then
  printf "Installation starten? Tippe 'install' zum Bestaetigen: "
  read -r answer
  if [ "$answer" != "install" ]; then
    echo "Abgebrochen."
    exit 0
  fi
fi

echo
echo "[1/4] Stack stoppen + Volumes loeschen ..."
"${DC[@]}" down -v --remove-orphans

echo
echo "[2/4] WordPress-Bind-Mount (wordpress/www) leeren ..."
# In einem Wegwerf-Container als root leeren – funktioniert plattformuebergreifend
# (auch wenn die Dateien www-data/33 gehoeren). .htaccess & versteckte Dateien inkl.
if [ -d "wordpress/www" ]; then
  docker run --rm -v "$SCRIPT_DIR/wordpress/www:/w" alpine:3.20 \
    sh -c 'rm -rf /w/* /w/.[!.]* /w/..?* 2>/dev/null || true'
fi
mkdir -p wordpress/www

echo
echo "[3/4] Stack neu bauen + starten ..."
"${DC[@]}" up -d --build

echo
echo "[4/4] Warte auf Shop + Matomo (das kann ~1–2 Minuten dauern) ..."
wait_http() {  # $1=URL  $2=Label
  local i=0
  until curl -s -o /dev/null -w '%{http_code}' "$1" 2>/dev/null | grep -qE '^(200|30[0-9])$'; do
    i=$((i + 1))
    if [ "$i" -gt 120 ]; then   # ~6 min
      echo " — $2 nicht erreichbar (Timeout). Logs: ${DC[*]} logs"
      return 1
    fi
    printf '.'; sleep 3
  done
  echo " — $2 erreichbar."
}
printf '   Shop   '; wait_http "http://localhost:${WP_PORT}/"        "Shop"   || true
printf '   Matomo '; wait_http "http://localhost:${MATOMO_PORT}/"    "Matomo" || true

if [ "$WAIT_SEED" -eq 1 ]; then
  echo
  echo "[5/5] Startbefuellung laeuft (${HIST_LABEL} + Bestellungen) ..."
  echo "      Das dauert einige Minuten. Fortschritt live unten – Spinner + Uhr"
  echo "      zeigen, dass gearbeitet wird. Danach ist Matomo SOFORT vollstaendig."
  spin='|/-\'
  start=$(date +%s)
  i=0
  while :; do
    code="$(curl -s -o /tmp/m392_ready.$$ -w '%{http_code}' "http://localhost:${TRAFFIC_PORT}/api/ready" 2>/dev/null || echo 000)"
    line="$(head -1 /tmp/m392_ready.$$ 2>/dev/null | tr -d '\r\n')"
    [ -z "$line" ] && line="verbinde mit Traffic-Container ..."
    now=$(date +%s); el=$((now - start)); mm=$((el / 60)); ss=$((el % 60))
    # Zeile zuerst mit Leerzeichen ueberschreiben (\r ... \r), dann neu zeichnen –
    # so bleiben keine Reste stehen und Multibyte-Zeichen (·/✓) werden nicht zerschnitten.
    if [ "$code" = "200" ]; then
      printf '\r%-79s\r   \xe2\x9c\x93  %s   %02d:%02d\n' "" "$line" "$mm" "$ss"
      break
    fi
    sc="${spin:$((i % 4)):1}"
    printf '\r%-79s\r   %s  %s   %02d:%02d ' "" "$sc" "$line" "$mm" "$ss"
    i=$((i + 1))
    if [ "$i" -gt 600 ]; then   # ~20 min Sicherheits-Timeout (2s-Takt)
      printf '\n'
      echo "      (Timeout – Befuellung laeuft im Hintergrund weiter:"
      echo "       ${DC[*]} logs -f traffic)"
      break
    fi
    sleep 2
  done
  rm -f /tmp/m392_ready.$$

  echo
  echo "      Archiviere Matomo (Berichte vorberechnen) ..."
  "${DC[@]}" exec -T matomo php /var/www/html/console core:archive \
      --force-idsites=1 --url="http://localhost/" >/dev/null 2>&1 \
      && echo "      Archivierung abgeschlossen." \
      || echo "      (Archivierung uebersprungen/fehlgeschlagen – beim ersten Bericht-Aufruf holt Matomo es nach.)"
fi

echo
echo "============================================================"
echo "  Installation abgeschlossen. Der Stack laeuft:"
echo "    • Shop        →  http://localhost:${WP_PORT}"
echo "    • Matomo      →  http://localhost:${MATOMO_PORT}"
echo "    • Traffic Lab →  http://localhost:${TRAFFIC_PORT}"
if [ "$WAIT_SEED" -eq 1 ]; then
  echo
  echo "  Matomo ist vorbefuellt UND archiviert – Berichte stimmen sofort."
  echo "  (In Matomo ggf. Zeitraum auf die letzten ~${BACKFILL_MONTHS} Monate stellen.)"
else
  echo
  echo "  Hinweis (--no-wait): Die ${HIST_LABEL} + Bestellungen werden"
  echo "  im HINTERGRUND befuellt. Fortschritt:  ${DC[*]} logs -f traffic"
  echo "  Berichte erscheinen erst nach Befuellung + Archivierung vollstaendig."
fi
echo "============================================================"
