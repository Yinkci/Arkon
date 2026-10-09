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
