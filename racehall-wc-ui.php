<?php
/**
 * Plugin Name: Onsite Booking System
 * Description: Onsite booking integration for Racehall and bmileisure API.
 * Version: 1.0.3
 * Author: Webkonsulenterne ApS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( defined( 'RACEHALL_WC_UI_BOOTSTRAPPED' ) ) {
    return;
}
define( 'RACEHALL_WC_UI_BOOTSTRAPPED', true );

// Define plugin paths
define( 'RACEHALL_WC_UI_PATH', plugin_dir_path( __FILE__ ) );
define( 'RACEHALL_WC_UI_URL', plugin_dir_url( __FILE__ ) );
define( 'RACEHALL_WC_UI_VERSION', '1.0.3' );

function wk_rh_get_settings_defaults() {
    return [
        'environment'         => 'test',
        'test_base_url'       => 'https://testbmiapigateway.azure-api.net',
        'live_base_url'       => 'https://api.bmileisure.com',
        'accept_language'     => 'en',
        'test_locations_json' => '[]',
        'live_locations_json' => '[]',
    ];
}

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

    $sanitized = [
        'environment'         => in_array( $input['environment'] ?? 'test', [ 'test', 'live' ], true ) ? $input['environment'] : 'test',
        'test_base_url'       => esc_url_raw( $input['test_base_url'] ?? $defaults['test_base_url'] ),
        'live_base_url'       => esc_url_raw( $input['live_base_url'] ?? $defaults['live_base_url'] ),
        'accept_language'     => sanitize_text_field( $input['accept_language'] ?? 'en' ),
        'test_locations_json' => wk_rh_sanitize_locations_json( $input['test_locations_json'] ?? '[]', 'wk_rh_test_locations_json_invalid' ),
        'live_locations_json' => wk_rh_sanitize_locations_json( $input['live_locations_json'] ?? '[]', 'wk_rh_live_locations_json_invalid' ),
    ];

    return $sanitized;
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

function wk_rh_get_upstream_products_cache_key( $location = '' ) {
    $settings = wk_rh_get_settings();
    $env      = isset( $settings['environment'] ) ? sanitize_text_field( $settings['environment'] ) : 'test';
    $loc      = strtolower( trim( (string) $location ) );
    return 'wk_rh_products_' . md5( $env . '|' . $loc );
}

function wk_rh_get_last_order_sync_error( $wc_order_id ) {
    $logs = get_option( 'wk_rh_upstream_logs', [] );
    if ( ! is_array( $logs ) ) {
        return null;
    }

    for ( $index = count( $logs ) - 1; $index >= 0; $index-- ) {
        $log = $logs[ $index ];
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
        update_option( 'wk_rh_upstream_logs', [], false );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Upstream logs cleared.', 'onsite-booking-system' ) . '</p></div>';
    }

    $logs = get_option( 'wk_rh_upstream_logs', [] );
    if ( ! is_array( $logs ) ) {
        $logs = [];
    }

    $logs = array_reverse( $logs );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Onsite Booking Diagnostics', 'onsite-booking-system' ); ?></h1>
        <p><?php esc_html_e( 'Recent upstream sync events (latest first).', 'onsite-booking-system' ); ?></p>

        <form method="post" style="margin: 12px 0 16px;">
            <?php wp_nonce_field( 'wk_rh_clear_logs_action', 'wk_rh_clear_logs_nonce' ); ?>
            <input type="hidden" name="wk_rh_clear_logs" value="1">
            <?php submit_button( __( 'Clear Logs', 'onsite-booking-system' ), 'secondary', 'submit', false ); ?>
        </form>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:180px;"><?php esc_html_e( 'Time (UTC)', 'onsite-booking-system' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( 'Level', 'onsite-booking-system' ); ?></th>
                    <th style="width:280px;"><?php esc_html_e( 'Message', 'onsite-booking-system' ); ?></th>
                    <th><?php esc_html_e( 'Context', 'onsite-booking-system' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr>
                    <td colspan="4"><?php esc_html_e( 'No upstream events logged yet.', 'onsite-booking-system' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <?php
                    $time    = isset( $log['time'] ) ? (string) $log['time'] : '';
                    $level   = isset( $log['level'] ) ? strtoupper( (string) $log['level'] ) : '';
                    $message = isset( $log['message'] ) ? (string) $log['message'] : '';
                    $context = isset( $log['context'] ) && is_array( $log['context'] ) ? $log['context'] : [];
                    ?>
                    <tr>
                        <td><?php echo esc_html( $time ); ?></td>
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

// Enqueue CSS/JS for single product pages
add_action('wp_enqueue_scripts', function() {
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
    }

});

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
add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_replace_main_product_only', 10, 3 );
function wk_rh_replace_main_product_only( $passed, $product_id, $quantity ) {
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

    // BMI booking session data (stored by rh_save_proposal AJAX, retrieved here)
    $bmi_data = WC()->session ? WC()->session->get('rh_bmi_booking') : null;
    if ( $bmi_data && ! $is_addon_request ) {
        $cart_item_data['bmi_proposal']    = $bmi_data['proposal']    ?? null;
        $cart_item_data['bmi_page_id']     = $bmi_data['pageId']      ?? '';
        $cart_item_data['bmi_resource_id'] = $bmi_data['resourceId']  ?? '';
        $cart_item_data['bmi_order_id']    = $bmi_data['orderId']     ?? '';
        $cart_item_data['bmi_order_item_id'] = $bmi_data['orderItemId'] ?? '';
    }

    if ( $is_addon_request ) {
        unset(
            $cart_item_data['bmi_proposal'],
            $cart_item_data['bmi_page_id'],
            $cart_item_data['bmi_resource_id']
        );
    }

    return $cart_item_data;
}

add_filter( 'woocommerce_add_to_cart_validation', 'wk_rh_block_addon_without_parent', 20, 3 );
function wk_rh_block_addon_without_parent( $passed, $product_id, $quantity ) {
    if ( isset( $_POST['is_addon'] ) && WC()->cart->is_empty() ) {
        wc_add_notice( __( 'Du skal vælge et race før du kan tilføje add-ons.', 'racehall-wc-ui' ), 'error' );
        return false;
    }

    if ( isset( $_POST['is_addon'] ) ) {
        $mapped_upstream_id = function_exists( 'get_field' )
            ? get_field( 'bmileisure_id', $product_id )
            : get_post_meta( $product_id, 'bmileisure_id', true );

        if ( empty( $mapped_upstream_id ) ) {
            wc_add_notice( __( 'Dette add-on produkt mangler upstream mapping (bmileisure_id).', 'racehall-wc-ui' ), 'error' );
            return false;
        }
    }

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

function wk_rh_send_booking_to_bmi_on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    $product = wc_get_product( $product_id );
    if ( ! $product ) return;

    // 🔥 Get BMI Product ID from product meta
    $bm_id = function_exists( 'get_field' )
        ? get_field( 'bmileisure_id', $product->get_id() )
        : get_post_meta( $product->get_id(), 'bmileisure_id', true );

    if ( ! $bm_id ) return;

    // These must be saved earlier in cart item data
    $proposal   = isset($cart_item_data['bmi_proposal']) ? $cart_item_data['bmi_proposal'] : null;
    $page_id    = isset($cart_item_data['bmi_page_id']) ? $cart_item_data['bmi_page_id'] : '';
    $resourceId = isset($cart_item_data['bmi_resource_id']) ? $cart_item_data['bmi_resource_id'] : '';
    $is_addon   = ! empty( $cart_item_data['is_addon'] );

    $booking_location = isset( $cart_item_data['booking_location'] ) ? sanitize_text_field( $cart_item_data['booking_location'] ) : '';

    if ( $is_addon && empty( $proposal ) && function_exists( 'wk_rh_post_booking_sell' ) ) {
        $parent_product_id = ! empty( $cart_item_data['parent_racehall_product'] ) ? (int) $cart_item_data['parent_racehall_product'] : 0;
        $parent_order_id = '';
        $parent_order_item_id = '';

        if ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $existing_item ) {
                if ( (int) ( $existing_item['product_id'] ?? 0 ) !== $parent_product_id ) {
                    continue;
                }
                if ( ! empty( $existing_item['is_addon'] ) ) {
                    continue;
                }
                $parent_order_id      = (string) ( $existing_item['bmi_order_id'] ?? '' );
                $parent_order_item_id = (string) ( $existing_item['bmi_order_item_id'] ?? '' );
                if ( $parent_order_id !== '' ) {
                    break;
                }
            }
        }

        if ( $parent_order_id !== '' ) {
            $sell_result = wk_rh_post_booking_sell( $booking_location, $bm_id, $quantity, $parent_order_id, $parent_order_item_id );
            if ( ! empty( $sell_result['success'] ) && isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
                $sell_data = is_array( $sell_result['data'] ?? null ) ? $sell_result['data'] : [];
                WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_id'] = $sell_data['orderId'] ?? $parent_order_id;

                if ( ! empty( $sell_data['orderItemId'] ) ) {
                    WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_item_id'] = $sell_data['orderItemId'];
                } elseif ( function_exists( 'wk_rh_log_upstream_event' ) ) {
                    wk_rh_log_upstream_event( 'error', 'booking/sell succeeded without orderItemId', [
                        'operation' => 'booking_sell',
                        'productId' => (string) $bm_id,
                        'orderId' => (string) ( $sell_data['orderId'] ?? $parent_order_id ),
                        'location' => (string) $booking_location,
                        'response' => $sell_data,
                    ] );
                }

                WC()->cart->set_session();
            }
        }

        return;
    }

    if ( ! $proposal ) return;

    if ( ! empty( $cart_item_data['bmi_order_id'] ) && ! empty( $cart_item_data['bmi_order_item_id'] ) ) {
        if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
            WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_id']      = $cart_item_data['bmi_order_id'];
            WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_item_id'] = $cart_item_data['bmi_order_item_id'];
            WC()->cart->set_session();
        }
        return;
    }

    // Prevent duplicate execution on the same cart item
    if ( isset(WC()->session) && WC()->session->get('bmi_booked_' . $cart_item_key) ) return;

    $creds            = wk_rh_get_api_credentials( $booking_location );

    if ( empty( $creds['client_key'] ) || empty( $creds['subscription_key'] ) ) {
        return;
    }

    $token = function_exists( 'wk_rh_get_token' ) ? wk_rh_get_token( $booking_location ) : false;
    if ( ! $token ) return;

    $current_user = wp_get_current_user();
    $first_name = ($current_user && $current_user->ID) ? $current_user->user_firstname : 'Guest';
    if(empty($first_name)) $first_name = 'Guest';
    $last_name = ($current_user && $current_user->ID) ? $current_user->user_lastname : 'User';
    if(empty($last_name)) $last_name = 'User';
    $email = ($current_user && $current_user->ID) ? $current_user->user_email : 'guest@example.com';
    $phone = ($current_user && $current_user->ID) ? get_user_meta( $current_user->ID, 'billing_phone', true ) : '00000000';
    if(empty($phone)) $phone = '00000000';

    $body = [
        "productId" => (string) $bm_id,
        "pageId"    => (string) $page_id,
        "quantity"  => (int) $quantity,
        "resourceId"=> (string) $resourceId,
        "proposal"  => is_string($proposal) ? json_decode($proposal, true) : $proposal,
        "contactPerson" => [
            "firstName" => $first_name,
            "lastName"  => $last_name,
            "email"     => $email,
            "phone"     => $phone
        ]
    ];

    $url = $creds['base_url'] . '/public-booking/' . rawurlencode( $creds['client_key'] ) . '/booking/book';
    $response = function_exists( 'wk_rh_remote_request_with_retry' )
        ? wk_rh_remote_request_with_retry(
            'POST',
            $url,
            [
                'headers' => [
                    'Authorization'        => 'Bearer ' . $token,
                    'Content-Type'         => 'application/json',
                    'Accept-Language'      => $creds['accept_language'],
                    'Bmi-Subscription-Key' => $creds['subscription_key']
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 60
            ],
            1,
            [
                'operation' => 'booking_book_add_to_cart',
                'productId' => (string) $bm_id,
                'location' => (string) $booking_location,
            ]
        )
        : wp_remote_post(
            $url,
            [
                'headers' => [
                    'Authorization'        => 'Bearer ' . $token,
                    'Content-Type'         => 'application/json',
                    'Accept-Language'      => $creds['accept_language'],
                    'Bmi-Subscription-Key' => $creds['subscription_key']
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 60
            ]
        );

    if ( is_wp_error( $response ) ) {
        if ( function_exists( 'wk_rh_log_upstream_event' ) ) {
            wk_rh_log_upstream_event( 'error', 'Upstream booking/book failed at add_to_cart transport level', [
                'operation' => 'booking_book_add_to_cart',
                'productId' => (string) $bm_id,
                'location' => (string) $booking_location,
                'error' => $response->get_error_message(),
            ] );
        }
        return;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $result = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! ( $code >= 200 && $code < 300 ) ) {
        if ( function_exists( 'wk_rh_log_upstream_event' ) ) {
            wk_rh_log_upstream_event( 'error', 'Upstream booking/book failed at add_to_cart', [
                'operation' => 'booking_book_add_to_cart',
                'productId' => (string) $bm_id,
                'location' => (string) $booking_location,
                'httpCode' => $code,
                'body' => wp_remote_retrieve_body( $response ),
            ] );
        }
        return;
    }

    if ( empty( $result['orderId'] ) ) return;

    if ( empty( $result['orderItemId'] ) ) {
        if ( function_exists( 'wk_rh_log_upstream_event' ) ) {
            wk_rh_log_upstream_event( 'error', 'booking/book succeeded without orderItemId', [
                'operation' => 'booking_book_add_to_cart',
                'productId' => (string) $bm_id,
                'orderId' => (string) $result['orderId'],
                'location' => (string) $booking_location,
                'response' => $result,
            ] );
        }
        return;
    }

    // Save BMI IDs to cart item data
    if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
        WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_id'] = $result['orderId'];
        WC()->cart->cart_contents[ $cart_item_key ]['bmi_order_item_id'] = $result['orderItemId'];
        WC()->cart->set_session();
        if (isset(WC()->session)) {
            WC()->session->set('bmi_booked_' . $cart_item_key, true);
        }
    }
}

// Ensure BMI IDs are passed to the order item when the order is created
add_action( 'woocommerce_checkout_create_order_line_item', 'wk_rh_add_bmi_ids_to_order_item', 10, 4 );
function wk_rh_add_bmi_ids_to_order_item( $item, $cart_item_key, $values, $order ) {
    if ( isset( $values['bmi_order_id'] ) ) {
        $item->add_meta_data( 'bmi_order_id', $values['bmi_order_id'], true );
        $item->add_meta_data( 'bmi_order_item_id', $values['bmi_order_item_id'], true );

        // Optionally save to the order itself
        $order->update_meta_data('bmi_order_id', $values['bmi_order_id']);
        $order->update_meta_data('bmi_order_item_id', $values['bmi_order_item_id']);
    }
}

function racehall_get_connected_products() {
    return wk_rh_get_connected_products();
}

