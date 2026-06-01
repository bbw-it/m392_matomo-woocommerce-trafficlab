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
