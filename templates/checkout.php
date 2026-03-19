<?php
defined( 'ABSPATH' ) || exit;
get_header();
do_action( 'woocommerce_check_cart_items' );

$checkout = WC()->checkout();
$cart_items = WC()->cart->get_cart();

$cart_location = '';
$date = '';
$time = '';
$checkout_supplements = [];

if ( ! WC()->cart->is_empty() ) {
    foreach ( $cart_items as $cart_item ) {
        if ( ! empty( $cart_item['booking_location'] ) ) {
            $cart_location = $cart_item['booking_location'];
        }
        if ( isset( $cart_item['booking_date'] ) ) {
            $date = $cart_item['booking_date'];
        }
        if ( isset( $cart_item['booking_time'] ) ) {
            $time = $cart_item['booking_time'];
        }
        if ( empty( $cart_item['is_addon'] ) && ! empty( $cart_item['bmi_supplements'] ) && is_array( $cart_item['bmi_supplements'] ) ) {
            $checkout_supplements = $cart_item['bmi_supplements'];
        }
    }
}

$hold_ctx = function_exists( 'wk_rh_get_cart_hold_expiry_context' )
    ? wk_rh_get_cart_hold_expiry_context()
    : [ 'expires_at' => 0, 'order_id' => '' ];
$hold_expires_at = isset( $hold_ctx['expires_at'] ) ? (int) $hold_ctx['expires_at'] : 0;
?>

<script>
window.RH_CHECKOUT_I18N = {
    requiredNotice: <?php echo wp_json_encode( __( 'Udfyld venligst alle påkrævede felter og accepter handelsbetingelserne.', 'racehall-wc-ui' ) ); ?>,
    processing: <?php echo wp_json_encode( __( 'Behandler...', 'racehall-wc-ui' ) ); ?>,
    codMissing: <?php echo wp_json_encode( __( 'Betal ved ankomst kræver betalingsmetoden COD er aktiv i WooCommerce.', 'racehall-wc-ui' ) ); ?>,
    payLater: <?php echo wp_json_encode( __( 'Betal ved ankomst', 'racehall-wc-ui' ) ); ?>
};
</script>

<div class="container">

<?php if ( $hold_expires_at > 0 ) : ?>
    <div class="rh-hold-banner"
         data-expires-at="<?php echo esc_attr( $hold_expires_at ); ?>"
         data-expired-text="<?php echo esc_attr__( 'Reservationstiden er udløbet. Du skal starte bookingflowet igen.', 'racehall-wc-ui' ); ?>"
         data-prefix-text="<?php echo esc_attr__( 'Din reservation holdes i:', 'racehall-wc-ui' ); ?>"
         data-cart-url="<?php echo esc_url( wc_get_cart_url() ); ?>">
        <strong><?php esc_html_e( 'Bekræft ordren inden tidsfristen udløber.', 'racehall-wc-ui' ); ?></strong>
        <span class="rh-hold-countdown" aria-live="polite">--:--</span>
    </div>
<?php endif; ?>

<?php if ( ! WC()->cart->is_empty() ) : ?>

<form name="checkout"
      method="post"
      class="checkout woocommerce-checkout"
      action="<?php echo esc_url( wc_get_checkout_url() ); ?>"
      enctype="multipart/form-data">

<main class="main-content">

<div class="hero-image">
    <img src="<?php echo esc_url( plugins_url( 'assets/image/3UAKSNLdD3.png', dirname( __FILE__, 2 ) . '/racehall-wc-ui.php' ) ); ?>"
         alt="<?php echo esc_attr__( 'Racing Track', 'racehall-wc-ui' ); ?>"
         class="hero-img">
</div>

<div class="booking-section">

<!-- ================= LEFT SIDE FORM ================= -->
<div class="booking-form">

<div class="form">

<!-- First Name -->
<div class="form-group">
    <input type="text"
           name="billing_first_name"
           class="form-input"
           placeholder="<?php echo esc_attr__( 'Fornavn', 'racehall-wc-ui' ); ?>"
           value="<?php echo esc_attr( $checkout->get_value('billing_first_name') ); ?>"
           required>
</div>

<!-- Last Name -->
<div class="form-group">
    <input type="text"
           name="billing_last_name"
           class="form-input"
           placeholder="<?php echo esc_attr__( 'Efternavn', 'racehall-wc-ui' ); ?>"
           value="<?php echo esc_attr( $checkout->get_value('billing_last_name') ); ?>"
           required>
</div>

<!-- Email -->
<div class="form-group">
    <input type="email"
           name="billing_email"
           class="form-input"
           placeholder="<?php echo esc_attr__( 'E-mail', 'racehall-wc-ui' ); ?>"
           value="<?php echo esc_attr( $checkout->get_value('billing_email') ); ?>"
           required>
</div>

<!-- Phone -->
<div class="form-group">
    <input type="tel"
           name="billing_phone"
           class="form-input"
           placeholder="<?php echo esc_attr__( 'Telefon nr.', 'racehall-wc-ui' ); ?>"
           value="<?php echo esc_attr( $checkout->get_value('billing_phone') ); ?>"
           required>
</div>

<!-- Address -->
<div class="form-group">
    <input type="text"
           name="billing_address_1"
           class="form-input"
           placeholder="<?php echo esc_attr__( 'Gade og nr.', 'racehall-wc-ui' ); ?>"
           value="<?php echo esc_attr( $checkout->get_value('billing_address_1') ); ?>"
           required>
</div>

<!-- Postcode -->
<div class="form-group">
    <input type="text"
           name="billing_postcode"
           class="form-input"
           placeholder="<?php echo esc_attr__( 'Postnummer', 'racehall-wc-ui' ); ?>"
           value="<?php echo esc_attr( $checkout->get_value('billing_postcode') ); ?>"
           required>
</div>

<!-- City -->
<div class="form-group">
    <input type="text"
           name="billing_city"
           class="form-input"
           placeholder="<?php echo esc_attr__( 'By', 'racehall-wc-ui' ); ?>"
           value="<?php echo esc_attr( $checkout->get_value('billing_city') ); ?>"
           required>
</div>

<!-- Country -->
<div class="form-group">
    <select name="billing_country"
            class="form-input"
            required>

        <?php
        $countries = WC()->countries->get_countries();
        $selected_country = $checkout->get_value('billing_country') ?: 'DK';

        foreach ( $countries as $code => $country ) {
            echo '<option value="' . esc_attr( $code ) . '" ' .
                 selected( $selected_country, $code, false ) . '>' .
                 esc_html( $country ) .
                 '</option>';
        }
        ?>

    </select>
</div>

<!-- Notes -->
<div class="form-group">
    <textarea name="order_comments"
              class="form-textarea"
              placeholder="<?php echo esc_attr( function_exists( 'wk_rh_get_order_comments_placeholder_text' ) ? wk_rh_get_order_comments_placeholder_text() : __( 'Bemærkninger til din ordre, f.eks. hvor mange børn under 13 år deltager?', 'racehall-wc-ui' ) ); ?>"
              rows="6"><?php echo esc_textarea( $checkout->get_value('order_comments') ); ?></textarea>
</div>

<!-- Terms -->
<div class="checkbox-group">
    <div class="checkbox-item">
        <input type="checkbox" name="terms" class="checkbox" required>
        <label class="checkbox-label">
            <?php esc_html_e( 'Jeg accepterer handelsbetingelserne', 'racehall-wc-ui' ); ?>
        </label>
    </div>
</div>

</div>
</div>

<!-- ================= RIGHT SIDE SUMMARY ================= -->
<div class="booking-summary">
<div class="summary-card">
<div class="summary-content">

<div class="summary-section">
    <h3 class="summary-label"><?php esc_html_e( 'Bane', 'racehall-wc-ui' ); ?></h3>
    <span class="summary-text">
        <?php echo $cart_location ? esc_html( $cart_location ) : '—'; ?>
    </span>
</div>

<div class="summary-section">
    <h3 class="summary-label"><?php esc_html_e( 'Produkt', 'racehall-wc-ui' ); ?></h3>
    <?php foreach ( $cart_items as $cart_item ) :
        $product = $cart_item['data'];
        $line_name = ! empty( $cart_item['is_addon'] ) && ! empty( $cart_item['addon_display_name'] )
            ? (string) $cart_item['addon_display_name']
            : $product->get_name();
        $line_image_html = '';
        $image_location = ! empty( $cart_item['booking_location'] ) ? (string) $cart_item['booking_location'] : (string) $cart_location;
        $addon_upstream_id = function_exists( 'wk_rh_get_cart_item_addon_upstream_id' )
            ? wk_rh_get_cart_item_addon_upstream_id( $cart_item, $checkout_supplements )
            : ( isset( $cart_item['addon_upstream_id'] ) ? (string) $cart_item['addon_upstream_id'] : '' );

        if ( ! empty( $cart_item['is_addon'] ) && $addon_upstream_id !== '' && function_exists( 'wk_rh_get_product_image_html' ) ) {
            $line_image_html = wk_rh_get_product_image_html(
                $image_location,
                $addon_upstream_id,
                $line_name,
                'wk-rh-checkout-line-image'
            );
        } elseif ( $product && is_object( $product ) && method_exists( $product, 'get_image' ) ) {
            $line_image_html = $product->get_image( 'woocommerce_thumbnail', [ 'class' => 'wk-rh-checkout-line-image' ] );
        }
        ?>
        <div class="wk-rh-checkout-line-item">
            <?php if ( $line_image_html !== '' ) : ?>
                <span class="wk-rh-checkout-line-item-media"><?php echo wp_kses_post( $line_image_html ); ?></span>
            <?php endif; ?>
            <span class="summary-text wk-rh-checkout-line-item-name">
                <?php echo esc_html( $line_name ); ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<div class="summary-section">
    <h3 class="summary-label"><?php esc_html_e( 'Antal', 'racehall-wc-ui' ); ?></h3>
    <?php foreach ( $cart_items as $cart_item ) :
        $product  = $cart_item['data'];
        $line_name = ! empty( $cart_item['is_addon'] ) && ! empty( $cart_item['addon_display_name'] )
            ? (string) $cart_item['addon_display_name']
            : $product->get_name();
        $quantity = $cart_item['quantity'];
        $line_total = wc_price( $cart_item['line_total'] );
    ?>
        <div class="detail-item">
            <span class="detail-text">
                <?php echo esc_html( $quantity . ' × ' . $line_name ); ?>
            </span>
            <span class="detail-price">
                <?php echo $line_total; ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<div class="summary-section">
    <h3 class="summary-label"><?php esc_html_e( 'Dato', 'racehall-wc-ui' ); ?></h3>
    <span class="summary-text"><?php echo esc_html( $date ); ?></span>
</div>

<div class="summary-section">
    <h3 class="summary-label"><?php esc_html_e( 'Tidspunkt', 'racehall-wc-ui' ); ?></h3>
    <span class="summary-text"><?php echo esc_html( $time ); ?></span>
</div>

<div class="summary-section total-section">
    <div class="total-row">
        <span class="total-label"><?php esc_html_e( 'Total', 'racehall-wc-ui' ); ?></span>
        <span class="total-price">
            <?php echo WC()->cart->get_total(); ?>
        </span>
    </div>
</div>

<!-- Payment Methods -->
<div class="woocommerce-checkout-payment">
    <?php wc_get_template( 'checkout/payment.php' ); ?>
</div>

<!-- Place Order Button -->
<div class="payment-buttons">
    <button  type="button" class="payment-button payment-later"><?php esc_html_e( 'Betal ved ankomst', 'racehall-wc-ui' ); ?></button>
    <button type="submit"
            class="payment-button payment-now"
            name="woocommerce_checkout_place_order"
            id="place_order"
            value="<?php echo esc_attr__( 'Betal nu', 'racehall-wc-ui' ); ?>">
        <?php esc_html_e( 'Betal nu', 'racehall-wc-ui' ); ?>
    </button>
</div>

</div>
</div>
</div>

</div>
</main>

</form>

<?php else : ?>
<p><?php esc_html_e( 'Din kurv er tom.', 'racehall-wc-ui' ); ?></p>
<?php endif; ?>

</div>

<?php get_footer(); ?>
