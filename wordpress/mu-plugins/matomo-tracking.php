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
    _paq.push(['trackPageView']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End Matomo -->
    <?php
}, 5);
