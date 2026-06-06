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

/** Für User heißt die Variante-Seite „Shop" (intern bleibt sie „Shop-Variante",
 *  damit sie im Backend unterscheidbar ist). */
add_filter('the_title', function ($title) {
    if (!is_admin() && $title === 'Shop-Variante'
        && function_exists('is_page') && is_page('shop-variante')) {
        return 'Shop';
    }
    return $title;
});
add_filter('pre_get_document_title', function ($t) {
    if (function_exists('is_page') && is_page('shop-variante')) {
        return 'Shop – ' . get_bloginfo('name');
    }
    return $t;
});

/** Promo-Banner oben auf der Variante-Seite. */
add_action('wp_body_open', function () {
    if (function_exists('is_page') && is_page('shop-variante')) {
        echo '<div class="m392-ab-banner">🎉 Sommer-Aktion – <strong>-10 % mit Code NATUR10</strong></div>';
    }
});

/** Filter-Sidebar (Kategorien, Preis, Bewertung, Angebote) – aus den echten
 *  Produktdaten gebaut. */
function m392_sv_sidebar_html() {
    $catrows = '';
    foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]) as $c) {
        if (in_array($c->slug, ['uncategorized', 'uncategorised'], true)) { continue; }
        $catrows .= '<label class="m392-sv-check"><input type="checkbox" class="m392-sv-cat" value="'
            . esc_attr($c->slug) . '"> ' . esc_html($c->name)
            . ' <span class="m392-sv-count">(' . (int) $c->count . ')</span></label>';
    }
    $min = 0; $max = 100;
    if (function_exists('wc_get_products')) {
        $prices = [];
        foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $p) {
            $pr = (float) $p->get_price(); if ($pr > 0) { $prices[] = $pr; }
        }
        if ($prices) { $min = (int) floor(min($prices)); $max = (int) ceil(max($prices)); }
    }
    ob_start(); ?>
    <h2 class="m392-sv-title">Filter</h2>
    <div class="m392-sv-group"><h3>Kategorien</h3><?php echo $catrows; ?></div>
    <div class="m392-sv-group"><h3>Preis</h3>
      <input type="range" id="m392-sv-pmax" min="<?php echo $min; ?>" max="<?php echo $max; ?>" value="<?php echo $max; ?>" step="1">
      <div class="m392-sv-pricelabel">bis <span id="m392-sv-pval"><?php echo $max; ?></span> €</div>
    </div>
    <div class="m392-sv-group"><h3>Bewertung</h3>
      <select id="m392-sv-rating">
        <option value="0">alle Bewertungen</option>
        <option value="3">ab 3 ★</option>
        <option value="4">ab 4 ★</option>
        <option value="4.5">ab 4,5 ★</option>
      </select>
    </div>
    <div class="m392-sv-group"><label class="m392-sv-check"><input type="checkbox" id="m392-sv-sale"> nur Angebote</label></div>
    <button type="button" id="m392-sv-reset" class="m392-sv-reset">Zurücksetzen</button>
    <div class="m392-sv-empty" id="m392-sv-empty" hidden>Keine Produkte für diese Auswahl.</div>
    <?php
    return ob_get_clean();
}

/** Seiteninhalt der Variante in ein 2-Spalten-Layout mit Filter-Sidebar packen. */
add_filter('the_content', function ($content) {
    if (is_admin() || !(function_exists('is_page') && is_page('shop-variante'))) { return $content; }
    return '<div class="m392-sv-layout"><aside class="m392-sv-filters">'
        . m392_sv_sidebar_html() . '</aside><div class="m392-sv-main">' . $content . '</div></div>';
}, 20);

/** Optik der Shop-Variante (Variante B): warmes Akzent-Farbschema, größere Karten,
 *  auffällige Buttons – PLUS moderne Filter-Sidebar. */
add_action('wp_head', function () {
    if (!(function_exists('is_page') && is_page('shop-variante'))) { return; }
    ?>
<style id="m392-variant-b-css">
  .m392-ab-banner{background:#b5532f;color:#fff;text-align:center;padding:12px 16px;font-size:15px;letter-spacing:.2px;}
  .m392-variant-b .entry-title,.m392-variant-b .page-title{color:#b5532f;}
  /* 2-Spalten-Layout: Filter links, Produkte rechts */
  .m392-sv-layout{display:flex;gap:30px;align-items:flex-start;}
  .m392-sv-filters{flex:0 0 250px;position:sticky;top:24px;background:#fff;border:1px solid #ecd9cb;
    border-radius:16px;padding:22px;box-shadow:0 8px 24px rgba(120,60,30,.07);}
  .m392-sv-title{margin:0 0 16px;font-size:18px;color:#9a3f20;}
  .m392-sv-main{flex:1;min-width:0;}
  .m392-sv-group{margin:0 0 18px;padding:0 0 14px;border-bottom:1px solid #f0e6dd;}
  .m392-sv-group h3{margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.6px;color:#9a3f20;}
  .m392-sv-check{display:flex;align-items:center;gap:8px;margin:7px 0;font-size:14px;cursor:pointer;}
  .m392-sv-count{color:#b39b8c;font-size:12px;}
  .m392-sv-filters input[type=range]{width:100%;accent-color:#b5532f;}
  .m392-sv-pricelabel{font-size:13px;color:#7a5c4c;margin-top:6px;}
  .m392-sv-filters select{width:100%;padding:8px;border:1px solid #e0cdbf;border-radius:8px;background:#fff;}
  .m392-sv-reset{background:#f3e7df;color:#9a3f20;border:0;border-radius:999px;padding:.6em 1.1em;font-weight:600;cursor:pointer;width:100%;}
  .m392-sv-reset:hover{background:#ecd9cb;}
  .m392-sv-empty{margin-top:14px;color:#c0392b;font-size:14px;}
  /* Produktkarten der Variante */
  .m392-variant-b .woocommerce ul.products{display:grid !important;grid-template-columns:repeat(2,1fr) !important;gap:26px;margin:0 !important;}
  .m392-variant-b .woocommerce ul.products li.product{width:auto !important;margin:0 !important;background:#fbf6f1;
    border:1px solid #ecd9cb;border-radius:14px;padding:18px;box-shadow:0 6px 20px rgba(120,60,30,.08);transition:transform .15s;}
  .m392-variant-b .woocommerce ul.products li.product:hover{transform:translateY(-4px);}
  .m392-variant-b .woocommerce ul.products li.product img{border-radius:10px;}
  .m392-variant-b .woocommerce ul.products li.product .price{color:#b5532f;font-weight:700;font-size:1.15em;}
  .m392-variant-b .woocommerce ul.products li.product .button,
  .m392-variant-b .woocommerce a.button.add_to_cart_button{background:#b5532f !important;color:#fff !important;
    border-radius:999px !important;padding:.7em 1.4em !important;font-weight:600 !important;border:0 !important;}
  .m392-variant-b .woocommerce a.button.add_to_cart_button:hover{background:#9a3f20 !important;}
  @media(max-width:782px){.m392-sv-layout{flex-direction:column;}.m392-sv-filters{position:static;flex-basis:auto;width:100%;}
    .m392-variant-b .woocommerce ul.products{grid-template-columns:repeat(2,1fr) !important;}}
</style>
    <?php
}, 6);

/** Client-seitige Filterlogik der Sidebar (liest die echten Produktdaten der Karten). */
add_action('wp_footer', function () {
    if (!(function_exists('is_page') && is_page('shop-variante'))) { return; }
    ?>
<script>
(function(){
  var root=document.querySelector('.m392-sv-layout'); if(!root) return;
  var items=[].slice.call(root.querySelectorAll('.m392-sv-main li.product'));
  function price(li){var a=li.querySelectorAll('.price .amount'); var t=a.length?a[a.length-1]:li.querySelector('.price');
    if(!t)return 0; var m=(t.textContent||'').replace(/[^0-9.,]/g,'').replace(/\./g,'').replace(',','.'); return parseFloat(m)||0;}
  function rating(li){var s=li.querySelector('.star-rating'); if(!s)return 0;
    var l=(s.getAttribute('aria-label')||s.title||'').replace(',','.').match(/([0-9]+(\.[0-9]+)?)/); return l?parseFloat(l[1]):0;}
  function cats(li){return (li.className.match(/product_cat-[\w-]+/g)||[]).map(function(c){return c.replace('product_cat-','');});}
  var pmax=document.getElementById('m392-sv-pmax'),pval=document.getElementById('m392-sv-pval'),
      rsel=document.getElementById('m392-sv-rating'),sale=document.getElementById('m392-sv-sale'),
      empty=document.getElementById('m392-sv-empty');
  function selCats(){return [].slice.call(document.querySelectorAll('.m392-sv-cat:checked')).map(function(c){return c.value;});}
  function apply(){
    var sc=selCats(),pm=pmax?parseFloat(pmax.value):Infinity,rm=rsel?parseFloat(rsel.value):0,so=sale&&sale.checked,vis=0;
    if(pval&&pmax)pval.textContent=pmax.value;
    items.forEach(function(li){var ok=true;
      if(sc.length){var lc=cats(li); ok=sc.some(function(c){return lc.indexOf(c)>=0;});}
      if(ok&&price(li)>pm)ok=false;
      if(ok&&rm>0&&rating(li)<rm)ok=false;
      if(ok&&so&&!li.querySelector('.onsale'))ok=false;
      li.style.display=ok?'':'none'; if(ok)vis++;});
    if(empty)empty.hidden=vis>0;
  }
  root.addEventListener('change',apply); root.addEventListener('input',apply);
  var r=document.getElementById('m392-sv-reset');
  if(r)r.addEventListener('click',function(){document.querySelectorAll('.m392-sv-cat').forEach(function(c){c.checked=false;});
    if(pmax)pmax.value=pmax.max; if(rsel)rsel.value='0'; if(sale)sale.checked=false; apply();});
})();
</script>
    <?php
});
