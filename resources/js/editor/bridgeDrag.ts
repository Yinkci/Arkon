/** Editor-only feedback inside the opaque canvas; no document operations or persistence. */
export const BRIDGE_DRAG_SCRIPT = String.raw`
  const dragFeedback = new Map();
  let dragSourceNode = null;
  let animateNextDragRender = false;
  const dragAnimations = [];
  function stopDragAnimations(){for(const a of dragAnimations)a.cancel();dragAnimations.length=0;}
  function clearDragFeedback() {
    for (const [el, saved] of dragFeedback) { el.style.transition="none"; el.style.translate=saved.translate; void el.offsetWidth; el.style.transition=saved.transition; }
    dragFeedback.clear();
  }
  function pauseDragFeedback() { for (const [el,saved] of dragFeedback) {el.style.transition='none';el.style.translate=saved.translate;} }
  function resumeDragFeedback() { for (const [el,saved] of dragFeedback) {el.style.translate=saved.offset;el.style.transition="translate 160ms ease-out";} }
  function showDragFeedback(ids, axis, reversed) {
    clearDragFeedback();
    if(matchMedia('(prefers-reduced-motion: reduce)').matches)return;
    for (const id of ids) {
      const el=nodeEl(id); if(!el || id===dragSourceNode) continue;
      const delta=reversed?-10:10;
      const offset=axis==='x'?delta+'px 0px':'0px '+delta+'px';
      dragFeedback.set(el,{translate:el.style.translate,transition:el.style.transition,offset});
      el.style.transition='translate 160ms ease-out'; el.style.translate=offset;
    }
  }
  function dragSnapshot(id) {
    const el=nodeEl(id);if(!el)return null;
    const r=el.getBoundingClientRect(),clone=el.cloneNode(true),cs=getComputedStyle(el);
    // The dragged subtree may inherit typography from the page's surrounding layout.
    for(const key of ['fontFamily','fontSize','fontWeight','lineHeight','color','textAlign'])clone.style[key]=cs[key];
    clone.style.margin='0';
    clone.querySelectorAll('script,iframe,object,embed,style').forEach(e=>e.remove());
    clone.querySelectorAll('[contenteditable]').forEach(e=>e.removeAttribute('contenteditable'));
    clone.querySelectorAll('input,button,select,textarea').forEach(e=>e.disabled=true);
    const html=clone.outerHTML,css=document.getElementById('ak-page-css').textContent;
    if(html.length>160000||css.length>500000)return null;
    return {html,css,rect:{left:r.left,top:r.top,width:Math.max(1,r.width),height:Math.max(1,r.height)}};
  }
  function captureDragRects() {
    clearDragFeedback(); stopDragAnimations();
    return new Map(Array.from(document.querySelectorAll('[data-ak-id]')).map(el=>[el.dataset.akId,el.getBoundingClientRect()]));
  }
  function animateDragRender(before) {
    if(!before || matchMedia('(prefers-reduced-motion: reduce)').matches)return;
    // Animate highest changed ancestors only; nested translations must not compound.
    const animated=new Set();
    for(const el of document.querySelectorAll('[data-ak-id]')) {
      const old=before.get(el.dataset.akId),r=el.getBoundingClientRect();
      if(!old || Math.hypot(old.left-r.left,old.top-r.top)<1)continue;
      let parent=el.parentElement,skip=false;
      while(parent){if(animated.has(parent)){skip=true;break;}parent=parent.parentElement;}
      if(skip)continue;
      animated.add(el);
      dragAnimations.push(el.animate([{translate:(old.left-r.left)+'px '+(old.top-r.top)+'px'},{translate:'0px 0px'}],{duration:160,easing:'ease-out'}));
    }
  }
`;
