<?php

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/includes/cart-replacement.php';

function assert_cart_replacement_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
        exit( 1 );
    }
}

$cart = [
    'old-main'  => [ 'product_id' => 10 ],
    'old-addon' => [ 'product_id' => 20, 'is_addon' => true ],
    'unrelated' => [ 'product_id' => 30 ],
    'incoming'  => [ 'product_id' => 40 ],
];

assert_cart_replacement_same(
    [ 'old-main', 'old-addon', 'unrelated' ],
    wk_rh_get_booking_replacement_removal_keys( $cart, 'incoming' ),
    'Successful replacement preserves only the incoming booking'
);

assert_cart_replacement_same(
    [],
    wk_rh_get_booking_replacement_removal_keys( [ 'incoming' => [ 'product_id' => 40 ] ], 'incoming' ),
    'First booking has nothing to replace'
);

assert_cart_replacement_same(
    [],
    wk_rh_get_booking_replacement_removal_keys( [ 'old-main' => [ 'product_id' => 10 ] ], 'incoming' ),
    'Missing incoming key leaves the old cart untouched'
);

assert_cart_replacement_same(
    [],
    wk_rh_get_booking_replacement_removal_keys( [ 'old-main' => [ 'product_id' => 10 ] ], '' ),
    'Empty incoming key leaves the old cart untouched'
);

$plugin_source = file_get_contents( dirname( __DIR__ ) . '/wk-racehall-bmi-booking.php' );
assert_cart_replacement_same(
    1,
    preg_match( "/add_action\(\s*'woocommerce_add_to_cart'\s*,\s*'wk_rh_replace_previous_cart_after_main_booking_added'/", $plugin_source ),
    'Replacement runs only after WooCommerce has inserted the incoming cart item'
);
assert_cart_replacement_same(
    0,
    preg_match( "/add_filter\(\s*'woocommerce_add_to_cart_validation'\s*,\s*'wk_rh_replace_previous_cart/", $plugin_source ),
    'Validation phase never performs the destructive replacement'
);

$direct_source       = file_get_contents( dirname( __DIR__ ) . '/includes/direct-booking-link.php' );
$direct_capture_pos  = strpos( $direct_source, '$previous_session = wk_rh_capture_direct_booking_session();' );
$direct_validate_pos = strpos( $direct_source, '$passed_validation = apply_filters(' );
$direct_add_pos      = strpos( $direct_source, '$cart_item_key = WC()->cart->add_to_cart(' );
assert_cart_replacement_same(
    true,
    is_int( $direct_capture_pos ) && is_int( $direct_validate_pos ) && is_int( $direct_add_pos ) && $direct_capture_pos < $direct_validate_pos && $direct_validate_pos < $direct_add_pos,
    'Direct booking snapshots state and validates before attempting cart insertion'
);

fwrite( STDOUT, "cart-replacement-test: OK\n" );
