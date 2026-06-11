<?php
/**
 * Plugin Name: M392 A/B-Test (Shop-Variante)
 * Description: A/B-Test der Shop-Seite für die Lehrumgebung Modul 392. Variante
 *  "Original" = /shop/, Variante "Shop-Variante" = /shop-variante/ (sichtbar anders).
 *  Die Variante wird URL-BASIERT in der Matomo-Custom-Dimension 1 ("AB-Variante")
 *  getrackt: /shop-variante/ = "Shop-Variante", alles andere = "Original".
 *
 *  WICHTIG: Es gibt KEIN Cookie-Bucketing und KEINEN Redirect mehr. Echte
 *  Besucher:innen sehen /shop/ ganz normal; /shop-variante/ ist eine eigenständig
 *  erreichbare Seite. Den A/B-Besuchs-Split (M392_AB_SPLIT_B) erzeugt das Traffic
 *  Lab synthetisch über die Tracking-API – dafür braucht der Shop selbst keine
 *  Umleitung. (Früher wurden Besucher:innen per Cookie zugewiesen und ggf. von
 *  /shop/ auf /shop-variante/ umgeleitet – das war im Unterricht irritierend.)
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

/** Altes Bucketing-Cookie aufräumen (stammt aus der früheren Redirect-Logik). */
add_action('init', function () {
    if (isset($_COOKIE[M392_AB_COOKIE]) && !headers_sent()) {
        setcookie(M392_AB_COOKIE, '', time() - 3600, (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/');
        unset($_COOKIE[M392_AB_COOKIE]);
    }
});

/** Matomo-Custom-Dimension 1 setzen – VOR dem trackPageView des Tracking-Plugins
 *  (das läuft auf wp_head-Priorität 5; wir nutzen 4, damit unser _paq-Push zuerst
 *  in der Warteschlange steht). URL-basiert: nur die Variante-Seite zählt als
 *  "Shop-Variante", alle übrigen Seiten als "Original". */
add_action('wp_head', function () {
    if (!m392_ab_enabled()) { return; }
    $variant = (function_exists('is_page') && is_page('shop-variante'))
        ? M392_AB_VARIANTE : M392_AB_ORIGINAL;
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

/** Promo-Banner oben auf der Variante-Seite (Shop-Farbwelt: Schwarz auf Weiß). */
add_action('wp_body_open', function () {
    if (function_exists('is_page') && is_page('shop-variante')) {
        echo '<div class="m392-ab-banner">Sommer-Aktion&nbsp;&nbsp;·&nbsp;&nbsp;<strong>−10&nbsp;% mit Code NATUR10</strong></div>';
    }
});

/** Filter-Sidebar (Kategorien, Preis, Bewertung, Angebote) – aus den echten
 *  Produktdaten gebaut, in der Designsprache der Shop-Filterleiste (Botiga:
 *  eckige Chips, Schwarz #212121, warme Haarlinien, Uppercase-Mikrolabels). */
function m392_sv_sidebar_html() {
    $catrows = '';
    foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]) as $c) {
        if (in_array($c->slug, ['uncategorized', 'uncategorised'], true)) { continue; }
        $catrows .= '<label class="m392-sv-check"><input type="checkbox" class="m392-sv-cat" value="'
            . esc_attr($c->slug) . '"><span class="m392-sv-box" aria-hidden="true"></span>'
            . esc_html($c->name)
            . '<span class="m392-sv-count">' . (int) $c->count . '</span></label>';
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
    <div class="m392-sv-group">
      <h3>Kategorie</h3>
      <?php echo $catrows; ?>
    </div>
    <div class="m392-sv-group">
      <h3>Preis</h3>
      <input type="range" id="m392-sv-pmax" min="<?php echo $min; ?>" max="<?php echo $max; ?>" value="<?php echo $max; ?>" step="1" aria-label="Maximaler Preis">
      <div class="m392-sv-pricelabel"><span><?php echo $min; ?> €</span><b>bis <span id="m392-sv-pval"><?php echo $max; ?></span> €</b></div>
    </div>
    <div class="m392-sv-group">
      <h3>Bewertung</h3>
      <div class="m392-sv-chips" id="m392-sv-rating">
        <button type="button" class="m392-sv-chip is-active" data-r="0" aria-pressed="true">Alle</button>
        <button type="button" class="m392-sv-chip" data-r="4" aria-pressed="false">&#9733;&nbsp;4+</button>
        <button type="button" class="m392-sv-chip" data-r="4.5" aria-pressed="false">&#9733;&nbsp;4,5+</button>
      </div>
    </div>
    <div class="m392-sv-group m392-sv-group--last">
      <h3>Angebote</h3>
      <button type="button" id="m392-sv-sale" class="m392-sv-chip m392-sv-chip--toggle" aria-pressed="false">
        <span class="m392-sv-dot" aria-hidden="true"></span> Nur Angebote
      </button>
    </div>
    <?php
    return ob_get_clean();
}

/** Seiteninhalt der Variante in ein 2-Spalten-Layout packen: Filter-Sidebar
 *  links, rechts Ergebnis-Kopf (Produktzähler + Zurücksetzen) und Produktraster. */
add_filter('the_content', function ($content) {
    if (is_admin() || !(function_exists('is_page') && is_page('shop-variante'))) { return $content; }
    $meta = '<div class="m392-sv-meta">'
        . '<span class="m392-sv-metainfo"><span id="m392-sv-num" class="m392-sv-num"></span>'
        . '<span id="m392-sv-empty" class="m392-sv-empty" hidden>&middot; keine Produkte für diese Auswahl</span></span>'
        . '<button type="button" id="m392-sv-reset" class="m392-sv-reset" hidden>Zurücksetzen</button></div>';
    return '<div class="m392-sv-layout"><aside class="m392-sv-filters" aria-label="Produktfilter">'
        . m392_sv_sidebar_html() . '</aside><div class="m392-sv-main">' . $meta . $content . '</div></div>';
}, 20);

/** Optik der Shop-Variante (Variante B): gleiche Farbwelt wie der Shop (Botiga:
 *  #212121, warme Haarlinien, eckig) – die Variante unterscheidet sich durch
 *  LAYOUT (Filter-Sidebar links, 2-Spalten-Produktraster, Aktionsband). */
add_action('wp_head', function () {
    if (!(function_exists('is_page') && is_page('shop-variante'))) { return; }
    ?>
<style id="m392-variant-b-css">
  .m392-variant-b{--sv-fg:#202833; --sv-active:#212121; --sv-mut:#9a988f; --sv-line:#e4e1da; --sv-border:#d7d3cb;}
  .m392-ab-banner{background:#212121;color:#fff;text-align:center;padding:11px 16px;font-size:13.5px;letter-spacing:.05em;}
  .m392-ab-banner strong{font-weight:700;}

  /* 2-Spalten-Layout: Filter links (sticky), Produkte rechts */
  .m392-sv-layout{display:flex;gap:48px;align-items:flex-start;}
  .m392-sv-filters{flex:0 0 240px;position:sticky;top:32px;background:#fff;
    border:1px solid var(--sv-line);border-radius:0;padding:24px 22px;}
  .m392-sv-main{flex:1;min-width:0;}

  .m392-sv-group{margin:0 0 22px;padding:0 0 20px;border-bottom:1px solid var(--sv-line);}
  .m392-sv-group--last{margin-bottom:0;padding-bottom:0;border-bottom:0;}
  .m392-sv-group h3{margin:0 0 14px;font-size:11px;font-weight:700;letter-spacing:.14em;
    text-transform:uppercase;color:var(--sv-mut);}

  /* Kategorien: eckige Checkboxen, Schwarz aktiv, Zähler rechtsbündig */
  .m392-sv-check{display:flex;align-items:center;gap:10px;margin:10px 0;font-size:14px;
    color:var(--sv-fg);cursor:pointer;user-select:none;}
  .m392-sv-check input{position:absolute;opacity:0;width:0;height:0;}
  .m392-sv-box{width:16px;height:16px;flex:none;border:1px solid var(--sv-border);background:#fff;
    display:grid;place-items:center;transition:border-color .15s, background .15s;}
  .m392-sv-check:hover .m392-sv-box{border-color:var(--sv-fg);}
  .m392-sv-check input:checked + .m392-sv-box{background:var(--sv-active);border-color:var(--sv-active);}
  .m392-sv-check input:checked + .m392-sv-box::after{content:'';width:9px;height:5px;margin-top:-2px;
    border-left:2px solid #fff;border-bottom:2px solid #fff;transform:rotate(-45deg);}
  .m392-sv-check input:focus-visible + .m392-sv-box{outline:2px solid var(--sv-fg);outline-offset:2px;}
  .m392-sv-count{margin-left:auto;color:var(--sv-mut);font-size:12px;font-variant-numeric:tabular-nums;}

  /* Preis: schwarzer Slider + Bereichsangabe */
  .m392-sv-filters input[type=range]{width:100%;accent-color:var(--sv-active);margin:2px 0 8px;}
  .m392-sv-pricelabel{display:flex;align-items:baseline;justify-content:space-between;
    font-size:13px;color:var(--sv-mut);}
  .m392-sv-pricelabel b{color:var(--sv-fg);font-weight:600;}

  /* Chips: exakt die Sprache der Shop-Filterleiste (eckig, Schwarz aktiv) */
  .m392-sv-chips{display:flex;gap:8px;flex-wrap:wrap;}
  .m392-sv-chip{appearance:none;cursor:pointer;font:inherit;font-size:13.5px;line-height:1;
    color:var(--sv-fg);background:#fff;border:1px solid var(--sv-border);padding:9px 13px;border-radius:0;
    white-space:nowrap;transition:border-color .15s, background .15s, color .15s;}
  .m392-sv-chip:hover{border-color:var(--sv-fg);}
  .m392-sv-chip.is-active{background:var(--sv-active);border-color:var(--sv-active);color:#fff;}
  .m392-sv-chip--toggle{display:inline-flex;align-items:center;gap:9px;}
  .m392-sv-dot{width:7px;height:7px;border-radius:50%;background:#cfcabf;transition:background .15s;}
  .m392-sv-chip--toggle.is-active .m392-sv-dot{background:#fff;}

  /* Ergebnis-Kopf über dem Raster: Zähler links, Zurücksetzen rechts (wie /shop) */
  .m392-sv-meta{display:flex;align-items:center;justify-content:space-between;gap:16px;
    margin:0 0 26px;padding:0 0 16px;border-bottom:1px solid var(--sv-line);
    font-size:14px;color:var(--sv-mut);}
  .m392-sv-num{font-weight:600;color:var(--sv-fg);letter-spacing:.02em;}
  .m392-sv-empty{color:#a23b3b;margin-left:6px;}
  .m392-sv-reset{appearance:none;cursor:pointer;background:none;border:none;font:inherit;font-size:11px;
    font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--sv-mut);padding:4px 0;}
  .m392-sv-reset:hover{color:var(--sv-fg);}

  /* Produktraster: 2 großzügige Spalten, ansonsten native Botiga-Optik */
  .m392-variant-b .woocommerce ul.products{display:grid !important;
    grid-template-columns:repeat(2,1fr) !important;gap:44px 36px;margin:0 !important;}
  .m392-variant-b .woocommerce ul.products li.product{width:auto !important;margin:0 !important;}

  @media(max-width:900px){
    .m392-sv-layout{flex-direction:column;gap:28px;}
    .m392-sv-filters{position:static;flex-basis:auto;width:100%;}
    .m392-variant-b .woocommerce ul.products{gap:28px 20px;}
  }
</style>
    <?php
}, 6);

/** Client-seitige Filterlogik der Sidebar (liest die echten Produktdaten der
 *  Karten); aktualisiert Produktzähler und blendet „Zurücksetzen" bedarfsweise ein. */
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
      sale=document.getElementById('m392-sv-sale'),empty=document.getElementById('m392-sv-empty'),
      num=document.getElementById('m392-sv-num'),reset=document.getElementById('m392-sv-reset'),
      ratingChips=[].slice.call(document.querySelectorAll('#m392-sv-rating .m392-sv-chip'));
  var rMin=0;

  function selCats(){return [].slice.call(document.querySelectorAll('.m392-sv-cat:checked')).map(function(c){return c.value;});}
  function saleOn(){return sale&&sale.classList.contains('is-active');}

  function apply(){
    var sc=selCats(),pm=pmax?parseFloat(pmax.value):Infinity,so=saleOn(),vis=0;
    if(pval&&pmax)pval.textContent=pmax.value;
    items.forEach(function(li){var ok=true;
      if(sc.length){var lc=cats(li); ok=sc.some(function(c){return lc.indexOf(c)>=0;});}
      if(ok&&price(li)>pm)ok=false;
      if(ok&&rMin>0&&rating(li)<rMin)ok=false;
      if(ok&&so&&!li.querySelector('.onsale'))ok=false;
      li.style.display=ok?'':'none'; if(ok)vis++;});
    if(num)num.textContent=vis+(vis===1?' Produkt':' Produkte');
    if(empty)empty.hidden=vis!==0;
    var active=sc.length>0||(pmax&&parseFloat(pmax.value)<parseFloat(pmax.max))||rMin>0||so;
    if(reset)reset.hidden=!active;
  }

  ratingChips.forEach(function(b){b.addEventListener('click',function(){
    rMin=parseFloat(b.getAttribute('data-r'))||0;
    ratingChips.forEach(function(x){var on=x===b;x.classList.toggle('is-active',on);
      x.setAttribute('aria-pressed',on?'true':'false');});
    apply();});});
  if(sale)sale.addEventListener('click',function(){
    sale.classList.toggle('is-active');
    sale.setAttribute('aria-pressed',saleOn()?'true':'false'); apply();});
  root.addEventListener('change',apply); root.addEventListener('input',apply);
  if(reset)reset.addEventListener('click',function(){
    document.querySelectorAll('.m392-sv-cat').forEach(function(c){c.checked=false;});
    if(pmax)pmax.value=pmax.max;
    rMin=0; ratingChips.forEach(function(x,i){x.classList.toggle('is-active',i===0);
      x.setAttribute('aria-pressed',i===0?'true':'false');});
    if(sale){sale.classList.remove('is-active');sale.setAttribute('aria-pressed','false');}
    apply();});
  apply();
})();
</script>
    <?php
});
