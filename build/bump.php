<?php
/**
 * Bump Version Script — Mayar Payment Gateway
 *
 * Otomatis update versi di:
 *   - mayar-payment-gateway.php (header Version + constant MAYAR_WC_VERSION)
 *
 * Usage: composer bump              # patch (1.0.0 → 1.0.1)
 *        composer bump patch        # 1.0.0 → 1.0.1
 *        composer bump minor        # 1.0.0 → 1.1.0
 *        composer bump major        # 1.0.0 → 2.0.0
 */

$which = $argv[1] ?? 'patch';
$valid = [ 'patch', 'minor', 'major' ];

if ( ! in_array( $which, $valid, true ) ) {
    echo "Usage: composer bump <patch|minor|major>\n";
    exit( 1 );
}

$plugin_dir = dirname( __DIR__ );
$main_file  = $plugin_dir . '/mayar-payment-gateway.php';
$content    = file_get_contents( $main_file );

// ── Baca versi dari header ──────────────────────────────────────────────────────
preg_match( '/Version:\s*([\d.]+)/', $content, $m );
if ( ! $m ) {
    echo "Gagal membaca version dari header plugin.\n";
    exit( 1 );
}

$old_version = $m[1];
$parts       = explode( '.', $old_version );
$major       = (int) ( $parts[0] ?? 1 );
$minor       = (int) ( $parts[1] ?? 0 );
$patch       = (int) ( $parts[2] ?? 0 );

switch ( $which ) {
    case 'major':
        $major++;
        $minor = 0;
        $patch = 0;
        break;
    case 'minor':
        $minor++;
        $patch = 0;
        break;
    case 'patch':
    default:
        $patch++;
        break;
}

$new_version = "{$major}.{$minor}.{$patch}";
echo "Bumping version: {$old_version} -> {$new_version} ({$which})\n";

// ── Update header Version: ──────────────────────────────────────────────────────
$content = preg_replace(
    '/(Version:\s*)[\d.]+\s*$/m',
    '${1}' . $new_version,
    $content
);

// ── Update constant MAYAR_WC_VERSION ────────────────────────────────────────────
$content = preg_replace(
    "/(define\s*\(\s*'MAYAR_WC_VERSION'\s*,\s*')\d+\.\d+\.\d+('\s*\))/",
    '${1}' . $new_version . '${2}',
    $content
);

file_put_contents( $main_file, $content );
echo "   mayar-payment-gateway.php\n";

echo "Done! Version bumped to {$new_version}\n";
