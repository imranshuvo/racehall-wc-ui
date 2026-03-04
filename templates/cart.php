<?php defined('ABSPATH') || exit;
get_header();
wc_print_notices();





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
}
// BMI booking session data (stored by rh_save_proposal AJAX, retrieved here)
    $supplement = WC()->session ? WC()->session->get('booking_supplement') : null;











// echo '<pre>';
// var_dump($supplement);
// echo '</pre>';
?>

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
    <h4><?php esc_html_e( 'Add ons', 'racehall-wc-ui' ); ?></h4>
    <div class="summary-item">
        <?php if ( ! WC()->cart->is_empty() ) : ?>
            <?php
            if (!empty($supplement['supplements'])) {
                foreach ($supplement['supplements'] as $addon) {
                    $product = $addon['product'];
                    $upstream_id = isset($product['id']) ? (string) $product['id'] : '';
                    $name = isset($product['name']) ? esc_html($product['name']) : '';
                    $price = isset($product['prices'][0]['amount']) ? number_format($product['prices'][0]['amount'], 2, ',', '.') : '';
                    $currency = isset($product['prices'][0]['shortName']) ? esc_html($product['prices'][0]['shortName']) : '';

                    $mapped_product_id = 0;
                    if ( $upstream_id !== '' ) {
                        $mapped_products = wc_get_products([
                            'status' => 'publish',
                            'limit' => 1,
                            'meta_key' => 'bmileisure_id',
                            'meta_value' => $upstream_id,
                            'return' => 'ids',
                        ]);
                        if ( ! empty( $mapped_products ) ) {
                            $mapped_product_id = (int) $mapped_products[0];
                        }
                    }
                    ?>

                    <div class="addon" style="margin-bottom:10px;">
                        <div class="info-container">
                            <div class="addon-info">
                                <span class="title"><?php echo $name; ?></span>
                                <span class="price"><?php echo $price . ' ' . $currency; ?></span>
                            </div>
                        </div>
                        <?php if ( $mapped_product_id > 0 && $main_product_id > 0 ) : ?>
                            <form method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>" style="margin-top:8px;">
                                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $mapped_product_id ); ?>">
                                <input type="hidden" name="quantity" value="1">
                                <input type="hidden" name="is_addon" value="1">
                                <input type="hidden" name="parent_racehall_product" value="<?php echo esc_attr( $main_product_id ); ?>">
                                <input type="hidden" name="booking_location" value="<?php echo esc_attr( $cart_location ); ?>">
                                <button type="submit" class="btn secondary"><?php esc_html_e( 'Tilføj add-on', 'racehall-wc-ui' ); ?></button>
                            </form>
                        <?php else : ?>
                            <span class="summary-label" style="display:block;margin-top:6px;"><?php esc_html_e( 'Ikke mappet til Woo produkt', 'racehall-wc-ui' ); ?></span>
                        <?php endif; ?>
                    </div>
                <?php }
            } else {
                ?>
                <span class="summary-label">—</span>
            <?php } ?>
        <?php else: ?>
            <span class="summary-label">—</span>
        <?php endif; ?>
    </div>
</div>


            <form id="racehall-cart-form" class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
                <?php if ( WC()->cart->is_empty() ) : ?>
                    <p class="empty"><?php esc_html_e( 'Din kurv er tom.', 'racehall-wc-ui' ); ?></p>
                <?php else : ?>

                    <?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
                        $_product   = $cart_item['data'];
                        $product_id = $cart_item['product_id'];
                        if ( ! $_product || ! $_product->exists() ) {
                            continue;
                        }
                        $thumbnail = $_product->get_image( 'thumbnail' );
                        $name      = $_product->get_name();
                        $price     = wc_price( $_product->get_price() );
                        $qty       = $cart_item['quantity'];
                    ?>
                        <div class="addon">
                            <div class="info-container">
                                <div class="addon-img"><?php echo $thumbnail; ?></div>
                                <div class="addon-info">
                                    <span class="title"><?php echo esc_html( $name ); ?></span>
                                    <span class="price"><?php echo wp_kses_post( $price ); ?></span>
                                </div>
                            </div>

                            <div>
                                <div class="counter">
                                    <button type="button" class="qty-decrease" aria-label="<?php echo esc_attr__( 'Decrease', 'racehall-wc-ui' ); ?>">-</button>

                                    <input type="number"
                                           class="qty-input"
                                           name="cart[<?php echo esc_attr( $cart_item_key ); ?>][qty]"
                                           value="<?php echo esc_attr( $qty ); ?>"
                                           min="0"
                                           max="<?php echo esc_attr( $_product->get_max_purchase_quantity() ); ?>">

                                    <button type="button" class="qty-increase" aria-label="<?php echo esc_attr__( 'Increase', 'racehall-wc-ui' ); ?>">+</button>
                                </div>

                                <a href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item_key ) ); ?>" class="remove-item" aria-label="<?php esc_attr_e( 'Fjern dette produkt', 'racehall-wc-ui' ); ?>">×</a>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>

                <?php wp_nonce_field( 'woocommerce-cart' ); ?>
            </form>
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
                    <span class="summary-label"><?php echo esc_html( implode( ', ', array_map( function( $item ){ return $item['data']->get_name(); }, WC()->cart->get_cart() ) ) ?: '—' ); ?></span>
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
                <div class="summary-item">
                    <span class="summary-label"><?php esc_html_e( 'Se add-ons ovenfor', 'racehall-wc-ui' ); ?></span>
                </div>
            </div>

            <div class="summary-total">
                <div class="total-item">
                    <span class="total-label"><?php esc_html_e( 'Total', 'racehall-wc-ui' ); ?></span>
                    <span class="total-price"><?php echo wc_price( WC()->cart->get_total( 'edit' ) ); ?></span>
                </div>
            </div>

            <div class="cart-actions-right">
                <button type="submit" name="update_cart" form="racehall-cart-form" class="btn primary update-cart-button"><?php esc_html_e( 'Opdater kurv', 'racehall-wc-ui' ); ?></button>
            </div>

            <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="btn secondary"><?php esc_html_e( 'Fortsæt til betaling', 'racehall-wc-ui' ); ?></a>
        </div>
    </aside>
</div>

<?php get_footer(); ?>