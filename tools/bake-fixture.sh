#!/usr/bin/env bash
# ===========================================================================
#  Fixture backen (einmalig, manuell): erzeugt frische Historie + WC-Bestellungen
#  mit den Parametern aus tools/bake.conf, dumpt die Roh-Tabellen und friert sie
#  als matomo/fixture/*.sql.gz ein. Beim Install werden sie nur noch restauriert
#  + datums-verschoben (tools/shift-dates.sh) statt 15-30 Min neu generiert.
#
#  ACHTUNG: destruktiv – fährt den Stack mit `down -v` frisch hoch (alle Volumes
#  weg) und seedet neu (dauert ~15-30 Min, je nach HISTORY_DAYS).
#
#  Aufruf:
#    ./tools/bake-fixture.sh        interaktiv (Sicherheitsabfrage)
#    ./tools/bake-fixture.sh -y     ohne Rueckfrage (z. B. fuer Skripte)
#    ./tools/bake-fixture.sh --help Hilfe
# ===========================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."
FIX_DIR="matomo/fixture"

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

# bake.conf laden + exportieren (vom Compose-Override interpoliert).
[ -f tools/bake.conf ] || { echo "FEHLER: tools/bake.conf fehlt." >&2; exit 1; }
set -a; . tools/bake.conf; set +a

if docker compose version >/dev/null 2>&1; then DC=(docker compose); else DC=(docker-compose); fi
DCB=("${DC[@]}" -f docker-compose.yml -f docker-compose.bake.yml)
read_env(){ grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' "; }
RP="$(read_env MYSQL_ROOT_PASSWORD)"
MDB="$(read_env MATOMO_DB_NAME)"; MDB="${MDB:-matomo}"
WDB="$(read_env WP_DB_NAME)";     WDB="${WDB:-wordpress}"
TP="$(read_env TRAFFIC_PORT)";    TP="${TP:-8092}"
dbq(){ "${DC[@]}" exec -T db mariadb -u root -p"$RP" -N -e "$1" 2>/dev/null; }

echo "============================================================"
echo "  Fixture backen – HISTORY_DAYS=${HISTORY_DAYS}, REVENUE=${AVG_MONTHLY_REVENUE}"
echo "============================================================"
echo "  ACHTUNG: destruktiv – der laufende Stack wird mit 'down -v'"
echo "  neu aufgebaut (alle Volumes/Demodaten weg) und ~15-30 Min"
echo "  frisch geseedet. Bestehende Fixture-Artefakte in ${FIX_DIR}/"
echo "  werden ueberschrieben."
echo "============================================================"

if [ "$ASSUME_YES" -ne 1 ]; then
  printf "Backen starten? Tippe 'bake' zum Bestaetigen: "
  read -r answer
  if [ "$answer" != "bake" ]; then
    echo "Abgebrochen."
    exit 0
  fi
fi

echo "[bake] Stack frisch hochfahren (mit Bake-Override, generiert Historie) ..."
"${DCB[@]}" down -v --remove-orphans
if [ -d wordpress/www ]; then
  docker run --rm -v "$SCRIPT_DIR/../wordpress/www:/w" alpine:3.20 \
    sh -c 'rm -rf /w/* /w/.[!.]* /w/..?* 2>/dev/null || true'
fi
mkdir -p wordpress/www
"${DCB[@]}" up -d --build

echo "[bake] Warte auf Seed-Abschluss (/api/ready=200; kann ~15-30 Min dauern) ..."
ok=0
for i in $(seq 1 900); do   # ~45 min @ 3s
  code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${TP}/api/ready" 2>/dev/null || echo 000)"
  [ "$code" = "200" ] && { ok=1; break; }
  [ "$code" = "500" ] && { echo "[bake] FEHLER: Seed meldete Fehler (/api/ready=500). Abbruch." >&2; exit 1; }
  sleep 3
done
[ "$ok" = "1" ] || { echo "[bake] FEHLER: Seed nicht rechtzeitig fertig. Abbruch." >&2; exit 1; }
echo "[bake] Seed fertig. Archiviere (für Konsistenz-Check) ..."
"${DCB[@]}" exec -T matomo php /var/www/html/console core:archive --force-idsites=1 --url="http://localhost/" >/dev/null 2>&1 || true

mkdir -p "$FIX_DIR"

echo "[bake] Dumpe Matomo-Roh-Logs (+ log_action, OHNE archive) ..."
"${DC[@]}" exec -T db mariadb-dump -u root -p"$RP" --no-tablespaces --single-transaction \
  "$MDB" matomo_log_action matomo_log_visit matomo_log_link_visit_action \
         matomo_log_conversion matomo_log_conversion_item \
  | gzip > "$FIX_DIR/matomo-history.sql.gz"

echo "[bake] Ermittle Kund:innen-IDs für den Teil-Dump ..."
CUST_IDS="$(dbq "SELECT GROUP_CONCAT(DISTINCT customer_id) FROM ${WDB}.wp_wc_orders WHERE customer_id>0;")"
CUST_IDS="${CUST_IDS:-0}"

# HPOS: Order-Daten liegen kanonisch in wp_wc_*. Die shop_order-Platzhalter-Posts
# (wp_posts) + deren postmeta werden BEWUSST NICHT gedumpt – ihre IDs kollidieren
# beim Restore mit bestehenden shop.sql.gz-Posts (HPOS vergibt die Post-ID aus der
# wp_wc_orders-Sequenz). WC-Admin + wc-admin-Analytics lesen aus wp_wc_*.
echo "[bake] Dumpe WooCommerce-Order-Tabellen (HPOS) + Kund:innen ..."
{
  "${DC[@]}" exec -T db mariadb-dump -u root -p"$RP" --no-tablespaces --single-transaction --no-create-info \
    "$WDB" wp_wc_orders wp_wc_orders_meta wp_wc_order_operational_data wp_wc_order_addresses \
           wp_wc_order_stats wp_wc_order_product_lookup wp_wc_order_coupon_lookup \
           wp_wc_order_tax_lookup wp_wc_customer_lookup
  "${DC[@]}" exec -T db mariadb-dump -u root -p"$RP" --no-tablespaces --single-transaction --no-create-info \
    "$WDB" wp_users --where="ID IN (${CUST_IDS})"
  # usermeta MIT dumpen (Rolle wp_capabilities, billing_*): ohne sie haben die
  # restaurierten Kund:innen keine Rolle/Adresse, und der Bestandskunden-Pool
  # der Order-API waere nach dem Install praktisch leer.
  "${DC[@]}" exec -T db mariadb-dump -u root -p"$RP" --no-tablespaces --single-transaction --no-create-info \
    "$WDB" wp_usermeta --where="user_id IN (${CUST_IDS})"
} | gzip > "$FIX_DIR/wc-orders.sql.gz"

date +%F > "$FIX_DIR/BASE"

VISITS="$(dbq "SELECT COUNT(*) FROM ${MDB}.matomo_log_visit;")"
CONV="$(dbq "SELECT COUNT(*) FROM ${MDB}.matomo_log_conversion WHERE idgoal=0;")"
ORDERS="$(dbq "SELECT COUNT(*) FROM ${WDB}.wp_wc_orders;")"
NET="$(dbq "SELECT ROUND(SUM(net_total)) FROM ${WDB}.wp_wc_order_stats;")"
{
  echo "# Fixture-Bake-Info (von tools/bake-fixture.sh)"
  echo "BAKED_AT=$(date +%FT%T)"
  echo "GIT=$(git rev-parse --short HEAD 2>/dev/null || echo '?')"
  echo "HISTORY_DAYS=${HISTORY_DAYS}"
  echo "AVG_MONTHLY_REVENUE=${AVG_MONTHLY_REVENUE}"
  echo "CONVERSION_RATE=${CONVERSION_RATE}"
  echo "RETURNING_RATE=${RETURNING_RATE}"
  echo "OFFSET_ROUNDING=${OFFSET_ROUNDING}"
  echo "MATOMO_VISITS=${VISITS}  MATOMO_ECOMMERCE_CONV=${CONV}"
  echo "WC_ORDERS=${ORDERS}  WC_NET_TOTAL=${NET}"
} > "$FIX_DIR/BAKE-INFO"

echo "============================================================"
echo "  Bake fertig. Artefakte in ${FIX_DIR}/ :"
ls -lh "$FIX_DIR"
echo "  Mengen: Matomo ${VISITS} Visits / ${CONV} E-Comm-Conv · WC ${ORDERS} Orders / EUR ${NET} netto"
echo "  Nächste Schritte: Artefakte committen, install.sh fixture-only (B4)."
echo "============================================================"
