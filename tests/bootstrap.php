<?php
/**
 * PHPUnit bootstrap — Mayar Payment Gateway
 *
 * Digunakan untuk unit test tanpa WordPress (standalone).
 * Untuk integration test dengan WordPress, gunakan WP_Mock atau WP PHPUnit.
 */

// Pastikan constant ABSPATH terdefinisi supaya plugin bisa di-load
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Load plugin main file
require_once dirname( __DIR__ ) . '/mayar-payment-gateway.php';
