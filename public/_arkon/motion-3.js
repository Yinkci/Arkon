/*! Arkon motion runtime 3. Entrances start with the page (CSS); this holds back the ones still below the fold, unseen, until they come into view, and shows at once, for good, every entrance around keyboard focus. Immutable: changes ship as motion-4. */
(function () {
    'use strict';
    var d = document;
    var h = d.documentElement;
    if (!window.matchMedia || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var waiting = [];
    var io = null;
    // Focus inside a block shows it, and every animated block around it, at once and for good (CSS
    // :focus-within only lasts while focus stays): whatever the trigger, delay or progress.
    function onFocus(event) {
        for (var el = event.target; el && el !== h; el = el.parentElement) {
            if (el.classList.contains('ak-anim')) {
                el.classList.add('ak-shown');
                release(el);
            }
        }
    }
    function release(el) {
        var i = waiting.indexOf(el);
        if (i < 0) return;
        waiting.splice(i, 1);
        io.unobserve(el);
        el.classList.remove('ak-wait');
        if (!waiting.length) io.disconnect();
    }
    d.addEventListener('focusin', onFocus, true);
    if (d.activeElement) onFocus({ target: d.activeElement });
    if (!('IntersectionObserver' in window)) return;
    var height = window.innerHeight;
    Array.prototype.forEach.call(d.querySelectorAll('.ak-reveal:not(.ak-shown)'), function (el) {
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
    // Coming into view plays its entrance from the start.
    io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) release(entry.target);
        });
    });
    waiting.forEach(function (el) {
        io.observe(el);
    });
})();
