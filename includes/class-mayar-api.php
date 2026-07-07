<?php
/**
 * Mayar.id API Client
 *
 * Handles all API communication with Mayar.id payment gateway.
 *
 * @package Mayar_With_Vodeco
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mayar_API {

    /**
     * Production API base URL
     *
     * @var string
     */
    const PRODUCTION_BASE_URL = 'https://api.mayar.id/hl/v2';

    /**
     * Sandbox API base URL
     *
     * @var string
     */
    const SANDBOX_BASE_URL = 'https://api.mayar.club/hl/v2';

    /**
     * API Key for authentication
     *
     * @var string
     */
    private $api_key;

    /**
     * Whether using sandbox environment
     *
     * @var bool
     */
    private $sandbox;

    /**
     * Constructor
     *
     * @param string $api_key Mayar.id API key.
     * @param bool   $sandbox Whether to use sandbox environment.
     */
    public function __construct( $api_key = '', $sandbox = false ) {
        $this->api_key = $api_key;
        $this->sandbox = (bool) $sandbox;
    }

    /**
     * Get the base URL for API requests
     *
     * @return string
     */
    private function get_base_url() {
        return $this->sandbox ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
    }

    /**
     * Get default headers for API requests
     *
     * @return array
     */
    private function get_headers() {
        return array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );
    }

    /**
     * Make a GET request to the API
     *
     * @param string $endpoint API endpoint path.
     * @param array  $args     Additional request arguments.
     * @return array|WP_Error Response data or error.
     */
    private function get( $endpoint, $args = array() ) {
        $url = $this->get_base_url() . $endpoint;

        $response = wp_remote_get( $url, array(
            'headers' => $this->get_headers(),
            'timeout' => 30,
        ) );

        return $this->handle_response( $response );
    }

    /**
     * Make a POST request to the API
     *
     * @param string $endpoint API endpoint path.
     * @param array  $data     Request body data.
     * @return array|WP_Error Response data or error.
     */
    private function post( $endpoint, $data = array() ) {
        $url = $this->get_base_url() . $endpoint;

        $response = wp_remote_post( $url, array(
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode( $data ),
            'timeout' => 30,
        ) );

        return $this->handle_response( $response );
    }

    /**
     * Handle API response
     *
     * @param mixed $response Raw response from wp_remote_get/post.
     * @return array|WP_Error Parsed response data or error.
     */
    private function handle_response( $response ) {
        if ( is_wp_error( $response ) ) {
            $logger = wc_get_logger();
            $logger->error( 'Mayar API Error: ' . $response->get_error_message(), array( 'source' => 'mayar-wc' ) );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $logger = wc_get_logger();
            $logger->error( 'Mayar API: Invalid JSON response', array( 'source' => 'mayar-wc', 'body' => $body ) );
            return new WP_Error( 'mayar_invalid_json', 'Invalid JSON response from Mayar API' );
        }

        // Check for API errors
        if ( $status_code >= 400 ) {
            $error_message = isset( $data['message'] ) ? $data['message'] : 'Unknown API error';
            $logger        = wc_get_logger();
            $logger->error( sprintf( 'Mayar API Error (HTTP %d): %s', $status_code, $error_message ), array( 'source' => 'mayar-wc', 'response' => $data ) );
            return new WP_Error( 'mayar_api_error', $error_message, array( 'status_code' => $status_code, 'response' => $data ) );
        }

        return $data;
    }

    /**
     * Create a payment request
     *
     * @param array $params Payment parameters.
     *   @type string $name        Payment title/name.
     *   @type int    $amount      Payment amount in IDR.
     *   @type string $email       Customer email.
     *   @type string $mobile      Customer phone number.
     *   @type string $description Payment description.
     *   @type string $expiredAt   Expiration datetime (ISO 8601).
     *   @type array  $extraData   Custom data to attach.
     * @return array|WP_Error Response with payment data or error.
     */
    public function create_payment( $params ) {
        $data = array(
            'name'        => isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '',
            'amount'      => isset( $params['amount'] ) ? absint( $params['amount'] ) : 0,
            'email'       => isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
            'mobile'      => isset( $params['mobile'] ) ? sanitize_text_field( $params['mobile'] ) : '',
            'description' => isset( $params['description'] ) ? sanitize_text_field( $params['description'] ) : '',
        );

        // Optional fields
        if ( ! empty( $params['expiredAt'] ) ) {
            $data['expiredAt'] = sanitize_text_field( $params['expiredAt'] );
        }

        if ( ! empty( $params['extraData'] ) && is_array( $params['extraData'] ) ) {
            $data['extraData'] = $params['extraData'];
        }

        $logger = wc_get_logger();
        $logger->info( 'Mayar API: Creating payment request', array( 'source' => 'mayar-wc', 'data' => $data ) );

        return $this->post( '/payments/create', $data );
    }

    /**
     * Get payment request details
     *
     * @param string $payment_id Payment ID (UUID).
     * @return array|WP_Error Response with payment data or error.
     */
    public function get_payment( $payment_id ) {
        return $this->get( '/payments/' . sanitize_text_field( $payment_id ) );
    }

    /**
     * Register webhook URL with Mayar
     *
     * @param string $webhook_url The webhook URL to register.
     * @return array|WP_Error Response or error.
     */
    public function register_webhook( $webhook_url ) {
        return $this->post( '/webhooks/update', array(
            'urlHook' => esc_url_raw( $webhook_url ),
        ) );
    }

    /**
     * Test webhook URL
     *
     * @param string $webhook_url The webhook URL to test.
     * @return array|WP_Error Response or error.
     */
    public function test_webhook( $webhook_url ) {
        return $this->post( '/webhooks/test', array(
            'urlHook' => esc_url_raw( $webhook_url ),
        ) );
    }

    /**
     * Get payment channels available
     *
     * @return array|WP_Error Response or error.
     */
    public function get_payment_channels() {
        return $this->get( '/payment-channels' );
    }

    /**
     * Get account balance
     *
     * @return array|WP_Error Response or error.
     */
    public function get_balance() {
        return $this->get( '/balance' );
    }
}
