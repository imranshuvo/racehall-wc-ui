<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

function __( $value ) { return $value; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function remove_accents( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
$booking_policy_test_language = '';
function apply_filters( $hook, $value ) {
    global $booking_policy_test_language;
    if ( $hook === 'wpml_current_language' && $booking_policy_test_language !== '' ) {
        return $booking_policy_test_language;
    }
    return $value;
}
function add_action() {}
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_salt() { return 'racehall-policy-test-secret'; }
function wp_unslash( $value ) { return $value; }
class BookingPolicyTestProduct {
    private $name;
    public function __construct( $name ) { $this->name = $name; }
    public function get_name() { return $this->name; }
}
$booking_policy_test_products = [
    101 => new BookingPolicyTestProduct( 'F1 Race 30 min Exclusive' ),
    102 => new BookingPolicyTestProduct( 'F1 Race 60 min' ),
    103 => new BookingPolicyTestProduct( 'F1 Race 30 min Open' ),
    104 => new BookingPolicyTestProduct( 'F1 Race 60 min Exclusive' ),
    105 => new BookingPolicyTestProduct( 'F1 Race 30 min Closed' ),
    106 => new BookingPolicyTestProduct( 'F1 Race 60 min eXcLuSiVe' ),
];
function wc_get_product( $product_id ) {
    global $booking_policy_test_products;
    return $booking_policy_test_products[ (int) $product_id ] ?? null;
}
class BookingPolicyTestSession {
    public $customer_id = 'customer-a';
    public $cookie_set = false;
    public function get_customer_id() { return $this->customer_id; }
    public function has_session() { return $this->cookie_set; }
    public function set_customer_session_cookie( $set ) { $this->cookie_set = (bool) $set; }
}
class BookingPolicyTestWooCommerce {
    public $session;
    public function __construct() { $this->session = new BookingPolicyTestSession(); }
}
$booking_policy_test_wc = new BookingPolicyTestWooCommerce();
function WC() { global $booking_policy_test_wc; return $booking_policy_test_wc; }

require_once dirname( __DIR__ ) . '/includes/booking-policy.php';

function proposal_at( $date_time, $resource_id = 'resource-1' ) {
    return [
        'blocks' => [
            [
                'block' => [
                    'start' => $date_time,
                    'resourceId' => $resource_id,
                ],
            ],
        ],
    ];
}

function assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, sprintf( "FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export( $expected, true ), var_export( $actual, true ) ) );
        exit( 1 );
    }
}

assert_same( true, wk_rh_ensure_booking_selection_session(), 'Fresh guest booking session is established before proposal signing' );
assert_same( true, WC()->session->cookie_set, 'Fresh guest receives a WooCommerce session cookie' );

function assert_policy( $expected_applies, $expected_minimum, $location, $date_time, $message ) {
    $result = wk_rh_get_peak_minimum_policy_result( 'exclusive', $location, proposal_at( $date_time ) );
    assert_same( $expected_applies, $result['applies'], $message . ' applies' );
    assert_same( $expected_minimum, $result['minimum'], $message . ' minimum' );
}

assert_same( 'exclusive', wk_rh_infer_booking_race_type_from_name( 'F1 Race 30 min CLOSED' ), 'Closed is case insensitive' );
assert_same( 'exclusive', wk_rh_infer_booking_race_type_from_name( 'F1 Race 60 min eXcLuSiVe' ), 'Exclusive is case insensitive' );
assert_same( 'open', wk_rh_infer_booking_race_type_from_name( 'F1 Race 30 min Open' ), 'Open remains non-exclusive' );
assert_same( 'exclusive', wk_rh_get_product_booking_race_type( 101, 'F1 Race 30 min' ), 'Woo Exclusive name takes precedence over an unrenamed BMI name' );
assert_same( 'exclusive', wk_rh_get_product_booking_race_type( 102, 'F1 Race 60 min Closed' ), 'BMI name remains a fallback when Woo name has no race type' );
assert_same( 'exclusive', wk_rh_resolve_booking_race_type( 101, 'other' ), 'Stale non-Exclusive session type cannot weaken an Exclusive product' );
assert_same( 'exclusive', wk_rh_resolve_booking_race_type( 103, 'exclusive' ), 'A stored Exclusive classification remains restrictive until a new proposal is selected' );
$exclusive_product_matrix = [
    [ 'wc_id' => 101, 'location' => 'CPH',       'minimum' => 18, 'label' => 'CPH 30-minute Exclusive' ],
    [ 'wc_id' => 104, 'location' => 'Copenhagen','minimum' => 18, 'label' => 'CPH 60-minute Exclusive' ],
    [ 'wc_id' => 105, 'location' => 'Aarhus',    'minimum' => 15, 'label' => 'Aarhus 30-minute Closed compatibility' ],
    [ 'wc_id' => 106, 'location' => 'AAR',       'minimum' => 15, 'label' => 'Aarhus 60-minute case-insensitive Exclusive' ],
    [ 'wc_id' => 101, 'location' => 'Stockholm', 'minimum' => 15, 'label' => 'Stockholm 30-minute Exclusive' ],
    [ 'wc_id' => 104, 'location' => 'STHLM',     'minimum' => 15, 'label' => 'Stockholm 60-minute Exclusive' ],
];
foreach ( $exclusive_product_matrix as $matrix_case ) {
    $matrix_race_type = wk_rh_get_product_booking_race_type( $matrix_case['wc_id'] );
    $matrix_proposal  = proposal_at( $matrix_case['location'] === 'Aarhus' || $matrix_case['location'] === 'AAR'
        ? '2026-09-12T13:00:00'
        : '2026-09-11T15:00:00' );
    $below_minimum = wk_rh_validate_peak_minimum( $matrix_race_type, $matrix_case['location'], $matrix_proposal, 12 );
    assert_same( false, $below_minimum['valid'], $matrix_case['label'] . ' rejects 12 participants during peak time' );
    assert_same( $matrix_case['minimum'], $below_minimum['minimum'], $matrix_case['label'] . ' resolves the configured minimum' );

    $matrix_context = [
        'wcProductId'       => $matrix_case['wc_id'],
        'productId'         => (string) ( 1000 + $matrix_case['wc_id'] ),
        'pageId'            => 'matrix-page',
        'quantity'          => $matrix_case['minimum'],
        'bookingLocation'   => $matrix_case['location'],
        'raceType'          => $matrix_race_type,
        'pageProductLimits' => [ 'minAmount' => 12, 'maxAmount' => 38 ],
        'pageProducts'      => [],
    ];
    $quantity_correct_proposal = wk_rh_sign_booking_proposal(
        wk_rh_add_booking_proposal_policy_minimum( $matrix_proposal, $matrix_case['minimum'] ),
        $matrix_context
    );
    assert_same( true, wk_rh_verify_booking_proposal_signature( $quantity_correct_proposal, $matrix_context ), $matrix_case['label'] . ' signed peak proposal survives session verification' );
}
$stale_type_peak_validation = wk_rh_validate_peak_minimum(
    wk_rh_resolve_booking_race_type( 101, 'other' ),
    'CPH',
    proposal_at( '2026-09-11T16:00:00' ),
    12
);
assert_same( false, $stale_type_peak_validation['valid'], 'Reported CPH regression: 12 people cannot book an Exclusive Friday 16:00 slot' );
assert_same( 18, $stale_type_peak_validation['minimum'], 'Reported CPH regression resolves the minimum to 18' );
$blocked_proposal = wk_rh_mark_booking_proposal_for_minimum( proposal_at( '2026-09-11T16:00:00' ), 18 );
assert_same( true, $blocked_proposal['_wkRhPolicyBlocked'], 'Below-minimum peak proposal remains visible as a forcing action' );
assert_same( 18, $blocked_proposal['_wkRhPolicyMinimum'], 'Visible peak proposal carries its required minimum' );
assert_same( false, isset( $blocked_proposal['_wkRhSelectionToken'] ), 'Below-minimum peak proposal is never signed as bookable' );
$valid_peak_proposal = wk_rh_add_booking_proposal_policy_minimum( proposal_at( '2026-09-11T16:00:00' ), 18 );
assert_same( 18, $valid_peak_proposal['_wkRhPolicyMinimum'], 'Quantity-correct peak proposal keeps the effective minimum active in the browser' );
$notice_catalog = wk_rh_get_peak_minimum_notice_catalog();
assert_same( [ 'da', 'en', 'sv' ], array_keys( $notice_catalog ), 'Peak-minimum notice covers every Racehall site language' );
foreach ( $notice_catalog as $language => $messages ) {
    assert_same( true, strpos( $messages['requirement'], '{{minimum}}' ) !== false, strtoupper( $language ) . ' requirement notice contains the dynamic minimum' );
    assert_same( true, strpos( $messages['adjusted'], '{{minimum}}' ) !== false, strtoupper( $language ) . ' adjusted notice contains the dynamic minimum' );
}
foreach ( [ 'da', 'en', 'sv' ] as $language ) {
    $booking_policy_test_language = $language;
    assert_same( $notice_catalog[ $language ], wk_rh_get_peak_minimum_notice_strings(), strtoupper( $language ) . ' notice is selected from the active WPML language' );
}
$booking_policy_test_language = '';

$hooks_source = file_get_contents( dirname( __DIR__ ) . '/templates/hooks.php' );
$product_template_source = file_get_contents( dirname( __DIR__ ) . '/templates/single-product.php' );
$plugin_source = file_get_contents( dirname( __DIR__ ) . '/wk-racehall-bmi-booking.php' );
$browser_source = file_get_contents( dirname( __DIR__ ) . '/assets/js/single-product.js' );
$product_css_source = file_get_contents( dirname( __DIR__ ) . '/assets/css/single-product.css' );
assert_same( true, substr_count( $hooks_source, 'wk_rh_resolve_booking_race_type(' ) >= 3, 'Proposal save, add-to-cart, and posted fallback use authoritative race-type resolution' );
assert_same( true, substr_count( $plugin_source, 'wk_rh_resolve_booking_race_type(' ) >= 2, 'Checkout and BMI hold creation use authoritative race-type resolution' );
assert_same( true, strpos( $hooks_source, 'wk_rh_mark_booking_proposal_for_minimum' ) !== false, 'Timeslot endpoint exposes an unsigned forcing action for below-minimum peak slots' );
assert_same( true, strpos( $hooks_source, 'wk_rh_add_booking_proposal_policy_minimum' ) !== false, 'Timeslot endpoint marks valid peak proposals with their active minimum' );
assert_same( true, strpos( $hooks_source, 'wk_rh_ensure_booking_selection_session()' ) < strpos( $hooks_source, 'wk_rh_sign_booking_proposal(' ), 'Timeslot endpoint establishes the WooCommerce session before signing proposals' );
assert_same( true, strpos( $browser_source, 'forceTotalQuantityMinimum(policyMinimum' ) !== false, 'Peak-slot selection forces the participant minimum in the browser' );
assert_same( true, strpos( $browser_source, 'fetchAndRenderTimeslots(dateStr, 0, start)' ) !== false, 'Forced peak-slot selection refetches BMI at the new quantity' );
assert_same( true, strpos( $product_template_source, 'id="booking-peak-minimum-notice"' ) !== false, 'Peak-minimum notice is rendered directly in the participant section' );
assert_same( true, strpos( $browser_source, 'showPeakMinimumNotice(policyMinimum' ) !== false, 'Browser displays the localized notice when the policy minimum is applied' );
assert_same( true, strpos( $product_css_source, '.booking-peak-minimum-notice' ) !== false, 'Peak-minimum notice has dedicated client-facing styling' );
assert_same( true, strpos( $plugin_source, "RACEHALL_WC_UI_VERSION', '2.40'" ) !== false, 'Plugin runtime version is 2.40' );
assert_same( true, strpos( $plugin_source, "RACEHALL_WC_UI_BOOKING_ASSET_VERSION', '2.40.1'" ) !== false, 'Booking assets invalidate the earlier 2.40 browser cache' );

assert_policy( true, 18, 'CPH', '2026-09-04T14:00:00.000Z', 'CPH Friday start boundary' );
assert_policy( true, 18, 'Copenhagen', '2026-09-04T20:00:00+02:00', 'CPH Friday inclusive end' );
assert_policy( false, 0, 'København', '2026-09-04T20:01:00', 'CPH Friday after end' );
assert_policy( false, 0, 'CPH', '2026-09-04T13:59:00', 'CPH Friday before start' );
assert_policy( false, 0, 'CPH', '2026-01-09T16:00:00', 'CPH January exclusion' );
assert_policy( false, 0, 'CPH', '2026-08-07T16:00:00', 'CPH August exclusion' );
assert_policy( false, 0, 'CPH', '2026-04-03T16:00:00', 'CPH Good Friday exclusion' );
assert_policy( false, 0, 'CPH', '2026-04-05T16:00:00', 'CPH Easter Sunday exclusion' );
assert_policy( false, 0, 'CPH', '2027-12-24T16:00:00', 'CPH Christmas Eve operational exclusion' );
assert_policy( false, 0, 'CPH', '2027-12-31T16:00:00', 'CPH New Year\'s Eve operational exclusion' );
assert_policy( true, 18, 'CPH', '2026-09-05T09:00:00', 'CPH Saturday start boundary' );
assert_policy( true, 18, 'CPH', '2026-09-05T19:00:00', 'CPH Saturday inclusive end' );
assert_policy( false, 0, 'CPH', '2026-09-05T19:01:00', 'CPH Saturday after end' );
assert_policy( true, 18, 'CPH', '2026-09-06T14:00:00', 'CPH Sunday start boundary' );
assert_policy( true, 18, 'CPH', '2026-09-06T18:00:00', 'CPH Sunday inclusive end' );
assert_policy( false, 0, 'CPH', '2026-09-06T18:01:00', 'CPH Sunday after end' );
$cph_peak_proposal = proposal_at( '2026-09-04T16:00:00' );
assert_same( false, wk_rh_validate_peak_minimum( 'exclusive', 'CPH', $cph_peak_proposal, 17 )['valid'], 'CPH rejects 17 participants at peak' );
assert_same( true, wk_rh_validate_peak_minimum( 'exclusive', 'CPH', $cph_peak_proposal, 18 )['valid'], 'CPH accepts 18 participants at peak' );

assert_policy( true, 15, 'AAR', '2026-09-05T11:00:00', 'Aarhus Saturday start boundary' );
assert_policy( true, 15, 'Aarhus', '2026-09-05T15:00:00', 'Aarhus Saturday inclusive end' );
assert_policy( false, 0, 'Århus', '2026-09-05T15:01:00', 'Aarhus after end' );
assert_policy( false, 0, 'Aarhus', '2026-08-01T13:00:00', 'Aarhus August exclusion' );

assert_policy( true, 15, 'STHLM', '2026-09-04T13:00:00', 'Stockholm Friday start boundary' );
assert_policy( true, 15, 'Stockholm', '2026-09-04T17:00:00', 'Stockholm Friday inclusive end' );
assert_policy( false, 0, 'Stockholm', '2026-09-04T17:01:00', 'Stockholm Friday after end' );
assert_policy( true, 15, 'Stockholm', '2026-09-05T11:00:00', 'Stockholm Saturday start boundary' );
assert_policy( true, 15, 'Stockholm', '2026-09-05T18:00:00', 'Stockholm Saturday inclusive end' );
assert_policy( false, 0, 'Stockholm', '2026-09-05T18:01:00', 'Stockholm Saturday after end' );
assert_policy( true, 15, 'Stockholm', '2026-01-02T14:00:00', 'Stockholm has no January exclusion' );
assert_policy( false, 0, 'Stockholm', '2026-06-06T14:00:00', 'Stockholm National Day exclusion' );
assert_policy( false, 0, 'Stockholm', '2026-06-19T14:00:00', 'Stockholm Midsummer Eve operational exclusion' );
assert_policy( false, 0, 'Stockholm', '2026-10-31T14:00:00', 'Stockholm All Saints Day exclusion' );
assert_policy( false, 0, 'Stockholm', '2027-12-24T14:00:00', 'Stockholm Christmas Eve operational exclusion' );
assert_policy( false, 0, 'Stockholm', '2027-12-31T14:00:00', 'Stockholm New Year\'s Eve operational exclusion' );

$dk_holidays_2027 = wk_rh_get_country_public_holiday_dates( 'DK', 2027, new DateTimeZone( 'Europe/Copenhagen' ) );
assert_same( true, in_array( '2027-12-24', $dk_holidays_2027, true ), 'DK holiday dates contain Christmas Eve' );
assert_same( true, in_array( '2027-12-31', $dk_holidays_2027, true ), 'DK holiday dates contain New Year\'s Eve' );
$se_holidays_2026 = wk_rh_get_country_public_holiday_dates( 'SE', 2026, new DateTimeZone( 'Europe/Stockholm' ) );
assert_same( true, in_array( '2026-06-19', $se_holidays_2026, true ), 'SE holiday dates contain Midsummer Eve' );
assert_same( true, in_array( '2026-12-24', $se_holidays_2026, true ), 'SE holiday dates contain Christmas Eve' );
assert_same( true, in_array( '2026-12-31', $se_holidays_2026, true ), 'SE holiday dates contain New Year\'s Eve' );

$non_exclusive = wk_rh_get_peak_minimum_policy_result( 'open', 'CPH', proposal_at( '2026-09-04T16:00:00' ) );
assert_same( false, $non_exclusive['applies'], 'Open race is not subject to Exclusive minimum' );
$invalid_exclusive = wk_rh_validate_peak_minimum( 'exclusive', 'CPH', [ 'blocks' => [] ], 18 );
assert_same( false, $invalid_exclusive['valid'], 'Exclusive proposal with unreadable time fails closed' );

$known_easter_dates = [
    2026 => '2026-04-05',
    2027 => '2027-03-28',
    2028 => '2028-04-16',
    2029 => '2029-04-01',
    2030 => '2030-04-21',
    2031 => '2031-04-13',
];
foreach ( $known_easter_dates as $year => $expected_date ) {
    assert_same( $expected_date, wk_rh_get_easter_sunday_date( $year, new DateTimeZone( 'Europe/Copenhagen' ) )->format( 'Y-m-d' ), 'Gregorian Easter calculation for ' . $year );
}

$context = [
    'wcProductId' => 101,
    'productId' => '202',
    'pageId' => '303',
    'quantity' => 18,
    'bookingLocation' => 'CPH',
    'raceType' => 'exclusive',
    'pageProductLimits' => [ 'minAmount' => 1, 'maxAmount' => 24 ],
    'pageProducts' => [ [ 'id' => 202, 'name' => 'Exclusive' ] ],
];
$signed = wk_rh_sign_booking_proposal( proposal_at( '2026-09-04T16:00:00' ), $context );
assert_same( true, wk_rh_verify_booking_proposal_signature( $signed, $context ), 'Untouched proposal signature verifies' );
$signed_peak = wk_rh_sign_booking_proposal(
    wk_rh_add_booking_proposal_policy_minimum( proposal_at( '2026-09-04T16:00:00' ), 18 ),
    $context
);
assert_same( 18, $signed_peak['_wkRhPolicyMinimum'], 'Signed peak proposal exposes the active policy minimum' );
assert_same( true, wk_rh_verify_booking_proposal_signature( $signed_peak, $context ), 'Signed peak proposal survives the exact timeslot-to-session round trip' );
WC()->session->customer_id = 'customer-b';
assert_same( false, wk_rh_verify_booking_proposal_signature( $signed, $context ), 'A second customer session cannot replay the proposal signature' );
WC()->session->customer_id = 'customer-a';
$tampered = $signed;
$tampered['blocks'][0]['block']['start'] = '2026-09-04T20:01:00';
assert_same( false, wk_rh_verify_booking_proposal_signature( $tampered, $context ), 'Changed proposal time invalidates signature' );
$expired = $signed;
$expired['_wkRhSelectionIssuedAt'] = time() - ( 31 * MINUTE_IN_SECONDS );
$expired['_wkRhSelectionToken'] = wk_rh_get_booking_selection_signature( $expired, $context );
assert_same( false, wk_rh_verify_booking_proposal_signature( $expired, $context ), 'Expired proposal signature is rejected' );
$changed_quantity = $context;
$changed_quantity['quantity'] = 17;
assert_same( false, wk_rh_verify_booking_proposal_signature( $signed, $changed_quantity ), 'Changed quantity invalidates signature' );
$changed_page = $context;
$changed_page['pageId'] = 'different-page';
assert_same( false, wk_rh_verify_booking_proposal_signature( $signed, $changed_page ), 'Changed BMI page invalidates signature' );
$changed_location = $context;
$changed_location['bookingLocation'] = 'Stockholm';
assert_same( false, wk_rh_verify_booking_proposal_signature( $signed, $changed_location ), 'Changed venue invalidates signature' );
$changed_race_type = $signed;
$changed_race_type['_wkRhRaceType'] = 'open';
assert_same( false, wk_rh_verify_booking_proposal_signature( $changed_race_type, $context ), 'Changed race type invalidates signature' );
$changed_resource = $signed;
$changed_resource['blocks'][0]['block']['resourceId'] = 'resource-2';
assert_same( false, wk_rh_verify_booking_proposal_signature( $changed_resource, $context ), 'Changed resource invalidates signature' );
$signed['_wkRhPolicyBlocked'] = true;
$signed['_wkRhPolicyMinimum'] = 18;
$clean = wk_rh_get_clean_booking_proposal( $signed );
assert_same( false, isset( $clean['_wkRhSelectionToken'] ) || isset( $clean['_wkRhSelectionIssuedAt'] ) || isset( $clean['_wkRhRaceType'] ) || isset( $clean['_wkRhPolicyBlocked'] ) || isset( $clean['_wkRhPolicyMinimum'] ), 'Private signature and policy fields are removed before BMI booking' );

assert_same( true, wk_rh_booking_selection_matches_proposal( proposal_at( '2026-09-04T16:00:00' ), 'CPH', '04.09.26', '16:00' ), 'Displayed booking date/time matches proposal' );
assert_same( false, wk_rh_booking_selection_matches_proposal( proposal_at( '2026-09-04T16:00:00' ), 'CPH', '04.09.26', '16:01' ), 'Changed display time is rejected' );

fwrite( STDOUT, "booking-policy-test: OK\n" );
