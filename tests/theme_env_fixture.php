<?php
/**
 * Minimal WordPress shim for testing theme .env URL resolution without WordPress.
 * This script only tests pure theme helpers and never invokes WordPress/database actions.
 */
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function add_shortcode( ...$args ) {}
function wp_parse_url( $url ) { return parse_url( $url ); }
function esc_url_raw( $url, $protocols = [] ) { return $url; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }

$home = sys_get_temp_dir() . '/dtp-theme-env-' . bin2hex(random_bytes(6));
$wp = $home . '/wp';
mkdir( $wp, 0700, true );
file_put_contents( $home . '/.env',
    "# parent file\nDTP_FRONTEND_URL=https://parent.example.org/front/\nDTP_BAD_URL=javascript:alert(1)\n" );
file_put_contents( $wp . '/.env',
    "DTP_FRONTEND_URL=https://ignored.example.org/\nDTP_MAP_URL=https://map.example.org/\nDTP_CONTACT_EMAIL=office@example.org\n" );
define( 'ABSPATH', $wp . '/' );
putenv( 'DTP_FRONTEND_URL' );
putenv( 'DTP_MAP_URL' );
require dirname( __DIR__ ) . '/theme/dtpstat-child/functions.php';

try {
    $checks = [
        [ 'parent preference', dtp_pages_url(), 'https://parent.example.org/front' ],
        [ 'missing key from WP env', dtp_map_url(), 'https://map.example.org' ],
        [ 'secondary env key', dtp_env( 'DTP_CONTACT_EMAIL' ), 'office@example.org' ],
        [ 'invalid scheme rejected', dtp_external_url( 'DTP_BAD_URL' ), '' ],
        [ 'missing key is empty', dtp_external_url( 'DTP_MISSING_URL' ), '' ],
    ];
    putenv( 'DTP_FRONTEND_URL=https://process.example.org/details/' );
    $checks[] = [ 'process env preference', dtp_pages_url(), 'https://process.example.org/details' ];
    foreach ( $checks as [ $label, $actual, $expected ] ) {
        if ( $actual !== $expected ) {
            fwrite( STDERR, "$label: expected $expected, got $actual\n" );
            exit( 1 );
        }
    }
    echo "theme dotenv tests passed\n";
} finally {
    unlink( $wp . '/.env' );
    unlink( $home . '/.env' );
    rmdir( $wp );
    rmdir( $home );
}
