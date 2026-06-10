/* Onsite Booking System — admin UI behaviour (progressive enhancement). */
(function () {
    'use strict';

    function init() {
        var layout = document.querySelector('.wkrh-admin .wkrh-layout');
        if (layout) {
            // Enables CSS show/hide of section panels. Without JS every panel stays visible.
            layout.classList.add('wkrh-js');
        }

        var navItems = Array.prototype.slice.call(document.querySelectorAll('.wkrh-nav__item'));
        var panels = Array.prototype.slice.call(document.querySelectorAll('.wkrh-panel'));

        function activate(section) {
            if (!section) {
                return;
            }
            navItems.forEach(function (n) {
                n.classList.toggle('is-active', n.getAttribute('data-section') === section);
            });
            panels.forEach(function (p) {
                p.classList.toggle('is-active', p.getAttribute('data-section') === section);
            });
        }

        navItems.forEach(function (n) {
            n.addEventListener('click', function (e) {
                e.preventDefault();
                var section = n.getAttribute('data-section');
                activate(section);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#wkrh-section-' + section);
                }
            });
        });

        // Deep-link support: open the section named in the URL hash.
        var hashSection = (window.location.hash || '').replace('#wkrh-section-', '');
        if (hashSection && document.querySelector('.wkrh-panel[data-section="' + hashSection + '"]')) {
            activate(hashSection);
        }

        // Environment selector dims the location credential field that is not in use.
        var envSelect = document.getElementById('wk_rh_environment');
        var testField = document.getElementById('wk_rh_test_locations_json');
        var liveField = document.getElementById('wk_rh_live_locations_json');

        if (envSelect && testField && liveField) {
            var testWrap = testField.closest('.wkrh-field');
            var liveWrap = liveField.closest('.wkrh-field');

            var refresh = function () {
                var env = envSelect.value;
                if (testWrap) {
                    testWrap.classList.toggle('is-dimmed', env !== 'test');
                }
                if (liveWrap) {
                    liveWrap.classList.toggle('is-dimmed', env !== 'live');
                }
            };

            envSelect.addEventListener('change', refresh);
            refresh();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
