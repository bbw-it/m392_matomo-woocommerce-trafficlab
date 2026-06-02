#!/usr/bin/env bash
# ===========================================================================
#  M392 Matomo Lab – Hard-Reset
#
#  Setzt die GESAMTE Lehrumgebung sauber neu auf:
#    1. stoppt den Stack und loescht alle Docker-Volumes (DB, Matomo, Token)
#    2. leert den WordPress-Bind-Mount (wordpress/www)  → frische Core-Dateien
#    3. baut + startet den Stack neu (docker compose up -d --build)
#    4. wartet, bis Shop + Matomo erreichbar sind
#
#  Danach spielt wp-init die Fixture ein (sauberer Shop, OHNE Bestellungen),
#  matomo-init richtet Matomo + Ziele ein, und das Traffic Lab seedet im
#  Hintergrund ~24 Monate Historie + echte Bestellungen/Kund:innen.
#
#  ACHTUNG: Alle bisherigen Demodaten (Bestellungen, Kund:innen, Matomo-
#  Historie, von Hand in wordpress/www abgelegte Dateien) gehen verloren.
#
#  Aufruf:
#    ./reset.sh           interaktiv (mit Sicherheitsabfrage)
#    ./reset.sh -y        ohne Rueckfrage (z. B. fuer Skripte)
#    ./reset.sh --help    Hilfe
# ===========================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

ASSUME_YES=0
for arg in "$@"; do
  case "$arg" in
    -y|--yes)  ASSUME_YES=1 ;;
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

echo "============================================================"
echo "  M392 Matomo Lab – HARD RESET"
echo "============================================================"
echo "  Dies LOESCHT unwiderruflich:"
echo "    • alle Docker-Volumes (Datenbank, Matomo, API-Token)"
echo "    • den Inhalt von wordpress/www (WordPress-Dateien/Uploads)"
echo "    • alle Demodaten (Bestellungen, Kund:innen, Matomo-Historie)"
echo "  Danach wird der Stack frisch aufgebaut und neu befuellt."
echo "============================================================"

if [ "$ASSUME_YES" -ne 1 ]; then
  printf "Wirklich fortfahren? Tippe 'reset' zum Bestaetigen: "
  read -r answer
  if [ "$answer" != "reset" ]; then
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

echo
echo "============================================================"
echo "  Fertig. Der Stack laeuft:"
echo "    • Shop        →  http://localhost:${WP_PORT}"
echo "    • Matomo      →  http://localhost:${MATOMO_PORT}"
echo "    • Traffic Lab →  http://localhost:${TRAFFIC_PORT}"
echo
echo "  Hinweis: Das Traffic Lab befuellt im HINTERGRUND ~24 Monate"
echo "  Matomo-Historie und seedet echte Bestellungen/Kund:innen."
echo "  Das dauert einige Minuten – Fortschritt im Lab (Protokoll)"
echo "  oder per:  ${DC[*]} logs -f traffic"
echo "============================================================"
