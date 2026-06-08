#!/bin/bash
set -euo pipefail

FIXTURE_DB="/fixture/shop.sql.gz"
FIXTURE_UPLOADS="/fixture/uploads.tar.gz"

# .htaccess mit WordPress-Rewrite-Block sicherstellen (idempotent, im Fixture-
# Restore genutzt). Noetig, weil 'wp rewrite flush' im CLI-Kontext mod_rewrite
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
#  Fixture-Restore: das Demo-Shop-Abbild (DB-Dump + Uploads) einspielen.
#  Die Fixture ist PFLICHT – fehlt sie, bricht das Init hart ab (kein Aufbau
#  von Grund auf mehr). Nach dem Import ist WordPress vollstaendig (KEIN
#  core install / Katalog-Seeding). Idempotent ueber den Restore-Marker.
# ===========================================================================
if [ ! -f "$FIXTURE_DB" ] || [ ! -f "$FIXTURE_UPLOADS" ]; then
  echo "[wp-init] FEHLER: Fixture fehlt – benoetigt:" >&2
  echo "          $FIXTURE_DB" >&2
  echo "          $FIXTURE_UPLOADS" >&2
  echo "[wp-init] Kein Aufbau von Grund auf mehr (Legacy-Modus entfernt). Abbruch." >&2
  exit 1
fi

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

  # 8b2) Shop-Variante-Seite (A/B-Test): zeigt dieselben Produkte unter /shop-variante/
  #      mit anderer Optik (Styling kommt aus dem mu-plugin m392-ab-test.php). Idempotent.
  echo "[wp-init] Stelle Shop-Variante-Seite (/shop-variante/) sicher ..."
  cat > /tmp/m392-shopvariant.php <<'PHPEOF'
<?php
$page = get_page_by_path('shop-variante');
$content = '<!-- wp:shortcode -->[products limit="12" columns="2" orderby="popularity"]<!-- /wp:shortcode -->';
$args = ['post_title'=>'Shop-Variante','post_name'=>'shop-variante','post_status'=>'publish','post_type'=>'page','post_content'=>$content];
if ($page) { $args['ID'] = $page->ID; wp_update_post($args); } else { wp_insert_post($args); }
echo "Shop-Variante-Seite bereit\n";
PHPEOF
  wp eval-file /tmp/m392-shopvariant.php --allow-root || echo "[wp-init] WARN: Shop-Variante-Seite konnte nicht angelegt werden."

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
