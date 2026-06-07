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
# Kommentare: nur die fuehrenden Ziffern behalten, sonst Fallback 90.
BACKFILL_DAYS="$(read_env TRAFFIC_BACKFILL_DAYS)"; BACKFILL_DAYS="${BACKFILL_DAYS%%[!0-9]*}"
BACKFILL_DAYS="${BACKFILL_DAYS:-90}"
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

# --- M392-Report-Plugins aktivieren (A/B-Testing, Funnels) ------------------
# Sicher: `console plugin:activate` schreibt die VOLLSTAENDIGE Plugin-Liste
# (inkl. Login/Auth) – im Gegensatz zu einem manuellen [Plugins]-Eintrag, der
# die Default-Plugins ersetzen und Matomo lahmlegen wuerde. Idempotent.
echo
echo "   M392-Report-Plugins aktivieren (A/B-Testing, Funnels) ..."
i=0
until "${DC[@]}" exec -T matomo sh -c 'grep -q "^\[PluginsInstalled\]" /var/www/html/config/config.ini.php' 2>/dev/null; do
  i=$((i + 1)); [ "$i" -gt 60 ] && break; sleep 3   # ~3 min auf Matomo-Installation warten
done
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

if [ "$WAIT_SEED" -eq 1 ]; then
  echo
  echo "[5/5] Startbefuellung laeuft (${HIST_LABEL} + Bestellungen) ..."
  echo "      Das dauert einige Minuten – die Fortschrittsbalken unten zeigen live,"
  echo "      wie weit Historie und Bestellungen sind. Danach ist Matomo SOFORT fertig."
  echo

  # --- Kosmetik: Docker-aehnliche Fortschrittsbalken ----------------------
  # Liest weiterhin /api/ready (UNVERAENDERTE Logik!) und rendert die dort
  # gemeldeten done/total-Werte pro Phase als Balken. Ohne TTY (Pipe/CI) ->
  # einfache Zeilen statt Steuercodes, damit Logs sauber bleiben.
  BAR_W=28
  CHECK=$'\xe2\x9c\x93'                       # ✓
  WARN=$'\xe2\x9a\xa0'                         # ⚠
  SPIN=(⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏)
  if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_DIM=$'\033[2m'; C_CYAN=$'\033[36m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BOLD=$'\033[1m'; TTY=1
  else
    C_RESET=''; C_DIM=''; C_CYAN=''; C_GREEN=''; C_YELLOW=''; C_BOLD=''; TTY=0
  fi

  _bar() {  # $1=done $2=total -> "<cyan ████░░░> <bold>NN%</bold>"
    local dn="${1:-0}" tot="${2:-0}" w="$BAR_W" pct=0 filled=0 k out=''
    [[ "$dn"  =~ ^[0-9]+$ ]] || dn=0
    [[ "$tot" =~ ^[0-9]+$ ]] || tot=0
    if [ "$tot" -gt 0 ]; then
      pct=$(( dn * 100 / tot )); [ "$pct" -gt 100 ] && pct=100
      filled=$(( dn * w / tot )); [ "$filled" -gt "$w" ] && filled=$w
    fi
    for ((k=0; k<filled; k++)); do out+='█'; done
    for ((k=0; k<w-filled; k++)); do out+='░'; done
    printf '%s%s%s %s%3d%%%s' "$C_CYAN" "$out" "$C_RESET" "$C_BOLD" "$pct" "$C_RESET"
  }

  _ibar() {  # unbestimmter Balken (Phase "bereitet vor"): $1=frame
    local pos=$(( ${1:-0} % BAR_W )) k out=''
    for ((k=0; k<BAR_W; k++)); do
      if [ "$k" -ge "$pos" ] && [ "$k" -lt $((pos + 3)) ]; then out+='▓'; else out+='░'; fi
    done
    printf '%s%s%s' "$C_DIM" "$out" "$C_RESET"
  }

  # parse_phase BODY LABEL -> PH_STATE(num|done|prep|none) PH_DONE PH_TOTAL
  parse_phase() {
    local body="$1" label="$2" frac
    PH_STATE=none; PH_DONE=0; PH_TOTAL=0
    if printf '%s' "$body" | grep -qE "${label} [0-9]+/[0-9]+"; then
      frac="$(printf '%s' "$body" | grep -oE "${label} [0-9]+/[0-9]+" | head -1 | grep -oE '[0-9]+/[0-9]+' || true)"
      PH_DONE="${frac%/*}"; PH_TOTAL="${frac#*/}"; PH_STATE=num
    elif printf '%s' "$body" | grep -qF "${label} ${CHECK}"; then
      PH_STATE=done; PH_DONE=1; PH_TOTAL=1
    elif printf '%s' "$body" | grep -qF "${label} ${WARN}"; then
      PH_STATE=warn
    elif printf '%s' "$body" | grep -q "$label"; then
      PH_STATE=prep
    fi
  }

  _phase_line() {  # $1=Label $2=state $3=done $4=total $5=frame
    local name="$1" st="$2" d="$3" t="$4" fr="$5" full='' k
    case "$st" in
      num)  printf '   %-12s %s   %s%s/%s%s' "$name" "$(_bar "$d" "$t")" "$C_DIM" "$d" "$t" "$C_RESET" ;;
      done) for ((k=0; k<BAR_W; k++)); do full+='█'; done
            printf '   %-12s %s%s%s %s100%%%s   %s%s fertig%s' \
              "$name" "$C_GREEN" "$full" "$C_RESET" "$C_BOLD" "$C_RESET" "$C_GREEN" "$CHECK" "$C_RESET" ;;
      prep) printf '   %-12s %s   %sbereitet vor …%s' "$name" "$(_ibar "$fr")" "$C_DIM" "$C_RESET" ;;
      warn) printf '   %-12s %s%s uebersprungen / Fehler%s' "$name" "$C_YELLOW" "$WARN" "$C_RESET" ;;
      *)    printf '   %-12s %s   %s—%s'              "$name" "$(_ibar "$fr")" "$C_DIM" "$C_RESET" ;;
    esac
  }

  start=$(date +%s)
  i=0
  drew=0
  ROWS=4                                      # Kopfzeile + 2 Phasen + Besuche-Zeile
  while :; do
    code="$(curl -s -o /tmp/m392_ready.$$ -w '%{http_code}' "http://localhost:${TRAFFIC_PORT}/api/ready" 2>/dev/null || echo 000)"
    body="$(head -1 /tmp/m392_ready.$$ 2>/dev/null | tr -d '\r\n')"
    [ -z "$body" ] && body="wartet auf Traffic-Container …"
    now=$(date +%s); el=$((now - start)); mm=$((el / 60)); ss=$((el % 60))
    parse_phase "$body" "Historie";     H_ST="$PH_STATE"; H_D="$PH_DONE"; H_T="$PH_TOTAL"
    parse_phase "$body" "Bestellungen"; O_ST="$PH_STATE"; O_D="$PH_DONE"; O_T="$PH_TOTAL"
    vis="$(printf '%s' "$body" | grep -oE '[0-9]+ Besuche' | head -1 | grep -oE '[0-9]+' || true)"; vis="${vis:-0}"
    sc="${SPIN[$(( i % 10 ))]}"

    if [ "$TTY" -eq 1 ]; then
      [ "$drew" -eq 1 ] && printf '\033[%dA' "$ROWS"      # Cursor zurueck an den Block-Anfang
      printf '\r\033[K %s%s%s  %sStartbefuellung laeuft%s   %s%02d:%02d%s\n' \
        "$C_CYAN" "$sc" "$C_RESET" "$C_BOLD" "$C_RESET" "$C_DIM" "$mm" "$ss" "$C_RESET"
      printf '\r\033[K%s\n' "$(_phase_line 'Historie'     "$H_ST" "$H_D" "$H_T" "$i")"
      printf '\r\033[K%s\n' "$(_phase_line 'Bestellungen' "$O_ST" "$O_D" "$O_T" "$i")"
      printf '\r\033[K   %s%s Besuche erzeugt%s\n' "$C_DIM" "$vis" "$C_RESET"
      drew=1
    else
      [ $(( i % 5 )) -eq 0 ] && printf '   … %s  (%02d:%02d)\n' "$body" "$mm" "$ss"
    fi

    if [ "$code" = "200" ]; then
      printf '   %s%s Startbefuellung abgeschlossen%s   (%02d:%02d)\n' "$C_GREEN" "$CHECK" "$C_RESET" "$mm" "$ss"
      break
    fi
    i=$((i + 1))
    if [ "$i" -gt 600 ]; then   # ~20 min Sicherheits-Timeout (2s-Takt)
      printf '\n      (Timeout – Befuellung laeuft im Hintergrund weiter:\n'
      printf '       %s logs -f traffic)\n' "${DC[*]}"
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
