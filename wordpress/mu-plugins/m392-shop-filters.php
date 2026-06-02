<?php
/**
 * Plugin Name: M392 Shop-Filter & Sortierung
 * Description: Moderne, sofort wirkende Produktfilter (Preis, Bewertung, Angebote)
 *              und Sortierung auf den Shop-/Kategorieseiten (Lehrumgebung Modul 392).
 *
 * Ansatz: Die echten Produktdaten (Preis, Sale, Bewertung, Verkäufe, Datum) werden
 * serverseitig als JSON bereitgestellt und je Produkt über die `post-<ID>`-CSS-Klasse
 * zugeordnet. Gefiltert/sortiert wird clientseitig ohne Neuladen – schnell und robust
 * gegenüber Theme-Markup (kein Parsen von Preis-/Sternchen-HTML).
 */
if (!defined('ABSPATH')) { exit; }

/** Nur auf Shop- und Produkt-Taxonomie-Archiven aktiv werden. */
function m392_is_shop_archive() {
    return function_exists('is_shop') &&
        (is_shop() || (function_exists('is_product_taxonomy') && is_product_taxonomy()));
}

/** Produktdaten (id => Kennzahlen) für die clientseitige Filterung/Sortierung. */
function m392_collect_product_data() {
    $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
    $data = [];
    foreach ($products as $p) {
        $data[$p->get_id()] = [
            'price'   => (float) $p->get_price(),
            'sale'    => (bool) $p->is_on_sale(),
            'rating'  => (float) $p->get_average_rating(),
            'reviews' => (int) $p->get_review_count(),
            'sales'   => (int) $p->get_total_sales(),
            'date'    => $p->get_date_created() ? $p->get_date_created()->getTimestamp() : 0,
            'name'    => $p->get_name(),
        ];
    }
    return $data;
}

/** Toolbar-HTML oberhalb des Produktrasters ausgeben. */
add_action('woocommerce_before_shop_loop', function () {
    if (!m392_is_shop_archive()) { return; }
    ?>
    <div id="m392-filters" class="m392-filters" role="region" aria-label="Produktfilter und Sortierung">
      <div class="m392-filters__row">
        <div class="m392-filters__group" data-filter="price">
          <span class="m392-filters__label">Preis</span>
          <div class="m392-seg">
            <button type="button" class="m392-chip is-active" data-price="all" aria-pressed="true">Alle</button>
            <button type="button" class="m392-chip" data-price="lo"  aria-pressed="false">&le;&nbsp;14&nbsp;&euro;</button>
            <button type="button" class="m392-chip" data-price="mid" aria-pressed="false">15&ndash;18&nbsp;&euro;</button>
            <button type="button" class="m392-chip" data-price="hi"  aria-pressed="false">&ge;&nbsp;19&nbsp;&euro;</button>
          </div>
        </div>

        <div class="m392-filters__group" data-filter="rating">
          <span class="m392-filters__label">Bewertung</span>
          <div class="m392-seg">
            <button type="button" class="m392-chip is-active" data-rating="0"   aria-pressed="true">Alle</button>
            <button type="button" class="m392-chip" data-rating="4"   aria-pressed="false">&#9733;&nbsp;4+</button>
            <button type="button" class="m392-chip" data-rating="4.5" aria-pressed="false">&#9733;&nbsp;4,5+</button>
          </div>
        </div>

        <div class="m392-filters__group" data-filter="sale">
          <button type="button" id="m392-sale" class="m392-chip m392-chip--toggle" data-on="0" aria-pressed="false">
            <span class="m392-dot"></span> Nur Angebote
          </button>
        </div>

        <button type="button" id="m392-reset" class="m392-reset" hidden>Zurücksetzen</button>

        <div class="m392-filters__sort">
          <label for="m392-sort">Sortieren</label>
          <select id="m392-sort">
            <option value="default">Empfohlen</option>
            <option value="popularity">Beliebtheit</option>
            <option value="rating">Beste Bewertung</option>
            <option value="price-asc">Preis: aufsteigend</option>
            <option value="price-desc">Preis: absteigend</option>
            <option value="date">Neuheiten</option>
            <option value="name">Name A&ndash;Z</option>
          </select>
        </div>
      </div>

      <div class="m392-filters__meta">
        <span id="m392-count" class="m392-count"></span>
        <span id="m392-empty" class="m392-empty" hidden>Keine Produkte für diese Auswahl &ndash; Filter zurücksetzen.</span>
      </div>
    </div>
    <?php
}, 25);

/** CSS + JS (inkl. Produktdaten) auf den Archivseiten einbinden. */
add_action('wp_enqueue_scripts', function () {
    if (!m392_is_shop_archive()) { return; }

    // Dummy-Handles, an die Inline-CSS/JS gehängt wird (kein extra Asset-File nötig).
    wp_register_style('m392-shop-filters', false);
    wp_enqueue_style('m392-shop-filters');
    wp_add_inline_style('m392-shop-filters', m392_shop_filters_css());

    wp_register_script('m392-shop-filters', '', [], null, true);
    wp_enqueue_script('m392-shop-filters');
    $json = wp_json_encode(m392_collect_product_data());
    wp_add_inline_script('m392-shop-filters', 'window.M392_PRODUCTS = ' . $json . ';', 'before');
    wp_add_inline_script('m392-shop-filters', m392_shop_filters_js());
}, 20);

function m392_shop_filters_css() {
    return <<<CSS
/* Standard-WooCommerce-Sortierung/Zählung ausblenden – wir liefern eigene. */
.woocommerce-ordering, .woocommerce-result-count { display: none !important; }

.m392-filters{
  --m392-fg:#1a1a1a; --m392-mut:#6b6b6b; --m392-line:#e5e3df; --m392-bg:#faf9f7;
  margin:0 0 28px; padding:18px 20px; border:1px solid var(--m392-line);
  border-radius:16px; background:var(--m392-bg);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
}
.m392-filters__row{ display:flex; flex-wrap:wrap; align-items:center; gap:18px 24px; }
.m392-filters__group{ display:flex; align-items:center; gap:10px; }
.m392-filters__label{ font-size:12px; font-weight:600; letter-spacing:.06em;
  text-transform:uppercase; color:var(--m392-mut); }
.m392-seg{ display:inline-flex; gap:6px; }
.m392-chip{
  appearance:none; cursor:pointer; border:1px solid var(--m392-line);
  background:#fff; color:var(--m392-fg); font-size:14px; line-height:1;
  padding:9px 14px; border-radius:999px; transition:all .15s ease; white-space:nowrap;
}
.m392-chip:hover{ border-color:#bdbab4; }
.m392-chip.is-active{ background:var(--m392-fg); border-color:var(--m392-fg); color:#fff; }
.m392-chip--toggle{ display:inline-flex; align-items:center; gap:8px; }
.m392-dot{ width:8px; height:8px; border-radius:50%; background:#d8d5cf; transition:background .15s ease; }
.m392-chip--toggle.is-active .m392-dot{ background:#ff5c61; }
.m392-reset{
  appearance:none; cursor:pointer; background:none; border:none; padding:6px 4px;
  color:var(--m392-mut); font-size:13px; text-decoration:underline; text-underline-offset:3px;
}
.m392-reset:hover{ color:var(--m392-fg); }
.m392-filters__sort{ margin-left:auto; display:flex; align-items:center; gap:10px; }
.m392-filters__sort label{ font-size:12px; font-weight:600; letter-spacing:.06em;
  text-transform:uppercase; color:var(--m392-mut); }
.m392-filters__sort select{
  appearance:none; cursor:pointer; border:1px solid var(--m392-line); background:#fff;
  color:var(--m392-fg); font-size:14px; padding:10px 38px 10px 14px; border-radius:999px;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%231a1a1a' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 14px center;
}
.m392-filters__meta{ margin-top:14px; font-size:14px; color:var(--m392-mut); }
.m392-count{ font-weight:600; color:var(--m392-fg); }
.m392-empty{ color:#b23b3b; }
@media (max-width:600px){
  .m392-filters__sort{ margin-left:0; width:100%; }
  .m392-filters__sort select{ flex:1; }
}
CSS;
}

function m392_shop_filters_js() {
    return <<<'JS'
(function(){
  function init(){
    var DATA = window.M392_PRODUCTS || {};
    var bar  = document.getElementById('m392-filters');
    var grid = document.querySelector('ul.products') || document.querySelector('.products');
    if (!bar || !grid) { return; }

    var items = [].slice.call(grid.querySelectorAll('li.product'));
    if (!items.length) { return; }

    items.forEach(function(li, i){
      var m = li.className.match(/post-(\d+)/);
      li.__id  = m ? m[1] : null;
      li.__d   = (m && DATA[m[1]]) ? DATA[m[1]] : {price:0,sale:false,rating:0,sales:0,date:0,name:''};
      li.__idx = i;
    });

    var state = { sort:'default', price:'all', rating:0, sale:false };
    var countEl = document.getElementById('m392-count');
    var emptyEl = document.getElementById('m392-empty');
    var resetEl = document.getElementById('m392-reset');

    function priceOk(p){
      if (state.price === 'all') return true;
      if (state.price === 'lo')  return p <= 14;
      if (state.price === 'mid') return p > 14 && p <= 18;
      if (state.price === 'hi')  return p >= 19;
      return true;
    }

    function comparator(a, b){
      var x = a.__d, y = b.__d;
      switch (state.sort) {
        case 'popularity': return y.sales - x.sales;
        case 'rating':     return y.rating - x.rating;
        case 'price-asc':  return x.price - y.price;
        case 'price-desc': return y.price - x.price;
        case 'date':       return y.date - x.date;
        case 'name':       return String(x.name).localeCompare(String(y.name), 'de');
        default:           return a.__idx - b.__idx;
      }
    }

    function apply(){
      var visible = 0;
      items.forEach(function(li){
        var d = li.__d;
        var ok = priceOk(d.price)
          && (state.rating ? d.rating >= state.rating : true)
          && (state.sale ? d.sale : true);
        li.style.display = ok ? '' : 'none';
        if (ok) visible++;
      });

      items.slice().sort(comparator).forEach(function(li){ grid.appendChild(li); });

      countEl.textContent = visible + (visible === 1 ? ' Produkt' : ' Produkte');
      emptyEl.hidden = visible !== 0;
      var active = state.price !== 'all' || state.rating !== 0 || state.sale || state.sort !== 'default';
      resetEl.hidden = !active;
    }

    function setActive(group, btn){
      group.querySelectorAll('.m392-chip').forEach(function(b){
        b.classList.remove('is-active'); b.setAttribute('aria-pressed','false');
      });
      btn.classList.add('is-active'); btn.setAttribute('aria-pressed','true');
    }

    bar.querySelectorAll('[data-filter="price"] .m392-chip').forEach(function(btn){
      btn.addEventListener('click', function(){
        state.price = btn.getAttribute('data-price');
        setActive(btn.closest('.m392-seg'), btn); apply();
      });
    });
    bar.querySelectorAll('[data-filter="rating"] .m392-chip').forEach(function(btn){
      btn.addEventListener('click', function(){
        state.rating = parseFloat(btn.getAttribute('data-rating')) || 0;
        setActive(btn.closest('.m392-seg'), btn); apply();
      });
    });

    var saleBtn = document.getElementById('m392-sale');
    saleBtn.addEventListener('click', function(){
      state.sale = !state.sale;
      saleBtn.classList.toggle('is-active', state.sale);
      saleBtn.setAttribute('aria-pressed', state.sale ? 'true' : 'false');
      apply();
    });

    document.getElementById('m392-sort').addEventListener('change', function(){
      state.sort = this.value; apply();
    });

    resetEl.addEventListener('click', function(){
      state = { sort:'default', price:'all', rating:0, sale:false };
      bar.querySelectorAll('.m392-seg').forEach(function(seg){
        var def = seg.querySelector('.m392-chip'); // erste Option = "Alle"
        seg.querySelectorAll('.m392-chip').forEach(function(b){
          b.classList.remove('is-active'); b.setAttribute('aria-pressed','false');
        });
        def.classList.add('is-active'); def.setAttribute('aria-pressed','true');
      });
      saleBtn.classList.remove('is-active'); saleBtn.setAttribute('aria-pressed','false');
      document.getElementById('m392-sort').value = 'default';
      apply();
    });

    apply();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
JS;
}
