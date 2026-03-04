<?php
defined('ABSPATH') || exit;
get_header();
global $product;

if (!$product || !is_a($product, 'WC_Product')) {
    $product = wc_get_product(get_the_ID());
}

// --- Example Usage for Server-side Rendering (optional, not used by AJAX) ---
$bm_id = function_exists( 'get_field' ) ? get_field( 'bmileisure_id', $product->get_id() ) : get_post_meta( $product->get_id(), 'bmileisure_id', true );
$lokation = function_exists( 'get_field' ) ? get_field( 'lokation', $product->get_id() ) : get_post_meta( $product->get_id(), 'lokation', true );
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
$availability = [];
$timeslots = [];




$current_rh_product = null;
if ( ! empty( $racehall_products ) && is_array( $racehall_products ) ) {
    foreach ( $racehall_products as $rh_p ) {
        if ( isset( $rh_p['id'] ) && (string) $rh_p['id'] === (string) $bm_id ) {
            $current_rh_product = $rh_p;
            break;
        }
    }
}





?>

<script>
window.RH_AJAX_URL = "<?php echo admin_url('admin-ajax.php'); ?>";
window.RH_PRODUCT_ID = <?php echo $bm_id?>;
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
    adultKartLabel: <?php echo wp_json_encode( __( 'voksen karts', 'racehall-wc-ui' ) ); ?>,
    childKartLabel: <?php echo wp_json_encode( __( 'børne kart', 'racehall-wc-ui' ) ); ?>,
    monthNames: <?php echo wp_json_encode( [
        __( 'Januar', 'racehall-wc-ui' ),
        __( 'Februar', 'racehall-wc-ui' ),
        __( 'Mars', 'racehall-wc-ui' ),
        __( 'April', 'racehall-wc-ui' ),
        __( 'Mai', 'racehall-wc-ui' ),
        __( 'Juni', 'racehall-wc-ui' ),
        __( 'Juli', 'racehall-wc-ui' ),
        __( 'August', 'racehall-wc-ui' ),
        __( 'September', 'racehall-wc-ui' ),
        __( 'Oktober', 'racehall-wc-ui' ),
        __( 'November', 'racehall-wc-ui' ),
        __( 'Desember', 'racehall-wc-ui' ),
    ] ); ?>,
    summaryDateTitle: <?php echo wp_json_encode( __( 'Dato', 'racehall-wc-ui' ) ); ?>,
    summaryTimeTitle: <?php echo wp_json_encode( __( 'Tidspunkt', 'racehall-wc-ui' ) ); ?>
};
</script>

<!-- Main Content -->

<section class="single-product-page">

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

                <div class="product-description">
                    <p><?php esc_html_e( 'F1 Race er et løb af en halv times varighed, hvor banen er reserveret kun til jer. De første 10 minutter udgør en kvalifikationsrunde, hvor den hurtigste omgangstid tæller. Vinderen er den deltager, der kører flest omgange og krydser målstregen først efter cirka 20 minutter.', 'racehall-wc-ui' ); ?></p>
                </div>

                <div class="product-details">
                    <div class="details-left">
                        <?php
                        // Read ACF (fall back to postmeta). Adjust field keys if needed.
                        if ( function_exists( 'get_field' ) ) {
                            $lokation  = get_field( 'lokation', $product->get_id() );
                            $event_tid = get_field( 'event_tid', $product->get_id() );
                            $banetid   = get_field( 'banetid', $product->get_id() );
                        } else {
                            $lokation  = get_post_meta( $product->get_id(), 'lokation', true );
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
                        <div class="calendar">
                            <div class="month-nav">
                                <button id="prevMonthBtn">&lt;</button>
                                <span id="monthYear"></span>
                                <button id="nextMonthBtn">&gt;</button>
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
                                    <strong><?php esc_html_e( 'Voksne', 'racehall-wc-ui' ); ?></strong>
                                    <small><?php esc_html_e( '18 år eller over.', 'racehall-wc-ui' ); ?></small>
                                </div>
                                <div class="counter-controls">
                                    <button onclick="updateCount('adult-1', -1)">−</button>
                                    <span id="adult-1">1</span>
                                    <button onclick="updateCount('adult-1', 1)">+</button>
                                </div>
                            </div>

                            <div class="counter-row">
                                <div class="counter-label">
                                    <strong><?php esc_html_e( 'Børn', 'racehall-wc-ui' ); ?></strong>
                                    <small><?php esc_html_e( '5–17 år.', 'racehall-wc-ui' ); ?></small>
                                </div>
                                <div class="counter-controls">
                                    <button onclick="updateCount('child-1', -1)">−</button>
                                    <span id="child-1">0</span>
                                    <button onclick="updateCount('child-1', 1)">+</button>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- EMPTY ACCORDIONS -->
                <div class="accordion-item">
                    <div class="accordion-header">
                        <span><?php esc_html_e( 'Vælg kort', 'racehall-wc-ui' ); ?></span>
                        <div class="chevron">
                            <svg width="16" height="9" viewBox="0 0 16 9" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M0.353516 0.353516L7.85352 7.85352L15.3535 0.353516" stroke="#D9D9D9"/>
                            </svg>

                        </div>
                    </div>
                    <div class="accordion-content">
                        <span class="summary-label"><?php esc_html_e( 'Vælg kort kommer snart.', 'racehall-wc-ui' ); ?></span>
                    </div>
                </div>

                <div class="accordion-item">
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
                            <div class="time-slots">

                            </div>
                        </div>
                    </div>
                </div>
                <div class="accordion-item">
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
                                    <div class="summary-details">
                                        <span id="summary-people" class="summary-label"><?php echo wp_kses_post( __( '1 voksen<br>0 børn', 'racehall-wc-ui' ) ); ?></span>
                                        <div class="summary-prices">
                                            <span class="price">
                                            <!-- product price -->
                                                <span id="summary-unit-price"><?php echo wc_price( $product->get_price() ); ?></span></span>

                                            <span id="summary-children-price" class="price"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Karts', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span id="summary-karts" class="summary-label"><?php echo wp_kses_post( __( '1 voksen kart<br>0 børne kart', 'racehall-wc-ui' ) ); ?></span>
                                </div>
                            </div>

                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Dato', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span id="summary-date" class="summary-label"></span>
                                </div>
                            </div>

                            <div class="summary-section">
                                <h4><?php esc_html_e( 'Tidspunkt', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item">
                                    <span id="summary-time" class="summary-label"></span>
                                </div>
                            </div>



                            <div class="summary-section addon-section" id="addonSection">
                                <h4><?php esc_html_e( 'Add ons', 'racehall-wc-ui' ); ?></h4>
                                <div class="summary-item" id="addonSummaryItems">
                                    <span class="summary-label">—</span>
                                </div>
                            </div>

                            <div class="summary-total">
                                <div class="total-item">
                                    <span class="total-label"><?php esc_html_e( 'Total', 'racehall-wc-ui' ); ?></span>
                                    <span class="total-price" id="summary-total-price">
                                        <?php echo wc_price( $product->get_price() ); ?>
                                    </span>
                                </div>
                            </div>
                                     <form class="cart" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="booking_date" id="booking_date">
                                        <input type="hidden" name="booking_time" id="booking_time">
                                        <input type="hidden" name="booking_adults" id="booking_adults" value="1">
                                        <input type="hidden" name="booking_children" id="booking_children" value="0">
                                        <input type="hidden" name="booking_quantity" id="booking_quantity" value="1">
                                        <input type="hidden" name="booking_location" id="booking_location"
                                            value="<?php echo esc_attr( $lokation ); ?>">
                                        <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" />
                                        <input type="hidden" name="quantity" id="cart_quantity" value="1" />
                                        <!-- Optional booking meta (populated by JS) -->

                                        <button type="submit" class="single_add_to_cart_button button alt add-to-cart-button"><?php esc_html_e( 'Tilføj til kurv', 'racehall-wc-ui' ); ?></button>
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



