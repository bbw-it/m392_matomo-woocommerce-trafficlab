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
 *                               (Backfill-Fenster) widerspiegelt;
 *                               oder { "carts": [[[sku, menge], ...], ...] } für
 *                               explizite Warenkörbe (je Bestellung) – so legt der
 *                               Live-Tropf Bestellungen mit EXAKT den Artikeln an,
 *                               die auch in Matomo getrackt wurden.
 *                             Header: X-M392-Key: <gemeinsames Secret>
 *   GET  /weights           → Beliebtheits-Gewichte je SKU (0..100, WP-Option)
 *   POST /weights           → Gewichte setzen/mergen (Header: X-M392-Key)
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
                // „vollständig eingerichtet": Marker wird am ENDE von wp-init gesetzt
                // (nach Kategorien/Gutschein/Verkaufsländern) – verhindert, dass der
                // Bestell-Seed startet, bevor z. B. der Gutschein existiert.
                'provisioned' => (bool) (get_option('m392_fixture_restored') || get_option('m392_shop_seeded')),
            ];
        },
    ]);

    // Produktumsatz-Summe separat (NICHT im /ping-Readiness-Check, da O(n) über
    // alle Bestellungen). Nur fürs idempotente Richtwert-Seeding (einmalige Prüfung).
    register_rest_route('m392/v1', '/orders-revenue', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            return ['revenue' => function_exists('wc_get_orders') ? m392_orders_revenue_sum() : 0.0];
        },
    ]);

    register_rest_route('m392/v1', '/orders', [
        'methods'             => 'POST',
        'permission_callback' => function (WP_REST_Request $req) {
            return hash_equals(m392_order_api_key(), (string) $req->get_header('x-m392-key'));
        },
        'callback'            => 'm392_create_orders',
    ]);

    // Live-Produktliste fuer das Traffic Lab: liefert SKU/Name/Preis/Kategorie
    // EXAKT so, wie der Matomo-Tracker echte Shop-Aktionen erfasst (SKU = echte
    // SKU oder 'wc_<id>'). So bleiben synthetische Matomo-Daten und realer Shop
    // deckungsgleich – und neu angelegte Produkte werden automatisch beruecksichtigt.
    register_rest_route('m392/v1', '/products', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'm392_list_products',
    ]);

    // Beliebtheits-Gewichte je Produkt (0..100, gesteuert vom Traffic Lab).
    // Als WP-Option persistiert, damit die Regler-Einstellungen Neustarts und
    // Rebuilds des Traffic-Containers ueberleben (./install.sh setzt zurueck).
    register_rest_route('m392/v1', '/weights', [
        [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function () {
                $w = get_option('m392_product_weights', []);
                return ['weights' => is_array($w) && $w ? $w : new stdClass()];
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => function (WP_REST_Request $req) {
                return hash_equals(m392_order_api_key(), (string) $req->get_header('x-m392-key'));
            },
            'callback'            => function (WP_REST_Request $req) {
                $in = $req->get_param('weights');
                if (!is_array($in)) {
                    return new WP_REST_Response(['error' => 'weights (Objekt sku => 0..100) fehlt'], 400);
                }
                // replace=true ersetzt die Option komplett (sonst Merge je Key).
                $replace = (bool) $req->get_param('replace');
                $w = $replace ? [] : get_option('m392_product_weights', []);
                if (!is_array($w)) { $w = []; }
                foreach ($in as $sku => $val) {
                    $sku = (string) $sku;
                    // SKUs enthalten nie Whitespace – fehlerhafte Keys verwerfen.
                    if ($sku === '' || preg_match('/\s/', $sku)) { continue; }
                    $w[$sku] = max(0, min(100, (int) $val));
                }
                update_option('m392_product_weights', $w, false);
                return ['weights' => $w ?: new stdClass()];
            },
        ],
    ]);
});

/** Bestellstatus, die als „Umsatz" zählen (bezahlt bzw. in Abwicklung; ohne
 *  storniert/erstattet/offen). Wird sowohl für die Ping-Umsatzsumme als auch für
 *  den pro-Batch zurückgemeldeten Umsatz beim Richtwert-Seeding genutzt – damit
 *  beide Seiten denselben Umsatzbegriff verwenden. */
function m392_revenue_statuses() {
    return ['processing', 'completed', 'on-hold'];
}

/** Summe des PRODUKTUMSATZES (Zwischensumme, OHNE Versand) über alle
 *  „Umsatz"-Bestellungen. Bewusst `get_subtotal()` statt `get_total()`:
 *  So entspricht der Richtwert-/Seed-Umsatz dem WooCommerce-„Bruttoumsatz"
 *  und damit exakt dem, was wir nach Matomo spiegeln (Versand bleibt außen vor). */
function m392_orders_revenue_sum() {
    if (!function_exists('wc_get_orders')) { return 0.0; }
    $ids = wc_get_orders([
        'limit'  => -1,
        'status' => m392_revenue_statuses(),
        'return' => 'ids',
    ]);
    $sum = 0.0;
    foreach ($ids as $oid) {
        $o = wc_get_order($oid);
        if ($o) { $sum += (float) $o->get_subtotal(); }
    }
    return round($sum, 2);
}

/** Live-Produkte des Shops im selben Schema wie der Matomo-Tracker. */
function m392_list_products() {
    if (!function_exists('wc_get_products')) {
        return new WP_REST_Response(['products' => []], 200);
    }
    $out = [];
    foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $p) {
        $sku = $p->get_sku();
        if (!$sku) { $sku = 'wc_' . $p->get_id(); }    // identisch zur Tracker-Logik
        $cat_name = ''; $cat_slug = '';
        $terms = get_the_terms($p->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) { $cat_name = $terms[0]->name; $cat_slug = $terms[0]->slug; }
        $out[] = [
            'id'            => $p->get_id(),
            'sku'           => $sku,
            'name'          => $p->get_name(),
            'price'         => (float) $p->get_price(),
            'category'      => $cat_name,
            'category_slug' => $cat_slug,
            'slug'          => $p->get_slug(),
            'total_sales'   => (int) $p->get_total_sales(),
        ];
    }
    return new WP_REST_Response(['products' => $out], 200);
}

/** Legt `count` realistische Bestellungen an (datiert innerhalb `days_back` Tage). */
function m392_create_orders(WP_REST_Request $req) {
    if (!function_exists('wc_create_order')) {
        return new WP_REST_Response(['error' => 'WooCommerce nicht bereit'], 503);
    }
    // Während dieses Requests KEINE E-Mails verschicken (kein SMTP in der Lehrumgebung).
    add_filter('pre_wp_mail', '__return_false', 99);

    // Explizite Bestelldaten (Epoch-Sekunden) haben Vorrang: eine Bestellung je
    // Zeitstempel. So kann das Traffic Lab die Bestellungen exakt über das
    // Backfill-Fenster verteilen (passend zur Matomo-Historie).
    $dates = $req->get_param('dates');
    $dates = is_array($dates)
        ? array_values(array_filter(array_map('intval', $dates)))
        : [];

    // Explizite Warenkoerbe (vom Live-Tropf): je Bestellung eine Liste [sku, menge].
    // Damit enthalten die echten WC-Bestellungen EXAKT die Artikel, die fuer diesen
    // Kauf in Matomo getrackt werden – Grundlage fuer identische Umsaetze.
    $carts = $req->get_param('carts');
    $carts = is_array($carts) ? array_values($carts) : [];

    if ($carts) {
        $count = min(200, count($carts));      // Sicherheitsdeckel pro Request
    } elseif ($dates) {
        $count = min(200, count($dates));      // Sicherheitsdeckel pro Request
    } else {
        $count = max(1, min(60, (int) $req->get_param('count')));
    }
    $days_back = max(0, min(1000, (int) $req->get_param('days_back')));

    // Anteil wiederkehrender Kund:innen (0..100 %). Steuerbar aus dem Traffic Lab;
    // Standard 35 %. Bei diesem Anteil wird – falls vorhanden – eine bestehende
    // Kund:in aus der DB wiederverwendet statt eine neue anzulegen.
    $returning_rate = $req->get_param('returning_rate');
    $returning_rate = ($returning_rate === null) ? 35 : max(0, min(100, (int) $returning_rate));

    // --- Stammdaten für realistische Bestellungen --------------------------
    // Namen nach Herkunft gruppiert (Vor-/Nachname stammen aus derselben Kultur,
    // damit die Paare stimmig sind). ~70% deutsch, ~30% aus in Berlin stark
    // vertretenen Communities (türkisch, arabisch, polnisch, vietnamesisch,
    // russisch, italienisch) – spiegelt die Vielfalt der Stadt wider.
    // Format: 'kürzel' => [Gewicht, [Vornamen...], [Nachnamen...]]
    $name_pool = [
        'de' => [70,
            ['Anna','Lena','Sophie','Marie','Laura','Julia','Hannah','Emma','Mia','Lea',
             'Lukas','Leon','Paul','Jonas','Finn','Noah','Elias','Felix','Max','Tim',
             'Martina','Sandra','Nicole','Stefan','Andreas','Thomas','Michael','Daniel','Sarah','Katrin'],
            ['Müller','Schmidt','Schneider','Fischer','Weber','Meyer','Wagner','Becker','Schulz',
             'Hoffmann','Koch','Bauer','Richter','Klein','Wolf','Schröder','Neumann','Braun',
             'Krüger','Hofmann','Zimmermann','Hartmann','Lange','Werner','Krause','Lehmann','König','Walter']],
        'tr' => [8,
            ['Mehmet','Emre','Mustafa','Hakan','Can','Murat','Burak','Kerem',
             'Aylin','Elif','Zeynep','Fatma','Esra','Merve','Selin','Derya'],
            ['Yılmaz','Demir','Şahin','Çelik','Kaya','Yıldız','Öztürk','Aydın','Arslan','Doğan']],
        'ar' => [6,
            ['Omar','Yusuf','Amir','Karim','Hassan','Tarek','Samir','Bilal',
             'Layla','Nour','Fatima','Yasmin','Amira','Salma','Rania','Dana'],
            ['Haddad','Nasser','Khalil','Ibrahim','Saleh','Mansour','Hamdan','Karam','Najjar','Rashid']],
        'pl' => [5,
            ['Piotr','Tomasz','Marek','Krzysztof','Michał','Paweł',
             'Katarzyna','Agnieszka','Magdalena','Joanna','Aleksandra','Natalia'],
            ['Nowak','Kowalski','Wiśniewski','Wójcik','Kowalczyk','Zieliński','Lewandowski','Szymański','Kaczmarek','Mazur']],
        'vn' => [3,
            ['Minh','Huy','Duc','Quan','Tuan','Nam',
             'Anh','Linh','Trang','Mai','Thao','Ngoc'],
            ['Nguyen','Tran','Le','Pham','Hoang','Vu','Dang','Bui','Do','Phan']],
        'ru' => [4,
            ['Dmitri','Sergei','Ivan','Alexei','Andrei','Nikolai',
             'Olga','Natalia','Tatiana','Irina','Elena','Anastasia'],
            ['Iwanow','Petrow','Sokolow','Wolkow','Kusnezow','Popow','Smirnow','Nowikow','Morozow','Kozlow']],
        'it' => [4,
            ['Luca','Marco','Matteo','Alessandro','Giuseppe','Davide',
             'Giulia','Francesca','Chiara','Sofia','Martina','Valentina'],
            ['Rossi','Russo','Ferrari','Esposito','Bianchi','Romano','Greco','Conti','Ricci','Marino']],
    ];
    // Gewichtetes Lostöpfchen der Herkünfte (Summe der Gewichte = 100 → Prozent).
    $culture_bag = [];
    foreach ($name_pool as $ckey => $cdef) {
        for ($w = 0; $w < $cdef[0]; $w++) { $culture_bag[] = $ckey; }
    }
    // Verkaufsländer DE/CH/AT mit Anteilen (Käufer:innen ≈ DE 65 / CH 20 / AT 15).
    // Format: kürzel => [Gewicht, Telefon-Vorwahl, [[Stadt, PLZ], ...]]
    $geo = [
        'DE' => [65, '+49', [['Berlin','10117'],['Hamburg','20095'],['München','80331'],['Köln','50667'],
                             ['Frankfurt am Main','60311'],['Stuttgart','70173'],['Leipzig','04109'],['Dresden','01067']]],
        'CH' => [20, '+41', [['Zürich','8001'],['Genève','1201'],['Basel','4001'],['Bern','3011'],
                             ['Lausanne','1003'],['Winterthur','8400'],['Luzern','6003']]],
        'AT' => [15, '+43', [['Wien','1010'],['Graz','8010'],['Linz','4020'],['Salzburg','5020'],['Innsbruck','6020']]],
    ];
    $geo_bag = [];
    foreach ($geo as $cc => $g) { for ($w = 0; $w < $g[0]; $w++) { $geo_bag[] = $cc; } }
    $streets = ['Hauptstraße','Bahnhofstraße','Gartenweg','Lindenallee','Friedrichstraße','Schulstraße',
                'Bergstraße','Rosenweg','Goethestraße','Schillerstraße','Mozartweg','Ahornstraße'];
    $mail_hosts = ['example.com','example.org','mail.example','post.example'];
    $payments = [
        ['m392_invoice', 'Kauf auf Rechnung'],
        ['m392_card',    'Kreditkarte'],
        ['m392_twint',   'TWINT'],
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

    // Kundenstamm fuer „wiederkehrende" Kaeufer einsammeln, damit ein Teil der
    // Bestellungen Bestandskund:innen zugeordnet wird (realistischer Mix).
    // WICHTIG: rollenUNabhaengig ueber die Bestellhistorie (wp_wc_orders), NICHT
    // per get_users(role=customer): Der Fixture-Restore bringt wp_users OHNE
    // usermeta mit (keine Rolle) – eine Rollen-Query faende diese Kund:innen
    // nicht, der Pool bestuende nur aus wenigen live angelegten Kund:innen und
    // eine einzige Kund:in bekaeme fast alle Folgebestellungen.
    global $wpdb;
    $customer_pool = array_map('intval', $wpdb->get_col(
        "SELECT DISTINCT customer_id FROM {$wpdb->prefix}wc_orders
         WHERE customer_id > 0 ORDER BY RAND() LIMIT 300"));

    // Rabattgutschein, der „ab und zu" eingeloest wird (~18 % der Bestellungen),
    // sofern er existiert (wird per wp-init reproduzierbar angelegt: NATUR10).
    $coupon_code = defined('M392_COUPON_CODE') ? M392_COUPON_CODE : 'natur10';
    $coupon_rate = 18;   // Prozent der Bestellungen mit Gutschein
    $coupon_exists = function_exists('wc_get_coupon_id_by_code')
        && wc_get_coupon_id_by_code($coupon_code) > 0;

    $created = [];
    $details = [];                        // pro Umsatz-Bestellung: ts/revenue/items (Matomo-Spiegelung)
    $all_details = [];                    // pro Bestellung (ALLE Status): fuer den Defer-Abschluss im Live-Tropf
    $batch_revenue = 0.0;                 // Summe der „Umsatz"-Bestellungen dieses Batches
    $returning_count = 0;                 // Anzahl Bestellungen von Bestandskund:innen (wiederkehrend)
    $revenue_statuses = m392_revenue_statuses();
    for ($i = 0; $i < $count; $i++) {
        // Herkunft ziehen (≈70% deutsch, ≈30% divers), Vor-/Nachname daraus.
        $culture = $culture_bag[array_rand($culture_bag)];
        $fn = $name_pool[$culture][1][array_rand($name_pool[$culture][1])];
        $ln = $name_pool[$culture][2][array_rand($name_pool[$culture][2])];
        // Verkaufsland (DE/CH/AT) + passende Stadt/PLZ ziehen.
        $country = $geo_bag[array_rand($geo_bag)];
        $phone_cc = $geo[$country][1];
        [$city, $plz] = $geo[$country][2][array_rand($geo[$country][2])];
        [$pm_id, $pm_title] = $payments[array_rand($payments)];

        // Kund:in bestimmen: ~35% Bestandskund:in (falls vorhanden), sonst neu.
        // Neue Kund:innen werden als echte WooCommerce-Kund:innen (Rolle customer)
        // angelegt und der Bestellung zugeordnet → erscheinen unter „Kunden".
        $customer_id = 0; $email = ''; $is_returning = false;
        if ($customer_pool && random_int(1, 100) <= $returning_rate) {
            $cid = (int) $customer_pool[array_rand($customer_pool)];
            $cu = get_userdata($cid);
            if ($cu) {
                $customer_id = $cid;
                $is_returning = true;
                // Stammdaten der Kund:in uebernehmen. Fixture-Kund:innen haben
                // keine usermeta (Restore ohne wp_usermeta) – dann liefert die
                // Analytics-Tabelle wc_customer_lookup Name/Adresse als Fallback,
                // damit Folgebestellungen zur bisherigen Historie passen.
                $lk = $wpdb->get_row($wpdb->prepare(
                    "SELECT first_name, last_name, email, city, postcode, country
                       FROM {$wpdb->prefix}wc_customer_lookup WHERE user_id = %d",
                    $cid), ARRAY_A) ?: [];
                $fn    = get_user_meta($cid, 'billing_first_name', true)
                         ?: ($cu->first_name ?: (($lk['first_name'] ?? '') ?: $fn));
                $ln    = get_user_meta($cid, 'billing_last_name', true)
                         ?: ($cu->last_name ?: (($lk['last_name'] ?? '') ?: $ln));
                $email = $cu->user_email ?: ($lk['email'] ?? '');
                $rcity = get_user_meta($cid, 'billing_city', true)     ?: ($lk['city'] ?? '');
                $rplz  = get_user_meta($cid, 'billing_postcode', true) ?: ($lk['postcode'] ?? '');
                $rco   = get_user_meta($cid, 'billing_country', true)  ?: ($lk['country'] ?? '');
                if ($rcity) { $city = $rcity; }
                if ($rplz)  { $plz = $rplz; }
                if ($rco && isset($geo[$rco])) { $country = $rco; $phone_cc = $geo[$rco][1]; }
            }
        }
        if (!$customer_id) {
            $email = m392_email_local($fn, $ln) . random_int(1, 99) . '@' . $mail_hosts[array_rand($mail_hosts)];
            $existing = get_user_by('email', $email);
            if ($existing) {
                $customer_id = (int) $existing->ID;
            } elseif (function_exists('wc_create_new_customer')) {
                $uname = m392_email_local($fn, $ln) . random_int(100, 9999);
                $new = wc_create_new_customer($email, $uname, wp_generate_password(12, false),
                                              ['first_name' => $fn, 'last_name' => $ln]);
                if (!is_wp_error($new)) {
                    $customer_id = (int) $new;
                    update_user_meta($customer_id, 'billing_first_name', $fn);
                    update_user_meta($customer_id, 'billing_last_name',  $ln);
                    update_user_meta($customer_id, 'billing_city',       $city);
                    update_user_meta($customer_id, 'billing_postcode',   $plz);
                    update_user_meta($customer_id, 'billing_country',    $country);
                    $customer_pool[] = $customer_id;   // ab jetzt als Bestandskund:in nutzbar
                }
            }
        }

        $order = wc_create_order();
        if (is_wp_error($order)) { continue; }
        if ($customer_id) { $order->set_customer_id($customer_id); }

        // Positionen (zusätzlich die Artikel für die Matomo-Spiegelung sammeln:
        // [sku, name, kategorie, preis, menge] – exakt das Matomo-ec_items-Format).
        // Mit explizitem Warenkorb (carts[i]) werden GENAU dessen Artikel verwendet;
        // sonst gewichtete Zufallsauswahl. Unauflösbare SKUs fallen auf Zufall zurück.
        $lines = [];
        if (isset($carts[$i]) && is_array($carts[$i])) {
            foreach ($carts[$i] as $entry) {
                $pid = m392_product_id_by_sku((string) ($entry[0] ?? ''));
                $product = $pid ? wc_get_product($pid) : false;
                if ($product) {
                    $lines[] = [$product, max(1, min(9, (int) ($entry[1] ?? 1)))];
                }
            }
        }
        if (!$lines) {
            foreach ($pick_products() as $pid) {
                $product = wc_get_product($pid);
                if (!$product) { continue; }
                $lines[] = [$product, (random_int(1, 100) <= 80) ? 1 : 2];
            }
        }
        $subtotal = 0.0;
        $line_items = [];
        foreach ($lines as [$product, $qty]) {
            $order->add_product($product, $qty);
            $subtotal += (float) $product->get_price() * $qty;
            $sku = $product->get_sku(); if (!$sku) { $sku = 'wc_' . $product->get_id(); }
            $cat = '';
            $terms = get_the_terms($product->get_id(), 'product_cat');
            if ($terms && !is_wp_error($terms)) { $cat = $terms[0]->name; }
            $line_items[] = [$sku, $product->get_name(), $cat, (float) $product->get_price(), $qty];
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
            'country'    => $country,
            'email'      => $email,
            'phone'      => $phone_cc . ' ' . random_int(60, 79) . ' ' . random_int(1000000, 9999999),
        ];
        $order->set_address($addr, 'billing');
        $order->set_address($addr, 'shipping');

        $order->set_payment_method($pm_id);
        $order->set_payment_method_title($pm_title);

        $note = $notes[array_rand($notes)];
        if ($note) { $order->set_customer_note($note); }

        // Gutschein „ab und zu" einloesen (vor der Summenberechnung). Schlaegt das
        // fehl (z. B. Gutschein abgelaufen/Nutzungslimit), nicht still ignorieren,
        // sondern als Bestellnotiz + PHP-Log festhalten.
        if ($coupon_exists && random_int(1, 100) <= $coupon_rate) {
            $applied = $order->apply_coupon($coupon_code);
            if (is_wp_error($applied)) {
                $msg = sprintf('M392: Gutschein "%s" nicht anwendbar: %s',
                               $coupon_code, $applied->get_error_message());
                $order->add_order_note($msg);
                error_log($msg);
            }
        }

        // Bestelldatum: explizit (dates[]) oder zufällig in den letzten
        // `days_back` Tagen – sonst „jetzt".
        if ($dates) {
            $order->set_date_created($dates[$i]);
        } elseif ($days_back > 0) {
            $order->set_date_created(time() - random_int(0, $days_back * 86400) - random_int(0, 86399));
        }

        $order->calculate_totals();
        $status = m392_pick_status($pm_id);
        $order->set_status($status);

        // Bezahlt-/Abschlussdatum setzen, damit Umsatz-Berichte (die nach
        // date_paid/date_completed gruppieren) sowie die Statistik stimmen.
        $created_dt = $order->get_date_created();
        if ($created_dt && in_array($status, ['processing', 'completed', 'refunded'], true)) {
            $order->set_date_paid($created_dt->getTimestamp());
        }
        if ($created_dt && $status === 'completed') {
            $order->set_date_completed($created_dt->getTimestamp());
        }
        $order->save();

        // ALLE wc-admin-Analytics-Tabellen fuer diese Bestellung synchronisieren
        // (Bestell-Statistik, Produkte, Gutscheine, Steuern, Kund:innen). Sonst
        // bleiben Berichte/Statistik und Kunden-Gesamtausgaben leer (es laeuft
        // kein Action-Scheduler, der das sonst asynchron erledigen wuerde).
        m392_sync_order_analytics($order->get_id());

        // Kund:innen-Daten konsistent zur Bestellhistorie halten: Anmeldedatum darf
        // nicht NACH der Bestellung liegen (sonst sieht eine Kund:in mit 6-Monats-
        // Historie wie heute frisch registriert aus). Wir datieren Registrierung auf
        // die FRÜHESTE und „zuletzt aktiv" auf die SPÄTESTE Bestellung – gilt fuer
        // Seed (datierte Bestellungen) wie Live-Tropf (Bestellung = jetzt).
        $ots = $created_dt ? $created_dt->getTimestamp() : time();
        m392_fix_customer_dates($customer_id, $ots);

        if (in_array($status, $revenue_statuses, true)) {
            // Produktumsatz OHNE Versand (= WC-Bruttoumsatz) – konsistent für
            // Richtwert/Idempotenz UND Matomo-Spiegelung (Versand bleibt außen vor).
            $sub = (float) $order->get_subtotal();
            $batch_revenue += $sub;
            // Detail für die Matomo-Spiegelung: Zeitstempel, Produktumsatz, Artikel.
            $details[] = [
                'ts'      => (int) ($created_dt ? $created_dt->getTimestamp() : time()),
                'revenue' => round($sub, 2),
                'items'   => $line_items,
            ];
        }
        // Detail je Bestellung UNABHÄNGIG vom Status: Der Live-Tropf trackt die
        // Matomo-Conversion zum Kaufzeitpunkt (real: Tracking feuert beim Kauf,
        // Stornos passieren später) und braucht dafür den echten Produktumsatz.
        $all_details[] = [
            'ts'       => (int) ($created_dt ? $created_dt->getTimestamp() : time()),
            'subtotal' => round((float) $order->get_subtotal(), 2),
            'items'    => $line_items,
            'status'   => $status,
        ];
        $created[] = $order->get_id();
        if ($is_returning) { $returning_count++; }
    }

    return new WP_REST_Response([
        'count'         => count($created),
        'created'       => $created,
        'revenue'       => round($batch_revenue, 2),
        'returning'     => $returning_count,
        'details'       => $details,
        'orders_detail' => $all_details,
    ], 201);
}

/** Synchronisiert ALLE wc-admin-Analytics-Tabellen fuer eine Bestellung, damit
 *  Statistik/Berichte (Umsatz, Produkte, Gutscheine) UND die Kunden-Gesamt-
 *  ausgaben sofort stimmen – ohne auf den Action-Scheduler zu warten.
 *  Reihenfolge: erst Kund:in (legt customer_id an), dann die uebrigen Tabellen. */
function m392_sync_order_analytics($order_id) {
    $steps = [
        ['\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Customers\\DataStore', 'sync_order_customer'],
        ['\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Orders\\Stats\\DataStore', 'sync_order'],
        ['\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Products\\DataStore', 'sync_order_products'],
        ['\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Coupons\\DataStore', 'sync_order_coupons'],
        ['\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Taxes\\DataStore', 'sync_order_taxes'],
    ];
    foreach ($steps as [$cls, $method]) {
        if (class_exists($cls) && method_exists($cls, $method)) {
            try { $cls::$method($order_id); } catch (\Throwable $e) { /* ignorieren */ }
        }
    }
}

/** Hält Anmelde-/Aktivitätsdatum einer Kund:in konsistent zur Bestellhistorie.
 *  - wp_users.user_registered wird nur ZURUECK-datiert (nie in die Zukunft).
 *  - wc_customer_lookup.date_registered = frueheste, date_last_active = spaeteste
 *    Bestellung (ueber min/max akkumuliert ueber alle Bestellungen der Kund:in).
 *  Idempotent: mehrfacher Aufruf mit verschiedenen Bestelldaten konvergiert. */
function m392_fix_customer_dates($customer_id, $order_ts) {
    $customer_id = (int) $customer_id;
    if ($customer_id <= 0 || $order_ts <= 0) { return; }
    global $wpdb;
    $dt = gmdate('Y-m-d H:i:s', (int) $order_ts);

    // Registrierung nur zurueckdatieren, wenn die Bestellung aelter ist.
    $u = get_userdata($customer_id);
    if ($u && $u->user_registered && $dt < $u->user_registered) {
        $wpdb->update($wpdb->users, ['user_registered' => $dt], ['ID' => $customer_id]);
        clean_user_cache($customer_id);
    }

    // Analytics-Lookup: frueheste Bestellung = Registrierung, spaeteste = zuletzt aktiv.
    $t = $wpdb->prefix . 'wc_customer_lookup';
    $wpdb->query($wpdb->prepare(
        "UPDATE {$t} SET
            date_registered  = LEAST(COALESCE(date_registered, %s), %s),
            date_last_active = GREATEST(COALESCE(date_last_active, %s), %s)
         WHERE customer_id = %d",
        $dt, $dt, $dt, $dt, $customer_id));
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

/** Produkt-ID aus SKU auflösen – inkl. des Tracker-Fallbacks 'wc_<id>'. */
function m392_product_id_by_sku($sku) {
    if ($sku === '') { return 0; }
    $pid = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($sku) : 0;
    if (!$pid && preg_match('/^wc_(\d+)$/', $sku, $m)) { $pid = (int) $m[1]; }
    return $pid;
}

/** Umlaute/ß und gängige diakritische Zeichen für E-Mail-Adressen vereinfachen. */
function m392_translit($s) {
    $map = [
        // Deutsch
        'ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue',
        // Türkisch
        'ı'=>'i','İ'=>'i','ş'=>'s','Ş'=>'s','ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g',
        // Polnisch
        'ł'=>'l','Ł'=>'l','ś'=>'s','ż'=>'z','ź'=>'z','ć'=>'c','ń'=>'n','ą'=>'a','ę'=>'e','ó'=>'o',
        // weitere Latin-Akzente (it./fr./vn. Restbestände)
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ô'=>'o','õ'=>'o','ù'=>'u','ú'=>'u','û'=>'u',
        'ñ'=>'n','đ'=>'d','Đ'=>'d',
    ];
    return strtr($s, $map);
}

/** Lokaler Teil einer Demo-E-Mail aus Vor-/Nachname (nur a-z0-9.). */
function m392_email_local($fn, $ln) {
    $local = strtolower(m392_translit($fn . '.' . $ln));
    // Restliche Nicht-ASCII-Zeichen (z. B. vietnamesische Tonzeichen) entfernen.
    $local = preg_replace('/[^a-z0-9.]+/', '', $local);
    return trim($local, '.') ?: 'kunde';
}
