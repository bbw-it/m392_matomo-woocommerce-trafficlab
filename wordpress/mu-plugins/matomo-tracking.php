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
?>
    _paq.push(['trackPageView']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End Matomo -->
    <?php
}, 5);
