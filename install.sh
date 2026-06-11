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
#    5. spielt die vorgebackene Fixture ein (Matomo-Historie + WC-Bestellungen),
#       verschiebt alle Datumswerte auf „heute" (tools/shift-dates.sh) und
#       archiviert Matomo → Berichte stimmen SOFORT (kein langes Generieren)
#
#  wp-init spielt den Shop ein (shop.sql.gz), matomo-init installiert Matomo +
#  Ziele/Dimension; install.sh restauriert dann matomo/fixture/* und shiftet die
#  Daten. Mit --no-wait wird nur die Archivierung uebersprungen (Matomo holt sie nach).
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

# .env MUSS vorhanden sein. Fehlt sie, liefe der Stack mit leeren Variablen
# (leere Passwoerter/DB-Namen/Ports) los, ohne dass es auffaellt – darum hier
# hart abbrechen, bevor irgendetwas (Volumes!) angefasst wird.
if [ ! -f .env ]; then
  echo "FEHLER: Keine .env im Projektverzeichnis gefunden ($SCRIPT_DIR)." >&2
  if [ -f .env.example ]; then
    echo "        Kopiere die Vorlage .env.example und benenne die Kopie in .env um:" >&2
    echo "            cp .env.example .env" >&2
    echo "        (Werte bei Bedarf anpassen) und starte install.sh erneut." >&2
  else
    echo "        Auch .env.example fehlt – Repository unvollstaendig?" >&2
  fi
  exit 1
fi

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

# (Historienlänge/Umsatz/CR sind in der Fixture eingebacken – siehe tools/bake.conf.)

# --- Fortschrittsanzeige -----------------------------------------------------
# run_spin "Label" cmd args... : fuehrt cmd im Hintergrund aus und zeigt solange
# einen Spinner mit mitlaufender Uhr, damit auch lange/stumme Schritte (DB-Import,
# Archivierung) sichtbar „leben". Interaktiv (TTY) animiert per \r, sonst schlichte
# Punkte (saubere Logs bei -y / Pipe). cmd-Ausgaben werden mitgeschnitten und nur
# im Fehlerfall (eingerueckt) gezeigt – so bleibt die Spinner-Zeile sauber.
# Rueckgabewert = Exitcode von cmd.
SPIN_FRAMES=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
HAVE_TTY=0; [ -t 1 ] && HAVE_TTY=1
fmt_elapsed() { printf '%d:%02d' $(( $1 / 60 )) $(( $1 % 60 )); }
# Aktuelles run_spin-Log auch bei Abbruch (Ctrl+C/TERM) aufraeumen, sonst bleiben
# Temp-Dateien unter ${TMPDIR:-/tmp}/install_spin.* liegen.
SPIN_LOG=""
trap '[ -z "$SPIN_LOG" ] || rm -f "$SPIN_LOG"' EXIT
run_spin() {
  local label="$1"; shift
  local start="$SECONDS" i=0 rc=0 pid log mark
  log="$(mktemp "${TMPDIR:-/tmp}/install_spin.XXXXXX")"
  SPIN_LOG="$log"
  "$@" >"$log" 2>&1 &
  pid=$!
  if [ "$HAVE_TTY" -eq 1 ]; then
    while kill -0 "$pid" 2>/dev/null; do
      printf '\r   %s %s … (%s)\033[K' \
        "${SPIN_FRAMES[i++ % ${#SPIN_FRAMES[@]}]}" "$label" "$(fmt_elapsed $(( SECONDS - start )))"
      sleep 0.1
    done
    wait "$pid" || rc=$?
    mark=✓; [ "$rc" -ne 0 ] && mark=✗
    printf '\r   %s %s (%s)\033[K\n' "$mark" "$label" "$(fmt_elapsed $(( SECONDS - start )))"
  else
    printf '   %s … ' "$label"
    while kill -0 "$pid" 2>/dev/null; do printf '.'; sleep 3; done
    wait "$pid" || rc=$?
    mark=✓; [ "$rc" -ne 0 ] && mark=✗
    printf ' %s (%s)\n' "$mark" "$(fmt_elapsed $(( SECONDS - start )))"
  fi
  [ "$rc" -ne 0 ] && [ -s "$log" ] && sed 's/^/        /' "$log" >&2
  rm -f "$log"
  SPIN_LOG=""
  return "$rc"
}

# Stille Poll-Loops fuer run_spin (geben nur 0=ok / !=0=Fehler zurueck).
http_ready() {  # $1=URL  -> wartet bis 200/30x antwortet (~6 min Timeout)
  local i=0
  until curl -s -o /dev/null -w '%{http_code}' "$1" 2>/dev/null | grep -qE '^(200|30[0-9])$'; do
    i=$((i + 1)); [ "$i" -gt 120 ] && return 1
    sleep 3
  done
}
init_done() {  # $1=service -> wartet bis Init-Container mit Exit 0 beendet ist
  local cid i=0
  cid="$("${DC[@]}" ps -aq "$1" 2>/dev/null | head -1)"
  [ -z "$cid" ] && { echo "kein Container gefunden." >&2; return 2; }
  until [ "$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null)" = "exited" ]; do
    i=$((i + 1)); [ "$i" -gt 200 ] && { echo "Timeout." >&2; return 1; }
    sleep 3
  done
  [ "$(docker inspect -f '{{.State.ExitCode}}' "$cid" 2>/dev/null)" = "0" ] || { echo "Exit ungleich 0." >&2; return 1; }
}
matomo_config_ready() {  # wartet bis Matomo installiert ist (config.ini hat [PluginsInstalled])
  local i=0
  until "${DC[@]}" exec -T matomo sh -c 'grep -q "^\[PluginsInstalled\]" /var/www/html/config/config.ini.php' 2>/dev/null; do
    i=$((i + 1)); [ "$i" -gt 60 ] && return 1
    sleep 3
  done
}

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
echo "[1/5] Stack stoppen + Volumes loeschen ..."
"${DC[@]}" down -v --remove-orphans

echo
echo "[2/5] WordPress-Bind-Mount (wordpress/www) leeren ..."
# In einem Wegwerf-Container als root leeren – funktioniert plattformuebergreifend
# (auch wenn die Dateien www-data/33 gehoeren). .htaccess & versteckte Dateien inkl.
if [ -d "wordpress/www" ]; then
  docker run --rm -v "$SCRIPT_DIR/wordpress/www:/w" alpine:3.20 \
    sh -c 'rm -rf /w/* /w/.[!.]* /w/..?* 2>/dev/null || true'
fi
mkdir -p wordpress/www

echo
echo "[3/5] Stack neu bauen + starten ..."
"${DC[@]}" up -d --build

echo
echo "[4/5] Warte auf Shop + Matomo (das kann ~1–2 Minuten dauern) ..."
SETUP_OK=1
run_spin "Shop erreichbar"   http_ready "http://localhost:${WP_PORT}/"     || SETUP_OK=0
run_spin "Matomo erreichbar" http_ready "http://localhost:${MATOMO_PORT}/" || SETUP_OK=0
if [ "$SETUP_OK" -ne 1 ]; then
  echo >&2
  echo "FEHLER: Shop und/oder Matomo nicht erreichbar – Installation abgebrochen." >&2
  echo "        Logs:  ${DC[*]} logs" >&2
  exit 1
fi

# --- M392-Report-Plugins aktivieren (A/B-Testing, Funnels) ------------------
# Sicher: `console plugin:activate` schreibt die VOLLSTAENDIGE Plugin-Liste
# (inkl. Login/Auth) – im Gegensatz zu einem manuellen [Plugins]-Eintrag, der
# die Default-Plugins ersetzen und Matomo lahmlegen wuerde. Idempotent.
echo
echo "   M392-Report-Plugins aktivieren (A/B-Testing, Funnels) ..."
run_spin "Matomo-Installation bereit" matomo_config_ready || true
for P in M392ABTesting M392Funnels; do
  if "${DC[@]}" exec -T -u www-data matomo ./console plugin:activate "$P" >/dev/null 2>&1; then
    echo "      ✓ ${P} aktiviert"
  else
    echo "      (— ${P} konnte nicht aktiviert werden – Report-Seite ggf. nicht verfuegbar)"
  fi
done
# Cache leeren, damit die eigenen Sidebar-Kategorien (Category/Subcategory/Widget)
# „M392 · Funnel" und „M392 · A/B-Test" sofort im Berichtsmenue erscheinen.
"${DC[@]}" exec -T -u www-data matomo ./console core:clear-caches >/dev/null 2>&1 || true
echo "      → Berichtsmenue: „Funnels\" → „Trichter (M392)\" und „A/B Tests\" → „Vergleich (M392)\""

if [ "$WAIT_SEED" -eq 1 ]; then ARCHIVE_WAIT=1; else ARCHIVE_WAIT=0; fi
echo
echo "[5/5] Fixture restaurieren, Datum auf heute verschieben, archivieren ..."

FIX_DIR="matomo/fixture"
RP="$(read_env MYSQL_ROOT_PASSWORD)"
MDB="$(read_env MATOMO_DB_NAME)"; MDB="${MDB:-matomo}"
WDB="$(read_env WP_DB_NAME)";     WDB="${WDB:-wordpress}"
RESTORE_OK=1; SHIFT_OK=1; ARCHIVE_OK=0

# Spielt beide Fixture-Dumps ein (Pipeline → eigene Funktion fuer run_spin).
restore_fixture() {
  gunzip -c "$FIX_DIR/matomo-history.sql.gz" | "${DC[@]}" exec -T db mariadb -u root -p"$RP" "$MDB" \
    && gunzip -c "$FIX_DIR/wc-orders.sql.gz" | "${DC[@]}" exec -T db mariadb -u root -p"$RP" "$WDB"
}
echo "      Warte auf Init-/Restore-Container (wp-init, matomo-init) ..."
run_spin "wp-init abgeschlossen"     init_done wp-init     || RESTORE_OK=0
run_spin "matomo-init abgeschlossen" init_done matomo-init || RESTORE_OK=0

if [ "$RESTORE_OK" -eq 1 ] && [ -f "$FIX_DIR/matomo-history.sql.gz" ] && [ -f "$FIX_DIR/wc-orders.sql.gz" ] && [ -f "$FIX_DIR/BASE" ]; then
  run_spin "Fixture einspielen (Matomo-Historie + WC-Bestellungen)" restore_fixture \
    || { echo "      FEHLER: Fixture-Restore (DB-Import) fehlgeschlagen." >&2; RESTORE_OK=0; }
elif [ "$RESTORE_OK" -eq 1 ]; then
  echo "      FEHLER: Fixture-Artefakte fehlen in $FIX_DIR/ - erst backen: ./tools/bake-fixture.sh" >&2
  RESTORE_OK=0
fi

if [ "$RESTORE_OK" -eq 1 ]; then
  BASE_DATE="$(cat "$FIX_DIR/BASE" 2>/dev/null || true)"
  base=$(date -d "$BASE_DATE" +%s 2>/dev/null || date -j -f "%Y-%m-%d" "$BASE_DATE" +%s 2>/dev/null || echo "")
  if [ -n "$base" ]; then
    OFFSET=$(( ( $(date +%s) - base ) / 86400 ))
    [ "$(grep -E '^OFFSET_ROUNDING=' tools/bake.conf 2>/dev/null | cut -d= -f2 | tr -d ' ')" = "week" ] && OFFSET=$(( (OFFSET / 7) * 7 ))
    run_spin "Zeitstempel auf heute verschieben (+${OFFSET} Tage, Anker ${BASE_DATE})" \
      ./tools/shift-dates.sh "$OFFSET" || SHIFT_OK=0
  else
    echo "      FEHLER: BASE-Datum unlesbar: '$BASE_DATE'." >&2; SHIFT_OK=0
  fi
fi

if [ "$RESTORE_OK" -eq 1 ] && [ "$SHIFT_OK" -eq 1 ]; then
  # WICHTIG: Matomo archiviert NICHTS vor dem Site-Erstelldatum. Die restaurierte
  # Historie liegt vor "heute" (Install-Tag) → ts_created auf den Datenanfang setzen,
  # sonst bleiben alle Alt-Monate leer (nur "heute" würde archiviert).
  DMIN="$("${DC[@]}" exec -T db mariadb -u root -p"$RP" -N -e \
        "SELECT DATE(MIN(visit_first_action_time)) FROM \`${MDB}\`.matomo_log_visit;" 2>/dev/null | tr -d '\r')"
  if [ -n "$DMIN" ] && [ "$DMIN" != "NULL" ]; then
    "${DC[@]}" exec -T db mariadb -u root -p"$RP" -e \
      "UPDATE \`${MDB}\`.matomo_site SET ts_created='${DMIN} 00:00:00' WHERE idsite=1;" >/dev/null 2>&1 || true
    run_spin "Berichtsdaten invalidieren (${DMIN} … heute)" \
      "${DC[@]}" exec -T matomo php /var/www/html/console core:invalidate-report-data \
      --sites=1 --dates="${DMIN},$(date +%F)" --periods=day --cascade || true
  fi
fi

if [ "$RESTORE_OK" -eq 1 ] && [ "$SHIFT_OK" -eq 1 ] && [ "$ARCHIVE_WAIT" -eq 1 ]; then
  if run_spin "Matomo archivieren (gesamte Historie vorberechnen)" \
       "${DC[@]}" exec -T matomo php /var/www/html/console core:archive \
       --force-idsites=1 --url="http://localhost/"; then
    ARCHIVE_OK=1
  else
    echo "      (Archivierung fehlgeschlagen - beim ersten Bericht-Aufruf holt Matomo es nach.)"; ARCHIVE_OK=0
  fi
fi

echo
echo "============================================================"
echo "  Der Stack laeuft:"
echo "    - Shop        ->  http://localhost:${WP_PORT}"
echo "    - Matomo      ->  http://localhost:${MATOMO_PORT}"
echo "    - Traffic Lab ->  http://localhost:${TRAFFIC_PORT}"
echo
FINAL_EXIT=0
if [ "${RESTORE_OK:-0}" -ne 1 ] || [ "${SHIFT_OK:-0}" -ne 1 ]; then
  echo "  Status: FEHLGESCHLAGEN - Fixture-Restore/Shift nicht erfolgreich." >&2
  echo "  Logs:  ${DC[*]} logs" >&2
  FINAL_EXIT=1
elif [ "$ARCHIVE_WAIT" -eq 0 ]; then
  echo "  Status: OK (--no-wait) - Fixture eingespielt + verschoben; Archivierung uebersprungen"
  echo "  (Matomo archiviert beim ersten Bericht-Aufruf)."
elif [ "${ARCHIVE_OK:-0}" -eq 1 ]; then
  echo "  Status: OK - Fixture eingespielt, auf heute verschoben UND archiviert; Berichte stimmen sofort."
else
  echo "  Status: TEILWEISE - Fixture+Shift ok, Archivierung fehlte (Matomo holt es beim ersten Bericht nach)." >&2
  FINAL_EXIT=2
fi
echo "============================================================"
exit "$FINAL_EXIT"
