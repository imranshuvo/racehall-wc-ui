(function(){
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
    } catch (e) {}

    try {
      fetch(logger.ajax_url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload
      }).catch(function () {});
    } catch (e) {}
  }

  function showCartLoading() {
    var overlay = document.getElementById('rh-cart-loading');
    if (!overlay) return;
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('rh-cart-loading-active');
  }

  function hideCartLoading() {
    var overlay = document.getElementById('rh-cart-loading');
    if (overlay) {
      overlay.classList.remove('is-visible');
      overlay.setAttribute('aria-hidden', 'true');
    }
    document.documentElement.classList.remove('rh-cart-loading-active');
  }

  function submitFormWithLoader(form, submitter) {
    if (!form) return;
    showCartLoading();
    window.setTimeout(function () {
      if (typeof form.requestSubmit === 'function') {
        if (submitter) {
          form.requestSubmit(submitter);
        } else {
          form.requestSubmit();
        }
      } else {
        form.submit();
      }
    }, 20);
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

  function disableCartActions() {
    document.querySelectorAll('.btn, .update-cart-button, .qty-increase, .qty-decrease, .remove-item, .single_add_to_cart_button, button[type="submit"]').forEach(function (el) {
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

    var hasReloaded = false;

    function redirectAfterExpiry() {
      if (hasReloaded) return;
      hasReloaded = true;
      logBookingClientEvent('cart_hold_expired', { fallbackRedirect: fallbackRedirect });

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
        banner.setAttribute('aria-live', 'polite');
        banner.querySelector('strong').textContent = expiredText;
        disableCartActions();

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
    logBookingClientEvent('cart_page_ready', {});
    window.setTimeout(hideCartLoading, 50);
  });

  window.addEventListener('pageshow', function () {
    window.setTimeout(hideCartLoading, 10);
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches) return;
    if (
      form.matches('form.woocommerce-cart-form') ||
      form.matches('form.addon-add-form') ||
      form.closest('.racehall-cart')
    ) {
      showCartLoading();
    }
  });

  document.addEventListener('click', function(e){
    var target = e.target;
    var clicked = target && target.closest ? target.closest('.addon-action-add, .addon-action-remove, .remove-item, .update-cart-button') : null;

    if (clicked && clicked.classList.contains('addon-action-add')) {
      e.preventDefault();
      if (clicked.disabled) return;
      logBookingClientEvent('cart_addon_add_clicked', {});
      submitFormWithLoader(clicked.closest('form'), clicked);
      return;
    }

    if (clicked && clicked.classList.contains('addon-action-remove')) {
      e.preventDefault();
      if (clicked.disabled) return;
      logBookingClientEvent('cart_addon_remove_clicked', {});
      submitFormWithLoader(clicked.closest('form'), clicked);
      return;
    }

    if (clicked && clicked.classList.contains('update-cart-button')) {
      e.preventDefault();
      if (clicked.disabled) return;
      logBookingClientEvent('cart_update_clicked', {});
      var formId = clicked.getAttribute('form');
      var form = formId ? document.getElementById(formId) : clicked.closest('form');
      submitFormWithLoader(form, clicked);
      return;
    }

    if (clicked && clicked.classList.contains('remove-item')) {
      e.preventDefault();
      logBookingClientEvent('cart_remove_clicked', { href: clicked.getAttribute('href') || '' });
      showCartLoading();
      var href = clicked.getAttribute('href');
      window.setTimeout(function () {
        if (href) window.location.href = href;
      }, 20);
      return;
    }

    if ( target.classList.contains('qty-increase') || target.classList.contains('qty-decrease') ) {
      e.preventDefault();
      var container = target.closest('.addon');
      var input = container.querySelector('.qty-input');
      var current = parseInt(input.value, 10) || 0;
      var minValue = parseInt(input.getAttribute('min'), 10);
      if (!Number.isFinite(minValue)) minValue = 0;
      var maxAttr = input.getAttribute('max');
      var maxValue = (maxAttr !== null && maxAttr !== '') ? parseInt(maxAttr, 10) : null;
      if (!Number.isFinite(maxValue)) maxValue = null;

      if ( target.classList.contains('qty-increase') ) current++;
      else current--;

      current = Math.max(minValue, current);
      if (maxValue !== null) current = Math.min(maxValue, current);
      input.value = current;
      // trigger a small debounce submit
      clearTimeout(window.racehallCartUpdateTimer);
      window.racehallCartUpdateTimer = setTimeout(function(){
        // submit the parent cart form
        var form = container.closest('form.woocommerce-cart-form');
        if (form) {
          submitFormWithLoader(form, null);
        }
      }, 500);
    }

    if (target.classList.contains('addon-qty-increase') || target.classList.contains('addon-qty-decrease')) {
      e.preventDefault();
      var addonForm = target.closest('form.addon-add-form');
      if (!addonForm) return;

      var addonInput = addonForm.querySelector('.addon-qty-input');
      if (!addonInput) return;

      var currentValue = parseInt(addonInput.value, 10);
      if (!Number.isFinite(currentValue)) {
        currentValue = parseInt(addonInput.getAttribute('min'), 10);
      }
      if (!Number.isFinite(currentValue)) currentValue = 1;

      var minValue = parseInt(addonInput.getAttribute('min'), 10);
      if (!Number.isFinite(minValue)) minValue = 1;

      var maxAttr = addonInput.getAttribute('max');
      var maxValue = maxAttr !== null && maxAttr !== '' ? parseInt(maxAttr, 10) : null;
      if (!Number.isFinite(maxValue)) maxValue = null;

      if (target.classList.contains('addon-qty-increase')) {
        currentValue += 1;
      } else {
        currentValue -= 1;
      }

      currentValue = Math.max(minValue, currentValue);
      if (maxValue !== null) currentValue = Math.min(maxValue, currentValue);
      addonInput.value = currentValue;
    }
  });

  document.addEventListener('input', function (e) {
    var target = e.target;
    if (!target || !target.classList || !target.classList.contains('addon-qty-input')) return;

    var minValue = parseInt(target.getAttribute('min'), 10);
    if (!Number.isFinite(minValue)) minValue = 1;
    var maxAttr = target.getAttribute('max');
    var maxValue = maxAttr !== null && maxAttr !== '' ? parseInt(maxAttr, 10) : null;
    if (!Number.isFinite(maxValue)) maxValue = null;

    var parsed = parseInt(target.value, 10);
    if (!Number.isFinite(parsed)) {
      return;
    }
    parsed = Math.max(minValue, parsed);
    if (maxValue !== null) parsed = Math.min(maxValue, parsed);
    target.value = parsed;
  });
})();