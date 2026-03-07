(function () {
    'use strict';

    function logBookingClientEvent(eventName, context) {
        var logger = window.RH_LOGGER || null;
        if (!logger || !logger.ajax_url || !logger.nonce || !eventName) return;

        var payload = new URLSearchParams({
            action: 'rh_log_client_event',
            nonce: logger.nonce,
            event: String(eventName),
            context: JSON.stringify(context || {})
        });

        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
                navigator.sendBeacon(logger.ajax_url, blob);
                return;
            }
        } catch (e) {
        }

        try {
            fetch(logger.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload
            }).catch(function () {});
        } catch (e) {
        }
    }

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function formatCountdown(seconds) {
        var safe = Math.max(0, parseInt(seconds, 10) || 0);
        var minutes = Math.floor(safe / 60);
        var remain = safe % 60;
        return pad2(minutes) + ':' + pad2(remain);
    }

    function disableCheckoutActions() {
        document.querySelectorAll('#place_order, .payment-button, button[type="submit"]').forEach(function (el) {
            if (el && typeof el.disabled !== 'undefined') {
                el.disabled = true;
            }
        });
    }

    function initHoldCountdown() {
        var banner = document.querySelector('.rh-hold-banner[data-expires-at]');
        if (!banner) return;

        var countdownEl = banner.querySelector('.rh-hold-countdown');
        var expiresAt = parseInt(banner.getAttribute('data-expires-at'), 10) || 0;
        var expiredText = banner.getAttribute('data-expired-text') || 'Reservation expired.';
        var prefixText = banner.getAttribute('data-prefix-text') || '';
        var timerCfg = window.RH_HOLD_TIMER || {};
        var ajaxUrl = timerCfg.ajax_url || '';
        var nonce = timerCfg.nonce || '';
        var fallbackRedirect = timerCfg.fallback_redirect || banner.getAttribute('data-cart-url') || window.location.href;
        if (!expiresAt || !countdownEl) return;

        var redirected = false;

        function redirectAfterExpiry() {
            if (redirected) return;
            redirected = true;
            logBookingClientEvent('checkout_hold_expired', { fallbackRedirect: fallbackRedirect });

            if (!ajaxUrl || !nonce || typeof fetch !== 'function') {
                window.setTimeout(function () {
                    window.location.href = fallbackRedirect;
                }, 1200);
                return;
            }

            var body = new URLSearchParams({
                action: 'rh_expire_hold',
                nonce: nonce
            });

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            })
                .then(function (res) { return res.text(); })
                .then(function (txt) {
                    var redirectUrl = fallbackRedirect;
                    try {
                        var json = JSON.parse(txt);
                        if (json && json.success && json.data && json.data.redirectUrl) {
                            redirectUrl = json.data.redirectUrl;
                        }
                    } catch (e) {
                    }

                    window.setTimeout(function () {
                        window.location.href = redirectUrl;
                    }, 1200);
                })
                .catch(function () {
                    window.setTimeout(function () {
                        window.location.href = fallbackRedirect;
                    }, 1200);
                });
        }

        var timer = window.setInterval(function () {
            var now = Math.floor(Date.now() / 1000);
            var left = expiresAt - now;

            if (left <= 0) {
                countdownEl.textContent = '00:00';
                banner.classList.add('expired');
                banner.querySelector('strong').textContent = expiredText;
                disableCheckoutActions();

                redirectAfterExpiry();

                window.clearInterval(timer);
                return;
            }

            if (prefixText) {
                countdownEl.textContent = prefixText + ' ' + formatCountdown(left);
            } else {
                countdownEl.textContent = formatCountdown(left);
            }
        }, 1000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initHoldCountdown();
        logBookingClientEvent('checkout_page_ready', {});

        const i18n = window.RH_CHECKOUT_I18N || {};
        const requiredNotice = i18n.requiredNotice || '';
        const processingText = i18n.processing || '';
        const codMissingNotice = i18n.codMissing || '';
        const payLaterText = i18n.payLater || '';

        const payLaterBtn = document.querySelector('.payment-button.payment-later');

        function redirectToProductIfNeeded() {
            const redirectLink = document.querySelector('.rh-booking-slot-unavailable-link');
            if (!redirectLink || !redirectLink.href) return;

            logBookingClientEvent('checkout_redirecting_to_product', { href: redirectLink.href });

            window.setTimeout(function () {
                window.location.href = redirectLink.href;
            }, 1600);
        }

        redirectToProductIfNeeded();

        if (window.jQuery && typeof window.jQuery === 'function') {
            window.jQuery(document.body).on('checkout_error', function () {
                logBookingClientEvent('checkout_error', {});
                if (payLaterBtn) {
                    payLaterBtn.disabled = false;
                    payLaterBtn.textContent = payLaterText;
                }
                redirectToProductIfNeeded();
            });
        }

        if (!payLaterBtn) return;

        payLaterBtn.addEventListener('click', function () {
            logBookingClientEvent('checkout_pay_later_clicked', {});
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
                logBookingClientEvent('checkout_validation_blocked', { reason: 'required_fields_missing' });
                showNotice(requiredNotice, 'error');
                return;
            }

            // --- Disable button & submit through Woo checkout using COD ---
            payLaterBtn.disabled = true;
            payLaterBtn.textContent = processingText;

            const codInput = form.querySelector('input[name="payment_method"][value="cod"]');
            if (!codInput) {
                logBookingClientEvent('checkout_validation_blocked', { reason: 'cod_missing' });
                showNotice(codMissingNotice, 'error');
                payLaterBtn.disabled = false;
                payLaterBtn.textContent = payLaterText;
                return;
            }

            logBookingClientEvent('checkout_place_order_triggered', { paymentMethod: 'cod' });

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
