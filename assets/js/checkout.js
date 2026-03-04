(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const payLaterBtn = document.querySelector('.payment-button.payment-later');
        if (!payLaterBtn) return;

        payLaterBtn.addEventListener('click', function () {
            const form = document.querySelector('form.checkout.woocommerce-checkout');
            if (!form) return;

            // --- Client-side validation ---
            const required = form.querySelectorAll('[required]');
            let valid = true;
            required.forEach(function (field) {
                field.style.borderColor = '';
                if (!field.value.trim()) {
                    field.style.borderColor = '#C8102E';
                    valid = false;
                }
            });

            // Terms checkbox
            const terms = form.querySelector('input[name="terms"]');
            if (terms && !terms.checked) {
                terms.closest('.checkbox-item').style.outline = '1px solid #C8102E';
                valid = false;
            } else if (terms) {
                terms.closest('.checkbox-item').style.outline = '';
            }

            if (!valid) {
                showNotice('Udfyld venligst alle påkrævede felter og accepter handelsbetingelserne.', 'error');
                return;
            }

            // --- Disable button & submit through Woo checkout using COD ---
            payLaterBtn.disabled = true;
            payLaterBtn.textContent = 'Behandler...';

            const codInput = form.querySelector('input[name="payment_method"][value="cod"]');
            if (!codInput) {
                showNotice('Betal ved ankomst kræver betalingsmetoden COD er aktiv i WooCommerce.', 'error');
                payLaterBtn.disabled = false;
                payLaterBtn.textContent = 'Betal ved ankomst';
                return;
            }

            codInput.checked = true;
            codInput.dispatchEvent(new Event('change', { bubbles: true }));

            const placeOrderBtn = form.querySelector('#place_order');
            if (placeOrderBtn) {
                placeOrderBtn.click();
                return;
            }

            form.submit();
        });

        function showNotice(message, type) {
            let notice = document.getElementById('rh-checkout-notice');
            if (!notice) {
                notice = document.createElement('div');
                notice.id = 'rh-checkout-notice';
                notice.style.cssText = 'margin:12px 0;padding:12px 16px;border-radius:4px;font-size:14px;font-weight:600;';
                const form = document.querySelector('form.checkout');
                if (form) form.prepend(notice);
            }
            notice.style.backgroundColor = type === 'error' ? '#3a0a0f' : '#0a3a1a';
            notice.style.color = type === 'error' ? '#ff6b6b' : '#6bffb8';
            notice.style.border = type === 'error' ? '1px solid #C8102E' : '1px solid #2ecc71';
            notice.textContent = message;
            notice.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
})();
