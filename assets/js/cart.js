(function(){
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

  document.addEventListener('DOMContentLoaded', initHoldCountdown);

  document.addEventListener('click', function(e){
    var target = e.target;
    if ( target.classList.contains('qty-increase') || target.classList.contains('qty-decrease') ) {
      e.preventDefault();
      var container = target.closest('.addon');
      var input = container.querySelector('.qty-input');
      var current = parseInt(input.value, 10) || 0;
      if ( target.classList.contains('qty-increase') ) current++;
      else current = Math.max(0, current - 1);
      input.value = current;
      // trigger a small debounce submit
      clearTimeout(window.racehallCartUpdateTimer);
      window.racehallCartUpdateTimer = setTimeout(function(){
        // submit the parent cart form
        var form = container.closest('form.woocommerce-cart-form');
        if (form) {
          // click update button if exists
          var btn = form.querySelector('.update-cart-button');
          if (btn) btn.click();
          else form.submit();
        }
      }, 500);
    }
  });
})();