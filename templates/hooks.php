<?php

function wk_rh_log_upstream_event( $level, $message, array $context = [] ) {
    $entry = [
        'time'    => gmdate( 'c' ),
        'level'   => (string) $level,
        'message' => (string) $message,
        'context' => $context,
    ];

    $existing = get_option( 'wk_rh_upstream_logs', [] );
    if ( ! is_array( $existing ) ) {
        $existing = [];
    }

    $existing[] = $entry;
    if ( count( $existing ) > 200 ) {
        $existing = array_slice( $existing, -200 );
    }

    update_option( 'wk_rh_upstream_logs', $existing, false );

    $payload = [
        'level'   => (string) $level,
        'message' => (string) $message,
        'context' => $context,
    ];

    error_log( 'OnsiteBookingUpstream ' . wp_json_encode( $payload ) );
}

function wk_rh_remote_request_with_retry( $method, $url, array $args = [], $attempts = 1, array $context = [] ) {
    $attempts = max( 1, (int) $attempts );
    $retryable_codes = [ 408, 429, 500, 502, 503, 504 ];
    $last_response = null;

    for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
        $response = wp_remote_request( $url, array_merge( $args, [ 'method' => strtoupper( (string) $method ) ] ) );
        $last_response = $response;

        if ( is_wp_error( $response ) ) {
            wk_rh_log_upstream_event( 'warning', 'Transport error calling upstream', array_merge( $context, [
                'attempt' => $attempt,
                'url' => $url,
                'error' => $response->get_error_message(),
            ] ) );

            if ( $attempt < $attempts ) {
                usleep( 250000 * $attempt );
                continue;
            }

            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, $retryable_codes, true ) && $attempt < $attempts ) {
            wk_rh_log_upstream_event( 'warning', 'Retryable upstream response', array_merge( $context, [
                'attempt' => $attempt,
                'url' => $url,
                'httpCode' => $code,
            ] ) );
            usleep( 250000 * $attempt );
            continue;
        }

        return $response;
    }

    return $last_response;
}

function wk_rh_get_token( $location = '' ) {
    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) || empty( $creds['username'] ) || empty( $creds['password'] ) ) {
        return false;
    }

    $url = $creds['base_url'] . '/auth/' . rawurlencode( $creds['client_key'] ) . '/publicbooking';
    $response = wk_rh_remote_request_with_retry(
        'POST',
        $url,
        [
            'headers' => [
                'Content-Type'         => 'application/json',
                'Bmi-Subscription-Key' => $creds['subscription_key'],
            ],
            'body'    => wp_json_encode([
                'Username' => $creds['username'],
                'Password' => $creds['password'],
            ]),
            'timeout' => 15,
        ],
        2,
        [
            'operation' => 'auth',
            'location'  => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    return $data['AccessToken'] ?? false;
}

function wk_rh_post_booking_sell( $location, $product_id, $quantity, $order_id, $parent_order_item_id = '' ) {
    $token = wk_rh_get_token( $location );
    $creds = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [ 'success' => false, 'data' => null ];
    }

    $body = [
        'productId' => (string) $product_id,
        'quantity'  => (int) $quantity,
        'orderId'   => (string) $order_id,
    ];

    if ( $parent_order_item_id !== '' ) {
        $body['parentOrderItemId'] = (string) $parent_order_item_id;
    }

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/sell';
    $response = wk_rh_remote_request_with_retry(
        'POST',
        $url,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
                'Bmi-Subscription-Key' => $creds['subscription_key'],
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ],
        1,
        [
            'operation' => 'booking_sell',
            'orderId'   => (string) $order_id,
            'productId' => (string) $product_id,
            'location'  => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [ 'success' => false, 'data' => null ];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! ( $code >= 200 && $code < 300 ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream booking/sell failed', [
            'operation' => 'booking_sell',
            'orderId' => (string) $order_id,
            'productId' => (string) $product_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => wp_remote_retrieve_body( $response ),
        ] );
    }

    return [
        'success' => $code >= 200 && $code < 300,
        'data'    => is_array( $data ) ? $data : null,
    ];
}

function wk_rh_remove_upstream_order_item( $location, $order_id, $order_item_id ) {
    $token = wk_rh_get_token( $location );
    $creds = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return false;
    }

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/removeItem';
    $response = wk_rh_remote_request_with_retry(
        'POST',
        $url,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
                'Bmi-Subscription-Key' => $creds['subscription_key'],
            ],
            'body'    => wp_json_encode([
                'orderId'     => ctype_digit( (string) $order_id ) ? (int) $order_id : (string) $order_id,
                'orderItemId' => ctype_digit( (string) $order_item_id ) ? (int) $order_item_id : (string) $order_item_id,
            ]),
            'timeout' => 30,
        ],
        3,
        [
            'operation' => 'booking_remove_item',
            'orderId' => (string) $order_id,
            'orderItemId' => (string) $order_item_id,
            'location' => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( ! ( $code >= 200 && $code < 300 ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream booking/removeItem failed', [
            'operation' => 'booking_remove_item',
            'orderId' => (string) $order_id,
            'orderItemId' => (string) $order_item_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => wp_remote_retrieve_body( $response ),
        ] );
    }
    return $code >= 200 && $code < 300;
}

function wk_rh_send_order_memo( WC_Order $order ) {
    $memo = trim( (string) $order->get_customer_note() );
    if ( $memo === '' || $order->get_meta( '_wk_rh_memo_synced', true ) === 'yes' ) {
        return;
    }

    $upstream_order_id = wk_rh_get_order_upstream_order_id( $order );
    if ( empty( $upstream_order_id ) ) {
        return;
    }

    $location = wk_rh_get_order_booking_location( $order );
    $token    = wk_rh_get_token( $location );
    $creds    = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return;
    }

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/memo';
    $response = wk_rh_remote_request_with_retry(
        'POST',
        $url,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
                'Bmi-Subscription-Key' => $creds['subscription_key'],
            ],
            'body'    => wp_json_encode([
                'orderId' => ctype_digit( (string) $upstream_order_id ) ? (int) $upstream_order_id : (string) $upstream_order_id,
                'memo'    => $memo,
            ]),
            'timeout' => 20,
        ],
        3,
        [
            'operation' => 'booking_memo',
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code >= 200 && $code < 300 ) {
        $order->update_meta_data( '_wk_rh_memo_synced', 'yes' );
        $order->save();
    } else {
        wk_rh_log_upstream_event( 'error', 'Upstream booking/memo failed', [
            'operation' => 'booking_memo',
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => wp_remote_retrieve_body( $response ),
        ] );
    }
}

function wk_rh_get_products( $token, $location = '' ) {
    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $response = wp_remote_get(
        $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/products',
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Bmi-Subscription-Key' => $creds['subscription_key'],
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
            ],
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [];
    }

    return json_decode( wp_remote_retrieve_body( $response ), true );
}

function wk_rh_get_availability( $token, $product_id, $date_from, $date_till, $location = '' ) {
    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $query = http_build_query([
        'productId' => (int) $product_id,
        'dateFrom'  => (string) $date_from,
        'dateTill'  => (string) $date_till,
    ]);

    $response = wp_remote_get(
        $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/availability?' . $query,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Bmi-Subscription-Key' => $creds['subscription_key'],
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
            ],
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [];
    }

    return json_decode( wp_remote_retrieve_body( $response ), true );
}

function wk_rh_ajax_get_availability() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'my_ajax_nonce' ) ) {
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    $product_id = isset( $_POST['productId'] ) ? intval( $_POST['productId'] ) : 0;
    $date_from  = isset( $_POST['dateFrom'] ) ? sanitize_text_field( $_POST['dateFrom'] ) : '';
    $date_till  = isset( $_POST['dateTill'] ) ? sanitize_text_field( $_POST['dateTill'] ) : '';

    if ( ! $product_id || ! $date_from || ! $date_till ) {
        wp_send_json_error( 'Missing productId/dateFrom/dateTill', 400 );
    }

    $booking_location = isset( $_POST['bookingLocation'] ) ? sanitize_text_field( $_POST['bookingLocation'] ) : '';

    $token = wk_rh_get_token( $booking_location );
    if ( ! $token ) {
        wp_send_json_error( 'No token', 401 );
    }

    $result = wk_rh_get_availability( $token, $product_id, $date_from, $date_till, $booking_location );
    wp_send_json( $result );
}

add_action( 'wp_ajax_rh_get_availability', 'wk_rh_ajax_get_availability' );
add_action( 'wp_ajax_nopriv_rh_get_availability', 'wk_rh_ajax_get_availability' );

function wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity = 1, $location = '' ) {
    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $response = wp_remote_post(
        $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/availability?date=' . rawurlencode( $date ),
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Bmi-Subscription-Key' => $creds['subscription_key'],
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
            ],
            'body'    => wp_json_encode([
                'productId' => (int) $product_id,
                'pageId'    => (int) $page_id,
                'quantity'  => (int) $quantity,
            ]),
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [];
    }

    return json_decode( wp_remote_retrieve_body( $response ), true );
}

function wk_rh_ajax_get_timeslots() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'my_ajax_nonce' ) ) {
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    $product_id = isset( $_POST['productId'] ) ? intval( $_POST['productId'] ) : 0;
    $date       = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
    $quantity   = isset( $_POST['quantity'] ) ? max( 1, intval( $_POST['quantity'] ) ) : 1;
    $booking_location = isset( $_POST['bookingLocation'] ) ? sanitize_text_field( $_POST['bookingLocation'] ) : '';
    if ( ! $product_id || ! $date ) {
        wp_send_json_error( 'Missing productId or date', 400 );
    }

    $token = wk_rh_get_token( $booking_location );
    if ( ! $token ) {
        wp_send_json_error( 'No token', 401 );
    }

    $creds = wk_rh_get_api_credentials( $booking_location );
    $pages_response = wp_remote_get(
        $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/page?date=' . rawurlencode( $date . 'T00:00:00.000Z' ),
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Bmi-Subscription-Key' => $creds['subscription_key'],
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
            ],
        ]
    );

    if ( is_wp_error( $pages_response ) ) {
        wp_send_json_error( 'API error: ' . $pages_response->get_error_message(), 500 );
    }

    $pages = json_decode( wp_remote_retrieve_body( $pages_response ), true );
    if ( ! is_array( $pages ) ) {
        wp_send_json_error( 'Invalid API response', 500 );
    }

    $page_id = null;
    foreach ( $pages as $page ) {
        if ( empty( $page['products'] ) || ! is_array( $page['products'] ) ) {
            continue;
        }
        foreach ( $page['products'] as $prod ) {
            if ( isset( $prod['id'] ) && (int) $prod['id'] === $product_id ) {
                $page_id = $page['id'];
                break 2;
            }
        }
    }

    if ( ! $page_id ) {
        wp_send_json_error( 'No page found for product/date', 404 );
    }

    $timeslots = wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity, $booking_location );
    wp_send_json( $timeslots );
}

add_action( 'wp_ajax_rh_get_timeslots', 'wk_rh_ajax_get_timeslots' );
add_action( 'wp_ajax_nopriv_rh_get_timeslots', 'wk_rh_ajax_get_timeslots' );

add_filter( 'woocommerce_add_cart_item_data', 'wk_rh_save_booking_data_to_cart', 10, 2 );
function wk_rh_save_booking_data_to_cart( $cart_item_data, $product_id ) {
    if ( ! empty( $_POST['booking_date'] ) ) {
        $cart_item_data['booking_date'] = sanitize_text_field( $_POST['booking_date'] );
    }
    if ( ! empty( $_POST['booking_time'] ) ) {
        $cart_item_data['booking_time'] = sanitize_text_field( $_POST['booking_time'] );
    }
    if ( ! empty( $_POST['booking_location'] ) ) {
        $cart_item_data['booking_location'] = sanitize_text_field( $_POST['booking_location'] );
    }
    if ( isset( $_POST['booking_adults'] ) ) {
        $cart_item_data['booking_adults'] = max( 0, intval( $_POST['booking_adults'] ) );
    }
    if ( isset( $_POST['booking_children'] ) ) {
        $cart_item_data['booking_children'] = max( 0, intval( $_POST['booking_children'] ) );
    }
    return $cart_item_data;
}

function wk_rh_extract_quantity_rules_from_proposal( $proposal ) {
    $rules = [
        'adults' => [ 'min' => 1, 'max' => null, 'step' => 1 ],
        'kids' => [ 'min' => 0, 'max' => null, 'step' => 1 ],
        'total' => [ 'min' => 1, 'max' => null, 'step' => 1 ],
    ];

    if ( ! is_array( $proposal ) ) {
        return $rules;
    }

    $proposal_min = null;
    foreach ( [ 'minQuantity', 'minQty', 'minimumQuantity', 'minimumQty' ] as $key ) {
        if ( isset( $proposal[ $key ] ) && is_numeric( $proposal[ $key ] ) ) {
            $proposal_min = (float) $proposal[ $key ];
            break;
        }
    }

    $proposal_max = null;
    foreach ( [ 'maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty' ] as $key ) {
        if ( isset( $proposal[ $key ] ) && is_numeric( $proposal[ $key ] ) ) {
            $proposal_max = (float) $proposal[ $key ];
            break;
        }
    }

    $min_amount = null;
    foreach ( [ 'minAmount', 'minimumAmount' ] as $key ) {
        if ( isset( $proposal[ $key ] ) && is_numeric( $proposal[ $key ] ) ) {
            $min_amount = (float) $proposal[ $key ];
            break;
        }
    }

    $max_amount = null;
    foreach ( [ 'maxAmount', 'maximumAmount' ] as $key ) {
        if ( isset( $proposal[ $key ] ) && is_numeric( $proposal[ $key ] ) ) {
            $max_amount = (float) $proposal[ $key ];
            break;
        }
    }

    if ( $proposal_min !== null && $proposal_min >= 0 ) {
        $rules['total']['min'] = (int) round( $proposal_min );
    }
    if ( $proposal_max !== null && $proposal_max >= 0 ) {
        $rules['total']['max'] = (int) round( $proposal_max );
    }
    if ( $min_amount !== null && $min_amount > 0 ) {
        $rules['total']['min'] = max( (int) $rules['total']['min'], (int) round( $min_amount ) );
    }
    if ( $max_amount !== null && $max_amount > 0 ) {
        $resolved_max = (int) round( $max_amount );
        $rules['total']['max'] = $rules['total']['max'] === null ? $resolved_max : min( (int) $rules['total']['max'], $resolved_max );
    }

    $groups = isset( $proposal['dynamicGroups'] ) && is_array( $proposal['dynamicGroups'] ) ? $proposal['dynamicGroups'] : [];
    foreach ( $groups as $group ) {
        if ( ! is_array( $group ) ) {
            continue;
        }

        $tag = strtolower( trim( (string) ( $group['tag'] ?? '' ) ) );
        $target_key = null;
        if ( in_array( $tag, [ 'adults', 'adult', 'voksne' ], true ) ) {
            $target_key = 'adults';
        } elseif ( in_array( $tag, [ 'kids', 'children', 'child', 'born', 'børn' ], true ) ) {
            $target_key = 'kids';
        }

        if ( $target_key === null ) {
            continue;
        }

        foreach ( [ 'minQuantity', 'minQty', 'minimumQuantity', 'minimumQty' ] as $key ) {
            if ( isset( $group[ $key ] ) && is_numeric( $group[ $key ] ) ) {
                $rules[ $target_key ]['min'] = max( 0, (int) round( (float) $group[ $key ] ) );
                break;
            }
        }
        foreach ( [ 'maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty' ] as $key ) {
            if ( isset( $group[ $key ] ) && is_numeric( $group[ $key ] ) ) {
                $rules[ $target_key ]['max'] = max( 0, (int) round( (float) $group[ $key ] ) );
                break;
            }
        }

        $step_candidates = [
            $group['step'] ?? null,
            $group['stepQuantity'] ?? null,
            $group['quantityStep'] ?? null,
            $group['stepSize'] ?? null,
            $group['increment'] ?? null,
        ];
        foreach ( $step_candidates as $candidate ) {
            if ( is_numeric( $candidate ) && (float) $candidate > 0 ) {
                $rules[ $target_key ]['step'] = (int) round( (float) $candidate );
                break;
            }
        }
    }

    return $rules;
}

function wk_rh_rule_value_matches_step( $value, $min, $step ) {
    $step = (int) $step;
    if ( $step <= 1 ) {
        return true;
    }
    $delta = (int) $value - (int) $min;
    return $delta >= 0 && $delta % $step === 0;
}

add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_validate_main_booking_quantity_rules', 30, 3 );
function wk_rh_validate_main_booking_quantity_rules( $passed, $product_id, $quantity ) {
    if ( ! $passed ) {
        return false;
    }

    if ( isset( $_POST['is_addon'] ) ) {
        return $passed;
    }

    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return $passed;
    }

    $session_booking = WC()->session->get( 'rh_bmi_booking' );
    if ( ! is_array( $session_booking ) || empty( $session_booking['proposal'] ) || ! is_array( $session_booking['proposal'] ) ) {
        return $passed;
    }

    $rules = wk_rh_extract_quantity_rules_from_proposal( $session_booking['proposal'] );

    $adults = isset( $_POST['booking_adults'] ) ? max( 0, (int) $_POST['booking_adults'] ) : null;
    $kids   = isset( $_POST['booking_children'] ) ? max( 0, (int) $_POST['booking_children'] ) : null;
    $qty    = max( 1, (int) $quantity );

    if ( $adults === null && $kids === null ) {
        return $passed;
    }
    if ( $adults === null ) {
        $adults = 0;
    }
    if ( $kids === null ) {
        $kids = 0;
    }

    $group_checks = [
        [ 'name' => __( 'Adults', 'onsite-booking-system' ), 'value' => $adults, 'rules' => $rules['adults'] ],
        [ 'name' => __( 'Children', 'onsite-booking-system' ), 'value' => $kids, 'rules' => $rules['kids'] ],
    ];

    foreach ( $group_checks as $check ) {
        $min = (int) $check['rules']['min'];
        $max = isset( $check['rules']['max'] ) ? $check['rules']['max'] : null;
        $step = (int) $check['rules']['step'];

        if ( $check['value'] < $min ) {
            wc_add_notice( sprintf( __( '%s must be at least %d.', 'onsite-booking-system' ), $check['name'], $min ), 'error' );
            return false;
        }
        if ( $max !== null && $check['value'] > (int) $max ) {
            wc_add_notice( sprintf( __( '%s cannot exceed %d.', 'onsite-booking-system' ), $check['name'], (int) $max ), 'error' );
            return false;
        }
        if ( ! wk_rh_rule_value_matches_step( $check['value'], $min, $step ) ) {
            wc_add_notice( sprintf( __( '%s quantity must follow step %d starting from %d.', 'onsite-booking-system' ), $check['name'], max( 1, $step ), $min ), 'error' );
            return false;
        }
    }

    $total = $adults + $kids;
    if ( $total !== $qty ) {
        wc_add_notice( __( 'Participant quantities do not match selected booking quantity.', 'onsite-booking-system' ), 'error' );
        return false;
    }

    $total_min = (int) $rules['total']['min'];
    $total_max = isset( $rules['total']['max'] ) ? $rules['total']['max'] : null;
    $total_step = (int) $rules['total']['step'];

    if ( $total < $total_min ) {
        wc_add_notice( sprintf( __( 'Total participants must be at least %d.', 'onsite-booking-system' ), $total_min ), 'error' );
        return false;
    }
    if ( $total_max !== null && $total > (int) $total_max ) {
        wc_add_notice( sprintf( __( 'Total participants cannot exceed %d.', 'onsite-booking-system' ), (int) $total_max ), 'error' );
        return false;
    }
    if ( ! wk_rh_rule_value_matches_step( $total, $total_min, $total_step ) ) {
        wc_add_notice( sprintf( __( 'Total participants must follow step %d starting from %d.', 'onsite-booking-system' ), max( 1, $total_step ), $total_min ), 'error' );
        return false;
    }

    return $passed;
}

add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_validate_main_booking_selection', 25, 3 );
function wk_rh_validate_main_booking_selection( $passed, $product_id, $quantity ) {
    if ( ! $passed ) {
        return false;
    }

    if ( isset( $_POST['is_addon'] ) ) {
        return $passed;
    }

    $bm_id = function_exists( 'get_field' )
        ? get_field( 'bmileisure_id', $product_id )
        : get_post_meta( $product_id, 'bmileisure_id', true );

    if ( empty( $bm_id ) ) {
        return $passed;
    }

    $booking_date = isset( $_POST['booking_date'] ) ? sanitize_text_field( (string) $_POST['booking_date'] ) : '';
    $booking_time = isset( $_POST['booking_time'] ) ? sanitize_text_field( (string) $_POST['booking_time'] ) : '';

    if ( $booking_date === '' || $booking_time === '' ) {
        wc_add_notice( __( 'Vælg venligst både dato og tidspunkt før du tilføjer til kurv.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        wc_add_notice( __( 'Booking session mangler. Opdater siden og prøv igen.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $session_booking = WC()->session->get( 'rh_bmi_booking' );
    if ( ! is_array( $session_booking ) || empty( $session_booking['proposal'] ) ) {
        wc_add_notice( __( 'Vælg et gyldigt tidspunkt før du tilføjer til kurv.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $session_product_id = isset( $session_booking['productId'] ) ? (string) $session_booking['productId'] : '';
    if ( $session_product_id !== '' && $session_product_id !== (string) $bm_id ) {
        wc_add_notice( __( 'Den valgte tid matcher ikke produktet. Vælg tidspunkt igen.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    return $passed;
}

add_filter( 'woocommerce_get_item_data', 'wk_rh_show_booking_data_in_cart', 10, 2 );
function wk_rh_show_booking_data_in_cart( $item_data, $cart_item ) {
    if ( isset( $cart_item['booking_date'] ) ) {
        $item_data[] = [ 'name' => 'Dato', 'value' => $cart_item['booking_date'] ];
    }
    if ( isset( $cart_item['booking_time'] ) ) {
        $item_data[] = [ 'name' => 'Tidspunkt', 'value' => $cart_item['booking_time'] ];
    }
    if ( isset( $cart_item['booking_location'] ) ) {
        $item_data[] = [ 'name' => 'Lokation', 'value' => $cart_item['booking_location'] ];
    }
    if ( isset( $cart_item['booking_adults'] ) || isset( $cart_item['booking_children'] ) ) {
        $adults = isset( $cart_item['booking_adults'] ) ? (int) $cart_item['booking_adults'] : 0;
        $children = isset( $cart_item['booking_children'] ) ? (int) $cart_item['booking_children'] : 0;
        $item_data[] = [ 'name' => 'Deltagere', 'value' => $adults . ' voksne, ' . $children . ' børn' ];
    }
    return $item_data;
}

function wk_rh_get_checkout_booking_details_text( $cart_item ) {
    if ( ! is_array( $cart_item ) ) {
        return '';
    }

    $parts = [];
    if ( ! empty( $cart_item['booking_date'] ) ) {
        $parts[] = 'Dato: ' . sanitize_text_field( $cart_item['booking_date'] );
    }
    if ( ! empty( $cart_item['booking_time'] ) ) {
        $parts[] = 'Tidspunkt: ' . sanitize_text_field( $cart_item['booking_time'] );
    }
    if ( ! empty( $cart_item['booking_location'] ) ) {
        $parts[] = 'Lokation: ' . sanitize_text_field( $cart_item['booking_location'] );
    }

    $has_people = isset( $cart_item['booking_adults'] ) || isset( $cart_item['booking_children'] );
    if ( $has_people ) {
        $adults   = isset( $cart_item['booking_adults'] ) ? (int) $cart_item['booking_adults'] : 0;
        $children = isset( $cart_item['booking_children'] ) ? (int) $cart_item['booking_children'] : 0;
        $parts[]  = 'Deltagere: ' . $adults . ' voksne, ' . $children . ' børn';
    }

    if ( empty( $parts ) ) {
        return '';
    }

    return implode( ' | ', $parts );
}

add_filter( 'woocommerce_cart_item_name', 'wk_rh_checkout_cart_item_name_with_details', 10, 3 );
function wk_rh_checkout_cart_item_name_with_details( $product_name, $cart_item, $cart_item_key ) {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return $product_name;
    }

    $details_text = wk_rh_get_checkout_booking_details_text( $cart_item );
    if ( $details_text === '' ) {
        return $product_name;
    }

    return $product_name . '<br><small class="wk-rh-checkout-booking-details">' . esc_html( $details_text ) . '</small>';
}

add_action( 'woocommerce_checkout_create_order_line_item', 'wk_rh_add_booking_data_to_order_items', 10, 4 );
function wk_rh_add_booking_data_to_order_items( $item, $cart_item_key, $values, $order ) {
    if ( isset( $values['booking_date'] ) ) {
        $item->add_meta_data( 'Dato', $values['booking_date'], true );
    }
    if ( isset( $values['booking_time'] ) ) {
        $item->add_meta_data( 'Tidspunkt', $values['booking_time'], true );
    }
    if ( isset( $values['booking_location'] ) ) {
        $item->add_meta_data( 'Lokation', $values['booking_location'], true );
        $item->add_meta_data( '_wk_rh_booking_location', $values['booking_location'], true );
    }
    if ( isset( $values['booking_adults'] ) ) {
        $item->add_meta_data( 'Voksne', (int) $values['booking_adults'], true );
    }
    if ( isset( $values['booking_children'] ) ) {
        $item->add_meta_data( 'Børn', (int) $values['booking_children'], true );
    }
}

add_action( 'wp_ajax_rh_save_proposal', 'wk_rh_save_proposal' );
add_action( 'wp_ajax_nopriv_rh_save_proposal', 'wk_rh_save_proposal' );
function wk_rh_save_proposal() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'my_ajax_nonce' ) ) {
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    $proposal    = isset( $_POST['proposal'] ) ? json_decode( stripslashes( $_POST['proposal'] ), true ) : null;
    $page_id     = isset( $_POST['pageId'] ) ? sanitize_text_field( $_POST['pageId'] ) : '';
    $resource_id = isset( $_POST['resourceId'] ) ? sanitize_text_field( $_POST['resourceId'] ) : '';
    $product_id  = isset( $_POST['productId'] ) ? sanitize_text_field( $_POST['productId'] ) : '';
    $quantity    = isset( $_POST['quantity'] ) ? max( 1, intval( $_POST['quantity'] ) ) : 1;
    $booking_location = isset( $_POST['bookingLocation'] ) ? sanitize_text_field( $_POST['bookingLocation'] ) : '';

    if ( empty( $proposal ) ) {
        wp_send_json_error( 'Missing proposal', 400 );
    }

    if ( WC()->session ) {
        WC()->session->set( 'rh_bmi_booking', [
            'proposal'        => $proposal,
            'pageId'          => $page_id,
            'resourceId'      => $resource_id,
            'productId'       => $product_id,
            'quantity'        => $quantity,
            'bookingLocation' => $booking_location,
            'orderId'         => '',
            'orderItemId'     => '',
            'expiresAt'       => '',
        ] );

        WC()->session->set( 'booking_supplement', [
            'supplements' => [],
        ] );
    }

    wp_send_json_success( [
        'stored' => true,
    ] );
}

function wk_rh_get_order_booking_location( WC_Order $order ) {
    foreach ( $order->get_items() as $item ) {
        $raw = $item->get_meta( '_wk_rh_booking_location', true );
        if ( ! empty( $raw ) ) {
            return sanitize_text_field( $raw );
        }
        $legacy = $item->get_meta( 'Lokation', true );
        if ( ! empty( $legacy ) ) {
            return sanitize_text_field( $legacy );
        }
    }
    return '';
}

function wk_rh_get_order_upstream_order_id( WC_Order $order ) {
    $order_id = $order->get_meta( 'bmi_order_id', true );
    if ( ! empty( $order_id ) ) {
        return (string) $order_id;
    }

    foreach ( $order->get_items() as $item ) {
        $item_order_id = $item->get_meta( 'bmi_order_id', true );
        if ( ! empty( $item_order_id ) ) {
            return (string) $item_order_id;
        }
    }

    return '';
}

function wk_rh_mark_payment_confirmed( WC_Order $order, $response_body = '' ) {
    $order->update_meta_data( '_wk_rh_payment_confirmed', 'yes' );
    $order->save();
    $order->add_order_note( 'Onsite booking: payment/confirm synced to upstream.' );

    if ( function_exists( 'wk_rh_release_active_hold' ) ) {
        $upstream_order_id = wk_rh_get_order_upstream_order_id( $order );
        if ( ! empty( $upstream_order_id ) ) {
            wk_rh_release_active_hold( (string) $upstream_order_id );
        }
    }
}

function wk_rh_confirm_payment_for_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    if ( $order->get_meta( '_wk_rh_payment_confirmed', true ) === 'yes' ) {
        return;
    }

    if ( $order->get_payment_method() === 'cod' ) {
        return;
    }

    $upstream_order_id = wk_rh_get_order_upstream_order_id( $order );
    if ( empty( $upstream_order_id ) ) {
        return;
    }

    $location = wk_rh_get_order_booking_location( $order );
    $token    = wk_rh_get_token( $location );
    $creds    = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return;
    }

    $transaction_id = $order->get_transaction_id();
    $external_id    = $transaction_id ? $transaction_id : 'wc-' . $order->get_id() . '-' . time();

    $payload = [
        'id'          => $external_id,
        'paymentTime' => gmdate( 'c' ),
        'amount'      => (float) $order->get_total(),
        'orderId'     => ctype_digit( (string) $upstream_order_id ) ? (int) $upstream_order_id : $upstream_order_id,
        'extraData'   => [
            'wcOrderId'      => (string) $order->get_id(),
            'paymentMethod'  => (string) $order->get_payment_method(),
            'transactionId'  => (string) $transaction_id,
        ],
    ];

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/payment/confirm';
    $response = wk_rh_remote_request_with_retry(
        'POST',
        $url,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
                'Bmi-Subscription-Key' => $creds['subscription_key'],
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ],
        3,
        [
            'operation' => 'payment_confirm',
            'orderId' => (string) $upstream_order_id,
            'wcOrderId' => (string) $order->get_id(),
            'location' => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        $order->add_order_note( 'Onsite booking payment/confirm failed: ' . $response->get_error_message() );
        return;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code >= 200 && $code < 300 ) {
        wk_rh_mark_payment_confirmed( $order, wp_remote_retrieve_body( $response ) );
    } else {
        wk_rh_log_upstream_event( 'error', 'Upstream payment/confirm failed', [
            'operation' => 'payment_confirm',
            'orderId' => (string) $upstream_order_id,
            'wcOrderId' => (string) $order->get_id(),
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => wp_remote_retrieve_body( $response ),
        ] );
        $order->add_order_note( 'Onsite booking payment/confirm failed with HTTP ' . $code );
    }
}

add_action( 'woocommerce_payment_complete', 'wk_rh_confirm_payment_for_order', 20 );
add_action( 'woocommerce_order_status_processing', 'wk_rh_confirm_payment_for_order', 20 );
add_action( 'woocommerce_order_status_completed', 'wk_rh_confirm_payment_for_order', 20 );

function wk_rh_cancel_upstream_order_by_id( $upstream_order_id, $location = '', array $extra_context = [] ) {
    $upstream_order_id = trim( (string) $upstream_order_id );
    if ( $upstream_order_id === '' ) {
        return false;
    }

    $token    = wk_rh_get_token( $location );
    $creds    = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return false;
    }

    $paths = [
        '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/order/' . rawurlencode( (string) $upstream_order_id ) . '/cancel',
        '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/bill/' . rawurlencode( (string) $upstream_order_id ) . '/cancel',
    ];

    foreach ( $paths as $path ) {
        $response = wk_rh_remote_request_with_retry(
            'DELETE',
            $creds['base_url'] . $path,
            [
                'headers' => [
                    'Authorization'        => 'Bearer ' . $token,
                    'Bmi-Subscription-Key' => $creds['subscription_key'],
                    'Accept-Language'      => $creds['accept_language'],
                ],
                'timeout' => 20,
            ],
            3,
            [
                'operation' => 'order_cancel',
                'orderId' => (string) $upstream_order_id,
                'path' => (string) $path,
                'location' => (string) $location,
                'extra' => $extra_context,
            ]
        );

        if ( is_wp_error( $response ) ) {
            continue;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            if ( function_exists( 'wk_rh_release_active_hold' ) ) {
                wk_rh_release_active_hold( $upstream_order_id );
            }
            return true;
        }
    }

    wk_rh_log_upstream_event( 'error', 'Upstream cancellation failed for all known endpoints', [
        'operation' => 'order_cancel',
        'orderId' => (string) $upstream_order_id,
        'location' => (string) $location,
        'extra' => $extra_context,
    ] );
    return false;
}

function wk_rh_cancel_upstream_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    if ( $order->get_meta( '_wk_rh_cancel_synced', true ) === 'yes' ) {
        return;
    }

    $upstream_order_id = wk_rh_get_order_upstream_order_id( $order );
    if ( empty( $upstream_order_id ) ) {
        return;
    }

    $location = wk_rh_get_order_booking_location( $order );
    $success = wk_rh_cancel_upstream_order_by_id( $upstream_order_id, $location, [
        'wcOrderId' => (string) $order->get_id(),
        'source'    => 'wc_order_status',
    ] );

    if ( $success ) {
        $order->update_meta_data( '_wk_rh_cancel_synced', 'yes' );
        $order->save();
        $order->add_order_note( 'Onsite booking cancellation synced to upstream.' );
        return;
    }

    $order->add_order_note( 'Onsite booking cancellation sync failed for all known cancel endpoints.' );
}

add_action( 'woocommerce_order_status_cancelled', 'wk_rh_cancel_upstream_order', 20 );
add_action( 'woocommerce_order_status_refunded', 'wk_rh_cancel_upstream_order', 20 );
add_action( 'woocommerce_order_status_failed', 'wk_rh_cancel_upstream_order', 20 );
add_action( 'woocommerce_checkout_order_processed', 'wk_rh_send_order_memo', 20 );

function racehall_get_token( $location = '' ) {
    return wk_rh_get_token( $location );
}

function racehall_get_products( $token, $location = '' ) {
    return wk_rh_get_products( $token, $location );
}

function racehall_get_availability( $token, $product_id, $date_from, $date_till, $location = '' ) {
    return wk_rh_get_availability( $token, $product_id, $date_from, $date_till, $location );
}

function racehall_get_timeslots( $token, $product_id, $page_id, $date, $quantity = 1, $location = '' ) {
    return wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity, $location );
}
