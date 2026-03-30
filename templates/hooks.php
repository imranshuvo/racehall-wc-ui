<?php

function wk_rh_remote_request_with_retry( $method, $url, array $args = [], $attempts = 1, array $context = [] ) {
    $attempts = max( 1, (int) $attempts );
    $retryable_codes = [ 408, 429, 500, 502, 503, 504 ];
    $last_response = null;
    $request_context = [
        'headers' => function_exists( 'wk_rh_prepare_log_http_headers' ) ? wk_rh_prepare_log_http_headers( $args['headers'] ?? [] ) : [],
        'body'    => function_exists( 'wk_rh_prepare_log_http_body' ) ? wk_rh_prepare_log_http_body( $args['body'] ?? '' ) : '',
        'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 0,
    ];

    for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
        wk_rh_log_upstream_event( 'info', 'Upstream request started', array_merge( $context, [
            'attempt' => $attempt,
            'method' => strtoupper( (string) $method ),
            'url' => $url,
            'request' => $request_context,
        ] ) );

        $response = wp_remote_request( $url, array_merge( $args, [ 'method' => strtoupper( (string) $method ) ] ) );
        $last_response = $response;

        if ( is_wp_error( $response ) ) {
            wk_rh_log_upstream_event( 'warning', 'Transport error calling upstream', array_merge( $context, [
                'attempt' => $attempt,
                'url' => $url,
                'error' => $response->get_error_message(),
                'request' => $request_context,
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
                'request' => $request_context,
                'response' => [
                    'headers' => function_exists( 'wk_rh_prepare_log_http_headers' ) ? wk_rh_prepare_log_http_headers( wp_remote_retrieve_headers( $response ) ) : [],
                    'body' => function_exists( 'wk_rh_prepare_log_http_body' ) ? wk_rh_prepare_log_http_body( wp_remote_retrieve_body( $response ) ) : '',
                ],
            ] ) );
            usleep( 250000 * $attempt );
            continue;
        }

        wk_rh_log_upstream_event( $code >= 200 && $code < 300 ? 'info' : 'warning', 'Upstream response received', array_merge( $context, [
            'attempt' => $attempt,
            'method' => strtoupper( (string) $method ),
            'url' => $url,
            'httpCode' => $code,
            'request' => $request_context,
            'response' => [
                'headers' => function_exists( 'wk_rh_prepare_log_http_headers' ) ? wk_rh_prepare_log_http_headers( wp_remote_retrieve_headers( $response ) ) : [],
                'body' => function_exists( 'wk_rh_prepare_log_http_body' ) ? wk_rh_prepare_log_http_body( wp_remote_retrieve_body( $response ) ) : '',
            ],
        ] ) );

        return $response;
    }

    return $last_response;
}

function wk_rh_get_token( $location = '' ) {
    static $runtime_cache = [];

    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) || empty( $creds['username'] ) || empty( $creds['password'] ) ) {
        return false;
    }

    $cache_key = 'wk_rh_token_' . md5( (string) $location . '|' . (string) $creds['base_url'] . '|' . (string) $creds['client_key'] . '|' . (string) $creds['username'] );
    if ( isset( $runtime_cache[ $cache_key ] ) && is_string( $runtime_cache[ $cache_key ] ) && $runtime_cache[ $cache_key ] !== '' ) {
        return $runtime_cache[ $cache_key ];
    }

    $cached_token = get_transient( $cache_key );
    if ( is_string( $cached_token ) && $cached_token !== '' ) {
        $runtime_cache[ $cache_key ] = $cached_token;
        return $cached_token;
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
    $token = isset( $data['AccessToken'] ) ? (string) $data['AccessToken'] : '';
    if ( $token === '' ) {
        return false;
    }

    $expires_in = isset( $data['ExpiresIn'] ) && is_numeric( $data['ExpiresIn'] ) ? (int) $data['ExpiresIn'] : 3600;
    $ttl = max( 60, $expires_in - 60 );
    set_transient( $cache_key, $token, $ttl );
    $runtime_cache[ $cache_key ] = $token;

    return $token;
}

function wk_rh_detect_image_content_type( $body, $content_type = '' ) {
    $content_type = is_string( $content_type ) ? trim( strtolower( (string) $content_type ) ) : '';
    if ( $content_type !== '' ) {
        $content_type = preg_replace( '/\s*;.*$/', '', $content_type );
        if ( is_string( $content_type ) && strpos( $content_type, 'image/' ) === 0 ) {
            return $content_type;
        }
    }

    if ( ! is_string( $body ) || $body === '' ) {
        return 'image/jpeg';
    }

    if ( function_exists( 'getimagesizefromstring' ) ) {
        $image_info = @getimagesizefromstring( $body );
        if ( is_array( $image_info ) && ! empty( $image_info['mime'] ) && is_string( $image_info['mime'] ) ) {
            $detected_mime = trim( strtolower( $image_info['mime'] ) );
            if ( strpos( $detected_mime, 'image/' ) === 0 ) {
                return $detected_mime;
            }
        }
    }

    if ( class_exists( 'finfo' ) ) {
        $finfo = new finfo( FILEINFO_MIME_TYPE );
        $detected_mime = $finfo->buffer( $body );
        if ( is_string( $detected_mime ) ) {
            $detected_mime = trim( strtolower( $detected_mime ) );
            if ( strpos( $detected_mime, 'image/' ) === 0 ) {
                return $detected_mime;
            }
        }
    }

    if ( strncmp( $body, "\x89PNG\r\n\x1a\n", 8 ) === 0 ) {
        return 'image/png';
    }

    if ( strncmp( $body, "\xff\xd8\xff", 3 ) === 0 ) {
        return 'image/jpeg';
    }

    if ( strncmp( $body, 'GIF87a', 6 ) === 0 || strncmp( $body, 'GIF89a', 6 ) === 0 ) {
        return 'image/gif';
    }

    if ( strncmp( $body, 'RIFF', 4 ) === 0 && substr( $body, 8, 4 ) === 'WEBP' ) {
        return 'image/webp';
    }

    if ( stripos( ltrim( $body ), '<svg' ) === 0 ) {
        return 'image/svg+xml';
    }

    return 'image/jpeg';
}

function wk_rh_get_product_image_data_uri( $location, $product_id ) {
    static $runtime_image_cache = [];

    $product_id = trim( (string) $product_id );
    if ( $product_id === '' ) {
        return '';
    }

    $runtime_key = md5( (string) $location . '|' . $product_id );
    if ( isset( $runtime_image_cache[ $runtime_key ] ) ) {
        return (string) $runtime_image_cache[ $runtime_key ];
    }

    $cache_key = 'wk_rh_img_' . md5( (string) $location . '|' . $product_id );
    $cached = get_transient( $cache_key );
    if ( is_string( $cached ) && $cached !== '' ) {
        $runtime_image_cache[ $runtime_key ] = $cached;
        return $cached;
    }

    $token = wk_rh_get_token( $location );
    $creds = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) || empty( $creds['base_url'] ) ) {
        $runtime_image_cache[ $runtime_key ] = '';
        return '';
    }

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/image/product?productId=' . rawurlencode( $product_id );
    $response = wk_rh_remote_request_with_retry(
        'GET',
        $url,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Accept'               => 'image/*',
                'Accept-Language'      => $creds['accept_language'],
                'Bmi-Subscription-Key' => $creds['subscription_key'],
            ],
            'timeout' => 30,
        ],
        1,
        [
            'operation' => 'product_image',
            'productId' => $product_id,
            'location'  => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        $runtime_image_cache[ $runtime_key ] = '';
        return '';
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        $runtime_image_cache[ $runtime_key ] = '';
        return '';
    }

    $body = wp_remote_retrieve_body( $response );
    if ( ! is_string( $body ) || $body === '' ) {
        $runtime_image_cache[ $runtime_key ] = '';
        return '';
    }

    $content_type = wp_remote_retrieve_header( $response, 'content-type' );
    $content_type = wk_rh_detect_image_content_type( $body, is_string( $content_type ) ? $content_type : '' );

    $data_uri = 'data:' . $content_type . ';base64,' . base64_encode( $body );
    set_transient( $cache_key, $data_uri, 12 * HOUR_IN_SECONDS );
    $runtime_image_cache[ $runtime_key ] = $data_uri;

    return $data_uri;
}

function wk_rh_get_product_image_html( $location, $product_id, $alt = '', $class_name = 'wk-rh-product-image' ) {
    $data_uri = wk_rh_get_product_image_data_uri( $location, $product_id );
    if ( $data_uri === '' ) {
        return '';
    }

    $classes = trim( (string) $class_name );

    return sprintf(
        '<img src="%1$s" alt="%2$s" class="%3$s" loading="lazy" />',
        esc_attr( $data_uri ),
        esc_attr( wp_strip_all_tags( (string) $alt ) ),
        esc_attr( $classes )
    );
}

function wk_rh_get_order_item_upstream_image_html( $item, $class_name = 'wk-rh-order-item-image' ) {
    if ( ! $item instanceof WC_Order_Item_Product ) {
        return '';
    }

    if ( $item->get_meta( '_wk_rh_is_addon', true ) !== 'yes' ) {
        return '';
    }

    $product_id = trim( (string) $item->get_meta( '_wk_rh_addon_upstream_id', true ) );
    if ( $product_id === '' ) {
        $product_id = trim( (string) $item->get_meta( '_wk_rh_addon_upstream_product_id', true ) );
    }

    if ( $product_id === '' ) {
        return '';
    }

    $location = trim( (string) $item->get_meta( '_wk_rh_booking_location', true ) );
    if ( $location === '' && method_exists( $item, 'get_order_id' ) ) {
        $order = wc_get_order( $item->get_order_id() );
        if ( $order instanceof WC_Order ) {
            $location = wk_rh_get_order_booking_location( $order );
        }
    }

    if ( $location === '' ) {
        return '';
    }

    return wk_rh_get_product_image_html( $location, $product_id, $item->get_name(), $class_name );
}

function wk_rh_get_cart_item_upstream_image_html( array $cart_item, $class_name = 'wk-rh-order-item-image' ) {
    if ( empty( $cart_item['is_addon'] ) ) {
        return '';
    }

    $product_id = function_exists( 'wk_rh_get_cart_item_addon_upstream_id' )
        ? wk_rh_get_cart_item_addon_upstream_id( $cart_item )
        : ( isset( $cart_item['addon_upstream_id'] ) ? trim( (string) $cart_item['addon_upstream_id'] ) : '' );

    if ( $product_id === '' ) {
        return '';
    }

    $location = isset( $cart_item['booking_location'] ) ? trim( (string) $cart_item['booking_location'] ) : '';
    if ( $location === '' ) {
        return '';
    }

    $alt = isset( $cart_item['addon_display_name'] ) ? (string) $cart_item['addon_display_name'] : '';

    return wk_rh_get_product_image_html( $location, $product_id, $alt, $class_name );
}

add_filter( 'kses_allowed_protocols', function( $protocols ) {
    if ( ! is_array( $protocols ) ) {
        return $protocols;
    }

    if ( ! in_array( 'data', $protocols, true ) ) {
        $protocols[] = 'data';
    }

    return $protocols;
} );

add_filter( 'woocommerce_cart_item_thumbnail', function( $thumbnail, $cart_item, $cart_item_key ) {
    $image_html = wk_rh_get_cart_item_upstream_image_html( is_array( $cart_item ) ? $cart_item : [], 'wk-rh-order-item-image' );

    return $image_html !== '' ? $image_html : $thumbnail;
}, 20, 3 );

add_filter( 'woocommerce_admin_order_item_thumbnail', function( $thumbnail, $item_id, $item ) {
    $image_html = wk_rh_get_order_item_upstream_image_html( $item, 'wk-rh-order-item-image' );

    return $image_html !== '' ? $image_html : $thumbnail;
}, 20, 3 );

add_filter( 'cfw_order_item_thumbnail', function( $thumbnail, $item ) {
    $image_html = wk_rh_get_order_item_upstream_image_html( $item, 'wk-rh-order-item-image' );

    return $image_html !== '' ? $image_html : $thumbnail;
}, 20, 2 );

function wk_rh_post_booking_sell( $location, $product_id, $quantity, $order_id, $parent_order_item_id = '' ) {
    $token = wk_rh_get_token( $location );
    $creds = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [ 'success' => false, 'data' => null, 'httpCode' => 0, 'rawBody' => '' ];
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
        return [ 'success' => false, 'data' => null, 'httpCode' => 0, 'rawBody' => $response->get_error_message() ];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $raw_body, true );
    if ( ! ( $code >= 200 && $code < 300 ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream booking/sell failed', [
            'operation' => 'booking_sell',
            'orderId' => (string) $order_id,
            'productId' => (string) $product_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => $raw_body,
        ] );
    }

    $body_success = ! is_array( $data ) || ! array_key_exists( 'success', $data ) || $data['success'] !== false;
    if ( $code >= 200 && $code < 300 && ! $body_success ) {
        wk_rh_log_upstream_event( 'error', 'Upstream booking/sell returned semantic failure', [
            'operation' => 'booking_sell',
            'orderId' => (string) $order_id,
            'productId' => (string) $product_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => is_array( $data ) ? $data : wp_remote_retrieve_body( $response ),
        ] );
    }

    return [
        'success' => $code >= 200 && $code < 300 && $body_success,
        'data'    => is_array( $data ) ? $data : null,
        'httpCode' => $code,
        'rawBody' => is_string( $raw_body ) ? $raw_body : '',
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

function wk_rh_send_order_memo( $order ) {
    if ( is_numeric( $order ) ) {
        $order = wc_get_order( absint( $order ) );
    }

    if ( ! $order instanceof WC_Order ) {
        return;
    }

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
    $payload = [
        'orderId' => ctype_digit( (string) $upstream_order_id ) ? (int) $upstream_order_id : (string) $upstream_order_id,
        'memo'    => $memo,
    ];

    $order->update_meta_data( 'wk_rh_memo_payload', wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    $order->save();

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
        $order->update_meta_data( 'wk_rh_memo_response', $response->get_error_message() );
        $order->save();
        return;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $response_data = json_decode( $response_body, true );
    $order->update_meta_data( 'wk_rh_memo_http_code', $code );
    $order->update_meta_data( 'wk_rh_memo_response', $response_body );

    $memo_success = ! is_array( $response_data ) || ! array_key_exists( 'success', $response_data ) || $response_data['success'] !== false;
    if ( $code >= 200 && $code < 300 && $memo_success ) {
        $order->update_meta_data( '_wk_rh_memo_synced', 'yes' );
        $order->save();
        wk_rh_log_user_event( 'order.memo_synced', [
            'wcOrderId' => (string) $order->get_id(),
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
        ] );
    } else {
        wk_rh_log_upstream_event( 'error', 'Upstream booking/memo failed', [
            'operation' => 'booking_memo',
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => is_array( $response_data ) ? $response_data : $response_body,
        ] );
        $order->save();
    }
}

function wk_rh_get_products( $token, $location = '' ) {
    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/products';

    $response = wk_rh_remote_request_with_retry(
        'GET',
        $url,
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
            'operation' => 'products_get',
            'location' => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream products request failed', [
            'operation' => 'products_get',
            'method' => 'GET',
            'url' => $url,
            'location' => (string) $location,
            'error' => $response->get_error_message(),
        ] );
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

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/availability?' . $query;

    $response = wk_rh_remote_request_with_retry(
        'GET',
        $url,
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
            'operation' => 'availability_get',
            'location' => (string) $location,
            'productId' => (int) $product_id,
        ]
    );

    if ( is_wp_error( $response ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream availability request failed', [
            'operation' => 'availability_get',
            'method' => 'GET',
            'url' => $url,
            'location' => (string) $location,
            'productId' => (int) $product_id,
            'error' => $response->get_error_message(),
        ] );
        return [];
    }
    return json_decode( wp_remote_retrieve_body( $response ), true );
}

function wk_rh_ajax_get_availability() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'my_ajax_nonce' ) ) {
        wk_rh_log_user_event( 'availability.request_rejected', [ 'reason' => 'invalid_nonce' ], 'warning' );
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    $product_id = isset( $_POST['productId'] ) ? intval( $_POST['productId'] ) : 0;
    $date_from  = isset( $_POST['dateFrom'] ) ? sanitize_text_field( $_POST['dateFrom'] ) : '';
    $date_till  = isset( $_POST['dateTill'] ) ? sanitize_text_field( $_POST['dateTill'] ) : '';

    if ( ! $product_id || ! $date_from || ! $date_till ) {
        wk_rh_log_user_event( 'availability.request_rejected', [
            'reason' => 'missing_required_fields',
            'productId' => $product_id,
            'dateFrom' => $date_from,
            'dateTill' => $date_till,
        ], 'warning' );
        wp_send_json_error( 'Missing productId/dateFrom/dateTill', 400 );
    }

    $booking_location = isset( $_POST['bookingLocation'] ) ? sanitize_text_field( $_POST['bookingLocation'] ) : '';

    $token = wk_rh_get_token( $booking_location );
    if ( ! $token ) {
        wk_rh_log_user_event( 'availability.request_failed', [
            'reason' => 'missing_token',
            'productId' => $product_id,
            'dateFrom' => $date_from,
            'dateTill' => $date_till,
            'bookingLocation' => $booking_location,
        ], 'error' );
        wp_send_json_error( 'No token', 401 );
    }

    $result = wk_rh_get_availability( $token, $product_id, $date_from, $date_till, $booking_location );
    wk_rh_log_user_event( 'availability.request_succeeded', [
        'productId' => $product_id,
        'dateFrom' => $date_from,
        'dateTill' => $date_till,
        'bookingLocation' => $booking_location,
        'resultCount' => is_array( $result ) ? count( $result ) : 0,
    ] );
    wp_send_json( $result );
}

add_action( 'wp_ajax_rh_get_availability', 'wk_rh_ajax_get_availability' );
add_action( 'wp_ajax_nopriv_rh_get_availability', 'wk_rh_ajax_get_availability' );

function wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity = 1, $location = '' ) {
    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/availability?date=' . rawurlencode( $date );

    $response = wk_rh_remote_request_with_retry(
        'POST',
        $url,
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
        ],
        1,
        [
            'operation' => 'timeslots_post',
            'location' => (string) $location,
            'productId' => (int) $product_id,
            'pageId' => (int) $page_id,
            'quantity' => (int) $quantity,
        ]
    );

    if ( is_wp_error( $response ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream timeslots request failed', [
            'operation' => 'timeslots_post',
            'method' => 'POST',
            'url' => $url,
            'location' => (string) $location,
            'productId' => (int) $product_id,
            'pageId' => (int) $page_id,
            'quantity' => (int) $quantity,
            'error' => $response->get_error_message(),
        ] );
        return [];
    }
    return json_decode( wp_remote_retrieve_body( $response ), true );
}

function wk_rh_ajax_get_timeslots() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'my_ajax_nonce' ) ) {
        wk_rh_log_user_event( 'timeslots.request_rejected', [ 'reason' => 'invalid_nonce' ], 'warning' );
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    $product_id = isset( $_POST['productId'] ) ? intval( $_POST['productId'] ) : 0;
    $date       = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
    $quantity   = isset( $_POST['quantity'] ) ? max( 0, intval( $_POST['quantity'] ) ) : 0;
    $booking_location = isset( $_POST['bookingLocation'] ) ? sanitize_text_field( $_POST['bookingLocation'] ) : '';
    if ( ! $product_id || ! $date ) {
        wk_rh_log_user_event( 'timeslots.request_rejected', [
            'reason' => 'missing_required_fields',
            'productId' => $product_id,
            'date' => $date,
            'quantity' => $quantity,
        ], 'warning' );
        wp_send_json_error( 'Missing productId or date', 400 );
    }

    $token = wk_rh_get_token( $booking_location );
    if ( ! $token ) {
        wk_rh_log_user_event( 'timeslots.request_failed', [
            'reason' => 'missing_token',
            'productId' => $product_id,
            'date' => $date,
            'quantity' => $quantity,
            'bookingLocation' => $booking_location,
        ], 'error' );
        wp_send_json_error( 'No token', 401 );
    }

    $creds = wk_rh_get_api_credentials( $booking_location );
    $pages_url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/page?date=' . rawurlencode( $date . 'T00:00:00.000Z' );

    $pages_response = wk_rh_remote_request_with_retry(
        'GET',
        $pages_url,
        [
            'headers' => [
                'Authorization'        => 'Bearer ' . $token,
                'Bmi-Subscription-Key' => $creds['subscription_key'],
                'Content-Type'         => 'application/json',
                'Accept-Language'      => $creds['accept_language'],
            ],
        ],
        1,
        [
            'operation' => 'page_get',
            'location' => (string) $booking_location,
            'productId' => (int) $product_id,
            'quantity' => (int) $quantity,
        ]
    );

    if ( is_wp_error( $pages_response ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream page lookup failed', [
            'operation' => 'page_get',
            'method' => 'GET',
            'url' => $pages_url,
            'location' => (string) $booking_location,
            'productId' => (int) $product_id,
            'quantity' => (int) $quantity,
            'error' => $pages_response->get_error_message(),
        ] );
        wk_rh_log_user_event( 'timeslots.request_failed', [
            'reason' => 'page_lookup_error',
            'productId' => $product_id,
            'date' => $date,
            'quantity' => $quantity,
            'bookingLocation' => $booking_location,
            'error' => $pages_response->get_error_message(),
        ], 'error' );
        wp_send_json_error( 'API error: ' . $pages_response->get_error_message(), 500 );
    }
    $pages = json_decode( wp_remote_retrieve_body( $pages_response ), true );
    if ( ! is_array( $pages ) ) {
        wk_rh_log_user_event( 'timeslots.request_failed', [
            'reason' => 'invalid_page_response',
            'productId' => $product_id,
            'date' => $date,
            'quantity' => $quantity,
            'bookingLocation' => $booking_location,
        ], 'error' );
        wp_send_json_error( 'Invalid API response', 500 );
    }

    $page_id = null;
    $matched_product = null;
    $matched_page_products = [];
    foreach ( $pages as $page ) {
        if ( empty( $page['products'] ) || ! is_array( $page['products'] ) ) {
            continue;
        }
        foreach ( $page['products'] as $prod ) {
            if ( isset( $prod['id'] ) && (int) $prod['id'] === $product_id ) {
                $page_id = $page['id'];
                $matched_product = is_array( $prod ) ? $prod : null;
                $matched_page_products = $page['products'];
                break 2;
            }
        }
    }

    if ( ! $page_id ) {
        wk_rh_log_user_event( 'timeslots.request_failed', [
            'reason' => 'page_not_found',
            'productId' => $product_id,
            'date' => $date,
            'quantity' => $quantity,
            'bookingLocation' => $booking_location,
        ], 'warning' );
        wp_send_json_error( 'No page found for product/date', 404 );
    }

    $timeslots = $quantity > 0
        ? wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity, $booking_location )
        : [ 'proposals' => [] ];
    if ( is_array( $timeslots ) ) {
        $timeslots['pageId'] = (string) $page_id;
        $timeslots['pageProductLimits'] = [
            'minAmount' => isset( $matched_product['minAmount'] ) ? $matched_product['minAmount'] : null,
            'maxAmount' => isset( $matched_product['maxAmount'] ) ? $matched_product['maxAmount'] : null,
        ];
        $timeslots['pageProducts'] = is_array( $matched_page_products ) ? array_values( $matched_page_products ) : [];
        if ( $quantity <= 0 ) {
            $timeslots['metadataOnly'] = true;
        }
    }
    wk_rh_log_user_event( 'timeslots.request_succeeded', [
        'productId' => $product_id,
        'date' => $date,
        'quantity' => $quantity,
        'bookingLocation' => $booking_location,
        'pageId' => $page_id,
        'metadataOnly' => $quantity <= 0,
        'proposalCount' => isset( $timeslots['proposals'] ) && is_array( $timeslots['proposals'] ) ? count( $timeslots['proposals'] ) : 0,
    ] );
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
    if ( isset( $_POST['booking_twin'] ) ) {
        $cart_item_data['booking_twin'] = max( 0, intval( $_POST['booking_twin'] ) );
    }
    return $cart_item_data;
}

function wk_rh_get_booking_participant_counts( array $source ) {
    return [
        'adults'   => isset( $source['booking_adults'] ) ? max( 0, (int) $source['booking_adults'] ) : 0,
        'children' => isset( $source['booking_children'] ) ? max( 0, (int) $source['booking_children'] ) : 0,
        'twin'     => isset( $source['booking_twin'] ) ? max( 0, (int) $source['booking_twin'] ) : 0,
    ];
}

function wk_rh_get_default_booking_quantity_rules() {
    return [
        'adults' => [ 'min' => 0, 'max' => null, 'step' => 1 ],
        'kids'   => [ 'min' => 0, 'max' => null, 'step' => 1 ],
        'twin'   => [ 'min' => 0, 'max' => null, 'step' => 1 ],
        'total'  => [ 'min' => 1, 'max' => null, 'step' => 1 ],
    ];
}

function wk_rh_get_booking_rule_number_from_keys( $source, array $keys, $default = null ) {
    if ( ! is_array( $source ) ) {
        return $default;
    }

    foreach ( $keys as $key ) {
        if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) {
            return (float) $source[ $key ];
        }
    }

    return $default;
}

function wk_rh_get_booking_group_step( array $group ) {
    foreach ( [ 'step', 'stepQuantity', 'quantityStep', 'stepSize', 'increment' ] as $key ) {
        if ( isset( $group[ $key ] ) && is_numeric( $group[ $key ] ) && (float) $group[ $key ] > 0 ) {
            return (int) round( (float) $group[ $key ] );
        }
    }

    return 1;
}

function wk_rh_get_booking_group_target_key( array $group ) {
    $candidates = [];

    if ( isset( $group['tag'] ) ) {
        $candidates[] = trim( strtolower( (string) $group['tag'] ) );
    }

    if ( isset( $group['name'] ) ) {
        $normalized_name = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $group['name'] ) );
        $normalized_name = trim( preg_replace( '/\s+/', ' ', $normalized_name ) );
        if ( $normalized_name !== '' ) {
            $candidates[] = $normalized_name;
        }
    }

    foreach ( $candidates as $candidate ) {
        if ( $candidate === '' ) {
            continue;
        }

        if ( in_array( $candidate, [ 'adults', 'adult', 'voksne' ], true ) || strpos( $candidate, 'adult' ) !== false || strpos( $candidate, 'over 150' ) !== false ) {
            return 'adults';
        }

        if ( in_array( $candidate, [ 'kids', 'children', 'child', 'born', 'børn' ], true ) || strpos( $candidate, 'child' ) !== false || strpos( $candidate, 'kid' ) !== false || strpos( $candidate, 'under 150' ) !== false ) {
            return 'kids';
        }

        if ( in_array( $candidate, [ 'twin', 'twinkart', 'tandem', 'passenger' ], true ) || strpos( $candidate, 'twin' ) !== false || strpos( $candidate, 'passenger' ) !== false ) {
            return 'twin';
        }
    }

    return null;
}

function wk_rh_get_selected_page_product( array $page_products, $product_id ) {
    $product_id = trim( (string) $product_id );
    if ( $product_id === '' ) {
        return null;
    }

    foreach ( $page_products as $page_product ) {
        if ( ! is_array( $page_product ) ) {
            continue;
        }

        $page_product_id = isset( $page_product['id'] ) ? trim( (string) $page_product['id'] ) : '';
        if ( $page_product_id !== '' && $page_product_id === $product_id ) {
            return $page_product;
        }
    }

    return null;
}

function wk_rh_extract_quantity_rules( $proposal, $page_product_limits = null, $page_products = [], $product_id = '' ) {
    $rules = wk_rh_get_default_booking_quantity_rules();
    $page_product_limits = is_array( $page_product_limits ) ? $page_product_limits : [];
    $page_products = is_array( $page_products ) ? array_values( $page_products ) : [];
    $proposal = is_array( $proposal ) ? $proposal : [];

    $page_min_amount = wk_rh_get_booking_rule_number_from_keys( $page_product_limits, [ 'minAmount', 'minimumAmount' ], null );
    $page_max_amount = wk_rh_get_booking_rule_number_from_keys( $page_product_limits, [ 'maxAmount', 'maximumAmount' ], null );
    $proposal_min = wk_rh_get_booking_rule_number_from_keys( $proposal, [ 'minQuantity', 'minQty', 'minimumQuantity', 'minimumQty' ], null );
    $proposal_max = wk_rh_get_booking_rule_number_from_keys( $proposal, [ 'maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty' ], null );
    $proposal_min_amount = wk_rh_get_booking_rule_number_from_keys( $proposal, [ 'minAmount', 'minimumAmount' ], null );
    $proposal_max_amount = wk_rh_get_booking_rule_number_from_keys( $proposal, [ 'maxAmount', 'maximumAmount' ], null );

    if ( $page_min_amount !== null && $page_min_amount > 0 ) {
        $rules['total']['min'] = max( (int) $rules['total']['min'], (int) round( $page_min_amount ) );
    }

    if ( $page_max_amount !== null && $page_max_amount > 0 ) {
        $rules['total']['max'] = (int) round( $page_max_amount );
    }

    foreach ( [ $proposal_min, $proposal_min_amount ] as $proposal_floor ) {
        if ( $proposal_floor !== null && $proposal_floor > 0 ) {
            $rules['total']['min'] = max( (int) $rules['total']['min'], (int) round( $proposal_floor ) );
        }
    }

    foreach ( [ $proposal_max, $proposal_max_amount ] as $proposal_ceiling ) {
        if ( $proposal_ceiling === null || $proposal_ceiling <= 0 ) {
            continue;
        }

        $resolved_ceiling = (int) round( $proposal_ceiling );
        $rules['total']['max'] = $rules['total']['max'] === null
            ? $resolved_ceiling
            : min( (int) $rules['total']['max'], $resolved_ceiling );
    }

    $groups = [];
    if ( isset( $proposal['dynamicGroups'] ) && is_array( $proposal['dynamicGroups'] ) && ! empty( $proposal['dynamicGroups'] ) ) {
        $groups = $proposal['dynamicGroups'];
    } else {
        $page_product = wk_rh_get_selected_page_product( $page_products, $product_id );
        if ( is_array( $page_product ) && isset( $page_product['dynamicGroups'] ) && is_array( $page_product['dynamicGroups'] ) ) {
            $groups = $page_product['dynamicGroups'];
        }
    }

    foreach ( $groups as $group ) {
        if ( ! is_array( $group ) ) {
            continue;
        }

        $target_key = wk_rh_get_booking_group_target_key( $group );
        if ( $target_key === null ) {
            continue;
        }

        $min = wk_rh_get_booking_rule_number_from_keys( $group, [ 'minQuantity', 'minQty', 'minimumQuantity', 'minimumQty' ], null );
        $max = wk_rh_get_booking_rule_number_from_keys( $group, [ 'maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty' ], null );

        if ( $min !== null && $min >= 0 ) {
            $rules[ $target_key ]['min'] = max( 0, (int) round( $min ) );
        }

        if ( $max !== null && $max >= 0 ) {
            $rules[ $target_key ]['max'] = max( 0, (int) round( $max ) );
        }

        $rules[ $target_key ]['step'] = wk_rh_get_booking_group_step( $group );
    }

    return $rules;
}

function wk_rh_get_booking_total_participants( array $counts ) {
    return max( 0, (int) ( $counts['adults'] ?? 0 ) ) + max( 0, (int) ( $counts['children'] ?? 0 ) ) + max( 0, (int) ( $counts['twin'] ?? 0 ) );
}

function wk_rh_format_booking_participants_text( array $source ) {
    $counts = wk_rh_get_booking_participant_counts( $source );

    return sprintf(
        'Voksen kart: %1$d, Børnekart: %2$d, Twin kart: %3$d',
        $counts['adults'],
        $counts['children'],
        $counts['twin']
    );
}

function wk_rh_extract_quantity_rules_from_proposal( $proposal ) {
    return wk_rh_extract_quantity_rules( $proposal, null, [], '' );
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

    $rules = wk_rh_extract_quantity_rules(
        $session_booking['proposal'],
        $session_booking['pageProductLimits'] ?? null,
        $session_booking['pageProducts'] ?? [],
        $session_booking['productId'] ?? ''
    );

    $adults = isset( $_POST['booking_adults'] ) ? max( 0, (int) $_POST['booking_adults'] ) : null;
    $kids   = isset( $_POST['booking_children'] ) ? max( 0, (int) $_POST['booking_children'] ) : null;
    $twin   = isset( $_POST['booking_twin'] ) ? max( 0, (int) $_POST['booking_twin'] ) : null;
    $qty    = max( 1, (int) $quantity );

    if ( $adults === null && $kids === null && $twin === null ) {
        return $passed;
    }
    if ( $adults === null ) {
        $adults = 0;
    }
    if ( $kids === null ) {
        $kids = 0;
    }
    if ( $twin === null ) {
        $twin = 0;
    }

    $group_checks = [
        [ 'name' => __( 'Adults', 'onsite-booking-system' ), 'value' => $adults, 'rules' => $rules['adults'] ],
        [ 'name' => __( 'Children', 'onsite-booking-system' ), 'value' => $kids, 'rules' => $rules['kids'] ],
        [ 'name' => __( 'Twin kart', 'racehall-wc-ui' ), 'value' => $twin, 'rules' => $rules['twin'] ],
    ];

    foreach ( $group_checks as $check ) {
        $min = (int) $check['rules']['min'];
        $max = isset( $check['rules']['max'] ) ? $check['rules']['max'] : null;
        $step = (int) $check['rules']['step'];

        if ( $check['value'] < $min ) {
            wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'group' => $check['name'], 'reason' => 'below_min', 'value' => $check['value'], 'min' => $min, 'productId' => $product_id ] , 'warning' );
            wc_add_notice( sprintf( __( '%s must be at least %d.', 'onsite-booking-system' ), $check['name'], $min ), 'error' );
            return false;
        }
        if ( $max !== null && $check['value'] > (int) $max ) {
            wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'group' => $check['name'], 'reason' => 'above_max', 'value' => $check['value'], 'max' => (int) $max, 'productId' => $product_id ], 'warning' );
            wc_add_notice( sprintf( __( '%s cannot exceed %d.', 'onsite-booking-system' ), $check['name'], (int) $max ), 'error' );
            return false;
        }
        if ( ! wk_rh_rule_value_matches_step( $check['value'], $min, $step ) ) {
            wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'group' => $check['name'], 'reason' => 'step_mismatch', 'value' => $check['value'], 'min' => $min, 'step' => $step, 'productId' => $product_id ], 'warning' );
            wc_add_notice( sprintf( __( '%s quantity must follow step %d starting from %d.', 'onsite-booking-system' ), $check['name'], max( 1, $step ), $min ), 'error' );
            return false;
        }
    }

    $counts = [
        'adults' => $adults,
        'children' => $kids,
        'twin' => $twin,
    ];

    $total = wk_rh_get_booking_total_participants( $counts );
    if ( $total !== $qty ) {
        wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'reason' => 'total_quantity_mismatch', 'total' => $total, 'quantity' => $qty, 'productId' => $product_id ], 'warning' );
        wc_add_notice( __( 'Participant quantities do not match selected booking quantity.', 'onsite-booking-system' ), 'error' );
        return false;
    }

    $total_min = (int) $rules['total']['min'];
    $total_max = isset( $rules['total']['max'] ) ? $rules['total']['max'] : null;
    $total_step = (int) $rules['total']['step'];

    if ( $total < $total_min ) {
        wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'reason' => 'total_below_min', 'total' => $total, 'min' => $total_min, 'productId' => $product_id ], 'warning' );
        wc_add_notice( sprintf( __( 'Total participants must be at least %d.', 'onsite-booking-system' ), $total_min ), 'error' );
        return false;
    }
    if ( $total_max !== null && $total > (int) $total_max ) {
        wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'reason' => 'total_above_max', 'total' => $total, 'max' => (int) $total_max, 'productId' => $product_id ], 'warning' );
        wc_add_notice( sprintf( __( 'Total participants cannot exceed %d.', 'onsite-booking-system' ), (int) $total_max ), 'error' );
        return false;
    }
    if ( ! wk_rh_rule_value_matches_step( $total, $total_min, $total_step ) ) {
        wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'reason' => 'total_step_mismatch', 'total' => $total, 'min' => $total_min, 'step' => $total_step, 'productId' => $product_id ], 'warning' );
        wc_add_notice( sprintf( __( 'Total participants must follow step %d starting from %d.', 'onsite-booking-system' ), max( 1, $total_step ), $total_min ), 'error' );
        return false;
    }

    return $passed;
}

add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_validate_main_booking_selection', 25, 3 );
function wk_rh_restore_booking_session_from_post( $bm_id ) {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return null;
    }

    $proposal_json = isset( $_POST['booking_proposal'] ) ? wp_unslash( (string) $_POST['booking_proposal'] ) : '';
    if ( $proposal_json === '' ) {
        return null;
    }

    $proposal = json_decode( $proposal_json, true );
    if ( ! is_array( $proposal ) || empty( $proposal ) ) {
        return null;
    }

    $page_id = isset( $_POST['booking_page_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['booking_page_id'] ) ) : '';
    $resource_id = isset( $_POST['booking_resource_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['booking_resource_id'] ) ) : '';
    $product_id = isset( $_POST['booking_product_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['booking_product_id'] ) ) : '';
    $page_product_limits = isset( $_POST['booking_page_product_limits'] ) ? json_decode( wp_unslash( (string) $_POST['booking_page_product_limits'] ), true ) : null;
    $page_products = isset( $_POST['booking_page_products'] ) ? json_decode( wp_unslash( (string) $_POST['booking_page_products'] ), true ) : null;
    $booking_location = isset( $_POST['booking_location'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['booking_location'] ) ) : '';
    $quantity = isset( $_POST['booking_quantity'] ) ? max( 1, intval( $_POST['booking_quantity'] ) ) : 1;

    if ( $product_id === '' ) {
        $product_id = (string) $bm_id;
    }

    if ( $page_id === '' || $resource_id === '' ) {
        return null;
    }

    $session_booking = [
        'proposal'        => $proposal,
        'pageId'          => $page_id,
        'resourceId'      => $resource_id,
        'productId'       => $product_id,
        'quantity'        => $quantity,
        'pageProductLimits' => is_array( $page_product_limits ) ? $page_product_limits : null,
        'pageProducts'    => is_array( $page_products ) ? array_values( $page_products ) : [],
        'bookingLocation' => $booking_location,
        'orderId'         => '',
        'orderItemId'     => '',
        'expiresAt'       => '',
    ];

    WC()->session->set( 'rh_bmi_booking', $session_booking );

    if ( ! WC()->session->get( 'booking_supplement' ) ) {
        WC()->session->set( 'booking_supplement', [
            'supplements' => [],
        ] );
    }

    return $session_booking;
}

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
        wk_rh_log_user_event( 'booking.selection_validation_failed', [ 'reason' => 'missing_date_or_time', 'productId' => $product_id ], 'warning' );
        wc_add_notice( __( 'Vælg venligst både dato og tidspunkt før du tilføjer til kurv.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        wk_rh_log_user_event( 'booking.selection_validation_failed', [ 'reason' => 'missing_session', 'productId' => $product_id ], 'error' );
        wc_add_notice( __( 'Booking session mangler. Opdater siden og prøv igen.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $session_booking = WC()->session->get( 'rh_bmi_booking' );
    if ( ! is_array( $session_booking ) || empty( $session_booking['proposal'] ) ) {
        $session_booking = wk_rh_restore_booking_session_from_post( $bm_id );
        if ( ! is_array( $session_booking ) || empty( $session_booking['proposal'] ) ) {
            wk_rh_log_user_event( 'booking.selection_validation_failed', [ 'reason' => 'missing_proposal', 'productId' => $product_id, 'bmProductId' => $bm_id ], 'warning' );
            wc_add_notice( __( 'Vælg et gyldigt tidspunkt før du tilføjer til kurv.', 'racehall-wc-ui' ), 'error' );
            return false;
        }
    }

    $session_page_id = isset( $session_booking['pageId'] ) ? trim( (string) $session_booking['pageId'] ) : '';
    $session_resource_id = isset( $session_booking['resourceId'] ) ? trim( (string) $session_booking['resourceId'] ) : '';
    if ( $session_page_id === '' || $session_resource_id === '' ) {
        wk_rh_log_user_event( 'booking.selection_validation_failed', [ 'reason' => 'missing_page_or_resource_id', 'productId' => $product_id, 'bmProductId' => $bm_id ], 'warning' );
        wc_add_notice( __( 'Bookingdata mangler. Vælg tidspunkt igen før du tilføjer til kurv.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $session_product_id = isset( $session_booking['productId'] ) ? (string) $session_booking['productId'] : '';
    if ( $session_product_id !== '' && $session_product_id !== (string) $bm_id ) {
        wk_rh_log_user_event( 'booking.selection_validation_failed', [ 'reason' => 'product_mismatch', 'productId' => $product_id, 'sessionProductId' => $session_product_id, 'bmProductId' => (string) $bm_id ], 'warning' );
        wc_add_notice( __( 'Den valgte tid matcher ikke produktet. Vælg tidspunkt igen.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    wk_rh_log_user_event( 'booking.selection_validated', [
        'productId' => $product_id,
        'bmProductId' => (string) $bm_id,
        'bookingDate' => $booking_date,
        'bookingTime' => $booking_time,
    ] );

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
    if ( isset( $cart_item['booking_adults'] ) || isset( $cart_item['booking_children'] ) || isset( $cart_item['booking_twin'] ) ) {
        $item_data[] = [ 'name' => 'Deltagere', 'value' => wk_rh_format_booking_participants_text( $cart_item ) ];
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

    $has_people = isset( $cart_item['booking_adults'] ) || isset( $cart_item['booking_children'] ) || isset( $cart_item['booking_twin'] );
    if ( $has_people ) {
        $parts[]  = 'Deltagere: ' . wk_rh_format_booking_participants_text( $cart_item );
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

    if ( ! empty( $cart_item['is_addon'] ) && ! empty( $cart_item['addon_display_name'] ) ) {
        $product_name = esc_html( sanitize_text_field( (string) $cart_item['addon_display_name'] ) );
    }

    $details_text = wk_rh_get_checkout_booking_details_text( $cart_item );
    if ( $details_text === '' ) {
        return $product_name;
    }

    return $product_name . '<br><small class="wk-rh-checkout-booking-details">' . esc_html( $details_text ) . '</small>';
}

add_filter( 'woocommerce_order_item_name', 'wk_rh_prepend_addon_image_to_order_item_name', 10, 3 );
function wk_rh_prepend_addon_image_to_order_item_name( $item_name, $item, $is_visible ) {
    if ( ! $item instanceof WC_Order_Item_Product ) {
        return $item_name;
    }

    if ( is_admin() && ! wp_doing_ajax() ) {
        return $item_name;
    }

    if ( $item->get_meta( '_wk_rh_is_addon', true ) !== 'yes' ) {
        return $item_name;
    }

    if ( strpos( (string) $item_name, 'wk-rh-order-item-image' ) !== false ) {
        return $item_name;
    }

    $image_html = wk_rh_get_order_item_upstream_image_html( $item );
    if ( $image_html === '' ) {
        return $item_name;
    }

    return '<span class="wk-rh-order-item-with-image">' . $image_html . '<span class="wk-rh-order-item-name">' . $item_name . '</span></span>';
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
    if ( isset( $values['booking_twin'] ) ) {
        $item->add_meta_data( 'Twin kart', (int) $values['booking_twin'], true );
    }
}

add_action( 'wp_ajax_rh_save_proposal', 'wk_rh_save_proposal' );
add_action( 'wp_ajax_nopriv_rh_save_proposal', 'wk_rh_save_proposal' );
function wk_rh_save_proposal() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'my_ajax_nonce' ) ) {
        wk_rh_log_user_event( 'proposal.save_rejected', [ 'reason' => 'invalid_nonce' ], 'warning' );
        wp_send_json_error( 'Invalid nonce', 403 );
    }

    $proposal    = isset( $_POST['proposal'] ) ? json_decode( stripslashes( $_POST['proposal'] ), true ) : null;
    $page_id     = isset( $_POST['pageId'] ) ? sanitize_text_field( $_POST['pageId'] ) : '';
    $resource_id = isset( $_POST['resourceId'] ) ? sanitize_text_field( $_POST['resourceId'] ) : '';
    $product_id  = isset( $_POST['productId'] ) ? sanitize_text_field( $_POST['productId'] ) : '';
    $quantity    = isset( $_POST['quantity'] ) ? max( 1, intval( $_POST['quantity'] ) ) : 1;
    $page_product_limits = isset( $_POST['pageProductLimits'] ) ? json_decode( stripslashes( (string) $_POST['pageProductLimits'] ), true ) : null;
    $page_products = isset( $_POST['pageProducts'] ) ? json_decode( stripslashes( (string) $_POST['pageProducts'] ), true ) : null;
    $booking_location = isset( $_POST['bookingLocation'] ) ? sanitize_text_field( $_POST['bookingLocation'] ) : '';

    if ( empty( $proposal ) ) {
        wk_rh_log_user_event( 'proposal.save_rejected', [ 'reason' => 'missing_proposal', 'productId' => $product_id, 'pageId' => $page_id, 'resourceId' => $resource_id ], 'warning' );
        wp_send_json_error( 'Missing proposal', 400 );
    }

    if ( $page_id === '' || $resource_id === '' ) {
        wk_rh_log_user_event( 'proposal.save_rejected', [ 'reason' => 'missing_page_or_resource_id', 'productId' => $product_id, 'pageId' => $page_id, 'resourceId' => $resource_id ], 'warning' );
        wp_send_json_error( 'Missing pageId or resourceId', 400 );
    }

    if ( WC()->session ) {
        WC()->session->set( 'rh_bmi_booking', [
            'proposal'        => $proposal,
            'pageId'          => $page_id,
            'resourceId'      => $resource_id,
            'productId'       => $product_id,
            'quantity'        => $quantity,
            'pageProductLimits' => is_array( $page_product_limits ) ? $page_product_limits : null,
            'pageProducts'    => is_array( $page_products ) ? array_values( $page_products ) : [],
            'bookingLocation' => $booking_location,
            'orderId'         => '',
            'orderItemId'     => '',
            'expiresAt'       => '',
        ] );

        WC()->session->set( 'booking_supplement', [
            'supplements' => [],
        ] );
    }

    wk_rh_log_user_event( 'proposal.saved', [
        'productId' => $product_id,
        'pageId' => $page_id,
        'resourceId' => $resource_id,
        'quantity' => $quantity,
        'bookingLocation' => $booking_location,
        'pageProductsCount' => is_array( $page_products ) ? count( $page_products ) : 0,
    ] );

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
    wk_rh_log_user_event( 'order.payment_confirmed', [
        'wcOrderId' => (string) $order->get_id(),
        'orderId' => (string) wk_rh_get_order_upstream_order_id( $order ),
        'location' => (string) wk_rh_get_order_booking_location( $order ),
    ] );

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
        wk_rh_log_user_event( 'order.payment_confirm_failed', [
            'wcOrderId' => (string) $order->get_id(),
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
            'error' => $response->get_error_message(),
        ], 'error' );
        return;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $response_data = json_decode( $response_body, true );
    $payment_success = ! is_array( $response_data ) || ! array_key_exists( 'success', $response_data ) || $response_data['success'] !== false;

    if ( $code >= 200 && $code < 300 && $payment_success ) {
        wk_rh_mark_payment_confirmed( $order, $response_body );
    } else {
        $error_message = 'Onsite booking payment/confirm failed';
        if ( is_array( $response_data ) && ! empty( $response_data['errormessage'] ) ) {
            $error_message .= ': ' . sanitize_text_field( (string) $response_data['errormessage'] );
        } else {
            $error_message .= ' with HTTP ' . $code;
        }

        wk_rh_log_user_event( 'order.payment_confirm_failed', [
            'wcOrderId' => (string) $order->get_id(),
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => is_array( $response_data ) ? $response_data : $response_body,
        ], 'error' );
        wk_rh_log_upstream_event( 'error', 'Upstream payment/confirm failed', [
            'operation' => 'payment_confirm',
            'orderId' => (string) $upstream_order_id,
            'wcOrderId' => (string) $order->get_id(),
            'location' => (string) $location,
            'httpCode' => $code,
            'body' => is_array( $response_data ) ? $response_data : $response_body,
        ] );
        $order->add_order_note( $error_message );
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
            wk_rh_log_user_event( 'order.cancel_synced', [
                'orderId' => (string) $upstream_order_id,
                'location' => (string) $location,
                'path' => (string) $path,
                'extra' => $extra_context,
            ] );
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
