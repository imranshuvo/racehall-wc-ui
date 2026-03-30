<?php
defined('ABSPATH') || exit;
get_header();
global $product;

if (!$product || !is_a($product, 'WC_Product')) {
    $product = wc_get_product(get_the_ID());
}

// --- Example Usage for Server-side Rendering (optional, not used by AJAX) ---
$bm_id = function_exists( 'wk_rh_get_product_bmileisure_id' )
    ? wk_rh_get_product_bmileisure_id( $product->get_id() )
    : ( function_exists( 'get_field' ) ? get_field( 'bmileisure_id', $product->get_id() ) : get_post_meta( $product->get_id(), 'bmileisure_id', true ) );
$lokation = function_exists( 'wk_rh_get_product_booking_location' )
    ? wk_rh_get_product_booking_location( $product->get_id() )
    : ( function_exists( 'get_field' ) ? get_field( 'lokation', $product->get_id() ) : get_post_meta( $product->get_id(), 'lokation', true ) );
$availability = [];
$timeslots = [];

/*
 * Initial upstream /products preflight intentionally disabled for staging review.
 * We now trust the stored bmileisure_id here and let the AJAX availability/page
 * requests validate the product upstream.
 */
/*
$racehall_token = function_exists( 'wk_rh_get_token' ) ? wk_rh_get_token( $lokation ) : racehall_get_token( $lokation );
$racehall_products = [];
if ( $racehall_token ) {
    if ( function_exists( 'wk_rh_get_products' ) ) {
        $racehall_products = wk_rh_get_products( $racehall_token, $lokation );
    } else {
        $racehall_products = racehall_get_products( $racehall_token );
    }
}
$first_rh_product = !empty($racehall_products) ? $racehall_products[0] : null;

$current_rh_product = null;
if ( ! empty( $racehall_products ) && is_array( $racehall_products ) ) {
    foreach ( $racehall_products as $rh_p ) {
        if ( isset( $rh_p['id'] ) && (string) $rh_p['id'] === (string) $bm_id ) {
            $current_rh_product = $rh_p;
            break;
        }
    }
}
*/

$booking_product_available = $bm_id !== '';
$booking_unavailable_message = __( 'Dette bookingprodukt findes ikke i det aktive BMI-miljø. Kontrollér bmileisure_id eller skift miljø.', 'racehall-wc-ui' );





?>

<script>
window.RH_AJAX_URL = "<?php echo admin_url('admin-ajax.php'); ?>";
window.RH_PRODUCT_ID = <?php echo wp_json_encode( $booking_product_available ? (string) $bm_id : '' ); ?>;
window.RH_PRODUCT_AVAILABLE = <?php echo wp_json_encode( $booking_product_available ); ?>;
window.RH_PRODUCT_UNAVAILABLE_MESSAGE = <?php echo wp_json_encode( $booking_unavailable_message ); ?>;
window.RH_BOOKING_LOCATION = "<?php echo esc_js( $lokation ); ?>";
window.RH_PRICE_CONFIG = {
    unitPrice: <?php echo wp_json_encode( (float) $product->get_price() ); ?>,
    currencySymbol: <?php echo wp_json_encode( get_woocommerce_currency_symbol() ); ?>,
    currencyPos: <?php echo wp_json_encode( get_option( 'woocommerce_currency_pos', 'right' ) ); ?>,
    decimals: <?php echo wp_json_encode( (int) wc_get_price_decimals() ); ?>,
    decimalSeparator: <?php echo wp_json_encode( wc_get_price_decimal_separator() ); ?>,
    thousandSeparator: <?php echo wp_json_encode( wc_get_price_thousand_separator() ); ?>
};
window.RH_I18N = {
    adultsLabel: <?php echo wp_json_encode( __( 'voksne', 'racehall-wc-ui' ) ); ?>,
    childrenLabel: <?php echo wp_json_encode( __( 'børn', 'racehall-wc-ui' ) ); ?>,
    twinLabel: <?php echo wp_json_encode( __( 'twin kart', 'racehall-wc-ui' ) ); ?>,
    adultKartLabel: <?php echo wp_json_encode( __( 'voksen karts', 'racehall-wc-ui' ) ); ?>,
    childKartLabel: <?php echo wp_json_encode( __( 'børne kart', 'racehall-wc-ui' ) ); ?>,
    twinKartLabel: <?php echo wp_json_encode( __( 'twin kart', 'racehall-wc-ui' ) ); ?>,
    monthNames: <?php echo wp_json_encode( [
        __( 'januar', 'racehall-wc-ui' ),
        __( 'februar', 'racehall-wc-ui' ),
        __( 'marts', 'racehall-wc-ui' ),
        __( 'april', 'racehall-wc-ui' ),
        __( 'maj', 'racehall-wc-ui' ),
        __( 'juni', 'racehall-wc-ui' ),
        __( 'juli', 'racehall-wc-ui' ),
        __( 'august', 'racehall-wc-ui' ),
        __( 'september', 'racehall-wc-ui' ),
        __( 'oktober', 'racehall-wc-ui' ),
        __( 'november', 'racehall-wc-ui' ),
        __( 'december', 'racehall-wc-ui' ),
    ] ); ?>,
    summaryDateTitle: <?php echo wp_json_encode( __( 'Dato', 'racehall-wc-ui' ) ); ?>,
    summaryTimeTitle: <?php echo wp_json_encode( __( 'Tidspunkt', 'racehall-wc-ui' ) ); ?>
};
</script>

<!-- Main Content -->

<section class="single-product-page">

    <?php echo wk_rh_get_product_page_booking_switch_html(); ?>

    <div class="main-content">
        <!-- Product Section -->
        <section class="product-section">
            <div class="product-image-and-text">
                <?php
                $image_id  = $product->get_image_id();
                $image_url = wp_get_attachment_url( $image_id );
                ?>
               <div class="product-image-div corner-fl-1">
                 <img class="product-image" src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $product->get_title() ); ?>" />
               </div>
                <!-- Text Content Section -->
                <section class="text-content">
                     <?php echo apply_filters( 'the_content', $product->get_description() ); ?>
                    <!-- <div class="package-info">
                        <h2 class="section-title">Pakken – Gokart F1 Race</h2>
                        <p class="package-description">
                            <ul>
                                <li> Prisen er for 30 minutters pr. person.</li>
                                <li> Kan bookes fra 12 – 34 personer.</li>
                                <li>Mødetid: 30 minutter før startskuddet til løbet lyder.</li>

                            </ul>
                            <span class="highlight">Eksklusiv obligatorisk startpakke, der indeholder handsker, hjelmhue
                                og app (driver's license).</span>
                        </p>
                    </div> -->

                    <!-- <div class="booking-section">
                        <h3 class="booking-title">Booking <span class="booking-period">Udenfor ferieperiode</span></h3>
                        <p class="booking-subtitle">Familierace kan bookes i følgende tidsrum:</p>

                        <div class="booking-schedule">
                            <div class="schedule-days">
                                <div class="day">Tirsdag</div>
                                <div class="day">Torsdag</div>
                                <div class="day">Lørdag</div>
                                <div class="day">Søndag</div>
                            </div>
                            <div class="schedule-times">
                                <div class="time">17.30-18.00</div>
                                <div class="time">17.30-18.00</div>
                                <div class="time">11.30-12.00, 17.30-18.00</div>
                                <div class="time">10.30-11.00, 13.30-14.00, 17.30-18.00</div>
                            </div>
                        </div>
                    </div> -->

                    <!-- <div class="booking-section">
                        <h3 class="booking-title">Booking i ferieperiode</h3>
                        <p class="booking-subtitle">(uge 1, 7-8, påske, 27-32, 42 og 52) kan familierace bookes i
                            følgende tidsrum:</p>

                        <div class="booking-schedule">
                            <div class="schedule-days">
                                <div class="day">Tirsdag - torsdag</div>
                                <div class="day">Fradag & Lørdag</div>
                                <div class="day">Søndag</div>
                            </div>
                            <div class="schedule-times">
                                <div class="time">10.30-11.00, 13.30-14.00, 17.30-18.00</div>
                                <div class="time">10.30-11.00, 13.30-14.00, 17.30-18.00</div>
                                <div class="time">10.30-11.00, 13.30-14.00, 17.30-18.00</div>
                            </div>
                        </div>
                    </div> -->

                    <!-- <div class="disclaimer">
                        <p>Der tages forbehold for udsolgte pladser.</p>
                    </div> -->



                </section>
            </div>

            <div class="product-info">
                <h1 class="product-title"><?php echo $product->get_title(); ?></h1>

                <?php if ( $product->get_price_html() ) : ?>
                <div class="product-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
                <?php endif; ?>

                <?php $product_short_description = trim( (string) $product->get_short_description() ); ?>
                <div class="product-description">
                    <?php if ( '' !== $product_short_description ) : ?>
                        <?php echo wp_kses_post( apply_filters( 'woocommerce_short_description', $product_short_description ) ); ?>
                    <?php endif; ?>
                </div>

                <div class="product-details">
                    <div class="details-left">
                        <?php
                        // Read ACF (fall back to postmeta). Adjust field keys if needed.
                        if ( function_exists( 'get_field' ) ) {
                            $lokation  = function_exists( 'wk_rh_get_product_booking_location' ) ? wk_rh_get_product_booking_location( $product->get_id() ) : get_field( 'lokation', $product->get_id() );
                            $event_tid = get_field( 'event_tid', $product->get_id() );
                            $banetid   = get_field( 'banetid', $product->get_id() );
                        } else {
                            $lokation  = function_exists( 'wk_rh_get_product_booking_location' ) ? wk_rh_get_product_booking_location( $product->get_id() ) : get_post_meta( $product->get_id(), 'lokation', true );
                            $event_tid = get_post_meta( $product->get_id(), 'event tid', true );
                            $banetid   = get_post_meta( $product->get_id(), 'banetid', true );
                        }
                        ?>
                        <?php if ( $lokation ) : ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php esc_html_e( 'Lokation:', 'racehall-wc-ui' ); ?></span>
                            <span class="detail-value"><?php echo esc_html( $lokation ); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ( $event_tid ) : ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php esc_html_e( 'Event tid:', 'racehall-wc-ui' ); ?></span>
                            <span class="detail-value"><?php echo esc_html( $event_tid ); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ( $banetid ) : ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php esc_html_e( 'Banetid:', 'racehall-wc-ui' ); ?></span>
                            <span class="detail-value"><?php echo esc_html( $banetid ); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="details-right">
                        <?php
                        if ( function_exists( 'get_field' ) ) {
                            $alder = get_field( 'alder', $product->get_id() );
                            $hojde = get_field( 'højde', $product->get_id() );
                        } else {
                            $alder = get_post_meta( $product->get_id(), 'Alder', true );
                            $hojde = get_post_meta( $product->get_id(), 'Højde', true );
                        }
                        ?>
                        <?php if ( $alder ) : ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php esc_html_e( 'Alder:', 'racehall-wc-ui' ); ?></span>
                            <span class="detail-value"><?php echo esc_html( $alder ); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ( $hojde ) : ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php esc_html_e( 'Højde:', 'racehall-wc-ui' ); ?></span>
                            <span class="detail-value"><?php echo esc_html( $hojde ); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>


        <!-- Booking Overview Sidebar -->
        <aside class="booking-overview">
            <!-- accordion -->
            <div class="accordion">

                <!-- DATE -->
                <div class="accordion-item active">
                    <div class="accordion-header">
                        <span><?php esc_html_e( 'Dato', 'racehall-wc-ui' ); ?></span>
                        <div class="chevron">
                            <svg width="16" height="9" viewBox="0 0 16 9" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M0.353516 0.353516L7.85352 7.85352L15.3535 0.353516" stroke="#D9D9D9"/>
                            </svg>

                        </div>
                    </div>

                    <div class="accordion-content">
                        <?php if ( ! $booking_product_available ) : ?>
                            <p class="booking-validation-message" style="display:block;" aria-live="polite"><?php echo esc_html( $booking_unavailable_message ); ?></p>
                        <?php endif; ?>
                        <div class="calendar" id="booking-calendar-section" tabindex="-1" role="group" aria-label="<?php esc_attr_e( 'Vælg dato', 'racehall-wc-ui' ); ?>">
                            <div class="month-nav">
                                <button id="prevMonthBtn" aria-label="Previous month">
                                    <svg width="16" height="9" viewBox="0 0 16 9" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path d="M10.5 0.75L4.5 4.5L10.5 8.25" stroke="currentColor"/>
                                    </svg>
                                </button>
                                <span id="monthYear"></span>
                                <button id="nextMonthBtn" aria-label="Next month">
                                    <svg width="16" height="9" viewBox="0 0 16 9" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path d="M5.5 0.75L11.5 4.5L5.5 8.25" stroke="currentColor"/>
                                    </svg>
                                </button>
                            </div>

                            <div class="weekdays">
                                <span><?php esc_html_e( 'Ma', 'racehall-wc-ui' ); ?></span>
                                <span><?php esc_html_e( 'Ti', 'racehall-wc-ui' ); ?></span>
                                <span><?php esc_html_e( 'On', 'racehall-wc-ui' ); ?></span>
                                <span><?php esc_html_e( 'To', 'racehall-wc-ui' ); ?></span>
                                <span><?php esc_html_e( 'Fe', 'racehall-wc-ui' ); ?></span>
                                <span><?php esc_html_e( 'Lø', 'racehall-wc-ui' ); ?></span>
                                <span><?php esc_html_e( 'Sø', 'racehall-wc-ui' ); ?></span>

                            </div>

                            <div class="days" id="calendarDays"></div>
                            <p class="booking-validation-message" id="booking-date-error" aria-live="polite" hidden></p>
                        </div>

                    </div>
                </div>

                <!-- PEOPLE -->
                <div class="accordion-item active">
                    <div class="accordion-header">
                        <span><?php esc_html_e( 'Antal personer', 'racehall-wc-ui' ); ?></span>
                        <div class="chevron">
                            <svg width="16" height="9" viewBox="0 0 16 9" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M0.353516 0.353516L7.85352 7.85352L15.3535 0.353516" stroke="#D9D9D9"/>
                            </svg>

                        </div>
                    </div>

                    <div class="accordion-content">
                        <div class="counter">
                            <div class="counter-row">
                                <div class="counter-label">
                                    <strong><?php esc_html_e( 'Voksen kart', 'racehall-wc-ui' ); ?></strong>
                                    <small><?php esc_html_e( 'Fra 150 cm. højde', 'racehall-wc-ui' ); ?></small>
                                </div>
                                <div class="counter-controls">
                                    <button type="button" onclick="updateCount('adult-1', -1)">−</button>
                                    <input type="number" id="adult-1" value="0" min="0" step="1" inputmode="numeric" />
                                    <button type="button" onclick="updateCount('adult-1', 1)">+</button>
                                </div>
                            </div>

                            <div class="counter-row">
                                <div class="counter-label">
                                    <strong><?php esc_html_e( 'Børnekart', 'racehall-wc-ui' ); ?></strong>
                                    <small><?php esc_html_e( 'Fra 120-149 cm. højde', 'racehall-wc-ui' ); ?></small>
                                </div>
                                <div class="counter-controls">
                                    <button type="button" onclick="updateCount('child-1', -1)">−</button>
                                    <input type="number" id="child-1" value="0" min="0" step="1" inputmode="numeric" />
                                    <button type="button" onclick="updateCount('child-1', 1)">+</button>
                                </div>
                            </div>

                            <div class="counter-row">
                                <div class="counter-label">
                                    <strong><?php esc_html_e( 'Twin kart', 'racehall-wc-ui' ); ?></strong>
                                    <small><?php esc_html_e( 'Passager mindst 90 cm. og chauffør mindst 18 år.', 'racehall-wc-ui' ); ?></small>
                                </div>
                                <div class="counter-controls">
                                    <button type="button" onclick="updateCount('twin-1', -1)">−</button>
                                    <input type="number" id="twin-1" value="0" min="0" step="1" inputmode="numeric" />
                                    <button type="button" onclick="updateCount('twin-1', 1)">+</button>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="accordion-item active">
                    <div class="accordion-header">
                        <span><?php esc_html_e( 'Tidspunkt', 'racehall-wc-ui' ); ?></span>
                        <div class="chevron">
                            <svg width="16" height="9" viewBox="0 0 16 9" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M0.353516 0.353516L7.85352 7.85352L15.3535 0.353516" stroke="#D9D9D9"/>
                            </svg>

                        </div>
                    </div>
                    <div class="accordion-content">
                        <div class="accordion-content">
                            <div class="time-slots" id="booking-time-slots-section" tabindex="-1" role="group" aria-label="<?php esc_attr_e( 'Vælg tidspunkt', 'racehall-wc-ui' ); ?>">

                            </div>
                            <p class="booking-validation-message" id="booking-time-error" aria-live="polite" hidden></p>
                        </div>
                    </div>
                </div>
                <div class="accordion-item active always-open">
                    <div class="accordion-header">
                        <span><?php esc_html_e( 'Bane', 'racehall-wc-ui' ); ?></span>
                        <div class="chevron">
                            <svg width="16" height="9" viewBox="0 0 16 9" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M0.353516 0.353516L7.85352 7.85352L15.3535 0.353516" stroke="#D9D9D9"/>
                            </svg>

                        </div>
                    </div>
                    <div class="accordion-content">
                        <div class="booking-s">
                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Bane', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span class="summary-label"><?php echo $lokation; ?></span>
                                </div>
                            </div>

                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Produkt', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span class="summary-label"><?php echo $product->get_title(); ?></span>
                                </div>
                            </div>

                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Antal', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span id="summary-people" class="summary-label"><?php echo wp_kses_post( __( '0 voksne<br>0 børn<br>0 twin kart', 'racehall-wc-ui' ) ); ?></span>
                                </div>
                            </div>

                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Dato', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span id="summary-date" class="summary-label"></span>
                                </div>
                            </div>

                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Køretid (mødetid 30 min. før)', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span id="summary-time" class="summary-label"></span>
                                </div>
                            </div>
                            <div class="summary-total">
                                <div class="total-item">
                                    <span class="total-label"><?php esc_html_e( 'Total', 'racehall-wc-ui' ); ?></span>
                                    <span class="total-price" id="summary-total-price">
                                        <?php echo wc_price( 0 ); ?>
                                    </span>
                                </div>
                            </div>
                                     <form class="cart" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="booking_date" id="booking_date">
                                        <input type="hidden" name="booking_time" id="booking_time">
                                                     <input type="hidden" name="booking_proposal" id="booking_proposal">
                                                     <input type="hidden" name="booking_page_id" id="booking_page_id">
                                                     <input type="hidden" name="booking_resource_id" id="booking_resource_id">
                                                     <input type="hidden" name="booking_product_id" id="booking_product_id" value="<?php echo esc_attr( $bm_id ); ?>">
                                                    <input type="hidden" name="booking_page_product_limits" id="booking_page_product_limits">
                                                    <input type="hidden" name="booking_page_products" id="booking_page_products">
                                        <input type="hidden" name="booking_adults" id="booking_adults" value="0">
                                        <input type="hidden" name="booking_children" id="booking_children" value="0">
                                                    <input type="hidden" name="booking_twin" id="booking_twin" value="0">
                                        <input type="hidden" name="booking_quantity" id="booking_quantity" value="0">
                                        <input type="hidden" name="booking_location" id="booking_location"
                                            value="<?php echo esc_attr( $lokation ); ?>">
                                        <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" />
                                        <input type="hidden" name="quantity" id="cart_quantity" value="0" />
                                        <!-- Optional booking meta (populated by JS) -->

                                        <button type="submit" class="single_add_to_cart_button button alt add-to-cart-button" aria-disabled="<?php echo $booking_product_available ? 'false' : 'true'; ?>"<?php disabled( ! $booking_product_available ); ?>><?php esc_html_e( 'Tilføj til kurv', 'racehall-wc-ui' ); ?></button>
                                    </form>
                            <!-- <button class="add-to-cart-button">Tilføj til kurv</button> -->
                        </div>
                    </div>
                </div>
            </div>


        </aside>
    </div>
    </section>

<?php
get_footer();



