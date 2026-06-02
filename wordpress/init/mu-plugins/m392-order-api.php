<?php
/**
 * Plugin Name: M392 Order API
 * Description: Geschützter REST-Endpunkt, über den das „Traffic Lab" echte
 *              WooCommerce-Bestellungen mit realistischen Daten anlegt
 *              (Lehrumgebung Modul 392). Nur für lokale Demo/Schulung.
 *
 * Endpunkte (Namespace m392/v1):
 *   GET  /ping              → Status (WooCommerce bereit?, Produkt-/Bestellanzahl)
 *   POST /orders            → legt N realistische Bestellungen an
 *                             Body: { "count": 1..60, "days_back": 0..1000 }
 *                               oder { "dates": [<epoch>, ...] } für exakte
 *                               Bestelldaten (eine Bestellung je Zeitstempel),
 *                               damit die Bestell-Historie den Matomo-Zeitraum
 *                               (~24 Monate) widerspiegelt.
 *                             Header: X-M392-Key: <gemeinsames Secret>
 *
 * Die Bestelldaten (Kund:innen, Adressen, Produkte, Zahlart, Status) werden hier
 * serverseitig erzeugt – mit vollem WooCommerce-Zugriff (echte Produkte/Preise).
 */
if (!defined('ABSPATH')) { exit; }

/** Erwartetes Secret (aus Container-Env; Fallback = Compose-Default). */
function m392_order_api_key() {
    $k = getenv('M392_ORDER_API_KEY');
    if (!$k && isset($_SERVER['M392_ORDER_API_KEY'])) { $k = $_SERVER['M392_ORDER_API_KEY']; }
    return $k ?: 'm392-order-secret';
}

add_action('rest_api_init', function () {
    register_rest_route('m392/v1', '/ping', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $ready = function_exists('wc_get_products');
            $products = $ready ? wc_get_products(['limit' => -1, 'status' => 'publish', 'return' => 'ids']) : [];
            $orders   = $ready && function_exists('wc_get_orders')
                ? wc_get_orders(['limit' => -1, 'return' => 'ids']) : [];
            return [
                'ready'    => $ready && count($products) > 0,
                'products' => count($products),
                'orders'   => count($orders),
            ];
        },
    ]);

    register_rest_route('m392/v1', '/orders', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) {
            return hash_equals(m392_order_api_key(), (string) $req->get_header('x-m392-key'));
        },
        'callback'            => 'm392_create_orders',
    ]);
});

/** Legt `count` realistische Bestellungen an (datiert innerhalb `days_back` Tage). */
function m392_create_orders(WP_REST_Request $req) {
    if (!function_exists('wc_create_order')) {
        return new WP_REST_Response(['error' => 'WooCommerce nicht bereit'], 503);
    }
    // Während dieses Requests KEINE E-Mails verschicken (kein SMTP in der Lehrumgebung).
    add_filter('pre_wp_mail', '__return_false', 99);

    // Explizite Bestelldaten (Epoch-Sekunden) haben Vorrang: eine Bestellung je
    // Zeitstempel. So kann das Traffic Lab die Bestellungen exakt über die
    // letzten ~24 Monate verteilen (passend zur Matomo-Historie).
    $dates = $req->get_param('dates');
    $dates = is_array($dates)
        ? array_values(array_filter(array_map('intval', $dates)))
        : [];

    if ($dates) {
        $count = min(200, count($dates));      // Sicherheitsdeckel pro Request
    } else {
        $count = max(1, min(60, (int) $req->get_param('count')));
    }
    $days_back = max(0, min(1000, (int) $req->get_param('days_back')));

    // --- Stammdaten für realistische Bestellungen --------------------------
    $first = ['Anna','Lena','Sophie','Marie','Laura','Julia','Hannah','Emma','Mia','Lea',
              'Lukas','Leon','Paul','Jonas','Finn','Noah','Elias','Felix','Max','Tim',
              'Martina','Sandra','Nicole','Stefan','Andreas','Thomas','Michael','Daniel','Sarah','Katrin'];
    $last  = ['Müller','Schmidt','Schneider','Fischer','Weber','Meyer','Wagner','Becker','Schulz',
              'Hoffmann','Koch','Bauer','Richter','Klein','Wolf','Schröder','Neumann','Braun',
              'Krüger','Hofmann','Zimmermann','Hartmann','Lange','Werner','Krause','Lehmann','König','Walter'];
    $cities = [['Berlin','10117'],['Berlin','10967'],['Berlin','12047'],['Hamburg','20095'],
               ['München','80331'],['Köln','50667'],['Frankfurt am Main','60311'],['Stuttgart','70173'],
               ['Düsseldorf','40213'],['Leipzig','04109'],['Dresden','01067'],['Bremen','28195'],['Hannover','30159']];
    $streets = ['Hauptstraße','Bahnhofstraße','Gartenweg','Lindenallee','Friedrichstraße','Schulstraße',
                'Bergstraße','Rosenweg','Goethestraße','Schillerstraße','Mozartweg','Ahornstraße'];
    $mail_hosts = ['example.com','example.org','mail.example','post.example'];
    $payments = [
        ['m392_invoice', 'Kauf auf Rechnung'],
        ['m392_card',    'Kreditkarte (Test)'],
        ['m392_twint',   'TWINT (Test)'],
    ];
    $notes = ['', '', '', '', 'Bitte klingeln bei Nachbarn.', 'Lieferung bitte an die Haustür.',
              'Geschenk – bitte ohne Rechnung beilegen.', 'Bin werktags ab 16 Uhr zu Hause.'];

    // Produkte nach Verkaufszahlen gewichten → Bestseller landen häufiger im Korb.
    $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
    if (!$products) {
        return new WP_REST_Response(['error' => 'keine Produkte'], 503);
    }
    $weighted = [];
    foreach ($products as $p) {
        $w = max(1, (int) $p->get_total_sales());
        for ($i = 0; $i < $w; $i++) { $weighted[] = $p->get_id(); }
    }

    $pick_products = function () use ($weighted) {
        $n = (random_int(1, 100) <= 65) ? 1 : random_int(2, 3);   // meist 1 Artikel
        $ids = [];
        $guard = 0;
        while (count($ids) < $n && $guard++ < 30) {
            $id = $weighted[array_rand($weighted)];
            if (!in_array($id, $ids, true)) { $ids[] = $id; }
        }
        return $ids;
    };

    $created = [];
    for ($i = 0; $i < $count; $i++) {
        $fn = $first[array_rand($first)];
        $ln = $last[array_rand($last)];
        [$city, $plz] = $cities[array_rand($cities)];
        [$pm_id, $pm_title] = $payments[array_rand($payments)];

        $order = wc_create_order();
        if (is_wp_error($order)) { continue; }

        // Positionen
        $subtotal = 0.0;
        foreach ($pick_products() as $pid) {
            $product = wc_get_product($pid);
            if (!$product) { continue; }
            $qty = (random_int(1, 100) <= 80) ? 1 : 2;
            $order->add_product($product, $qty);
            $subtotal += (float) $product->get_price() * $qty;
        }

        // Versand (frei ab 50 €, sonst 4,90 €)
        $ship_cost = $subtotal >= 50 ? 0.0 : 4.90;
        $ship = new WC_Order_Item_Shipping();
        $ship->set_method_title('Standardversand');
        $ship->set_total((string) $ship_cost);
        $order->add_item($ship);

        // Adresse (Rechnung = Lieferung)
        $addr = [
            'first_name' => $fn,
            'last_name'  => $ln,
            'address_1'  => $streets[array_rand($streets)] . ' ' . random_int(1, 180),
            'city'       => $city,
            'postcode'   => $plz,
            'country'    => 'DE',
            'email'      => strtolower(m392_translit($fn . '.' . $ln)) . random_int(1, 99)
                            . '@' . $mail_hosts[array_rand($mail_hosts)],
            'phone'      => '+49 ' . random_int(150, 179) . ' ' . random_int(1000000, 9999999),
        ];
        $order->set_address($addr, 'billing');
        $order->set_address($addr, 'shipping');

        $order->set_payment_method($pm_id);
        $order->set_payment_method_title($pm_title);

        $note = $notes[array_rand($notes)];
        if ($note) { $order->set_customer_note($note); }

        // Bestelldatum: explizit (dates[]) oder zufällig in den letzten
        // `days_back` Tagen – sonst „jetzt".
        if ($dates) {
            $order->set_date_created($dates[$i]);
        } elseif ($days_back > 0) {
            $order->set_date_created(time() - random_int(0, $days_back * 86400) - random_int(0, 86399));
        }

        $order->calculate_totals();
        $order->set_status(m392_pick_status($pm_id));
        $order->save();

        $created[] = $order->get_id();
    }

    return new WP_REST_Response(['count' => count($created), 'created' => $created], 201);
}

/** Realistischer Status-Mix, leicht abhängig von der Zahlart. */
function m392_pick_status($payment_id) {
    $r = random_int(1, 100);
    if ($payment_id === 'm392_invoice') {
        // Rechnung: häufiger „wartet auf Zahlung"
        if ($r <= 40) return 'on-hold';
        if ($r <= 72) return 'processing';
        if ($r <= 92) return 'completed';
        if ($r <= 97) return 'pending';
        return 'cancelled';
    }
    // Karte / TWINT: meist bezahlt
    if ($r <= 52) return 'completed';
    if ($r <= 88) return 'processing';
    if ($r <= 93) return 'pending';
    if ($r <= 97) return 'refunded';
    return 'cancelled';
}

/** Umlaute/ß für E-Mail-Adressen vereinfachen. */
function m392_translit($s) {
    $map = ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue'];
    return strtr($s, $map);
}
