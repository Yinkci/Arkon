// Arkon components-5: immutable, page-local progressive enhancement; no dependencies.
(() => {
    'use strict';
// Shared responsive slider resolution: base -> tablet -> mobile, using validated options only.
function applySliderScreen(root) {
 const spec=JSON.parse(root.dataset.sliderResponsive), value={...spec.base};
 for(const bp of (matchMedia('(max-width:599px)').matches ? ['tablet','mobile'] : matchMedia('(max-width:899px)').matches ? ['tablet'] : []))
  for(const [key,v] of Object.entries(spec[bp]||{})) if(v!=='inherit') value[key]=(v==='true'?true:v==='false'?false:v);
 const prefixes={pagination:'',controlsAlign:'controls-',controlsTone:'tone-',arrowPlacement:'arrows-',paginationAlign:'pagination-',paginationPosition:'pagination-',arrowAppearance:'arrowAppearance-',arrowShape:'arrowShape-',arrowButtonSize:'arrowButtonSize-',arrowIconSize:'arrowIconSize-',arrowGap:'arrowGap-',arrowHorizontalOffset:'arrowHorizontalOffset-',arrowVerticalOffset:'arrowVerticalOffset-',groupedArrowPosition:'groupedArrowPosition-'};
 for(const [key,prefix] of Object.entries(prefixes)) {
  const options=key==='pagination'?['numbers','dots','bars','none']:key==='paginationAlign'?['start','center','end']:key==='paginationPosition'?['top','bottom']:null;
  for(const c of [...root.classList]) if(options?options.includes(c.replace('ak-slider--'+prefix,'')):c.startsWith('ak-slider--'+prefix)) root.classList.remove(c);
  root.classList.add('ak-slider--'+prefix+value[key]);
 }
 root.classList.toggle('ak-slider--no-arrows',!value.arrows);
 root.classList.toggle('ak-slider--quiet-playback',!value.showPauseControl);
 for(const [key,attr] of Object.entries({autoplay:'autoplay',interval:'interval',pauseOnHover:'pauseHover',transition:'transition',transitionDuration:'transitionDuration'})) root.dataset[attr]=String(value[key]);
 const pause=root.querySelector('[data-slide-pause]'); if(pause)pause.hidden=!value.autoplay;
}
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    document.querySelectorAll('[data-arkon-slider]').forEach(root => {
        const slides = [...root.querySelector('.ak-slider__slides').children];
        if (!slides.length) return;
        let transitions = [];
        let index = 0, timer = null, paused = false, hovering = false, focused = false;
        if(root.dataset.sliderResponsive) applySliderScreen(root);
        let autoplay = root.dataset.autoplay === 'true';
        let pauseOnHover = root.dataset.pauseHover !== 'false';
        const controls = root.querySelector('.ak-slider__controls');
        const pause = root.querySelector('[data-slide-pause]');
        const dots = [...root.querySelectorAll('[data-slide-index]')];
        const status = root.querySelector('[data-slide-status]');
        const stop = () => { if (timer !== null) clearTimeout(timer); timer = null; };
        const schedule = () => {
            stop();
            root.dataset.playback = !autoplay ? 'off' : slides.length < 2 ? 'single-slide' : reduced.matches ? 'paused-reduced-motion' : document.hidden ? 'paused-hidden-tab' : paused ? 'paused' : focused ? 'paused-focus' : hovering ? 'paused-hover' : 'playing';
            if (pause) { pause.title = root.dataset.playback; pause.setAttribute('aria-label', paused ? 'Resume autoplay' : 'Pause autoplay'); }
            if (autoplay && !paused && !hovering && !focused && !document.hidden && !reduced.matches && slides.length > 1)
                timer = setTimeout(() => show(index + 1, false), Number(root.dataset.interval) || 7000);
        };
        function show(next, user) {
            const previous = index;
            transitions.forEach(animation => animation.cancel()); transitions = [];
            slides.forEach(slide => slide.removeAttribute('data-exiting'));
            index = (next + slides.length) % slides.length;
            slides.forEach((slide, i) => {
                slide.hidden = false;
                slide.inert = i !== index;
                slide.setAttribute('aria-hidden', String(i !== index));
                slide.dataset.active = i === index ? 'true' : 'false';
                slide.setAttribute('aria-label', 'Slide ' + (i + 1) + ' of ' + slides.length);
            });
            if (previous !== index && root.dataset.transition && root.dataset.transition !== 'none' && !reduced.matches) {
                const outgoing = slides[previous], incoming = slides[index];
                outgoing.dataset.exiting = 'true';
                outgoing.dataset.active = 'true';
                const duration = Number(root.dataset.transitionDuration) || 500;
                const direction = next < previous ? -1 : 1;
                const fade = root.dataset.transition === 'fade';
                const options = {duration, easing:'cubic-bezier(.22,.61,.36,1)'};
                const leaving = outgoing.animate(fade ? [{opacity:1},{opacity:0}] : [{transform:'translateX(0)'},{transform:'translateX('+(-direction*100)+'%)'}], options);
                const entering = incoming.animate(fade ? [{opacity:0},{opacity:1}] : [{transform:'translateX('+(direction*100)+'%)'},{transform:'translateX(0)'}], options);
                transitions = [leaving, entering];
                leaving.onfinish = () => { outgoing.dataset.active = 'false'; outgoing.removeAttribute('data-exiting'); };
            }
            dots.forEach((dot, i) => i === index ? dot.setAttribute('aria-current', 'true') : dot.removeAttribute('aria-current'));
            if (user) status.textContent = 'Slide ' + (index + 1) + ' of ' + slides.length;
            schedule();
        }
        root.dataset.enhanced = 'true';
        controls.hidden = slides.length < 2;
        root.addEventListener('click', event => {
            const button = event.target.closest('button');
            if (!button || !root.contains(button)) return;
            if (button.hasAttribute('data-slide-step')) show(index + Number(button.dataset.slideStep), true);
            if (button.hasAttribute('data-slide-index')) show(Number(button.dataset.slideIndex), true);
            if (button === pause) {
                paused = !paused; pause.textContent = paused ? 'Resume autoplay' : 'Pause autoplay';
                pause.setAttribute('aria-pressed', String(paused)); if (!paused) focused = false; schedule();
            }
        });
        root.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key) || event.target.matches('input,textarea,select,[contenteditable]')) return;
            event.preventDefault();
            // If focus is in slide content, move it to a persistent control before hiding that content.
            if (slides.some(slide => slide.contains(event.target))) {
                // Responsive controls can hide the first arrow. Never focus a
                // display:none button then make the still-focused slide inert.
                const target = [...root.querySelectorAll('.ak-slider__controls button,.ak-slider__pagination button')]
                    .find(button => !button.disabled && button.getClientRects().length && getComputedStyle(button).visibility !== 'hidden');
                if (target) target.focus();
                else { root.setAttribute('tabindex', '-1'); root.focus(); }
            }
            show(index + (event.key === 'ArrowRight' ? 1 : -1), true);
        });
        let start = null;
        root.addEventListener('pointerdown', event => { if (event.pointerType === 'touch') start = { x: event.clientX, y: event.clientY }; }, { passive: true });
        root.addEventListener('pointerup', event => {
            if (!start) return;
            const dx = event.clientX - start.x, dy = event.clientY - start.y; start = null;
            if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) show(index + (dx < 0 ? 1 : -1), true);
        }, { passive: true });
        root.addEventListener('pointercancel', () => { start = null; });
        root.addEventListener('mouseenter', () => { if (pauseOnHover) { hovering = true; schedule(); } });
        root.addEventListener('mouseleave', () => { hovering = false; schedule(); });
        root.addEventListener('focusin', () => { focused = true; stop(); });
        root.addEventListener('focusout', event => { focused = root.contains(event.relatedTarget); schedule(); });
        document.addEventListener('visibilitychange', schedule);
        reduced.addEventListener('change', schedule);
        if(root.dataset.sliderResponsive) { const update=()=>{applySliderScreen(root);autoplay=root.dataset.autoplay==='true';pauseOnHover=root.dataset.pauseHover!=='false';if(!pauseOnHover)hovering=false;schedule();}; for(const q of ['(max-width:899px)','(max-width:599px)']) matchMedia(q).addEventListener('change',update); }
        show(0, false);
    });
    document.querySelectorAll('[data-arkon-top]').forEach(link => {
        link.addEventListener('click', event => {
            event.preventDefault();
            scrollTo({ top: 0, behavior: reduced.matches ? 'instant' : 'smooth' });
            const heading = document.querySelector('main h1,main');
            if (heading) { if (!heading.hasAttribute('tabindex')) heading.setAttribute('tabindex', '-1'); heading.focus({ preventScroll: true }); }
        });
    });
})();
