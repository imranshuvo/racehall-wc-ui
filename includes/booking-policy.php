<?php
/**
 * Racehall booking policy.
 *
 * Client rules are evaluated against the venue-local date and time displayed for
 * the selected BMI proposal. End times are inclusive (for example 20:00 is peak,
 * while 20:01 is not).
 *
 * BMI public-booking API reference:
 * https://bmileisure.atlassian.net/wiki/external/YTYwMTA3YjAyNWVkNDAzMmJhNDkxZWE5OWZiYTc5YmM
 * Statutory public-holiday definitions:
 * DK: https://www.retsinformation.dk/eli/lta/2023/214
 * SE: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/lag-1989253-om-allmanna-helgdagar_sfs-1989-253/
 *
 * The client also treats Christmas Eve and New Year's Eve in both countries,
 * plus Midsummer Eve in Sweden, as operational holiday exclusions even though
 * they are not statutory public holidays.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wk_rh_get_booking_race_type_options() {
    return [
        ''          => __( 'Automatic (from product name)', 'racehall-wc-ui' ),
        'exclusive' => __( 'Exclusive', 'racehall-wc-ui' ),
        'family'    => __( 'Family', 'racehall-wc-ui' ),
        'open'      => __( 'Open', 'racehall-wc-ui' ),
        'other'     => __( 'Other', 'racehall-wc-ui' ),
    ];
}

function wk_rh_normalize_booking_race_type( $value ) {
    $value = sanitize_key( (string) $value );
    return in_array( $value, [ 'exclusive', 'family', 'open', 'other' ], true ) ? $value : '';
}

function wk_rh_infer_booking_race_type_from_name( $name ) {
    $name = html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $name = strtolower( remove_accents( $name ) );

    if ( preg_match( '/(?:^|[^a-z0-9])(closed|exclusive)(?:[^a-z0-9]|$)/i', $name ) ) {
        return 'exclusive';
    }
    if ( preg_match( '/(?:^|[^a-z0-9])family(?:[^a-z0-9]|$)/i', $name ) ) {
        return 'family';
    }
    if ( preg_match( '/(?:^|[^a-z0-9])open(?:[^a-z0-9]|$)/i', $name ) ) {
        return 'open';
    }

    return 'other';
}

function wk_rh_get_product_booking_race_type( $product = null, $fallback_name = '' ) {
    $product_id = function_exists( 'wk_rh_normalize_product_id' ) ? wk_rh_normalize_product_id( $product ) : absint( $product );
    $configured = $product_id > 0 && function_exists( 'wk_rh_get_canonical_product_meta_value' )
        ? wk_rh_get_canonical_product_meta_value( $product_id, [ '_wk_rh_booking_race_type' ] )
        : '';
    $type = wk_rh_normalize_booking_race_type( $configured );

    if ( $type !== '' ) {
        return $type;
    }

    // WooCommerce owns the booking policy configuration. BMI's name is only a
    // fallback because its rename may happen independently of the website rename.
    // Preferring the BMI name here could classify a Woo "Exclusive" product as
    // "other" and bypass the local minimum rules.
    $product_name = '';
    if ( $product_id > 0 ) {
        $product_object = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
        if ( $product_object && is_callable( [ $product_object, 'get_name' ] ) ) {
            $product_name = (string) $product_object->get_name();
        } elseif ( function_exists( 'get_the_title' ) ) {
            $product_name = (string) get_the_title( $product_id );
        }
    }

    if ( $product_name !== '' ) {
        $product_type = wk_rh_infer_booking_race_type_from_name( $product_name );
        if ( $product_type !== 'other' ) {
            return $product_type;
        }
    }

    return wk_rh_infer_booking_race_type_from_name( $fallback_name );
}

/**
 * Resolve a policy type without allowing stale proposal/session data to weaken
 * the current WooCommerce product classification.
 */
function wk_rh_resolve_booking_race_type( $product = null, $stored_type = '', $fallback_name = '' ) {
    $product_type = wk_rh_get_product_booking_race_type( $product, $fallback_name );
    $stored_type = wk_rh_normalize_booking_race_type( $stored_type );

    // Exclusive is the restrictive classification, so either trusted source is
    // sufficient to retain the minimum-participant enforcement.
    if ( $product_type === 'exclusive' || $stored_type === 'exclusive' ) {
        return 'exclusive';
    }
    if ( $product_type !== 'other' ) {
        return $product_type;
    }

    return $stored_type !== '' ? $stored_type : 'other';
}

add_action( 'woocommerce_product_options_general_product_data', 'wk_rh_add_booking_race_type_product_field' );
function wk_rh_add_booking_race_type_product_field() {
    if ( ! function_exists( 'woocommerce_wp_select' ) ) {
        return;
    }

    woocommerce_wp_select( [
        'id'          => '_wk_rh_booking_race_type',
        'label'       => __( 'Booking race type', 'racehall-wc-ui' ),
        'description' => __( 'Used by Racehall booking rules. Automatic recognizes Closed and Exclusive without case sensitivity.', 'racehall-wc-ui' ),
        'desc_tip'    => true,
        'options'     => wk_rh_get_booking_race_type_options(),
    ] );
}

add_action( 'woocommerce_admin_process_product_object', 'wk_rh_save_booking_race_type_product_field' );
function wk_rh_save_booking_race_type_product_field( $product ) {
    if ( ! is_object( $product ) || ! is_callable( [ $product, 'update_meta_data' ] ) ) {
        return;
    }

    $raw_value = isset( $_POST['_wk_rh_booking_race_type'] ) ? wp_unslash( $_POST['_wk_rh_booking_race_type'] ) : '';
    $product->update_meta_data( '_wk_rh_booking_race_type', wk_rh_normalize_booking_race_type( $raw_value ) );
}

function wk_rh_get_booking_policy_location_key( $location ) {
    if ( function_exists( 'wk_rh_normalize_location_key' ) ) {
        return wk_rh_normalize_location_key( $location );
    }

    $value = strtolower( remove_accents( (string) $location ) );
    if ( preg_match( '/kobenhavn|copenhagen|kbh|cph/', $value ) ) {
        return 'kobenhavn';
    }
    if ( preg_match( '/stockholm|sthlm/', $value ) ) {
        return 'stockholm';
    }
    if ( preg_match( '/aarhus|arhus|^aar$/', $value ) ) {
        return 'aarhus';
    }
    return '';
}

function wk_rh_get_peak_minimum_policy_config() {
    $config = [
        'kobenhavn' => [
            'country'         => 'DK',
            'timezone'        => 'Europe/Copenhagen',
            'minimum'         => 18,
            'excluded_months' => [ 1, 8 ],
            'windows'         => [
                5 => [ [ '14:00', '20:00' ] ],
                6 => [ [ '09:00', '19:00' ] ],
                7 => [ [ '14:00', '18:00' ] ],
            ],
        ],
        'stockholm' => [
            'country'         => 'SE',
            'timezone'        => 'Europe/Stockholm',
            'minimum'         => 15,
            'excluded_months' => [],
            'windows'         => [
                5 => [ [ '13:00', '17:00' ] ],
                6 => [ [ '11:00', '18:00' ] ],
            ],
        ],
        'aarhus' => [
            'country'         => 'DK',
            'timezone'        => 'Europe/Copenhagen',
            'minimum'         => 15,
            'excluded_months' => [ 1, 8 ],
            'windows'         => [
                6 => [ [ '11:00', '15:00' ] ],
            ],
        ],
    ];

    return apply_filters( 'wk_rh_peak_minimum_policy_config', $config );
}

function wk_rh_get_easter_sunday_date( $year, $timezone ) {
    $year = (int) $year;
    $a = $year % 19;
    $b = intdiv( $year, 100 );
    $c = $year % 100;
    $d = intdiv( $b, 4 );
    $e = $b % 4;
    $f = intdiv( $b + 8, 25 );
    $g = intdiv( $b - $f + 1, 3 );
    $h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
    $i = intdiv( $c, 4 );
    $k = $c % 4;
    $l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
    $m = intdiv( $a + 11 * $h + 22 * $l, 451 );
    $month = intdiv( $h + $l - 7 * $m + 114, 31 );
    $day = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

    return new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), $timezone );
}

function wk_rh_get_country_public_holiday_dates( $country, $year, $timezone ) {
    $country = strtoupper( (string) $country );
    $year = (int) $year;
    $easter = wk_rh_get_easter_sunday_date( $year, $timezone );
    $dates = [];

    if ( $country === 'DK' ) {
        $dates = [
            sprintf( '%04d-01-01', $year ),
            $easter->modify( '-3 days' )->format( 'Y-m-d' ), // Maundy Thursday.
            $easter->modify( '-2 days' )->format( 'Y-m-d' ), // Good Friday.
            $easter->format( 'Y-m-d' ),
            $easter->modify( '+1 day' )->format( 'Y-m-d' ),
            $easter->modify( '+39 days' )->format( 'Y-m-d' ),
            $easter->modify( '+49 days' )->format( 'Y-m-d' ),
            $easter->modify( '+50 days' )->format( 'Y-m-d' ),
            sprintf( '%04d-12-24', $year ), // Operational exclusion: Christmas Eve.
            sprintf( '%04d-12-25', $year ),
            sprintf( '%04d-12-26', $year ),
            sprintf( '%04d-12-31', $year ), // Operational exclusion: New Year's Eve.
        ];
    } elseif ( $country === 'SE' ) {
        $midsummer = new DateTimeImmutable( sprintf( '%04d-06-20 00:00:00', $year ), $timezone );
        while ( (int) $midsummer->format( 'N' ) !== 6 ) {
            $midsummer = $midsummer->modify( '+1 day' );
        }
        $all_saints = new DateTimeImmutable( sprintf( '%04d-10-31 00:00:00', $year ), $timezone );
        while ( (int) $all_saints->format( 'N' ) !== 6 ) {
            $all_saints = $all_saints->modify( '+1 day' );
        }
        $dates = [
            sprintf( '%04d-01-01', $year ),
            sprintf( '%04d-01-06', $year ),
            $easter->modify( '-2 days' )->format( 'Y-m-d' ),
            $easter->format( 'Y-m-d' ),
            $easter->modify( '+1 day' )->format( 'Y-m-d' ),
            sprintf( '%04d-05-01', $year ),
            $easter->modify( '+39 days' )->format( 'Y-m-d' ),
            $easter->modify( '+49 days' )->format( 'Y-m-d' ),
            sprintf( '%04d-06-06', $year ),
            $midsummer->modify( '-1 day' )->format( 'Y-m-d' ), // Operational exclusion: Midsummer Eve.
            $midsummer->format( 'Y-m-d' ),
            $all_saints->format( 'Y-m-d' ),
            sprintf( '%04d-12-24', $year ), // Operational exclusion: Christmas Eve.
            sprintf( '%04d-12-25', $year ),
            sprintf( '%04d-12-26', $year ),
            sprintf( '%04d-12-31', $year ), // Operational exclusion: New Year's Eve.
        ];
    }

    $dates = array_values( array_unique( $dates ) );
    return apply_filters( 'wk_rh_country_public_holiday_dates', $dates, $country, $year, $timezone );
}

function wk_rh_is_country_public_holiday( DateTimeImmutable $date, $country ) {
    $dates = wk_rh_get_country_public_holiday_dates( $country, (int) $date->format( 'Y' ), $date->getTimezone() );
    return in_array( $date->format( 'Y-m-d' ), $dates, true );
}

function wk_rh_get_proposal_start_value( $proposal ) {
    if ( ! is_array( $proposal ) || empty( $proposal['blocks'] ) || ! is_array( $proposal['blocks'] ) ) {
        return '';
    }
    $first = reset( $proposal['blocks'] );
    return is_array( $first ) && ! empty( $first['block']['start'] ) ? trim( (string) $first['block']['start'] ) : '';
}

function wk_rh_get_proposal_resource_id( $proposal ) {
    if ( ! is_array( $proposal ) || empty( $proposal['blocks'] ) || ! is_array( $proposal['blocks'] ) ) {
        return '';
    }
    $first = reset( $proposal['blocks'] );
    if ( ! is_array( $first ) ) {
        return '';
    }
    if ( ! empty( $first['block']['resourceId'] ) ) {
        return sanitize_text_field( (string) $first['block']['resourceId'] );
    }
    return ! empty( $first['productLineIds'][0] ) ? sanitize_text_field( (string) $first['productLineIds'][0] ) : '';
}

function wk_rh_get_proposal_venue_datetime( $proposal, $location ) {
    $location_key = wk_rh_get_booking_policy_location_key( $location );
    $config = wk_rh_get_peak_minimum_policy_config();
    if ( $location_key === '' || empty( $config[ $location_key ]['timezone'] ) ) {
        return null;
    }

    $start = wk_rh_get_proposal_start_value( $proposal );
    if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $start, $matches ) ) {
        return null;
    }

    try {
        // BMI's date/time components are the wall-clock values displayed in the
        // existing selector. Attach the venue timezone without shifting that value.
        $timezone = new DateTimeZone( $config[ $location_key ]['timezone'] );
        $date = new DateTimeImmutable( $matches[1] . ' ' . $matches[2] . ':00', $timezone );
    } catch ( Exception $exception ) {
        return null;
    }

    return apply_filters( 'wk_rh_booking_proposal_venue_datetime', $date, $proposal, $location_key, $start );
}

function wk_rh_time_to_minutes( $time ) {
    if ( ! preg_match( '/^(\d{2}):(\d{2})$/', (string) $time, $matches ) ) {
        return null;
    }
    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];
    return $hours <= 23 && $minutes <= 59 ? ( $hours * 60 + $minutes ) : null;
}

function wk_rh_get_peak_minimum_policy_result( $race_type, $location, $proposal ) {
    $location_key = wk_rh_get_booking_policy_location_key( $location );
    $config = wk_rh_get_peak_minimum_policy_config();
    $result = [
        'applies'      => false,
        'minimum'      => 0,
        'reason'       => 'not_applicable',
        'locationKey'  => $location_key,
        'selectedTime' => '',
    ];

    if ( wk_rh_normalize_booking_race_type( $race_type ) !== 'exclusive' ) {
        $result['reason'] = 'not_exclusive';
        return $result;
    }
    if ( $location_key === '' || empty( $config[ $location_key ] ) ) {
        $result['reason'] = 'unknown_location';
        return $result;
    }

    $date = wk_rh_get_proposal_venue_datetime( $proposal, $location );
    if ( ! $date instanceof DateTimeImmutable ) {
        $result['reason'] = 'invalid_proposal_start';
        return $result;
    }
    $result['selectedTime'] = $date->format( DateTimeInterface::ATOM );
    $location_config = $config[ $location_key ];

    if ( in_array( (int) $date->format( 'n' ), array_map( 'intval', $location_config['excluded_months'] ?? [] ), true ) ) {
        $result['reason'] = 'excluded_month';
        return $result;
    }
    if ( wk_rh_is_country_public_holiday( $date, $location_config['country'] ?? '' ) ) {
        $result['reason'] = 'public_holiday';
        return $result;
    }

    $weekday = (int) $date->format( 'N' );
    $windows = $location_config['windows'][ $weekday ] ?? [];
    $selected_minutes = (int) $date->format( 'G' ) * 60 + (int) $date->format( 'i' );
    foreach ( $windows as $window ) {
        if ( ! is_array( $window ) || count( $window ) < 2 ) {
            continue;
        }
        $start_minutes = wk_rh_time_to_minutes( $window[0] );
        $end_minutes = wk_rh_time_to_minutes( $window[1] );
        if ( $start_minutes !== null && $end_minutes !== null && $selected_minutes >= $start_minutes && $selected_minutes <= $end_minutes ) {
            $result['applies'] = true;
            $result['minimum'] = max( 1, (int) $location_config['minimum'] );
            $result['reason'] = 'peak_window';
            break;
        }
    }

    return apply_filters( 'wk_rh_peak_minimum_policy_result', $result, $race_type, $location, $proposal );
}

function wk_rh_validate_peak_minimum( $race_type, $location, $proposal, $quantity ) {
    $result = wk_rh_get_peak_minimum_policy_result( $race_type, $location, $proposal );
    $cannot_evaluate = wk_rh_normalize_booking_race_type( $race_type ) === 'exclusive'
        && in_array( $result['reason'], [ 'unknown_location', 'invalid_proposal_start' ], true );
    $result['valid'] = ! $cannot_evaluate
        && ( empty( $result['applies'] ) || (int) $quantity >= (int) $result['minimum'] );
    return $result;
}

function wk_rh_get_peak_policy_error_message( array $policy ) {
    if ( ! empty( $policy['minimum'] ) ) {
        return sprintf( __( 'Dette Exclusive-tidspunkt kræver mindst %d deltagere.', 'racehall-wc-ui' ), (int) $policy['minimum'] );
    }
    return __( 'Bookingtidspunktet kunne ikke valideres mod Exclusive-reglerne. Vælg tidspunkt igen.', 'racehall-wc-ui' );
}

/**
 * Client-facing peak-minimum notices for every language currently offered by
 * Racehall. These explicit fallbacks keep the dynamic JavaScript notice
 * translated even when a gettext catalogue has not yet been generated.
 */
function wk_rh_get_peak_minimum_notice_catalog() {
    return [
        'da' => [
            'requirement' => 'Det valgte tidspunkt kræver mindst {{minimum}} deltagere.',
            'adjusted'    => 'Det valgte tidspunkt kræver mindst {{minimum}} deltagere. Antallet af deltagere er automatisk justeret til {{minimum}}.',
        ],
        'en' => [
            'requirement' => 'The selected time requires at least {{minimum}} participants.',
            'adjusted'    => 'The selected time requires at least {{minimum}} participants. The number of participants has been automatically adjusted to {{minimum}}.',
        ],
        'sv' => [
            'requirement' => 'Den valda tiden kräver minst {{minimum}} deltagare.',
            'adjusted'    => 'Den valda tiden kräver minst {{minimum}} deltagare. Antalet deltagare har automatiskt justerats till {{minimum}}.',
        ],
    ];
}

function wk_rh_get_peak_minimum_notice_strings() {
    $language = function_exists( 'apply_filters' )
        ? (string) apply_filters( 'wpml_current_language', '' )
        : '';
    if ( $language === '' ) {
        if ( function_exists( 'determine_locale' ) ) {
            $language = (string) determine_locale();
        } elseif ( function_exists( 'get_locale' ) ) {
            $language = (string) get_locale();
        }
    }

    $language = strtolower( substr( str_replace( '_', '-', $language ), 0, 2 ) );
    $catalog  = wk_rh_get_peak_minimum_notice_catalog();
    $strings  = isset( $catalog[ $language ] ) ? $catalog[ $language ] : $catalog['da'];

    return function_exists( 'apply_filters' )
        ? apply_filters( 'wk_rh_peak_minimum_notice_strings', $strings, $language )
        : $strings;
}

/**
 * Return an unsigned proposal that the browser may display as requiring a
 * higher local-policy minimum. It must be refetched and signed at the forced
 * quantity before it can be selected or added to the cart.
 */
function wk_rh_mark_booking_proposal_for_minimum( $proposal, $minimum ) {
    if ( ! is_array( $proposal ) ) {
        return [];
    }

    unset( $proposal['_wkRhSelectionToken'], $proposal['_wkRhSelectionIssuedAt'], $proposal['_wkRhRaceType'] );
    $proposal['_wkRhPolicyBlocked'] = true;
    $proposal['_wkRhPolicyMinimum'] = max( 1, (int) $minimum );
    return $proposal;
}

/**
 * Carry an applicable policy minimum with a valid signed proposal so the
 * browser keeps that minimum active after the quantity-correct refetch.
 */
function wk_rh_add_booking_proposal_policy_minimum( $proposal, $minimum ) {
    if ( ! is_array( $proposal ) ) {
        return [];
    }

    $proposal['_wkRhPolicyMinimum'] = max( 1, (int) $minimum );
    return $proposal;
}

function wk_rh_normalize_booking_date_for_compare( $value ) {
    $value = trim( (string) $value );
    foreach ( [ '!Y-m-d', '!d.m.y', '!d.m.Y' ] as $format ) {
        $date = DateTimeImmutable::createFromFormat( $format, $value, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        if ( $date instanceof DateTimeImmutable && ( $errors === false || ( empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) ) ) {
            return $date->format( 'Y-m-d' );
        }
    }
    return '';
}

function wk_rh_booking_selection_matches_proposal( $proposal, $location, $booking_date, $booking_time ) {
    $date = wk_rh_get_proposal_venue_datetime( $proposal, $location );
    if ( ! $date instanceof DateTimeImmutable ) {
        return false;
    }
    return wk_rh_normalize_booking_date_for_compare( $booking_date ) === $date->format( 'Y-m-d' )
        && trim( (string) $booking_time ) === $date->format( 'H:i' );
}

function wk_rh_canonicalize_booking_signature_value( $value ) {
    if ( ! is_array( $value ) ) {
        return $value;
    }
    $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
    if ( ! $is_list ) {
        ksort( $value, SORT_STRING );
    }
    foreach ( $value as $key => $item ) {
        $value[ $key ] = wk_rh_canonicalize_booking_signature_value( $item );
    }
    return $value;
}

function wk_rh_get_clean_booking_proposal( $proposal ) {
    if ( ! is_array( $proposal ) ) {
        return [];
    }
    unset(
        $proposal['_wkRhSelectionToken'],
        $proposal['_wkRhSelectionIssuedAt'],
        $proposal['_wkRhRaceType'],
        $proposal['_wkRhPolicyBlocked'],
        $proposal['_wkRhPolicyMinimum']
    );
    return $proposal;
}

function wk_rh_get_unsigned_booking_proposal( $proposal ) {
    if ( ! is_array( $proposal ) ) {
        return [];
    }
    unset( $proposal['_wkRhSelectionToken'] );
    return $proposal;
}

function wk_rh_get_booking_selection_signature( array $proposal, array $context ) {
    $payload = [
        'proposal'          => wk_rh_get_unsigned_booking_proposal( $proposal ),
        'wcProductId'       => absint( $context['wcProductId'] ?? 0 ),
        'productId'         => (string) ( $context['productId'] ?? '' ),
        'pageId'            => (string) ( $context['pageId'] ?? '' ),
        'quantity'          => max( 0, (int) ( $context['quantity'] ?? 0 ) ),
        'bookingLocation'   => wk_rh_get_booking_policy_location_key( $context['bookingLocation'] ?? '' ),
        'pageProductLimits' => is_array( $context['pageProductLimits'] ?? null ) ? $context['pageProductLimits'] : null,
        'pageProducts'      => is_array( $context['pageProducts'] ?? null ) ? array_values( $context['pageProducts'] ) : [],
        'sessionKey'        => wk_rh_get_booking_selection_session_key(),
    ];
    $json = wp_json_encode( wk_rh_canonicalize_booking_signature_value( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    return hash_hmac( 'sha256', (string) $json, wp_salt( 'auth' ) );
}

function wk_rh_get_booking_selection_session_key() {
    if ( function_exists( 'WC' ) && WC()->session && is_callable( [ WC()->session, 'get_customer_id' ] ) ) {
        return hash( 'sha256', (string) WC()->session->get_customer_id() );
    }
    return '';
}

/**
 * Make the WooCommerce customer identifier durable before signing a proposal.
 *
 * On a guest's first request WooCommerce can expose a generated customer ID in
 * memory without sending its session cookie. A proposal signed with that ID
 * cannot be verified by the following AJAX request because it receives a new
 * generated ID. Explicitly starting the customer session before signing keeps
 * the security binding stable across the timeslot and save requests.
 */
function wk_rh_ensure_booking_selection_session() {
    if ( ! function_exists( 'WC' ) || ! WC()->session || ! is_callable( [ WC()->session, 'get_customer_id' ] ) ) {
        return false;
    }

    $session = WC()->session;
    $has_session = is_callable( [ $session, 'has_session' ] ) ? (bool) $session->has_session() : false;
    $is_logged_in = function_exists( 'is_user_logged_in' ) && is_user_logged_in();

    if ( ! $has_session && ! $is_logged_in ) {
        if ( headers_sent() || ! is_callable( [ $session, 'set_customer_session_cookie' ] ) ) {
            return false;
        }
        $session->set_customer_session_cookie( true );
    }

    return wk_rh_get_booking_selection_session_key() !== '';
}

function wk_rh_sign_booking_proposal( array $proposal, array $context ) {
    $proposal['_wkRhRaceType'] = wk_rh_normalize_booking_race_type( $context['raceType'] ?? '' );
    $proposal['_wkRhSelectionIssuedAt'] = time();
    $proposal['_wkRhSelectionToken'] = wk_rh_get_booking_selection_signature( $proposal, $context );
    return $proposal;
}

function wk_rh_verify_booking_proposal_signature( $proposal, array $context ) {
    if ( ! is_array( $proposal ) || empty( $proposal['_wkRhSelectionToken'] ) || ! is_numeric( $proposal['_wkRhSelectionIssuedAt'] ?? null ) ) {
        return false;
    }
    $issued_at = (int) $proposal['_wkRhSelectionIssuedAt'];
    $max_age = max( 60, (int) apply_filters( 'wk_rh_booking_selection_signature_max_age', 30 * MINUTE_IN_SECONDS ) );
    if ( $issued_at > time() + 60 || $issued_at < time() - $max_age ) {
        return false;
    }
    $provided = (string) $proposal['_wkRhSelectionToken'];
    $expected = wk_rh_get_booking_selection_signature( $proposal, $context );
    return hash_equals( $expected, $provided );
}
