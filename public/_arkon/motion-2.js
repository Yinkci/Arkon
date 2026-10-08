/*! Arkon motion runtime 2. Entrances start with the page (CSS); this holds back the ones still below the fold, unseen, until they come into view. Immutable: changes ship as motion-3. */
(function () {
    'use strict';
    var d = document;
    var h = d.documentElement;
    if (!('IntersectionObserver' in window) || !window.matchMedia || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var height = window.innerHeight;
    var waiting = [];
    Array.prototype.forEach.call(d.querySelectorAll('.ak-reveal'), function (el) {
        var r = el.getBoundingClientRect();
        // On screen: its entrance is already playing from the first paint. Leave it alone.
        if (r.bottom > 0 && r.top < height) return;
        // Below the fold: hold it back only while its entrance hasn't finished (unseen). A finished one,
        // or none at all (turned off on this screen), stays as it is.
        var running = el.getAnimations ? el.getAnimations() : [];
        for (var i = 0; i < running.length; i++) {
            if (running[i].playState !== 'finished') {
                el.classList.add('ak-wait');
                waiting.push(el);
                return;
            }
        }
    });
    if (!waiting.length) return;
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) show(entry.target, false);
        });
    });
    function show(el, now) {
        var i = waiting.indexOf(el);
        if (i < 0) return;
        waiting.splice(i, 1);
        io.unobserve(el);
        // Keyboard focus shows it at once; coming into view plays its entrance from the start.
        if (now) el.classList.add('ak-shown');
        el.classList.remove('ak-wait');
        if (!waiting.length) {
            io.disconnect();
            d.removeEventListener('focusin', onFocus, true);
        }
    }
    function onFocus(event) {
        for (var el = event.target; el && el !== h; el = el.parentElement) show(el, true);
    }
    waiting.forEach(function (el) {
        io.observe(el);
    });
    d.addEventListener('focusin', onFocus, true);
})();
