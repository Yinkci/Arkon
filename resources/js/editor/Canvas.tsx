import { useEffect, useMemo, useRef, useState } from 'react';
import { componentName, resolvePart } from '@/arkon/editor/parts';
import { resolveSlot, scrollStep, type Geometry, type Indicator, type NodeGeometry } from '@/arkon/editor/placement';
import { canContain, nodeLabel } from '@/arkon/editor/structure';
import { removalOf } from '@/arkon/editor/remove';
import { Icon } from '@/Components/Icon';
import type { PageDocument } from '@/arkon/schema/document';
import { BRIDGE_SCRIPT, CANVAS_CSS } from './bridge';
import type { DragSource, DropZone, Session, DragSnapshot } from './drag/controller';
import { useDragController, useDragState } from './drag/DragProvider';

export type Viewport = 'desktop' | 'tablet' | 'mobile';
// Tablet and mobile widths fall inside the published breakpoints (899 px and 599 px); desktop is never narrower
// than 960 px, so a small editor window still shows the desktop layout (the canvas scrolls instead).
const VIEWPORT_WIDTH: Record<Viewport, string> = { desktop: '100%', tablet: '820px', mobile: '390px' };
const VIEWPORT_MIN_WIDTH: Record<Viewport, string | undefined> = { desktop: '960px', tablet: undefined, mobile: undefined };

type Rect = { top: number; left: number; width: number; height: number };

interface Box {
    id: string;
    label: string | null;
    rect: Rect | null;
    /** The selected part (style slot) and its element's rectangle, when a part other than the whole component is active. */
    part?: string | null;
    partRect?: Rect | null;
}

export interface CanvasProps {
    body: string;
    css: string;
    /** Inline fields that keep line breaks, by component type. */
    multiline: Record<string, string[]>;
    selectedId: string | null;
    /** The part of the selected component being edited (highlighted on the canvas). */
    selectedPart?: string | null;
    viewport: Viewport;
    /** Incremented when the document changed from outside the canvas, so the iframe must re-render. */
    renderToken: number;
    /** No inline editing or dragging (selection still works). */
    readOnly?: boolean;
    /** The document shown, for drop rules (drag and drop is off without it). */
    document?: PageDocument;
    /**
     * The document changed and the canvas has not been re-rendered yet: its geometry is stale,
     * so drags from the canvas wait and drops on it wait (briefly) for the new render.
     */
    renderPending?: boolean;
    /** A click on the canvas: the component, and the part clicked (an image, a heading …) when it has parts. */
    onSelect(nodeId: string | null, part?: string | null): void;
    onInlineEdit(nodeId: string, prop: string, value: string): void;
    onSaveShortcut(): void;
    /** Duplicates a block (the selection controls and Ctrl/Cmd+D while the canvas has focus). */
    onDuplicate?(nodeId: string): void;
    /** Deletes the selection (the selected block, or with only a hero's image selected, the image); Delete/Backspace too. */
    onDelete?(nodeId: string, part: string | null): void;
    /** Adds a block of `type` into an empty column (the canvas's "Add block" targets). */
    onAddInto?(parentId: string, type: string): void;
    /** An animation preview of a block, for document `version`; a new `seq` asks again. */
    replay?: { nodeId: string; seq: number; version: number; action?: 'slider-play' | 'slider-pause' } | null;
    /** The editor's current document version (previews for an older one are dropped). */
    documentVersion?: number;
}

/** What an empty column's "Add block" menu offers (what a column accepts, most common first). */
const ADD_INTO = [
    ['text', 'Text'],
    ['image', 'Image'],
    ['button', 'Button'],
    ['group', 'Group'],
] as const;

interface CanvasGeometry extends Geometry {
    session: number;
    generation: number;
    renderToken: number | null;
    maxScrollX: number;
    maxScrollY: number;
}

/**
 * The canvas is a sandboxed iframe (scripts allowed, no same-origin access) showing
 * the renderer's editor-mode HTML with the real page CSS. Selection chrome is drawn
 * in this document, over the iframe, so it never becomes part of the page DOM.
 *
 * Dragging: the canvas is a drop zone of the editor's drag controller. When a drag starts it
 * asks the bridge for a geometry snapshot (every block's rectangle in page coordinates and each
 * container's real layout); the bridge sends a new one whenever layout changes. Destinations
 * are resolved here, synchronously, from that snapshot and the scroll position this side
 * controls, so nothing waits for a reply per pointer move and a release always uses its own
 * coordinates. Automatic scrolling and indicators run in the controller's animation frame.
 */
export function Canvas(props: CanvasProps) {
    const frameRef = useRef<HTMLIFrameElement>(null);
    const stageRef = useRef<HTMLDivElement>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const indicatorRef = useRef<HTMLDivElement>(null);
    const indicatorLabelRef = useRef<HTMLSpanElement>(null);
    const sourceRef = useRef<HTMLDivElement>(null);
    const feedbackKey = useRef('');
    const [ready, setReady] = useState(false);
    const [boxes, setBoxes] = useState<{ selected: Box | null; hover: Box | null; empty: { id: string; rect: Rect }[] }>({
        selected: null,
        hover: null,
        empty: [],
    });
    const [addMenu, setAddMenu] = useState<string | null>(null);
    const overHoverHandle = useRef(false);
    const latest = useRef(props);
    useEffect(() => {
        latest.current = props;
    });
    const controller = useDragController();
    const drag = useDragState();

    // srcdoc is built once; later updates are posted to the bridge, which keeps scroll position.
    const srcDoc = useMemo(() => buildSrcDoc(), []);
    const post = (message: Record<string, unknown>) => frameRef.current?.contentWindow?.postMessage({ source: 'arkon-editor', ...message }, '*');

    // ── The drop zone (state in refs: it changes every frame while dragging) ──
    const zone = useRef<{
        session: number | null;
        geometry: CanvasGeometry | null;
        scroll: { x: number; y: number };
        sentSeq: number;
        waiters: ((ok: boolean) => void)[];
    }>({ session: null, geometry: null, scroll: { x: 0, y: 0 }, sentSeq: 0, waiters: [] });

    const visibleRect = (): DOMRect | null => {
        const frame = frameRef.current?.getBoundingClientRect();
        const stage = stageRef.current?.getBoundingClientRect();
        if (!frame || !stage) return null;
        const left = Math.max(frame.left, stage.left);
        const top = Math.max(frame.top, stage.top);
        const right = Math.min(frame.right, stage.right);
        const bottom = Math.min(frame.bottom, stage.bottom);
        return right > left && bottom > top ? new DOMRect(left, top, right - left, bottom - top) : null;
    };
    const current = () => {
        const g = zone.current.geometry;
        return g !== null && g.session === zone.current.session && g.renderToken === latest.current.renderToken && !latest.current.renderPending;
    };
    const settle = () => {
        if (!current()) return;
        const waiters = zone.current.waiters.splice(0);
        waiters.forEach((resolve) => resolve(true));
    };

    useEffect(() => {
        const canvasZone: DropZone = {
            id: 'canvas',
            contains(x, y) {
                const r = visibleRect();
                return !!r && x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
            },
            resolve(source, x, y, previous) {
                const doc = latest.current.document;
                if (!doc || latest.current.readOnly) return { kind: 'invalid', reason: 'The canvas is read-only right now' };
                if (!current()) return 'pending';
                const frame = frameRef.current!.getBoundingClientRect();
                const { scroll } = zone.current;
                return resolveSlot(doc, source, zone.current.geometry!, x - frame.left + scroll.x, y - frame.top + scroll.y, previous);
            },
            autoscroll(x, y, dt) {
                const g = zone.current.geometry;
                const r = visibleRect();
                if (!g || !r) return false;
                let moved = false;
                if (x >= r.left && x <= r.right) {
                    const dy = scrollStep(y, r.top, r.bottom, zone.current.scroll.y, Math.max(0, g.maxScrollY), dt);
                    if (Math.abs(dy) >= 0.5) {
                        zone.current.scroll.y += dy;
                        post({ type: 'scroll-to', x: zone.current.scroll.x, y: Math.round(zone.current.scroll.y), seq: ++zone.current.sentSeq });
                        moved = true;
                    }
                }
                // The stage scrolls sideways when the canvas is wider than the editor (desktop on a small screen).
                const stage = stageRef.current;
                if (stage && stage.scrollWidth > stage.clientWidth && y >= r.top && y <= r.bottom) {
                    const s = stage.getBoundingClientRect();
                    const dx = scrollStep(x, s.left, s.right, stage.scrollLeft, stage.scrollWidth - stage.clientWidth, dt);
                    if (Math.abs(dx) >= 0.5) {
                        stage.scrollLeft += dx;
                        moved = true;
                    }
                }
                return moved;
            },
            frame(session) {
                paint(session);
            },
            begin(session) {
                zone.current.session = session.id;
                zone.current.geometry = null;
                post({ type: 'measure', session: session.id, nodeId: session.source.nodeId });
            },
            end() {
                feedbackKey.current = '';
                post({ type: 'unmeasure', session: zone.current.session });
                zone.current.session = null;
                zone.current.geometry = null;
                zone.current.waiters.splice(0).forEach((resolve) => resolve(false));
                if (indicatorRef.current) indicatorRef.current.style.display = 'none';
                if (sourceRef.current) sourceRef.current.style.display = 'none';
            },
            ready(timeoutMs) {
                if (current()) return Promise.resolve(true);
                return new Promise((resolve) => {
                    const timer = setTimeout(() => {
                        zone.current.waiters = zone.current.waiters.filter((w) => w !== done);
                        resolve(false);
                    }, timeoutMs);
                    const done = (ok: boolean) => {
                        clearTimeout(timer);
                        resolve(ok);
                    };
                    zone.current.waiters.push(done);
                });
            },
        };
        return controller.registerZone(canvasZone);
        // The zone reads everything through refs.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [controller]);

    useEffect(() => controller.onFinish((_session, committed) => post({ type: 'drop-feedback', committed })), [controller]);

    // A render finished while dragging: the geometry that follows it makes the zone current again.
    useEffect(() => {
        settle();
        controller.invalidate();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [props.renderPending, props.renderToken]);

    /** Indicators follow scrolling every frame, without React renders. */
    const paint = (session: Session) => {
        const indicator = indicatorRef.current;
        const source = sourceRef.current;
        const frame = frameRef.current?.getBoundingClientRect();
        const container = containerRef.current?.getBoundingClientRect();
        const g = zone.current.geometry;
        if (!indicator || !source || !frame || !container || !g) return;
        const offsetX = frame.left - container.left - zone.current.scroll.x;
        const offsetY = frame.top - container.top - zone.current.scroll.y;
        const place = (el: HTMLElement, r: Rect) => {
            el.style.display = 'block';
            el.style.transform = `translate(${Math.round(r.left + offsetX)}px, ${Math.round(r.top + offsetY)}px)`;
            el.style.width = `${Math.max(2, Math.round(r.width))}px`;
            el.style.height = `${Math.max(2, Math.round(r.height))}px`;
        };
        const moved: NodeGeometry | undefined = session.source.nodeId ? g.nodes[session.source.nodeId] : undefined;
        if (moved) place(source, moved.rect);
        else source.style.display = 'none';
        const result = session.result;
        const key = session.zone === 'canvas' && result && result !== 'pending' && result.kind === 'place' ? result.parentId + ':' + result.index : '';
        if (key !== feedbackKey.current) {
            feedbackKey.current = key;
            const place = key && result && result !== 'pending' && result.kind === 'place' ? result : null;
            const parent = place ? latest.current.document?.nodes[place.parentId] : null;
            const layout = place ? g.nodes[place.parentId]?.layout : null;
            post({
                type: 'drag-feedback',
                session: session.id,
                ids: parent?.children?.slice(place?.index ?? 0).filter((id) => id !== session.source.nodeId) ?? [],
                axis: layout?.axis ?? 'y',
                reversed: layout?.reversed ?? false,
            });
        }
        const shown: Indicator | undefined =
            session.zone === 'canvas' && result && result !== 'pending' ? (result.kind === 'invalid' ? result.indicator : result.indicator) : undefined;
        if (!shown) {
            indicator.style.display = 'none';
            return;
        }
        place(indicator, shown.rect);
        const kind = result && result !== 'pending' ? result.kind : 'none';
        indicator.dataset.kind = shown.kind;
        indicator.dataset.result = kind;
        indicator.dataset.position = shown.kind === 'inside' ? 'inside' : 'between';
        if (indicatorLabelRef.current) indicatorLabelRef.current.textContent = result && result !== 'pending' && result.kind !== 'invalid' ? result.label : '';
    };

    useEffect(() => {
        function onMessage(event: MessageEvent) {
            if (event.source !== frameRef.current?.contentWindow) return;
            const msg = event.data as { source?: string; type?: string; [key: string]: unknown };
            if (msg?.source !== 'arkon-canvas') return;
            const current = latest.current;
            switch (msg.type) {
                case 'ready':
                    setReady(true);
                    break;
                case 'select':
                    current.onSelect((msg.nodeId as string | null) ?? null, (msg.part as string | null) ?? null);
                    break;
                case 'edit':
                    current.onInlineEdit(msg.nodeId as string, msg.prop as string, msg.value as string);
                    break;
                case 'rects': {
                    const hover = msg.hover as Box | null;
                    // Moving from a hovered block onto its Move handle (outside the iframe) keeps the hover.
                    // While dragging, the handle that started it stays where it is (removing it would end the drag).
                    setBoxes((prev) => ({
                        selected: msg.selected as Box | null,
                        hover: controller.getState().phase !== 'idle' ? prev.hover : (hover ?? (overHoverHandle.current ? prev.hover : null)),
                        empty: (msg.empty as { id: string; rect: Rect }[] | undefined) ?? [],
                    }));
                    break;
                }
                case 'shortcut':
                    if (msg.action === 'save') current.onSaveShortcut();
                    else if (msg.action === 'escape') controller.cancel('escape');
                    else if (msg.action === 'duplicate' && current.selectedId && !current.readOnly) current.onDuplicate?.(current.selectedId);
                    else if (msg.action === 'delete' && current.selectedId && !current.readOnly)
                        current.onDelete?.(current.selectedId, current.selectedPart ?? null);
                    break;
                case 'blur':
                    // Focus moving from the canvas to the editor is not a blur of the window.
                    setTimeout(() => {
                        if (!document.hasFocus()) controller.cancel('blur');
                    }, 0);
                    break;
                case 'drag-preview': {
                    if (msg.session !== zone.current.session) break;
                    const preview = msg.preview as DragSnapshot;
                    const frame = frameRef.current?.getBoundingClientRect();
                    const session = controller.getState().session;
                    if (frame && session?.source.origin === 'canvas' && preview?.rect)
                        controller.setPreview(session.id, {
                            ...preview,
                            rect:
                                session.source.origin === 'canvas'
                                    ? { ...preview.rect, left: preview.rect.left + frame.left, top: preview.rect.top + frame.top }
                                    : session.origin.rect,
                        });
                    break;
                }
                case 'geometry': {
                    // Only for the drag in progress: replies to ended drags are ignored.
                    if (msg.session !== zone.current.session || msg.session === null) break;
                    // Messages can arrive late: never let an older snapshot replace a newer one.
                    if (zone.current.geometry && (msg.generation as number) <= zone.current.geometry.generation) break;
                    const nodes: Record<string, NodeGeometry> = {};
                    for (const node of msg.nodes as NodeGeometry[]) nodes[node.id] = node;
                    zone.current.geometry = {
                        session: msg.session as number,
                        generation: msg.generation as number,
                        renderToken: (msg.renderToken as number | null) ?? null,
                        nodes,
                        maxScrollX: msg.maxScrollX as number,
                        maxScrollY: msg.maxScrollY as number,
                    };
                    // Scroll commands not yet applied there win over the scroll position in the snapshot.
                    if (zone.current.sentSeq === 0 || (msg.seq as number | undefined) === zone.current.sentSeq) {
                        zone.current.scroll = { x: msg.scrollX as number, y: msg.scrollY as number };
                    }
                    settle();
                    controller.invalidate();
                    break;
                }
                case 'scrolled':
                    if (msg.session !== zone.current.session) break;
                    // Also scrolling by wheel during a drag; ignored while our own commands are in flight.
                    if (msg.seq === zone.current.sentSeq) {
                        zone.current.scroll = { x: msg.scrollX as number, y: msg.scrollY as number };
                        controller.invalidate();
                    }
                    break;
            }
        }
        window.addEventListener('message', onMessage);
        // If the iframe loaded before this listener existed, its "ready" was missed: ask again.
        frameRef.current?.contentWindow?.postMessage({ source: 'arkon-editor', type: 'ping' }, '*');
        return () => window.removeEventListener('message', onMessage);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [controller]);

    useEffect(() => {
        if (ready) post({ type: 'render', body: props.body, css: props.css, multiline: props.multiline, token: props.renderToken });
        // Only re-render the iframe for outside changes; inline edits are already visible there.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, props.renderToken]);

    useEffect(() => {
        if (ready) post({ type: 'mode', readOnly: props.readOnly === true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, props.readOnly]);

    // A preview plays once the canvas shows the render of the document it was asked for. It is dropped
    // when the document changed since (typing, structural edits, a newer setting asks again), when
    // another block is selected, during a drag, and in read-only states.
    const replayed = useRef(0);
    useEffect(() => {
        const replay = props.replay;
        if (!ready || !replay || replay.seq === replayed.current) return;
        const obsolete =
            replay.version !== props.documentVersion || replay.nodeId !== props.selectedId || props.readOnly || controller.getState().phase !== 'idle';
        if (obsolete) {
            replayed.current = replay.seq;
            return;
        }
        if (props.renderPending) return; // wait for the render that shows these settings
        replayed.current = replay.seq;
        post({ type: replay.action ?? 'replay', nodeId: replay.nodeId });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, props.replay, props.renderPending, props.renderToken, props.documentVersion, props.selectedId, props.readOnly]);

    useEffect(() => {
        if (ready) post({ type: 'select', nodeId: props.selectedId, part: props.selectedPart ?? null });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, props.selectedId, props.selectedPart]);

    const dragging = drag.phase !== 'idle';
    const canDrag = !props.readOnly && props.document !== undefined;
    const selected = boxes.selected?.rect && boxes.selected.id !== props.document?.root ? boxes.selected : null;
    const selectedNode = selected && props.document ? props.document.nodes[selected.id] : undefined;
    const hovered = boxes.hover?.rect && boxes.hover.id !== selected?.id && props.document?.nodes[boxes.hover.id] ? boxes.hover : null;
    const hoveredNode = hovered ? props.document!.nodes[hovered.id] : undefined;
    const nameOf = (box: Box) => {
        const node = props.document?.nodes[box.id];
        return node ? componentName(node) : box.label ? componentName({ type: box.label }) : '';
    };
    const activePart = selectedNode && selected?.partRect && selected.part ? resolvePart(selectedNode, selected.part) : null;
    const sourceFor = (nodeId: string): DragSource => {
        const node = props.document!.nodes[nodeId]!;
        return { nodeId, type: node.type, label: componentName(node) === node.type ? nodeLabel(node) : componentName(node), origin: 'canvas' };
    };
    // The selection controls, right to left: Move, Duplicate, Delete (each 32 px wide with its gap).
    const controls = (props.onDuplicate ? 1 : 0) + (props.onDelete ? 1 : 0);
    const moveLeft = (r: Rect) => Math.max(2 + 32 * controls, r.left + r.width - 150);
    const removal = selectedNode && props.document ? removalOf(props.document, selectedNode.id, props.selectedPart ?? null) : null;
    const startDrag = (nodeId: string) => (event: React.PointerEvent<HTMLButtonElement>) => {
        if (!canDrag || props.renderPending) return;
        event.preventDefault();
        controller.press(sourceFor(nodeId), event, event.currentTarget, () => latest.current.onSelect(nodeId));
    };

    return (
        <div
            ref={stageRef}
            className="flex h-full justify-center-safe overflow-x-auto overflow-y-hidden bg-canvas p-3 sm:p-4"
            data-testid="canvas-stage"
            data-selection-scope
        >
            <div
                ref={containerRef}
                className="relative h-full overflow-hidden rounded-md bg-white shadow-raise transition-[width] duration-200 ease-snap"
                style={{ width: VIEWPORT_WIDTH[props.viewport], minWidth: VIEWPORT_MIN_WIDTH[props.viewport] }}
                data-testid="canvas-frame"
            >
                <iframe
                    ref={frameRef}
                    title="Page canvas"
                    sandbox="allow-scripts"
                    srcDoc={srcDoc}
                    className={`h-full w-full border-0 ${dragging ? 'pointer-events-none' : ''}`}
                    data-testid="canvas"
                />
                <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden">
                    {!dragging && hovered && <Outline rect={hovered.rect!} label={nameOf(hovered)} tone="hover" />}
                    {!dragging && selected && <Outline rect={selected.rect!} label={nameOf(selected)} tone={activePart ? 'parent' : 'selected'} />}
                    {!dragging && selected && activePart && (
                        <Outline rect={selected.partRect!} label={activePart.label} tone="part" testId="canvas-part-highlight" />
                    )}
                    {/* What is being moved stays visible but dimmed, so its place in the page is clear. */}
                    <div
                        ref={sourceRef}
                        className="absolute top-0 left-0 hidden rounded-sm bg-white/60 outline-2 outline-dashed outline-[var(--ak-accent)]"
                        data-testid="drag-source"
                    />
                    <div
                        ref={indicatorRef}
                        data-testid="drop-indicator"
                        className="group/indicator absolute top-0 left-0 hidden rounded-full bg-[var(--ak-accent)] data-[kind=inside]:rounded-md data-[kind=inside]:bg-[color-mix(in_srgb,var(--ak-accent)_10%,transparent)] data-[kind=inside]:outline-2 data-[kind=inside]:outline-[var(--ak-accent)] data-[result=invalid]:bg-[color-mix(in_srgb,var(--ak-danger)_10%,transparent)] data-[result=invalid]:outline-2 data-[result=invalid]:outline-dashed data-[result=invalid]:outline-[var(--ak-danger)]"
                    >
                        <span
                            ref={indicatorLabelRef}
                            className="absolute -top-6 left-0 rounded-md bg-[var(--ak-accent)] px-1.5 py-0.5 text-3xs font-medium whitespace-nowrap text-[var(--ak-accent-fg)] empty:hidden"
                        />
                    </div>
                </div>
                {canDrag && hovered && hoveredNode && (
                    <MoveHandle
                        compact
                        label={`Move ${componentName(hoveredNode)}`}
                        rect={hovered.rect!}
                        onPointerDown={startDrag(hovered.id)}
                        onPointerEnter={() => (overHoverHandle.current = true)}
                        onPointerLeave={() => (overHoverHandle.current = false)}
                        pending={props.renderPending}
                        hidden={dragging}
                    />
                )}
                {canDrag && selected && selectedNode && (
                    <MoveHandle
                        label={`Move ${componentName(selectedNode)}`}
                        rect={selected.rect!}
                        onPointerDown={startDrag(selectedNode.id)}
                        pending={props.renderPending}
                        hidden={dragging}
                        testId="canvas-drag-handle"
                        minLeft={2 + 32 * controls}
                    />
                )}
                {canDrag && selected && selectedNode && props.onDuplicate && (
                    <button
                        type="button"
                        aria-label={`Duplicate ${componentName(selectedNode)}`}
                        title={`Duplicate ${componentName(selectedNode)} (Ctrl+D)`}
                        data-testid="canvas-duplicate"
                        disabled={props.renderPending}
                        onClick={() => props.onDuplicate!(selectedNode.id)}
                        className={`absolute z-10 grid h-7 w-7 place-items-center rounded-md bg-[var(--ak-surface)] text-[var(--ak-fg)] shadow-raise hover:bg-[var(--ak-raised)] disabled:cursor-progress disabled:opacity-70 ${dragging ? 'invisible' : ''}`}
                        // Just left of the Move handle (which keeps room for the controls at the canvas edge).
                        style={{ top: Math.max(2, selected.rect!.top - 30), left: moveLeft(selected.rect!) - 32 }}
                    >
                        <Icon name="copy" className="size-3.5" />
                    </button>
                )}
                {canDrag && selected && selectedNode && props.onDelete && removal && (
                    <button
                        type="button"
                        aria-label={removal.label}
                        title={removal.ok ? `${removal.label} (Delete key)` : removal.reason}
                        data-testid="canvas-delete"
                        disabled={props.renderPending || !removal.ok}
                        onClick={() => props.onDelete!(selectedNode.id, props.selectedPart ?? null)}
                        className={`absolute z-10 grid h-7 w-7 place-items-center rounded-md bg-[var(--ak-surface)] text-[var(--ak-danger)] shadow-raise hover:bg-[var(--ak-danger-soft)] disabled:cursor-not-allowed disabled:opacity-50 ${dragging ? 'invisible' : ''}`}
                        style={{ top: Math.max(2, selected.rect!.top - 30), left: moveLeft(selected.rect!) - 32 * controls }}
                    >
                        <Icon name="trash" className="size-3.5" />
                    </button>
                )}
                {canDrag && !dragging && props.onAddInto && props.document && (props.document.nodes[props.document.root]?.children?.length ?? 1) === 0 && (
                    <EmptyCanvas document={props.document} onAdd={(type) => props.onAddInto!(props.document!.root, type)} />
                )}
                {canDrag &&
                    !dragging &&
                    props.onAddInto &&
                    boxes.empty.map(({ id, rect }) => (
                        <div
                            key={id}
                            className="absolute z-10 flex flex-col items-center gap-1"
                            style={{ top: rect.top + Math.max(4, rect.height / 2 - 14), left: rect.left + rect.width / 2, transform: 'translateX(-50%)' }}
                        >
                            <button
                                type="button"
                                data-testid="empty-column-add"
                                aria-haspopup="menu"
                                aria-expanded={addMenu === id}
                                onClick={() => setAddMenu((open) => (open === id ? null : id))}
                                className="inline-flex h-7 items-center gap-1 rounded-md bg-[var(--ak-surface)] px-2.5 text-2xs font-medium whitespace-nowrap text-[var(--ak-accent)] shadow-raise hover:bg-[var(--ak-accent-soft)]"
                            >
                                <Icon name="plus" className="size-3" />
                                Add block
                            </button>
                            {addMenu === id && (
                                <div
                                    role="menu"
                                    aria-label="Add to this column"
                                    className="flex gap-0.5 rounded-lg bg-[var(--ak-surface)] p-1 shadow-pop"
                                    onKeyDown={(event) => event.key === 'Escape' && setAddMenu(null)}
                                >
                                    {ADD_INTO.map(([type, label]) => (
                                        <button
                                            key={type}
                                            type="button"
                                            role="menuitem"
                                            autoFocus={type === 'text'}
                                            onClick={() => {
                                                setAddMenu(null);
                                                props.onAddInto!(id, type);
                                            }}
                                            className="h-7 rounded-md px-2 text-2xs font-medium text-[var(--ak-fg)] hover:bg-[var(--ak-hover)]"
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
            </div>
        </div>
    );
}

/** What an empty page shows: where to start, with the first blocks one click away. */
function EmptyCanvas({ document: doc, onAdd }: { document: PageDocument; onAdd(type: string): void }) {
    const root = doc.nodes[doc.root]!;
    const choices = (
        [
            ['section', 'Add section'],
            ['hero', 'Add hero'],
            ['text', 'Add text'],
        ] as const
    ).filter(([type]) => canContain(root, type));
    return (
        <div className="absolute inset-0 z-10 grid place-items-center p-6" data-testid="canvas-empty">
            <div className="flex max-w-sm flex-col items-center rounded-lg border border-dashed border-[var(--ak-line-strong)] bg-[var(--ak-surface)] px-8 py-8 text-center">
                <span className="grid size-10 place-items-center rounded-lg bg-[var(--ak-accent-soft)] text-[var(--ak-accent)]">
                    <Icon name="plus" className="size-5" />
                </span>
                <p className="mt-4 text-sm font-semibold text-[var(--ak-fg)]">Start building your page</p>
                <p className="mt-1 text-xs text-[var(--ak-muted)]">Drag a block here from Layers, or start with one of these.</p>
                {choices.length > 0 && (
                    <div className="mt-5 flex flex-wrap justify-center gap-2">
                        {choices.map(([type, label], index) => (
                            <button
                                key={type}
                                type="button"
                                onClick={() => onAdd(type)}
                                className={`inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-medium transition-colors ${
                                    index === 0
                                        ? 'bg-[var(--ak-ink)] text-[var(--ak-ink-fg)] hover:bg-[var(--ak-ink-hover)]'
                                        : 'border border-[var(--ak-line-strong)] text-[var(--ak-fg)] hover:bg-[var(--ak-raised)]'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * The handle that moves a whole block (never one of its parts: a hero's image moves with its
 * hero). Comfortably sized, named for exactly what moves, and touch-friendly (touch-action:
 * none, so a touch drag on it moves the block instead of scrolling).
 */
function MoveHandle(props: {
    label: string;
    rect: Rect;
    compact?: boolean;
    pending?: boolean;
    hidden?: boolean;
    testId?: string;
    /** The leftmost position (room for the controls beside it). */
    minLeft?: number;
    onPointerDown(event: React.PointerEvent<HTMLButtonElement>): void;
    onPointerEnter?(): void;
    onPointerLeave?(): void;
}) {
    const top = Math.max(2, props.rect.top - (props.compact ? 22 : 30));
    return (
        <button
            type="button"
            tabIndex={-1}
            aria-label={props.label}
            title={props.pending ? 'Updating the canvas…' : `${props.label} (drag; or use Move up/down in Layers)`}
            data-testid={props.testId}
            disabled={props.pending}
            onPointerDown={props.onPointerDown}
            onPointerEnter={props.onPointerEnter}
            onPointerLeave={props.onPointerLeave}
            className={`absolute z-10 flex cursor-grab touch-none items-center gap-1 rounded-md font-medium shadow-hairline select-none active:cursor-grabbing disabled:cursor-progress disabled:opacity-70 ${
                props.compact
                    ? 'h-5 bg-[var(--ak-surface)] px-1.5 text-3xs text-[var(--ak-accent)] ring-1 ring-[var(--ak-accent-line)]'
                    : 'h-7 bg-[var(--ak-accent)] px-2 text-2xs text-[var(--ak-accent-fg)]'
            } ${props.hidden ? 'invisible' : ''}`}
            style={
                props.compact
                    ? { top, left: Math.max(2, props.rect.left) }
                    : { top, left: Math.max(props.minLeft ?? 2, props.rect.left + props.rect.width - 150), maxWidth: 148 }
            }
        >
            <Icon name="grip" className={props.compact ? 'size-3' : 'size-3.5'} />
            <span className="truncate">{props.label}</span>
        </button>
    );
}

/**
 * Selection chrome, drawn over the canvas (never inside the page). The selected component
 * gets a solid outline; an active part (e.g. its image) gets crop-mark corners and a tag
 * with its name and size, so it is clear exactly what the controls change.
 */
function Outline({ rect: r, label, tone, testId }: { rect: Rect; label: string; tone: 'hover' | 'selected' | 'parent' | 'part'; testId?: string }) {
    const border =
        tone === 'selected'
            ? 'border-2 border-[var(--ak-accent)]'
            : tone === 'part'
              ? 'border-2 border-[var(--ak-accent)] bg-[color-mix(in_srgb,var(--ak-accent)_8%,transparent)]'
              : tone === 'parent'
                ? 'border border-dashed border-[var(--ak-accent)]'
                : 'border border-[var(--ak-accent-line)]';
    const size = `${Math.round(r.width)} × ${Math.round(r.height)}`;
    return (
        <div className={`absolute ${border}`} style={{ top: r.top, left: r.left, width: r.width, height: r.height }} data-testid={testId} data-label={label}>
            {tone === 'part' &&
                (
                    [
                        '-top-1 -left-1 border-t-2 border-l-2',
                        '-top-1 -right-1 border-t-2 border-r-2',
                        '-bottom-1 -left-1 border-b-2 border-l-2',
                        '-bottom-1 -right-1 border-b-2 border-r-2',
                    ] as const
                ).map((corner) => <span key={corner} className={`absolute size-3 border-[var(--ak-accent)] ${corner}`} />)}
            {(tone === 'selected' || tone === 'part' || tone === 'parent') && label && (
                <span
                    className={`absolute left-0 flex h-5 items-center gap-1.5 rounded-sm px-1.5 text-3xs font-medium whitespace-nowrap shadow-hairline ${
                        tone === 'parent' ? 'bg-[var(--ak-surface)] text-[var(--ak-accent)]' : 'bg-[var(--ak-accent)] text-[var(--ak-accent-fg)]'
                    }`}
                    style={tone === 'part' ? { bottom: -22 } : { top: r.top < 22 ? 2 : -22 }}
                >
                    {label}
                    {tone !== 'parent' && <span className="tabular-nums opacity-70">{size}</span>}
                </span>
            )}
        </div>
    );
}

function buildSrcDoc(): string {
    return (
        '<!doctype html><html lang="en"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<style id="ak-page-css"></style>' +
        `<style>${CANVAS_CSS}</style>` +
        `</head><body><script>${BRIDGE_SCRIPT}</script></body></html>`
    );
}
