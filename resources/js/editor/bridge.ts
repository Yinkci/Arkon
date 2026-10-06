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
  let hoverId = null;
  let editing = null;
  const NBSP = new RegExp(String.fromCharCode(160), "g");

  const nodeEl = (id) => (id ? document.querySelector('[data-ak-id="' + CSS.escape(id) + '"]') : null);
  const rectOf = (el) => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { top: r.top, left: r.left, width: r.width, height: r.height };
  };
  const labelOf = (id) => nodeEl(id)?.getAttribute("data-ak-type") ?? null;
  const reportRects = () =>
    send({
      type: "rects",
      selected: selectedId ? { id: selectedId, label: labelOf(selectedId), rect: rectOf(nodeEl(selectedId)) } : null,
      hover: hoverId && hoverId !== selectedId ? { id: hoverId, label: labelOf(hoverId), rect: rectOf(nodeEl(hoverId)) } : null,
    });

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
    selectedId = node ? node.getAttribute("data-ak-id") : null;
    send({ type: "select", nodeId: selectedId });
    if (field && node && node.contains(field)) startEditing(field);
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
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
      event.preventDefault();
      send({ type: "shortcut", action: "save" });
      return;
    }
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
      stopEditing();
      document.getElementById("ak-page-css").textContent = msg.css;
      document.body.innerHTML = msg.body;
      markMultiline(msg.multiline);
      reportRects();
    } else if (msg.type === "select") {
      selectedId = msg.nodeId;
      nodeEl(selectedId)?.scrollIntoView({ block: "nearest" });
      reportRects();
    }
  });

  function markMultiline(fields) {
    for (const el of document.querySelectorAll("[data-ak-prop]")) {
      const type = el.closest("[data-ak-id]")?.getAttribute("data-ak-type");
      if ((fields[type] || []).includes(el.getAttribute("data-ak-prop"))) el.setAttribute("data-ak-multiline", "true");
    }
  }

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
[data-ak-type="column"]{outline:1px dashed rgba(79,70,229,.25);outline-offset:4px;min-height:3rem}
[data-ak-type="column"]:empty::before{content:"Empty column: select it, then add text, an image or a button";display:block;padding:1rem;font-size:.875rem;opacity:.5}
.ak-image__empty{display:block;padding:2rem 1rem;border:2px dashed #d4d4d8;border-radius:1rem;text-align:center}
.ak-image__empty::before{content:attr(data-ak-placeholder);opacity:.6}
`;
