<?php
/**
 * Test — Version Constants
 *
 * Memastikan version constant cocok dengan header plugin.
 */

class TestVersionConstants extends PHPUnit\Framework\TestCase {

    public function test_version_constant_is_defined(): void {
        $this->assertTrue( defined( 'MAYAR_WC_VERSION' ) );
    }

    public function test_version_constant_matches_header(): void {
        $plugin_file = dirname( __DIR__ ) . '/mayar-payment-gateway.php';
        $content     = file_get_contents( $plugin_file );

        preg_match( '/Version:\s*([\d.]+)/', $content, $m );
        $header_version = $m[1] ?? '';

        $this->assertNotEmpty( $header_version, 'Version header not found' );
        $this->assertEquals( $header_version, MAYAR_WC_VERSION );
    }
}
