<?php defined('ABSPATH') || exit;
get_header();
do_action( 'woocommerce_check_cart_items' );
wc_print_notices();

?>
<?php





// Determine Racehall product id from first cart item (bmileisure_id ACF/post meta). Fallback to the previous static id.
$racehall_product_id = null;
$date = null;
if ( function_exists( 'WC' ) && ! WC()->cart->is_empty() ) {
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $prod     = $cart_item['data'];
        $prod_id  = $prod->get_id();
        $date     = $cart_item['booking_date'] ?? null;
        $bm_value = function_exists( 'get_field' ) ? get_field( 'bmileisure_id', $prod_id ) : get_post_meta( $prod_id, 'bmileisure_id', true );
        if ( $bm_value ) {
            $racehall_product_id = intval( $bm_value );
            break;
        }
    }
}




$cart_location = '';
$main_product_id = 0;
$addon_cart_index = [];
$addon_carrier_product_id = function_exists( 'wk_rh_get_configured_addon_product_id' ) ? wk_rh_get_configured_addon_product_id() : 0;
$addon_carrier_error = function_exists( 'wk_rh_get_addon_carrier_validation_error' ) ? wk_rh_get_addon_carrier_validation_error( $addon_carrier_product_id ) : '';

if ( function_exists( 'WC' ) && ! WC()->cart->is_empty() ) {
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['booking_location'] ) ) {
            $cart_location = $cart_item['booking_location'];
            break; // use first product location
        }
    }

    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( empty( $cart_item['is_addon'] ) ) {
            $main_product_id = (int) ( $cart_item['product_id'] ?? 0 );
            if ( $main_product_id > 0 ) {
                break;
            }
        }
    }

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( empty( $cart_item['is_addon'] ) ) {
            continue;
        }
        $addon_upstream_id = isset( $cart_item['addon_upstream_id'] ) ? (string) $cart_item['addon_upstream_id'] : '';
        if ( $addon_upstream_id === '' && isset( $cart_item['addon_upstream_product_id'] ) ) {
            $addon_upstream_id = (string) $cart_item['addon_upstream_product_id'];
        }
        if ( $addon_upstream_id === '' ) {
            continue;
        }

        if ( ! isset( $addon_cart_index[ $addon_upstream_id ] ) ) {
            $addon_cart_index[ $addon_upstream_id ] = [
                'key' => $cart_item_key,
                'qty' => 0,
            ];
        }

        $addon_cart_index[ $addon_upstream_id ]['qty'] += max( 0, (int) ( $cart_item['quantity'] ?? 0 ) );
    }
}
// BMI booking session data (stored by rh_save_proposal AJAX, retrieved here)
    $supplement = WC()->session ? WC()->session->get('booking_supplement') : null;

$supplement_rows = [];
if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( ! empty( $cart_item['is_addon'] ) ) {
            continue;
        }
        if ( ! empty( $cart_item['bmi_supplements'] ) && is_array( $cart_item['bmi_supplements'] ) ) {
            $supplement_rows = $cart_item['bmi_supplements'];
            break;
        }
    }
}

if ( empty( $supplement_rows ) && is_array( $supplement ) && ! empty( $supplement['supplements'] ) && is_array( $supplement['supplements'] ) ) {
    $supplement_rows = $supplement['supplements'];
}

$rh_pick_qty_rule = static function ( $source, array $keys, $fallback = null ) {
    if ( ! is_array( $source ) ) {
        return $fallback;
    }
    foreach ( $keys as $key ) {
        if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) {
            return (int) round( (float) $source[ $key ] );
        }
    }
    return $fallback;
};

$main_summary_product_name = '—';
$summary_addon_rows = [];
if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( empty( $cart_item['is_addon'] ) && $main_summary_product_name === '—' ) {
            $main_product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
            if ( $main_product && is_object( $main_product ) && method_exists( $main_product, 'get_name' ) ) {
                $main_summary_product_name = (string) $main_product->get_name();
            }
            continue;
        }

        if ( empty( $cart_item['is_addon'] ) ) {
            continue;
        }

        $addon_product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
        $addon_name = ! empty( $cart_item['addon_display_name'] )
            ? (string) $cart_item['addon_display_name']
            : ( $addon_product && is_object( $addon_product ) && method_exists( $addon_product, 'get_name' ) ? (string) $addon_product->get_name() : '' );
        if ( $addon_name === '' ) {
            continue;
        }

        $addon_qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
        $addon_unit_price = isset( $cart_item['addon_unit_price'] ) && is_numeric( $cart_item['addon_unit_price'] )
            ? (float) $cart_item['addon_unit_price']
            : ( isset( $cart_item['line_total'] ) && is_numeric( $cart_item['line_total'] ) && $addon_qty > 0
                ? ( (float) $cart_item['line_total'] / $addon_qty )
                : 0.0 );
        $addon_line_total = $addon_unit_price * $addon_qty;

        $summary_addon_rows[] = [
            'label' => sprintf( '%s × %d', $addon_name, $addon_qty ),
            'price' => wc_price( $addon_line_total ),
        ];
    }
}

$hold_ctx = function_exists( 'wk_rh_get_cart_hold_expiry_context' )
    ? wk_rh_get_cart_hold_expiry_context()
    : [ 'expires_at' => 0, 'order_id' => '' ];
$hold_expires_at = isset( $hold_ctx['expires_at'] ) ? (int) $hold_ctx['expires_at'] : 0;
$continue_shopping_url = function_exists( 'wk_rh_get_main_booking_product_url' )
    ? wk_rh_get_main_booking_product_url()
    : wc_get_shop_page_url();











// echo '<pre>';
// var_dump($supplement);
// echo '</pre>';
?>

<?php if ( $hold_expires_at > 0 ) : ?>
    <div class="rh-hold-banner"
         data-expires-at="<?php echo esc_attr( $hold_expires_at ); ?>"
         data-expired-text="<?php echo esc_attr__( 'Reservationstiden er udløbet. Kurven opdateres…', 'racehall-wc-ui' ); ?>"
         data-prefix-text="<?php echo esc_attr__( 'Din reservation holdes i:', 'racehall-wc-ui' ); ?>"
         data-cart-url="<?php echo esc_url( wc_get_cart_url() ); ?>">
        <strong><?php esc_html_e( 'Bekræft din ordre inden tiden udløber.', 'racehall-wc-ui' ); ?></strong>
        <span class="rh-hold-countdown" aria-live="polite">--:--</span>
    </div>
<?php endif; ?>

<div id="rh-cart-loading" class="rh-cart-loading" aria-hidden="true">
    <div class="spinner" aria-hidden="true"></div>
</div>

<div class="racehall-cart cart-page">

    <!-- LEFT SECTION -->
    <section class="left">
        <h1><?php esc_html_e( 'FULDFØR DIN OPLEVELSE', 'racehall-wc-ui' ); ?></h1>
        <p><?php esc_html_e( 'Løft din oplevelse til næste niveau. Her får du muligheden for at skræddersy dit race, finjustere detaljerne og sætte dit personlige præg på dagen. Uanset om jagten er fart, præcision eller bare den perfekte oplevelse, er dette stedet, hvor du former dit eget løb.', 'racehall-wc-ui' ); ?></p>
        <!-- echo all the upsell id -->


        <div class="trophy">
            <img src="<?php echo esc_url( plugins_url( 'assets/image/trophy.png', dirname( __FILE__, 2 ) . '/racehall-wc-ui.php' ) ); ?>" alt="<?php echo esc_attr__( 'Trophy illustration', 'racehall-wc-ui' ); ?>" />
        </div>
    </section>

    <!-- ADD ONS / CART ITEMS -->
    <div class="center">
        <section class="addons">

            <h2><?php esc_html_e( 'ADD ONS', 'racehall-wc-ui' ); ?></h2>

<div class="">
    <div class="summary-item">
        <?php if ( ! WC()->cart->is_empty() ) : ?>
            <?php
            $rendered_addons = 0;
            if ( ! empty( $supplement_rows ) ) {
                foreach ( $supplement_rows as $addon ) {
                    $addon_row = is_array( $addon ) ? $addon : [];
                    if ( function_exists( 'wk_rh_normalize_booking_supplement_row' ) ) {
                        $addon_row = wk_rh_normalize_booking_supplement_row( $addon_row );
                    }
                    $product = ! empty( $addon_row ) ? $addon_row : [];
                    if ( empty( $product ) ) {
                        continue;
                    }

                    $supplement_row_id = isset( $addon_row['id'] ) ? trim( (string) $addon_row['id'] ) : '';
                    $upstream_id = $supplement_row_id;

                    if ( $upstream_id !== '' && (string) $racehall_product_id === $upstream_id ) {
                        continue;
                    }
                    $rendered_addons++;
                    $addon_name = '';
                    if ( isset( $addon_row['name'] ) && trim( (string) $addon_row['name'] ) !== '' ) {
                        $addon_name = (string) $addon_row['name'];
                    } elseif ( isset( $product['name'] ) ) {
                        $addon_name = (string) $product['name'];
                    }
                    $name = esc_html( $addon_name );
                    $amount_raw = isset($product['prices'][0]['amount']) && is_numeric($product['prices'][0]['amount']) ? (float) $product['prices'][0]['amount'] : 0.0;
                    $price = number_format($amount_raw, 2, ',', '.');
                    $currency = isset($product['prices'][0]['shortName']) ? esc_html($product['prices'][0]['shortName']) : ( isset( $product['prices'][0]['shortname'] ) ? esc_html( $product['prices'][0]['shortname'] ) : '' );
                    $addon_image = '';
                    if ( $upstream_id !== '' && function_exists( 'wk_rh_get_product_image_data_uri' ) ) {
                        $addon_image = wk_rh_get_product_image_data_uri( $cart_location, $upstream_id );
                    }

                    $min_qty = $rh_pick_qty_rule( $product, [ 'minQuantity', 'minQty', 'minimumQuantity', 'minimumQty', 'minAmount', 'minimumAmount' ], 1 );
                    $max_qty = $rh_pick_qty_rule( $product, [ 'maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty', 'maxAmount', 'maximumAmount' ], null );
                    $min_qty = max( 1, (int) $min_qty );
                    if ( $max_qty !== null ) {
                        $max_qty = max( $min_qty, (int) $max_qty );
                    }

                    $add_to_cart_product_id = $addon_carrier_product_id > 0 ? $addon_carrier_product_id : 0;
                    $existing_addon_qty = isset( $addon_cart_index[ $upstream_id ]['qty'] ) ? (int) $addon_cart_index[ $upstream_id ]['qty'] : 0;
                    $existing_addon_key = isset( $addon_cart_index[ $upstream_id ]['key'] ) ? (string) $addon_cart_index[ $upstream_id ]['key'] : '';
                    $display_qty = $existing_addon_qty > 0 ? $existing_addon_qty : $min_qty;
                    ?>

                    <div class="addon" style="margin-bottom:10px;">
                        <div class="info-container">
                            <?php if ( $addon_image !== '' ) : ?>
                                <div class="addon-img"><img src="<?php echo esc_attr( $addon_image ); ?>" alt="<?php echo esc_attr( wp_strip_all_tags( (string) ( $product['name'] ?? '' ) ) ); ?>" /></div>
                            <?php endif; ?>
                            <div class="addon-info">
                                <span class="title"><?php echo $name; ?></span>
                                <span class="price"><?php echo $price . ' ' . $currency; ?></span>
                            </div>
                        </div>
                        <?php if ( $add_to_cart_product_id > 0 && $upstream_id !== '' && $main_product_id > 0 && $addon_carrier_error === '' ) : ?>
                            <form method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>" class="addon-add-form addon-control-row" style="margin:8px 0; display:flex; align-items:center; gap:8px; flex-wrap:nowrap;">
                                <?php if ( $existing_addon_qty > 0 && $existing_addon_key !== '' ) : ?>
                                    <input type="hidden" name="update_cart" value="1">
                                    <?php wp_nonce_field( 'woocommerce-cart' ); ?>
                                    <button type="button" class="addon-qty-decrease" aria-label="<?php echo esc_attr__( 'Decrease', 'racehall-wc-ui' ); ?>">-</button>
                                    <input type="number"
                                           name="cart[<?php echo esc_attr( $existing_addon_key ); ?>][qty]"
                                           class="qty-input addon-qty-input"
                                           value="<?php echo esc_attr( $display_qty ); ?>"
                                           min="<?php echo esc_attr( $min_qty ); ?>"
                                           <?php if ( $max_qty !== null ) : ?>max="<?php echo esc_attr( $max_qty ); ?>"<?php endif; ?>
                                           step="1">
                                    <button type="button" class="addon-qty-increase" aria-label="<?php echo esc_attr__( 'Increase', 'racehall-wc-ui' ); ?>"<?php disabled( $max_qty !== null && $existing_addon_qty >= $max_qty ); ?>>+</button>
                                    <button type="submit" class="button addon-add-button"><?php esc_html_e( 'Tilføj', 'racehall-wc-ui' ); ?></button>
                                <?php else : ?>
                                    <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $add_to_cart_product_id ); ?>">
                                    <input type="hidden" name="is_addon" value="1">
                                    <input type="hidden" name="parent_racehall_product" value="<?php echo esc_attr( $main_product_id ); ?>">
                                    <input type="hidden" name="booking_location" value="<?php echo esc_attr( $cart_location ); ?>">
                                    <input type="hidden" name="addon_price" value="<?php echo esc_attr( wc_format_decimal( $amount_raw ) ); ?>">
                                    <input type="hidden" name="addon_upstream_id" value="<?php echo esc_attr( $upstream_id ); ?>">
                                    <input type="hidden" name="addon_supplement_id" value="<?php echo esc_attr( $supplement_row_id ); ?>">
                                    <input type="hidden" name="addon_display_name" value="<?php echo esc_attr( wp_strip_all_tags( $addon_name ) ); ?>">
                                    <input type="hidden" name="addon_min_qty" value="<?php echo esc_attr( $min_qty ); ?>">
                                    <input type="hidden" name="addon_max_qty" value="<?php echo esc_attr( $max_qty !== null ? $max_qty : '' ); ?>">
                                    <button type="button" class="addon-qty-decrease" aria-label="<?php echo esc_attr__( 'Decrease', 'racehall-wc-ui' ); ?>">-</button>
                                    <input type="number"
                                           name="quantity"
                                           class="qty-input addon-qty-input"
                                           value="<?php echo esc_attr( $display_qty ); ?>"
                                           min="<?php echo esc_attr( $min_qty ); ?>"
                                           <?php if ( $max_qty !== null ) : ?>max="<?php echo esc_attr( $max_qty ); ?>"<?php endif; ?>
                                           step="1">
                                    <button type="button" class="addon-qty-increase" aria-label="<?php echo esc_attr__( 'Increase', 'racehall-wc-ui' ); ?>">+</button>
                                    <button type="submit" class="button addon-add-button"><?php esc_html_e( 'Tilføj', 'racehall-wc-ui' ); ?></button>
                                <?php endif; ?>
                            </form>
                        <?php else : ?>
                            <span class="summary-label" style="display:block;margin-top:6px;"><?php echo esc_html( $addon_carrier_error !== '' ? $addon_carrier_error : __( 'Add-on kan ikke tilføjes lige nu. Kontrollér at add-on produktet er valgt i plugin-indstillingerne.', 'racehall-wc-ui' ) ); ?></span>
                        <?php endif; ?>
                    </div>
                <?php }

                if ( $rendered_addons === 0 ) {
                    ?>
                    <span class="summary-label">—</span>
                    <?php
                }
            } else {
                ?>
                <span class="summary-label">—</span>
            <?php } ?>
        <?php else: ?>
            <span class="summary-label">—</span>
        <?php endif; ?>
    </div>
</div>
        </section>
    </div>

    <!-- SUMMARY -->
    <aside class="summary">
        <div class="box">
            <div class="summary-section">
                <h4><?php esc_html_e( 'Bane', 'racehall-wc-ui' ); ?></h4>
                <div class="summary-item">
                    <span class="summary-label">  <?php echo $cart_location ? esc_html( $cart_location ) : '—'; ?></span>
                </div>
            </div>

            <div class="summary-section">
                <h4><?php esc_html_e( 'Produkt', 'racehall-wc-ui' ); ?></h4>
                <div class="summary-item">
                    <span class="summary-label"><?php echo esc_html( $main_summary_product_name ); ?></span>
                </div>
            </div>

            <div class="summary-section">
                <h4><?php esc_html_e( 'Pris', 'racehall-wc-ui' ); ?></h4>
                <div class="summary-item">
                    <span class="summary-label"><?php echo WC()->cart->get_cart_subtotal(); ?></span>
                </div>
            </div>

            <div class="summary-section addon-section">
                <h4><?php esc_html_e( 'Add ons', 'racehall-wc-ui' ); ?></h4>
                <?php if ( ! empty( $summary_addon_rows ) ) : ?>
                    <?php foreach ( $summary_addon_rows as $addon_row ) : ?>
                        <div class="summary-item">
                            <span class="summary-label"><?php echo esc_html( (string) $addon_row['label'] ); ?></span>
                            <span class="summary-label"><?php echo wp_kses_post( (string) $addon_row['price'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="summary-item">
                        <span class="summary-label">—</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="summary-total">
                <div class="total-item">
                    <span class="total-label"><?php esc_html_e( 'Total', 'racehall-wc-ui' ); ?></span>
                    <span class="total-price"><?php echo wc_price( WC()->cart->get_total( 'edit' ) ); ?></span>
                </div>
            </div>

            <div class="cart-actions-right">
                <a href="<?php echo esc_url( $continue_shopping_url ); ?>" class="btn primary"><?php esc_html_e( 'Fortsæt shopping', 'racehall-wc-ui' ); ?></a>
            </div>

            <?php if ( ! WC()->cart->is_empty() ) : ?>
                <div class="cart-actions-right">
                    <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'rh_clear_cart', '1', wc_get_cart_url() ), 'rh_clear_cart' ) ); ?>" class="clear-cart-link"><?php esc_html_e( 'Ryd kurv', 'racehall-wc-ui' ); ?></a>
                </div>
            <?php endif; ?>

            <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="btn secondary"><?php esc_html_e( 'Fortsæt til betaling', 'racehall-wc-ui' ); ?></a>
        </div>
    </aside>
</div>

<?php get_footer(); ?>