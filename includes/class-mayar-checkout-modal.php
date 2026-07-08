<?php
/**
 * Mayar Checkout Modal
 *
 * Handles asset enqueuing and AJAX endpoints for the payment modal popup.
 *
 * @package Mayar_With_Vodeco
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mayar_Checkout_Modal {

    /**
     * Logger instance
     *
     * @var WC_Logger|null
     */
    private $logger = null;

    /**
     * Constructor — hook into WordPress
     */
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_mayar_check_payment_status', array( $this, 'ajax_check_payment_status' ) );
        add_action( 'wp_ajax_mayar_cancel_payment', array( $this, 'ajax_cancel_payment' ) );
    }

    /**
     * Get logger instance (cached)
     *
     * @return WC_Logger
     */
    private function get_logger() {
        if ( null === $this->logger ) {
            $this->logger = wc_get_logger();
        }
        return $this->logger;
    }

    /**
     * Get Mayar API instance from saved settings (avoids full gateway instantiation)
     *
     * @return Mayar_API
     */
    private function get_api() {
        $settings = get_option( 'woocommerce_mayar_settings', array() );
        $api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        $sandbox  = isset( $settings['sandbox'] ) && 'yes' === $settings['sandbox'];
        return new Mayar_API( $api_key, $sandbox );
    }

    /**
     * Enqueue JS and CSS only on checkout pages
     */
    public function enqueue_assets() {
        if ( ! is_checkout() ) {
            return;
        }

        // Check if Mayar gateway is enabled and popup mode is active
        $settings = get_option( 'woocommerce_mayar_settings', array() );
        if ( empty( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
            return;
        }
        if ( empty( $settings['enable_popup'] ) || 'yes' !== $settings['enable_popup'] ) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'mayar-checkout-modal',
            MAYAR_WC_PLUGIN_URL . 'assets/css/mayar-checkout-modal.css',
            array(),
            MAYAR_WC_VERSION
        );

        // JS
        wp_enqueue_script(
            'mayar-checkout-modal',
            MAYAR_WC_PLUGIN_URL . 'assets/js/mayar-checkout-modal.js',
            array( 'jquery' ),
            MAYAR_WC_VERSION,
            true
        );

        // Localize data for JS
        wp_localize_script( 'mayar-checkout-modal', 'MayarCheckoutData', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'mayar_checkout_modal_nonce' ),
            'i18n'      => array(
                'title'              => __( 'Pembayaran Mayar.id', 'mayar-payment-gateway' ),
                'waiting'            => __( 'Menunggu pembayaran...', 'mayar-payment-gateway' ),
                'success'            => __( 'Pembayaran Berhasil!', 'mayar-payment-gateway' ),
                'redirecting'        => __( 'Mengalihkan ke halaman pesanan...', 'mayar-payment-gateway' ),
                'failed'             => __( 'Pembayaran Gagal', 'mayar-payment-gateway' ),
                'expired'            => __( 'Pembayaran Kedaluwarsa', 'mayar-payment-gateway' ),
                'timeout'            => __( 'Waktu Habis', 'mayar-payment-gateway' ),
                'iframeError'        => __( 'Gagal memuat halaman pembayaran di dalam popup.', 'mayar-payment-gateway' ),
                'openInNewTab'       => __( 'Buka Halaman Pembayaran', 'mayar-payment-gateway' ),
                'retry'              => __( 'Coba Lagi', 'mayar-payment-gateway' ),
                'closeConfirm'       => __( 'Pembayaran belum selesai. Yakin ingin menutup?', 'mayar-payment-gateway' ),
                'paymentPending'     => __( 'Pembayaran belum selesai. Pesanan Anda masih dalam status pending.', 'mayar-payment-gateway' ),
                'continuePayment'    => __( 'Anda memiliki pembayaran yang belum selesai. Lanjutkan pembayaran?', 'mayar-payment-gateway' ),
                'continuePayNow'     => __( 'Bayar Sekarang', 'mayar-payment-gateway' ),
                'dismiss'            => __( 'Tutup', 'mayar-payment-gateway' ),
            ),
        ) );
    }

    /**
     * AJAX handler: Check payment status for an order
     */
    public function ajax_check_payment_status() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mayar_checkout_modal_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        // Verify order ID
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Invalid order ID' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found' ) );
        }

        // Verify user owns this order
        if ( get_current_user_id() !== $order->get_customer_id() ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        $payment_id     = $order->get_meta( '_mayar_payment_id' );
        $payment_status = $order->get_meta( '_mayar_payment_status' );

        if ( empty( $payment_id ) ) {
            wp_send_json_error( array( 'message' => 'No payment ID found' ) );
        }

        // QUICK CHECK: if webhook already updated order to paid, return immediately
        if ( $order->is_paid() && 'paid' !== $payment_status ) {
            $order->update_meta_data( '_mayar_payment_status', 'paid' );
            $order->save();
            $payment_status = 'paid';
        }
        if ( 'paid' === $payment_status ) {
            wp_send_json_success( $this->get_status_response( $order, 'paid' ) );
            return;
        }

        // Rate limit: check transient before calling Mayar API
        $throttle_key = 'mayar_poll_throttle_' . $order_id;
        if ( get_transient( $throttle_key ) ) {
            // Return cached status from order meta
            wp_send_json_success( $this->get_status_response( $order, $payment_status ) );
            return;
        }

        // Check live status from Mayar API
        $api    = $this->get_api();
        $result = $api->get_payment( $payment_id );

        // Set throttle transient (3 seconds)
        set_transient( $throttle_key, true, 3 );

        $logger = $this->get_logger();

        if ( is_wp_error( $result ) ) {
            // API error — return cached status
            $logger->warning( sprintf( 'Mayar modal poll: API error for order #%d — %s', $order_id, $result->get_error_message() ), array( 'source' => 'mayar-wc' ) );
            wp_send_json_success( $this->get_status_response( $order, $payment_status ) );
            return;
        }

        $logger->info( sprintf( 'Mayar modal poll: API response for order #%d', $order_id ), array(
            'source' => 'mayar-wc',
        ) );

        // Process live status — check multiple possible field names and values
        $data           = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : $result;
        $live_status    = isset( $data['transactionStatus'] ) ? $data['transactionStatus'] : '';
        $live_main      = isset( $data['status'] ) ? $data['status'] : '';
        $live_tx_status = isset( $data['transaction_status'] ) ? $data['transaction_status'] : '';

        // Normalize: check all possible "paid/success" indicators
        $is_paid = in_array( strtolower( $live_status ), array( 'paid', 'settlement', 'capture', 'completed', 'success' ), true )
                || in_array( strtolower( $live_main ), array( 'paid', 'success', 'settlement', 'capture', 'completed' ), true )
                || in_array( strtolower( $live_tx_status ), array( 'paid', 'settlement', 'capture', 'completed', 'success' ), true );

        $is_expired = in_array( strtolower( $live_main ), array( 'expired', 'closed', 'cancel', 'cancelled' ), true )
                   || in_array( strtolower( $live_status ), array( 'expired', 'closed', 'cancel', 'cancelled' ), true );

        if ( $is_paid ) {
            // Payment completed — update order directly
            if ( 'paid' !== $payment_status && ! $order->is_paid() ) {
                $logger->info( sprintf( 'Mayar modal poll: Payment completed for order #%d (status=%s, main=%s)', $order_id, $live_status, $live_main ), array( 'source' => 'mayar-wc' ) );

                $transaction_id = isset( $data['transactionId'] ) ? $data['transactionId'] : '';

                // Complete the order (sets status to processing, reduces stock)
                $order->payment_complete( $transaction_id );
                $order->add_order_note( sprintf(
                    'Mayar.id: Pembayaran berhasil diterima via polling. Payment ID: %s | Status: %s',
                    $payment_id,
                    $live_main
                ) );

                // Update meta
                $order->update_meta_data( '_mayar_payment_status', 'paid' );
                if ( ! empty( $transaction_id ) ) {
                    $order->update_meta_data( '_mayar_transaction_id', $transaction_id );
                }
                $order->save();

                $payment_status = 'paid';
            } elseif ( 'paid' !== $payment_status ) {
                // Order already paid (is_paid() true) but meta not updated
                $order->update_meta_data( '_mayar_payment_status', 'paid' );
                $order->save();
                $payment_status = 'paid';
            }
        } elseif ( $is_expired ) {
            // Payment expired/cancelled
            if ( 'expired' !== $payment_status && 'closed' !== $payment_status ) {
                $order->update_meta_data( '_mayar_payment_status', 'expired' );
                $order->add_order_note( sprintf( 'Mayar.id: Pembayaran kedaluwarsa/dibatalkan (status: %s)', $live_main ) );
                $order->save();
                $payment_status = 'expired';
            }
        } elseif ( ! empty( $live_status ) && $live_status !== $payment_status ) {
            // Status changed (but not paid/expired)
            $order->update_meta_data( '_mayar_payment_status', $live_status );
            $order->save();
            $payment_status = $live_status;
        }

        wp_send_json_success( $this->get_status_response( $order, $payment_status ) );
    }

    /**
     * Build status response array
     *
     * @param WC_Order $order          Order object.
     * @param string   $payment_status Current payment status.
     * @return array Status response data.
     */
    private function get_status_response( $order, $payment_status ) {
        $status_labels = array(
            'pending'  => __( 'Menunggu Pembayaran', 'mayar-payment-gateway' ),
            'paid'     => __( 'Pembayaran Berhasil', 'mayar-payment-gateway' ),
            'expired'  => __( 'Pembayaran Kedaluwarsa', 'mayar-payment-gateway' ),
            'closed'   => __( 'Pembayaran Dibatalkan', 'mayar-payment-gateway' ),
            'failed'   => __( 'Pembayaran Gagal', 'mayar-payment-gateway' ),
        );

        // Get thank-you page URL with fallback
        $thankyou_url = $order->get_checkout_order_received_url();
        if ( empty( $thankyou_url ) ) {
            // Manual fallback: use WC core URL pattern
            $thankyou_url = wc_get_checkout_order_received_url( $order->get_id(), $order->get_order_key() );
        }
        if ( empty( $thankyou_url ) ) {
            // Last resort: go to orders page
            $thankyou_url = home_url( '/dashboard/orders/' . $order->get_id() . '/' );
        }

        return array(
            'status'       => $payment_status,
            'status_label' => isset( $status_labels[ $payment_status ] ) ? $status_labels[ $payment_status ] : $payment_status,
            'order_url'    => $thankyou_url,
            'payment_id'   => $order->get_meta( '_mayar_payment_id' ),
        );
    }

    /**
     * AJAX handler: User cancelled/closed the payment modal
     */
    public function ajax_cancel_payment() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mayar_checkout_modal_nonce' ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( array( 'message' => 'Invalid order ID' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found' ) );
        }

        $this->get_logger()->info( sprintf( 'Mayar modal: User closed payment modal for order #%d', $order_id ), array( 'source' => 'mayar-wc' ) );

        $order->add_order_note( 'Mayar.id: User menutup halaman pembayaran sebelum selesai.' );

        wp_send_json_success( array( 'message' => 'OK' ) );
    }
}
