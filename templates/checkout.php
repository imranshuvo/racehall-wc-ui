<?php
defined( 'ABSPATH' ) || exit;
get_header();

$checkout = WC()->checkout();
$cart_items = WC()->cart->get_cart();

$cart_location = '';
$date = '';
$time = '';

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
    }
}
?>

<div class="container">

<?php if ( ! WC()->cart->is_empty() ) : ?>

<form name="checkout"
      method="post"
      class="checkout woocommerce-checkout"
      action="<?php echo esc_url( wc_get_checkout_url() ); ?>"
      enctype="multipart/form-data">

<main class="main-content">

<div class="hero-image">
    <img src="<?php echo esc_url( plugins_url( 'assets/image/3UAKSNLdD3.png', dirname( __FILE__, 2 ) . '/racehall-wc-ui.php' ) ); ?>"
         alt="Racing Track"
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
           placeholder="Fornavn"
           value="<?php echo esc_attr( $checkout->get_value('billing_first_name') ); ?>"
           required>
</div>

<!-- Last Name -->
<div class="form-group">
    <input type="text"
           name="billing_last_name"
           class="form-input"
           placeholder="Efternavn"
           value="<?php echo esc_attr( $checkout->get_value('billing_last_name') ); ?>"
           required>
</div>

<!-- Email -->
<div class="form-group">
    <input type="email"
           name="billing_email"
           class="form-input"
           placeholder="E-mail"
           value="<?php echo esc_attr( $checkout->get_value('billing_email') ); ?>"
           required>
</div>

<!-- Phone -->
<div class="form-group">
    <input type="tel"
           name="billing_phone"
           class="form-input"
           placeholder="Telefon nr."
           value="<?php echo esc_attr( $checkout->get_value('billing_phone') ); ?>"
           required>
</div>

<!-- Address -->
<div class="form-group">
    <input type="text"
           name="billing_address_1"
           class="form-input"
           placeholder="Gade og nr."
           value="<?php echo esc_attr( $checkout->get_value('billing_address_1') ); ?>"
           required>
</div>

<!-- Postcode -->
<div class="form-group">
    <input type="text"
           name="billing_postcode"
           class="form-input"
           placeholder="Postnummer"
           value="<?php echo esc_attr( $checkout->get_value('billing_postcode') ); ?>"
           required>
</div>

<!-- City -->
<div class="form-group">
    <input type="text"
           name="billing_city"
           class="form-input"
           placeholder="By"
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
              placeholder="Evt. noter"
              rows="6"><?php echo esc_textarea( $checkout->get_value('order_comments') ); ?></textarea>
</div>

<!-- Terms -->
<div class="checkbox-group">
    <div class="checkbox-item">
        <input type="checkbox" name="terms" class="checkbox" required>
        <label class="checkbox-label">
            Jeg accepterer handelsbetingelserne
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
    <h3 class="summary-label">Bane</h3>
    <span class="summary-text">
        <?php echo $cart_location ? esc_html( $cart_location ) : '—'; ?>
    </span>
</div>

<div class="summary-section">
    <h3 class="summary-label">Produkt</h3>
    <?php foreach ( $cart_items as $cart_item ) :
        $product = $cart_item['data']; ?>
        <span class="summary-text">
            <?php echo esc_html( $product->get_name() ); ?>
        </span>
    <?php endforeach; ?>
</div>

<div class="summary-section">
    <h3 class="summary-label">Antal</h3>
    <?php foreach ( $cart_items as $cart_item ) :
        $product  = $cart_item['data'];
        $quantity = $cart_item['quantity'];
        $line_total = wc_price( $cart_item['line_total'] );
    ?>
        <div class="detail-item">
            <span class="detail-text">
                <?php echo esc_html( $quantity . ' × ' . $product->get_name() ); ?>
            </span>
            <span class="detail-price">
                <?php echo $line_total; ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

<div class="summary-section">
    <h3 class="summary-label">Dato</h3>
    <span class="summary-text"><?php echo esc_html( $date ); ?></span>
</div>

<div class="summary-section">
    <h3 class="summary-label">Tidspunkt</h3>
    <span class="summary-text"><?php echo esc_html( $time ); ?></span>
</div>

<div class="summary-section total-section">
    <div class="total-row">
        <span class="total-label">Total</span>
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
     <button  type="button" class="payment-button payment-later">Betal ved ankomst</button>
    <button type="submit"
            class="payment-button payment-now"
            name="woocommerce_checkout_place_order"
            id="place_order"
            value="Betal nu">
        Betal nu
    </button>
</div>

</div>
</div>
</div>

</div>
</main>

</form>

<?php else : ?>
<p>Din kurv er tom.</p>
<?php endif; ?>

</div>

<?php get_footer(); ?>
