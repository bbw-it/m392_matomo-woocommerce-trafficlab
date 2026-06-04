#!/bin/bash
set -euo pipefail

FIXTURE_DB="/fixture/shop.sql.gz"
FIXTURE_UPLOADS="/fixture/uploads.tar.gz"

# .htaccess mit WordPress-Rewrite-Block sicherstellen (idempotent, von beiden
# Modi genutzt). Noetig, weil 'wp rewrite flush' im CLI-Kontext mod_rewrite
# nicht erkennt und daher KEINE Rewrite-Regeln in .htaccess schreibt -> sonst
# 404 auf huebschen URLs. Der Apache des offiziellen WordPress-Images erlaubt
# .htaccess-Overrides.
ensure_htaccess() {
  local HTACCESS="/var/www/html/.htaccess"
  if ! grep -q "RewriteEngine On" "$HTACCESS" 2>/dev/null; then
    echo "[wp-init] Schreibe WordPress-Rewrite-Block in .htaccess ..."
    cat > "$HTACCESS" <<'HTEOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTEOF
  fi
}

# ===========================================================================
#  FIXTURE-MODUS: vorhandenes Demo-Shop-Abbild (DB-Dump + Uploads) einspielen.
#  Aktiv, wenn der DB-Dump existiert UND der Marker noch nicht gesetzt ist.
#  In diesem Modus ist WordPress nach dem Import vollstaendig -> KEIN
#  core install / Katalog-Seeding (das uebernimmt der Legacy-Modus als
#  Fallback, falls keine Fixture vorliegt).
# ===========================================================================
if [ -f "$FIXTURE_DB" ]; then
  echo "[wp-init] Fixture gefunden – pruefe Restore-Marker ..."

  # 1) Auf WP-Core-Dateien + DB-Erreichbarkeit warten. Im Fixture-Modus ist WP
  #    noch NICHT 'installiert' (keine Tabellen) -> nur DB-Connectivity pruefen.
  echo "[wp-init] Warte auf WordPress-Dateien und DB-Verbindung ..."
  until [ -f /var/www/html/wp-load.php ] && wp db check --allow-root >/dev/null 2>&1; do
    sleep 3
  done

  # Marker schon gesetzt? -> Restore bereits erfolgt, idempotent aussteigen.
  if wp option get m392_fixture_restored --allow-root >/dev/null 2>&1; then
    echo "[wp-init] Fixture bereits eingespielt (Marker gesetzt) – nichts zu tun."
    exit 0
  fi

  echo "[wp-init] === FIXTURE-RESTORE startet ==="

  # 2) .htaccess-Rewrite-Block sicherstellen.
  ensure_htaccess

  # 3) DB-Dump importieren (legt alle Tabellen + Optionen inkl. siteurl/home und
  #    active_plugins an).
  echo "[wp-init] Importiere DB-Dump ..."
  gunzip -c "$FIXTURE_DB" | wp db import - --allow-root
  echo "[wp-init] DB-Dump importiert."

  # 3b) active_plugins voruebergehend leeren – die Plugin-DATEIEN liegen erst
  #     nach 'wp plugin install' auf der Platte. Wuerde WP-CLI jetzt die aktiven
  #     Plugins bootstrappen, scheiterte das an fehlenden Dateien. Wir leeren die
  #     Option per direktem DB-Query (kein Plugin-Bootstrap) und aktivieren die
  #     Plugins gleich beim Installieren neu.
  echo "[wp-init] Setze active_plugins temporaer leer (DB-Query) ..."
  TABLE_PREFIX="$(wp config get table_prefix --allow-root 2>/dev/null || echo 'wp_')"
  wp db query "UPDATE ${TABLE_PREFIX}options SET option_value='a:0:{}' WHERE option_name='active_plugins';" --allow-root

  # 4) Theme + Plugins in gepinnten Versionen installieren (Dateien auf Platte).
  echo "[wp-init] Installiere Botiga-Theme (2.4.5) ..."
  wp theme install botiga --version=2.4.5 --force --allow-root || wp theme install botiga --force --allow-root
  wp theme activate botiga --allow-root

  # Plugin-Slug + gepinnte Version, je Zeile. Bei nicht verfuegbarer Version:
  # Fallback auf latest + Warnung (nicht den ganzen Run abbrechen).
  install_plugin() {
    local slug="$1" ver="$2"
    echo "[wp-init] Installiere Plugin ${slug} (${ver}) ..."
    if wp plugin install "$slug" --version="$ver" --force --activate --allow-root; then
      return 0
    fi
    echo "[wp-init] WARNUNG: ${slug} ${ver} nicht verfuegbar – Fallback auf latest."
    wp plugin install "$slug" --force --activate --allow-root \
      || echo "[wp-init] WARNUNG: ${slug} konnte nicht installiert werden."
  }

  install_plugin woocommerce 9.5.1
  install_plugin elementor 4.1.1
  install_plugin athemes-addons-for-elementor-lite 1.1.9
  install_plugin athemes-starter-sites 1.1.9
  install_plugin merchant 2.2.7
  install_plugin wpforms-lite 1.10.1

  # 4b) Deutsche Sprachpakete installieren. Die .mo-Dateien liegen NICHT im
  #     DB-Dump/Uploads-Archiv und muessen aus wp.org nachgeladen werden, damit
  #     WooCommerce/WP-Strings auf Deutsch erscheinen (Locale de_CH kommt aus dem Dump).
  echo "[wp-init] Installiere deutsche Sprachpakete (de_CH) ..."
  wp language core install de_CH --allow-root >/dev/null 2>&1 || true
  wp language plugin install --all de_CH --allow-root >/dev/null 2>&1 || true
  wp language theme install --all de_CH --allow-root >/dev/null 2>&1 || true
  wp site switch-language de_CH --allow-root >/dev/null 2>&1 || true

  # 5) Uploads-Archiv entpacken (legt wp-content/uploads/... an).
  if [ -f "$FIXTURE_UPLOADS" ]; then
    echo "[wp-init] Entpacke Uploads-Archiv ..."
    tar xzf "$FIXTURE_UPLOADS" -C /var/www/html/wp-content
    chown -R 33:33 /var/www/html/wp-content/uploads 2>/dev/null \
      || echo "[wp-init] (chown uploads uebersprungen – vermutlich bereits Eigentuemer)"
  else
    echo "[wp-init] WARNUNG: Uploads-Archiv ${FIXTURE_UPLOADS} fehlt."
  fi

  # 6) Port-Korrektheit: falls abweichender Port, in allen Tabellen ersetzen.
  if [ "${WORDPRESS_PORT}" != "8090" ]; then
    echo "[wp-init] Passe Port 8090 -> ${WORDPRESS_PORT} an (search-replace) ..."
    wp search-replace 'localhost:8090' "localhost:${WORDPRESS_PORT}" --all-tables --allow-root || true
  fi
  wp option update home "http://localhost:${WORDPRESS_PORT}" --allow-root
  wp option update siteurl "http://localhost:${WORDPRESS_PORT}" --allow-root

  # 7) Admin-Account aus .env durchsetzen (damit Login-Creds funktionieren).
  if wp user get "${WP_ADMIN_USER}" --allow-root >/dev/null 2>&1; then
    echo "[wp-init] Aktualisiere Admin-User '${WP_ADMIN_USER}' ..."
    ADMIN_ID=$(wp user get "${WP_ADMIN_USER}" --field=ID --allow-root)
    wp user update "$ADMIN_ID" --user_pass="${WP_ADMIN_PASSWORD}" --user_email="${WP_ADMIN_EMAIL}" --allow-root
  else
    echo "[wp-init] Lege Admin-User '${WP_ADMIN_USER}' an ..."
    wp user create "${WP_ADMIN_USER}" "${WP_ADMIN_EMAIL}" \
      --role=administrator --user_pass="${WP_ADMIN_PASSWORD}" --allow-root
  fi

  # 8) Schoene Permalinks.
  wp rewrite structure '/%postname%/' --allow-root
  wp rewrite flush --allow-root || true
  ensure_htaccess

  # 8b) Danke-Seite + Kontaktformular-Weiterleitung sicherstellen.
  #     Das Kontaktformular (WPForms) leitet nach dem Absenden auf /danke/ weiter,
  #     damit Matomo den Seitenaufruf als Ziel „Kontaktanfrage" zaehlen kann.
  echo "[wp-init] Stelle Danke-Seite + Kontaktformular-Weiterleitung sicher ..."
  cat > /tmp/m392-thankyou.php <<'PHPEOF'
<?php
// 1) Danke-Seite (idempotent ueber Slug "danke")
$page = get_page_by_path('danke');
$content = "<!-- wp:paragraph -->\n<p>Vielen Dank für deine Nachricht! Wir haben deine Anfrage erhalten und melden uns so bald wie möglich bei dir – in der Regel innerhalb von ein bis zwei Werktagen.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>In der Zwischenzeit kannst du gerne weiter in unserem Sortiment stöbern.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"/shop/\">Weiter zum Shop</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
$args = ['post_title'=>'Vielen Dank','post_name'=>'danke','post_status'=>'publish','post_type'=>'page','post_content'=>$content];
if ($page) { $args['ID'] = $page->ID; $id = wp_update_post($args); } else { $id = wp_insert_post($args); }

// 2) WPForms-Kontaktformular auf Seiten-Weiterleitung umstellen (ID 438, sonst erstes Formular)
$form = get_post(438);
if (!$form || $form->post_type !== 'wpforms') {
  $forms = get_posts(['post_type'=>'wpforms','numberposts'=>1,'post_status'=>'any']);
  $form = $forms ? $forms[0] : null;
}
if ($form) {
  $cfg = json_decode($form->post_content, true);
  if (is_array($cfg) && isset($cfg['settings'])) {
    if (!isset($cfg['settings']['confirmations']['1'])) { $cfg['settings']['confirmations']['1'] = []; }
    $cfg['settings']['confirmations']['1']['type'] = 'page';
    $cfg['settings']['confirmations']['1']['page'] = (string)$id;
    $cfg['settings']['confirmations']['1']['redirect'] = '';
    wp_update_post(['ID'=>$form->ID, 'post_content'=>wp_slash(wp_json_encode($cfg))]);
    echo "Danke-Seite {$id}, Formular {$form->ID} -> Seiten-Weiterleitung\n";
  }
}
PHPEOF
  wp eval-file /tmp/m392-thankyou.php --allow-root || echo "[wp-init] WARN: Danke-Seite/Formular-Weiterleitung konnte nicht gesetzt werden."

  # 8c) Bestseller-Prior setzen: total_sales je Produkt aus catalog.json (popularity).
  #     Die Fixture ist bestellungsfrei (total_sales=0). Damit Bestellungen vom ersten
  #     Lab-Seed an KLAR gewichtete Bestseller zeigen – deckungsgleich mit der Matomo-
  #     Bestseller-Gewichtung – wird hier ein Startwert gesetzt. Lab-Bestellungen
  #     erhoehen total_sales anschliessend weiter.
  echo "[wp-init] Setze Bestseller-Prior (total_sales aus catalog.json) ..."
  cat > /tmp/m392-bestseller.php <<'PHPEOF'
<?php
$cat = json_decode(file_get_contents('/seed/catalog.json'), true);
if (is_array($cat) && !empty($cat['products'])) {
  foreach ($cat['products'] as $cp) {
    $pid = 0;
    if (!empty($cp['slug'])) { $o = get_page_by_path($cp['slug'], OBJECT, 'product'); if ($o) { $pid = (int) $o->ID; } }
    if (!$pid && !empty($cp['sku']) && preg_match('/^wc_(\d+)$/', $cp['sku'], $m)) { $pid = (int) $m[1]; }
    if ($pid) {
      $p = wc_get_product($pid);
      if ($p) { $p->set_total_sales((int) ($cp['popularity'] ?? 0)); $p->save(); }
    }
  }
  echo "Bestseller-Prior gesetzt\n";
}
PHPEOF
  wp eval-file /tmp/m392-bestseller.php --allow-root || echo "[wp-init] WARN: Bestseller-Prior konnte nicht gesetzt werden."

  # 8d) Produktkategorien aus catalog.json anlegen + Produkte zuweisen.
  #     Ersetzt die generische „Kosmetik"-Kategorie durch mehrere passende
  #     Kategorien (Gesichtsreinigung/-pflege/Make-up). Idempotent.
  echo "[wp-init] Setze Produktkategorien aus catalog.json ..."
  cat > /tmp/m392-categories.php <<'PHPEOF'
<?php
$cat = json_decode(file_get_contents('/seed/catalog.json'), true);
if (!is_array($cat) || empty($cat['categories'])) { return; }
$slug2term = [];
foreach ($cat['categories'] as $c) {
  $term = get_term_by('slug', $c['slug'], 'product_cat');
  if (!$term) {
    $res = wp_insert_term($c['name'], 'product_cat', ['slug' => $c['slug']]);
    if (!is_wp_error($res)) { $slug2term[$c['slug']] = (int) $res['term_id']; }
  } else {
    wp_update_term($term->term_id, 'product_cat', ['name' => $c['name']]);
    $slug2term[$c['slug']] = (int) $term->term_id;
  }
}
foreach ($cat['products'] as $cp) {
  $pid = 0;
  if (!empty($cp['slug'])) { $o = get_page_by_path($cp['slug'], OBJECT, 'product'); if ($o) { $pid = (int) $o->ID; } }
  if (!$pid && !empty($cp['sku']) && preg_match('/^wc_(\d+)$/', $cp['sku'], $m)) { $pid = (int) $m[1]; }
  if ($pid && !empty($cp['category_slug']) && isset($slug2term[$cp['category_slug']])) {
    wp_set_object_terms($pid, [$slug2term[$cp['category_slug']]], 'product_cat', false);
  }
}
// Alte generische Kategorie entfernen (Produkte sind bereits umgehaengt).
foreach (['cosmetics', 'kosmetik'] as $old) {
  $t = get_term_by('slug', $old, 'product_cat');
  if ($t) { wp_delete_term($t->term_id, 'product_cat'); }
}
echo "Kategorien gesetzt\n";
PHPEOF
  wp eval-file /tmp/m392-categories.php --allow-root || echo "[wp-init] WARN: Kategorien konnten nicht gesetzt werden."

  # 8e) Rabattgutschein aus catalog.json anlegen (wird vom Traffic Lab „ab und zu"
  #     auf Bestellungen angewendet). Idempotent.
  echo "[wp-init] Lege Rabattgutschein aus catalog.json an ..."
  cat > /tmp/m392-coupon.php <<'PHPEOF'
<?php
$cat = json_decode(file_get_contents('/seed/catalog.json'), true);
$c = is_array($cat) ? ($cat['coupon'] ?? null) : null;
if (!$c || empty($c['code']) || !class_exists('WC_Coupon')) { return; }
if (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($c['code']) > 0) {
  echo "Gutschein bereits vorhanden\n"; return;
}
$coupon = new WC_Coupon();
$coupon->set_code($c['code']);
$coupon->set_discount_type($c['discount_type'] ?? 'percent');
$coupon->set_amount((float) ($c['amount'] ?? 10));
if (!empty($c['description'])) { $coupon->set_description($c['description']); }
$coupon->set_individual_use(true);
$coupon->save();
echo "Gutschein {$c['code']} angelegt\n";
PHPEOF
  wp eval-file /tmp/m392-coupon.php --allow-root || echo "[wp-init] WARN: Gutschein konnte nicht angelegt werden."

  # 8f) Verkaufsländer auf DE/CH/AT beschränken (nur aus diesen Ländern wird
  #     bestellt; übriges Europa kann ansehen, aber nicht kaufen).
  echo "[wp-init] Beschränke Verkaufsländer auf DE/CH/AT ..."
  wp option update woocommerce_allowed_countries specific --allow-root || true
  wp option update woocommerce_specific_allowed_countries '["DE","CH","AT"]' --format=json --allow-root || true

  # 8g) HPOS-Kompatibilitaetsmodus: Bestellungen zusaetzlich in posts/postmeta
  #     synchronisieren. WICHTIG, damit die ALTE WooCommerce-Berichte-Seite
  #     (WooCommerce -> Berichte, liest aus postmeta) funktioniert. Wird VOR dem
  #     Bestell-Seed des Traffic Labs gesetzt, sodass jede Bestellung sofort
  #     mitsynchronisiert wird (kein nachtraegliches Backfill noetig).
  echo "[wp-init] Aktiviere HPOS-Kompatibilitaetsmodus (Sync Orders -> posts) ..."
  wp option update woocommerce_custom_orders_table_data_sync_enabled yes --allow-root || true

  # 9) Caches leeren (inkl. best-effort Elementor-Cache).
  wp cache flush --allow-root || true
  wp eval 'if(class_exists("\\Elementor\\Plugin")){ \Elementor\Plugin::$instance->files_manager->clear_cache(); echo "elementor-cache-cleared"; }' --allow-root || true

  # 10) Marker setzen + Erfolg melden.
  wp option update m392_fixture_restored 1 --allow-root
  echo "[wp-init] === FIXTURE-RESTORE erfolgreich abgeschlossen ==="
  echo "[wp-init] Aktive Plugins: $(wp plugin list --status=active --field=name --allow-root | tr '\n' ',')"
  exit 0
fi

# ===========================================================================
#  LEGACY-MODUS (Fallback): kein Fixture-Abbild vorhanden -> Shop von Grund auf
#  aufbauen (Core-Install, Katalog-Produkte, Botiga, Produktbilder). Bleibt
#  unveraendert erhalten, falls jemand die Fixture loescht.
# ===========================================================================
echo "[wp-init] Keine Fixture gefunden – Legacy-Setup (Aufbau von Grund auf)."

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

# --- 3) Idempotenz-Marker pruefen ---
# Frueher: frueher Ausstieg (exit 0). Jetzt: nur der einmalige Seed-Block
# (WooCommerce-Install + Produkt-ERSTELLUNG) wird uebersprungen. Das Skript
# laeuft danach weiter und fuehrt die idempotenten Schritte (Theme, Bilder,
# Gateways) bei JEDEM Aufruf erneut aus.
if wp option get m392_shop_seeded --allow-root >/dev/null 2>&1; then
  SHOP_SEEDED=1
  echo "[wp-init] Shop-Basis bereits eingerichtet – ueberspringe Seed-Block, fuehre Erweiterungen aus."
else
  SHOP_SEEDED=0
fi

if [ "$SHOP_SEEDED" = "0" ]; then
  # --- 4) WooCommerce + Storefront ---
  echo "[wp-init] Installiere WooCommerce + Storefront ..."
  if ! wp plugin is-installed woocommerce --allow-root; then
    wp plugin install woocommerce --version="${WOOCOMMERCE_VERSION}" --activate --allow-root
  else
    wp plugin activate woocommerce --allow-root || true
  fi
  if ! wp theme is-installed storefront --allow-root; then
    wp theme install storefront --allow-root
  fi

  # --- 5) Shop-Basis: Deutschland / EUR ---
  echo "[wp-init] Konfiguriere Shop-Basis (Deutschland/EUR) ..."
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
  echo "[wp-init] Shop-Basis-Setup abgeschlossen."
fi

# ===========================================================================
#  IDEMPOTENTE ERWEITERUNGEN – laufen bei JEDEM Aufruf (auch auf bestehendem
#  Volume), jeweils einzeln idempotent. Voraussetzung: WooCommerce ist aktiv.
# ===========================================================================
if ! wp plugin is-active woocommerce --allow-root >/dev/null 2>&1; then
  echo "[wp-init] WooCommerce nicht aktiv – ueberspringe Erweiterungen (Theme/Bilder)."
  echo "[wp-init] Fertig."
  exit 0
fi

# --- 9) Botiga-Theme installieren/aktivieren (idempotent) ---
if [ "$(wp theme list --status=active --field=name --allow-root)" != "botiga" ]; then
  echo "[wp-init] Installiere/aktiviere Botiga-Theme ..."
  wp theme install botiga --activate --allow-root
else
  echo "[wp-init] Botiga-Theme bereits aktiv."
fi

# Startseite auf die WooCommerce-"Shop"-Seite setzen (idempotent) ---
SHOP_PAGE_ID=$(wp option get woocommerce_shop_page_id --allow-root 2>/dev/null || echo "")
if [ -n "$SHOP_PAGE_ID" ] && [ "$SHOP_PAGE_ID" != "0" ]; then
  wp option update show_on_front page --allow-root
  wp option update page_on_front "$SHOP_PAGE_ID" --allow-root
fi

# Schoene Permalinks (Botiga/WooCommerce), idempotent ---
wp rewrite structure '/%postname%/' --allow-root >/dev/null 2>&1 || true
wp rewrite flush --allow-root >/dev/null 2>&1 || true

# .htaccess mit WordPress-Rewrite-Block sicherstellen (idempotent).
# Noetig, weil 'wp rewrite flush' im CLI-Kontext mod_rewrite nicht erkennt und
# daher KEINE Rewrite-Regeln in .htaccess schreibt -> sonst 404 auf huebschen
# URLs (Produktseiten, /kasse/, /checkout/order-received/...). Der Apache des
# offiziellen WordPress-Images erlaubt .htaccess-Overrides.
HTACCESS="/var/www/html/.htaccess"
if ! grep -q "RewriteEngine On" "$HTACCESS" 2>/dev/null; then
  echo "[wp-init] Schreibe WordPress-Rewrite-Block in .htaccess ..."
  cat > "$HTACCESS" <<'HTEOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HTEOF
fi

# --- 10) Produktbilder (HYBRID: echtes Foto -> sonst Platzhalter), idempotent ---
echo "[wp-init] Pruefe/setze Produktbilder ..."

# Platzhalter-Generator on-the-fly nach /tmp schreiben (kein neuer Mount noetig).
PH_SCRIPT="/tmp/make-placeholder.php"
cat > "$PH_SCRIPT" <<'PHPEOF'
<?php
if ($argc < 4) { fwrite(STDERR, "Usage: php make-placeholder.php <name> <category> <output.png>\n"); exit(1); }
$name = $argv[1]; $category = $argv[2]; $out = $argv[3];
$W = 800; $H = 800;
$palette = array(
    'Bekleidung'  => array(0x2E, 0x6F, 0x95),
    'Accessoires' => array(0x8A, 0x55, 0x9A),
    'Haushalt'    => array(0x2A, 0x9D, 0x8F),
    'Elektronik'  => array(0xE7, 0x6F, 0x51),
);
$rgb = isset($palette[$category]) ? $palette[$category] : array(0x4A, 0x4A, 0x4A);
$img = imagecreatetruecolor($W, $H);
for ($y = 0; $y < $H; $y++) {
    $t = $y / $H; $f = 1.0 - 0.45 * $t;
    $col = imagecolorallocate($img, (int)round($rgb[0]*$f), (int)round($rgb[1]*$f), (int)round($rgb[2]*$f));
    imagefilledrectangle($img, 0, $y, $W, $y, $col);
}
imagesetthickness($img, 6);
imagerectangle($img, 24, 24, $W - 24, $H - 24, imagecolorallocatealpha($img, 0xFF, 0xFF, 0xFF, 100));
function draw_scaled_line($dst, $text, $cy, $scale, $color_rgb) {
    $font = 5; $cw = imagefontwidth($font); $ch = imagefontheight($font);
    $tw = $cw * strlen($text); $th = $ch; if ($tw <= 0) return;
    $tmp = imagecreatetruecolor($tw, $th);
    imagealphablending($tmp, false); imagesavealpha($tmp, true);
    imagefilledrectangle($tmp, 0, 0, $tw, $th, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
    $tc = imagecolorallocate($tmp, $color_rgb[0], $color_rgb[1], $color_rgb[2]);
    imagestring($tmp, $font, 0, 0, $text, $tc);
    $dw = (int)round($tw * $scale); $dh = (int)round($th * $scale);
    $dx = (int)round((imagesx($dst) - $dw) / 2); $dy = (int)round($cy - $dh / 2);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $tmp, $dx, $dy, 0, 0, $dw, $dh, $tw, $th);
    imagedestroy($tmp);
}
function wrap_words($text, $max) {
    $tokens = preg_split('/\s+/', trim($text)); $words = array();
    foreach ($tokens as $tok) {
        if ($tok === '') continue;
        foreach (preg_split('/(?<=-)/', $tok) as $p) {
            if ($p === '') continue;
            while (strlen($p) > $max) { $words[] = substr($p, 0, $max); $p = substr($p, $max); }
            $words[] = $p;
        }
    }
    $lines = array(); $cur = '';
    foreach ($words as $w) {
        $sep = ($cur !== '' && substr($cur, -1) !== '-') ? ' ' : '';
        $cand = ($cur === '') ? $w : $cur . $sep . $w;
        if (strlen($cand) <= $max) { $cur = $cand; }
        else { if ($cur !== '') $lines[] = $cur; $cur = $w; }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}
$ascii = strtr($name, array('ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss'));
$tmp = @iconv('UTF-8', 'ASCII//TRANSLIT', $ascii);
$ascii = ($tmp === false) ? preg_replace('/[^\x20-\x7E]/', '', $name) : $tmp;
$lines = wrap_words($ascii, 14);
$font = 5; $cw = imagefontwidth($font); $maxLen = 1;
foreach ($lines as $ln) $maxLen = max($maxLen, strlen($ln));
$scale = min(5.5, ($W * 0.80) / ($cw * $maxLen));
$lineGap = (int)round(imagefontheight($font) * $scale * 1.35);
$startY = ($H / 2) - (count($lines) - 1) * $lineGap / 2;
foreach ($lines as $li => $ln) draw_scaled_line($img, $ln, $startY + $li * $lineGap, $scale, array(255, 255, 255));
$catAscii = @iconv('UTF-8', 'ASCII//TRANSLIT', $category); if ($catAscii === false) $catAscii = $category;
draw_scaled_line($img, strtoupper($catAscii), 120, 3.0, array(234, 234, 234));
draw_scaled_line($img, 'M392 DEMO-SHOP', $H - 110, 2.0, array(234, 234, 234));
imagepng($img, $out, 6); imagedestroy($img);
if (!file_exists($out) || filesize($out) < 1000) { fwrite(STDERR, "Placeholder generation failed\n"); exit(1); }
echo $out, "\n";
PHPEOF

PRODUCT_COUNT=$(wp eval 'echo count(json_decode(file_get_contents("/seed/catalog.json"))->products);' --allow-root)
for i in $(seq 0 $((PRODUCT_COUNT-1))); do
  IDX="$i"
  SKU=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->sku;' --allow-root)
  NAME=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->name;' --allow-root)
  CAT=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->category;' --allow-root)
  KEYWORD=$(IDX="$IDX" wp eval 'echo json_decode(file_get_contents("/seed/catalog.json"))->products[getenv("IDX")]->image_keyword ?? "";' --allow-root)

  PID=$(wp wc product list --sku="$SKU" --field=id --user="${ADMIN_ID}" --allow-root 2>/dev/null | head -n1)
  if [ -z "$PID" ]; then
    echo "[wp-init]  ! Produkt $SKU nicht gefunden – ueberspringe Bild."
    continue
  fi

  # Schon ein Beitragsbild? -> idempotent ueberspringen.
  THUMB=$(wp post meta get "$PID" _thumbnail_id --allow-root 2>/dev/null || echo "")
  if [ -n "$THUMB" ] && [ "$THUMB" != "0" ]; then
    echo "[wp-init]  = Bild: $NAME bereits vorhanden (ueberspringe)"
    continue
  fi

  TMP="/tmp/img_${SKU}.img"
  rm -f "$TMP"
  SOURCE=""

  # 1) PRIMARY: echtes Foto von LoremFlickr (erstes Keyword).
  KW1=$(echo "$KEYWORD" | cut -d',' -f1)
  if [ -n "$KW1" ]; then
    if curl -fsSL --max-time 20 "https://loremflickr.com/800/800/${KW1}" -o "$TMP" 2>/dev/null; then
      # Validierung: Groesse > 5000 Bytes UND echte Bild-Magic-Bytes (JPEG/PNG/GIF).
      SIZE=$(wc -c < "$TMP" 2>/dev/null | tr -d ' ')
      MAGIC=$(od -An -tx1 -N4 "$TMP" 2>/dev/null | tr -d ' \n')
      case "$MAGIC" in
        ffd8ff*|89504e47|47494638) IS_IMG=1 ;;
        *) IS_IMG=0 ;;
      esac
      if [ "${SIZE:-0}" -gt 5000 ] && [ "$IS_IMG" = "1" ]; then
        SOURCE="Foto"
      fi
    fi
  fi

  # 2) FALLBACK: lokaler Platzhalter (GD), wenn kein gueltiges Foto.
  if [ -z "$SOURCE" ]; then
    rm -f "$TMP"
    TMP="/tmp/img_${SKU}.png"
    if php "$PH_SCRIPT" "$NAME" "$CAT" "$TMP" >/dev/null 2>&1; then
      SOURCE="Platzhalter"
    else
      echo "[wp-init]  ! Bild: $NAME konnte nicht erzeugt werden – ueberspringe."
      continue
    fi
  else
    # Foto: Endung auf .jpg normalisieren fuer sauberen Import-Dateinamen.
    NEWTMP="/tmp/img_${SKU}.jpg"
    mv "$TMP" "$NEWTMP" && TMP="$NEWTMP"
  fi

  # Import + als Beitragsbild setzen.
  if wp media import "$TMP" --post_id="$PID" --featured_image --allow-root >/dev/null 2>&1; then
    echo "[wp-init]  Bild: $NAME (Quelle: $SOURCE)"
  else
    echo "[wp-init]  ! Bild-Import fehlgeschlagen fuer $NAME."
  fi
  rm -f "$TMP"
done

# --- 11) Platzhalter fuer kuenftige Gateway-Schritte (laeuft bei jedem Aufruf) ---
# (Noch keine Zahlungs-Gateways konfiguriert – Schritt bewusst als no-op belassen.)
echo "[wp-init] (Gateway-Schritte: derzeit keine – Platzhalter)"

echo "[wp-init] Fertig."
