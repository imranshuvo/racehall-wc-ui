<?php
/**
 * Plugin Name: Onsite Booking System
 * Description: Onsite booking integration for Racehall and bmileisure API.
 * Version: 1.62
 * Author: Webkonsulenterne ApS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'RACEHALL_WC_UI_BOOTSTRAPPED' ) ) {
    return;
}

function wk_rh_get_main_booking_product_url() {
    if ( function_exists( 'WC' ) && WC()->session ) {
        $session_url = WC()->session->get( 'rh_last_product_url' );
        if ( is_string( $session_url ) && $session_url !== '' ) {
            return esc_url_raw( $session_url );
        }
    }

    if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['is_addon'] ) ) {
                continue;
            }

            $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
            if ( $product_id > 0 ) {
                $url = get_permalink( $product_id );
                if ( is_string( $url ) && $url !== '' ) {
                    return $url;
                }
            }
        }
    }

    return wc_get_cart_url();
}
define( 'RACEHALL_WC_UI_BOOTSTRAPPED', true );

// Define plugin paths
define( 'RACEHALL_WC_UI_PATH', plugin_dir_path( __FILE__ ) );
define( 'RACEHALL_WC_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'RACEHALL_WC_UI_VERSION', '1.62' );

function wk_rh_get_log_environment() {
    $settings = wk_rh_get_settings();
    $environment = isset( $settings['environment'] ) && $settings['environment'] === 'live' ? 'live' : 'test';
    return $environment === 'live' ? 'live' : 'testapi';
}

function wk_rh_get_log_root_directory() {
    $upload_dir = wp_upload_dir();
    $base_dir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
    if ( $base_dir === '' ) {
        return '';
    }

    return trailingslashit( $base_dir ) . 'onsite-booking';
}

function wk_rh_get_log_directory( $environment = '' ) {
    $environment = $environment !== '' ? sanitize_key( $environment ) : wk_rh_get_log_environment();
    $root_dir = wk_rh_get_log_root_directory();
    if ( $root_dir === '' ) {
        return '';
    }

    return trailingslashit( $root_dir ) . ( $environment === 'live' ? 'live' : 'testapi' );
}

function wk_rh_get_log_date_directory( $timestamp = null, $environment = '' ) {
    $timestamp = $timestamp !== null ? (int) $timestamp : time();
    $base_dir = wk_rh_ensure_log_directory( $environment );
    if ( $base_dir === '' ) {
        return '';
    }

    $date_dir = trailingslashit( $base_dir ) . gmdate( 'd_m_Y', $timestamp );
    if ( ! wp_mkdir_p( $date_dir ) ) {
        return '';
    }

    $index_file = trailingslashit( $date_dir ) . 'index.php';
    if ( ! file_exists( $index_file ) ) {
        @file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
    }

    return $date_dir;
}

function wk_rh_ensure_log_directory( $environment = '' ) {
    $dir = wk_rh_get_log_directory( $environment );
    if ( $dir === '' ) {
        return '';
    }

    if ( ! wp_mkdir_p( $dir ) ) {
        return '';
    }

    $root_dir = wk_rh_get_log_root_directory();
    if ( $root_dir !== '' && wp_mkdir_p( $root_dir ) ) {
        $root_index = trailingslashit( $root_dir ) . 'index.php';
        $root_htaccess = trailingslashit( $root_dir ) . '.htaccess';
        if ( ! file_exists( $root_index ) ) {
            @file_put_contents( $root_index, "<?php\n// Silence is golden.\n" );
        }
        if ( ! file_exists( $root_htaccess ) ) {
            @file_put_contents( $root_htaccess, "Options -Indexes\n<FilesMatch \"\\.(log)$\">\nRequire all denied\n</FilesMatch>\n" );
        }
    }

    $index_file = trailingslashit( $dir ) . 'index.php';
    if ( ! file_exists( $index_file ) ) {
        @file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
    }

    return $dir;
}

function wk_rh_normalize_log_channel( $channel ) {
    $channel = sanitize_key( (string) $channel );
    if ( $channel === 'api' ) {
        return 'api';
    }

    return 'user-actions';
}

function wk_rh_get_log_file_path( $channel, $timestamp = null, $environment = '' ) {
    $timestamp = $timestamp !== null ? (int) $timestamp : time();
    $directory = wk_rh_get_log_date_directory( $timestamp, $environment );
    if ( $directory === '' ) {
        return '';
    }

    $channel = wk_rh_normalize_log_channel( $channel );
    $date = gmdate( 'Y-m-d', $timestamp );

    return trailingslashit( $directory ) . $channel . '-' . $date . '.log';
}

function wk_rh_redact_sensitive_log_value( $key, $value ) {
    $key = is_string( $key ) ? strtolower( $key ) : '';
    $sensitive_fragments = [ 'authorization', 'password', 'token', 'subscription', 'secret' ];

    foreach ( $sensitive_fragments as $fragment ) {
        if ( $key !== '' && strpos( $key, $fragment ) !== false ) {
            return '[redacted]';
        }
    }

    return $value;
}

function wk_rh_sanitize_log_context( $value, $depth = 0 ) {
    if ( $depth > 5 ) {
        return '[max-depth-reached]';
    }

    if ( is_array( $value ) ) {
        $sanitized = [];
        foreach ( $value as $key => $item ) {
            $safe_key = is_string( $key ) ? sanitize_key( $key ) : $key;
            $redacted_item = wk_rh_redact_sensitive_log_value( $safe_key, $item );
            $sanitized[ $safe_key ] = wk_rh_sanitize_log_context( $redacted_item, $depth + 1 );
        }
        return $sanitized;
    }

    if ( is_object( $value ) ) {
        return wk_rh_sanitize_log_context( get_object_vars( $value ), $depth + 1 );
    }

    if ( is_bool( $value ) || is_null( $value ) || is_int( $value ) || is_float( $value ) ) {
        return $value;
    }

    if ( is_scalar( $value ) ) {
        return sanitize_textarea_field( (string) $value );
    }

    return '[unsupported-type]';
}

function wk_rh_prepare_log_http_body( $body ) {
    if ( is_array( $body ) || is_object( $body ) ) {
        return wk_rh_sanitize_log_context( $body );
    }

    if ( ! is_scalar( $body ) ) {
        return '';
    }

    $body = (string) $body;
    if ( $body === '' ) {
        return '';
    }

    $decoded = json_decode( $body, true );
    if ( is_array( $decoded ) ) {
        return wk_rh_sanitize_log_context( $decoded );
    }

    return sanitize_textarea_field( strlen( $body ) > 5000 ? substr( $body, 0, 5000 ) . '…[truncated]' : $body );
}

function wk_rh_prepare_log_http_headers( $headers ) {
    if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
        $headers = $headers->getAll();
    }

    if ( ! is_array( $headers ) ) {
        return [];
    }

    return wk_rh_sanitize_log_context( $headers );
}

function wk_rh_collect_log_files( $directory ) {
    if ( $directory === '' || ! is_dir( $directory ) ) {
        return [];
    }

    $files = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file_info ) {
            if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
                continue;
            }
            if ( strtolower( $file_info->getExtension() ) !== 'log' ) {
                continue;
            }
            $files[] = $file_info->getPathname();
        }
    } catch ( Exception $exception ) {
        return [];
    }

    return $files;
}

function wk_rh_get_logger_request_id() {
    static $request_id = null;

    if ( $request_id !== null ) {
        return $request_id;
    }

    try {
        $request_id = wp_generate_uuid4();
    } catch ( Exception $exception ) {
        $request_id = uniqid( 'wk_rh_', true );
    }

    return $request_id;
}

function wk_rh_get_logger_actor_context() {
    $user_id = get_current_user_id();
    $session_id = '';

    if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
        $session_id = (string) WC()->session->get_customer_id();
    }

    return [
        'requestId' => wk_rh_get_logger_request_id(),
        'userId' => $user_id > 0 ? $user_id : 0,
        'sessionId' => $session_id,
        'requestUri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '',
        'requestMethod' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '',
    ];
}

function wk_rh_write_log_entry( $channel, $level, $message, array $context = [], $environment = '' ) {
    if ( ! wk_rh_is_logging_enabled() ) {
        return false;
    }

    $file_path = wk_rh_get_log_file_path( $channel, time(), $environment );
    if ( $file_path === '' ) {
        error_log( 'OnsiteBookingLogger unavailable: ' . wp_json_encode( [
            'channel' => $channel,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ] ) );
        return false;
    }

    $entry = [
        'time' => gmdate( 'c' ),
        'environment' => $environment !== '' ? $environment : wk_rh_get_log_environment(),
        'channel' => wk_rh_normalize_log_channel( $channel ),
        'level' => sanitize_key( (string) $level ),
        'message' => sanitize_textarea_field( (string) $message ),
        'context' => array_merge( wk_rh_get_logger_actor_context(), wk_rh_sanitize_log_context( $context ) ),
    ];

    $line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( ! is_string( $line ) || $line === '' ) {
        return false;
    }

    return file_put_contents( $file_path, $line . PHP_EOL, FILE_APPEND | LOCK_EX ) !== false;
}

function wk_rh_log_upstream_event( $level, $message, array $context = [] ) {
    return wk_rh_write_log_entry( 'api', $level, $message, $context );
}

function wk_rh_log_user_event( $message, array $context = [], $level = 'info' ) {
    return wk_rh_write_log_entry( 'user-actions', $level, $message, $context );
}

function wk_rh_get_recent_log_entries( array $channels = [], $limit = 200, $environment = '' ) {
    $environment = $environment !== '' ? sanitize_key( $environment ) : wk_rh_get_log_environment();
    $directory = wk_rh_get_log_directory( $environment );
    if ( $directory === '' || ! is_dir( $directory ) ) {
        return [];
    }

    $limit = max( 1, (int) $limit );
    $channels = empty( $channels ) ? [ 'api', 'user-actions' ] : array_map( 'wk_rh_normalize_log_channel', $channels );
    $channels = array_values( array_unique( $channels ) );

    $all_files = wk_rh_collect_log_files( $directory );
    $files = [];
    foreach ( $all_files as $file ) {
        $basename = basename( (string) $file );
        foreach ( $channels as $channel ) {
            if ( strpos( $basename, $channel . '-' ) === 0 ) {
                $files[] = $file;
                break;
            }
        }
    }

    if ( empty( $files ) ) {
        return [];
    }

    usort( $files, static function( $left, $right ) {
        return filemtime( $right ) <=> filemtime( $left );
    } );

    $entries = [];
    foreach ( array_slice( array_unique( $files ), 0, 7 ) as $file ) {
        $lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $lines ) ) {
            continue;
        }

        for ( $index = count( $lines ) - 1; $index >= 0; $index-- ) {
            $decoded = json_decode( $lines[ $index ], true );
            if ( is_array( $decoded ) ) {
                $entries[] = $decoded;
            }
            if ( count( $entries ) >= $limit ) {
                break 2;
            }
        }
    }

    usort( $entries, static function( $left, $right ) {
        return strcmp( (string) ( $right['time'] ?? '' ), (string) ( $left['time'] ?? '' ) );
    } );

    return array_slice( $entries, 0, $limit );
}

function wk_rh_clear_log_files( $environment = '' ) {
    $directory = wk_rh_get_log_directory( $environment );
    if ( $directory === '' || ! is_dir( $directory ) ) {
        return 0;
    }

    $deleted = 0;
    foreach ( wk_rh_collect_log_files( $directory ) as $file ) {
        if ( is_file( $file ) && @unlink( $file ) ) {
            $deleted++;
        }
    }

    return $deleted;
}

function wk_rh_get_settings_defaults() {
    return [
        'environment'         => 'test',
        'test_base_url'       => 'https://testbmiapigateway.azure-api.net',
        'live_base_url'       => 'https://api.bmileisure.com',
        'accept_language'     => 'en',
        'logging_enabled'     => 'yes',
        'addon_product_id'    => 0,
        'booking_hold_timeout_minutes' => 15,
        'test_locations_json' => '[]',
        'live_locations_json' => '[]',
    ];
}

function wk_rh_is_logging_enabled() {
    $settings = wk_rh_get_settings();
    return ! isset( $settings['logging_enabled'] ) || $settings['logging_enabled'] === 'yes';
}

function wk_rh_expire_current_cart_reservation( $source = 'manual', $add_notice = true ) {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return [
            'success' => false,
            'redirect_url' => wc_get_cart_url(),
        ];
    }

    $redirect_url = wk_rh_get_main_booking_product_url();
    $main_holds = [];

    if ( ! WC()->cart->is_empty() ) {
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['is_addon'] ) ) {
                continue;
            }

            $upstream_order_id = isset( $item['bmi_order_id'] ) ? trim( (string) $item['bmi_order_id'] ) : '';
            if ( $upstream_order_id === '' ) {
                continue;
            }

            $location = isset( $item['booking_location'] ) ? sanitize_text_field( (string) $item['booking_location'] ) : '';
            $main_holds[ $upstream_order_id ] = $location;
        }
    }

    foreach ( $main_holds as $upstream_order_id => $location ) {
        if ( function_exists( 'wk_rh_cancel_upstream_order_by_id' ) ) {
            $cancelled = wk_rh_cancel_upstream_order_by_id( $upstream_order_id, $location, [
                'source' => (string) $source,
                'operation' => 'hold_timeout_cancel',
            ] );

            if ( ! $cancelled && function_exists( 'wk_rh_log_upstream_event' ) ) {
                wk_rh_log_upstream_event( 'error', 'Failed to cancel reservation during forced timeout expiry', [
                    'operation' => 'hold_timeout_cancel',
                    'orderId' => (string) $upstream_order_id,
                    'location' => (string) $location,
                    'source' => (string) $source,
                ] );
            }
        }

        wk_rh_release_active_hold( $upstream_order_id );
    }

    wk_rh_log_user_event( 'hold.expired', [
        'source' => (string) $source,
        'expiredOrderIds' => array_keys( $main_holds ),
        'redirectUrl' => $redirect_url,
    ], empty( $main_holds ) ? 'warning' : 'info' );

    WC()->cart->empty_cart();
    wk_rh_clear_booking_session_state();

    $notice = __( 'Din reservation er udløbet. Start venligst bookingprocessen igen.', 'racehall-wc-ui' );
    if ( $add_notice ) {
        wc_add_notice( $notice, 'error' );
    }

    return [
        'success' => true,
        'redirect_url' => $redirect_url,
        'notice' => $notice,
    ];
}

add_action( 'wp_ajax_rh_expire_hold', function() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'rh_hold_nonce' ) ) {
        wk_rh_log_user_event( 'hold.expire_request_rejected', [ 'reason' => 'invalid_nonce', 'source' => 'ajax' ], 'warning' );
        wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
    }

    wk_rh_process_expired_active_holds( 'ajax_timeout' );
    $result = wk_rh_expire_current_cart_reservation( 'ajax_timeout' );

    wp_send_json_success( [
        'redirectUrl' => isset( $result['redirect_url'] ) ? $result['redirect_url'] : wc_get_cart_url(),
        'message' => isset( $result['notice'] ) ? $result['notice'] : '',
    ] );
} );

add_action( 'wp_ajax_nopriv_rh_expire_hold', function() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'rh_hold_nonce' ) ) {
        wk_rh_log_user_event( 'hold.expire_request_rejected', [ 'reason' => 'invalid_nonce', 'source' => 'ajax_nopriv' ], 'warning' );
        wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
    }

    wk_rh_process_expired_active_holds( 'ajax_timeout' );
    $result = wk_rh_expire_current_cart_reservation( 'ajax_timeout' );

    wp_send_json_success( [
        'redirectUrl' => isset( $result['redirect_url'] ) ? $result['redirect_url'] : wc_get_cart_url(),
        'message' => isset( $result['notice'] ) ? $result['notice'] : '',
    ] );
} );

function wk_rh_get_settings() {
    $saved = get_option( 'wk_rh_settings', [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }

    $settings = wp_parse_args( $saved, wk_rh_get_settings_defaults() );

    $legacy_json = trim( (string) ( $settings['locations_json'] ?? '' ) );
    if ( $legacy_json !== '' && $legacy_json !== '[]' ) {
        $test_json = trim( (string) ( $settings['test_locations_json'] ?? '' ) );
        $live_json = trim( (string) ( $settings['live_locations_json'] ?? '' ) );

        if ( $test_json === '' || $test_json === '[]' ) {
            $settings['test_locations_json'] = $legacy_json;
        }
        if ( $live_json === '' || $live_json === '[]' ) {
            $settings['live_locations_json'] = $legacy_json;
        }
    }

    return $settings;
}

function wk_rh_get_location_profiles() {
    $settings = wk_rh_get_settings();
    $env      = isset( $settings['environment'] ) && $settings['environment'] === 'live' ? 'live' : 'test';
    $json_key = $env === 'live' ? 'live_locations_json' : 'test_locations_json';
    $json     = isset( $settings[ $json_key ] ) ? trim( (string) $settings[ $json_key ] ) : '[]';
    $profiles = json_decode( $json, true );

    if ( ! is_array( $profiles ) ) {
        return [];
    }

    $normalized = [];
    foreach ( $profiles as $profile ) {
        if ( ! is_array( $profile ) ) {
            continue;
        }

        $normalized[] = [
            'location'         => sanitize_text_field( $profile['location'] ?? '' ),
            'client_key'       => sanitize_text_field( $profile['client_key'] ?? '' ),
            'subscription_key' => sanitize_text_field( $profile['subscription_key'] ?? '' ),
            'username'         => sanitize_text_field( $profile['username'] ?? '' ),
            'password'         => (string) ( $profile['password'] ?? '' ),
        ];
    }

    return $normalized;
}

function wk_rh_get_effective_location_name( $location = '' ) {
    if ( ! empty( $location ) ) {
        return sanitize_text_field( $location );
    }

    if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['booking_location'] ) ) {
                return sanitize_text_field( $cart_item['booking_location'] );
            }
        }
    }

    return '';
}

function wk_rh_get_location_profile( $location = '' ) {
    $profiles      = wk_rh_get_location_profiles();
    $location_name = wk_rh_get_effective_location_name( $location );

    if ( $location_name === '' ) {
        if ( function_exists( 'wk_rh_log_upstream_event' ) ) {
            wk_rh_log_upstream_event( 'error', 'No booking location supplied for credential resolution', [
                'operation' => 'resolve_location_profile',
            ] );
        }

        return [
            'location'         => '',
            'client_key'       => '',
            'subscription_key' => '',
            'username'         => '',
            'password'         => '',
        ];
    }

    foreach ( $profiles as $profile ) {
        if ( ! empty( $profile['location'] ) && strcasecmp( $profile['location'], $location_name ) === 0 ) {
            return $profile;
        }
    }

    if ( $location_name !== '' && function_exists( 'wk_rh_log_upstream_event' ) ) {
        wk_rh_log_upstream_event( 'error', 'No credential profile found for booking location', [
            'operation' => 'resolve_location_profile',
            'location' => (string) $location_name,
        ] );
    }

    return [
        'location'         => '',
        'client_key'       => '',
        'subscription_key' => '',
        'username'         => '',
        'password'         => '',
    ];
}

function wk_rh_get_base_url() {
    $settings = wk_rh_get_settings();
    $env      = $settings['environment'] ?? 'test';

    $url = $env === 'live'
        ? ( $settings['live_base_url'] ?? '' )
        : ( $settings['test_base_url'] ?? '' );

    return rtrim( trim( (string) $url ), '/' );
}

function wk_rh_get_accept_language() {
    $settings = wk_rh_get_settings();
    $lang     = sanitize_text_field( $settings['accept_language'] ?? 'en' );
    return $lang ?: 'en';
}

function wk_rh_get_api_credentials( $location = '' ) {
    $profile = wk_rh_get_location_profile( $location );
    return [
        'base_url'         => wk_rh_get_base_url(),
        'accept_language'  => wk_rh_get_accept_language(),
        'client_key'       => $profile['client_key'] ?? '',
        'subscription_key' => $profile['subscription_key'] ?? '',
        'username'         => $profile['username'] ?? '',
        'password'         => $profile['password'] ?? '',
    ];
}

function wk_rh_sanitize_locations_json( $raw_json, $error_key ) {
    $json = trim( (string) $raw_json );
    if ( $json === '' ) {
        $json = '[]';
    }

    $parsed = json_decode( $json, true );
    if ( ! is_array( $parsed ) ) {
        add_settings_error( 'wk_rh_settings', $error_key, __( 'Location credentials JSON is invalid. Saved as empty list.', 'onsite-booking-system' ), 'error' );
        return '[]';
    }

    return $json;
}

function wk_rh_sanitize_settings( $input ) {
    $defaults = wk_rh_get_settings_defaults();
    $input    = is_array( $input ) ? $input : [];

    $addon_product_id = isset( $input['addon_product_id'] ) ? absint( $input['addon_product_id'] ) : 0;
    if ( $addon_product_id > 0 && ! function_exists( 'wc_get_product' ) ) {
        $addon_product_id = 0;
    }
    if ( $addon_product_id > 0 ) {
        $validation_error = wk_rh_get_addon_carrier_validation_error( $addon_product_id );
        if ( $validation_error !== '' ) {
            add_settings_error( 'wk_rh_settings', 'wk_rh_addon_product_invalid', $validation_error . ' ' . __( 'Setting was cleared.', 'onsite-booking-system' ), 'error' );
            $addon_product_id = 0;
        }
    }

    $sanitized = [
        'environment'         => in_array( $input['environment'] ?? 'test', [ 'test', 'live' ], true ) ? $input['environment'] : 'test',
        'test_base_url'       => esc_url_raw( $input['test_base_url'] ?? $defaults['test_base_url'] ),
        'live_base_url'       => esc_url_raw( $input['live_base_url'] ?? $defaults['live_base_url'] ),
        'accept_language'     => sanitize_text_field( $input['accept_language'] ?? 'en' ),
        'logging_enabled'     => ! empty( $input['logging_enabled'] ) && $input['logging_enabled'] === 'yes' ? 'yes' : 'no',
        'addon_product_id'    => $addon_product_id,
        'booking_hold_timeout_minutes' => max( 5, min( 120, (int) ( $input['booking_hold_timeout_minutes'] ?? $defaults['booking_hold_timeout_minutes'] ) ) ),
        'test_locations_json' => wk_rh_sanitize_locations_json( $input['test_locations_json'] ?? '[]', 'wk_rh_test_locations_json_invalid' ),
        'live_locations_json' => wk_rh_sanitize_locations_json( $input['live_locations_json'] ?? '[]', 'wk_rh_live_locations_json_invalid' ),
    ];

    return $sanitized;
}

function wk_rh_get_configured_addon_product_id() {
    $settings = wk_rh_get_settings();
    $product_id = isset( $settings['addon_product_id'] ) ? absint( $settings['addon_product_id'] ) : 0;
    if ( $product_id <= 0 ) {
        return 0;
    }

    if ( has_filter( 'wpml_object_id' ) ) {
        $translated_product_id = apply_filters( 'wpml_object_id', $product_id, 'product', true );
        if ( is_numeric( $translated_product_id ) && (int) $translated_product_id > 0 ) {
            $product_id = (int) $translated_product_id;
        }
    }

    return $product_id;
}

function wk_rh_get_configured_addon_product() {
    $product_id = wk_rh_get_configured_addon_product_id();
    if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
        return null;
    }

    $product = wc_get_product( $product_id );
    return $product ? $product : null;
}

function wk_rh_get_addon_carrier_validation_error( $product = null ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return __( 'WooCommerce product API is unavailable.', 'onsite-booking-system' );
    }

    if ( is_numeric( $product ) ) {
        $product = wc_get_product( absint( $product ) );
    }

    if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
        return __( 'Selected add-on product does not exist.', 'onsite-booking-system' );
    }

    if ( 'publish' !== $product->get_status() ) {
        return __( 'Add-on carrier product must be published.', 'onsite-booking-system' );
    }

    if ( ! $product->is_type( 'simple' ) ) {
        return __( 'Add-on carrier product must be a simple product.', 'onsite-booking-system' );
    }

    if ( ! $product->is_purchasable() ) {
        return __( 'Add-on carrier product must be purchasable.', 'onsite-booking-system' );
    }

    if ( $product->is_sold_individually() ) {
        return __( 'Add-on carrier product cannot be sold individually.', 'onsite-booking-system' );
    }

    if ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
        return __( 'Add-on carrier product must be in stock.', 'onsite-booking-system' );
    }

    return '';
}

function wk_rh_is_valid_addon_carrier_product( $product = null ) {
    return wk_rh_get_addon_carrier_validation_error( $product ) === '';
}

function wk_rh_get_settings_product_options() {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return [];
    }

    $products = wc_get_products( [
        'status' => [ 'publish' ],
        'limit'  => -1,
        'orderby' => 'title',
        'order'   => 'ASC',
        'return'  => 'objects',
    ] );

    if ( ! is_array( $products ) ) {
        return [];
    }

    $options = [];
    foreach ( $products as $product ) {
        if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            continue;
        }

        $product_id = (int) $product->get_id();
        if ( $product_id <= 0 ) {
            continue;
        }

        if ( ! wk_rh_is_valid_addon_carrier_product( $product ) ) {
            continue;
        }

        $options[ $product_id ] = sprintf( '%s (#%d)', $product->get_name(), $product_id );
    }

    return $options;
}

function wk_rh_is_configured_addon_product( $product_id ) {
    $product_id = absint( $product_id );
    if ( $product_id <= 0 ) {
        return false;
    }

    return $product_id === wk_rh_get_configured_addon_product_id();
}

function wk_rh_get_booking_hold_timeout_minutes() {
    $settings = wk_rh_get_settings();
    $value = isset( $settings['booking_hold_timeout_minutes'] ) ? (int) $settings['booking_hold_timeout_minutes'] : 15;
    return max( 5, min( 120, $value ) );
}

function wk_rh_get_cart_hold_expiry_context() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        return [
            'expires_at' => 0,
            'order_id'   => '',
        ];
    }

    $earliest_expiry = 0;
    $order_id = '';
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['is_addon'] ) ) {
            continue;
        }

        $item_expiry = isset( $cart_item['bmi_hold_expires_at'] ) ? (int) $cart_item['bmi_hold_expires_at'] : 0;
        $item_order  = isset( $cart_item['bmi_order_id'] ) ? trim( (string) $cart_item['bmi_order_id'] ) : '';
        if ( $item_expiry <= 0 || $item_order === '' ) {
            continue;
        }

        if ( $earliest_expiry === 0 || $item_expiry < $earliest_expiry ) {
            $earliest_expiry = $item_expiry;
            $order_id = $item_order;
        }
    }

    return [
        'expires_at' => $earliest_expiry,
        'order_id'   => $order_id,
    ];
}

function wk_rh_render_hold_banner_html( $expires_at, $expired_text, $prefix_text, $headline_text = '', $extra_class = '' ) {
    $expires_at = (int) $expires_at;
    if ( $expires_at <= 0 ) {
        return;
    }

    $headline = $headline_text !== ''
        ? $headline_text
        : __( 'Bekræft ordren inden tidsfristen udløber.', 'racehall-wc-ui' );

    $class_name = trim( 'rh-hold-banner ' . sanitize_html_class( (string) $extra_class ) );
    echo '<div class="' . esc_attr( $class_name ) . '" data-expires-at="' . esc_attr( $expires_at ) . '" data-expired-text="' . esc_attr( $expired_text ) . '" data-prefix-text="' . esc_attr( $prefix_text ) . '" data-cart-url="' . esc_url( wc_get_cart_url() ) . '">';
    echo '<strong>' . esc_html( $headline ) . '</strong>';
    echo '<span class="rh-hold-countdown" aria-live="polite">--:--</span>';
    echo '</div>';
}

function wk_rh_get_hold_banner_markup( array $args = [] ) {
    $defaults = [
        'expires_at'   => 0,
        'expired_text' => __( 'Reservationstiden er udløbet. Du skal starte bookingflowet igen.', 'racehall-wc-ui' ),
        'prefix_text'  => __( 'Din reservation holdes i:', 'racehall-wc-ui' ),
        'headline_text'=> __( 'Bekræft ordren inden tidsfristen udløber.', 'racehall-wc-ui' ),
        'extra_class'  => '',
    ];

    $args = wp_parse_args( $args, $defaults );
    $expires_at = (int) $args['expires_at'];
    if ( $expires_at <= 0 ) {
        $hold_ctx = wk_rh_get_cart_hold_expiry_context();
        $expires_at = isset( $hold_ctx['expires_at'] ) ? (int) $hold_ctx['expires_at'] : 0;
    }

    if ( $expires_at <= 0 ) {
        return '';
    }

    ob_start();
    wk_rh_render_hold_banner_html(
        $expires_at,
        (string) $args['expired_text'],
        (string) $args['prefix_text'],
        (string) $args['headline_text'],
        (string) $args['extra_class']
    );
    return (string) ob_get_clean();
}

add_shortcode( 'rh_hold_countdown', function( $atts ) {
    $atts = shortcode_atts( [
        'expired_text' => __( 'Reservationstiden er udløbet. Du skal starte bookingflowet igen.', 'racehall-wc-ui' ),
        'prefix_text' => __( 'Din reservation holdes i:', 'racehall-wc-ui' ),
        'headline_text' => __( 'Bekræft ordren inden tidsfristen udløber.', 'racehall-wc-ui' ),
        'class' => '',
    ], $atts, 'rh_hold_countdown' );

    return wk_rh_get_hold_banner_markup( [
        'expired_text' => (string) $atts['expired_text'],
        'prefix_text' => (string) $atts['prefix_text'],
        'headline_text' => (string) $atts['headline_text'],
        'extra_class' => (string) $atts['class'],
    ] );
} );

function wk_rh_render_checkoutwc_hold_banner() {
    static $already_rendered = false;

    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }

    if ( $already_rendered ) {
        return;
    }

    $already_rendered = true;

    echo wk_rh_get_hold_banner_markup( [
        'extra_class' => 'rh-hold-banner-checkoutwc',
    ] );
}

add_action( 'cfw_before_checkout_form', 'wk_rh_render_checkoutwc_hold_banner', 5 );
add_action( 'cfw_before_main_content', 'wk_rh_render_checkoutwc_hold_banner', 5 );

function wk_rh_render_checkout_loading_overlay() {
    static $already_rendered = false;

    if ( is_admin() ) {
        return;
    }

    if ( $already_rendered ) {
        return;
    }

    $already_rendered = true;

    echo '<div id="rh-checkout-loading" class="rh-checkout-loading" aria-hidden="true"><div class="spinner" aria-hidden="true"></div></div>';
}

add_action( 'cfw_before_checkout_form', 'wk_rh_render_checkout_loading_overlay', 6 );
add_action( 'cfw_before_main_content', 'wk_rh_render_checkout_loading_overlay', 6 );
add_action( 'woocommerce_before_checkout_form', 'wk_rh_render_checkout_loading_overlay', 6 );

add_action( 'wp', function() {
    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }

    remove_action( 'cfw_checkout_customer_info_tab', 'cfw_payment_request_buttons', 10 );
}, 20 );

add_filter( 'body_class', function( $classes ) {
    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return $classes;
    }

    $classes[] = 'wk-rh-checkout-flow';
    $classes[] = 'wk-rh-checkout-step-pending';

    return $classes;
} );

function wk_rh_get_checkout_previous_back_link_markup() {
    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return '';
    }

    $main_context = wk_rh_get_main_booking_context();
    if ( empty( $main_context['cartItemKey'] ) || ! wk_rh_is_checkout_booking_step_ready() ) {
        return '';
    }

    return sprintf(
        '<a href="javascript:" data-tab="#cfw-customer-info" class="cfw-prev-tab cfw-return-to-information-btn wk-rh-checkout-back-btn">&laquo; %s</a>',
        esc_html__( 'Back', 'racehall-wc-ui' )
    );
}

add_filter( 'cfw_return_to_cart_link', function( $link ) {
    $custom_link = wk_rh_get_checkout_previous_back_link_markup();
    return $custom_link !== '' ? $custom_link : $link;
}, 20 );

add_filter( 'cfw_return_to_customer_information_link', function( $link ) {
    $custom_link = wk_rh_get_checkout_previous_back_link_markup();
    return $custom_link !== '' ? $custom_link : $link;
}, 20 );

function wk_rh_render_checkout_step_customer_gate() {
    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }

    $main_context = wk_rh_get_main_booking_context();
    if ( empty( $main_context['cartItemKey'] ) ) {
        return;
    }

    echo wk_rh_get_checkout_step_customer_gate_markup( wk_rh_is_checkout_booking_step_ready() );
}

function wk_rh_get_checkout_step_customer_gate_markup( $is_ready = false ) {
    $is_ready = (bool) $is_ready;

    ob_start();
    ?>
    <div class="wk-rh-checkout-step-panel wk-rh-checkout-step-panel--customer <?php echo $is_ready ? 'is-ready' : 'is-pending'; ?>" data-step="customer">
        <div class="wk-rh-checkout-step-actions">
            <button
                type="button"
                class="cfw-primary-btn cfw-next-tab validate wk-rh-checkout-next-btn"
                data-label-default="<?php echo esc_attr__( 'Next', 'racehall-wc-ui' ); ?>"
                data-label-ready="<?php echo esc_attr__( 'Next', 'racehall-wc-ui' ); ?>"
                data-label-loading="<?php echo esc_attr__( 'Behandler...', 'racehall-wc-ui' ); ?>"
            >
                <?php esc_html_e( 'Next', 'racehall-wc-ui' ); ?>
            </button>
        </div>
        <div class="wk-rh-checkout-step-notice" aria-live="polite"></div>
    </div>
    <?php

    return (string) ob_get_clean();
}

add_action( 'cfw_checkout_customer_info_tab', 'wk_rh_render_checkout_step_customer_gate', 58 );

function wk_rh_render_checkout_step_supplements() {
    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }

    $main_context = wk_rh_get_main_booking_context();
    if ( empty( $main_context['cartItemKey'] ) ) {
        return;
    }

    echo wk_rh_get_checkout_step_supplements_markup( $main_context, wk_rh_is_checkout_booking_step_ready() );
}

function wk_rh_get_checkout_step_supplements_markup( array $main_context, $is_ready = false ) {
    $is_ready = (bool) $is_ready;
    $location = isset( $main_context['location'] ) ? (string) $main_context['location'] : '';
    $supplements = isset( $main_context['supplements'] ) && is_array( $main_context['supplements'] ) ? $main_context['supplements'] : [];

    ob_start();
    ?>
    <div class="wk-rh-checkout-step-panel wk-rh-checkout-step-panel--supplements <?php echo $is_ready ? 'is-ready' : 'is-locked'; ?>" data-step="supplements">
        <?php if ( ! $is_ready ) : ?>
            <div class="wk-rh-checkout-step-empty"></div>
        <?php elseif ( empty( $supplements ) ) : ?>
            <div class="racehall-cart cart-page wk-rh-checkout-addons-layout">
                <section class="left">
                    <h2 style="color: #fff;"><?php esc_html_e( 'FULDFØR DIN OPLEVELSE', 'racehall-wc-ui' ); ?></h2>
                    <p><?php esc_html_e( 'Løft din oplevelse til næste niveau. Her får du muligheden for at skræddersy dit race, finjustere detaljerne og sætte dit personlige præg på dagen. Uanset om jagten er fart, præcision eller bare den perfekte oplevelse, er dette stedet, hvor du former dit eget løb.', 'racehall-wc-ui' ); ?></p>
                    <div class="trophy">
                        <img src="<?php echo esc_url( plugins_url( 'assets/image/trophy.png', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Trophy illustration', 'racehall-wc-ui' ); ?>" />
                    </div>
                </section>
                <div class="center wk-rh-checkout-addons-shell">
                    <section class="addons wk-rh-checkout-addons-list">
                        <!-- <h2><?php esc_html_e( 'ADD ONS', 'racehall-wc-ui' ); ?></h2> -->
                        <div class="wk-rh-checkout-step-empty">
                            <?php esc_html_e( 'Der er ingen tilgængelige tilvalg til denne booking.', 'racehall-wc-ui' ); ?>
                        </div>
                    </section>
                </div>
            </div>
        <?php else : ?>
            <div class="racehall-cart cart-page wk-rh-checkout-addons-layout">
                <section class="left">
                    <h2 style="color: #fff;"><?php esc_html_e( 'FULDFØR DIN OPLEVELSE', 'racehall-wc-ui' ); ?></h2>
                    <p><?php esc_html_e( 'Løft din oplevelse til næste niveau. Her får du muligheden for at skræddersy dit race, finjustere detaljerne og sætte dit personlige præg på dagen. Uanset om jagten er fart, præcision eller bare den perfekte oplevelse, er dette stedet, hvor du former dit eget løb.', 'racehall-wc-ui' ); ?></p>
                    <div class="trophy">
                        <img src="<?php echo esc_url( plugins_url( 'assets/image/trophy.png', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Trophy illustration', 'racehall-wc-ui' ); ?>" />
                    </div>
                </section>
                <div class="center wk-rh-checkout-addons-shell">
                <section class="addons wk-rh-checkout-addons-list">
                    <div class="summary-item">
                <?php foreach ( $supplements as $supplement_row ) :
                    if ( ! is_array( $supplement_row ) ) {
                        continue;
                    }

                    $supplement = wk_rh_normalize_booking_supplement_row( $supplement_row );
                    $upstream_id = isset( $supplement['id'] ) ? trim( (string) $supplement['id'] ) : '';
                    if ( $upstream_id === '' ) {
                        continue;
                    }

                    $name = isset( $supplement['name'] ) ? (string) $supplement['name'] : $upstream_id;
                    $bounds = wk_rh_get_supplement_quantity_bounds( $supplement );
                    $min_qty = (int) $bounds['min'];
                    $max_qty = isset( $bounds['max'] ) ? (int) $bounds['max'] : 0;
                    $current_qty = wk_rh_get_addon_quantity_by_upstream_id( $upstream_id );
                    $display_qty = $current_qty > 0 ? $current_qty : $min_qty;
                    $price_amount = wk_rh_get_supplement_price_amount( $supplement );
                    $addon_image = function_exists( 'wk_rh_get_product_image_data_uri' )
                        ? wk_rh_get_product_image_data_uri( $location, $upstream_id )
                        : '';
                    ?>
                    <div
                        class="addon wk-rh-addon-card <?php echo $current_qty > 0 ? 'is-selected' : ''; ?>"
                        data-addon-upstream-id="<?php echo esc_attr( $upstream_id ); ?>"
                        data-current-qty="<?php echo esc_attr( $current_qty ); ?>"
                        data-min-qty="<?php echo esc_attr( $min_qty ); ?>"
                        data-display-qty="<?php echo esc_attr( $display_qty ); ?>"
                        data-is-selected="<?php echo esc_attr( $current_qty > 0 ? '1' : '0' ); ?>"
                        <?php if ( $max_qty > 0 ) : ?>data-max-qty="<?php echo esc_attr( $max_qty ); ?>"<?php else : ?>data-max-qty="0"<?php endif; ?>
                    >
                        <div class="info-container">
                            <?php if ( $addon_image !== '' ) : ?>
                                <div class="addon-img">
                                    <img src="<?php echo esc_attr( $addon_image ); ?>" alt="<?php echo esc_attr( wp_strip_all_tags( $name ) ); ?>" loading="lazy" />
                                </div>
                            <?php endif; ?>

                            <div class="addon-info">
                                <span class="title"><?php echo esc_html( $name ); ?></span>
                                <span class="price"><?php echo wp_kses_post( wc_price( $price_amount ) ); ?></span>
                            </div>
                        </div>

                        <div class="addon-control-row wk-rh-addon-card-actions">
                            <button type="button" class="wk-rh-addon-qty-btn" data-direction="decrease" aria-label="<?php echo esc_attr__( 'Decrease', 'racehall-wc-ui' ); ?>">-</button>
                            <input type="number" class="qty-input addon-qty-display" value="<?php echo esc_attr( $display_qty ); ?>" readonly>
                            <button type="button" class="wk-rh-addon-qty-btn" data-direction="increase" aria-label="<?php echo esc_attr__( 'Increase', 'racehall-wc-ui' ); ?>"<?php disabled( $max_qty > 0 && $display_qty >= $max_qty ); ?>>+</button>
                            <button type="button" class="button addon-add-button wk-rh-addon-add-btn"><?php esc_html_e( 'Tilføj', 'racehall-wc-ui' ); ?></button>
                        </div>
                    </div>
                <?php endforeach; ?>
                    </div>
                </section>
            </div>
            </div>
            <div class="wk-rh-checkout-step-notice wk-rh-checkout-step-notice--supplements" aria-live="polite"></div>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

add_action( 'cfw_checkout_payment_method_tab', 'wk_rh_render_checkout_step_supplements', 1 );

add_action( 'woocommerce_before_checkout_form', function() {
    static $already_rendered = false;

    if ( is_admin() ) {
        return;
    }

    if ( $already_rendered ) {
        return;
    }

    $already_rendered = true;

    $hold_ctx = wk_rh_get_cart_hold_expiry_context();
    $hold_expires_at = isset( $hold_ctx['expires_at'] ) ? (int) $hold_ctx['expires_at'] : 0;

    wk_rh_render_hold_banner_html(
        $hold_expires_at,
        __( 'Reservationstiden er udløbet. Du skal starte bookingflowet igen.', 'racehall-wc-ui' ),
        __( 'Din reservation holdes i:', 'racehall-wc-ui' )
    );
}, 5 );

function wk_rh_get_active_holds() {
    $holds = get_option( 'wk_rh_active_holds', [] );
    return is_array( $holds ) ? $holds : [];
}

function wk_rh_set_active_holds( array $holds ) {
    update_option( 'wk_rh_active_holds', $holds, false );
}

function wk_rh_register_active_hold( $upstream_order_id, $location, $expires_at, $context = [] ) {
    $upstream_order_id = trim( (string) $upstream_order_id );
    if ( $upstream_order_id === '' ) {
        return;
    }

    $holds = wk_rh_get_active_holds();
    $holds[ $upstream_order_id ] = [
        'location'   => sanitize_text_field( (string) $location ),
        'expires_at' => max( time(), (int) $expires_at ),
        'status'     => 'active',
        'updated_at' => time(),
        'context'    => is_array( $context ) ? $context : [],
    ];
    wk_rh_set_active_holds( $holds );
}

function wk_rh_release_active_hold( $upstream_order_id ) {
    $upstream_order_id = trim( (string) $upstream_order_id );
    if ( $upstream_order_id === '' ) {
        return;
    }

    $holds = wk_rh_get_active_holds();
    if ( isset( $holds[ $upstream_order_id ] ) ) {
        unset( $holds[ $upstream_order_id ] );
        wk_rh_set_active_holds( $holds );
    }
}

function wk_rh_process_expired_active_holds( $source = 'runtime' ) {
    if ( ! function_exists( 'wk_rh_cancel_upstream_order_by_id' ) ) {
        return;
    }

    $holds = wk_rh_get_active_holds();
    if ( empty( $holds ) ) {
        return;
    }

    $now = time();
    $changed = false;

    foreach ( $holds as $upstream_order_id => $hold ) {
        if ( ! is_array( $hold ) ) {
            unset( $holds[ $upstream_order_id ] );
            $changed = true;
            continue;
        }

        $expires_at = isset( $hold['expires_at'] ) ? (int) $hold['expires_at'] : 0;
        if ( $expires_at <= 0 || $expires_at > $now ) {
            continue;
        }

        $location = isset( $hold['location'] ) ? sanitize_text_field( (string) $hold['location'] ) : '';
        $ok = wk_rh_cancel_upstream_order_by_id( $upstream_order_id, $location, [
            'operation' => 'hold_timeout_cancel',
            'source'    => (string) $source,
        ] );

        if ( $ok ) {
            unset( $holds[ $upstream_order_id ] );
        } else {
            $holds[ $upstream_order_id ]['expires_at'] = $now + 300;
            $holds[ $upstream_order_id ]['updated_at'] = $now;
        }
        $changed = true;
    }

    if ( $changed ) {
        wk_rh_set_active_holds( $holds );
    }
}

function wk_rh_register_settings() {
    register_setting( 'wk_rh_settings_group', 'wk_rh_settings', [
        'sanitize_callback' => 'wk_rh_sanitize_settings',
    ] );
}
add_action( 'admin_init', 'wk_rh_register_settings' );

function wk_rh_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $settings = wk_rh_get_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Onsite Booking System Settings', 'onsite-booking-system' ); ?></h1>
        <?php settings_errors( 'wk_rh_settings' ); ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'wk_rh_settings_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="wk_rh_environment"><?php esc_html_e( 'Environment', 'onsite-booking-system' ); ?></label></th>
                    <td>
                        <select id="wk_rh_environment" name="wk_rh_settings[environment]">
                            <option value="test" <?php selected( $settings['environment'], 'test' ); ?>><?php esc_html_e( 'Test', 'onsite-booking-system' ); ?></option>
                            <option value="live" <?php selected( $settings['environment'], 'live' ); ?>><?php esc_html_e( 'Live', 'onsite-booking-system' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_test_base_url"><?php esc_html_e( 'Test Base URL', 'onsite-booking-system' ); ?></label></th>
                    <td><input class="regular-text" type="url" id="wk_rh_test_base_url" name="wk_rh_settings[test_base_url]" value="<?php echo esc_attr( $settings['test_base_url'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_live_base_url"><?php esc_html_e( 'Live Base URL', 'onsite-booking-system' ); ?></label></th>
                    <td><input class="regular-text" type="url" id="wk_rh_live_base_url" name="wk_rh_settings[live_base_url]" value="<?php echo esc_attr( $settings['live_base_url'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_accept_language"><?php esc_html_e( 'Accept-Language', 'onsite-booking-system' ); ?></label></th>
                    <td><input class="regular-text" type="text" id="wk_rh_accept_language" name="wk_rh_settings[accept_language]" value="<?php echo esc_attr( $settings['accept_language'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_logging_enabled"><?php esc_html_e( 'Enable logging', 'onsite-booking-system' ); ?></label></th>
                    <td>
                        <label for="wk_rh_logging_enabled">
                            <input type="checkbox" id="wk_rh_logging_enabled" name="wk_rh_settings[logging_enabled]" value="yes" <?php checked( $settings['logging_enabled'] ?? 'yes', 'yes' ); ?>>
                            <?php esc_html_e( 'Write API and user-action logs to uploads/onsite-booking.', 'onsite-booking-system' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_addon_product_id"><?php esc_html_e( 'Add-on carrier product', 'onsite-booking-system' ); ?></label></th>
                    <td>
                        <select id="wk_rh_addon_product_id" name="wk_rh_settings[addon_product_id]">
                            <option value="0"><?php esc_html_e( '— Select WooCommerce product —', 'onsite-booking-system' ); ?></option>
                            <?php foreach ( wk_rh_get_settings_product_options() as $product_id => $product_label ) : ?>
                                <option value="<?php echo esc_attr( $product_id ); ?>" <?php selected( absint( $settings['addon_product_id'] ?? 0 ), $product_id ); ?>><?php echo esc_html( $product_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Choose one hidden WooCommerce product to carry all add-ons in the cart. The customer-facing add-on name and price still come from the upstream response.', 'onsite-booking-system' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_booking_hold_timeout_minutes"><?php esc_html_e( 'Booking hold timeout (minutes)', 'onsite-booking-system' ); ?></label></th>
                    <td>
                        <input class="small-text" type="number" min="5" max="120" step="1" id="wk_rh_booking_hold_timeout_minutes" name="wk_rh_settings[booking_hold_timeout_minutes]" value="<?php echo esc_attr( wk_rh_get_booking_hold_timeout_minutes() ); ?>">
                        <p class="description"><?php esc_html_e( 'Recommended range is 10–20 minutes. When exceeded, held bookings are cancelled upstream and users must start again.', 'onsite-booking-system' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_test_locations_json"><?php esc_html_e( 'Test Location Credential Map (JSON)', 'onsite-booking-system' ); ?></label></th>
                    <td>
                        <textarea class="large-text code" rows="12" id="wk_rh_test_locations_json" name="wk_rh_settings[test_locations_json]"><?php echo esc_textarea( $settings['test_locations_json'] ?? '[]' ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Used only when Environment = Test.', 'onsite-booking-system' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wk_rh_live_locations_json"><?php esc_html_e( 'Live Location Credential Map (JSON)', 'onsite-booking-system' ); ?></label></th>
                    <td>
                        <textarea class="large-text code" rows="12" id="wk_rh_live_locations_json" name="wk_rh_settings[live_locations_json]"><?php echo esc_textarea( $settings['live_locations_json'] ?? '[]' ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Format: [{"location":"Copenhagen","client_key":"...","subscription_key":"...","username":"...","password":"..."}]', 'onsite-booking-system' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <script>
            (function () {
                var envSelect = document.getElementById('wk_rh_environment');
                var testArea = document.getElementById('wk_rh_test_locations_json');
                var liveArea = document.getElementById('wk_rh_live_locations_json');

                if (!envSelect || !testArea || !liveArea) {
                    return;
                }

                var testRow = testArea.closest('tr');
                var liveRow = liveArea.closest('tr');

                function refreshRows() {
                    var env = envSelect.value;
                    if (testRow) {
                        testRow.style.opacity = env === 'test' ? '1' : '0.55';
                    }
                    if (liveRow) {
                        liveRow.style.opacity = env === 'live' ? '1' : '0.55';
                    }
                }

                envSelect.addEventListener('change', refreshRows);
                refreshRows();
            })();
        </script>
    </div>
    <?php
}

function wk_rh_register_admin_menu() {
    add_menu_page(
        __( 'Onsite Booking', 'onsite-booking-system' ),
        __( 'Onsite Booking', 'onsite-booking-system' ),
        'manage_options',
        'wk-rh-settings',
        'wk_rh_render_settings_page',
        'dashicons-calendar-alt',
        56
    );

    add_submenu_page(
        'wk-rh-settings',
        __( 'Diagnostics', 'onsite-booking-system' ),
        __( 'Diagnostics', 'onsite-booking-system' ),
        'manage_options',
        'wk-rh-diagnostics',
        'wk_rh_render_diagnostics_page'
    );

    add_submenu_page(
        'wk-rh-settings',
        __( 'Upstream Data', 'onsite-booking-system' ),
        __( 'Upstream Data', 'onsite-booking-system' ),
        'manage_options',
        'wk-rh-upstream-data',
        'wk_rh_render_upstream_data_page'
    );
}
add_action( 'admin_menu', 'wk_rh_register_admin_menu' );

add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['wk_rh_every_five_minutes'] ) ) {
        $schedules['wk_rh_every_five_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Every 5 minutes (Onsite Booking)', 'onsite-booking-system' ),
        ];
    }
    return $schedules;
} );

add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'wk_rh_expire_booking_holds_event' ) ) {
        wp_schedule_event( time() + 60, 'wk_rh_every_five_minutes', 'wk_rh_expire_booking_holds_event' );
    }
} );

add_action( 'wk_rh_expire_booking_holds_event', function() {
    wk_rh_process_expired_active_holds( 'cron' );
} );

function wk_rh_get_upstream_products_cache_key( $location = '' ) {
    $settings = wk_rh_get_settings();
    $env      = isset( $settings['environment'] ) ? sanitize_text_field( $settings['environment'] ) : 'test';
    $loc      = strtolower( trim( (string) $location ) );
    return 'wk_rh_products_' . md5( $env . '|' . $loc );
}

function wk_rh_get_last_order_sync_error( $wc_order_id ) {
    foreach ( [ 'live', 'testapi' ] as $environment ) {
        $logs = wk_rh_get_recent_log_entries( [ 'api' ], 250, $environment );
        foreach ( $logs as $log ) {
            if ( ! is_array( $log ) ) {
                continue;
            }

            $level = isset( $log['level'] ) ? strtolower( (string) $log['level'] ) : '';
            if ( ! in_array( $level, [ 'error', 'warning' ], true ) ) {
                continue;
            }

            $context = isset( $log['context'] ) && is_array( $log['context'] ) ? $log['context'] : [];
            if ( isset( $context['wcOrderId'] ) && (string) $context['wcOrderId'] === (string) $wc_order_id ) {
                return $log;
            }
        }
    }

    return null;
}

function wk_rh_render_upstream_data_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $profiles = wk_rh_get_location_profiles();
    $selected_location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';

    if ( $selected_location === '' && ! empty( $profiles ) && ! empty( $profiles[0]['location'] ) ) {
        $selected_location = (string) $profiles[0]['location'];
    }

    $cache_key = wk_rh_get_upstream_products_cache_key( $selected_location );
    $refresh_requested = isset( $_GET['refresh_products'] ) && $_GET['refresh_products'] === '1';
    if ( $refresh_requested ) {
        check_admin_referer( 'wk_rh_refresh_products_' . $selected_location );
        delete_transient( $cache_key );
    }

    $products_data = get_transient( $cache_key );
    if ( ! is_array( $products_data ) ) {
        $products_data = [
            'items' => [],
            'fetched_at' => '',
            'error' => '',
        ];

        if ( $selected_location !== '' ) {
            $token = function_exists( 'wk_rh_get_token' ) ? wk_rh_get_token( $selected_location ) : false;
            if ( ! $token ) {
                $products_data['error'] = __( 'Could not fetch token for selected location.', 'onsite-booking-system' );
            } else {
                $items = function_exists( 'wk_rh_get_products' ) ? wk_rh_get_products( $token, $selected_location ) : [];
                if ( is_array( $items ) ) {
                    $products_data['items'] = $items;
                    $products_data['fetched_at'] = gmdate( 'c' );
                    set_transient( $cache_key, $products_data, 5 * MINUTE_IN_SECONDS );
                } else {
                    $products_data['error'] = __( 'Upstream products response was not an array.', 'onsite-booking-system' );
                }
            }
        }
    }

    $orders = function_exists( 'wc_get_orders' ) ? wc_get_orders([
        'limit'   => 30,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
    ]) : [];

    $refresh_url = wp_nonce_url(
        add_query_arg([
            'page' => 'wk-rh-upstream-data',
            'location' => $selected_location,
            'refresh_products' => '1',
        ], admin_url( 'admin.php' ) ),
        'wk_rh_refresh_products_' . $selected_location
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Onsite Booking Upstream Data', 'onsite-booking-system' ); ?></h1>

        <form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="wk-rh-upstream-data">
            <label for="wk_rh_location_select"><strong><?php esc_html_e( 'Location', 'onsite-booking-system' ); ?>:</strong></label>
            <select id="wk_rh_location_select" name="location">
                <option value=""><?php esc_html_e( 'Select location', 'onsite-booking-system' ); ?></option>
                <?php foreach ( $profiles as $profile ) :
                    $location_name = isset( $profile['location'] ) ? (string) $profile['location'] : '';
                    if ( $location_name === '' ) {
                        continue;
                    }
                ?>
                    <option value="<?php echo esc_attr( $location_name ); ?>" <?php selected( $selected_location, $location_name ); ?>>
                        <?php echo esc_html( $location_name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( __( 'Apply', 'onsite-booking-system' ), 'secondary', 'submit', false ); ?>
            <a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh Products', 'onsite-booking-system' ); ?></a>
        </form>

        <h2><?php esc_html_e( 'Upstream Products', 'onsite-booking-system' ); ?></h2>
        <?php if ( ! empty( $products_data['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $products_data['error'] ); ?></p></div>
        <?php endif; ?>
        <p>
            <?php
            if ( ! empty( $products_data['fetched_at'] ) ) {
                echo esc_html( sprintf( __( 'Last fetched (UTC): %s', 'onsite-booking-system' ), $products_data['fetched_at'] ) );
            } else {
                esc_html_e( 'No cached fetch yet.', 'onsite-booking-system' );
            }
            ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:120px;"><?php esc_html_e( 'Product ID', 'onsite-booking-system' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'onsite-booking-system' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( 'Resource ID', 'onsite-booking-system' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'Sale Mode', 'onsite-booking-system' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $products_data['items'] ) ) : ?>
                <tr><td colspan="4"><?php esc_html_e( 'No products available for selected location.', 'onsite-booking-system' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $products_data['items'] as $product_item ) :
                    if ( ! is_array( $product_item ) ) {
                        continue;
                    }
                ?>
                    <tr>
                        <td><?php echo esc_html( (string) ( $product_item['id'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $product_item['name'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $product_item['resourceId'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $product_item['saleMode'] ?? '' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top:24px;"><?php esc_html_e( 'Booking Sync State (Woo → Upstream)', 'onsite-booking-system' ); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:90px;"><?php esc_html_e( 'Order', 'onsite-booking-system' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( 'Status', 'onsite-booking-system' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( 'Location', 'onsite-booking-system' ); ?></th>
                    <th style="width:170px;"><?php esc_html_e( 'Upstream Order ID', 'onsite-booking-system' ); ?></th>
                    <th style="width:180px;"><?php esc_html_e( 'Upstream Item IDs', 'onsite-booking-system' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Payment', 'onsite-booking-system' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Cancel', 'onsite-booking-system' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Memo', 'onsite-booking-system' ); ?></th>
                    <th><?php esc_html_e( 'Last Error/Warning', 'onsite-booking-system' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $orders ) ) : ?>
                <tr><td colspan="9"><?php esc_html_e( 'No orders found.', 'onsite-booking-system' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $orders as $order ) :
                    if ( ! $order instanceof WC_Order ) {
                        continue;
                    }

                    $order_id = (int) $order->get_id();
                    $location = function_exists( 'wk_rh_get_order_booking_location' ) ? wk_rh_get_order_booking_location( $order ) : '';
                    $upstream_order_id = function_exists( 'wk_rh_get_order_upstream_order_id' ) ? wk_rh_get_order_upstream_order_id( $order ) : (string) $order->get_meta( 'bmi_order_id', true );

                    $upstream_item_ids = [];
                    foreach ( $order->get_items() as $item ) {
                        $item_id = (string) $item->get_meta( 'bmi_order_item_id', true );
                        if ( $item_id !== '' ) {
                            $upstream_item_ids[] = $item_id;
                        }
                    }
                    $upstream_item_ids = array_values( array_unique( $upstream_item_ids ) );

                    $payment_synced = $order->get_meta( '_wk_rh_payment_confirmed', true ) === 'yes';
                    $cancel_synced  = $order->get_meta( '_wk_rh_cancel_synced', true ) === 'yes';
                    $memo_synced    = $order->get_meta( '_wk_rh_memo_synced', true ) === 'yes';

                    $last_error = wk_rh_get_last_order_sync_error( $order_id );
                    $last_error_text = '';
                    if ( is_array( $last_error ) ) {
                        $last_error_text = sprintf(
                            '%s: %s',
                            strtoupper( (string) ( $last_error['level'] ?? '' ) ),
                            (string) ( $last_error['message'] ?? '' )
                        );
                    }
                ?>
                    <tr>
                        <td><?php echo esc_html( '#' . $order_id ); ?></td>
                        <td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
                        <td><?php echo esc_html( (string) $location ); ?></td>
                        <td><?php echo esc_html( (string) $upstream_order_id ); ?></td>
                        <td><?php echo esc_html( implode( ', ', $upstream_item_ids ) ); ?></td>
                        <td><?php echo $payment_synced ? '✅' : '—'; ?></td>
                        <td><?php echo $cancel_synced ? '✅' : '—'; ?></td>
                        <td><?php echo $memo_synced ? '✅' : '—'; ?></td>
                        <td><?php echo esc_html( $last_error_text ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function wk_rh_render_diagnostics_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['wk_rh_clear_logs'] ) ) {
        check_admin_referer( 'wk_rh_clear_logs_action', 'wk_rh_clear_logs_nonce' );
        $deleted = wk_rh_clear_log_files();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Log files cleared (%d deleted).', 'onsite-booking-system' ), $deleted ) ) . '</p></div>';
    }

    $environment = wk_rh_get_log_environment();
    $log_directory = wk_rh_get_log_directory( $environment );
    $logs = wk_rh_get_recent_log_entries( [ 'api', 'user-actions' ], 250, $environment );
    $logging_enabled = wk_rh_is_logging_enabled();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Onsite Booking Diagnostics', 'onsite-booking-system' ); ?></h1>
        <p><?php esc_html_e( 'Recent booking lifecycle logs (latest first).', 'onsite-booking-system' ); ?></p>
        <p>
            <strong><?php esc_html_e( 'Logging:', 'onsite-booking-system' ); ?></strong>
            <?php echo esc_html( $logging_enabled ? __( 'Enabled', 'onsite-booking-system' ) : __( 'Disabled', 'onsite-booking-system' ) ); ?>
            <br>
            <strong><?php esc_html_e( 'Active log environment:', 'onsite-booking-system' ); ?></strong>
            <?php echo esc_html( $environment ); ?>
            <br>
            <strong><?php esc_html_e( 'Directory:', 'onsite-booking-system' ); ?></strong>
            <?php echo esc_html( trailingslashit( (string) $log_directory ) . gmdate( 'd_m_Y' ) ); ?>
        </p>

        <?php if ( ! $logging_enabled ) : ?>
            <div class="notice notice-warning"><p><?php esc_html_e( 'Logging is currently disabled in the plugin settings. No new log entries will be written until it is enabled again.', 'onsite-booking-system' ); ?></p></div>
        <?php endif; ?>

        <form method="post" style="margin: 12px 0 16px;">
            <?php wp_nonce_field( 'wk_rh_clear_logs_action', 'wk_rh_clear_logs_nonce' ); ?>
            <input type="hidden" name="wk_rh_clear_logs" value="1">
            <?php submit_button( __( 'Clear Logs', 'onsite-booking-system' ), 'secondary', 'submit', false ); ?>
        </form>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:180px;"><?php esc_html_e( 'Time (UTC)', 'onsite-booking-system' ); ?></th>
                    <th style="width:110px;"><?php esc_html_e( 'Channel', 'onsite-booking-system' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Level', 'onsite-booking-system' ); ?></th>
                    <th style="width:280px;"><?php esc_html_e( 'Message', 'onsite-booking-system' ); ?></th>
                    <th><?php esc_html_e( 'Context', 'onsite-booking-system' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr>
                    <td colspan="5"><?php esc_html_e( 'No booking events logged yet.', 'onsite-booking-system' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <?php
                    $time    = isset( $log['time'] ) ? (string) $log['time'] : '';
                    $channel = isset( $log['channel'] ) ? strtoupper( (string) $log['channel'] ) : '';
                    $level   = isset( $log['level'] ) ? strtoupper( (string) $log['level'] ) : '';
                    $message = isset( $log['message'] ) ? (string) $log['message'] : '';
                    $context = isset( $log['context'] ) && is_array( $log['context'] ) ? $log['context'] : [];
                    ?>
                    <tr>
                        <td><?php echo esc_html( $time ); ?></td>
                        <td><?php echo esc_html( $channel ); ?></td>
                        <td><?php echo esc_html( $level ); ?></td>
                        <td><?php echo esc_html( $message ); ?></td>
                        <td><code><?php echo esc_html( wp_json_encode( $context ) ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

require_once RACEHALL_WC_UI_PATH . 'templates/hooks.php';

function wk_rh_handle_client_log_event() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'rh_logger_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
    }

    $event = isset( $_POST['event'] ) ? preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) wp_unslash( $_POST['event'] ) ) : '';
    if ( $event === '' ) {
        wp_send_json_error( [ 'message' => 'Missing event' ], 400 );
    }

    $raw_context = isset( $_POST['context'] ) ? wp_unslash( (string) $_POST['context'] ) : '';
    if ( strlen( $raw_context ) > 8000 ) {
        $raw_context = substr( $raw_context, 0, 8000 );
    }

    $context = json_decode( $raw_context, true );
    if ( ! is_array( $context ) ) {
        $context = [];
    }

    wk_rh_log_user_event( 'client.' . $event, $context );
    wp_send_json_success( [ 'logged' => true ] );
}

add_action( 'wp_ajax_rh_log_client_event', 'wk_rh_handle_client_log_event' );
add_action( 'wp_ajax_nopriv_rh_log_client_event', 'wk_rh_handle_client_log_event' );

add_filter( 'wc_add_to_cart_message_html', '__return_empty_string', 10, 2 );

function wk_rh_mark_add_to_cart_success_for_redirect( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    $GLOBALS['wk_rh_add_to_cart_succeeded'] = true;
}

add_action( 'woocommerce_add_to_cart', 'wk_rh_mark_add_to_cart_success_for_redirect', 5, 6 );

// Enqueue CSS/JS for single product pages
add_action('wp_enqueue_scripts', function() {
    $logger_config = [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'rh_logger_nonce' ),
    ];

    if ( is_product() ) {
        wp_enqueue_style(
            'racehall-single-product-css',
            RACEHALL_WC_UI_URL . 'assets/css/single-product.css',
            [],
            RACEHALL_WC_UI_VERSION
        );
        wp_enqueue_script(
            'racehall-single-product-js',
            RACEHALL_WC_UI_URL . 'assets/js/single-product.js',
            ['jquery'],
            RACEHALL_WC_UI_VERSION,
            true
        );
        wp_localize_script('racehall-single-product-js', 'my_ajax_object', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('my_ajax_nonce')
        ]);
        wp_localize_script( 'racehall-single-product-js', 'RH_LOGGER', array_merge( $logger_config, [ 'page_type' => 'product' ] ) );
    }
    // CART PAGE
    if ( is_cart() ) {
        wp_enqueue_style(
            'racehall-cart-css',
            RACEHALL_WC_UI_URL . 'assets/css/cart.css',
            [],
                        RACEHALL_WC_UI_VERSION
        );
          wp_enqueue_script(
            'racehall-cart-js',
            RACEHALL_WC_UI_URL . 'assets/js/cart.js',
            ['jquery'],
                        RACEHALL_WC_UI_VERSION,
            true
        );

                wp_localize_script( 'racehall-cart-js', 'RH_HOLD_TIMER', [
                        'ajax_url' => admin_url( 'admin-ajax.php' ),
                        'nonce' => wp_create_nonce( 'rh_hold_nonce' ),
                        'fallback_redirect' => wk_rh_get_main_booking_product_url(),
                ] );
                wp_localize_script( 'racehall-cart-js', 'RH_LOGGER', array_merge( $logger_config, [ 'page_type' => 'cart' ] ) );
    }

    if ( is_checkout() ) {
        $checkout_flow_context = wk_rh_get_main_booking_context();
        wp_enqueue_style(
            'racehall-checkout-css',
            RACEHALL_WC_UI_URL . 'assets/css/checkout.css',
            [],
            RACEHALL_WC_UI_VERSION
        );
        wp_enqueue_style(
            'racehall-cart-css',
            RACEHALL_WC_UI_URL . 'assets/css/cart.css',
            [ 'racehall-checkout-css' ],
            RACEHALL_WC_UI_VERSION
        );

        wp_enqueue_script(
            'racehall-checkout-js',
            RACEHALL_WC_UI_URL . 'assets/js/checkout.js',
            ['jquery'],
            RACEHALL_WC_UI_VERSION,
            true
        );

        wp_localize_script( 'racehall-checkout-js', 'RH_HOLD_TIMER', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'rh_hold_nonce' ),
            'fallback_redirect' => wk_rh_get_main_booking_product_url(),
        ] );
        wp_localize_script( 'racehall-checkout-js', 'RH_CHECKOUT_FLOW', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'rh_checkout_step_nonce' ),
            'is_step_ready' => ! empty( $checkout_flow_context['orderId'] ) && ! empty( $checkout_flow_context['orderItemId'] ),
            'required_fields' => wk_rh_get_checkout_step_required_keys(),
            'messages' => [
                'invalidCustomerInfo' => __( 'Udfyld venligst alle påkrævede kundeoplysninger før du går videre.', 'racehall-wc-ui' ),
                'changedCustomerInfo' => __( 'Kundeoplysninger er ændret. Klik på knappen igen for at opdatere bookinggrundlaget.', 'racehall-wc-ui' ),
                'processing' => __( 'Behandler...', 'racehall-wc-ui' ),
                'genericError' => __( 'Noget gik galt. Prøv igen.', 'racehall-wc-ui' ),
                'supplementUpdating' => __( 'Opdaterer tilvalg...', 'racehall-wc-ui' ),
            ],
        ] );
        wp_localize_script( 'racehall-checkout-js', 'RH_LOGGER', array_merge( $logger_config, [ 'page_type' => 'checkout' ] ) );
    }

});

add_action( 'template_redirect', function() {
    if ( is_admin() ) {
        return;
    }

    if ( is_product() ) {
        wk_rh_log_user_event( 'page.view', [ 'pageType' => 'product', 'productId' => get_the_ID() ] );
    } elseif ( is_cart() ) {
        wk_rh_log_user_event( 'page.view', [ 'pageType' => 'cart' ] );
    } elseif ( is_checkout() ) {
        wk_rh_log_user_event( 'page.view', [ 'pageType' => 'checkout' ] );
    }
}, 5 );

// Replace single product page content
add_action('template_redirect', function() {
    if ( is_product() ) {

        // Remove all default WooCommerce single product hooks
        remove_all_actions('woocommerce_before_single_product');
        remove_all_actions('woocommerce_before_single_product_summary');
        remove_all_actions('woocommerce_single_product_summary');
        remove_all_actions('woocommerce_after_single_product_summary');
        remove_all_actions('woocommerce_after_single_product');

        // Load custom template file
        $template = RACEHALL_WC_UI_PATH . 'templates/single-product.php';
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            echo '<p>' . esc_html__( 'Custom single product template not found.', 'racehall-wc-ui' ) . '</p>';
        }

        // Stop further rendering
        exit;
    }

    // CART
    if ( is_cart() ) {
        if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        if ( isset( $_GET['rh_clear_cart'] ) && $_GET['rh_clear_cart'] === '1' ) {
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'rh_clear_cart' ) ) {
                wc_add_notice( __( 'Ugyldig forespørgsel. Prøv igen.', 'racehall-wc-ui' ), 'error' );
                wp_safe_redirect( wc_get_cart_url() );
                exit;
            }

            $orders_to_cancel = [];
            if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
                foreach ( WC()->cart->get_cart() as $item ) {
                    if ( ! empty( $item['is_addon'] ) ) {
                        continue;
                    }
                    $order_id = isset( $item['bmi_order_id'] ) ? trim( (string) $item['bmi_order_id'] ) : '';
                    if ( $order_id === '' ) {
                        continue;
                    }
                    $orders_to_cancel[ $order_id ] = isset( $item['booking_location'] ) ? sanitize_text_field( (string) $item['booking_location'] ) : '';
                }
            }

            foreach ( $orders_to_cancel as $order_id => $location ) {
                if ( function_exists( 'wk_rh_cancel_upstream_order_by_id' ) ) {
                    wk_rh_cancel_upstream_order_by_id( $order_id, $location, [ 'source' => 'manual_clear_cart' ] );
                }
                if ( function_exists( 'wk_rh_release_active_hold' ) ) {
                    wk_rh_release_active_hold( $order_id );
                }
            }

            if ( function_exists( 'WC' ) && WC()->cart ) {
                WC()->cart->empty_cart();
            }
            if ( function_exists( 'wk_rh_clear_booking_session_state' ) ) {
                wk_rh_clear_booking_session_state();
            }

            wc_add_notice( __( 'Kurven er ryddet.', 'racehall-wc-ui' ), 'success' );
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        remove_all_actions( 'woocommerce_before_cart' );
        remove_all_actions( 'woocommerce_cart_contents' );
        remove_all_actions( 'woocommerce_cart_actions' );
        remove_all_actions( 'woocommerce_after_cart' );

        include RACEHALL_WC_UI_PATH . 'templates/cart.php';
        exit;
    }

});


// ----------------------
// Main product & add-ons logic
// ----------------------
add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_block_direct_addon_carrier_purchase', 5, 3 );
function wk_rh_block_direct_addon_carrier_purchase( $passed, $product_id, $quantity ) {
    if ( ! $passed ) {
        return false;
    }

    if ( isset( $_POST['is_addon'] ) ) {
        return $passed;
    }

    if ( ! wk_rh_is_configured_addon_product( $product_id ) ) {
        return $passed;
    }

    wk_rh_log_user_event( 'addon.add_blocked', [
        'reason' => 'direct_carrier_purchase_blocked',
        'productId' => (int) $product_id,
    ], 'warning' );

    wc_add_notice( __( 'Dette produkt kan kun tilføjes som et add-on fra bookingforløbet.', 'racehall-wc-ui' ), 'error' );
    return false;
}

add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_replace_main_product_only', 10, 3 );
function wk_rh_replace_main_product_only( $passed, $product_id, $quantity ) {
    if ( isset( $_POST['is_addon'] ) ) {
        return $passed;
    }

    $is_main_product = get_post_meta( $product_id, 'bmileisure_id', true );
    if ( ! $is_main_product || WC()->cart->is_empty() ) return $passed;

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        $cart_product_id = $cart_item['product_id'];
        $cart_is_main    = get_post_meta( $cart_product_id, 'bmileisure_id', true );
        if ( $cart_is_main ) WC()->cart->remove_cart_item( $cart_item_key );
    }
    return $passed;
}

add_action( 'woocommerce_remove_cart_item', function( $cart_item_key, $cart ) {
    $item = $cart->get_cart_item( $cart_item_key );
    if ( is_array( $item ) ) {
        wk_rh_log_user_event( 'cart.item_removed', [
            'cartItemKey' => (string) $cart_item_key,
            'productId' => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
            'isAddon' => ! empty( $item['is_addon'] ),
            'upstreamOrderId' => isset( $item['bmi_order_id'] ) ? (string) $item['bmi_order_id'] : '',
            'upstreamOrderItemId' => isset( $item['bmi_order_item_id'] ) ? (string) $item['bmi_order_item_id'] : '',
            'bookingLocation' => isset( $item['booking_location'] ) ? (string) $item['booking_location'] : '',
        ] );
    }

    if ( ! empty( $item['is_addon'] ) && ! empty( $item['bmi_order_id'] ) && ! empty( $item['bmi_order_item_id'] ) ) {
        $location = ! empty( $item['booking_location'] ) ? sanitize_text_field( $item['booking_location'] ) : '';
        if ( function_exists( 'wk_rh_remove_upstream_order_item' ) ) {
            wk_rh_remove_upstream_order_item( $location, $item['bmi_order_id'], $item['bmi_order_item_id'] );
        }
    }

    $removed_product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
    $is_main_product_removed = $removed_product_id > 0
        && empty( $item['is_addon'] )
        && ! empty( get_post_meta( $removed_product_id, 'bmileisure_id', true ) );

    if ( ! $is_main_product_removed ) return;

    wk_rh_log_user_event( 'cart.main_booking_removed', [
        'cartItemKey' => (string) $cart_item_key,
        'productId' => $removed_product_id,
        'upstreamOrderId' => isset( $item['bmi_order_id'] ) ? (string) $item['bmi_order_id'] : '',
    ] );

    $main_order_id = isset( $item['bmi_order_id'] ) ? trim( (string) $item['bmi_order_id'] ) : '';
    $main_location = ! empty( $item['booking_location'] ) ? sanitize_text_field( $item['booking_location'] ) : '';
    if ( $main_order_id !== '' && function_exists( 'wk_rh_cancel_upstream_order_by_id' ) ) {
        wk_rh_cancel_upstream_order_by_id( $main_order_id, $main_location, [
            'source' => 'cart_remove_main',
        ] );
    }

    if ( $main_order_id !== '' && function_exists( 'wk_rh_release_active_hold' ) ) {
        wk_rh_release_active_hold( $main_order_id );
    }

    if ( function_exists( 'wk_rh_clear_booking_session_state' ) ) {
        wk_rh_clear_booking_session_state();
    }

    foreach ( $cart->get_cart() as $key => $cart_item ) {
        if ( isset( $cart_item['is_addon'] ) ) $cart->remove_cart_item( $key );
    }
}, 10, 2 );

add_filter( 'woocommerce_add_cart_item_data', 'wk_rh_addon_cart_item_data', 10, 2 );
function wk_rh_addon_cart_item_data( $cart_item_data, $product_id ) {
    $is_addon_request = isset( $_POST['is_addon'] );
    if ( $is_addon_request ) $cart_item_data['is_addon'] = true;
    if ( ! empty( $_POST['parent_racehall_product'] ) ) $cart_item_data['parent_racehall_product'] = absint($_POST['parent_racehall_product']);
    if ( ! empty( $_POST['booking_location'] ) ) $cart_item_data['booking_location'] = sanitize_text_field($_POST['booking_location']);
    if ( $is_addon_request && isset( $_POST['addon_price'] ) && is_numeric( $_POST['addon_price'] ) ) {
        $cart_item_data['addon_unit_price'] = wc_format_decimal( wp_unslash( $_POST['addon_price'] ) );
    }
    if ( $is_addon_request && ! empty( $_POST['addon_upstream_id'] ) ) {
        $cart_item_data['addon_upstream_id'] = sanitize_text_field( wp_unslash( $_POST['addon_upstream_id'] ) );
    }
    if ( $is_addon_request && isset( $_POST['addon_supplement_id'] ) ) {
        $cart_item_data['addon_supplement_id'] = sanitize_text_field( wp_unslash( $_POST['addon_supplement_id'] ) );
    }
    if ( $is_addon_request && isset( $_POST['addon_display_name'] ) ) {
        $cart_item_data['addon_display_name'] = sanitize_text_field( wp_unslash( $_POST['addon_display_name'] ) );
    }
    if ( $is_addon_request && isset( $_POST['addon_min_qty'] ) && is_numeric( $_POST['addon_min_qty'] ) ) {
        $cart_item_data['addon_min_qty'] = max( 1, (int) $_POST['addon_min_qty'] );
    }
    if ( $is_addon_request && isset( $_POST['addon_max_qty'] ) && $_POST['addon_max_qty'] !== '' && is_numeric( $_POST['addon_max_qty'] ) ) {
        $cart_item_data['addon_max_qty'] = max( 1, (int) $_POST['addon_max_qty'] );
    }

    // BMI booking session data (stored by rh_save_proposal AJAX, retrieved here)
    $bmi_data = WC()->session ? WC()->session->get('rh_bmi_booking') : null;
    if ( $bmi_data && ! $is_addon_request ) {
        $cart_item_data['bmi_proposal']    = $bmi_data['proposal']    ?? null;
        $cart_item_data['bmi_page_id']     = $bmi_data['pageId']      ?? '';
        $cart_item_data['bmi_resource_id'] = $bmi_data['resourceId']  ?? '';
        $cart_item_data['bmi_page_product_limits'] = $bmi_data['pageProductLimits'] ?? null;
        $cart_item_data['bmi_page_products'] = $bmi_data['pageProducts'] ?? [];
        $cart_item_data['bmi_order_id']    = '';
        $cart_item_data['bmi_order_item_id'] = '';
        $cart_item_data['bmi_hold_expires_at'] = 0;

        if ( WC()->session ) {
            if ( is_array( $bmi_data ) ) {
                $bmi_data['orderId'] = '';
                $bmi_data['orderItemId'] = '';
                $bmi_data['expiresAt'] = 0;
                $bmi_data['contactPerson'] = [];
                WC()->session->set( 'rh_bmi_booking', $bmi_data );
            }

            WC()->session->set( 'booking_supplement', null );
        }
    }

    if ( $is_addon_request ) {
        unset(
            $cart_item_data['bmi_proposal'],
            $cart_item_data['bmi_page_id'],
            $cart_item_data['bmi_resource_id'],
            $cart_item_data['bmi_page_product_limits'],
            $cart_item_data['bmi_page_products']
        );
    }

    return $cart_item_data;
}

add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_validate_addon_quantity_rules', 28, 3 );
function wk_rh_validate_addon_quantity_rules( $passed, $product_id, $quantity ) {
    if ( ! $passed || ! isset( $_POST['is_addon'] ) ) {
        return $passed;
    }

    $min_qty = isset( $_POST['addon_min_qty'] ) && is_numeric( $_POST['addon_min_qty'] )
        ? max( 1, (int) $_POST['addon_min_qty'] )
        : 1;
    $max_qty = isset( $_POST['addon_max_qty'] ) && $_POST['addon_max_qty'] !== '' && is_numeric( $_POST['addon_max_qty'] )
        ? max( $min_qty, (int) $_POST['addon_max_qty'] )
        : null;
    $requested_qty = max( 1, (int) $quantity );

    if ( $requested_qty < $min_qty ) {
        wc_add_notice( sprintf( __( 'Add-on quantity must be at least %d.', 'racehall-wc-ui' ), $min_qty ), 'error' );
        return false;
    }

    if ( $max_qty !== null && $requested_qty > $max_qty ) {
        wc_add_notice( sprintf( __( 'Add-on quantity cannot exceed %d.', 'racehall-wc-ui' ), $max_qty ), 'error' );
        return false;
    }

    return $passed;
}

add_filter( 'woocommerce_update_cart_validation', 'wk_rh_validate_addon_update_quantity_rules', 20, 4 );
function wk_rh_validate_addon_update_quantity_rules( $passed, $cart_item_key, $values, $quantity ) {
    if ( ! $passed || empty( $values['is_addon'] ) ) {
        return $passed;
    }

    $min_qty = isset( $values['addon_min_qty'] ) && is_numeric( $values['addon_min_qty'] )
        ? max( 1, (int) $values['addon_min_qty'] )
        : 1;
    $max_qty = isset( $values['addon_max_qty'] ) && is_numeric( $values['addon_max_qty'] )
        ? max( $min_qty, (int) $values['addon_max_qty'] )
        : null;
    $requested_qty = max( 0, (int) $quantity );

    if ( $requested_qty === 0 ) {
        return $passed;
    }

    if ( $requested_qty < $min_qty ) {
        wc_add_notice( sprintf( __( 'Add-on quantity must be at least %d.', 'racehall-wc-ui' ), $min_qty ), 'error' );
        return false;
    }

    if ( $max_qty !== null && $requested_qty > $max_qty ) {
        wc_add_notice( sprintf( __( 'Add-on quantity cannot exceed %d.', 'racehall-wc-ui' ), $max_qty ), 'error' );
        return false;
    }

    return $passed;
}

add_filter( 'woocommerce_add_to_cart_redirect', function( $url ) {
    if ( ! empty( $_REQUEST['add-to-cart'] ) && ! empty( $GLOBALS['wk_rh_add_to_cart_succeeded'] ) ) {
        wk_rh_log_user_event( 'cart.redirect_to_cart', [
            'requestedProductId' => isset( $_REQUEST['add-to-cart'] ) ? (int) $_REQUEST['add-to-cart'] : 0,
        ] );
        return wc_get_checkout_url();
    }
    return $url;
}, 20 );

add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values, $cart_item_key ) {
    if ( empty( $cart_item['is_addon'] ) ) {
        return $cart_item;
    }

    $cart_item['is_addon'] = true;

    if ( isset( $values['parent_racehall_product'] ) ) {
        $cart_item['parent_racehall_product'] = absint( $values['parent_racehall_product'] );
    }

    if ( isset( $values['booking_location'] ) ) {
        $cart_item['booking_location'] = sanitize_text_field( (string) $values['booking_location'] );
    }

    if ( isset( $values['addon_unit_price'] ) && is_numeric( $values['addon_unit_price'] ) ) {
        $cart_item['addon_unit_price'] = wc_format_decimal( $values['addon_unit_price'] );
    }

    if ( isset( $values['addon_upstream_id'] ) ) {
        $cart_item['addon_upstream_id'] = sanitize_text_field( (string) $values['addon_upstream_id'] );
    } elseif ( isset( $values['addon_upstream_product_id'] ) ) {
        $cart_item['addon_upstream_id'] = sanitize_text_field( (string) $values['addon_upstream_product_id'] );
    }

    if ( isset( $values['addon_supplement_id'] ) ) {
        $cart_item['addon_supplement_id'] = sanitize_text_field( (string) $values['addon_supplement_id'] );
    }

    if ( isset( $values['addon_display_name'] ) ) {
        $cart_item['addon_display_name'] = sanitize_text_field( (string) $values['addon_display_name'] );
    }

    if ( isset( $values['addon_min_qty'] ) && is_numeric( $values['addon_min_qty'] ) ) {
        $cart_item['addon_min_qty'] = max( 1, (int) $values['addon_min_qty'] );
    }

    if ( isset( $values['addon_max_qty'] ) && is_numeric( $values['addon_max_qty'] ) ) {
        $cart_item['addon_max_qty'] = max( 1, (int) $values['addon_max_qty'] );
    }

    if ( isset( $values['bmi_order_id'] ) ) {
        $cart_item['bmi_order_id'] = sanitize_text_field( (string) $values['bmi_order_id'] );
    }

    if ( isset( $values['bmi_order_item_id'] ) ) {
        $cart_item['bmi_order_item_id'] = sanitize_text_field( (string) $values['bmi_order_item_id'] );
    }

    if ( isset( $values['bmi_sell_response'] ) && is_array( $values['bmi_sell_response'] ) ) {
        $cart_item['bmi_sell_response'] = $values['bmi_sell_response'];
    }

    if ( isset( $cart_item['addon_unit_price'] ) && is_numeric( $cart_item['addon_unit_price'] )
        && ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'set_price' )
    ) {
        $cart_item['data']->set_price( (float) $cart_item['addon_unit_price'] );
    }

    return $cart_item;
}, 20, 3 );

add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
        return;
    }

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( empty( $cart_item['is_addon'] ) || ! isset( $cart_item['addon_unit_price'] ) || ! is_numeric( $cart_item['addon_unit_price'] ) ) {
            continue;
        }
        if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) || ! method_exists( $cart_item['data'], 'set_price' ) ) {
            continue;
        }

        $addon_price = (float) $cart_item['addon_unit_price'];
        if ( $addon_price >= 0 ) {
            $cart_item['data']->set_price( $addon_price );
        }
    }
}, 9999 );

add_filter( 'woocommerce_cart_item_price', function( $price_html, $cart_item, $cart_item_key ) {
    if ( empty( $cart_item['is_addon'] ) || ! isset( $cart_item['addon_unit_price'] ) || ! is_numeric( $cart_item['addon_unit_price'] ) ) {
        return $price_html;
    }

    return wc_price( (float) $cart_item['addon_unit_price'] );
}, 20, 3 );

add_filter( 'woocommerce_cart_item_subtotal', function( $subtotal_html, $cart_item, $cart_item_key ) {
    if ( empty( $cart_item['is_addon'] ) || ! isset( $cart_item['addon_unit_price'] ) || ! is_numeric( $cart_item['addon_unit_price'] ) ) {
        return $subtotal_html;
    }

    $qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
    $line_total = (float) $cart_item['addon_unit_price'] * $qty;
    return wc_price( $line_total );
}, 20, 3 );

function wk_rh_clear_booking_session_state() {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }

    if ( method_exists( WC()->session, 'get_session_data' ) ) {
        $session_data = WC()->session->get_session_data();
        if ( is_array( $session_data ) ) {
            foreach ( $session_data as $key => $value ) {
                if ( is_string( $key ) && strpos( $key, 'bmi_booked_' ) === 0 ) {
                    WC()->session->set( $key, null );
                }
            }
        }
    }

    WC()->session->set( 'rh_bmi_booking', null );
    WC()->session->set( 'booking_supplement', null );
    WC()->session->set( 'rh_checkout_prefill', null );
    WC()->session->set( 'rh_last_product_url', null );
}

add_action( 'template_redirect', function() {
    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }

    $redirect_url = WC()->session->get( 'rh_checkout_redirect_url' );
    if ( ! is_string( $redirect_url ) || $redirect_url === '' ) {
        return;
    }

    WC()->session->set( 'rh_checkout_redirect_url', null );
    wp_safe_redirect( $redirect_url );
    exit;
}, 1 );

function wk_rh_cancel_and_clear_expired_cart_holds() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        return;
    }

    $redirect_url = wk_rh_get_main_booking_product_url();
    $now = time();
    $expired_orders = [];
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( ! empty( $item['is_addon'] ) ) {
            continue;
        }

        $upstream_order_id = isset( $item['bmi_order_id'] ) ? trim( (string) $item['bmi_order_id'] ) : '';
        $expires_at = isset( $item['bmi_hold_expires_at'] ) ? (int) $item['bmi_hold_expires_at'] : 0;
        if ( $upstream_order_id === '' || $expires_at <= 0 || $expires_at > $now ) {
            continue;
        }

        $location = isset( $item['booking_location'] ) ? sanitize_text_field( (string) $item['booking_location'] ) : '';
        $expired_orders[ $upstream_order_id ] = $location;
    }

    if ( empty( $expired_orders ) ) {
        return;
    }

    foreach ( $expired_orders as $upstream_order_id => $location ) {
        if ( function_exists( 'wk_rh_cancel_upstream_order_by_id' ) ) {
            $cancelled = wk_rh_cancel_upstream_order_by_id( $upstream_order_id, $location, [
                'source' => 'cart_timeout_guard',
            ] );
            if ( ! $cancelled && function_exists( 'wk_rh_log_upstream_event' ) ) {
                wk_rh_log_upstream_event( 'error', 'Failed to cancel expired hold while clearing cart', [
                    'operation' => 'hold_timeout_cancel',
                    'orderId' => (string) $upstream_order_id,
                    'location' => (string) $location,
                ] );
            }
        }

        wk_rh_release_active_hold( $upstream_order_id );
    }

    wk_rh_log_user_event( 'cart.expired_hold_cleared', [
        'orderIds' => array_keys( $expired_orders ),
    ] );

    WC()->cart->empty_cart();
    wk_rh_clear_booking_session_state();

    wc_add_notice(
        __( 'Din reservation er udløbet. Kurven er nulstillet, så du kan vælge tidspunkt og booke igen.', 'racehall-wc-ui' ),
        'error'
    );

    if ( ! wp_doing_ajax() && function_exists( 'is_checkout' ) && is_checkout() && is_string( $redirect_url ) && $redirect_url !== '' && function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'rh_checkout_redirect_url', $redirect_url );
    }
}

add_action( 'woocommerce_check_cart_items', function() {
    wk_rh_process_expired_active_holds( 'checkout_guard' );
    wk_rh_cancel_and_clear_expired_cart_holds();
}, 5 );

add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_block_addon_without_parent', 20, 3 );
function wk_rh_block_addon_without_parent( $passed, $product_id, $quantity ) {
    if ( ! isset( $_POST['is_addon'] ) ) {
        return $passed;
    }

    $configured_addon_product_id = wk_rh_get_configured_addon_product_id();
    if ( $configured_addon_product_id <= 0 ) {
        wk_rh_log_user_event( 'addon.add_blocked', [ 'reason' => 'missing_configured_carrier_product', 'productId' => $product_id ], 'error' );
        wc_add_notice( __( 'Add-on produkt er ikke konfigureret endnu.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $carrier_validation_error = wk_rh_get_addon_carrier_validation_error( $configured_addon_product_id );
    if ( $carrier_validation_error !== '' ) {
        wk_rh_log_user_event( 'addon.add_blocked', [ 'reason' => 'invalid_configured_carrier_product', 'productId' => $product_id, 'carrierProductId' => $configured_addon_product_id, 'validationError' => $carrier_validation_error ], 'error' );
        wc_add_notice( $carrier_validation_error, 'error' );
        return false;
    }

    if ( (int) $product_id !== $configured_addon_product_id ) {
        wk_rh_log_user_event( 'addon.add_blocked', [ 'reason' => 'unexpected_carrier_product', 'productId' => $product_id, 'expectedProductId' => $configured_addon_product_id ], 'warning' );
        wc_add_notice( __( 'Forkert add-on produkt blev sendt til kurven.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    if ( WC()->cart->is_empty() || wk_rh_get_main_booking_cart_item_key() === '' ) {
        wk_rh_log_user_event( 'addon.add_blocked', [ 'reason' => 'missing_parent', 'productId' => $product_id ], 'warning' );
        wc_add_notice( __( 'Du skal vælge et race før du kan tilføje add-ons.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $addon_upstream_id = isset( $_POST['addon_upstream_id'] )
        ? trim( sanitize_text_field( wp_unslash( (string) $_POST['addon_upstream_id'] ) ) )
        : '';
    $addon_supplement_id = isset( $_POST['addon_supplement_id'] )
        ? trim( sanitize_text_field( wp_unslash( (string) $_POST['addon_supplement_id'] ) ) )
        : '';

    if ( $addon_upstream_id === '' ) {
        wk_rh_log_user_event( 'addon.add_blocked', [ 'reason' => 'missing_upstream_id', 'productId' => $product_id ], 'warning' );
        wc_add_notice( __( 'Add-on mangler upstream ID.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $main_context = wk_rh_get_main_booking_context();

    $resolved_addon_upstream_id = wk_rh_get_cart_item_addon_upstream_id( [
        'addon_upstream_id' => $addon_upstream_id,
        'addon_supplement_id' => $addon_supplement_id,
        'addon_display_name' => isset( $_POST['addon_display_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['addon_display_name'] ) ) : '',
    ], isset( $main_context['supplements'] ) && is_array( $main_context['supplements'] ) ? $main_context['supplements'] : [] );

    if ( $resolved_addon_upstream_id === '' ) {
        wk_rh_log_user_event( 'addon.add_blocked', [
            'reason' => 'supplement_not_available_for_booking',
            'productId' => $product_id,
            'requestedUpstreamId' => $addon_upstream_id,
            'requestedSupplementId' => $addon_supplement_id,
        ], 'warning' );
        wc_add_notice( __( 'Det valgte add-on er ikke tilgængeligt for den aktuelle booking. Gå videre til næste step i checkout først.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    $_POST['addon_upstream_id'] = $resolved_addon_upstream_id;

    return $passed;
}

add_filter( 'woocommerce_get_item_data', function( $data, $cart_item ) {
    if ( isset( $cart_item['is_addon'] ) ) {
        $data[] = [
            'name'  => __( 'Type', 'racehall-wc-ui' ),
            'value' => __( 'Add-on', 'racehall-wc-ui' ),
        ];
    }
    return $data;
}, 10, 2 );

// ----------------------
// Connected products (upsells / additional sales)
// ----------------------
function wk_rh_get_connected_products() {
    if ( ! function_exists( 'WC' ) || WC()->cart->is_empty() ) return [];

    $upsell_products = [];
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product = $cart_item['data'];
        $upsell_ids = $product->get_upsell_ids();
        if ( ! empty( $upsell_ids ) ) $upsell_products = array_merge( $upsell_products, $upsell_ids );
    }

    $cart_product_ids = wp_list_pluck( WC()->cart->get_cart(), 'product_id' );
    $upsell_products  = array_diff( array_unique( $upsell_products ), $cart_product_ids );

    return $upsell_products;
}



add_action('woocommerce_add_to_cart', 'wk_rh_send_booking_to_bmi_on_add_to_cart', 20, 6);

add_action( 'woocommerce_add_to_cart', function( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    wk_rh_log_user_event( 'cart.item_added', [
        'cartItemKey' => (string) $cart_item_key,
        'productId' => (int) $product_id,
        'quantity' => (int) $quantity,
        'isAddon' => ! empty( $cart_item_data['is_addon'] ),
        'bookingDate' => isset( $cart_item_data['booking_date'] ) ? (string) $cart_item_data['booking_date'] : '',
        'bookingTime' => isset( $cart_item_data['booking_time'] ) ? (string) $cart_item_data['booking_time'] : '',
        'bookingLocation' => isset( $cart_item_data['booking_location'] ) ? (string) $cart_item_data['booking_location'] : '',
    ] );
}, 25, 6 );

function wk_rh_get_main_booking_cart_item_key() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        return '';
    }

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( ! empty( $cart_item['is_addon'] ) ) {
            continue;
        }

        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        if ( $product_id <= 0 ) {
            continue;
        }

        $bm_id = function_exists( 'get_field' )
            ? get_field( 'bmileisure_id', $product_id )
            : get_post_meta( $product_id, 'bmileisure_id', true );

        if ( ! empty( $bm_id ) ) {
            return (string) $cart_item_key;
        }
    }

    return '';
}

function wk_rh_get_checkout_contact_person( array $posted_data ) {
    $first_name = isset( $posted_data['billing_first_name'] ) ? sanitize_text_field( (string) $posted_data['billing_first_name'] ) : '';
    $last_name  = isset( $posted_data['billing_last_name'] ) ? sanitize_text_field( (string) $posted_data['billing_last_name'] ) : '';
    $email      = isset( $posted_data['billing_email'] ) ? sanitize_email( (string) $posted_data['billing_email'] ) : '';
    $phone      = isset( $posted_data['billing_phone'] ) ? sanitize_text_field( (string) $posted_data['billing_phone'] ) : '';

    return [
        'firstName' => $first_name,
        'lastName'  => $last_name,
        'email'     => $email,
        'phone'     => $phone,
    ];
}

function wk_rh_get_checkout_step_required_keys() {
    return [
        'billing_first_name',
        'billing_last_name',
        'billing_email',
        'billing_phone',
        'billing_address_1',
        'billing_postcode',
        'billing_city',
        'billing_country',
    ];
}

function wk_rh_get_checkout_prefill_keys() {
    return [
        'billing_first_name',
        'billing_last_name',
        'billing_email',
        'billing_phone',
        'billing_address_1',
        'billing_postcode',
        'billing_city',
        'billing_country',
        'order_comments',
    ];
}

function wk_rh_store_checkout_prefill( array $posted_data ) {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }

    $prefill = [];
    foreach ( wk_rh_get_checkout_prefill_keys() as $key ) {
        if ( ! array_key_exists( $key, $posted_data ) ) {
            continue;
        }

        $value = (string) $posted_data[ $key ];
        $prefill[ $key ] = $key === 'billing_email'
            ? sanitize_email( $value )
            : sanitize_text_field( $value );
    }

    WC()->session->set( 'rh_checkout_prefill', $prefill );

    if ( ! WC()->customer ) {
        return;
    }

    $customer = WC()->customer;
    $setter_map = [
        'billing_first_name' => 'set_billing_first_name',
        'billing_last_name' => 'set_billing_last_name',
        'billing_email' => 'set_billing_email',
        'billing_phone' => 'set_billing_phone',
        'billing_address_1' => 'set_billing_address_1',
        'billing_postcode' => 'set_billing_postcode',
        'billing_city' => 'set_billing_city',
        'billing_country' => 'set_billing_country',
    ];

    foreach ( $setter_map as $key => $setter ) {
        if ( ! isset( $prefill[ $key ] ) || ! method_exists( $customer, $setter ) ) {
            continue;
        }

        $customer->$setter( $prefill[ $key ] );
    }

    if ( method_exists( $customer, 'save' ) ) {
        $customer->save();
    }
}

function wk_rh_get_checkout_prefill_value( $checkout, $key ) {
    $key = sanitize_key( (string) $key );

    if ( function_exists( 'WC' ) && WC()->session ) {
        $prefill = WC()->session->get( 'rh_checkout_prefill' );
        if ( is_array( $prefill ) && array_key_exists( $key, $prefill ) && $prefill[ $key ] !== '' ) {
            return (string) $prefill[ $key ];
        }
    }

    if ( $checkout && is_object( $checkout ) && method_exists( $checkout, 'get_value' ) ) {
        return (string) $checkout->get_value( $key );
    }

    return '';
}

add_filter( 'woocommerce_checkout_get_value', function( $value, $input ) {
    if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return $value;
    }

    $input = sanitize_key( (string) $input );
    if ( ! in_array( $input, wk_rh_get_checkout_prefill_keys(), true ) ) {
        return $value;
    }

    $prefill_value = wk_rh_get_checkout_prefill_value( null, $input );
    return $prefill_value !== '' ? $prefill_value : $value;
}, 20, 2 );

function wk_rh_get_main_booking_context() {
    $context = [
        'cartItemKey' => '',
        'item' => [],
        'orderId' => '',
        'orderItemId' => '',
        'supplements' => [],
        'location' => '',
        'expiresAt' => 0,
        'contactPerson' => [],
    ];

    $main_cart_item_key = wk_rh_get_main_booking_cart_item_key();
    if ( $main_cart_item_key === '' || ! function_exists( 'WC' ) || ! WC()->cart ) {
        return $context;
    }

    $cart_contents = WC()->cart->get_cart();
    if ( empty( $cart_contents[ $main_cart_item_key ] ) || ! is_array( $cart_contents[ $main_cart_item_key ] ) ) {
        return $context;
    }

    $item = $cart_contents[ $main_cart_item_key ];
    $supplements = isset( $item['bmi_supplements'] ) && is_array( $item['bmi_supplements'] ) ? $item['bmi_supplements'] : [];
    if ( empty( $supplements ) && function_exists( 'WC' ) && WC()->session ) {
        $session_supplements = WC()->session->get( 'booking_supplement' );
        if ( is_array( $session_supplements ) && ! empty( $session_supplements['supplements'] ) && is_array( $session_supplements['supplements'] ) ) {
            $supplements = array_values( $session_supplements['supplements'] );
        }
    }

    $context['cartItemKey'] = $main_cart_item_key;
    $context['item'] = $item;
    $context['orderId'] = isset( $item['bmi_order_id'] ) ? trim( (string) $item['bmi_order_id'] ) : '';
    $context['orderItemId'] = isset( $item['bmi_order_item_id'] ) ? trim( (string) $item['bmi_order_item_id'] ) : '';
    $context['supplements'] = $supplements;
    $context['location'] = isset( $item['booking_location'] ) ? sanitize_text_field( (string) $item['booking_location'] ) : '';
    $context['expiresAt'] = isset( $item['bmi_hold_expires_at'] ) ? (int) $item['bmi_hold_expires_at'] : 0;
    $context['contactPerson'] = isset( $item['bmi_contact_person'] ) && is_array( $item['bmi_contact_person'] )
        ? wk_rh_prepare_booking_contact_person( $item['bmi_contact_person'] )
        : [];

    return $context;
}

function wk_rh_get_stored_checkout_prefill() {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return [];
    }

    $prefill = WC()->session->get( 'rh_checkout_prefill' );
    return is_array( $prefill ) ? $prefill : [];
}

function wk_rh_checkout_prefill_matches_posted( array $posted_data ) {
    $stored = wk_rh_get_stored_checkout_prefill();
    if ( empty( $stored ) ) {
        return false;
    }

    foreach ( wk_rh_get_checkout_step_required_keys() as $key ) {
        $current = isset( $posted_data[ $key ] ) ? (string) $posted_data[ $key ] : '';
        $stored_value = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

        if ( $key === 'billing_email' ) {
            $current = sanitize_email( $current );
            $stored_value = sanitize_email( $stored_value );
        } else {
            $current = sanitize_text_field( $current );
            $stored_value = sanitize_text_field( $stored_value );
        }

        if ( $current !== $stored_value ) {
            return false;
        }
    }

    return true;
}

function wk_rh_get_main_item_booking_contact_person( array $main_item ) {
    if ( isset( $main_item['bmi_contact_person'] ) && is_array( $main_item['bmi_contact_person'] ) ) {
        return wk_rh_prepare_booking_contact_person( $main_item['bmi_contact_person'] );
    }

    if ( function_exists( 'WC' ) && WC()->session ) {
        $session_booking = WC()->session->get( 'rh_bmi_booking' );
        if ( is_array( $session_booking ) && ! empty( $session_booking['contactPerson'] ) && is_array( $session_booking['contactPerson'] ) ) {
            return wk_rh_prepare_booking_contact_person( $session_booking['contactPerson'] );
        }
    }

    return [];
}

function wk_rh_contact_persons_match( array $left, array $right ) {
    $left = wk_rh_prepare_booking_contact_person( $left );
    $right = wk_rh_prepare_booking_contact_person( $right );

    foreach ( [ 'firstName', 'lastName', 'email', 'phone' ] as $key ) {
        if ( (string) ( $left[ $key ] ?? '' ) !== (string) ( $right[ $key ] ?? '' ) ) {
            return false;
        }
    }

    return true;
}

function wk_rh_is_checkout_booking_step_ready() {
    $context = wk_rh_get_main_booking_context();
    return ! empty( $context['orderId'] ) && ! empty( $context['orderItemId'] );
}

function wk_rh_find_booking_supplement_by_upstream_id( array $supplements, $upstream_id ) {
    $upstream_id = trim( (string) $upstream_id );
    if ( $upstream_id === '' ) {
        return null;
    }

    foreach ( $supplements as $supplement ) {
        if ( ! is_array( $supplement ) ) {
            continue;
        }

        $supplement = wk_rh_normalize_booking_supplement_row( $supplement );
        $candidate_id = isset( $supplement['id'] ) ? trim( (string) $supplement['id'] ) : '';
        if ( $candidate_id === $upstream_id ) {
            return $supplement;
        }
    }

    return null;
}

function wk_rh_get_supplement_quantity_bounds( array $supplement ) {
    $min_qty = null;
    foreach ( [ 'minQuantity', 'minQty', 'minimumQuantity', 'minimumQty', 'minAmount', 'minimumAmount', 'minamount' ] as $key ) {
        if ( isset( $supplement[ $key ] ) && is_numeric( $supplement[ $key ] ) ) {
            $min_qty = (int) round( (float) $supplement[ $key ] );
            break;
        }
    }

    $max_qty = null;
    foreach ( [ 'maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty', 'maxAmount', 'maximumAmount', 'maxamount' ] as $key ) {
        if ( isset( $supplement[ $key ] ) && is_numeric( $supplement[ $key ] ) ) {
            $max_qty = (int) round( (float) $supplement[ $key ] );
            break;
        }
    }

    $min_qty = max( 1, (int) ( $min_qty !== null ? $min_qty : 1 ) );
    $max_qty = $max_qty !== null ? max( $min_qty, (int) $max_qty ) : null;

    return [
        'min' => $min_qty,
        'max' => $max_qty,
    ];
}

function wk_rh_get_supplement_price_amount( array $supplement ) {
    $prices = isset( $supplement['prices'] ) && is_array( $supplement['prices'] ) ? $supplement['prices'] : [];
    if ( empty( $prices[0]['amount'] ) || ! is_numeric( $prices[0]['amount'] ) ) {
        return 0.0;
    }

    return (float) $prices[0]['amount'];
}

function wk_rh_get_addon_cart_item_key_by_upstream_id( $upstream_id ) {
    $upstream_id = trim( (string) $upstream_id );
    if ( $upstream_id === '' || ! function_exists( 'WC' ) || ! WC()->cart ) {
        return '';
    }

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( empty( $cart_item['is_addon'] ) ) {
            continue;
        }

        $cart_upstream_id = wk_rh_get_cart_item_addon_upstream_id( $cart_item );
        if ( $cart_upstream_id === $upstream_id ) {
            return (string) $cart_item_key;
        }
    }

    return '';
}

function wk_rh_get_addon_quantity_by_upstream_id( $upstream_id ) {
    $cart_item_key = wk_rh_get_addon_cart_item_key_by_upstream_id( $upstream_id );
    if ( $cart_item_key === '' || ! function_exists( 'WC' ) || ! WC()->cart ) {
        return 0;
    }

    $cart_item = WC()->cart->get_cart_item( $cart_item_key );
    return is_array( $cart_item ) && isset( $cart_item['quantity'] ) ? max( 0, (int) $cart_item['quantity'] ) : 0;
}

function wk_rh_remove_all_cart_addon_items( $set_session = true ) {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return;
    }

    foreach ( array_keys( WC()->cart->get_cart() ) as $cart_item_key ) {
        $cart_item = WC()->cart->get_cart_item( $cart_item_key );
        if ( empty( $cart_item['is_addon'] ) ) {
            continue;
        }

        unset( WC()->cart->cart_contents[ $cart_item_key ] );
    }

    if ( $set_session ) {
        WC()->cart->calculate_totals();
        WC()->cart->set_session();
    }
}

function wk_rh_get_checkout_booking_redirect_url() {
    $url = wk_rh_get_main_booking_product_url();
    return is_string( $url ) && $url !== '' ? $url : wc_get_cart_url();
}

function wk_rh_add_checkout_booking_error( WP_Error $errors, $message, $redirect_to_product = false ) {
    $full_message = (string) $message;
    if ( $redirect_to_product ) {
        $redirect_url = wk_rh_get_checkout_booking_redirect_url();
        $full_message .= ' <a class="rh-booking-slot-unavailable-link" data-rh-product-redirect="1" href="' . esc_url( $redirect_url ) . '">' . esc_html__( 'Vælg et nyt tidspunkt', 'racehall-wc-ui' ) . '</a>';
    }

    $errors->add( 'rh_booking_slot_unavailable', wp_kses_post( $full_message ) );
}

function wk_rh_apply_page_product_limits_to_rules( array $rules, $page_product_limits ) {
    if ( ! is_array( $page_product_limits ) ) {
        return $rules;
    }

    $min_amount = isset( $page_product_limits['minAmount'] ) && is_numeric( $page_product_limits['minAmount'] )
        ? (int) round( (float) $page_product_limits['minAmount'] )
        : null;
    $max_amount = isset( $page_product_limits['maxAmount'] ) && is_numeric( $page_product_limits['maxAmount'] )
        ? (int) round( (float) $page_product_limits['maxAmount'] )
        : null;

    if ( $min_amount !== null && $min_amount > 0 ) {
        $rules['total']['min'] = max( (int) $rules['total']['min'], $min_amount );
    }

    if ( $max_amount !== null && $max_amount > 0 ) {
        $rules['total']['max'] = isset( $rules['total']['max'] ) && $rules['total']['max'] !== null
            ? min( (int) $rules['total']['max'], $max_amount )
            : $max_amount;
    }

    return $rules;
}

function wk_rh_validate_checkout_booking_quantity( array $cart_item, $quantity, WP_Error $errors ) {
    if ( ! function_exists( 'wk_rh_extract_quantity_rules_from_proposal' ) || ! function_exists( 'wk_rh_rule_value_matches_step' ) ) {
        return true;
    }

    $proposal = isset( $cart_item['bmi_proposal'] ) && is_array( $cart_item['bmi_proposal'] ) ? $cart_item['bmi_proposal'] : null;
    if ( ! is_array( $proposal ) ) {
        $errors->add( 'rh_booking_missing_proposal', __( 'Bookingforslaget mangler på checkout. Vælg tidspunkt igen.', 'racehall-wc-ui' ) );
        return false;
    }

    $rules = wk_rh_extract_quantity_rules_from_proposal( $proposal );
    $rules = wk_rh_apply_page_product_limits_to_rules( $rules, $cart_item['bmi_page_product_limits'] ?? null );

    $adults = isset( $cart_item['booking_adults'] ) ? max( 0, (int) $cart_item['booking_adults'] ) : 0;
    $kids   = isset( $cart_item['booking_children'] ) ? max( 0, (int) $cart_item['booking_children'] ) : 0;
    $total  = $adults + $kids;
    $qty    = max( 1, (int) $quantity );

    if ( $total > 0 && $total !== $qty ) {
        $errors->add( 'rh_booking_quantity_mismatch', __( 'Deltagerantal matcher ikke bookingens antal. Gå tilbage og vælg tidspunkt igen.', 'racehall-wc-ui' ) );
        return false;
    }

    if ( $total === 0 ) {
        $total = $qty;
        $adults = $qty;
    }

    $group_checks = [
        [ 'label' => __( 'Adults', 'onsite-booking-system' ), 'value' => $adults, 'rules' => $rules['adults'] ],
        [ 'label' => __( 'Children', 'onsite-booking-system' ), 'value' => $kids, 'rules' => $rules['kids'] ],
    ];

    foreach ( $group_checks as $check ) {
        $min = (int) ( $check['rules']['min'] ?? 0 );
        $max = isset( $check['rules']['max'] ) ? $check['rules']['max'] : null;
        $step = (int) ( $check['rules']['step'] ?? 1 );

        if ( $check['value'] < $min ) {
            $errors->add( 'rh_booking_group_min', sprintf( __( '%s must be at least %d.', 'onsite-booking-system' ), $check['label'], $min ) );
            return false;
        }

        if ( $max !== null && $check['value'] > (int) $max ) {
            $errors->add( 'rh_booking_group_max', sprintf( __( '%s cannot exceed %d.', 'onsite-booking-system' ), $check['label'], (int) $max ) );
            return false;
        }

        if ( ! wk_rh_rule_value_matches_step( $check['value'], $min, $step ) ) {
            $errors->add( 'rh_booking_group_step', sprintf( __( '%s quantity must follow step %d starting from %d.', 'onsite-booking-system' ), $check['label'], max( 1, $step ), $min ) );
            return false;
        }
    }

    $total_min = (int) ( $rules['total']['min'] ?? 1 );
    $total_max = isset( $rules['total']['max'] ) ? $rules['total']['max'] : null;
    $total_step = (int) ( $rules['total']['step'] ?? 1 );

    if ( $total < $total_min ) {
        $errors->add( 'rh_booking_total_min', sprintf( __( 'Total participants must be at least %d.', 'onsite-booking-system' ), $total_min ) );
        return false;
    }

    if ( $total_max !== null && $total > (int) $total_max ) {
        $errors->add( 'rh_booking_total_max', sprintf( __( 'Total participants cannot exceed %d.', 'onsite-booking-system' ), (int) $total_max ) );
        return false;
    }

    if ( ! wk_rh_rule_value_matches_step( $total, $total_min, $total_step ) ) {
        $errors->add( 'rh_booking_total_step', sprintf( __( 'Total participants must follow step %d starting from %d.', 'onsite-booking-system' ), max( 1, $total_step ), $total_min ) );
        return false;
    }

    return true;
}

function wk_rh_get_case_insensitive_array_value( array $source, array $keys, $default = null ) {
    foreach ( $keys as $key ) {
        if ( array_key_exists( $key, $source ) ) {
            return $source[ $key ];
        }
    }

    $lower_map = [];
    foreach ( $source as $key => $value ) {
        if ( is_string( $key ) ) {
            $lower_map[ strtolower( $key ) ] = $value;
        }
    }

    foreach ( $keys as $key ) {
        $lookup = strtolower( (string) $key );
        if ( array_key_exists( $lookup, $lower_map ) ) {
            return $lower_map[ $lookup ];
        }
    }

    return $default;
}

function wk_rh_normalize_upstream_prices( $prices ) {
    if ( ! is_array( $prices ) ) {
        return [];
    }

    $normalized_prices = [];
    foreach ( $prices as $price ) {
        if ( ! is_array( $price ) ) {
            continue;
        }

        $normalized_price = $price;
        $short_name = wk_rh_get_case_insensitive_array_value( $price, [ 'shortName', 'shortname' ], null );
        if ( $short_name !== null ) {
            $normalized_price['shortName'] = $short_name;
            $normalized_price['shortname'] = $short_name;
        }

        $amount = wk_rh_get_case_insensitive_array_value( $price, [ 'amount' ], null );
        if ( $amount !== null ) {
            $normalized_price['amount'] = $amount;
        }

        $normalized_prices[] = $normalized_price;
    }

    return $normalized_prices;
}

function wk_rh_normalize_booking_supplement_row( array $supplement ) {
    $product = isset( $supplement['product'] ) && is_array( $supplement['product'] ) ? $supplement['product'] : [];
    $source = ! empty( $product ) ? $product : $supplement;
    $normalized = $supplement;

    $field_map = [
        'id' => [ 'id' ],
        'name' => [ 'name' ],
        'info' => [ 'info' ],
        'hasPicture' => [ 'hasPicture', 'haspicture' ],
        'minAmount' => [ 'minAmount', 'minamount' ],
        'maxAmount' => [ 'maxAmount', 'maxamount' ],
        'resourceId' => [ 'resourceId', 'resourceid' ],
        'resourceKind' => [ 'resourceKind', 'resourcekind' ],
        'kind' => [ 'kind' ],
        'saleMode' => [ 'saleMode', 'salemode' ],
        'bookingMode' => [ 'bookingMode', 'bookingmode' ],
        'productGroup' => [ 'productGroup', 'productgroup' ],
        'dynamicGroups' => [ 'dynamicGroups', 'dynamicgroups' ],
        'isEntry' => [ 'isEntry', 'isentry' ],
        'isCombo' => [ 'isCombo', 'iscombo' ],
        'minAge' => [ 'minAge', 'minage' ],
        'maxAge' => [ 'maxAge', 'maxage' ],
        'isMembersOnly' => [ 'isMembersOnly', 'ismembersonly' ],
        'pageId' => [ 'pageId', 'pageid' ],
    ];

    foreach ( $field_map as $target_key => $candidate_keys ) {
        $value = wk_rh_get_case_insensitive_array_value( $normalized, $candidate_keys, null );
        if ( $value === null ) {
            $value = wk_rh_get_case_insensitive_array_value( $source, $candidate_keys, null );
        }

        if ( $value !== null ) {
            $normalized[ $target_key ] = $value;
        }
    }

    $prices = wk_rh_get_case_insensitive_array_value( $normalized, [ 'prices' ], null );
    if ( $prices === null ) {
        $prices = wk_rh_get_case_insensitive_array_value( $source, [ 'prices', 'price' ], null );
    }
    if ( $prices !== null ) {
        $normalized['prices'] = wk_rh_normalize_upstream_prices( $prices );
    }

    return $normalized;
}

function wk_rh_get_booking_supplement_sell_product_id( array $supplement ) {
    $supplement = wk_rh_normalize_booking_supplement_row( $supplement );
    return isset( $supplement['id'] ) ? trim( (string) $supplement['id'] ) : '';
}

function wk_rh_get_cart_item_addon_upstream_id( array $cart_item, array $supplements = [] ) {
    $stored_upstream_id = isset( $cart_item['addon_upstream_id'] ) ? trim( (string) $cart_item['addon_upstream_id'] ) : '';
    if ( $stored_upstream_id === '' && isset( $cart_item['addon_upstream_product_id'] ) ) {
        $stored_upstream_id = trim( (string) $cart_item['addon_upstream_product_id'] );
    }

    if ( empty( $supplements ) ) {
        return $stored_upstream_id;
    }

    $stored_supplement_id = isset( $cart_item['addon_supplement_id'] ) ? trim( (string) $cart_item['addon_supplement_id'] ) : '';
    $available_supplement_ids = [];
    $available_upstream_ids = [];

    foreach ( $supplements as $supplement ) {
        if ( ! is_array( $supplement ) ) {
            continue;
        }

        $supplement = wk_rh_normalize_booking_supplement_row( $supplement );

        $supplement_id = isset( $supplement['id'] ) ? trim( (string) $supplement['id'] ) : '';
        if ( $supplement_id !== '' ) {
            $available_supplement_ids[] = $supplement_id;
            $available_upstream_ids[] = $supplement_id;
        }

        if ( $stored_upstream_id !== '' && $supplement_id !== '' && $supplement_id === $stored_upstream_id ) {
            return $supplement_id;
        }

        if ( $stored_supplement_id === '' || $supplement_id === '' || $supplement_id !== $stored_supplement_id ) {
            continue;
        }

        $resolved_upstream_id = wk_rh_get_booking_supplement_sell_product_id( $supplement );
        if ( $resolved_upstream_id !== '' ) {
            return $resolved_upstream_id;
        }
    }

    if ( function_exists( 'wk_rh_log_user_event' ) ) {
        wk_rh_log_user_event( 'checkout.addon_supplement_not_found', [
            'addonName' => isset( $cart_item['addon_display_name'] ) ? sanitize_text_field( (string) $cart_item['addon_display_name'] ) : '',
            'storedSupplementId' => $stored_supplement_id,
            'storedUpstreamId' => $stored_upstream_id,
            'availableSupplementIds' => $available_supplement_ids,
            'availableUpstreamIds' => $available_upstream_ids,
        ], 'error' );
    }

    return '';
}

function wk_rh_resolve_checkout_addon_product_id( array $cart_item, array $supplements ) {
    return wk_rh_get_cart_item_addon_upstream_id( $cart_item, $supplements );
}

function wk_rh_prepare_booking_contact_person( array $contact_person ) {
    $prepared = [];

    foreach ( [ 'firstName', 'lastName', 'email', 'phone' ] as $key ) {
        if ( empty( $contact_person[ $key ] ) ) {
            continue;
        }

        $value = (string) $contact_person[ $key ];
        $value = $key === 'email' ? sanitize_email( $value ) : sanitize_text_field( $value );
        if ( $value !== '' ) {
            $prepared[ $key ] = $value;
        }
    }

    return $prepared;
}

function wk_rh_store_main_cart_booking_hold( $main_cart_item_key, array $main_item, array $result, $expires_at, $proposal, $page_id, $resource_id, $booking_location, $product_id, $bm_id, $main_quantity, $source, array $contact_person = [] ) {
    $normalized_supplements = wk_rh_extract_booking_supplements( $result );
    $main_order_id = isset( $result['orderId'] ) ? (string) $result['orderId'] : '';
    $main_order_item_id = isset( $result['orderItemId'] ) ? (string) $result['orderItemId'] : '';
    $prepared_contact_person = wk_rh_prepare_booking_contact_person( $contact_person );

    WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_order_id'] = $main_order_id;
    WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_order_item_id'] = $main_order_item_id;
    WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_hold_expires_at'] = $expires_at;
    WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_supplements'] = $normalized_supplements;
    WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_booking_response'] = $result;
    WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_contact_person'] = $prepared_contact_person;

    if ( function_exists( 'WC' ) && WC()->session ) {
        $session_booking = WC()->session->get( 'rh_bmi_booking' );
        if ( ! is_array( $session_booking ) ) {
            $session_booking = [];
        }
        $session_booking['proposal'] = $proposal;
        $session_booking['pageId'] = $page_id;
        $session_booking['resourceId'] = $resource_id;
        $session_booking['productId'] = (string) $bm_id;
        $session_booking['quantity'] = $main_quantity;
        $session_booking['pageProductLimits'] = $main_item['bmi_page_product_limits'] ?? null;
        $session_booking['pageProducts'] = $main_item['bmi_page_products'] ?? [];
        $session_booking['bookingLocation'] = $booking_location;
        $session_booking['orderId'] = $main_order_id;
        $session_booking['orderItemId'] = $main_order_item_id;
        $session_booking['expiresAt'] = $expires_at;
        $session_booking['contactPerson'] = $prepared_contact_person;
        WC()->session->set( 'rh_bmi_booking', $session_booking );
        WC()->session->set( 'booking_supplement', [
            'supplements' => $normalized_supplements,
            'orderId'     => $main_order_id,
            'expiresAt'   => $expires_at,
        ] );
    }

    if ( function_exists( 'wk_rh_register_active_hold' ) ) {
        wk_rh_register_active_hold( $main_order_id, $booking_location, $expires_at, [
            'source'      => (string) $source,
            'wcProductId' => (string) $product_id,
        ] );
    }

    WC()->cart->set_session();

    return [
        'orderId' => $main_order_id,
        'orderItemId' => $main_order_item_id,
        'supplements' => $normalized_supplements,
        'expiresAt' => $expires_at,
    ];
}

function wk_rh_ensure_main_cart_booking_hold( $main_cart_item_key, array $contact_person = [], $source = 'cart_prepare', $force_refresh = false ) {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return [
            'success' => false,
            'userMessage' => __( 'Booking session mangler. Opdater siden og prøv igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => false,
        ];
    }

    $cart_contents = WC()->cart->get_cart();
    if ( empty( $cart_contents[ $main_cart_item_key ] ) || ! is_array( $cart_contents[ $main_cart_item_key ] ) ) {
        return [
            'success' => false,
            'userMessage' => __( 'Bookinglinjen blev ikke fundet i kurven. Vælg tidspunkt igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => true,
        ];
    }

    $main_item = $cart_contents[ $main_cart_item_key ];
    $product_id = isset( $main_item['product_id'] ) ? (int) $main_item['product_id'] : 0;
    $bm_id = function_exists( 'get_field' )
        ? get_field( 'bmileisure_id', $product_id )
        : get_post_meta( $product_id, 'bmileisure_id', true );
    $proposal = isset( $main_item['bmi_proposal'] ) ? $main_item['bmi_proposal'] : null;
    $page_id = isset( $main_item['bmi_page_id'] ) ? trim( (string) $main_item['bmi_page_id'] ) : '';
    $resource_id = isset( $main_item['bmi_resource_id'] ) ? trim( (string) $main_item['bmi_resource_id'] ) : '';
    $booking_location = isset( $main_item['booking_location'] ) ? sanitize_text_field( (string) $main_item['booking_location'] ) : '';
    $main_quantity = isset( $main_item['quantity'] ) ? max( 1, (int) $main_item['quantity'] ) : 1;
    $main_order_id = isset( $main_item['bmi_order_id'] ) ? trim( (string) $main_item['bmi_order_id'] ) : '';
    $main_order_item_id = isset( $main_item['bmi_order_item_id'] ) ? trim( (string) $main_item['bmi_order_item_id'] ) : '';
    $prepared_contact_person = wk_rh_prepare_booking_contact_person( $contact_person );
    $stored_contact_person = wk_rh_get_main_item_booking_contact_person( $main_item );
    $needs_contact_refresh = $main_order_id !== ''
        && $main_order_item_id !== ''
        && ! empty( $prepared_contact_person )
        && ! wk_rh_contact_persons_match( $prepared_contact_person, $stored_contact_person );

    if ( $main_order_id !== '' && $main_order_item_id !== '' && ! $force_refresh && ! $needs_contact_refresh ) {
        if ( empty( $main_item['bmi_supplements'] ) && function_exists( 'WC' ) && WC()->session ) {
            $session_supplements = WC()->session->get( 'booking_supplement' );
            if ( is_array( $session_supplements ) && ! empty( $session_supplements['supplements'] ) && is_array( $session_supplements['supplements'] ) ) {
                WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_supplements'] = array_values( $session_supplements['supplements'] );
                WC()->cart->set_session();
                $main_item['bmi_supplements'] = WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_supplements'];
            }
        }

        return [
            'success' => true,
            'orderId' => $main_order_id,
            'orderItemId' => $main_order_item_id,
            'supplements' => isset( $main_item['bmi_supplements'] ) && is_array( $main_item['bmi_supplements'] ) ? $main_item['bmi_supplements'] : [],
            'expiresAt' => isset( $main_item['bmi_hold_expires_at'] ) ? (int) $main_item['bmi_hold_expires_at'] : 0,
        ];
    }

    if ( $main_order_id !== '' && $main_order_item_id !== '' && ( $force_refresh || $needs_contact_refresh ) ) {
        if ( $booking_location !== '' && function_exists( 'wk_rh_cancel_upstream_order_by_id' ) ) {
            wk_rh_cancel_upstream_order_by_id( $main_order_id, $booking_location, [
                'source' => sanitize_key( (string) $source ) . '_refresh',
            ] );
        }

        if ( function_exists( 'wk_rh_release_active_hold' ) ) {
            wk_rh_release_active_hold( $main_order_id );
        }

        if ( function_exists( 'wk_rh_remove_all_cart_addon_items' ) ) {
            wk_rh_remove_all_cart_addon_items( false );
        }

        WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_order_id'] = '';
        WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_order_item_id'] = '';
        WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_hold_expires_at'] = 0;
        WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_supplements'] = [];
        WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_booking_response'] = [];
        WC()->cart->cart_contents[ $main_cart_item_key ]['bmi_contact_person'] = [];

        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_booking = WC()->session->get( 'rh_bmi_booking' );
            if ( ! is_array( $session_booking ) ) {
                $session_booking = [];
            }

            $session_booking['orderId'] = '';
            $session_booking['orderItemId'] = '';
            $session_booking['expiresAt'] = 0;
            $session_booking['contactPerson'] = [];
            WC()->session->set( 'rh_bmi_booking', $session_booking );
            WC()->session->set( 'booking_supplement', null );
        }

        WC()->cart->calculate_totals();
        WC()->cart->set_session();

        $main_item = WC()->cart->cart_contents[ $main_cart_item_key ];
    }

    if ( empty( $bm_id ) || ! is_array( $proposal ) || empty( $proposal ) || $page_id === '' || $resource_id === '' ) {
        return [
            'success' => false,
            'userMessage' => __( 'Bookingforslaget mangler på kurven. Vælg tidspunkt igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => true,
        ];
    }

    $body = [
        'productId'  => (string) $bm_id,
        'pageId'     => (string) $page_id,
        'quantity'   => $main_quantity,
        'resourceId' => (string) $resource_id,
        'proposal'   => $proposal,
    ];
    if ( ! empty( $prepared_contact_person ) ) {
        $body['contactPerson'] = $prepared_contact_person;
    }

    wk_rh_log_user_event( 'booking.hold_started', [
        'source' => (string) $source,
        'productId' => $product_id,
        'bmProductId' => (string) $bm_id,
        'pageId' => (string) $page_id,
        'resourceId' => (string) $resource_id,
        'quantity' => $main_quantity,
        'bookingLocation' => $booking_location,
    ] );

    $token = wk_rh_get_token( $booking_location );
    $creds = wk_rh_get_api_credentials( $booking_location );
    if ( ! $token || empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return [
            'success' => false,
            'userMessage' => __( 'Tidslot kunne ikke reserveres lige nu. Prøv igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => false,
        ];
    }

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/book';
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
            'timeout' => 60,
        ],
        1,
        [
            'operation' => 'booking_book_' . sanitize_key( (string) $source ),
            'productId' => (string) $bm_id,
            'location'  => $booking_location,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [
            'success' => false,
            'userMessage' => __( 'Tidslot kunne ikke reserveres lige nu. Prøv igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => false,
        ];
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $result = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! ( $code >= 200 && $code < 300 ) ) {
        wk_rh_log_user_event( 'booking.hold_failed', [
            'source' => (string) $source,
            'reason' => 'upstream_booking_rejected',
            'productId' => $product_id,
            'bmProductId' => (string) $bm_id,
            'bookingLocation' => $booking_location,
            'httpCode' => $code,
        ], 'warning' );
        return [
            'success' => false,
            'userMessage' => __( 'Det valgte tidspunkt er ikke længere tilgængeligt. Vælg venligst et nyt tidspunkt.', 'racehall-wc-ui' ),
            'redirectToProduct' => true,
        ];
    }

    if ( empty( $result['orderId'] ) || empty( $result['orderItemId'] ) ) {
        wk_rh_log_user_event( 'booking.hold_failed', [
            'source' => (string) $source,
            'reason' => 'missing_upstream_identifiers',
            'productId' => $product_id,
            'bmProductId' => (string) $bm_id,
            'bookingLocation' => $booking_location,
        ], 'error' );
        return [
            'success' => false,
            'userMessage' => __( 'Reservationen mangler nødvendige upstream id’er. Prøv igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => false,
        ];
    }

    $hold_timeout_minutes = function_exists( 'wk_rh_get_booking_hold_timeout_minutes' )
        ? wk_rh_get_booking_hold_timeout_minutes()
        : 15;
    $expires_at = time() + ( max( 5, (int) $hold_timeout_minutes ) * 60 );

    $stored = wk_rh_store_main_cart_booking_hold(
        $main_cart_item_key,
        $main_item,
        $result,
        $expires_at,
        $proposal,
        $page_id,
        $resource_id,
        $booking_location,
        $product_id,
        $bm_id,
        $main_quantity,
        $source,
        $prepared_contact_person
    );

    wk_rh_log_user_event( 'booking.hold_confirmed', [
        'source' => (string) $source,
        'productId' => $product_id,
        'bmProductId' => (string) $bm_id,
        'bookingLocation' => $booking_location,
        'orderId' => $stored['orderId'],
        'orderItemId' => $stored['orderItemId'],
        'expiresAt' => $stored['expiresAt'],
        'supplementsCount' => is_array( $stored['supplements'] ) ? count( $stored['supplements'] ) : 0,
    ] );

    return array_merge( [ 'success' => true ], $stored );
}

function wk_rh_sync_checkout_booking_before_order( $posted_data, $errors ) {
    if ( ! $errors instanceof WP_Error || $errors->has_errors() ) {
        return;
    }

    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        return;
    }

    $main_cart_item_key = wk_rh_get_main_booking_cart_item_key();
    if ( $main_cart_item_key === '' ) {
        return;
    }

    $cart_contents = WC()->cart->get_cart();
    if ( empty( $cart_contents[ $main_cart_item_key ] ) || ! is_array( $cart_contents[ $main_cart_item_key ] ) ) {
        return;
    }

    $main_item = $cart_contents[ $main_cart_item_key ];
    $main_quantity = isset( $main_item['quantity'] ) ? max( 1, (int) $main_item['quantity'] ) : 1;
    $booking_date = isset( $main_item['booking_date'] ) ? trim( (string) $main_item['booking_date'] ) : '';
    $booking_time = isset( $main_item['booking_time'] ) ? trim( (string) $main_item['booking_time'] ) : '';
    if ( $booking_date === '' || $booking_time === '' ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'missing_booking_time', 'cartItemKey' => $main_cart_item_key ], 'warning' );
        wk_rh_add_checkout_booking_error( $errors, __( 'Det valgte bookingtidspunkt mangler på checkout. Vælg tidspunkt igen.', 'racehall-wc-ui' ), true );
        return;
    }

    if ( ! wk_rh_validate_checkout_booking_quantity( $main_item, $main_quantity, $errors ) ) {
        return;
    }

    $contact_person = wk_rh_get_checkout_contact_person( is_array( $posted_data ) ? $posted_data : [] );
    if ( $contact_person['firstName'] === '' || $contact_person['lastName'] === '' || $contact_person['email'] === '' || $contact_person['phone'] === '' ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'missing_contact_person', 'cartItemKey' => $main_cart_item_key ], 'warning' );
        $errors->add( 'rh_booking_contact_person', __( 'Kontaktoplysninger mangler. Udfyld venligst checkout-felterne.', 'racehall-wc-ui' ) );
        return;
    }

    $booking_location = isset( $main_item['booking_location'] ) ? sanitize_text_field( (string) $main_item['booking_location'] ) : '';
    $page_id = isset( $main_item['bmi_page_id'] ) ? trim( (string) $main_item['bmi_page_id'] ) : '';
    $resource_id = isset( $main_item['bmi_resource_id'] ) ? trim( (string) $main_item['bmi_resource_id'] ) : '';
    $proposal = isset( $main_item['bmi_proposal'] ) ? $main_item['bmi_proposal'] : null;

    if ( $page_id === '' || $resource_id === '' ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'missing_page_or_resource_id', 'cartItemKey' => $main_cart_item_key ], 'error' );
        wk_rh_add_checkout_booking_error( $errors, __( 'Bookingdata mangler på checkout. Vælg tidspunkt igen.', 'racehall-wc-ui' ), true );
        return;
    }

    if ( ! is_array( $proposal ) || empty( $proposal ) ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'missing_proposal', 'cartItemKey' => $main_cart_item_key ], 'warning' );
        wk_rh_add_checkout_booking_error( $errors, __( 'Bookingforslaget er ikke længere gyldigt. Vælg tidspunkt igen.', 'racehall-wc-ui' ), true );
        return;
    }

    $product_id = isset( $main_item['product_id'] ) ? (int) $main_item['product_id'] : 0;
    $product = $product_id > 0 ? wc_get_product( $product_id ) : null;
    if ( ! $product ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'missing_product', 'productId' => $product_id ], 'error' );
        $errors->add( 'rh_booking_missing_product', __( 'Kunne ikke finde bookingproduktet i checkout.', 'racehall-wc-ui' ) );
        return;
    }

    $bm_id = function_exists( 'get_field' )
        ? get_field( 'bmileisure_id', $product->get_id() )
        : get_post_meta( $product->get_id(), 'bmileisure_id', true );

    if ( empty( $bm_id ) ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'missing_upstream_product_id', 'productId' => $product->get_id() ], 'error' );
        $errors->add( 'rh_booking_missing_upstream_product', __( 'Bookingproduktet mangler upstream produkt-id.', 'racehall-wc-ui' ) );
        return;
    }

    if ( ! wk_rh_checkout_prefill_matches_posted( is_array( $posted_data ) ? $posted_data : [] ) ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'customer_info_changed_after_prepare', 'cartItemKey' => $main_cart_item_key ], 'warning' );
        $errors->add( 'rh_booking_prepare_required', __( 'Kundeoplysningerne er ændret. Klik på “Næste” igen før du gennemfører ordren.', 'racehall-wc-ui' ) );
        return;
    }

    $prepared_order_id = isset( $main_item['bmi_order_id'] ) ? trim( (string) $main_item['bmi_order_id'] ) : '';
    $prepared_order_item_id = isset( $main_item['bmi_order_item_id'] ) ? trim( (string) $main_item['bmi_order_item_id'] ) : '';
    if ( $prepared_order_id === '' || $prepared_order_item_id === '' ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'prepare_step_not_completed', 'cartItemKey' => $main_cart_item_key ], 'warning' );
        $errors->add( 'rh_booking_prepare_missing', __( 'Du skal først godkende kundeoplysningerne med “Næste” før betaling bliver mulig.', 'racehall-wc-ui' ) );
        return;
    }

    if ( ! wk_rh_contact_persons_match( $contact_person, wk_rh_get_main_item_booking_contact_person( $main_item ) ) ) {
        wk_rh_log_user_event( 'checkout.booking_failed', [ 'reason' => 'contact_person_changed_after_prepare', 'cartItemKey' => $main_cart_item_key ], 'warning' );
        $errors->add( 'rh_booking_prepare_contact_changed', __( 'Kontaktoplysningerne er ændret. Klik på “Næste” igen før ordren kan gennemføres.', 'racehall-wc-ui' ) );
        return;
    }

    $booking_sync = wk_rh_ensure_main_cart_booking_hold( $main_cart_item_key, $contact_person, 'checkout_validation' );
    if ( empty( $booking_sync['success'] ) ) {
        $message = isset( $booking_sync['userMessage'] ) ? (string) $booking_sync['userMessage'] : __( 'Tidslot kunne ikke reserveres under checkout. Prøv igen.', 'racehall-wc-ui' );
        if ( ! empty( $booking_sync['redirectToProduct'] ) ) {
            wk_rh_add_checkout_booking_error( $errors, $message, true );
        } else {
            $errors->add( 'rh_booking_transport_error', $message );
        }
        return;
    }

    $cart_contents = WC()->cart->get_cart();
    $main_item = $cart_contents[ $main_cart_item_key ];
    $main_order_id = isset( $booking_sync['orderId'] ) ? (string) $booking_sync['orderId'] : '';
    $main_order_item_id = isset( $booking_sync['orderItemId'] ) ? (string) $booking_sync['orderItemId'] : '';

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( empty( $cart_item['is_addon'] ) ) {
            continue;
        }

        $existing_addon_order_item_id = isset( $cart_item['bmi_order_item_id'] ) ? trim( (string) $cart_item['bmi_order_item_id'] ) : '';
        if ( $existing_addon_order_item_id !== '' ) {
            continue;
        }

        $original_addon_upstream_id = isset( $cart_item['addon_upstream_id'] ) ? trim( (string) $cart_item['addon_upstream_id'] ) : '';
        $addon_upstream_id = wk_rh_resolve_checkout_addon_product_id( $cart_item, $main_item['bmi_supplements'] ?? [] );

        if ( $addon_upstream_id !== '' && $addon_upstream_id !== $original_addon_upstream_id ) {
            WC()->cart->cart_contents[ $cart_item_key ]['addon_upstream_id'] = $addon_upstream_id;
            $cart_item['addon_upstream_id'] = $addon_upstream_id;

            wk_rh_log_user_event( 'checkout.addon_product_remapped', [
                'cartItemKey' => $cart_item_key,
                'addonName' => isset( $cart_item['addon_display_name'] ) ? sanitize_text_field( (string) $cart_item['addon_display_name'] ) : '',
                'fromUpstreamId' => $original_addon_upstream_id,
                'toUpstreamId' => $addon_upstream_id,
                'orderId' => $main_order_id,
            ] );
        }

        if ( $addon_upstream_id === '' ) {
            wk_rh_log_user_event( 'checkout.addon_failed', [ 'reason' => 'missing_upstream_id', 'cartItemKey' => $cart_item_key ], 'error' );
            $errors->add( 'rh_booking_addon_missing_product', __( 'Et add-on mangler upstream ID.', 'racehall-wc-ui' ) );
            return;
        }

        $addon_quantity = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
        $sell_result = function_exists( 'wk_rh_post_booking_sell' )
            ? wk_rh_post_booking_sell( $booking_location, $addon_upstream_id, $addon_quantity, $main_order_id, $main_order_item_id )
            : [ 'success' => false, 'data' => null ];

        if ( empty( $sell_result['success'] ) || ! is_array( $sell_result['data'] ?? null ) ) {
            wk_rh_log_user_event( 'checkout.addon_failed', [
                'reason' => 'sell_failed',
                'cartItemKey' => $cart_item_key,
                'upstreamId' => $addon_upstream_id,
                'quantity' => $addon_quantity,
                'orderId' => $main_order_id,
                'httpCode' => isset( $sell_result['httpCode'] ) ? (int) $sell_result['httpCode'] : 0,
                'body' => isset( $sell_result['rawBody'] ) ? (string) $sell_result['rawBody'] : '',
            ], 'error' );
            $errors->add( 'rh_booking_addon_sell_failed', __( 'Et add-on kunne ikke reserveres under checkout. Prøv igen.', 'racehall-wc-ui' ) );
            return;
        }

        $sell_data = $sell_result['data'];
        if ( empty( $sell_data['orderItemId'] ) ) {
            wk_rh_log_user_event( 'checkout.addon_failed', [
                'reason' => 'missing_order_item_id',
                'cartItemKey' => $cart_item_key,
                'upstreamId' => $addon_upstream_id,
                'orderId' => $main_order_id,
            ], 'error' );
            $errors->add( 'rh_booking_addon_missing_identifier', __( 'Et add-on mangler upstream item-id efter reservation.', 'racehall-wc-ui' ) );
            return;
        }

        WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_id'] = isset( $sell_data['orderId'] ) ? (string) $sell_data['orderId'] : $main_order_id;
        WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_item_id'] = (string) $sell_data['orderItemId'];
        WC()->cart->cart_contents[ $cart_item_key ]['bmi_sell_response'] = $sell_data;

        wk_rh_log_user_event( 'checkout.addon_confirmed', [
            'cartItemKey' => $cart_item_key,
            'productId' => isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0,
            'quantity' => $addon_quantity,
            'orderId' => isset( $sell_data['orderId'] ) ? (string) $sell_data['orderId'] : $main_order_id,
            'orderItemId' => (string) $sell_data['orderItemId'],
        ] );
    }

    WC()->cart->set_session();
}

add_action( 'woocommerce_after_checkout_validation', 'wk_rh_sync_checkout_booking_before_order', 20, 2 );

function wk_rh_prepare_checkout_booking_step() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ), 'rh_checkout_step_nonce' ) ) {
        wp_send_json_error( [ 'message' => __( 'Ugyldig forespørgsel. Opdater siden og prøv igen.', 'racehall-wc-ui' ) ], 403 );
    }

    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        wp_send_json_error( [ 'message' => __( 'Din kurv er tom.', 'racehall-wc-ui' ) ], 400 );
    }

    $posted_data = [];
    foreach ( wk_rh_get_checkout_prefill_keys() as $key ) {
        if ( ! array_key_exists( $key, $_POST ) ) {
            continue;
        }

        $raw_value = wp_unslash( (string) $_POST[ $key ] );
        $posted_data[ $key ] = $key === 'billing_email'
            ? sanitize_email( $raw_value )
            : sanitize_text_field( $raw_value );
    }

    $required_map = [
        'billing_first_name' => __( 'Fornavn', 'racehall-wc-ui' ),
        'billing_last_name' => __( 'Efternavn', 'racehall-wc-ui' ),
        'billing_email' => __( 'E-mail', 'racehall-wc-ui' ),
        'billing_phone' => __( 'Telefon nr.', 'racehall-wc-ui' ),
        'billing_address_1' => __( 'Gade og nr.', 'racehall-wc-ui' ),
        'billing_postcode' => __( 'Postnummer', 'racehall-wc-ui' ),
        'billing_city' => __( 'By', 'racehall-wc-ui' ),
        'billing_country' => __( 'Land', 'racehall-wc-ui' ),
    ];

    foreach ( $required_map as $key => $label ) {
        if ( empty( $posted_data[ $key ] ) ) {
            wp_send_json_error( [ 'message' => sprintf( __( '%s mangler.', 'racehall-wc-ui' ), $label ) ], 400 );
        }
    }

    if ( ! empty( $posted_data['billing_email'] ) && ! is_email( $posted_data['billing_email'] ) ) {
        wp_send_json_error( [ 'message' => __( 'E-mail adressen er ikke gyldig.', 'racehall-wc-ui' ) ], 400 );
    }

    wk_rh_store_checkout_prefill( $posted_data );

    $main_context = wk_rh_get_main_booking_context();
    if ( empty( $main_context['cartItemKey'] ) ) {
        $expired = wk_rh_expire_current_cart_reservation( 'checkout_step_missing_main_item', false );
        wp_send_json_error( [
            'message' => __( 'Bookinglinjen blev ikke fundet i kurven. Vælg tidspunkt igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => true,
            'redirectUrl' => isset( $expired['redirect_url'] ) ? (string) $expired['redirect_url'] : wk_rh_get_checkout_booking_redirect_url(),
        ], 400 );
    }

    $contact_person = wk_rh_get_checkout_contact_person( $posted_data );
    $force_refresh = ! empty( $main_context['orderId'] ) && ! wk_rh_contact_persons_match( $contact_person, $main_context['contactPerson'] ?? [] );

    $booking_sync = wk_rh_ensure_main_cart_booking_hold( $main_context['cartItemKey'], $contact_person, 'checkout_step', $force_refresh );
    if ( empty( $booking_sync['success'] ) ) {
        $redirect_url = ! empty( $booking_sync['redirectToProduct'] )
            ? (string) ( wk_rh_expire_current_cart_reservation( 'checkout_step_redirect_to_product', false )['redirect_url'] ?? wk_rh_get_checkout_booking_redirect_url() )
            : '';

        wp_send_json_error( [
            'message' => isset( $booking_sync['userMessage'] ) ? (string) $booking_sync['userMessage'] : __( 'Booking kunne ikke klargøres. Prøv igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => ! empty( $booking_sync['redirectToProduct'] ),
            'redirectUrl' => $redirect_url,
        ], 400 );
    }

    wp_send_json_success( [
        'reload' => true,
        'message' => __( 'Booking klargjort. Vælg nu add-ons og bekræft ordren.', 'racehall-wc-ui' ),
        'supplementsHtml' => wk_rh_get_checkout_step_supplements_markup( wk_rh_get_main_booking_context(), true ),
    ] );
}

add_action( 'wp_ajax_rh_prepare_checkout_booking', 'wk_rh_prepare_checkout_booking_step' );
add_action( 'wp_ajax_nopriv_rh_prepare_checkout_booking', 'wk_rh_prepare_checkout_booking_step' );

function wk_rh_set_checkout_addon_quantity() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ), 'rh_checkout_step_nonce' ) ) {
        wp_send_json_error( [ 'message' => __( 'Ugyldig forespørgsel. Opdater siden og prøv igen.', 'racehall-wc-ui' ) ], 403 );
    }

    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        wp_send_json_error( [ 'message' => __( 'Kurven er ikke tilgængelig lige nu.', 'racehall-wc-ui' ) ], 400 );
    }

    $main_context = wk_rh_get_main_booking_context();
    if ( empty( $main_context['cartItemKey'] ) ) {
        $expired = wk_rh_expire_current_cart_reservation( 'checkout_addon_missing_main_item', false );
        wp_send_json_error( [
            'message' => __( 'Bookinglinjen blev ikke fundet i kurven. Vælg tidspunkt igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => true,
            'redirectUrl' => isset( $expired['redirect_url'] ) ? (string) $expired['redirect_url'] : wk_rh_get_checkout_booking_redirect_url(),
        ], 400 );
    }

    if ( empty( $main_context['orderId'] ) || empty( $main_context['orderItemId'] ) ) {
        $expired = wk_rh_expire_current_cart_reservation( 'checkout_addon_missing_hold', false );
        wp_send_json_error( [
            'message' => __( 'Din reservation er udløbet. Vælg tidspunkt igen.', 'racehall-wc-ui' ),
            'redirectToProduct' => true,
            'redirectUrl' => isset( $expired['redirect_url'] ) ? (string) $expired['redirect_url'] : wk_rh_get_checkout_booking_redirect_url(),
        ], 400 );
    }

    $upstream_id = isset( $_POST['addon_upstream_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['addon_upstream_id'] ) ) : '';
    $requested_qty = isset( $_POST['quantity'] ) ? max( 0, (int) $_POST['quantity'] ) : 0;
    $supplement = wk_rh_find_booking_supplement_by_upstream_id( $main_context['supplements'], $upstream_id );
    if ( ! is_array( $supplement ) ) {
        wp_send_json_error( [ 'message' => __( 'Det valgte add-on er ikke tilgængeligt for denne booking.', 'racehall-wc-ui' ) ], 400 );
    }

    $bounds = wk_rh_get_supplement_quantity_bounds( $supplement );
    $min_qty = (int) $bounds['min'];
    $max_qty = isset( $bounds['max'] ) ? $bounds['max'] : null;

    if ( $requested_qty > 0 && $requested_qty < $min_qty ) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Add-on quantity must be at least %d.', 'racehall-wc-ui' ), $min_qty ) ], 400 );
    }

    if ( $max_qty !== null && $requested_qty > (int) $max_qty ) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Add-on quantity cannot exceed %d.', 'racehall-wc-ui' ), (int) $max_qty ) ], 400 );
    }

    $existing_key = wk_rh_get_addon_cart_item_key_by_upstream_id( $upstream_id );
    if ( $requested_qty === 0 ) {
        if ( $existing_key !== '' ) {
            WC()->cart->remove_cart_item( $existing_key );
            WC()->cart->calculate_totals();
            WC()->cart->set_session();
        }

        wp_send_json_success( [ 'reload' => false, 'quantity' => 0 ] );
    }

    if ( $existing_key !== '' ) {
        WC()->cart->set_quantity( $existing_key, $requested_qty, true );
        WC()->cart->calculate_totals();
        WC()->cart->set_session();
        wp_send_json_success( [ 'reload' => false, 'quantity' => $requested_qty ] );
    }

    $carrier_product_id = wk_rh_get_configured_addon_product_id();
    if ( $carrier_product_id <= 0 ) {
        wp_send_json_error( [ 'message' => __( 'Add-on produkt er ikke konfigureret endnu.', 'racehall-wc-ui' ) ], 400 );
    }

    $addon_name = isset( $supplement['name'] ) ? (string) $supplement['name'] : '';
    $addon_price = wk_rh_get_supplement_price_amount( $supplement );

    $_POST['is_addon'] = '1';
    $_POST['parent_racehall_product'] = isset( $main_context['item']['product_id'] ) ? (string) (int) $main_context['item']['product_id'] : '0';
    $_POST['booking_location'] = isset( $main_context['location'] ) ? (string) $main_context['location'] : '';
    $_POST['addon_price'] = (string) wc_format_decimal( $addon_price );
    $_POST['addon_upstream_id'] = $upstream_id;
    $_POST['addon_supplement_id'] = $upstream_id;
    $_POST['addon_display_name'] = $addon_name;
    $_POST['addon_min_qty'] = (string) $min_qty;
    $_POST['addon_max_qty'] = $max_qty !== null ? (string) (int) $max_qty : '';

    $added_key = WC()->cart->add_to_cart( $carrier_product_id, $requested_qty );
    if ( ! $added_key ) {
        wp_send_json_error( [ 'message' => __( 'Add-on kunne ikke tilføjes lige nu. Prøv igen.', 'racehall-wc-ui' ) ], 400 );
    }

    WC()->cart->calculate_totals();
    WC()->cart->set_session();

    wp_send_json_success( [ 'reload' => false, 'quantity' => $requested_qty ] );
}

add_action( 'wp_ajax_rh_checkout_set_addon_qty', 'wk_rh_set_checkout_addon_quantity' );
add_action( 'wp_ajax_nopriv_rh_checkout_set_addon_qty', 'wk_rh_set_checkout_addon_quantity' );

add_action( 'woocommerce_checkout_order_processed', function() {
    if ( function_exists( 'wk_rh_clear_booking_session_state' ) ) {
        wk_rh_clear_booking_session_state();
    }
}, 40 );

function wk_rh_extract_booking_supplements( $result ) {
    if ( ! is_array( $result ) ) {
        return [];
    }

    if ( isset( $result['supplements'] ) && is_array( $result['supplements'] ) ) {
        $normalized_supplements = [];

        foreach ( $result['supplements'] as $supplement ) {
            if ( ! is_array( $supplement ) ) {
                continue;
            }

            $normalized_supplements[] = wk_rh_normalize_booking_supplement_row( $supplement );
        }

        return $normalized_supplements;
    }

    if ( function_exists( 'wk_rh_log_upstream_event' ) ) {
        wk_rh_log_upstream_event( 'warning', 'booking/book response missing top-level supplements', [
            'operation' => 'booking_book',
            'responseKeys' => array_keys( $result ),
        ] );
    }

    return [];
}

function wk_rh_send_booking_to_bmi_on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }

    if ( empty( $cart_item_data['is_addon'] ) ) {
        $product_url = get_permalink( (int) $product_id );
        if ( is_string( $product_url ) && $product_url !== '' ) {
            WC()->session->set( 'rh_last_product_url', $product_url );
        }
    }

    if ( ! empty( $cart_item_data['bmi_order_id'] ) && ! empty( $cart_item_data['bmi_order_item_id'] ) && isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
        WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_id'] = (string) $cart_item_data['bmi_order_id'];
        WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_item_id'] = (string) $cart_item_data['bmi_order_item_id'];
        WC()->cart->set_session();
    }
}

add_action( 'woocommerce_check_cart_items', function() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
        return;
    }

    $removed_invalid_booking = false;
    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( ! empty( $cart_item['is_addon'] ) ) {
            continue;
        }

        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        if ( $product_id <= 0 ) {
            continue;
        }

        $bm_id = function_exists( 'get_field' )
            ? get_field( 'bmileisure_id', $product_id )
            : get_post_meta( $product_id, 'bmileisure_id', true );

        if ( empty( $bm_id ) ) {
            continue;
        }

        $order_id = isset( $cart_item['bmi_order_id'] ) ? trim( (string) $cart_item['bmi_order_id'] ) : '';
        $order_item_id = isset( $cart_item['bmi_order_item_id'] ) ? trim( (string) $cart_item['bmi_order_item_id'] ) : '';

        if ( $order_id === '' && $order_item_id === '' ) {
            continue;
        }

        if ( $order_id !== '' && $order_item_id !== '' ) {
            continue;
        }

        WC()->cart->remove_cart_item( $cart_item_key );
        $removed_invalid_booking = true;
    }

    if ( $removed_invalid_booking ) {
        wc_add_notice( __( 'En booking i kurven manglede bekræftelse og blev fjernet. Vælg tidspunkt igen.', 'racehall-wc-ui' ), 'error' );
    }
}, 15 );

// Ensure BMI IDs are passed to the order item when the order is created
add_action( 'woocommerce_checkout_create_order_line_item', 'wk_rh_add_bmi_ids_to_order_item', 10, 4 );
function wk_rh_add_bmi_ids_to_order_item( $item, $cart_item_key, $values, $order ) {
    $is_addon = ! empty( $values['is_addon'] );

    if ( $is_addon && ! empty( $values['addon_display_name'] ) && is_callable( [ $item, 'set_name' ] ) ) {
        $item->set_name( sanitize_text_field( (string) $values['addon_display_name'] ) );
    }

    if ( isset( $values['bmi_order_id'] ) ) {
        $item->add_meta_data( 'bmi_order_id', $values['bmi_order_id'], true );
        $item->add_meta_data( 'bmi_order_item_id', $values['bmi_order_item_id'], true );

        if ( $is_addon ) {
            $item->add_meta_data( 'Type', __( 'Add-on', 'racehall-wc-ui' ), true );
            $item->add_meta_data( 'Upstream orderId', sanitize_text_field( (string) $values['bmi_order_id'] ), true );
            if ( ! empty( $values['bmi_order_item_id'] ) ) {
                $item->add_meta_data( 'Upstream orderItemId', sanitize_text_field( (string) $values['bmi_order_item_id'] ), true );
            }
        }

        if ( ! $is_addon ) {
            $order->update_meta_data( 'bmi_order_id', $values['bmi_order_id'] );
            $order->update_meta_data( 'bmi_order_item_id', $values['bmi_order_item_id'] );
        }
    }

    if ( ! $is_addon && ! empty( $values['bmi_booking_response'] ) && is_array( $values['bmi_booking_response'] ) ) {
        $booking_response_json = wp_json_encode( $values['bmi_booking_response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( is_string( $booking_response_json ) && $booking_response_json !== '' ) {
            $item->add_meta_data( 'wk_rh_booking_response', $booking_response_json, true );
            $order->update_meta_data( 'wk_rh_booking_response', $booking_response_json );
        }
    }

    if ( ! $is_addon && isset( $values['bmi_supplements'] ) && is_array( $values['bmi_supplements'] ) ) {
        $supplements_json = wp_json_encode( $values['bmi_supplements'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( is_string( $supplements_json ) && $supplements_json !== '' ) {
            $item->add_meta_data( 'wk_rh_booking_supplements', $supplements_json, true );
            $order->update_meta_data( 'wk_rh_booking_supplements', $supplements_json );
        }
    }

    if ( ! $is_addon && ! empty( $values['bmi_hold_expires_at'] ) ) {
        $item->add_meta_data( 'wk_rh_booking_expires_at', (int) $values['bmi_hold_expires_at'], true );
        $order->update_meta_data( 'wk_rh_booking_expires_at', (int) $values['bmi_hold_expires_at'] );
    }

    if ( $is_addon ) {
        $item->add_meta_data( '_wk_rh_is_addon', 'yes', true );

        $addon_upstream_id = '';
        if ( isset( $values['addon_upstream_id'] ) ) {
            $addon_upstream_id = sanitize_text_field( (string) $values['addon_upstream_id'] );
        } elseif ( isset( $values['addon_upstream_product_id'] ) ) {
            $addon_upstream_id = sanitize_text_field( (string) $values['addon_upstream_product_id'] );
        }

        if ( $addon_upstream_id !== '' ) {
            $item->add_meta_data( '_wk_rh_addon_upstream_id', $addon_upstream_id, true );
            $item->add_meta_data( 'Upstream add-on ID', $addon_upstream_id, true );
        }

        if ( isset( $values['addon_display_name'] ) ) {
            $item->add_meta_data( '_wk_rh_addon_display_name', sanitize_text_field( (string) $values['addon_display_name'] ), true );
        }

        if ( isset( $values['addon_unit_price'] ) && is_numeric( $values['addon_unit_price'] ) ) {
            $item->add_meta_data( '_wk_rh_addon_unit_price', wc_format_decimal( $values['addon_unit_price'] ), true );
        }

        if ( ! empty( $values['bmi_sell_response'] ) && is_array( $values['bmi_sell_response'] ) ) {
            $sell_response_json = wp_json_encode( $values['bmi_sell_response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( is_string( $sell_response_json ) && $sell_response_json !== '' ) {
                $item->add_meta_data( 'wk_rh_addon_sell_response', $sell_response_json, true );

                $existing_responses = $order->get_meta( 'wk_rh_addon_sell_responses', true );
                if ( ! is_array( $existing_responses ) ) {
                    $existing_responses = [];
                }

                $existing_responses[] = [
                    'name' => isset( $values['addon_display_name'] ) ? sanitize_text_field( (string) $values['addon_display_name'] ) : '',
                    'productId' => isset( $values['addon_upstream_id'] ) ? sanitize_text_field( (string) $values['addon_upstream_id'] ) : '',
                    'response' => $values['bmi_sell_response'],
                ];
                $order->update_meta_data( 'wk_rh_addon_sell_responses', $existing_responses );
            }
        }
    }
}

function racehall_get_connected_products() {
    return wk_rh_get_connected_products();
}

