<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Return every cart item key except the newly added booking.
 *
 * Keeping this selection separate from the WooCommerce mutation makes the
 * replacement rule deterministic and independently testable.
 *
 * @param array  $cart_contents       Current WooCommerce cart contents.
 * @param string $incoming_item_key   Cart item key created for the new booking.
 * @return array
 */
function wk_rh_get_booking_replacement_removal_keys( array $cart_contents, $incoming_item_key ) {
    $incoming_item_key = (string) $incoming_item_key;
    $removal_keys      = [];

    // Fail safe: replacement is only authorized once the incoming line is
    // observably present. An unexpected/malformed hook call must never empty
    // a customer's existing cart.
    if ( $incoming_item_key === '' || ! array_key_exists( $incoming_item_key, $cart_contents ) ) {
        return [];
    }

    foreach ( array_keys( $cart_contents ) as $cart_item_key ) {
        $cart_item_key = (string) $cart_item_key;
        if ( $cart_item_key === $incoming_item_key ) {
            continue;
        }

        $removal_keys[] = $cart_item_key;
    }

    return $removal_keys;
}
