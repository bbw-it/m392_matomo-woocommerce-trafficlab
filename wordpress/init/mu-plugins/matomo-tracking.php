<?php
/**
 * Plugin Name: M392 Matomo Tracking
 * Description: Fügt den Matomo-JS-Tracking-Code in <head> ein (Lehrumgebung Modul 392).
 */
if (!defined('ABSPATH')) { exit; }

add_action('wp_head', function () {
    // Vom Browser aus erreichbare Matomo-URL (Host-Port). Bei Bedarf anpassen.
    $matomo_url = 'http://localhost:8091/';
    $site_id    = 1;
    ?>
<!-- Matomo (M392 Lehrumgebung) -->
<script>
  var _paq = window._paq = window._paq || [];
  _paq.push(['enableLinkTracking']);
  (function() {
    var u="<?php echo esc_js($matomo_url); ?>";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '<?php echo (int) $site_id; ?>']);
<?php
    // --- On-Site-Suche / Produkt-/Kategorie-Ansichten / Warenkorb -----------
    // Entscheidet, ob diese Seite eine Suchergebnisseite ist. Auf Suchseiten
    // wird statt trackPageView ein trackSiteSearch ausgeloest (Matomo zaehlt
    // die Suche als Aktion). Alle anderen Seiten ergaenzen ggf. E-Commerce-
    // Ansichten/Warenkorb und behalten ihren normalen trackPageView.
    $is_search_page = false;

    if (function_exists('is_search') && is_search()) {
        $is_search_page = true;
        $keyword = function_exists('get_search_query') ? get_search_query() : '';
        global $wp_query;
        $count = isset($wp_query->found_posts) ? (int) $wp_query->found_posts : 0;
        ?>
    _paq.push(['trackSiteSearch', <?php echo json_encode((string) $keyword, JSON_UNESCAPED_UNICODE); ?>, false, <?php echo (int) $count; ?>]);
<?php
    } elseif (function_exists('is_product') && is_product()) {
        $product = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;
        if ($product) {
            $sku = $product->get_sku();
            if (!$sku) { $sku = 'wc_' . $product->get_id(); }
            $name  = $product->get_name();
            $terms = get_the_terms($product->get_id(), 'product_cat');
            $cat   = (!is_wp_error($terms) && $terms) ? $terms[0]->name : '';
            $price = (float) $product->get_price();
            ?>
    _paq.push(['setEcommerceView', <?php echo json_encode((string) $sku, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode((string) $name, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode((string) $cat, JSON_UNESCAPED_UNICODE); ?>, <?php echo floatval($price); ?>]);
<?php
        }
    } elseif (function_exists('is_product_category') && (is_product_category() || is_product_tag())) {
        $term = get_queried_object();
        $cat  = isset($term->name) ? $term->name : '';
        ?>
    _paq.push(['setEcommerceView', false, false, <?php echo json_encode((string) $cat, JSON_UNESCAPED_UNICODE); ?>]);
<?php
    }

    // Warenkorb-Update unabhaengig (kein elseif): nicht-leerer Warenkorb.
    if (function_exists('is_cart') && is_cart() && function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            $p = $cart_item['data'];
            if (!$p) { continue; }
            $sku = $p->get_sku();
            if (!$sku) { $sku = 'wc_' . $p->get_id(); }
            $name  = $p->get_name();
            $terms = get_the_terms($p->get_id(), 'product_cat');
            $cat   = (!is_wp_error($terms) && $terms) ? $terms[0]->name : '';
            $qty   = (int) $cart_item['quantity'];
            ?>
    _paq.push(['addEcommerceItem', <?php echo json_encode((string) $sku, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode((string) $name, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode((string) $cat, JSON_UNESCAPED_UNICODE); ?>, <?php echo floatval((float) $p->get_price()); ?>, <?php echo (int) $qty; ?>]);
<?php
        }
        ?>
    _paq.push(['trackEcommerceCartUpdate', <?php echo floatval((float) WC()->cart->get_total('edit')); ?>]);
<?php
    }

    // E-Commerce-Bestellung auf der Danke-/Bestellbestätigungsseite tracken,
    // damit echte Browser-Käufe in Matomo als Conversions erscheinen.
    // Hinweis: Erneutes Tracken beim Neuladen der Seite ist unkritisch, da Matomo
    // E-Commerce-Bestellungen anhand der Bestell-ID dedupliziert.
    if (function_exists('is_order_received_page') && is_order_received_page()) {
        global $wp;
        $order_id = absint($wp->query_vars['order-received'] ?? 0);
        $order    = $order_id ? wc_get_order($order_id) : false;
        if ($order) {
            foreach ($order->get_items() as $item) {
                $product  = $item->get_product();
                $sku      = $product ? $product->get_sku() : '';
                $name     = $item->get_name();
                $category = '';
                if ($product) {
                    $terms = get_the_terms($product->get_id(), 'product_cat');
                    if ($terms && !is_wp_error($terms)) {
                        $first    = reset($terms);
                        $category = $first->name;
                    }
                }
                $qty        = (int) $item->get_quantity();
                $unit_price = $qty > 0 ? ((float) $item->get_total() + (float) $item->get_total_tax()) / $qty : 0.0;
                ?>
    _paq.push(['addEcommerceItem', <?php echo json_encode((string) $sku, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode((string) $name, JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode((string) $category, JSON_UNESCAPED_UNICODE); ?>, <?php echo floatval($unit_price); ?>, <?php echo (int) $qty; ?>]);
<?php
            }
            ?>
    _paq.push(['trackEcommerceOrder', <?php echo json_encode((string) $order->get_order_number(), JSON_UNESCAPED_UNICODE); ?>, <?php echo floatval($order->get_total()); ?>, <?php echo floatval($order->get_subtotal()); ?>, <?php echo floatval($order->get_total_tax()); ?>, <?php echo floatval($order->get_shipping_total()); ?>, false]);
<?php
        }
    }

    // Auf Suchseiten KEIN trackPageView (trackSiteSearch ersetzt die Aktion).
    if (!$is_search_page) {
        ?>
    _paq.push(['trackPageView']);
<?php
    }
?>
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End Matomo -->
    <?php
}, 5);
