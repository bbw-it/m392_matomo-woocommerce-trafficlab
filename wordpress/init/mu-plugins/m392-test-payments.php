<?php
/**
 * Plugin Name: M392 Test-Zahlungsmethoden
 * Description: Stellt drei Test-Zahlungsmethoden bereit (Kauf auf Rechnung, Kreditkarte 4242, TWINT) für die Lehrumgebung Modul 392. Alle sind standardmässig aktiviert.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * Hilfsfunktion: Warenkorb sicher leeren (in Nicht-Checkout-Kontexten kann WC()->cart fehlen).
 */
function m392_safe_empty_cart() {
    if (function_exists('WC') && WC() && isset(WC()->cart) && WC()->cart) {
        WC()->cart->empty_cart();
    }
}

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    /**
     * 1) Kauf auf Rechnung
     */
    class M392_Gateway_Invoice extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                 = 'm392_invoice';
            $this->method_title       = 'Kauf auf Rechnung (Test)';
            $this->method_description = 'Test-Zahlungsmethode: Bestellung wird auf "in Wartestellung" gesetzt, Bezahlung per Rechnung.';
            $this->has_fields         = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled     = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Aktivieren/Deaktivieren',
                    'type'    => 'checkbox',
                    'label'   => 'Kauf auf Rechnung aktivieren',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => 'Titel',
                    'type'        => 'text',
                    'description' => 'Name der Zahlungsmethode im Checkout.',
                    'default'     => 'Kauf auf Rechnung',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Beschreibung',
                    'type'        => 'textarea',
                    'description' => 'Beschreibung der Zahlungsmethode im Checkout.',
                    'default'     => 'Sie erhalten die Rechnung per E-Mail und bezahlen innert 30 Tagen.',
                ),
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $order->update_status('on-hold', 'Zahlung per Rechnung ausstehend (Test).');
            wc_reduce_stock_levels($order_id);
            m392_safe_empty_cart();
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    }

    /**
     * 2) Kreditkarte (Test)
     */
    class M392_Gateway_Card extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                 = 'm392_card';
            $this->method_title       = 'Kreditkarte (Test)';
            $this->method_description = 'Test-Kreditkarte: nur die Karte 4242 4242 4242 4242 wird akzeptiert, alle anderen werden abgelehnt.';
            $this->has_fields         = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled     = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Aktivieren/Deaktivieren',
                    'type'    => 'checkbox',
                    'label'   => 'Kreditkarte (Test) aktivieren',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => 'Titel',
                    'type'        => 'text',
                    'description' => 'Name der Zahlungsmethode im Checkout.',
                    'default'     => 'Kreditkarte (Test)',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Beschreibung',
                    'type'        => 'textarea',
                    'description' => 'Beschreibung der Zahlungsmethode im Checkout.',
                    'default'     => 'Bezahlen Sie mit Ihrer Test-Kreditkarte.',
                ),
            );
        }

        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
            ?>
            <div class="m392-card-fields" style="padding:8px 0;">
                <p style="margin:0 0 8px;padding:8px;background:#fff8e1;border:1px solid #ffe082;border-radius:4px;font-size:13px;">
                    <strong>Testkarte:</strong> 4242 4242 4242 4242, beliebiges Ablaufdatum in der Zukunft, beliebige CVC.
                </p>
                <p class="form-row form-row-wide">
                    <label for="m392card_number">Kartennummer <span class="required">*</span></label>
                    <input id="m392card_number" name="m392card_number" type="text" autocomplete="off"
                           placeholder="4242 4242 4242 4242" inputmode="numeric" />
                </p>
                <p class="form-row form-row-first">
                    <label for="m392card_expiry">Ablaufdatum (MM/YY) <span class="required">*</span></label>
                    <input id="m392card_expiry" name="m392card_expiry" type="text" autocomplete="off"
                           placeholder="MM/YY" />
                </p>
                <p class="form-row form-row-last">
                    <label for="m392card_cvc">CVC <span class="required">*</span></label>
                    <input id="m392card_cvc" name="m392card_cvc" type="text" autocomplete="off"
                           placeholder="123" inputmode="numeric" />
                </p>
                <div class="clear"></div>
            </div>
            <?php
        }

        public function process_payment($order_id) {
            $raw    = isset($_POST['m392card_number']) ? wc_clean(wp_unslash($_POST['m392card_number'])) : '';
            $digits = preg_replace('/\D/', '', (string) $raw);

            if ($digits === '4242424242424242') {
                $order = wc_get_order($order_id);
                $order->payment_complete();
                $order->add_order_note('Test-Kreditkarte akzeptiert (4242).');
                m392_safe_empty_cart();
                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            }

            wc_add_notice('Kreditkarte abgelehnt. Bitte die Testkarte 4242 4242 4242 4242 verwenden.', 'error');
            return false;
        }
    }

    /**
     * 3) TWINT (Test)
     */
    class M392_Gateway_Twint extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                 = 'm392_twint';
            $this->method_title       = 'TWINT (Test)';
            $this->method_description = 'Simulierte TWINT-Zahlung, die zu Demozwecken immer automatisch bestätigt wird.';
            $this->has_fields         = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled     = $this->get_option('enabled');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Aktivieren/Deaktivieren',
                    'type'    => 'checkbox',
                    'label'   => 'TWINT (Test) aktivieren',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => 'Titel',
                    'type'        => 'text',
                    'description' => 'Name der Zahlungsmethode im Checkout.',
                    'default'     => 'TWINT (Test)',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Beschreibung',
                    'type'        => 'textarea',
                    'description' => 'Beschreibung der Zahlungsmethode im Checkout.',
                    'default'     => 'Simulierte TWINT-Zahlung – wird zu Demozwecken automatisch bestätigt.',
                ),
            );
        }

        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
            ?>
            <div class="m392-twint" style="padding:8px 0;">
                <div style="display:flex;align-items:center;gap:10px;padding:10px;background:#000;border-radius:6px;color:#fff;">
                    <span style="font-weight:700;letter-spacing:1px;font-size:18px;">TWINT</span>
                    <span style="font-size:12px;opacity:.85;">Test-Modus</span>
                </div>
                <p class="form-row form-row-wide">
                    <label for="m392twint_phone">Mobilnummer (optional)</label>
                    <input id="m392twint_phone" name="m392twint_phone" type="text" autocomplete="off"
                           placeholder="+41 79 000 00 00" />
                </p>
                <div class="clear"></div>
            </div>
            <?php
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $order->payment_complete();
            $order->add_order_note('TWINT (Test) bestätigt.');
            m392_safe_empty_cart();
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        }
    }

    // Gateways registrieren (co-lokalisiert mit den Klassendefinitionen oben).
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'M392_Gateway_Invoice';
        $gateways[] = 'M392_Gateway_Card';
        $gateways[] = 'M392_Gateway_Twint';
        return $gateways;
    });
});
