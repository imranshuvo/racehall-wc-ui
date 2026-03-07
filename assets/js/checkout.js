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

    function showCheckoutLoading() {
        var overlay = document.getElementById('rh-checkout-loading');
        if (!overlay) return;

        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('rh-checkout-loading-active');
    }

    function hideCheckoutLoading() {
        var overlay = document.getElementById('rh-checkout-loading');
        if (overlay) {
            overlay.classList.remove('is-visible');
            overlay.setAttribute('aria-hidden', 'true');
        }

        document.documentElement.classList.remove('rh-checkout-loading-active');
    }

    function getCheckoutFlowConfig() {
        return window.RH_CHECKOUT_FLOW || {};
    }

    function setCheckoutStepState(isReady) {
        document.body.classList.toggle('wk-rh-checkout-step-ready', !!isReady);
        document.body.classList.toggle('wk-rh-checkout-step-pending', !isReady);

        document.querySelectorAll('.wk-rh-checkout-next-btn').forEach(function (button) {
            if (!button) return;
            var nextLabel = button.getAttribute('data-label-default') || button.textContent;
            button.textContent = nextLabel;
        });
    }

    function showScopedNotice(target, message, type) {
        if (!target) return;

        target.textContent = message || '';
        target.classList.remove('is-error', 'is-success');

        if (!message) return;

        target.classList.add(type === 'success' ? 'is-success' : 'is-error');
    }

    function showNotice(message, type) {
        var notice = document.getElementById('rh-checkout-notice');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'rh-checkout-notice';
            notice.style.cssText = 'margin:12px 0;padding:12px 16px;border-radius:4px;font-size:14px;font-weight:600;';
            var form = document.querySelector('form.checkout');
            if (form) form.prepend(notice);
        }
        notice.style.backgroundColor = type === 'error' ? '#3a0a0f' : '#0a3a1a';
        notice.style.color = type === 'error' ? '#ff6b6b' : '#6bffb8';
        notice.style.border = type === 'error' ? '1px solid #C8102E' : '1px solid #2ecc71';
        notice.textContent = message;
        notice.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function showFlowNotice(message, type, selector) {
        var target = document.querySelector(selector || '.wk-rh-checkout-step-panel--customer .wk-rh-checkout-step-notice');
        if (target) {
            showScopedNotice(target, message, type);
            return;
        }

        showNotice(message, type);
    }

    function findCheckoutField(name) {
        var nodes = Array.prototype.slice.call(document.querySelectorAll('[name="' + name + '"]'));
        if (!nodes.length) return null;

        var visible = nodes.find(function (node) {
            return !node.disabled && node.type !== 'hidden' && node.offsetParent !== null;
        });

        return visible || nodes.find(function (node) { return !node.disabled; }) || nodes[0];
    }

    function markFieldValidity(field, isValid) {
        if (!field) return;

        field.classList.toggle('wk-rh-invalid', !isValid);
        field.setAttribute('aria-invalid', isValid ? 'false' : 'true');

        var wrapper = field.closest('.form-row, .cfw-input-wrap, .form-group');
        if (wrapper) {
            wrapper.classList.toggle('wk-rh-invalid-wrap', !isValid);
        }
    }

    function validateCustomerInfoStep() {
        var flow = getCheckoutFlowConfig();
        var requiredFields = Array.isArray(flow.required_fields) ? flow.required_fields : [];
        var firstInvalid = null;
        var values = {};
        var isValid = true;

        requiredFields.forEach(function (fieldName) {
            var field = findCheckoutField(fieldName);
            var value = field ? String(field.value || '').trim() : '';
            var fieldValid = !!value;

            if (fieldValid && fieldName === 'billing_email') {
                fieldValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
            }

            if (field) {
                markFieldValidity(field, fieldValid);
            }

            values[fieldName] = value;

            if (!fieldValid) {
                isValid = false;
                if (!firstInvalid) firstInvalid = field;
            }
        });

        if (!isValid && firstInvalid && typeof firstInvalid.focus === 'function') {
            firstInvalid.focus();
        }

        return {
            valid: isValid,
            values: values
        };
    }

    function setPrepareButtonLoading(button, isLoading) {
        if (!button) return;

        var defaultLabel = document.body.classList.contains('wk-rh-checkout-step-ready')
            ? (button.getAttribute('data-label-ready') || button.textContent)
            : (button.getAttribute('data-label-default') || button.textContent);
        var loadingLabel = button.getAttribute('data-label-loading') || defaultLabel;

        button.disabled = !!isLoading;
        button.textContent = isLoading ? loadingLabel : defaultLabel;
    }

    function postCheckoutAction(payload) {
        var flow = getCheckoutFlowConfig();
        if (!flow.ajax_url || typeof fetch !== 'function') {
            return Promise.reject(new Error('missing_ajax_url'));
        }

        return fetch(flow.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = {};
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    data = { success: false, data: { message: text || '' } };
                }

                if (!response.ok || !data || data.success !== true) {
                    var errorMessage = data && data.data && data.data.message ? data.data.message : '';
                    var err = new Error(errorMessage || 'request_failed');
                    err.payload = data && data.data ? data.data : {};
                    throw err;
                }

                return data.data || {};
            });
        });
    }

    function handleCheckoutFieldMutation() {
        if (!document.body.classList.contains('wk-rh-checkout-step-ready')) return;

        setCheckoutStepState(false);
        showFlowNotice(getCheckoutFlowConfig().messages && getCheckoutFlowConfig().messages.changedCustomerInfo
            ? getCheckoutFlowConfig().messages.changedCustomerInfo
            : '', 'error');
    }

    function computeNextAddonQuantity(card, direction) {
        var storedQty = parseInt(card.getAttribute('data-current-qty'), 10) || 0;
        var currentQty = storedQty;
        var displayInput = card.querySelector('.addon-qty-display');
        if (displayInput) {
            var displayQty = parseInt(displayInput.value, 10);
            if (!isNaN(displayQty)) {
                currentQty = Math.max(0, displayQty);
            }
        }
        var minQty = parseInt(card.getAttribute('data-min-qty'), 10) || 1;
        var maxQty = parseInt(card.getAttribute('data-max-qty'), 10) || 0;

        if (direction === 'add') {
            if (maxQty > 0) {
                currentQty = Math.min(currentQty, maxQty);
            }
            return currentQty;
        }

        if (direction === 'remove') {
            return 0;
        }

        if (direction === 'increase') {
            var increased = currentQty > 0 ? currentQty + 1 : minQty;
            if (maxQty > 0) {
                increased = Math.min(increased, maxQty);
            }
            return increased;
        }

        if (direction === 'decrease') {
            if (storedQty > 0 && currentQty <= minQty) {
                return 0;
            }

            if (storedQty <= 0 && currentQty <= minQty) {
                return minQty;
            }

            return Math.max(minQty, currentQty - 1);
        }

        return currentQty;
    }

    function setSupplementsLoading(isLoading) {
        if (isLoading) {
            showCheckoutLoading();
        } else {
            hideCheckoutLoading();
        }

        var list = document.querySelector('.wk-rh-checkout-addons-list');
        if (list) {
            list.classList.toggle('is-updating', !!isLoading);
        }

        document.querySelectorAll('.wk-rh-addon-card-actions button').forEach(function (button) {
            button.disabled = !!isLoading;
        });

        document.querySelectorAll('.wk-rh-checkout-back-btn').forEach(function (link) {
            if (!link) return;
            link.classList.toggle('is-disabled', !!isLoading);
            link.setAttribute('aria-disabled', isLoading ? 'true' : 'false');
            link.tabIndex = isLoading ? -1 : 0;
        });
    }

    function updateAddonCardState(card, quantity) {
        if (!card) return;

        var nextQty = Math.max(0, parseInt(quantity, 10) || 0);
        var minQty = parseInt(card.getAttribute('data-min-qty'), 10) || 1;
        var maxQty = parseInt(card.getAttribute('data-max-qty'), 10) || 0;
        var displayQty = nextQty > 0 ? nextQty : minQty;
        var displayInput = card.querySelector('.addon-qty-display');
        var increaseButton = card.querySelector('.wk-rh-addon-qty-btn[data-direction="increase"]');

        card.setAttribute('data-current-qty', String(nextQty));
        card.setAttribute('data-display-qty', String(displayQty));
        card.setAttribute('data-is-selected', nextQty > 0 ? '1' : '0');
        card.classList.toggle('is-selected', nextQty > 0);

        if (displayInput) {
            displayInput.value = String(displayQty);
        }

        if (increaseButton) {
            increaseButton.disabled = maxQty > 0 && displayQty >= maxQty;
        }
    }

    function refreshCheckoutFragments() {
        return new Promise(function (resolve) {
            if (!window.jQuery || typeof window.jQuery !== 'function') {
                resolve();
                return;
            }

            var $body = window.jQuery(document.body);
            var settled = false;

            function done() {
                if (settled) return;
                settled = true;
                $body.off('updated_checkout.wkRhCheckoutFlow');
                resolve();
            }

            $body.one('updated_checkout.wkRhCheckoutFlow', done);
            $body.trigger('wc_fragment_refresh');
            $body.trigger('update_checkout');

            window.setTimeout(done, 2000);
        });
    }

    function replaceStepPanel(selector, html) {
        if (!selector || !html) return null;

        var current = document.querySelector(selector);
        if (!current) return null;

        var wrapper = document.createElement('div');
        wrapper.innerHTML = String(html).trim();
        var next = wrapper.firstElementChild;
        if (!next) return null;

        current.replaceWith(next);
        return next;
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
        hideCheckoutLoading();

        const i18n = window.RH_CHECKOUT_I18N || {};
        const flow = getCheckoutFlowConfig();
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

        setCheckoutStepState(false);

        document.addEventListener('input', function (event) {
            var target = event.target;
            if (!target || !Array.isArray(flow.required_fields)) return;
            if (flow.required_fields.indexOf(target.name) === -1) return;
            handleCheckoutFieldMutation();
        });

        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || !Array.isArray(flow.required_fields)) return;
            if (flow.required_fields.indexOf(target.name) === -1) return;
            handleCheckoutFieldMutation();
        });

        document.addEventListener('click', function (event) {
            var prepareButton = event.target.closest('.wk-rh-checkout-next-btn');
            if (prepareButton) {
                event.preventDefault();

                var validation = validateCustomerInfoStep();
                if (!validation.valid) {
                    logBookingClientEvent('checkout_step_validation_blocked', { reason: 'customer_info_invalid' });
                    showFlowNotice(flow.messages && flow.messages.invalidCustomerInfo ? flow.messages.invalidCustomerInfo : requiredNotice, 'error');
                    return;
                }

                showCheckoutLoading();
                setPrepareButtonLoading(prepareButton, true);
                showFlowNotice('', 'success');

                var payload = new URLSearchParams({
                    action: 'rh_prepare_checkout_booking',
                    nonce: flow.nonce || ''
                });

                Object.keys(validation.values).forEach(function (key) {
                    payload.append(key, validation.values[key]);
                });

                var orderComments = findCheckoutField('order_comments');
                if (orderComments) {
                    payload.append('order_comments', String(orderComments.value || ''));
                }

                postCheckoutAction(payload)
                    .then(function (data) {
                        logBookingClientEvent('checkout_step_prepared', {});
                        var supplementsPanel = null;

                        if (data && data.supplementsHtml) {
                            supplementsPanel = replaceStepPanel('.wk-rh-checkout-step-panel--supplements', data.supplementsHtml);
                        }

                        return refreshCheckoutFragments().then(function () {
                            setCheckoutStepState(true);
                            showFlowNotice(data.message || '', 'success', '.wk-rh-checkout-step-notice--supplements');

                            supplementsPanel = supplementsPanel || document.querySelector('.wk-rh-checkout-step-panel--supplements');
                            if (supplementsPanel && typeof supplementsPanel.scrollIntoView === 'function') {
                                supplementsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        });
                    })
                    .catch(function (error) {
                        var payload = error && error.payload ? error.payload : {};
                        logBookingClientEvent('checkout_step_failed', { message: error.message || '' });
                        showFlowNotice(error.message || (flow.messages && flow.messages.genericError) || '', 'error');
                        if (payload.redirectToProduct && payload.redirectUrl) {
                            window.setTimeout(function () {
                                window.location.href = payload.redirectUrl;
                            }, 1200);
                        }
                    })
                    .finally(function () {
                        hideCheckoutLoading();
                        setPrepareButtonLoading(prepareButton, false);
                    });

                return;
            }

            var backButton = event.target.closest('.wk-rh-checkout-back-btn');
            if (backButton) {
                if (backButton.classList.contains('is-disabled')) {
                    event.preventDefault();
                    return;
                }

                setCheckoutStepState(false);
                showFlowNotice('', 'success', '.wk-rh-checkout-step-notice--supplements');
                return;
            }

            var qtyButton = event.target.closest('.wk-rh-addon-qty-btn');
            if (qtyButton) {
                event.preventDefault();

                var qtyCard = qtyButton.closest('.wk-rh-addon-card');
                if (!qtyCard) return;

                var qtyInput = qtyCard.querySelector('.addon-qty-display');
                if (!qtyInput) return;

                var directionAttr = qtyButton.getAttribute('data-direction') || 'increase';
                var currentDisplay = parseInt(qtyInput.value, 10) || 0;
                var minDisplay = parseInt(qtyCard.getAttribute('data-min-qty'), 10) || 1;
                var maxDisplay = parseInt(qtyCard.getAttribute('data-max-qty'), 10) || 0;
                var nextDisplay = currentDisplay;

                if (directionAttr === 'decrease') {
                    nextDisplay = Math.max(minDisplay, currentDisplay - 1);
                } else {
                    nextDisplay = currentDisplay + 1;
                    if (maxDisplay > 0) {
                        nextDisplay = Math.min(nextDisplay, maxDisplay);
                    }
                }

                qtyInput.value = String(nextDisplay);
                return;
            }

            var actionButton = event.target.closest('.wk-rh-addon-add-btn, .wk-rh-addon-remove-btn, .wk-rh-addon-qty-btn');
            if (!actionButton) return;

            event.preventDefault();

            var card = actionButton.closest('.wk-rh-addon-card');
            if (!card) return;

            var direction = 'add';
            if (actionButton.classList.contains('wk-rh-addon-remove-btn')) {
                direction = 'remove';
            } else if (actionButton.classList.contains('wk-rh-addon-qty-btn')) {
                direction = actionButton.getAttribute('data-direction') || 'increase';
            }

            var nextQty = computeNextAddonQuantity(card, direction);
            var addonId = card.getAttribute('data-addon-upstream-id') || '';
            if (!addonId) return;

            setSupplementsLoading(true);
            showFlowNotice(flow.messages && flow.messages.supplementUpdating ? flow.messages.supplementUpdating : processingText, 'success', '.wk-rh-checkout-step-notice--supplements');

            var addonPayload = new URLSearchParams({
                action: 'rh_checkout_set_addon_qty',
                nonce: flow.nonce || '',
                addon_upstream_id: addonId,
                quantity: String(nextQty)
            });

            postCheckoutAction(addonPayload)
                .then(function (data) {
                    var appliedQty = data && typeof data.quantity !== 'undefined' ? data.quantity : nextQty;
                    updateAddonCardState(card, appliedQty);
                    logBookingClientEvent('checkout_addon_quantity_updated', { addonId: addonId, quantity: appliedQty });
                    showFlowNotice('', 'success', '.wk-rh-checkout-step-notice--supplements');
                    return refreshCheckoutFragments();
                })
                .catch(function (error) {
                    logBookingClientEvent('checkout_addon_quantity_failed', { addonId: addonId, quantity: nextQty, message: error.message || '' });
                    showFlowNotice(error.message || (flow.messages && flow.messages.genericError) || '', 'error', '.wk-rh-checkout-step-notice--supplements');
                })
                .finally(function () {
                    setSupplementsLoading(false);
                });
        });

        if (window.jQuery && typeof window.jQuery === 'function') {
            window.jQuery(document.body).on('checkout_error', function () {
                logBookingClientEvent('checkout_error', {});
                hideCheckoutLoading();
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

    });

    window.addEventListener('pageshow', function () {
        hideCheckoutLoading();
    });
})();
