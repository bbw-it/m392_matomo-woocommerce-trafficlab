<?php
/**
 * Plugin Name: M392 Deutsch-Feinschliff
 * Description: Übersetzt verbleibende englische UI-Strings von Theme/Plugins, die keine
 *              de_CH-Übersetzung mitbringen (Sale-Badge, Trust-Badges etc.). Lehrumgebung Modul 392.
 */
if (!defined('ABSPATH')) { exit; }

// WooCommerce-Sale-Badge auf Deutsch.
add_filter('woocommerce_sale_flash', function () {
    return '<span class="onsale">Angebot!</span>';
});

// Gezielte Übersetzungen einzelner Strings (greift unabhängig vom Plugin/Theme).
add_filter('gettext', function ($translated, $text) {
    static $map = [
        'Safe & Secure Checkout' => 'Sicherer & geschützter Checkout',
        'Sale!'                  => 'Angebot!',
        'Sale'                   => 'Angebot',
        'Related products'       => 'Ähnliche Produkte',
        'You may also like…'     => 'Das könnte dir auch gefallen …',
        'Quick View'             => 'Schnellansicht',
        'Add to wishlist'        => 'Zur Merkliste hinzufügen',
        'Free shipping'          => 'Kostenloser Versand',
    ];
    if (isset($map[$text])) { return $map[$text]; }
    // Fragment-Ersatz, falls der Quellstring zusätzliche Zeichen enthält (z. B. Emoji-Präfix).
    if (strpos($translated, 'Safe & Secure Checkout') !== false
        || strpos($translated, 'Money Back') !== false
        || strpos($translated, 'Free Shipping') !== false) {
        return str_replace(
            ['Safe & Secure Checkout', 'Money Back Guarantee', 'Money Back', 'Free Shipping'],
            ['Sicherer & geschützter Checkout', 'Geld-zurück-Garantie', 'Geld zurück', 'Kostenloser Versand'],
            $translated
        );
    }
    return $translated;
}, 20, 2);
