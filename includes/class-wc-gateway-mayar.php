<?php
/**
 * WooCommerce Mayar.id Payment Gateway
 *
 * Extends WC_Payment_Gateway to provide Mayar.id payment integration.
 *
 * @package Mayar_With_Vodeco
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Mayar extends WC_Payment_Gateway {

    /**
     * Whether the gateway is enabled
     *
     * @var string
     */
    public $enabled;

    /**
     * Gateway title
     *
     * @var string
     */
    public $title;

    /**
     * Gateway description
     *
     * @var string
     */
    public $description;

    /**
     * Mayar.id API key
     *
     * @var string
     */
    private $api_key;

    /**
     * Whether using sandbox mode
     *
     * @var string
     */
    public $sandbox;

    /**
     * Webhook secret for verification
     *
     * @var string
     */
    private $webhook_secret;

    /**
     * API client instance
     *
     * @var Mayar_API
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'mayar';
        $this->icon               = MAYAR_WC_PLUGIN_URL . 'assets/images/idn6BZF_rt_logos.png';
        $this->has_fields         = false;
        $this->method_title       = __( 'Mayar.id', 'mayar-payment-gateway' );
        $this->method_description = __( 'Terima pembayaran melalui Mayar.id. Mendukung Virtual Account, QRIS, E-Wallet, Kartu Kredit/Debit, dan metode pembayaran lainnya.', 'mayar-payment-gateway' );
        $this->supports           = array( 'products' );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings
        $this->enabled      = $this->get_option( 'enabled', 'yes' );
        $this->title        = $this->get_option( 'title', __( 'Mayar.id', 'mayar-payment-gateway' ) );
        $this->description  = $this->get_option( 'description', __( 'Bayar menggunakan Mayar.id - Virtual Account, QRIS, E-Wallet, dan lainnya.', 'mayar-payment-gateway' ) );
        $this->api_key      = $this->get_option( 'api_key', '' );
        $this->sandbox      = $this->get_option( 'sandbox', 'yes' );
        $this->webhook_secret = $this->get_option( 'webhook_secret', '' );

        // Initialize API client
        $this->api = new Mayar_API( $this->api_key, 'yes' === $this->sandbox );

        // Save admin options
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Check webhook on settings save
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'maybe_register_webhook' ) );
    }

    /**
     * Initialize gateway settings fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'         => array(
                'title'   => __( 'Enable/Disable', 'mayar-payment-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Mayar.id Payment Gateway', 'mayar-payment-gateway' ),
                'default' => 'yes',
            ),
            'title'           => array(
                'title'       => __( 'Title', 'mayar-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'Judul yang ditampilkan di halaman checkout.', 'mayar-payment-gateway' ),
                'default'     => __( 'Mayar.id', 'mayar-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'description'     => array(
                'title'       => __( 'Description', 'mayar-payment-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Deskripsi yang ditampilkan di halaman checkout.', 'mayar-payment-gateway' ),
                'default'     => __( 'Bayar menggunakan Mayar.id - Virtual Account, QRIS, E-Wallet, Kartu Kredit/Debit, dan metode pembayaran lainnya.', 'mayar-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'sandbox'         => array(
                'title'       => __( 'Sandbox Mode', 'mayar-payment-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Sandbox Mode', 'mayar-payment-gateway' ),
                'description' => __( 'Aktifkan untuk menggunakan environment testing (sandbox).', 'mayar-payment-gateway' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'api_key'         => array(
                'title'       => __( 'API Key', 'mayar-payment-gateway' ),
                'type'        => 'password',
                'description' => sprintf(
                    __( 'Masukkan API Key dari Mayar.id. Dapatkan di %sProduction%s atau %sSandbox%s.', 'mayar-payment-gateway' ),
                    '<a href="https://web.mayar.id/api-keys" target="_blank">', '</a>',
                    '<a href="https://web.mayar.club/api-keys" target="_blank">', '</a>'
                ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'webhook_secret'  => array(
                'title'       => __( 'Webhook Secret', 'mayar-payment-gateway' ),
                'type'        => 'password',
                'description' => __( 'Secret key untuk verifikasi webhook (opsional). Kosongkan jika tidak digunakan.', 'mayar-payment-gateway' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'instructions'    => array(
                'title'       => __( 'Instructions', 'mayar-payment-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Instruksi pembayaran yang ditampilkan kepada pelanggan setelah checkout.', 'mayar-payment-gateway' ),
                'default'     => __( 'Anda akan diarahkan ke halaman Mayar.id untuk menyelesaikan pembayaran. Pilih metode pembayaran yang diinginkan dan ikuti instruksi yang diberikan.', 'mayar-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'enable_popup'   => array(
                'title'       => __( 'Popup Payment Mode', 'mayar-payment-gateway' ),
                'type'        => 'checkbox',
                'label'       => __( 'Tampilkan halaman pembayaran dalam popup di halaman checkout', 'mayar-payment-gateway' ),
                'description' => __( 'Aktifkan agar pelanggan tidak di-redirect ke halaman Mayar.id, melainkan membuka popup pembayaran di halaman checkout yang sama.', 'mayar-payment-gateway' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Process the payment
     *
     * @param int $order_id WooCommerce order ID.
     * @return array Redirect data.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order tidak ditemukan.', 'mayar-payment-gateway' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Validate API key
        if ( empty( $this->api_key ) ) {
            wc_add_notice( __( 'API Key Mayar.id belum dikonfigurasi. Silakan hubungi administrator.', 'mayar-payment-gateway' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Check if payment already exists for this order
        $existing_payment_id = $order->get_meta( '_mayar_payment_id' );
        if ( ! empty( $existing_payment_id ) ) {
            $existing_result = $this->api->get_payment( $existing_payment_id );
            if ( ! is_wp_error( $existing_result ) && isset( $existing_result['data']['link'] ) ) {
                // Check if payment is still valid (not expired, not paid)
                $payment_status = isset( $existing_result['data']['status'] ) ? $existing_result['data']['status'] : '';
                if ( 'paid' !== $payment_status && 'closed' !== $payment_status ) {
                    // Reuse existing payment link
                    return array(
                        'result'   => 'success',
                        'redirect' => $existing_result['data']['link'],
                    );
                }
            }
        }

        // Calculate expiration (24 hours from now)
        $expired_at = gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( '+24 hours' ) );

        // Prepare payment data
        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();
        $customer_name  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_number   = $order->get_order_number();
        $store_name     = get_bloginfo( 'name' );

        $payment_params = array(
            'name'        => sprintf( 'Order #%s - %s', $order_number, $store_name ),
            'amount'      => (int) $order->get_total(),
            'email'       => $customer_email,
            'mobile'      => $customer_phone,
            'description' => sprintf( __( 'Pembayaran untuk pesanan #%s dari %s', 'mayar-payment-gateway' ), $order_number, $store_name ),
            'expiredAt'   => $expired_at,
            'extraData'   => array(
                'order_id'     => (string) $order->get_id(),
                'order_key'    => $order->get_order_key(),
                'order_number' => $order_number,
            ),
        );

        // Create payment request
        $result = $this->api->create_payment( $payment_params );

        if ( is_wp_error( $result ) ) {
            $error_message = $result->get_error_message();
            $order->add_order_note( sprintf( 'Mayar.id: Gagal membuat payment request - %s', $error_message ) );
            wc_add_notice( sprintf( __( 'Gagal membuat pembayaran: %s', 'mayar-payment-gateway' ), $error_message ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Check response
        if ( ! isset( $result['data']['link'] ) || ! isset( $result['data']['id'] ) ) {
            $order->add_order_note( 'Mayar.id: Response tidak valid dari API' );
            wc_add_notice( __( 'Gagal membuat pembayaran. Response tidak valid.', 'mayar-payment-gateway' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Store payment data in order
        $payment_id     = $result['data']['id'];
        $transaction_id = isset( $result['data']['transactionId'] ) ? $result['data']['transactionId'] : '';
        $payment_link   = $result['data']['link'];

        $order->update_meta_data( '_mayar_payment_id', $payment_id );
        $order->update_meta_data( '_mayar_transaction_id', $transaction_id );
        $order->update_meta_data( '_mayar_payment_link', $payment_link );
        $order->update_meta_data( '_mayar_payment_status', 'pending' );
        $order->set_status( 'pending' );
        $order->add_order_note( sprintf(
            'Mayar.id: Payment request dibuat. Payment ID: %s | Link: %s',
            $payment_id,
            $payment_link
        ) );
        $order->save();

        // Log success
        $logger = wc_get_logger();
        $logger->info( sprintf( 'Mayar: Payment created for order #%d - Payment ID: %s', $order->get_id(), $payment_id ), array( 'source' => 'mayar-wc' ) );

        // Empty cart
        WC()->cart->empty_cart();

        // Redirect to Mayar payment page
        return array(
            'result'   => 'success',
            'redirect' => $payment_link,
        );
    }

    /**
     * Register webhook with Mayar.id when settings are saved
     */
    public function maybe_register_webhook() {
        if ( 'yes' !== $this->get_option( 'enabled', 'yes' ) ) {
            return;
        }

        $api_key = $this->get_option( 'api_key', '' );
        if ( empty( $api_key ) ) {
            return;
        }

        $webhook_url = rest_url( 'mayar-wc/v1/webhook' );

        // Create temporary API instance with new settings
        $sandbox = 'yes' === $this->get_option( 'sandbox', 'yes' );
        $api     = new Mayar_API( $api_key, $sandbox );

        $result = $api->register_webhook( $webhook_url );

        if ( ! is_wp_error( $result ) ) {
            $logger = wc_get_logger();
            $logger->info( 'Mayar: Webhook registered successfully', array( 'source' => 'mayar-wc', 'url' => $webhook_url ) );

            // Store registered webhook URL
            update_option( 'mayar_wc_registered_webhook', $webhook_url );
        } else {
            $logger = wc_get_logger();
            $logger->warning( 'Mayar: Failed to register webhook - ' . $result->get_error_message(), array( 'source' => 'mayar-wc' ) );
        }
    }

    /**
     * Get API key
     *
     * @return string
     */
    public function get_api_key() {
        return $this->api_key;
    }

    /**
     * Check if sandbox mode is active
     *
     * @return bool
     */
    public function is_sandbox() {
        return 'yes' === $this->sandbox;
    }

    /**
     * Get webhook URL for display
     *
     * @return string
     */
    public function get_webhook_url() {
        return rest_url( 'mayar-wc/v1/webhook' );
    }

    /**
     * Add admin order meta box for Mayar payment info
     *
     * @param WC_Order $order Order object.
     */
    public function display_admin_order_meta( $order ) {
        if ( 'mayar' !== $order->get_payment_method() ) {
            return;
        }

        $payment_id     = $order->get_meta( '_mayar_payment_id' );
        $transaction_id = $order->get_meta( '_mayar_transaction_id' );
        $payment_status = $order->get_meta( '_mayar_payment_status' );
        $payment_link   = $order->get_meta( '_mayar_payment_link' );

        if ( $payment_id ) {
            echo '<div class="mayar-order-info" style="margin-top: 15px; padding: 15px; background: #f8f8f8; border-left: 4px solid #0073aa;">';
            echo '<h3 style="margin: 0 0 10px 0;">Mayar.id Payment Info</h3>';
            echo '<p><strong>Payment ID:</strong> ' . esc_html( $payment_id ) . '</p>';
            if ( $transaction_id ) {
                echo '<p><strong>Transaction ID:</strong> ' . esc_html( $transaction_id ) . '</p>';
            }
            echo '<p><strong>Status:</strong> ' . esc_html( ucfirst( $payment_status ) ) . '</p>';
            if ( $payment_link ) {
                echo '<p><strong>Payment Link:</strong> <a href="' . esc_url( $payment_link ) . '" target="_blank">' . esc_html( $payment_link ) . '</a></p>';
            }
            echo '</div>';
        }
    }
}
