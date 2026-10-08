// Where a dragged block lands: the one placement model behind dragging on the canvas and in
// Layers. Pure and synchronous over a geometry snapshot (rectangles in document coordinates
// and each container's real layout), so a release resolves from its own coordinates at once;
// nothing waits for a reply from the canvas. Validity uses the same nesting rules as every
// other edit (placeAt), and the document validation checks the result again.
import { currentDefinition } from '../components/registry';
import { collectSubtree, findParent, type Node, type NodeId, type PageDocument } from '../schema/document';
import { componentName } from './parts';
import { canContain, nodeLabel, type Placement } from './structure';

export interface Rect {
    top: number;
    left: number;
    width: number;
    height: number;
}

/** How a container lays out its children, measured from the element that holds them. */
export interface Layout {
    /** Main axis: 'x' side by side, 'y' stacked. */
    axis: 'x' | 'y';
    /** row-reverse / column-reverse: document order runs against the visual direction. */
    reversed: boolean;
    /** Children form rows of several items (grid with 2+ tracks, or wrapping flex rows). */
    wrap: boolean;
    /** The box the children are laid out in (content box), for empty containers and line extents. */
    box: Rect;
}

export interface NodeGeometry {
    id: NodeId;
    rect: Rect;
    layout?: Layout;
}

export interface Geometry {
    nodes: Record<NodeId, NodeGeometry>;
}

export interface Dragged {
    nodeId?: NodeId;
    type: string;
}

export interface Indicator {
    /** A line between children, or the whole container highlighted (empty, or "into"). */
    kind: 'line' | 'inside';
    rect: Rect;
    axis: 'x' | 'y';
}

export type Resolution =
    | { kind: 'place'; parentId: NodeId; index: number; placement: Placement; label: string; indicator: Indicator }
    | { kind: 'noop'; parentId: NodeId; index: number; label: string; indicator: Indicator }
    | { kind: 'invalid'; reason: string; indicator?: Indicator };

export type PlaceCheck = { ok: true; placement: Placement } | { ok: false; noop: boolean; reason: string };

const label = (type: string) => currentDefinition(type)?.label ?? type;

/**
 * Whether `dragged` may be put in `parentId` at `index` (an index in the parent's current
 * children, i.e. counted with the dragged node still in place), and the resulting move or
 * insert placement. Refusals say why, in words.
 */
export function placeAt(doc: PageDocument, dragged: Dragged, parentId: NodeId, index: number): PlaceCheck {
    const parent = doc.nodes[parentId];
    if (!parent) return { ok: false, noop: false, reason: 'That place no longer exists' };
    if (dragged.nodeId && !doc.nodes[dragged.nodeId]) return { ok: false, noop: false, reason: 'The block being moved no longer exists' };
    if (!parent.children) return { ok: false, noop: false, reason: `${componentName(parent)} can't hold other blocks` };
    if (dragged.nodeId && collectSubtree(doc, dragged.nodeId).some((n) => n.id === parentId)) {
        return { ok: false, noop: false, reason: 'A block can’t go inside itself' };
    }
    const from = dragged.nodeId ? findParent(doc, dragged.nodeId) : null;
    const sameParent = from?.parentId === parentId;
    const definition = currentDefinition(parent.type);
    if (!definition || definition.children === false || !definition.children.allow.includes(dragged.type)) {
        return { ok: false, noop: false, reason: `${label(dragged.type)} can’t go in ${article(componentName(parent))}` };
    }
    if (!canContain(parent, dragged.type, sameParent ? 0 : 1)) {
        return { ok: false, noop: false, reason: `${componentName(parent)} is full (${definition.children.max} blocks at most)` };
    }
    if (dragged.nodeId && from && !sameParent) {
        const source = doc.nodes[from.parentId]!;
        const sourceDefinition = currentDefinition(source.type);
        const min = sourceDefinition && sourceDefinition.children !== false ? sourceDefinition.children.min : undefined;
        if (min !== undefined && (source.children?.length ?? 0) <= min) {
            return {
                ok: false,
                noop: false,
                reason: `${componentName(source)} needs at least ${min} ${min === 1 ? label(dragged.type).toLowerCase() : `${label(dragged.type).toLowerCase()}s`}`,
            };
        }
    }
    const count = parent.children.length;
    let at = Math.max(0, Math.min(index, count));
    // Move indices count positions after the node has left its old place.
    if (sameParent && from && from.index < at) at -= 1;
    if (sameParent && from && from.index === at) return { ok: false, noop: true, reason: 'Current position' };
    return { ok: true, placement: { parentId, index: at } };
}

function article(name: string) {
    return /^[AEIOU]/.test(name) ? `an ${name.toLowerCase()}` : `a ${name.toLowerCase()}`;
}

const contains = (r: Rect, x: number, y: number) => x >= r.left && x <= r.left + r.width && y >= r.top && y <= r.top + r.height;

function distance(r: Rect, x: number, y: number) {
    const dx = x < r.left ? r.left - x : x > r.left + r.width ? x - r.left - r.width : 0;
    const dy = y < r.top ? r.top - y : y > r.top + r.height ? y - r.top - r.height : 0;
    return Math.hypot(dx, dy);
}

function depthOf(doc: PageDocument, id: NodeId): number {
    let depth = 0;
    let location = findParent(doc, id);
    while (location) {
        depth++;
        location = findParent(doc, location.parentId);
    }
    return depth;
}

/** Pixels around a boundary inside which the previous slot is kept, so small movements don't flicker. */
export const HYSTERESIS = 6;
/** Near a container's edge (along its parent's axis), the slot next to it wins over going inside. */
const EDGE_BAND = 14;

export interface SlotIndex {
    index: number;
    /** Distance from the point to the boundary that decided `index` (for hysteresis). */
    margin: number;
}

/** Which gap among a container's children the point is in, in document order. */
export function slotIndex(
    doc: PageDocument,
    geometry: Geometry,
    containerId: NodeId,
    x: number,
    y: number,
    previous?: { parentId: NodeId; index: number } | null,
): SlotIndex {
    const container = geometry.nodes[containerId];
    const children = (doc.nodes[containerId]?.children ?? []).map((id, index) => ({ id, index, g: geometry.nodes[id] })).filter((c) => c.g);
    if (children.length === 0) return { index: doc.nodes[containerId]?.children?.length ?? 0, margin: Infinity };
    const layout = container?.layout ?? { axis: 'y' as const, reversed: false, wrap: false, box: container?.rect ?? children[0]!.g!.rect };
    let nearest = children[0]!;
    let best = Infinity;
    for (const child of children) {
        const d = distance(child.g!.rect, x, y);
        if (d < best) {
            best = d;
            nearest = child;
        }
    }
    const r = nearest.g!.rect;
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    let after: boolean;
    let margin: number;
    if (layout.wrap && (y < r.top || y > r.top + r.height)) {
        after = y > r.top + r.height;
        margin = Math.min(Math.abs(y - r.top), Math.abs(y - r.top - r.height));
    } else if (layout.axis === 'x' || layout.wrap) {
        after = layout.reversed ? x < cx : x > cx;
        margin = Math.abs(x - cx);
    } else {
        after = layout.reversed ? y < cy : y > cy;
        margin = Math.abs(y - cy);
    }
    let index = nearest.index + (after ? 1 : 0);
    if (previous && previous.parentId === containerId && previous.index !== index && Math.abs(previous.index - index) === 1 && margin < HYSTERESIS)
        index = previous.index;
    return { index, margin };
}

/**
 * The slot under a point: the deepest container around it that accepts the dragged block,
 * at the gap nearest the point among its children (padding and gaps included), honouring
 * the container's real direction. Near a container's own edge, the slot beside it in its
 * parent wins, so dropping between two sections never needs pixel precision.
 */
export function resolveSlot(
    doc: PageDocument,
    dragged: Dragged,
    geometry: Geometry,
    x: number,
    y: number,
    previous?: { parentId: NodeId; index: number } | null,
): Resolution {
    if (dragged.nodeId && !doc.nodes[dragged.nodeId]) return { kind: 'invalid', reason: 'The block being moved no longer exists' };
    const excluded = new Set(dragged.nodeId ? collectSubtree(doc, dragged.nodeId).map((n) => n.id) : []);
    const candidates = Object.values(geometry.nodes)
        .filter((g) => g.id !== doc.root && doc.nodes[g.id]?.children !== undefined && contains(g.rect, x, y))
        .map((g) => ({ g, depth: depthOf(doc, g.id) }))
        .sort((a, b) => b.depth - a.depth)
        .map((c) => c.g.id);
    candidates.push(doc.root);
    let reason: string | null = null;
    let reasonAt: NodeId | null = null;
    for (const id of candidates) {
        if (excluded.has(id)) {
            reason ??= 'A block can’t go inside itself';
            continue;
        }
        if (id !== doc.root && nearOwnEdge(doc, geometry, id, x, y) && parentAccepts(doc, dragged, id)) continue;
        const { index } = slotIndex(doc, geometry, id, x, y, previous);
        const check = placeAt(doc, dragged, id, index);
        if (check.ok)
            return {
                kind: 'place',
                parentId: id,
                index,
                placement: check.placement,
                label: slotLabel(doc, dragged, id, index),
                indicator: indicatorFor(doc, geometry, id, index),
            };
        if (check.noop)
            return { kind: 'noop', parentId: id, index, label: 'Current position: nothing changes', indicator: indicatorFor(doc, geometry, id, index) };
        if (reason === null) {
            reason = check.reason;
            reasonAt = id;
        }
    }
    const at = reasonAt ? geometry.nodes[reasonAt] : undefined;
    return { kind: 'invalid', reason: reason ?? 'Not allowed here', indicator: at ? { kind: 'inside', rect: at.rect, axis: 'y' } : undefined };
}

function nearOwnEdge(doc: PageDocument, geometry: Geometry, id: NodeId, x: number, y: number): boolean {
    const location = findParent(doc, id);
    const g = geometry.nodes[id];
    if (!location || !g) return false;
    const axis = geometry.nodes[location.parentId]?.layout?.axis ?? 'y';
    const r = g.rect;
    if (axis === 'x') {
        const band = Math.min(EDGE_BAND, r.width * 0.2);
        return x - r.left < band || r.left + r.width - x < band;
    }
    const band = Math.min(EDGE_BAND, r.height * 0.2);
    return y - r.top < band || r.top + r.height - y < band;
}

function parentAccepts(doc: PageDocument, dragged: Dragged, id: NodeId): boolean {
    const location = findParent(doc, id);
    if (!location) return false;
    const check = placeAt(doc, dragged, location.parentId, location.index);
    return check.ok || check.noop;
}

/** The siblings around a slot, ignoring the block being moved. */
function neighbours(doc: PageDocument, dragged: Dragged, parentId: NodeId, index: number): { prev: Node | null; next: Node | null } {
    const children = doc.nodes[parentId]?.children ?? [];
    const before = children.slice(0, index).filter((id) => id !== dragged.nodeId);
    const after = children.slice(index).filter((id) => id !== dragged.nodeId);
    return { prev: before.length ? doc.nodes[before.at(-1)!]! : null, next: after.length ? doc.nodes[after[0]!]! : null };
}

/**
 * A block as destination labels name it: its own label ("Text: Block 3"), or for a container
 * without text of its own, its kind and its first text ("Section “Journal entry 14”").
 */
export function describeBlock(doc: PageDocument, node: Node): string {
    const own = nodeLabel(node);
    if (own !== (currentDefinition(node.type)?.label ?? node.type) || !node.children) return own;
    const stack = [...node.children];
    while (stack.length) {
        const child = doc.nodes[stack.shift()!];
        if (!child) continue;
        const text = [child.props.heading, child.props.text, child.props.label].find((v) => typeof v === 'string' && v.trim() !== '') as string | undefined;
        if (text) return `${componentName(node)} “${text.trim().replace(/\s+/g, ' ').slice(0, 32)}”`;
        stack.unshift(...(child.children ?? []));
    }
    return componentName(node);
}

/** "After Text: Block 3", "Before Section “Projects”", "Into Group (empty)", with the container when nested. */
export function slotLabel(doc: PageDocument, dragged: Dragged, parentId: NodeId, index: number): string {
    const parent = doc.nodes[parentId]!;
    const { prev, next } = neighbours(doc, dragged, parentId, index);
    const where = parentId === doc.root ? '' : ` · in ${componentName(parent)}`;
    if (prev) return `After ${describeBlock(doc, prev)}${where}`;
    if (next) return `Before ${describeBlock(doc, next)}${where}`;
    return `Into ${componentName(parent)} (empty)`;
}

/** Where to draw a slot: a line in the gap between the neighbours, or the empty container. */
export function indicatorFor(doc: PageDocument, geometry: Geometry, parentId: NodeId, index: number): Indicator {
    const container = geometry.nodes[parentId];
    const layout = container?.layout;
    const box = layout?.box ?? container?.rect ?? { top: 0, left: 0, width: 0, height: 0 };
    const axis = layout && (layout.axis === 'x' || layout.wrap) ? 'x' : 'y';
    const children = doc.nodes[parentId]?.children ?? [];
    const prev = geometry.nodes[children[index - 1] ?? '']?.rect;
    const next = geometry.nodes[children[index] ?? '']?.rect;
    if (!prev && !next) return { kind: 'inside', rect: container?.rect ?? box, axis };
    const T = 3;
    if (axis === 'y') {
        let y: number;
        if (prev && next) y = prev.top + prev.height <= next.top ? (prev.top + prev.height + next.top) / 2 : (next.top + next.height + prev.top) / 2;
        else if (next) y = layout?.reversed ? next.top + next.height + T : next.top - T;
        else y = layout?.reversed ? prev!.top - T : prev!.top + prev!.height + T;
        return { kind: 'line', rect: { top: y - T / 2, left: box.left, width: box.width, height: T }, axis };
    }
    const ref = (next ?? prev)!;
    let x: number;
    if (prev && next && Math.abs(prev.top - next.top) < Math.min(prev.height, next.height) / 2) {
        x = prev.left + prev.width <= next.left ? (prev.left + prev.width + next.left) / 2 : (next.left + next.width + prev.left) / 2;
    } else if (next) x = layout?.reversed ? next.left + next.width + T : next.left - T;
    else x = layout?.reversed ? prev!.left - T : prev!.left + prev!.width + T;
    return { kind: 'line', rect: { top: ref.top, left: x - T / 2, width: T, height: ref.height }, axis };
}

// ── Layers (the outline tree) ───────────────────────────────────────────────

export interface TreeRow {
    id: NodeId;
    depth: number;
    rect: Rect;
}

/** Pixels of indent per depth level in the Layers outline. */
export const TREE_INDENT = 14;

/**
 * The slot under a point in the Layers outline: before or after a row, or into a container
 * row (its middle). After the last child of a container, moving the pointer left chooses a
 * shallower level, so the intended parent is explicit.
 */
export function resolveTreeSlot(doc: PageDocument, dragged: Dragged, rows: TreeRow[], x: number, y: number): Resolution {
    if (rows.length === 0) {
        const check = placeAt(doc, dragged, doc.root, 0);
        return check.ok
            ? {
                  kind: 'place',
                  parentId: doc.root,
                  index: 0,
                  placement: check.placement,
                  label: 'Into the page',
                  indicator: { kind: 'inside', rect: { top: y, left: x, width: 0, height: 0 }, axis: 'y' },
              }
            : { kind: 'invalid', reason: check.reason };
    }
    let row = rows[0]!;
    for (const r of rows) if (distance(r.rect, x, y) < distance(row.rect, x, y)) row = r;
    const node = doc.nodes[row.id];
    if (!node) return { kind: 'invalid', reason: 'That place no longer exists' };
    const along = (y - row.rect.top) / Math.max(row.rect.height, 1);
    const options: { parentId: NodeId; index: number; indicator: Indicator }[] = [];
    const lineAt = (top: number, depth: number): Indicator => ({
        kind: 'line',
        rect: { top: top - 1.5, left: row.rect.left + 6 + depth * TREE_INDENT, width: row.rect.width - 6 - depth * TREE_INDENT, height: 3 },
        axis: 'y',
    });
    const location = findParent(doc, row.id);
    const before = location && { parentId: location.parentId, index: location.index, indicator: lineAt(row.rect.top, row.depth) };
    const inside = node.children && {
        parentId: node.id,
        index: node.children.length,
        indicator: { kind: 'inside' as const, rect: row.rect, axis: 'y' as const },
    };
    // After the row: at a level chosen by the pointer's indent when the row ends its parent.
    let afterTarget = row.id;
    let afterDepth = row.depth;
    const wanted = Math.max(0, Math.floor((x - row.rect.left - 6) / TREE_INDENT));
    for (;;) {
        const loc = findParent(doc, afterTarget);
        if (!loc || loc.parentId === doc.root || wanted >= afterDepth) break;
        const siblings = doc.nodes[loc.parentId]!.children!;
        if (loc.index !== siblings.length - 1) break;
        if (node.children && node.children.length > 0 && afterTarget === row.id) break; // an expanded container's end is its last child's row
        afterTarget = loc.parentId;
        afterDepth -= 1;
    }
    const afterLoc = findParent(doc, afterTarget);
    const after = afterLoc && { parentId: afterLoc.parentId, index: afterLoc.index + 1, indicator: lineAt(row.rect.top + row.rect.height, afterDepth) };
    if (node.children) {
        if (along < 0.25) options.push(...[before, inside].filter((o) => o !== null && o !== undefined));
        else if (along > 0.75) options.push(...[after, inside].filter((o) => o !== null && o !== undefined));
        else options.push(...[inside, along < 0.5 ? before : after].filter((o) => o !== null && o !== undefined));
    } else options.push(...[along < 0.5 ? before : after].filter((o) => o !== null && o !== undefined));
    let reason: string | null = null;
    for (const option of options) {
        const check = placeAt(doc, dragged, option.parentId, option.index);
        if (check.ok)
            return {
                kind: 'place',
                parentId: option.parentId,
                index: option.index,
                placement: check.placement,
                label: slotLabel(doc, dragged, option.parentId, option.index),
                indicator: option.indicator,
            };
        if (check.noop)
            return { kind: 'noop', parentId: option.parentId, index: option.index, label: 'Current position: nothing changes', indicator: option.indicator };
        reason ??= check.reason;
    }
    return { kind: 'invalid', reason: reason ?? 'Not allowed here', indicator: { kind: 'inside', rect: row.rect, axis: 'y' } };
}

// ── Automatic scrolling ─────────────────────────────────────────────────────

/** Width of the band at a scroll edge in which dragging scrolls (px). */
export const SCROLL_EDGE = 56;
/** Fastest scroll speed, at (or beyond) the edge (px per second). */
export const SCROLL_MAX = 1400;

/**
 * Scroll speed for a pointer `inset` px inside an edge band (0 = at the edge, negative =
 * beyond it): zero outside the band, rising smoothly to the maximum at the edge.
 */
export function scrollSpeed(inset: number, edge = SCROLL_EDGE, max = SCROLL_MAX): number {
    if (inset >= edge) return 0;
    const t = Math.min(1, (edge - inset) / edge);
    return max * t * t;
}

/** Signed scroll step for one axis, bounded by the scroll range. `dt` in seconds (capped, so a stalled frame doesn't jump). */
export function scrollStep(pointer: number, start: number, end: number, scroll: number, maxScroll: number, dt: number): number {
    const step = Math.min(dt, 0.05);
    const up = scrollSpeed(pointer - start);
    const down = scrollSpeed(end - pointer);
    const delta = (down - up) * step;
    return Math.max(-scroll, Math.min(maxScroll - scroll, delta));
}
