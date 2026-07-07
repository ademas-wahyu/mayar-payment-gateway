<?php
/**
 * Plugin Name: Mayar Payment Gateway
 * Plugin URI: https://mayar.id
 * Description: Terima pembayaran melalui Mayar.id - mendukung Virtual Account, QRIS, E-Wallet, Kartu Kredit/Debit, dan masih banyak lagi.
 * Version: 1.0.0
 * Author: Wahyu (Vodeco Media Group)
 * Author URI: https://ademaswahyu.my.id
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mayar-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'MAYAR_WC_VERSION', '1.0.0' );
define( 'MAYAR_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAYAR_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MAYAR_WC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active before initializing
 */
function mayar_wc_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'mayar_wc_missing_wc_notice' );
        return false;
    }
    return true;
}

/**
 * Display admin notice if WooCommerce is not active
 */
function mayar_wc_missing_wc_notice() {
    ?>
    <div class="error">
        <p><strong><?php esc_html_e( 'Mayar.id Payment Gateway membutuhkan WooCommerce yang sudah terinstall dan aktif.', 'mayar-payment-gateway' ); ?></strong></p>
    </div>
    <?php
}

/**
 * Initialize plugin after all plugins are loaded
 */
function mayar_wc_init() {
    if ( ! mayar_wc_check_woocommerce() ) {
        return;
    }

    // Load dependencies
    require_once MAYAR_WC_PLUGIN_DIR . 'includes/class-mayar-api.php';
    require_once MAYAR_WC_PLUGIN_DIR . 'includes/class-wc-gateway-mayar.php';
    require_once MAYAR_WC_PLUGIN_DIR . 'includes/class-mayar-checkout-modal.php';

    // Register payment gateway
    add_filter( 'woocommerce_payment_gateways', 'mayar_wc_add_gateway' );

    // Initialize checkout modal
    new Mayar_Checkout_Modal();
}
add_action( 'plugins_loaded', 'mayar_wc_init' );

/**
 * Add Mayar.id gateway to WooCommerce payment gateways
 *
 * @param array $gateways Existing payment gateways.
 * @return array Modified payment gateways.
 */
function mayar_wc_add_gateway( $gateways ) {
    $gateways[] = 'WC_Gateway_Mayar';
    return $gateways;
}

/**
 * Add settings link to plugins page
 *
 * @param array $links Plugin action links.
 * @return array Modified links.
 */
function mayar_wc_plugin_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mayar' ) . '">' . __( 'Settings', 'mayar-payment-gateway' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . MAYAR_WC_PLUGIN_BASENAME, 'mayar_wc_plugin_links' );

/**
 * Display test mode notice in admin
 */
function mayar_wc_admin_notice_test_mode() {
    $settings = get_option( 'woocommerce_mayar_settings', array() );
    if ( isset( $settings['sandbox'] ) && 'yes' === $settings['sandbox'] ) {
        echo '<div class="notice notice-info is-dismissible"><p><strong>Mayar.id Payment Gateway</strong> sedang dalam mode <strong>Sandbox</strong>. Pembayaran tidak akan diproses secara nyata.</p></div>';
    }
}
add_action( 'admin_notices', 'mayar_wc_admin_notice_test_mode' );

/**
 * Register webhook endpoint via REST API
 */
function mayar_wc_register_webhook_endpoint() {
    register_rest_route( 'mayar-wc/v1', '/webhook', array(
        'methods'             => 'POST',
        'callback'            => 'mayar_wc_handle_webhook',
        'permission_callback' => '__return_true', // Public endpoint for webhooks
    ) );
}
add_action( 'rest_api_init', 'mayar_wc_register_webhook_endpoint' );

/**
 * Handle incoming webhook from Mayar.id
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function mayar_wc_handle_webhook( WP_REST_Request $request ) {
    $raw_body = $request->get_body();
    $payload  = json_decode( $raw_body, true );

    if ( empty( $payload ) || empty( $payload['event'] ) ) {
        return new WP_REST_Response( array( 'message' => 'Invalid payload' ), 400 );
    }

    // Log the webhook
    $logger = wc_get_logger();
    $logger->info( 'Mayar webhook received', array( 'source' => 'mayar-wc', 'payload' => $payload ) );

    // Process payment received event
    if ( 'payment.received' === $payload['event'] && ! empty( $payload['data'] ) ) {
        mayar_wc_process_payment_webhook( $payload['data'] );
    }

    return new WP_REST_Response( array( 'message' => 'OK' ), 200 );
}

/**
 * Process payment webhook data and update WooCommerce order
 *
 * @param array $data Webhook payment data.
 */
function mayar_wc_process_payment_webhook( $data ) {
    $logger = wc_get_logger();

    // Get order by transaction ID stored in order meta
    $transaction_id = isset( $data['transactionId'] ) ? $data['transactionId'] : '';
    $payment_id     = isset( $data['id'] ) ? $data['id'] : '';
    $status         = isset( $data['status'] ) ? $data['status'] : '';
    $amount         = isset( $data['amount'] ) ? (int) $data['amount'] : 0;

    if ( empty( $transaction_id ) && empty( $payment_id ) ) {
        $logger->error( 'Mayar webhook: No transaction ID or payment ID found in payload', array( 'source' => 'mayar-wc' ) );
        return;
    }

    // Cari order berdasarkan Mayar payment_id (paling reliable — selalu tersimpan dari process_payment)
    $order = null;
    if ( ! empty( $payment_id ) ) {
        $orders = wc_get_orders( array(
            'meta_key'   => '_mayar_payment_id',
            'meta_value' => $payment_id,
            'limit'      => 1,
            'return'     => 'ids',
        ) );
        $order_id = ! empty( $orders ) ? $orders[0] : 0;
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
        }
    }

    // Fallback: search by mayar_transaction_id
    if ( ! $order && ! empty( $transaction_id ) ) {
        $orders = wc_get_orders( array(
            'meta_key'   => '_mayar_transaction_id',
            'meta_value' => $transaction_id,
            'limit'      => 1,
            'return'     => 'ids',
        ) );
        $order_id = ! empty( $orders ) ? $orders[0] : 0;
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
        }
    }

    if ( ! $order ) {
        $logger->error( sprintf( 'Mayar webhook: Order not found for transaction_id=%s, payment_id=%s', $transaction_id, $payment_id ), array( 'source' => 'mayar-wc' ) );
        return;
    }

    // Check if order is already paid (idempotency)
    if ( $order->is_paid() ) {
        $logger->info( sprintf( 'Mayar webhook: Order #%d already paid, skipping', $order->get_id() ), array( 'source' => 'mayar-wc' ) );
        return;
    }

    // Verify amount matches
    $order_total = (int) $order->get_total();
    if ( $amount > 0 && $amount !== $order_total ) {
        $logger->error( sprintf( 'Mayar webhook: Amount mismatch for order #%d. Expected %d, got %d', $order->get_id(), $order_total, $amount ), array( 'source' => 'mayar-wc' ) );
        $order->add_order_note( sprintf( 'Mayar.id: Pembayaran ditolak. jumlah tidak cocok. Diterima: Rp %s, Seharusnya: Rp %s', number_format( $amount, 0, ',', '.' ), number_format( $order_total, 0, ',', '.' ) ) );
        return;
    }

    // Process based on status
    $transaction_status = isset( $data['transactionStatus'] ) ? $data['transactionStatus'] : '';

    if ( 'SUCCESS' === $status || 'paid' === $transaction_status ) {
        // Payment successful
        $order->payment_complete( $transaction_id );
        $order->add_order_note( sprintf(
            'Mayar.id: Pembayaran berhasil diterima. Transaction ID: %s | Payment ID: %s | Jumlah: Rp %s',
            $transaction_id,
            $payment_id,
            number_format( $amount, 0, ',', '.' )
        ) );

        // Store meta
        $order->update_meta_data( '_mayar_transaction_id', $transaction_id );
        $order->update_meta_data( '_mayar_payment_id', $payment_id );
        $order->update_meta_data( '_mayar_payment_status', 'paid' );
        $order->save();

        // Reduce stock
        wc_reduce_stock_levels( $order->get_id() );

        $logger->info( sprintf( 'Mayar webhook: Payment completed for order #%d', $order->get_id() ), array( 'source' => 'mayar-wc' ) );
    } else {
        // Payment not successful
        $order->add_order_note( sprintf(
            'Mayar.id: Status pembayaran: %s (%s)',
            $status,
            $transaction_status
        ) );
        $order->update_meta_data( '_mayar_payment_status', $transaction_status );
        $order->save();

        $logger->warning( sprintf( 'Mayar webhook: Payment status %s for order #%d', $status, $order->get_id() ), array( 'source' => 'mayar-wc' ) );
    }
}

/**
 * Check payment status via API (manual trigger from admin)
 */
function mayar_wc_check_payment_status( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return false;
    }

    $payment_id = $order->get_meta( '_mayar_payment_id' );
    if ( empty( $payment_id ) ) {
        return false;
    }

    $gateway = new WC_Gateway_Mayar();
    $api     = new Mayar_API( $gateway->get_api_key(), $gateway->is_sandbox() );
    $result  = $api->get_payment( $payment_id );

    if ( is_wp_error( $result ) ) {
        return false;
    }

    if ( isset( $result['data']['status'] ) && 'paid' === $result['data']['transactionStatus'] ) {
        mayar_wc_process_payment_webhook( $result['data'] );
        return true;
    }

    return false;
}

/**
 * Add custom action to order admin for manual status check
 */
function mayar_wc_order_admin_actions( $order ) {
    $payment_method = $order->get_payment_method();
    if ( 'mayar' !== $payment_method ) {
        return;
    }

    $payment_status = $order->get_meta( '_mayar_payment_status' );
    if ( 'paid' !== $payment_status && 'pending' !== $payment_status ) {
        printf(
            '<a class="button" href="%s">%s</a>',
            wp_nonce_url( admin_url( 'admin-post.php?action=mayar_check_payment&order_id=' . $order->get_id() ), 'mayar_check_payment_' . $order->get_id() ),
            __( 'Cek Status Mayar', 'mayar-payment-gateway' )
        );
    }
}
add_action( 'woocommerce_order_actions', 'mayar_wc_order_admin_actions' );

/**
 * Handle manual payment status check
 */
function mayar_wc_admin_post_check_payment() {
    if ( ! isset( $_GET['order_id'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mayar_check_payment_' . intval( $_GET['order_id'] ) ) ) {
        wp_die( 'Security check failed' );
    }

    $order_id = intval( $_GET['order_id'] );
    $result   = mayar_wc_check_payment_status( $order_id );

    if ( $result ) {
        wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit&message=1' ) );
    } else {
        wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit&message=0' ) );
    }
    exit;
}
add_action( 'admin_post_mayar_check_payment', 'mayar_wc_admin_post_check_payment' );
