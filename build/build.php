<?php
/**
 * Build Script — Mayar Payment Gateway
 *
 * Generates production-ready zip file untuk deploy ke WordPress.
 * Usage: composer build-zip
 */

$start       = microtime( true );
$plugin_dir  = dirname( __DIR__ );
$plugin_name = 'mayar-payment-gateway';
$slug        = 'mayar-payment-gateway';

// ── Nama output ────────────────────────────────────────────────────────────────
$output_dir = sys_get_temp_dir() . "/{$slug}";

// ── Bersihkan dulu ─────────────────────────────────────────────────────────────
if ( is_dir( $output_dir ) ) {
    echo "Cleaning temp dir...\n";
    shell_exec( "rm -rf " . escapeshellarg( $output_dir ) );
}

// ── Daftar file/dir yg DIKECUALIKAN dari build production ──────────────────────
$exclude_patterns = [
    '#(^|/)\.git($|/)#',
    '#(^|/)\.gitignore$#',
    '#(^|/)build($|/)#',
    '#(^|/)node_modules($|/)#',
    '#(^|/)tests($|/)#',
    '#(^|/)\.DS_Store$#',
    '#(^|/)Thumbs\.db$#',
    '#\.log$#',
    '#\.map$#',
    '#composer\.json$#',
    '#composer\.lock$#',
    '#phpunit\.xml$#',
    '#phpunit\.xml\.dist$#',
];

function is_excluded( string $relative_path, array $patterns ): bool {
    foreach ( $patterns as $pattern ) {
        if ( preg_match( $pattern, $relative_path ) ) {
            return true;
        }
    }
    return false;
}

// ── Salin file ke temp directory ───────────────────────────────────────────────
echo "Copying files...\n";

$rdi = new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS );
$rii = new RecursiveIteratorIterator( $rdi, RecursiveIteratorIterator::LEAVES_ONLY );

$copied   = 0;
$excluded = 0;

foreach ( $rii as $file ) {
    $relative = str_replace( $plugin_dir . '/', '', $file->getPathname() );

    if ( is_excluded( $relative, $exclude_patterns ) ) {
        $excluded++;
        continue;
    }

    $target     = $output_dir . '/' . $relative;
    $target_dir = dirname( $target );

    if ( ! is_dir( $target_dir ) ) {
        mkdir( $target_dir, 0755, true );
    }

    copy( $file->getPathname(), $target );
    $copied++;
}

echo "   {$copied} files copied, {$excluded} excluded\n";

// ── Buat zip ───────────────────────────────────────────────────────────────────
$zip_path = $plugin_dir . "/{$slug}.zip";

if ( file_exists( $zip_path ) ) {
    echo "Removing existing zip...\n";
    unlink( $zip_path );
}

echo "Creating zip: {$zip_path}\n";

$zip = new ZipArchive();
if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
    echo "Gagal membuat zip.\n";
    exit( 1 );
}

$zip_rdi = new RecursiveDirectoryIterator( $output_dir, RecursiveDirectoryIterator::SKIP_DOTS );
$zip_rii = new RecursiveIteratorIterator( $zip_rdi, RecursiveIteratorIterator::LEAVES_ONLY );

$zip_count = 0;
foreach ( $zip_rii as $file ) {
    $relative = str_replace( $output_dir . '/', '', $file->getPathname() );
    $zip->addFile( $file->getPathname(), "{$slug}/{$relative}" );
    $zip_count++;
}

$zip->close();

// ── Bersihkan temp ─────────────────────────────────────────────────────────────
shell_exec( "rm -rf " . escapeshellarg( $output_dir ) );

// ── Selesai ────────────────────────────────────────────────────────────────────
$elapsed = round( microtime( true ) - $start, 2 );
$size_mb = round( filesize( $zip_path ) / 1024 / 1024, 2 );

echo "\n--- Build selesai! ---\n";
echo " File: {$slug}.zip\n";
echo " Size: {$size_mb} MB\n";
echo " {$zip_count} files\n";
echo " {$elapsed}s\n";
