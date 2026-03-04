(function(){
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