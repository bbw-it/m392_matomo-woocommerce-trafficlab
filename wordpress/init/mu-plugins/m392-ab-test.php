<?php
/**
 * Plugin Name: M392 A/B-Test (Shop-Variante)
 * Description: A/B-Test der Shop-Seite für die Lehrumgebung Modul 392. Variante
 *  "Original" = /shop/, Variante "Shop-Variante" = /shop-variante/ (sichtbar anders).
 *  Besucher:innen werden fix (Cookie) einer Variante zugewiesen; die Variante wird
 *  in der Matomo-Custom-Dimension 1 ("AB-Variante") getrackt. So lässt sich in
 *  Matomo vergleichen, welche Variante besser konvertiert.
 */
if (!defined('ABSPATH')) { exit; }

const M392_AB_COOKIE   = 'm392_ab';
const M392_AB_ORIGINAL  = 'Original';
const M392_AB_VARIANTE  = 'Shop-Variante';

function m392_ab_enabled() {
    $v = getenv('M392_AB_TEST_ENABLED');
    if ($v === false && isset($_SERVER['M392_AB_TEST_ENABLED'])) { $v = $_SERVER['M392_AB_TEST_ENABLED']; }
    return strtolower(trim((string) ($v === false ? 'true' : $v))) === 'true';
}

function m392_ab_split_b() {
    $v = getenv('M392_AB_SPLIT_B');
    if ($v === false && isset($_SERVER['M392_AB_SPLIT_B'])) { $v = $_SERVER['M392_AB_SPLIT_B']; }
    $n = (int) preg_replace('/[^0-9].*$/', '', (string) $v);
    return max(0, min(100, $n ?: 50));
}

/** Zugewiesene Variante (Cookie-sticky); weist beim ersten Besuch nach Split zu. */
function m392_ab_variant() {
    static $variant = null;
    if ($variant !== null) { return $variant; }
    if (!m392_ab_enabled()) { return $variant = ''; }
    $c = isset($_COOKIE[M392_AB_COOKIE]) ? (string) $_COOKIE[M392_AB_COOKIE] : '';
    if ($c === M392_AB_ORIGINAL || $c === M392_AB_VARIANTE) {
        return $variant = $c;
    }
    $variant = (mt_rand(1, 100) <= m392_ab_split_b()) ? M392_AB_VARIANTE : M392_AB_ORIGINAL;
    if (!headers_sent()) {
        setcookie(M392_AB_COOKIE, $variant, time() + 30 * 86400, COOKIEPATH ?: '/');
        $_COOKIE[M392_AB_COOKIE] = $variant;
    }
    return $variant;
}

/** Der Variante "Shop-Variante" zugewiesene Besucher:innen vom /shop/ auf
 *  /shop-variante/ schicken – so sehen sie wirklich die andere Variante. */
add_action('template_redirect', function () {
    if (!m392_ab_enabled() || is_admin()) { return; }
    if (function_exists('is_shop') && is_shop() && m392_ab_variant() === M392_AB_VARIANTE) {
        $url = home_url('/shop-variante/');
        wp_safe_redirect($url, 302);
        exit;
    }
}, 1);

/** Matomo-Custom-Dimension 1 setzen – VOR dem trackPageView des Tracking-Plugins
 *  (das läuft auf wp_head-Priorität 5; wir nutzen 4, damit unser _paq-Push zuerst
 *  in der Warteschlange steht). Auf der Variante-Seite immer "Shop-Variante". */
add_action('wp_head', function () {
    if (!m392_ab_enabled()) { return; }
    $variant = (function_exists('is_page') && is_page('shop-variante')) ? M392_AB_VARIANTE : m392_ab_variant();
    if (!$variant) { return; }
    echo "<script>window._paq=window._paq||[];_paq.push(['setCustomDimension',1,"
        . json_encode($variant, JSON_UNESCAPED_UNICODE) . "]);</script>\n";
}, 4);

/** Body-Klasse für die Variante-Seite (CSS-Hook). */
add_filter('body_class', function ($classes) {
    if (function_exists('is_page') && is_page('shop-variante')) { $classes[] = 'm392-variant-b'; }
    return $classes;
});

/** Promo-Banner oben auf der Variante-Seite. */
add_action('wp_body_open', function () {
    if (function_exists('is_page') && is_page('shop-variante')) {
        echo '<div class="m392-ab-banner">🎉 Sommer-Aktion in der neuen Shop-Variante – <strong>-10 % mit Code NATUR10</strong></div>';
    }
});

/** Deutlich andere Optik der Shop-Variante (Variante B): warmes Akzent-Farbschema,
 *  2-Spalten-Raster mit größeren Karten, auffällige „In den Warenkorb"-Buttons. */
add_action('wp_head', function () {
    if (!(function_exists('is_page') && is_page('shop-variante'))) { return; }
    ?>
<style id="m392-variant-b-css">
  .m392-ab-banner{background:#b5532f;color:#fff;text-align:center;padding:12px 16px;
    font-size:15px;letter-spacing:.2px;}
  .m392-variant-b .woocommerce ul.products{display:grid !important;
    grid-template-columns:repeat(2,1fr) !important;gap:28px;}
  .m392-variant-b .woocommerce ul.products li.product{width:auto !important;margin:0 !important;
    background:#fbf6f1;border:1px solid #ecd9cb;border-radius:14px;padding:18px;
    box-shadow:0 6px 20px rgba(120,60,30,.08);transition:transform .15s;}
  .m392-variant-b .woocommerce ul.products li.product:hover{transform:translateY(-4px);}
  .m392-variant-b .woocommerce ul.products li.product img{border-radius:10px;}
  .m392-variant-b .woocommerce ul.products li.product .price{color:#b5532f;font-weight:700;font-size:1.15em;}
  .m392-variant-b .woocommerce ul.products li.product .button,
  .m392-variant-b .woocommerce a.button.add_to_cart_button{
    background:#b5532f !important;color:#fff !important;border-radius:999px !important;
    padding:.7em 1.4em !important;font-weight:600 !important;border:0 !important;}
  .m392-variant-b .woocommerce a.button.add_to_cart_button:hover{background:#9a3f20 !important;}
  .m392-variant-b .entry-title,.m392-variant-b .page-title{color:#b5532f;}
</style>
    <?php
}, 6);
