#!/bin/bash
set -euo pipefail

echo "[wp-init] Warte auf WordPress-Dateien und DB ..."
until wp core is-installed --allow-root 2>/dev/null || { wp db check --allow-root 2>/dev/null && [ -f /var/www/html/wp-load.php ]; }; do
  sleep 3
done

# --- 1) WordPress-Kern installieren (falls noch nicht) ---
if ! wp core is-installed --allow-root; then
  echo "[wp-init] Installiere WordPress-Kern ..."
  wp core install --allow-root \
    --url="http://localhost:${WORDPRESS_PORT}" --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" --skip-email
  wp language core install "${WP_LOCALE}" --allow-root || true
  wp site switch-language "${WP_LOCALE}" --allow-root || true
fi

# --- 2) Numerische Admin-User-ID ermitteln (wc-Befehle wollen die ID) ---
ADMIN_ID=$(wp user get "${WP_ADMIN_USER}" --field=ID --allow-root) \
  || { echo "[wp-init] FEHLER: Admin-User '${WP_ADMIN_USER}' nicht gefunden."; exit 1; }

# --- 3) Idempotenz-Marker (frühzeitiger Ausstieg) ---
if wp option get m392_shop_seeded --allow-root >/dev/null 2>&1; then
  echo "[wp-init] Shop bereits eingerichtet – überspringe."
  exit 0
fi

# --- 4) WooCommerce + Storefront ---
echo "[wp-init] Installiere WooCommerce + Storefront ..."
if ! wp plugin is-installed woocommerce --allow-root; then
  wp plugin install woocommerce --version="${WOOCOMMERCE_VERSION}" --activate --allow-root
else
  wp plugin activate woocommerce --allow-root || true
fi
if ! wp theme is-installed storefront --allow-root; then
  wp theme install storefront --activate --allow-root
else
  wp theme activate storefront --allow-root || true
fi

# --- 5) Shop-Basis: Schweiz / CHF ---
echo "[wp-init] Konfiguriere Shop-Basis (Schweiz/CHF) ..."
wp option update woocommerce_default_country "${SHOP_COUNTRY}:*" --allow-root || true
wp option update woocommerce_currency "${SHOP_CURRENCY}" --allow-root || true
wp option update woocommerce_store_address "Bahnhofstrasse 1" --allow-root || true
wp option update woocommerce_store_city "Zürich" --allow-root || true
wp option update woocommerce_store_postcode "8001" --allow-root || true
wp option update woocommerce_onboarding_profile '{"completed":true,"skipped":true}' --format=json --allow-root || true
wp option update woocommerce_task_list_hidden "yes" --allow-root || true

# WooCommerce-Seiten (shop/cart/checkout) anlegen
wp eval 'WC_Install::create_pages();' --allow-root

# --- 6) Produktkategorien aus catalog.json anlegen ---
echo "[wp-init] Lege Produktkategorien an ..."
CATS=$(wp eval 'foreach (json_decode(file_get_contents("/seed/catalog.json"))->categories as $c) { echo $c, "\n"; }' --allow-root)
while IFS= read -r CAT; do
  [ -z "$CAT" ] && continue
  if ! wp term list product_cat --name="$CAT" --field=term_id --allow-root | grep -q .; then
    wp wc product_cat create --name="$CAT" --user="${ADMIN_ID}" --porcelain --allow-root >/dev/null 2>&1 || \
      wp term create product_cat "$CAT" --allow-root >/dev/null 2>&1 || true
  fi
done <<< "$CATS"

# --- 7) Produkte aus catalog.json anlegen ---
echo "[wp-init] Lege Produkte an ..."
PRODUCT_COUNT=$(wp eval 'echo count(json_decode(file_get_contents("/seed/catalog.json"))->products);' --allow-root)
for i in $(seq 0 $((PRODUCT_COUNT-1))); do
  IDX="$i"
  SKU=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->sku;' --allow-root)
  NAME=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->name;' --allow-root)
  PRICE=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->price;' --allow-root)
  CAT=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->category;' --allow-root)

  # Schon vorhanden? (SKU-basiert -> idempotent)
  if wp wc product list --sku="$SKU" --field=id --user="${ADMIN_ID}" --allow-root 2>/dev/null | grep -q .; then
    echo "[wp-init]  = $NAME ($SKU) bereits vorhanden"
    continue
  fi

  CAT_ID=$(wp term list product_cat --name="$CAT" --field=term_id --allow-root 2>/dev/null | head -n1)
  [ -z "$CAT_ID" ] && { echo "[wp-init] WARNUNG: Kategorie '$CAT' nicht gefunden, ueberspringe $SKU."; continue; }
  wp wc product create --name="$NAME" --sku="$SKU" --regular_price="$PRICE" \
    --type=simple --manage_stock=false --status=publish \
    --categories="[{\"id\":${CAT_ID}}]" --user="${ADMIN_ID}" --allow-root >/dev/null
  echo "[wp-init]  + $NAME ($SKU)"
done

# --- 8) Marker setzen ---
wp option update m392_shop_seeded 1 --allow-root
echo "[wp-init] Shop-Setup abgeschlossen."
