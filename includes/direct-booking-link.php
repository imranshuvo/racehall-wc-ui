<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wk_rh_get_direct_booking_request_value( array $keys ) {
    foreach ( $keys as $key ) {
        if ( isset( $_GET[ $key ] ) ) {
            return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
        }
    }

    return '';
}

function wk_rh_is_direct_booking_request() {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
        return false;
    }

    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }

    $flag = wk_rh_get_direct_booking_request_value( [ 'rh_booking', 'racehall_booking' ] );
    if ( $flag === '' ) {
        return false;
    }

    return ! in_array( strtolower( $flag ), [ '0', 'false', 'no' ], true );
}

function wk_rh_normalize_direct_booking_compare_value( $value ) {
    $value = remove_accents( sanitize_text_field( (string) $value ) );
    $value = strtolower( $value );
    $value = preg_replace( '/[^a-z0-9]+/', '', $value );

    return is_string( $value ) ? $value : '';
}

function wk_rh_get_direct_booking_product_location( $product_id ) {
    $location = function_exists( 'wk_rh_get_product_booking_location' )
        ? wk_rh_get_product_booking_location( $product_id )
        : '';

    return is_string( $location ) ? sanitize_text_field( $location ) : '';
}

function wk_rh_get_direct_booking_bm_product_id( $product_id ) {
    $bm_product_id = function_exists( 'wk_rh_get_product_bmileisure_id' )
        ? wk_rh_get_product_bmileisure_id( $product_id )
        : '';

    return is_scalar( $bm_product_id ) ? sanitize_text_field( (string) $bm_product_id ) : '';
}

function wk_rh_resolve_direct_booking_product() {
    $product_ref = wk_rh_get_direct_booking_request_value( [ 'product_id', 'product', 'product_slug', 'rh_product' ] );
    $bm_ref      = wk_rh_get_direct_booking_request_value( [ 'bm_product_id', 'bmileisure_id', 'rh_bm_product_id' ] );
    $product_id  = 0;

    if ( $product_ref !== '' && ctype_digit( $product_ref ) ) {
        $product_id = (int) $product_ref;
    } elseif ( $product_ref !== '' ) {
        $product_post = get_page_by_path( sanitize_title( $product_ref ), OBJECT, 'product' );
        if ( $product_post instanceof WP_Post ) {
            $product_id = (int) $product_post->ID;
        }
    }

    if ( $product_id <= 0 && $bm_ref !== '' ) {
        $posts = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => 'bmileisure_id',
                    'value' => $bm_ref,
                ],
            ],
        ] );

        if ( ! empty( $posts[0] ) ) {
            $product_id = (int) $posts[0];
            if ( function_exists( 'wk_rh_get_current_language_product_id' ) ) {
                $product_id = wk_rh_get_current_language_product_id( $product_id );
            }
        }
    }

    if ( $product_id <= 0 ) {
        return new WP_Error( 'missing_product', __( 'Bookinglink mangler et gyldigt produkt.', 'racehall-wc-ui' ) );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product instanceof WC_Product ) {
        return new WP_Error( 'invalid_product', __( 'Bookingproduktet kunne ikke findes.', 'racehall-wc-ui' ) );
    }

    $bm_product_id = wk_rh_get_direct_booking_bm_product_id( $product_id );
    if ( $bm_product_id === '' ) {
        return new WP_Error( 'missing_bm_product', __( 'Produktet mangler BMI-bookingopsætning.', 'racehall-wc-ui' ) );
    }

    return [
        'product'      => $product,
        'productId'    => $product_id,
        'bmProductId'  => $bm_product_id,
        'location'     => wk_rh_get_direct_booking_product_location( $product_id ),
        'redirectUrl'  => get_permalink( $product_id ),
    ];
}

function wk_rh_normalize_direct_booking_date( $value ) {
    $value = trim( (string) $value );
    if ( $value === '' ) {
        return '';
    }

    if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
        return '';
    }

    $year = (int) $matches[1];
    $month = (int) $matches[2];
    $day = (int) $matches[3];
    if ( ! checkdate( $month, $day, $year ) ) {
        return '';
    }

    return sprintf( '%04d-%02d-%02d', $year, $month, $day );
}

function wk_rh_normalize_direct_booking_time( $value ) {
    $value = trim( (string) $value );
    if ( $value === '' ) {
        return '';
    }

    if ( preg_match( '/^(\d{2}):(\d{2})(?::\d{2})?$/', $value, $matches ) ) {
        return $matches[1] . ':' . $matches[2];
    }

    return '';
}

function wk_rh_get_direct_booking_participants() {
    $adults = (int) wk_rh_get_direct_booking_request_value( [ 'adults', 'adult', 'booking_adults', 'rh_adults' ] );
    $children = (int) wk_rh_get_direct_booking_request_value( [ 'children', 'kids', 'booking_children', 'rh_children' ] );
    $twin = (int) wk_rh_get_direct_booking_request_value( [ 'twin', 'booking_twin', 'rh_twin' ] );
    $quantity = (int) wk_rh_get_direct_booking_request_value( [ 'quantity', 'booking_quantity', 'rh_quantity' ] );

    $adults = max( 0, $adults );
    $children = max( 0, $children );
    $twin = max( 0, $twin );
    $quantity = max( 0, $quantity );

    $participant_total = $adults + $children + $twin;
    if ( $participant_total <= 0 && $quantity <= 0 ) {
        $adults = 1;
        $participant_total = 1;
    } elseif ( $participant_total <= 0 ) {
        $adults = $quantity;
        $participant_total = $quantity;
    } elseif ( $quantity > 0 && $quantity !== $participant_total ) {
        return new WP_Error( 'participant_mismatch', __( 'Bookinglinkens deltagerantal matcher ikke quantity.', 'racehall-wc-ui' ) );
    }

    return [
        'adults'   => $adults,
        'children' => $children,
        'twin'     => $twin,
        'quantity' => max( 1, $participant_total ),
    ];
}

function wk_rh_get_direct_booking_page_context( $token, $bm_product_id, $booking_date, $booking_location ) {
    $creds = wk_rh_get_api_credentials( $booking_location );
    if ( empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return new WP_Error( 'missing_api_credentials', __( 'Bookingopsætningen for lokationen er ikke komplet.', 'racehall-wc-ui' ) );
    }

    $pages_url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'page', [ 'date' => $booking_date . 'T00:00:00.000Z' ] )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/page?date=' . rawurlencode( $booking_date . 'T00:00:00.000Z' );
    $response = wk_rh_remote_request_with_retry(
        'GET',
        $pages_url,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Bmi-Subscription-Key' => $creds['subscription_key'],
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
            ],
            'timeout' => 15,
        ],
        1,
        [
            'operation' => 'direct_booking_page_get',
            'location'  => (string) $booking_location,
            'productId' => (string) $bm_product_id,
            'date'      => (string) $booking_date,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'page_lookup_failed', __( 'Kunne ikke hente ledige bookingpages.', 'racehall-wc-ui' ) );
    }

    $pages = function_exists( 'wk_rh_decode_api_response_body' ) ? wk_rh_decode_api_response_body( $response ) : json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $pages ) ) {
        return new WP_Error( 'invalid_page_response', __( 'Bookingdata fra upstream kunne ikke læses.', 'racehall-wc-ui' ) );
    }

    foreach ( $pages as $page ) {
        if ( empty( $page['products'] ) || ! is_array( $page['products'] ) ) {
            continue;
        }

        foreach ( $page['products'] as $page_product ) {
            if ( ! isset( $page_product['id'] ) || (string) $page_product['id'] !== (string) $bm_product_id ) {
                continue;
            }

            return [
                'pageId'            => isset( $page['id'] ) ? (string) $page['id'] : '',
                'pageProductLimits' => [
                    'minAmount' => $page_product['minAmount'] ?? null,
                    'maxAmount' => $page_product['maxAmount'] ?? null,
                ],
                'pageProducts'      => array_values( $page['products'] ),
            ];
        }
    }

    return new WP_Error( 'page_not_found', __( 'Ingen bookingpage matcher produktet og datoen.', 'racehall-wc-ui' ) );
}

function wk_rh_get_direct_booking_proposal_display_time( $proposal ) {
    $blocks = isset( $proposal['blocks'] ) && is_array( $proposal['blocks'] ) ? $proposal['blocks'] : [];
    $first_block = ! empty( $blocks ) ? $blocks[0] : null;
    $slot = is_array( $first_block ) && ! empty( $first_block['block'] ) && is_array( $first_block['block'] ) ? $first_block['block'] : null;

    if ( empty( $slot['start'] ) ) {
        return '';
    }

    return substr( (string) $slot['start'], 11, 5 );
}

function wk_rh_get_direct_booking_proposal_resource_id( $proposal ) {
    $blocks = isset( $proposal['blocks'] ) && is_array( $proposal['blocks'] ) ? $proposal['blocks'] : [];
    $first_block = ! empty( $blocks ) ? $blocks[0] : null;
    if ( ! is_array( $first_block ) ) {
        return '';
    }

    if ( ! empty( $first_block['block'] ) && is_array( $first_block['block'] ) && ! empty( $first_block['block']['resourceId'] ) ) {
        return sanitize_text_field( (string) $first_block['block']['resourceId'] );
    }

    if ( ! empty( $first_block['productLineIds'] ) && is_array( $first_block['productLineIds'] ) && ! empty( $first_block['productLineIds'][0] ) ) {
        return sanitize_text_field( (string) $first_block['productLineIds'][0] );
    }

    return '';
}

function wk_rh_find_direct_booking_proposal( array $timeslot_response, $requested_time, $fallback_page_id = '' ) {
    $proposals = isset( $timeslot_response['proposals'] ) && is_array( $timeslot_response['proposals'] ) ? $timeslot_response['proposals'] : [];

    foreach ( $proposals as $proposal ) {
        if ( ! is_array( $proposal ) ) {
            continue;
        }

        if ( wk_rh_get_direct_booking_proposal_display_time( $proposal ) !== $requested_time ) {
            continue;
        }

        $resource_id = wk_rh_get_direct_booking_proposal_resource_id( $proposal );
        if ( $resource_id === '' ) {
            continue;
        }

        return [
            'proposal'   => $proposal,
            'resourceId' => $resource_id,
            'pageId'     => ! empty( $timeslot_response['pageId'] ) ? (string) $timeslot_response['pageId'] : (string) $fallback_page_id,
        ];
    }

    return new WP_Error( 'proposal_not_found', __( 'Det valgte tidspunkt er ikke ledigt længere.', 'racehall-wc-ui' ) );
}

function wk_rh_set_direct_booking_request_payload( array $context ) {
    $_POST['booking_date'] = $context['bookingDate'];
    $_POST['booking_time'] = $context['bookingTime'];
    $_POST['booking_location'] = $context['bookingLocation'];
    $_POST['booking_adults'] = (string) $context['participants']['adults'];
    $_POST['booking_children'] = (string) $context['participants']['children'];
    $_POST['booking_twin'] = (string) $context['participants']['twin'];
    $_POST['booking_quantity'] = (string) $context['participants']['quantity'];
    $_POST['booking_proposal'] = wp_json_encode( $context['proposal'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $_POST['booking_page_id'] = (string) $context['pageId'];
    $_POST['booking_resource_id'] = (string) $context['resourceId'];
    $_POST['booking_product_id'] = (string) $context['bmProductId'];
    $_POST['booking_page_product_limits'] = wp_json_encode( $context['pageProductLimits'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $_POST['booking_page_products'] = wp_json_encode( $context['pageProducts'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    $_POST['add-to-cart'] = (string) $context['productId'];
    $_POST['quantity'] = (string) $context['participants']['quantity'];

    foreach ( $_POST as $key => $value ) {
        $_REQUEST[ $key ] = $value;
    }
}

function wk_rh_store_direct_booking_session( array $context ) {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }

    WC()->session->set( 'rh_bmi_booking', [
        'proposal'          => $context['proposal'],
        'pageId'            => (string) $context['pageId'],
        'resourceId'        => (string) $context['resourceId'],
        'productId'         => (string) $context['bmProductId'],
        'quantity'          => (int) $context['participants']['quantity'],
        'pageProductLimits' => is_array( $context['pageProductLimits'] ) ? $context['pageProductLimits'] : null,
        'pageProducts'      => is_array( $context['pageProducts'] ) ? array_values( $context['pageProducts'] ) : [],
        'bookingLocation'   => (string) $context['bookingLocation'],
        'orderId'           => '',
        'orderItemId'       => '',
        'expiresAt'         => '',
    ] );

    WC()->session->set( 'booking_supplement', [
        'supplements' => [],
    ] );
}

function wk_rh_clear_direct_booking_seeded_session() {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }

    WC()->session->set( 'rh_bmi_booking', null );
    WC()->session->set( 'booking_supplement', null );
}

function wk_rh_prepare_cart_for_direct_booking() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return;
    }

    if ( ! WC()->cart->is_empty() ) {
        foreach ( array_keys( WC()->cart->get_cart() ) as $cart_item_key ) {
            WC()->cart->remove_cart_item( $cart_item_key );
        }

        WC()->cart->calculate_totals();

        if ( method_exists( WC()->cart, 'set_session' ) ) {
            WC()->cart->set_session();
        }
    }

    if ( function_exists( 'wk_rh_clear_booking_session_state' ) ) {
        wk_rh_clear_booking_session_state();
        return;
    }

    wk_rh_clear_direct_booking_seeded_session();
}

function wk_rh_fail_direct_booking_request( WP_Error $error, $redirect_url = '' ) {
    $message = $error->get_error_message();
    if ( $message !== '' ) {
        wc_add_notice( $message, 'error' );
    }

    wk_rh_log_user_event( 'direct_booking.failed', [
        'errorCode'    => $error->get_error_code(),
        'errorMessage' => $message,
        'redirectUrl'  => (string) $redirect_url,
    ], 'warning' );

    $target = $redirect_url !== '' ? $redirect_url : wk_rh_get_booking_fallback_url();
    wp_safe_redirect( $target );
    exit;
}

function wk_rh_handle_direct_booking_request() {
    static $handled = false;

    if ( $handled || ! wk_rh_is_direct_booking_request() ) {
        return;
    }
    $handled = true;

    if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
        return;
    }

    $product_context = wk_rh_resolve_direct_booking_product();
    if ( is_wp_error( $product_context ) ) {
        wk_rh_fail_direct_booking_request( $product_context );
    }

    $booking_date = wk_rh_normalize_direct_booking_date( wk_rh_get_direct_booking_request_value( [ 'date', 'booking_date', 'rh_date' ] ) );
    if ( $booking_date === '' ) {
        wk_rh_fail_direct_booking_request( new WP_Error( 'missing_date', __( 'Bookinglink mangler en gyldig dato.', 'racehall-wc-ui' ) ), $product_context['redirectUrl'] );
    }

    $booking_time = wk_rh_normalize_direct_booking_time( wk_rh_get_direct_booking_request_value( [ 'time', 'booking_time', 'rh_time' ] ) );
    if ( $booking_time === '' ) {
        wk_rh_fail_direct_booking_request( new WP_Error( 'missing_time', __( 'Bookinglink mangler et gyldigt tidspunkt.', 'racehall-wc-ui' ) ), $product_context['redirectUrl'] );
    }

    $requested_location = wk_rh_get_direct_booking_request_value( [ 'location', 'booking_location', 'rh_location' ] );
    $product_location = (string) $product_context['location'];
    $booking_location = $product_location !== '' ? $product_location : $requested_location;
    if ( $booking_location === '' ) {
        wk_rh_fail_direct_booking_request( new WP_Error( 'missing_location', __( 'Bookingproduktet mangler lokation.', 'racehall-wc-ui' ) ), $product_context['redirectUrl'] );
    }

    if ( $product_location !== '' && $requested_location !== '' && wk_rh_normalize_direct_booking_compare_value( $product_location ) !== wk_rh_normalize_direct_booking_compare_value( $requested_location ) ) {
        wk_rh_log_user_event( 'direct_booking.location_mismatch_ignored', [
            'productId'         => $product_context['productId'],
            'bmProductId'       => $product_context['bmProductId'],
            'productLocation'   => $product_location,
            'requestedLocation' => $requested_location,
        ], 'warning' );
    }

    $participants = wk_rh_get_direct_booking_participants();
    if ( is_wp_error( $participants ) ) {
        wk_rh_fail_direct_booking_request( $participants, $product_context['redirectUrl'] );
    }

    wk_rh_log_user_event( 'direct_booking.started', [
        'productId'       => $product_context['productId'],
        'bmProductId'     => $product_context['bmProductId'],
        'bookingDate'     => $booking_date,
        'bookingTime'     => $booking_time,
        'bookingLocation' => $booking_location,
        'quantity'        => $participants['quantity'],
    ] );

    $token = wk_rh_get_token( $booking_location );
    if ( ! $token ) {
        wk_rh_fail_direct_booking_request( new WP_Error( 'missing_token', __( 'Kunne ikke oprette forbindelse til bookingmotoren.', 'racehall-wc-ui' ) ), $product_context['redirectUrl'] );
    }

    $page_context = wk_rh_get_direct_booking_page_context( $token, $product_context['bmProductId'], $booking_date, $booking_location );
    if ( is_wp_error( $page_context ) ) {
        wk_rh_fail_direct_booking_request( $page_context, $product_context['redirectUrl'] );
    }

    $dynamic_lines = function_exists( 'wk_rh_build_booking_dynamic_lines' )
        ? wk_rh_build_booking_dynamic_lines( $participants, [], $page_context['pageProducts'], $product_context['bmProductId'] )
        : [];

    $timeslot_response = wk_rh_get_timeslots(
        $token,
        (int) $product_context['bmProductId'],
        (int) $page_context['pageId'],
        $booking_date,
        (int) $participants['quantity'],
        $booking_location,
        $dynamic_lines
    );
    if ( ! is_array( $timeslot_response ) ) {
        wk_rh_fail_direct_booking_request( new WP_Error( 'timeslots_failed', __( 'Kunne ikke hente ledige tider.', 'racehall-wc-ui' ) ), $product_context['redirectUrl'] );
    }

    $proposal_context = wk_rh_find_direct_booking_proposal( $timeslot_response, $booking_time, $page_context['pageId'] );
    if ( is_wp_error( $proposal_context ) ) {
        wk_rh_fail_direct_booking_request( $proposal_context, $product_context['redirectUrl'] );
    }

    $booking_context = [
        'productId'         => $product_context['productId'],
        'bmProductId'       => $product_context['bmProductId'],
        'bookingDate'       => $booking_date,
        'bookingTime'       => $booking_time,
        'bookingLocation'   => $booking_location,
        'participants'      => $participants,
        'proposal'          => $proposal_context['proposal'],
        'pageId'            => $proposal_context['pageId'],
        'resourceId'        => $proposal_context['resourceId'],
        'pageProductLimits' => $page_context['pageProductLimits'],
        'pageProducts'      => $page_context['pageProducts'],
    ];

    wk_rh_prepare_cart_for_direct_booking();
    wk_rh_store_direct_booking_session( $booking_context );
    wk_rh_set_direct_booking_request_payload( $booking_context );

    $cart_item_key = WC()->cart->add_to_cart( $product_context['productId'], $participants['quantity'] );
    if ( ! $cart_item_key ) {
        wk_rh_clear_direct_booking_seeded_session();
        wk_rh_fail_direct_booking_request( new WP_Error( 'add_to_cart_failed', __( 'Bookingen kunne ikke tilføjes til kurven.', 'racehall-wc-ui' ) ), $product_context['redirectUrl'] );
    }

    wk_rh_log_user_event( 'direct_booking.succeeded', [
        'productId'       => $product_context['productId'],
        'bmProductId'     => $product_context['bmProductId'],
        'bookingDate'     => $booking_date,
        'bookingTime'     => $booking_time,
        'bookingLocation' => $booking_location,
        'quantity'        => $participants['quantity'],
        'cartItemKey'     => (string) $cart_item_key,
    ] );

    wp_safe_redirect( wc_get_checkout_url() );
    exit;
}
add_action( 'template_redirect', 'wk_rh_handle_direct_booking_request', 1 );