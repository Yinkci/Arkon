/*! Arkon motion runtime 1. Reveals blocks marked .ak-reveal once, when they enter the viewport. Immutable: changes ship as motion-2. */
(function () {
    'use strict';
    var d = document;
    var h = d.documentElement;
    if (!('IntersectionObserver' in window) || !window.matchMedia || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var pending = Array.prototype.slice.call(d.querySelectorAll('.ak-reveal'));
    if (!pending.length) return;
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) finish(entry.target, 'ak-in');
        });
    });
    function finish(el, state) {
        var i = pending.indexOf(el);
        if (i < 0) return;
        pending.splice(i, 1);
        io.unobserve(el);
        el.classList.add(state);
        if (!pending.length) {
            io.disconnect();
            d.removeEventListener('focusin', onFocus, true);
        }
    }
    // Keyboard focus inside a block that has not been revealed yet shows it at once.
    function onFocus(event) {
        for (var el = event.target; el && el !== h; el = el.parentElement) finish(el, 'ak-static');
    }
    // Blocks already on screen when this runs are shown as they are (never hidden after being seen).
    var height = window.innerHeight;
    pending.slice().forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.bottom > 0 && r.top < height) finish(el, 'ak-static');
        else io.observe(el);
    });
    if (pending.length) {
        d.addEventListener('focusin', onFocus, true);
        h.classList.add('ak-reveal-on');
    }
})();
