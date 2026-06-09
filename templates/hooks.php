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

function wk_rh_is_initial_calendar_month_request( $date_from, $date_till ) {
    $range = function_exists( 'wk_rh_get_availability_cache_date_range' )
        ? wk_rh_get_availability_cache_date_range()
        : [ 'dateFrom' => '', 'dateTill' => '' ];

    return (string) $date_from === $range['dateFrom'] && (string) $date_till === $range['dateTill'];
}

function wk_rh_get_token( $location = '', array $creds_override = [] ) {
    static $runtime_cache = [];

    $creds = ! empty( $creds_override ) ? $creds_override : wk_rh_get_api_credentials( $location );
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
            'clientKey' => (string) $creds['client_key'],
        ]
    );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $data = function_exists( 'wk_rh_decode_api_response_body' ) ? wk_rh_decode_api_response_body( $response ) : json_decode( wp_remote_retrieve_body( $response ), true );
    $token = isset( $data['accessToken'] ) ? (string) $data['accessToken'] : '';
    if ( $token === '' ) {
        return false;
    }

    $expires_in = isset( $data['expiresIn'] ) && is_numeric( $data['expiresIn'] ) ? (int) $data['expiresIn'] : 3600;
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

/**
 * Best-effort downscale of a product image to a thumbnail before it is cached/served.
 * BMI returns full-resolution images, but they are only ever shown as small thumbnails,
 * so this drastically cuts transfer size. Falls back to the original bytes if the GD
 * image functions are unavailable or anything goes wrong.
 *
 * @return array{0:string,1:string} [ binary, content_type ]
 */
function wk_rh_maybe_downscale_image( $binary, $content_type, $max_dimension = 480 ) {
    if ( ! function_exists( 'imagecreatefromstring' ) || ! is_string( $binary ) || $binary === '' ) {
        return [ $binary, $content_type ];
    }

    $src = @imagecreatefromstring( $binary );
    if ( ! $src ) {
        return [ $binary, $content_type ];
    }

    $width  = imagesx( $src );
    $height = imagesy( $src );
    if ( $width <= $max_dimension && $height <= $max_dimension ) {
        imagedestroy( $src );
        return [ $binary, $content_type ];
    }

    $ratio      = min( $max_dimension / $width, $max_dimension / $height );
    $new_width  = max( 1, (int) round( $width * $ratio ) );
    $new_height = max( 1, (int) round( $height * $ratio ) );

    $dst = imagecreatetruecolor( $new_width, $new_height );
    imagealphablending( $dst, false );
    imagesavealpha( $dst, true );
    imagecopyresampled( $dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height );

    ob_start();
    if ( strpos( (string) $content_type, 'png' ) !== false ) {
        imagepng( $dst, null, 6 );
        $out_type = 'image/png';
    } elseif ( strpos( (string) $content_type, 'webp' ) !== false && function_exists( 'imagewebp' ) ) {
        imagewebp( $dst, null, 82 );
        $out_type = 'image/webp';
    } else {
        imagejpeg( $dst, null, 82 );
        $out_type = 'image/jpeg';
    }
    $resized = ob_get_clean();

    imagedestroy( $src );
    imagedestroy( $dst );

    if ( ! is_string( $resized ) || $resized === '' ) {
        return [ $binary, $content_type ];
    }

    return [ $resized, $out_type ];
}

function wk_rh_get_product_image_data_uri( $location, $product_id ) {
    static $runtime_image_cache = [];

    $product_id = trim( (string) $product_id );
    if ( $product_id === '' ) {
        return '';
    }

    $runtime_key = md5( 'v2|' . (string) $location . '|' . $product_id );
    if ( isset( $runtime_image_cache[ $runtime_key ] ) ) {
        return (string) $runtime_image_cache[ $runtime_key ];
    }

    $cache_key = 'wk_rh_img_' . md5( 'v2|' . (string) $location . '|' . $product_id );
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

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'image/product', [ 'productId' => $product_id ] )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/image/product?productId=' . rawurlencode( $product_id );
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

    list( $body, $content_type ) = wk_rh_maybe_downscale_image( $body, $content_type );

    $data_uri = 'data:' . $content_type . ';base64,' . base64_encode( $body );
    set_transient( $cache_key, $data_uri, 12 * HOUR_IN_SECONDS );
    $runtime_image_cache[ $runtime_key ] = $data_uri;

    return $data_uri;
}

/**
 * Filesystem cache for downscaled product images. After the first fetch each image is
 * a plain static file served by the web server — no WordPress boot, no BMI round-trip —
 * and fully browser/CDN cacheable.
 */
function wk_rh_get_addon_image_cache_paths() {
    static $paths = null;
    if ( $paths !== null ) {
        return $paths;
    }

    $uploads = wp_get_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        $paths = false;
        return $paths;
    }

    $paths = [
        'dir' => trailingslashit( $uploads['basedir'] ) . 'wk-rh-addon-images',
        'url' => trailingslashit( $uploads['baseurl'] ) . 'wk-rh-addon-images',
    ];

    return $paths;
}

function wk_rh_get_addon_image_basename( $location, $product_id ) {
    return md5( 'v2|' . (string) $location . '|' . (string) $product_id );
}

function wk_rh_image_extension_from_content_type( $content_type ) {
    $content_type = strtolower( (string) $content_type );
    if ( strpos( $content_type, 'png' ) !== false ) {
        return 'png';
    }
    if ( strpos( $content_type, 'webp' ) !== false ) {
        return 'webp';
    }
    if ( strpos( $content_type, 'gif' ) !== false ) {
        return 'gif';
    }
    return 'jpg';
}

function wk_rh_find_cached_addon_image_url( $location, $product_id ) {
    $paths = wk_rh_get_addon_image_cache_paths();
    if ( ! $paths ) {
        return '';
    }

    $base = wk_rh_get_addon_image_basename( $location, $product_id );
    foreach ( [ 'jpg', 'png', 'webp', 'gif' ] as $ext ) {
        if ( file_exists( $paths['dir'] . '/' . $base . '.' . $ext ) ) {
            return $paths['url'] . '/' . $base . '.' . $ext;
        }
    }

    return '';
}

/**
 * URL for a BMI product image. Prefers the cached static file (fast, no PHP); otherwise
 * returns a signed proxy URL that fetches once, writes the static file, and redirects to
 * it. The signature (site auth salt) prevents the proxy fetching arbitrary product ids.
 */
function wk_rh_get_product_image_url( $location, $product_id ) {
    $product_id = trim( (string) $product_id );
    $location   = (string) $location;
    if ( $product_id === '' ) {
        return '';
    }

    $cached_url = wk_rh_find_cached_addon_image_url( $location, $product_id );
    if ( $cached_url !== '' ) {
        return $cached_url;
    }

    return add_query_arg(
        [
            'wk_rh_img' => 1,
            'loc'       => rawurlencode( $location ),
            'pid'       => rawurlencode( $product_id ),
            'sig'       => hash_hmac( 'sha256', $location . '|' . $product_id, wp_salt( 'auth' ) ),
        ],
        home_url( '/' )
    );
}

function wk_rh_get_product_image_html( $location, $product_id, $alt = '', $class_name = 'wk-rh-product-image' ) {
    $cached_url = wk_rh_find_cached_addon_image_url( $location, $product_id );

    // If not cached yet, confirm the image exists upstream before emitting an <img> (so
    // we never render a broken image). Once a static file exists, skip the fetch entirely.
    if ( $cached_url === '' && wk_rh_get_product_image_data_uri( $location, $product_id ) === '' ) {
        return '';
    }

    $url = $cached_url !== '' ? $cached_url : wk_rh_get_product_image_url( $location, $product_id );
    if ( $url === '' ) {
        return '';
    }

    return sprintf(
        '<img src="%1$s" alt="%2$s" class="%3$s" loading="lazy" />',
        esc_url( $url ),
        esc_attr( wp_strip_all_tags( (string) $alt ) ),
        esc_attr( trim( (string) $class_name ) )
    );
}

/**
 * Cacheable image proxy: serves the BMI product image behind the signed URL above.
 * Fetches server-side (creds stay server-side), reuses the existing 12h transient
 * cache, and sends public cache headers so the browser/CDN cache it. Returns the
 * binary and exits; only ever triggers on its own signed query.
 */
function wk_rh_handle_product_image_proxy() {
    if ( ! isset( $_GET['wk_rh_img'] ) ) {
        return;
    }

    $location   = isset( $_GET['loc'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['loc'] ) ) ) : '';
    $product_id = isset( $_GET['pid'] ) ? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['pid'] ) ) ) : '';
    $sig        = isset( $_GET['sig'] ) ? (string) wp_unslash( $_GET['sig'] ) : '';

    $expected = hash_hmac( 'sha256', $location . '|' . $product_id, wp_salt( 'auth' ) );
    if ( $product_id === '' || $sig === '' || ! hash_equals( $expected, $sig ) ) {
        status_header( 403 );
        exit;
    }

    // A concurrent request may have already written the file; hand it off if so.
    $cached_url = wk_rh_find_cached_addon_image_url( $location, $product_id );
    if ( $cached_url !== '' ) {
        wp_safe_redirect( $cached_url, 302 );
        exit;
    }

    $data_uri = wk_rh_get_product_image_data_uri( $location, $product_id );
    if ( $data_uri === '' || ! preg_match( '#^data:([^;]+);base64,(.*)$#s', $data_uri, $matches ) ) {
        status_header( 404 );
        exit;
    }

    $binary = base64_decode( $matches[2], true );
    if ( $binary === false || $binary === '' ) {
        status_header( 404 );
        exit;
    }

    // Persist as a static file so every subsequent load skips WordPress and BMI entirely.
    $paths = wk_rh_get_addon_image_cache_paths();
    if ( $paths && wp_mkdir_p( $paths['dir'] ) ) {
        $filename = wk_rh_get_addon_image_basename( $location, $product_id ) . '.' . wk_rh_image_extension_from_content_type( $matches[1] );
        if ( file_put_contents( $paths['dir'] . '/' . $filename, $binary ) !== false ) {
            wp_safe_redirect( $paths['url'] . '/' . $filename, 302 );
            exit;
        }
    }

    // Fallback: stream inline if the static file could not be written.
    if ( ! headers_sent() ) {
        header_remove( 'Pragma' );
        header( 'Content-Type: ' . $matches[1] );
        header( 'Content-Length: ' . strlen( $binary ) );
        header( 'Cache-Control: public, max-age=43200, immutable' );
        header( 'X-Content-Type-Options: nosniff' );
    }

    echo $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw image bytes.
    exit;
}
add_action( 'template_redirect', 'wk_rh_handle_product_image_proxy', 1 );

function wk_rh_get_order_item_upstream_image_html( $item, $class_name = 'wk-rh-order-item-image' ) {
    if ( ! $item instanceof WC_Order_Item_Product ) {
        return '';
    }

    if ( $item->get_meta( '_wk_rh_is_addon', true ) !== 'yes' ) {
        return '';
    }

    // Try every known identifier for this add-on. The supplement id captured at
    // add-to-cart is the image-bearing product; the upstream id is remapped to the
    // sell product id at checkout and may not have an upstream image. Use the first
    // candidate that actually returns an image.
    $candidate_ids = [];
    foreach ( [ '_wk_rh_addon_supplement_id', '_wk_rh_addon_upstream_id', '_wk_rh_addon_upstream_product_id' ] as $meta_key ) {
        $candidate = trim( (string) $item->get_meta( $meta_key, true ) );
        if ( $candidate !== '' && ! in_array( $candidate, $candidate_ids, true ) ) {
            $candidate_ids[] = $candidate;
        }
    }

    if ( empty( $candidate_ids ) ) {
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

    foreach ( $candidate_ids as $candidate_id ) {
        $image_html = wk_rh_get_product_image_html( $location, $candidate_id, $item->get_name(), $class_name );
        if ( $image_html !== '' ) {
            return $image_html;
        }
    }

    return '';
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

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'booking/sell' )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/sell';
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
            'body'    => wp_json_encode( function_exists( 'wk_rh_prepare_api_payload' ) ? wk_rh_prepare_api_payload( $body ) : $body ),
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
    $data = function_exists( 'wk_rh_normalize_api_response' ) ? wk_rh_normalize_api_response( json_decode( $raw_body, true ) ) : json_decode( $raw_body, true );
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

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'booking/removeItem' )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/removeItem';
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
            'body'    => wp_json_encode( function_exists( 'wk_rh_prepare_api_payload' ) ? wk_rh_prepare_api_payload( [
                'orderId'     => ctype_digit( (string) $order_id ) ? (int) $order_id : (string) $order_id,
                'orderItemId' => ctype_digit( (string) $order_item_id ) ? (int) $order_item_id : (string) $order_item_id,
            ] ) : [
                'orderId'     => ctype_digit( (string) $order_id ) ? (int) $order_id : (string) $order_id,
                'orderItemId' => ctype_digit( (string) $order_item_id ) ? (int) $order_item_id : (string) $order_item_id,
            ] ),
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

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'booking/memo' )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/memo';
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
            'body'    => wp_json_encode( function_exists( 'wk_rh_prepare_api_payload' ) ? wk_rh_prepare_api_payload( $payload ) : $payload ),
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
    $response_data = function_exists( 'wk_rh_normalize_api_response' ) ? wk_rh_normalize_api_response( json_decode( $response_body, true ) ) : json_decode( $response_body, true );
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

function wk_rh_get_products( $token, $location = '', array $creds_override = [] ) {
    $creds = ! empty( $creds_override ) ? $creds_override : wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'products' )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/products';

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
            'clientKey' => (string) $creds['client_key'],
        ]
    );

    if ( is_wp_error( $response ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream products request failed', [
            'operation' => 'products_get',
            'method' => 'GET',
            'url' => $url,
            'location' => (string) $location,
            'clientKey' => (string) $creds['client_key'],
            'error' => $response->get_error_message(),
        ] );
        return [];
    }
    return function_exists( 'wk_rh_decode_api_response_body' ) ? wk_rh_decode_api_response_body( $response ) : json_decode( wp_remote_retrieve_body( $response ), true );
}

function wk_rh_get_availability( $token, $product_id, $date_from, $date_till, $location = '', array $creds_override = [] ) {
    $creds = ! empty( $creds_override ) ? $creds_override : wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $query = http_build_query([
        'productId' => (int) $product_id,
        'dateFrom'  => (string) $date_from,
        'dateTill'  => (string) $date_till,
    ]);

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'availability', [
            'productId' => (int) $product_id,
            'dateFrom'  => (string) $date_from,
            'dateTill'  => (string) $date_till,
        ] )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/availability?' . $query;

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
            'timeout' => 30,
        ],
        2,
        [
            'operation' => 'availability_get',
            'location' => (string) $location,
            'productId' => (int) $product_id,
            'clientKey' => (string) $creds['client_key'],
        ]
    );

    if ( is_wp_error( $response ) ) {
        wk_rh_log_upstream_event( 'error', 'Upstream availability request failed', [
            'operation' => 'availability_get',
            'method' => 'GET',
            'url' => $url,
            'location' => (string) $location,
            'productId' => (int) $product_id,
            'clientKey' => (string) $creds['client_key'],
            'error' => $response->get_error_message(),
        ] );
        return [];
    }
    return function_exists( 'wk_rh_decode_api_response_body' ) ? wk_rh_decode_api_response_body( $response ) : json_decode( wp_remote_retrieve_body( $response ), true );
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
    $use_initial_month_cache = wk_rh_is_initial_calendar_month_request( $date_from, $date_till );
    if ( $use_initial_month_cache && function_exists( 'wk_rh_read_availability_cache_payload' ) ) {
        $cached_result = wk_rh_read_availability_cache_payload( $product_id, $booking_location, $date_from );
        if ( is_array( $cached_result ) && isset( $cached_result['activities'] ) && is_array( $cached_result['activities'] ) ) {
            wk_rh_log_user_event( 'availability.current_month_cache_hit', [
                'productId' => $product_id,
                'dateFrom' => $date_from,
                'dateTill' => $date_till,
                'bookingLocation' => $booking_location,
            ] );
            wp_send_json( $cached_result );
        }
    }

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
    if ( $use_initial_month_cache && function_exists( 'wk_rh_prepare_availability_cache_payload' ) && function_exists( 'wk_rh_write_availability_cache_payload' ) ) {
        $cache_payload = wk_rh_prepare_availability_cache_payload( $product_id, $booking_location, is_array( $result ) ? $result : [], $date_from, $date_till );
        if ( ! empty( $cache_payload ) ) {
            wk_rh_write_availability_cache_payload( $product_id, $booking_location, $cache_payload );
        }
    }
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

function wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity = 1, $location = '', $dynamic_lines = [] ) {
    $creds = wk_rh_get_api_credentials( $location );
    if ( empty( $token ) || empty( $creds['base_url'] ) || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [];
    }

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'availability', [ 'date' => (string) $date ] )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/availability?date=' . rawurlencode( $date );
    $request_body = [
        'productId' => (int) $product_id,
        'pageId'    => (int) $page_id,
        'quantity'  => (int) $quantity,
    ];
    if ( ! empty( $dynamic_lines ) ) {
        $request_body['dynamicLines'] = array_values( $dynamic_lines );
    }

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
            'body'    => wp_json_encode( function_exists( 'wk_rh_prepare_api_payload' ) ? wk_rh_prepare_api_payload( $request_body ) : $request_body ),
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
    return function_exists( 'wk_rh_decode_api_response_body' ) ? wk_rh_decode_api_response_body( $response ) : json_decode( wp_remote_retrieve_body( $response ), true );
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
    $pages_url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'page', [ 'date' => $date . 'T00:00:00.000Z' ] )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/page?date=' . rawurlencode( $date . 'T00:00:00.000Z' );

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
    $pages = function_exists( 'wk_rh_decode_api_response_body' ) ? wk_rh_decode_api_response_body( $pages_response ) : json_decode( wp_remote_retrieve_body( $pages_response ), true );
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

    $participant_counts = wk_rh_get_booking_participant_counts_for_request(
        [
            'booking_adults'   => isset( $_POST['booking_adults'] ) ? wp_unslash( $_POST['booking_adults'] ) : null,
            'booking_children' => isset( $_POST['booking_children'] ) ? wp_unslash( $_POST['booking_children'] ) : null,
            'booking_twin'     => isset( $_POST['booking_twin'] ) ? wp_unslash( $_POST['booking_twin'] ) : null,
        ],
        $quantity
    );
    $dynamic_lines = $quantity > 0
        ? wk_rh_build_booking_dynamic_lines( $participant_counts, [], $matched_page_products, (string) $product_id )
        : [];

    $timeslots = $quantity > 0
        ? wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity, $booking_location, $dynamic_lines )
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
        $cart_item_data['booking_location'] = function_exists( 'wk_rh_sanitize_location_value' )
            ? wk_rh_sanitize_location_value( wp_unslash( $_POST['booking_location'] ) )
            : sanitize_text_field( wp_unslash( $_POST['booking_location'] ) );
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

function wk_rh_get_booking_dynamic_groups( $proposal, $page_products = [], $product_id = '' ) {
    $proposal = is_array( $proposal ) ? $proposal : [];
    $page_products = is_array( $page_products ) ? array_values( $page_products ) : [];

    if ( isset( $proposal['dynamicGroups'] ) && is_array( $proposal['dynamicGroups'] ) && ! empty( $proposal['dynamicGroups'] ) ) {
        return array_values( $proposal['dynamicGroups'] );
    }

    $page_product = wk_rh_get_selected_page_product( $page_products, $product_id );
    if ( is_array( $page_product ) && isset( $page_product['dynamicGroups'] ) && is_array( $page_product['dynamicGroups'] ) ) {
        return array_values( $page_product['dynamicGroups'] );
    }

    return [];
}

function wk_rh_get_booking_dynamic_line_quantity( array $counts, $target_key ) {
    if ( $target_key === 'kids' ) {
        return max( 0, (int) ( $counts['children'] ?? 0 ) );
    }

    return max( 0, (int) ( $counts[ $target_key ] ?? 0 ) );
}

function wk_rh_normalize_booking_dynamic_line( array $group, $quantity ) {
    $dynamic_line = [];

    if ( isset( $group['id'] ) && $group['id'] !== '' ) {
        $dynamic_line['id'] = is_numeric( $group['id'] ) ? (int) $group['id'] : sanitize_text_field( (string) $group['id'] );
    }

    if ( isset( $group['name'] ) && $group['name'] !== '' ) {
        $dynamic_line['name'] = sanitize_text_field( (string) $group['name'] );
    }

    if ( isset( $group['tag'] ) && $group['tag'] !== '' ) {
        $dynamic_line['tag'] = sanitize_text_field( (string) $group['tag'] );
    }

    $min_quantity = wk_rh_get_booking_rule_number_from_keys( $group, [ 'minQuantity', 'minQty', 'minimumQuantity', 'minimumQty' ], null );
    if ( $min_quantity !== null ) {
        $dynamic_line['minQuantity'] = (float) $min_quantity;
    }

    $max_quantity = wk_rh_get_booking_rule_number_from_keys( $group, [ 'maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty' ], null );
    if ( $max_quantity !== null ) {
        $dynamic_line['maxQuantity'] = (float) $max_quantity;
    }

    $dynamic_line['quantity'] = (float) max( 0, (int) $quantity );

    return $dynamic_line;
}

function wk_rh_build_booking_dynamic_lines( array $counts, $proposal, $page_products = [], $product_id = '' ) {
    $groups = wk_rh_get_booking_dynamic_groups( $proposal, $page_products, $product_id );
    if ( empty( $groups ) ) {
        return [];
    }

    $dynamic_lines = [];
    foreach ( $groups as $group ) {
        if ( ! is_array( $group ) ) {
            continue;
        }

        $target_key = wk_rh_get_booking_group_target_key( $group );
        if ( $target_key === null ) {
            continue;
        }

        $quantity = wk_rh_get_booking_dynamic_line_quantity( $counts, $target_key );
        if ( $quantity <= 0 ) {
            continue;
        }

        $dynamic_lines[] = wk_rh_normalize_booking_dynamic_line( $group, $quantity );
    }

    return array_values( $dynamic_lines );
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

    $groups = wk_rh_get_booking_dynamic_groups( $proposal, $page_products, $product_id );

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

function wk_rh_get_booking_participant_counts_for_request( array $source, $quantity = 0 ) {
    $counts = wk_rh_get_booking_participant_counts( $source );
    if ( wk_rh_get_booking_total_participants( $counts ) <= 0 && $quantity > 0 ) {
        $counts['adults'] = max( 0, (int) $quantity );
    }

    return $counts;
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
        [ 'name' => __( 'Adults', 'racehall-wc-ui' ), 'value' => $adults, 'rules' => $rules['adults'] ],
        [ 'name' => __( 'Children', 'racehall-wc-ui' ), 'value' => $kids, 'rules' => $rules['kids'] ],
        [ 'name' => __( 'Twin kart', 'racehall-wc-ui' ), 'value' => $twin, 'rules' => $rules['twin'] ],
    ];

    foreach ( $group_checks as $check ) {
        $min = (int) $check['rules']['min'];
        $max = isset( $check['rules']['max'] ) ? $check['rules']['max'] : null;
        $step = (int) $check['rules']['step'];

        if ( $check['value'] < $min ) {
            wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'group' => $check['name'], 'reason' => 'below_min', 'value' => $check['value'], 'min' => $min, 'productId' => $product_id ] , 'warning' );
            wc_add_notice( sprintf( __( '%s must be at least %d.', 'racehall-wc-ui' ), $check['name'], $min ), 'error' );
            return false;
        }
        if ( $max !== null && $check['value'] > (int) $max ) {
            wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'group' => $check['name'], 'reason' => 'above_max', 'value' => $check['value'], 'max' => (int) $max, 'productId' => $product_id ], 'warning' );
            wc_add_notice( sprintf( __( '%s cannot exceed %d.', 'racehall-wc-ui' ), $check['name'], (int) $max ), 'error' );
            return false;
        }
        if ( ! wk_rh_rule_value_matches_step( $check['value'], $min, $step ) ) {
            wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'group' => $check['name'], 'reason' => 'step_mismatch', 'value' => $check['value'], 'min' => $min, 'step' => $step, 'productId' => $product_id ], 'warning' );
            wc_add_notice( sprintf( __( '%s quantity must follow step %d starting from %d.', 'racehall-wc-ui' ), $check['name'], max( 1, $step ), $min ), 'error' );
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
        wc_add_notice( __( 'Participant quantities do not match selected booking quantity.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $total_min = (int) $rules['total']['min'];
    $total_max = isset( $rules['total']['max'] ) ? $rules['total']['max'] : null;
    $total_step = (int) $rules['total']['step'];

    if ( $total < $total_min ) {
        wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'reason' => 'total_below_min', 'total' => $total, 'min' => $total_min, 'productId' => $product_id ], 'warning' );
        wc_add_notice( sprintf( __( 'Total participants must be at least %d.', 'racehall-wc-ui' ), $total_min ), 'error' );
        return false;
    }
    if ( $total_max !== null && $total > (int) $total_max ) {
        wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'reason' => 'total_above_max', 'total' => $total, 'max' => (int) $total_max, 'productId' => $product_id ], 'warning' );
        wc_add_notice( sprintf( __( 'Total participants cannot exceed %d.', 'racehall-wc-ui' ), (int) $total_max ), 'error' );
        return false;
    }
    if ( ! wk_rh_rule_value_matches_step( $total, $total_min, $total_step ) ) {
        wk_rh_log_user_event( 'booking.quantity_validation_failed', [ 'reason' => 'total_step_mismatch', 'total' => $total, 'min' => $total_min, 'step' => $total_step, 'productId' => $product_id ], 'warning' );
        wc_add_notice( sprintf( __( 'Total participants must follow step %d starting from %d.', 'racehall-wc-ui' ), max( 1, $total_step ), $total_min ), 'error' );
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

    $bm_id = function_exists( 'wk_rh_get_product_bmileisure_id' )
        ? wk_rh_get_product_bmileisure_id( $product_id )
        : '';

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
function wk_rh_get_booking_time_display_label() {
    return __( 'Tidspunkt (Mødetid 30 minutter før.)', 'racehall-wc-ui' );
}

function wk_rh_show_booking_data_in_cart( $item_data, $cart_item ) {
    if ( isset( $cart_item['booking_date'] ) ) {
        $item_data[] = [ 'name' => 'Dato', 'value' => $cart_item['booking_date'] ];
    }
    if ( isset( $cart_item['booking_time'] ) ) {
        $item_data[] = [ 'name' => wk_rh_get_booking_time_display_label(), 'value' => $cart_item['booking_time'] ];
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
        $parts[] = wk_rh_get_booking_time_display_label() . ': ' . sanitize_text_field( $cart_item['booking_time'] );
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

        $order_location = $order->get_meta( '_wk_rh_booking_location', true );
        if ( $order_location === '' ) {
            $order->update_meta_data( '_wk_rh_booking_location', sanitize_text_field( (string) $values['booking_location'] ) );
        }
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
    $order_location = $order->get_meta( '_wk_rh_booking_location', true );
    if ( ! empty( $order_location ) ) {
        return sanitize_text_field( (string) $order_location );
    }

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

function wk_rh_add_admin_order_list_columns( $columns ) {
    if ( ! is_array( $columns ) ) {
        return $columns;
    }

    $new_columns = [];
    $inserted = false;

    foreach ( $columns as $key => $label ) {
        if ( 'order_date' === (string) $key ) {
            $new_columns['wk_rh_order_location'] = __( 'Location', 'racehall-wc-ui' );
            $new_columns['wk_rh_booking_reference'] = __( 'BMI Booking Reference', 'racehall-wc-ui' );
            $inserted = true;
        }

        $new_columns[ $key ] = $label;
    }

    if ( ! $inserted ) {
        $new_columns['wk_rh_order_location'] = __( 'Location', 'racehall-wc-ui' );
        $new_columns['wk_rh_booking_reference'] = __( 'BMI Booking Reference', 'racehall-wc-ui' );
    }

    return $new_columns;
}
add_filter( 'manage_edit-shop_order_columns', 'wk_rh_add_admin_order_list_columns', 20 );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'wk_rh_add_admin_order_list_columns', 20 );

function wk_rh_output_admin_order_list_column( $column, WC_Order $order ) {
    if ( 'wk_rh_order_location' === $column ) {
        $value = wk_rh_get_order_booking_location( $order );
        echo $value !== '' ? esc_html( $value ) : '&mdash;';
        return;
    }

    if ( 'wk_rh_booking_reference' === $column ) {
        $details = wk_rh_get_order_reservation_details( $order );
        echo $details['number'] !== '' ? esc_html( $details['number'] ) : '&mdash;';
    }
}

function wk_rh_render_legacy_admin_order_list_column( $column ) {
    if ( ! in_array( $column, [ 'wk_rh_order_location', 'wk_rh_booking_reference' ], true ) ) {
        return;
    }

    global $post;

    if ( ! $post || ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = wc_get_order( $post->ID );
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    wk_rh_output_admin_order_list_column( $column, $order );
}
add_action( 'manage_shop_order_posts_custom_column', 'wk_rh_render_legacy_admin_order_list_column', 20 );

function wk_rh_render_hpos_admin_order_list_column( $column, $order_or_id ) {
    if ( ! in_array( $column, [ 'wk_rh_order_location', 'wk_rh_booking_reference' ], true ) || ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    wk_rh_output_admin_order_list_column( $column, $order );
}
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'wk_rh_render_hpos_admin_order_list_column', 20, 2 );

function wk_rh_mark_payment_confirmed( WC_Order $order, $response_body = '', $note = '' ) {
    $order->update_meta_data( '_wk_rh_payment_confirmed', 'yes' );
    $order->save();
    $order->add_order_note( $note !== '' ? $note : 'Onsite booking: payment/confirm synced to upstream.' );
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

    // "Betal ved ankomst" maps to the WooCommerce COD gateway. Instead of an online
    // payment/confirm, finalize the booking upstream via the pay-on-site endpoint.
    if ( $order->get_payment_method() === 'cod' ) {
        wk_rh_pay_on_site_for_order( $order );
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
    $payment_method = (string) $order->get_payment_method();

    $payload = [
        'Id'          => $external_id,
        'PaymentTime' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        'Amount'      => (float) $order->get_total(),
        'OrderId'     => ctype_digit( (string) $upstream_order_id ) ? (int) $upstream_order_id : $upstream_order_id,
        'ExtraData'   => [
            'provider'      => $payment_method,
            'transactionId' => (string) $transaction_id,
        ],
    ];

    if ( '' === $payload['ExtraData']['provider'] ) {
        unset( $payload['ExtraData']['provider'] );
    }

    if ( '' === $payload['ExtraData']['transactionId'] ) {
        unset( $payload['ExtraData']['transactionId'] );
    }

    if ( empty( $payload['ExtraData'] ) ) {
        unset( $payload['ExtraData'] );
    }

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'payment/confirm' )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/payment/confirm';
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
    $response_data = function_exists( 'wk_rh_normalize_api_response' ) ? wk_rh_normalize_api_response( json_decode( $response_body, true ) ) : json_decode( $response_body, true );
    $payment_status = is_array( $response_data ) && isset( $response_data['status'] ) && is_numeric( $response_data['status'] )
        ? (int) $response_data['status']
        : null;
    $payment_success = $payment_status === 0;

    if ( $payment_status === null && is_array( $response_data ) && array_key_exists( 'success', $response_data ) ) {
        $payment_success = $response_data['success'] !== false;
    }

    if ( $code >= 200 && $code < 300 && $payment_success ) {
        wk_rh_store_reservation_details( $order, $response_data );
        wk_rh_mark_payment_confirmed( $order, $response_body );
    } else {
        $error_message = 'Onsite booking payment/confirm failed';
        if ( is_array( $response_data ) && ! empty( $response_data['errorMessage'] ) ) {
            $error_message .= ': ' . sanitize_text_field( (string) $response_data['errorMessage'] );
        } elseif ( $payment_status !== null ) {
            $error_message .= ' with status ' . $payment_status;
        } else {
            $error_message .= ' with HTTP ' . $code;
        }

        wk_rh_log_user_event( 'order.payment_confirm_failed', [
            'wcOrderId' => (string) $order->get_id(),
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'status' => $payment_status,
            'body' => is_array( $response_data ) ? $response_data : $response_body,
        ], 'error' );
        wk_rh_log_upstream_event( 'error', 'Upstream payment/confirm failed', [
            'operation' => 'payment_confirm',
            'orderId' => (string) $upstream_order_id,
            'wcOrderId' => (string) $order->get_id(),
            'location' => (string) $location,
            'httpCode' => $code,
            'status' => $payment_status,
            'body' => is_array( $response_data ) ? $response_data : $response_body,
        ] );
        $order->add_order_note( $error_message );
    }
}

add_action( 'woocommerce_payment_complete', 'wk_rh_confirm_payment_for_order', 20 );
add_action( 'woocommerce_order_status_processing', 'wk_rh_confirm_payment_for_order', 20 );
add_action( 'woocommerce_order_status_completed', 'wk_rh_confirm_payment_for_order', 20 );

/**
 * Finalize a "Betal ved ankomst" (pay-on-site) order upstream.
 *
 * Mirrors wk_rh_confirm_payment_for_order(), but calls the BMI payOnSite endpoint
 * which closes the bill as "Billed" and creates the booking task. The response is
 * the same PublicPaymentResult shape as payment/confirm (Status 0 = Confirmed).
 *
 * @param WC_Order|int $order Order object or ID.
 */
function wk_rh_pay_on_site_for_order( $order ) {
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    if ( ! wk_rh_is_pay_on_site_enabled() ) {
        return;
    }

    if ( $order->get_meta( '_wk_rh_payment_confirmed', true ) === 'yes' ) {
        return;
    }

    $upstream_order_id = wk_rh_get_order_upstream_order_id( $order );
    if ( empty( $upstream_order_id ) ) {
        wk_rh_handle_pay_on_site_unattempted(
            $order,
            'missing_upstream_order_id',
            'Onsite booking pay-on-site skipped: no upstream BMI order id on the order; the booking was NOT finalized upstream.',
            [ 'location' => (string) wk_rh_get_order_booking_location( $order ) ]
        );
        return;
    }

    $location = wk_rh_get_order_booking_location( $order );
    $token    = wk_rh_get_token( $location );
    $creds    = wk_rh_get_api_credentials( $location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        wk_rh_handle_pay_on_site_unattempted(
            $order,
            'missing_token_or_credentials',
            'Onsite booking pay-on-site skipped: missing API token/credentials for this location; the booking was NOT finalized upstream.',
            [ 'orderId' => (string) $upstream_order_id, 'location' => (string) $location ]
        );
        return;
    }

    $payload = [
        'OrderId' => ctype_digit( (string) $upstream_order_id ) ? (int) $upstream_order_id : $upstream_order_id,
    ];

    $url = function_exists( 'wk_rh_build_bmi_client_url' )
        ? wk_rh_build_bmi_client_url( $creds, 'booking', 'payment/payOnSite' )
        : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/payment/payOnSite';
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
            'operation' => 'payment_pay_on_site',
            'orderId' => (string) $upstream_order_id,
            'wcOrderId' => (string) $order->get_id(),
            'location' => (string) $location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        $order->add_order_note( 'Onsite booking payment/payOnSite failed: ' . $response->get_error_message() );
        wk_rh_log_user_event( 'order.pay_on_site_failed', [
            'wcOrderId' => (string) $order->get_id(),
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
            'error' => $response->get_error_message(),
        ], 'error' );
        if ( ! $order->has_status( 'on-hold' ) ) {
            $order->update_status( 'on-hold', 'Pay-on-site finalization failed (transport error); needs manual review. ' );
        }
        return;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $response_data = function_exists( 'wk_rh_normalize_api_response' ) ? wk_rh_normalize_api_response( json_decode( $response_body, true ) ) : json_decode( $response_body, true );
    $payment_status = is_array( $response_data ) && isset( $response_data['status'] ) && is_numeric( $response_data['status'] )
        ? (int) $response_data['status']
        : null;
    $payment_success = $payment_status === 0;

    if ( $payment_status === null && is_array( $response_data ) && array_key_exists( 'success', $response_data ) ) {
        $payment_success = $response_data['success'] !== false;
    }

    if ( $code >= 200 && $code < 300 && $payment_success ) {
        wk_rh_store_reservation_details( $order, $response_data );
        wk_rh_mark_payment_confirmed( $order, $response_body, 'Onsite booking: pay-on-site (payOnSite) confirmed upstream.' );
    } else {
        $error_message = 'Onsite booking payment/payOnSite failed';
        if ( is_array( $response_data ) && ! empty( $response_data['errorMessage'] ) ) {
            $error_message .= ': ' . sanitize_text_field( (string) $response_data['errorMessage'] );
        } elseif ( $payment_status !== null ) {
            $error_message .= ' with status ' . $payment_status;
        } else {
            $error_message .= ' with HTTP ' . $code;
        }

        wk_rh_log_user_event( 'order.pay_on_site_failed', [
            'wcOrderId' => (string) $order->get_id(),
            'orderId' => (string) $upstream_order_id,
            'location' => (string) $location,
            'httpCode' => $code,
            'status' => $payment_status,
            'body' => is_array( $response_data ) ? $response_data : $response_body,
        ], 'error' );
        wk_rh_log_upstream_event( 'error', 'Upstream payment/payOnSite failed', [
            'operation' => 'payment_pay_on_site',
            'orderId' => (string) $upstream_order_id,
            'wcOrderId' => (string) $order->get_id(),
            'location' => (string) $location,
            'httpCode' => $code,
            'status' => $payment_status,
            'body' => is_array( $response_data ) ? $response_data : $response_body,
        ] );
        $order->add_order_note( $error_message );
        if ( ! $order->has_status( 'on-hold' ) ) {
            $order->update_status( 'on-hold', 'Pay-on-site finalization failed; needs manual review. ' );
        }
    }
}

/**
 * Surface a pay-on-site finalization that could not even be attempted.
 *
 * These are the "can't even try" bails (no upstream order id, no token/credentials):
 * the COD order would otherwise sit in `processing` while its booking silently never
 * gets finalized upstream and the hold expires. We log it, leave an order note, and
 * move the order to on-hold for parity with the attempted-but-failed paths so staff see it.
 *
 * @param WC_Order $order   Order.
 * @param string   $reason  Machine-readable reason code.
 * @param string   $note    Human-readable order note.
 * @param array    $context Extra log context.
 */
function wk_rh_handle_pay_on_site_unattempted( WC_Order $order, $reason, $note, array $context = [] ) {
    $order->add_order_note( $note );
    wk_rh_log_user_event( 'order.pay_on_site_failed', array_merge( [
        'wcOrderId' => (string) $order->get_id(),
        'reason'    => $reason,
    ], $context ), 'error' );

    if ( ! $order->has_status( 'on-hold' ) ) {
        $order->update_status( 'on-hold', 'Pay-on-site finalization could not be attempted (' . $reason . '); needs manual review. ' );
    }
}

/**
 * Persist the BMI reservation reference returned by payment/confirm or payment/payOnSite.
 *
 * @param WC_Order $order         Order to update.
 * @param mixed    $response_data Normalized API response (keys are lcfirst'd).
 */
function wk_rh_store_reservation_details( WC_Order $order, $response_data ) {
    if ( ! is_array( $response_data ) ) {
        return;
    }

    $number = isset( $response_data['reservationNumber'] ) ? sanitize_text_field( (string) $response_data['reservationNumber'] ) : '';
    $code   = isset( $response_data['reservationCode'] ) ? sanitize_text_field( (string) $response_data['reservationCode'] ) : '';

    $changed = false;
    if ( $number !== '' && $order->get_meta( '_wk_rh_reservation_number', true ) !== $number ) {
        $order->update_meta_data( '_wk_rh_reservation_number', $number );
        $changed = true;
    }
    if ( $code !== '' && $order->get_meta( '_wk_rh_reservation_code', true ) !== $code ) {
        $order->update_meta_data( '_wk_rh_reservation_code', $code );
        $changed = true;
    }

    if ( $changed ) {
        $order->save();
    }
}

/**
 * Read the stored BMI reservation reference for an order.
 *
 * @param WC_Order $order Order.
 * @return array{number:string,code:string}
 */
function wk_rh_get_order_reservation_details( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return [ 'number' => '', 'code' => '' ];
    }

    return [
        'number' => (string) $order->get_meta( '_wk_rh_reservation_number', true ),
        'code'   => (string) $order->get_meta( '_wk_rh_reservation_code', true ),
    ];
}

/**
 * Build the BMI reservation reference markup shown to customers/staff.
 *
 * @param WC_Order $order Order.
 * @param bool     $plain Render as plain text (for plain-text emails).
 * @return string
 */
function wk_rh_build_reservation_reference_html( $order, $plain = false ) {
    $details = wk_rh_get_order_reservation_details( $order );
    // Only the human-readable reservation number is shown; the rXXXX reservation code
    // (QR payload) is stored but intentionally not displayed.
    if ( $details['number'] === '' ) {
        return '';
    }

    $heading    = __( 'Booking reference', 'racehall-wc-ui' );
    $number_lbl = __( 'Reservation number', 'racehall-wc-ui' );

    if ( $plain ) {
        return $heading . "\n" . $number_lbl . ': ' . $details['number'] . "\n";
    }

    return '<section class="wk-rh-reservation-reference"><h3>' . esc_html( $heading ) . '</h3>'
        . '<p class="wk-rh-reservation-number"><strong>' . esc_html( $number_lbl ) . ':</strong> ' . esc_html( $details['number'] ) . '</p>'
        . '</section>';
}

/**
 * Thank-you page + customer My Account order view (both fire this action).
 *
 * @param WC_Order $order Order.
 */
function wk_rh_render_reservation_reference_frontend( $order ) {
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    // CheckoutWC may fire woocommerce_order_details_after_order_table more than once
    // on the thank-you page; render at most once per order per request.
    static $rendered = [];
    $order_id = $order->get_id();
    if ( isset( $rendered[ $order_id ] ) ) {
        return;
    }
    $rendered[ $order_id ] = true;

    echo wk_rh_build_reservation_reference_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in builder.
}
add_action( 'woocommerce_order_details_after_order_table', 'wk_rh_render_reservation_reference_frontend', 20 );

/**
 * Order confirmation emails.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether the email is to the admin.
 * @param bool     $plain_text    Whether the email is plain text.
 */
function wk_rh_render_reservation_reference_email( $order, $sent_to_admin = false, $plain_text = false ) {
    if ( ! $order instanceof WC_Order ) {
        return;
    }
    $markup = wk_rh_build_reservation_reference_html( $order, (bool) $plain_text );
    if ( $markup === '' ) {
        return;
    }
    echo $plain_text ? "\n" . $markup : wp_kses_post( $markup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_email_after_order_table', 'wk_rh_render_reservation_reference_email', 20, 3 );

/**
 * Admin order-edit screen.
 *
 * @param WC_Order $order Order.
 */
function wk_rh_render_reservation_reference_admin( $order ) {
    $details = wk_rh_get_order_reservation_details( $order );
    if ( $details['number'] === '' ) {
        return;
    }
    echo '<div class="wk-rh-reservation-reference-admin">';
    echo '<h4 style="margin-bottom:4px;">' . esc_html__( 'BMI booking reference', 'racehall-wc-ui' ) . '</h4>';
    echo '<p style="margin:0;"><strong>' . esc_html__( 'Reservation number', 'racehall-wc-ui' ) . ':</strong> ' . esc_html( $details['number'] ) . '</p>';
    echo '</div>';
}
add_action( 'woocommerce_admin_order_data_after_order_details', 'wk_rh_render_reservation_reference_admin', 20 );

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
        function_exists( 'wk_rh_build_bmi_client_url' )
            ? wk_rh_build_bmi_client_url( $creds, 'booking', 'order/' . rawurlencode( (string) $upstream_order_id ) . '/cancel' )
            : $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/order/' . rawurlencode( (string) $upstream_order_id ) . '/cancel',
    ];

    foreach ( $paths as $path ) {
        $response = wk_rh_remote_request_with_retry(
            'DELETE',
            $path,
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
        $response_body = wp_remote_retrieve_body( $response );
        $response_data = function_exists( 'wk_rh_normalize_api_response' ) ? wk_rh_normalize_api_response( json_decode( $response_body, true ) ) : json_decode( $response_body, true );
        $cancel_success = $code >= 200 && $code < 300
            && ( ! is_array( $response_data ) || ! array_key_exists( 'success', $response_data ) || $response_data['success'] !== false );

        if ( $cancel_success ) {
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

        if ( $code >= 200 && $code < 300 ) {
            wk_rh_log_upstream_event( 'warning', 'Upstream cancellation endpoint returned semantic failure', [
                'operation' => 'order_cancel',
                'orderId' => (string) $upstream_order_id,
                'location' => (string) $location,
                'path' => (string) $path,
                'httpCode' => $code,
                'body' => is_array( $response_data ) ? $response_data : $response_body,
                'extra' => $extra_context,
            ] );
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

function racehall_get_timeslots( $token, $product_id, $page_id, $date, $quantity = 1, $location = '', $dynamic_lines = [] ) {
    return wk_rh_get_timeslots( $token, $product_id, $page_id, $date, $quantity, $location, $dynamic_lines );
}
