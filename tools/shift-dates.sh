#!/usr/bin/env bash
# Verschiebt alle Fixture-Zeitstempel um OFFSET Tage (Argument $1; vom Host berechnet).
# Identischer Offset für Matomo UND WooCommerce – sonst bricht die Umsatz-Kopplung.
# Aufruf (aus install.sh):  tools/shift-dates.sh <offset_days>
set -euo pipefail
OFF="${1:?offset_days fehlt}"
case "$OFF" in (''|*[!0-9-]*) echo "shift-dates: offset muss ganzzahlig sein: '$OFF'" >&2; exit 1;; esac
[ "$OFF" -eq 0 ] && { echo "[shift] offset=0 – nichts zu verschieben."; exit 0; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."
if docker compose version >/dev/null 2>&1; then DC=(docker compose); else DC=(docker-compose); fi
read_env(){ grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' "; }
RP="$(read_env MYSQL_ROOT_PASSWORD)"
MDB="$(read_env MATOMO_DB_NAME)"; MDB="${MDB:-matomo}"
WDB="$(read_env WP_DB_NAME)";     WDB="${WDB:-wordpress}"

echo "[shift] verschiebe alle Fixture-Zeitstempel um ${OFF} Tage ..."
"${DC[@]}" exec -T db mariadb -u root -p"$RP" <<SQL
SET @off = ${OFF};
-- Matomo (verifizierte datetime-Spalten)
UPDATE \`${MDB}\`.matomo_log_visit SET visit_first_action_time = visit_first_action_time + INTERVAL @off DAY, visit_last_action_time = visit_last_action_time + INTERVAL @off DAY;
UPDATE \`${MDB}\`.matomo_log_link_visit_action SET server_time = server_time + INTERVAL @off DAY;
UPDATE \`${MDB}\`.matomo_log_conversion SET server_time = server_time + INTERVAL @off DAY;
UPDATE \`${MDB}\`.matomo_log_conversion_item SET server_time = server_time + INTERVAL @off DAY;
-- WooCommerce / WP (verifizierte Tabellen/Spalten)
UPDATE \`${WDB}\`.wp_wc_orders SET date_created_gmt = date_created_gmt + INTERVAL @off DAY, date_updated_gmt = date_updated_gmt + INTERVAL @off DAY WHERE date_created_gmt IS NOT NULL OR date_updated_gmt IS NOT NULL;
UPDATE \`${WDB}\`.wp_wc_order_operational_data SET date_paid_gmt = date_paid_gmt + INTERVAL @off DAY, date_completed_gmt = date_completed_gmt + INTERVAL @off DAY WHERE date_paid_gmt IS NOT NULL OR date_completed_gmt IS NOT NULL;
UPDATE \`${WDB}\`.wp_wc_order_stats SET date_created = date_created + INTERVAL @off DAY, date_created_gmt = date_created_gmt + INTERVAL @off DAY, date_paid = date_paid + INTERVAL @off DAY, date_completed = date_completed + INTERVAL @off DAY;
UPDATE \`${WDB}\`.wp_wc_order_product_lookup SET date_created = date_created + INTERVAL @off DAY WHERE date_created IS NOT NULL;
UPDATE \`${WDB}\`.wp_wc_customer_lookup SET date_registered = date_registered + INTERVAL @off DAY, date_last_active = date_last_active + INTERVAL @off DAY WHERE date_registered IS NOT NULL OR date_last_active IS NOT NULL;
UPDATE \`${WDB}\`.wp_posts SET post_date = post_date + INTERVAL @off DAY, post_date_gmt = post_date_gmt + INTERVAL @off DAY, post_modified = post_modified + INTERVAL @off DAY, post_modified_gmt = post_modified_gmt + INTERVAL @off DAY WHERE post_type LIKE 'shop_order%';
-- postmeta: datetime-Strings (z. B. 2026-03-11 16:11:14)
UPDATE \`${WDB}\`.wp_postmeta SET meta_value = DATE_ADD(meta_value, INTERVAL @off DAY) WHERE meta_key IN ('_paid_date','_completed_date') AND meta_value <> '' AND meta_value REGEXP '^[0-9]{4}-';
-- postmeta: UNIX-Sekunden
UPDATE \`${WDB}\`.wp_postmeta SET meta_value = CAST(meta_value AS UNSIGNED) + (@off * 86400) WHERE meta_key IN ('_date_paid','_date_completed') AND meta_value REGEXP '^[0-9]+\$';
-- Kund:innen-Registrierung (admin harmlos mitverschoben)
UPDATE \`${WDB}\`.wp_users SET user_registered = user_registered + INTERVAL @off DAY WHERE user_registered IS NOT NULL;
SQL
echo "[shift] fertig."
