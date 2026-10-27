<?php
/**
 * Behaviour regression: Neve's real 'neve-style' handle is removed after
 * the parent's priority-10 enqueue while the child CSS stays enqueued.
 * Runs without WordPress or filesystem writes.
 */

$actions = [];
$registered_styles = [
    'neve-style' => true,
    'nv-css-vars' => true,
];
$enqueued_styles = [
    'neve-style' => true,
    'nv-css-vars' => true,
];

function add_action( $hook, $callback, $priority = 10 ) {
    global $actions;
    $actions[ $hook ][ $priority ][] = $callback;
}
function add_filter( ...$args ) {}
function add_shortcode( ...$args ) {}
function get_stylesheet_directory_uri() {
    return 'https://example.test/wp-content/themes/dtpstat-child';
}
function wp_enqueue_script( ...$args ) {}
function wp_enqueue_style( $handle, ...$args ) {
    global $registered_styles, $enqueued_styles;
    $registered_styles[ $handle ] = true;
    $enqueued_styles[ $handle ] = true;
}
function wp_dequeue_style( $handle ) {
    global $enqueued_styles;
    unset( $enqueued_styles[ $handle ] );
}
function wp_deregister_style( $handle ) {
    global $registered_styles;
    unset( $registered_styles[ $handle ] );
}
function is_page( ...$args ) { return false; }
function is_home() { return false; }
function is_search() { return false; }

require dirname( __DIR__ ) . '/theme/dtpstat-child/functions.php';

if ( ! isset( $actions['wp_enqueue_scripts'][20][0] ) ) {
    fwrite( STDERR, "Expected child styles callback at priority 20\n" );
    exit( 1 );
}

// Simulate Neve having queued its CSS at priority 10 before our callback.
$actions['wp_enqueue_scripts'][20][0]();

if ( isset( $enqueued_styles['neve-style'] ) || isset( $registered_styles['neve-style'] ) ) {
    fwrite( STDERR, "Neve CSS must be dequeued and deregistered\n" );
    exit( 1 );
}
foreach ( [ 'dtpstat-fonts', 'dtpstat-main', 'dtpstat-child', 'nv-css-vars' ] as $handle ) {
    if ( ! isset( $enqueued_styles[ $handle ] ) ) {
        fwrite( STDERR, "Unexpectedly dequeued CSS: $handle\n" );
        exit( 1 );
    }
}
echo "parent Neve CSS dequeue tests passed\n";
