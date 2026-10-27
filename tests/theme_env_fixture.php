<?php
/**
 * Test how theme imports a pre-generated WP_CONTENT_DIR/dtpstat-config.php.
 * Does not bootstrap WordPress and does not write to the website.
 */
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function add_shortcode( ...$args ) {}
function wp_parse_url( $url ) { return parse_url( $url ); }
function esc_url_raw( $url, $protocols = [] ) { return $url; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }

$config_file = $argv[1] ?? '';
if ( ! is_file( $config_file ) ) {
    fwrite( STDERR, "Expected existing generated PHP config as first argument\n" );
    exit( 2 );
}
define( 'ABSPATH', dirname( $config_file ) . '/' );
define( 'WP_CONTENT_DIR', dirname( $config_file ) );
putenv( 'DTP_FRONTEND_URL=https://runtime-ignored.example.org' );
require dirname( __DIR__ ) . '/theme/dtpstat-child/functions.php';

$checks = [
    [ 'generated map', dtp_map_url(), 'https://map.example.org' ],
    [ 'generated details', dtp_pages_url(), 'https://details.example.org' ],
    [ 'email constant', dtp_config( 'DTP_CONTACT_EMAIL' ), 'team@example.org' ],
    [ 'missing key', dtp_config( 'NONEXISTENT_KEY' ), '' ],
];
foreach ( $checks as [ $label, $actual, $expected ] ) {
    if ( $actual !== $expected ) {
        fwrite( STDERR, "$label: expected $expected, got $actual\n" );
        exit( 1 );
    }
}
echo "theme compiled PHP config tests passed\n";
