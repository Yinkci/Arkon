/**
 * Editor-only script injected into the sandboxed canvas iframe (never into
 * published or preview HTML). It maps pointer events to node ids, reports element
 * rectangles so the parent can draw selection chrome *outside* the page DOM, and
 * runs inline text editing. It talks to the parent only through postMessage.
 */
export const BRIDGE_SCRIPT = String.raw`(() => {
  "use strict";
  const send = (msg) => parent.postMessage(Object.assign({ source: "arkon-canvas" }, msg), "*");
  let selectedId = null;
  let selectedPart = null;
  let hoverId = null;
  let editing = null;
  let readOnly = false; // set by the parent, e.g. while a draft needs repair
  const NBSP = new RegExp(String.fromCharCode(160), "g");

  const nodeEl = (id) => (id ? document.querySelector('[data-ak-id="' + CSS.escape(id) + '"]') : null);
  const rectOf = (el) => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { top: r.top, left: r.left, width: r.width, height: r.height };
  };
  const labelOf = (id) => nodeEl(id)?.getAttribute("data-ak-type") ?? null;
  // A part (style slot) of a component: the element marked data-ak-part inside that component (not a nested one).
  const partEl = (id, part) => {
    const node = nodeEl(id);
    if (!node || !part || part === "root") return null;
    for (const el of node.querySelectorAll('[data-ak-part="' + CSS.escape(part) + '"]')) {
      if (el.closest("[data-ak-id]") === node) return el;
    }
    return null;
  };
  // Empty columns get an "Add block" target drawn by the editor over the canvas.
  const emptyColumns = () => {
    const out = [];
    for (const el of document.querySelectorAll('[data-ak-type="column"]')) {
      if (!el.querySelector("[data-ak-id]")) out.push({ id: el.getAttribute("data-ak-id"), rect: rectOf(el) });
    }
    return out;
  };
  const reportRects = () =>
    measuring === null && send({
      type: "rects",
      selected: selectedId ? { id: selectedId, label: labelOf(selectedId), rect: rectOf(nodeEl(selectedId)), part: selectedPart, partRect: rectOf(partEl(selectedId, selectedPart)) } : null,
      hover: hoverId && hoverId !== selectedId ? { id: hoverId, label: labelOf(hoverId), rect: rectOf(nodeEl(hoverId)) } : null,
      empty: emptyColumns(),
    });

  // ── Animation replay: an explicit preview only. The canvas otherwise always shows the final
  // state (editor CSS: .ak-anim:not(.ak-replay) has no animation); any edit, click, key, drag or
  // render stops it, so it never interferes with editing or drag geometry.
  let replaying = [];
  let replayTimer = null;
  function stopReplay() {
    for (const el of replaying) el.classList.remove("ak-replay");
    replaying = [];
    clearTimeout(replayTimer);
  }
  function replay(id) {
    stopReplay();
    const el = nodeEl(id);
    if (!el) return;
    const targets = [el, ...el.querySelectorAll(".ak-anim")].filter((t) => t.classList.contains("ak-anim"));
    if (targets.length === 0) return;
    el.scrollIntoView({ block: "nearest" });
    void document.body.offsetWidth; // restart from the first frame
    for (const t of targets) t.classList.add("ak-replay");
    replaying = targets;
    // Delay is bounded at 2 s and duration at 4 s; let the longest preview finish.
    replayTimer = setTimeout(stopReplay, 6500);
  }
  document.addEventListener("animationend", (event) => {
    const el = event.target;
    if (el instanceof Element && replaying.includes(el)) {
      el.classList.remove("ak-replay");
      replaying = replaying.filter((t) => t !== el);
    }
  });
  document.addEventListener("pointerdown", stopReplay, true);

  // The page root (<main>) is not a selectable section.
  const closestNode = (target) => {
    const el = target instanceof Element ? target.closest("[data-ak-id]") : null;
    return el && el.getAttribute("data-ak-type") !== "page" ? el : null;
  };

  function stopEditing() {
    if (!editing) return;
    editing.removeAttribute("contenteditable");
    editing = null;
  }

  function startEditing(el) {
    if (editing === el) return;
    stopEditing();
    editing = el;
    el.setAttribute("contenteditable", "plaintext-only");
    el.focus();
  }

  document.addEventListener("click", (event) => {
    event.preventDefault(); // links and buttons inside the canvas never navigate
    const node = closestNode(event.target);
    const field = event.target instanceof Element ? event.target.closest("[data-ak-prop]") : null;
    // The part clicked (an image, a heading, the content area), if it belongs to the selected component.
    const partHit = event.target instanceof Element ? event.target.closest("[data-ak-part]") : null;
    selectedId = node ? node.getAttribute("data-ak-id") : null;
    selectedPart = node && partHit && partHit.closest("[data-ak-id]") === node ? partHit.getAttribute("data-ak-part") : null;
    send({ type: "select", nodeId: selectedId, part: selectedPart });
    if (field && node && node.contains(field) && !readOnly) startEditing(field);
    else stopEditing();
    reportRects();
  }, true);

  document.addEventListener("mousemove", (event) => {
    const node = closestNode(event.target);
    const id = node ? node.getAttribute("data-ak-id") : null;
    if (id !== hoverId) { hoverId = id; reportRects(); }
  });
  document.addEventListener("mouseleave", () => { hoverId = null; reportRects(); });

  document.addEventListener("input", (event) => {
    const el = event.target;
    if (!(el instanceof HTMLElement) || el !== editing) return;
    const node = el.closest("[data-ak-id]");
    const multiline = el.getAttribute("data-ak-multiline") === "true";
    let value = el.innerText.replace(NBSP, " ");
    if (!multiline) value = value.replace(/[\r\n]+/g, " ");
    send({ type: "edit", nodeId: node.getAttribute("data-ak-id"), prop: el.getAttribute("data-ak-prop"), value });
    reportRects();
  });

  document.addEventListener("keydown", (event) => {
    stopReplay();
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
      event.preventDefault();
      send({ type: "shortcut", action: "save" });
      return;
    }
    // Delete/Backspace delete the selection, unless typing (then the keys edit text).
    if ((event.key === "Delete" || event.key === "Backspace") && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && !editing && selectedId && !readOnly) {
      event.preventDefault();
      send({ type: "shortcut", action: "delete" });
      return;
    }
    // Duplicate the selected block, unless typing (then the key is the browser's).
    if ((event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && event.key.toLowerCase() === "d" && !editing && selectedId && !readOnly) {
      event.preventDefault();
      send({ type: "shortcut", action: "duplicate" });
      return;
    }
    // Escape also ends a drag in progress in the editor (keys go here while the canvas has focus).
    if (event.key === "Escape") send({ type: "shortcut", action: "escape" });
    if (!editing) return;
    if (event.key === "Escape") { stopEditing(); return; }
    if (event.key === "Enter" && editing.getAttribute("data-ak-multiline") !== "true") {
      event.preventDefault();
      stopEditing();
    }
  });
  document.addEventListener("focusout", (event) => { if (event.target === editing) stopEditing(); });

  window.addEventListener("message", (event) => {
    if (event.source !== parent || !event.data || event.data.source !== "arkon-editor") return;
    const msg = event.data;
    // The parent may hydrate after this script announced itself; it pings, we answer.
    if (msg.type === "ping") {
      send({ type: "ready" });
    } else if (msg.type === "render") {
      stopReplay();
      stopEditing();
      renderToken = msg.token ?? null;
      document.getElementById("ak-page-css").textContent = msg.css;
      document.body.innerHTML = msg.body;
      observer.disconnect();
      observed = new WeakSet();
      markMultiline(msg.multiline);
      reportRects();
      if (measuring !== null) measure();
    } else if (msg.type === "mode") {
      readOnly = msg.readOnly === true;
      if (readOnly) stopEditing();
    } else if (msg.type === "replay") {
      if (!readOnly) replay(msg.nodeId);
    } else if (msg.type === "stop-replay") {
      stopReplay();
    } else if (msg.type === "measure") {
      stopReplay();
      // A drag started (driven by the parent): report every block's geometry now, and again
      // whenever layout changes, until "unmeasure". The parent resolves drops from it locally.
      measuring = msg.session;
      measure();
    } else if (msg.type === "unmeasure") {
      if (msg.session === measuring) { measuring = null; reportRects(); }
    } else if (msg.type === "scroll-to") {
      window.scrollTo(msg.x, msg.y);
      scrollSeq = msg.seq;
      scrolled();
    } else if (msg.type === "select") {
      const moved = msg.nodeId !== selectedId;
      if (moved) stopReplay();
      selectedId = msg.nodeId;
      selectedPart = msg.part ?? null;
      if (moved) nodeEl(selectedId)?.scrollIntoView({ block: "nearest" });
      else partEl(selectedId, selectedPart)?.scrollIntoView({ block: "nearest" });
      reportRects();
    }
  });

  // ── Geometry for dragging ──
  // One snapshot of every block (document coordinates, so scrolling never invalidates it) with
  // the real layout of each container's children: axis, reversed order, wrapping, content box.
  let measuring = null;
  let renderToken = null;
  let generation = 0;
  let measureQueued = false;
  let scrollQueued = false;
  let scrollSeq = 0;
  // Each element is observed once per render (observing fires the callback once by itself).
  let observed = new WeakSet();
  const observer = new ResizeObserver(() => queueMeasure());
  function queueMeasure() {
    if (measuring === null || measureQueued) return;
    measureQueued = true;
    requestAnimationFrame(() => { measureQueued = false; if (measuring !== null) measure(); });
  }
  function layoutOf(el) {
    let holder = el;
    for (const child of el.querySelectorAll("[data-ak-id]")) {
      if (child.parentElement && child.parentElement.closest("[data-ak-id]") === el) { holder = child.parentElement; break; }
    }
    const cs = getComputedStyle(holder);
    let axis = "y", reversed = false, wrap = false;
    if (cs.display === "flex" || cs.display === "inline-flex") {
      axis = cs.flexDirection.indexOf("row") === 0 ? "x" : "y";
      reversed = cs.flexDirection.indexOf("reverse") > 0;
      wrap = axis === "x" && cs.flexWrap !== "nowrap";
    } else if (cs.display === "grid" || cs.display === "inline-grid") {
      const tracks = cs.gridTemplateColumns.split(" ").filter(Boolean).length;
      axis = tracks > 1 ? "x" : "y";
      wrap = tracks > 1;
    }
    const r = holder.getBoundingClientRect();
    const px = (v) => parseFloat(v) || 0;
    const box = {
      top: r.top + scrollY + px(cs.paddingTop) + px(cs.borderTopWidth),
      left: r.left + scrollX + px(cs.paddingLeft) + px(cs.borderLeftWidth),
      width: Math.max(0, r.width - px(cs.paddingLeft) - px(cs.paddingRight) - px(cs.borderLeftWidth) - px(cs.borderRightWidth)),
      height: Math.max(0, r.height - px(cs.paddingTop) - px(cs.paddingBottom) - px(cs.borderTopWidth) - px(cs.borderBottomWidth)),
    };
    return { axis, reversed, wrap, box };
  }
  function measure() {
    if (!observed.has(document.documentElement)) { observed.add(document.documentElement); observer.observe(document.documentElement); }
    const nodes = [];
    for (const el of document.querySelectorAll("[data-ak-id]")) {
      if (!observed.has(el)) { observed.add(el); observer.observe(el); }
      const r = el.getBoundingClientRect();
      nodes.push({
        id: el.getAttribute("data-ak-id"),
        rect: { top: r.top + scrollY, left: r.left + scrollX, width: r.width, height: r.height },
        layout: layoutOf(el),
      });
    }
    const root = document.scrollingElement || document.documentElement;
    send({
      type: "geometry", session: measuring, generation: ++generation, renderToken, seq: scrollSeq, nodes,
      scrollX, scrollY, maxScrollX: root.scrollWidth - innerWidth, maxScrollY: root.scrollHeight - innerHeight,
    });
  }
  function scrolled() {
    if (measuring === null || scrollQueued) return;
    scrollQueued = true;
    requestAnimationFrame(() => { scrollQueued = false; if (measuring !== null) send({ type: "scrolled", session: measuring, seq: scrollSeq, scrollX, scrollY }); });
  }
  addEventListener("scroll", scrolled, { passive: true });

  function markMultiline(fields) {
    for (const el of document.querySelectorAll("[data-ak-prop]")) {
      const type = el.closest("[data-ak-id]")?.getAttribute("data-ak-type");
      if ((fields[type] || []).includes(el.getAttribute("data-ak-prop"))) el.setAttribute("data-ak-multiline", "true");
    }
  }

  // The window losing focus while the canvas has it (switching applications) ends a drag too.
  window.addEventListener("blur", () => send({ type: "blur" }));
  window.addEventListener("scroll", reportRects, { passive: true });
  window.addEventListener("resize", reportRects);
  new ResizeObserver(reportRects).observe(document.documentElement);
  send({ type: "ready" });
})();`;

/** Editor-only affordances inside the canvas. Kept out of component CSS on purpose. */
export const CANVAS_CSS = `
[data-ak-prop]{cursor:text}
[data-ak-prop]:empty::before{content:attr(data-ak-placeholder);opacity:.45}
[contenteditable]{outline:none}
[data-ak-type]:not([data-ak-type="page"]){cursor:default}
[data-ak-type="column"]{outline:1px dashed rgba(48,71,209,.3);outline-offset:4px;min-height:3rem}
[data-ak-type="column"]:empty{min-height:5.5rem;background:repeating-linear-gradient(-45deg,rgba(48,71,209,.04) 0 8px,transparent 8px 16px);border-radius:.375rem}
[data-ak-type="column"]:empty::before{content:"Empty column";display:block;padding:.5rem .75rem;font-size:.75rem;opacity:.55}
.ak-image__empty{display:block;padding:2rem 1rem;border:2px dashed #d4d4d8;border-radius:1rem;text-align:center}
.ak-image__empty::before{content:attr(data-ak-placeholder);opacity:.6}
`;
