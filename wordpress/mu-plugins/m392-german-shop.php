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
    return $map[$text] ?? $translated;
}, 20, 2);
