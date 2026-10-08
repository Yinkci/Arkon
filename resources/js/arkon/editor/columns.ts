// Column layouts: one set of helpers for creating Columns, changing how many columns there
// are, duplicating or removing one column. Structure (the Column nodes) and widths (the
// `columns` design setting of the Columns block, per screen) always change together, as one
// list of operations, so a layout never has more tracks than columns or the other way round.
import { currentDefinition } from '../components/registry';
import { createNodeId, type Node, type NodeId, type PageDocument } from '../schema/document';
import { applyOperations, type PageOperation } from '../schema/operations';
import { BREAKPOINTS, type Breakpoint, type Style } from '../style/schema';

export const MAX_COLUMNS = 6;

export interface ColumnPreset {
    id: string;
    label: string;
    count: number;
    /** Width proportions for all screens (null: equal widths). */
    tracks: string | null;
}

/** One click in the layout picker: equal columns, and common two- and three-column proportions. */
export const COLUMN_PRESETS: ColumnPreset[] = [
    { id: '2', label: '2 equal', count: 2, tracks: null },
    { id: '3', label: '3 equal', count: 3, tracks: null },
    { id: '4', label: '4 equal', count: 4, tracks: null },
    { id: '5', label: '5 equal', count: 5, tracks: null },
    { id: '6', label: '6 equal', count: 6, tracks: null },
    { id: '1fr 2fr', label: 'One third, two thirds', count: 2, tracks: '1fr 2fr' },
    { id: '2fr 1fr', label: 'Two thirds, one third', count: 2, tracks: '2fr 1fr' },
    { id: '1fr 3fr', label: 'Sidebar, content', count: 2, tracks: '1fr 3fr' },
    { id: '1fr 2fr 1fr', label: 'Wide middle', count: 3, tracks: '1fr 2fr 1fr' },
    { id: '2fr 1fr 1fr', label: 'Wide first', count: 3, tracks: '2fr 1fr 1fr' },
];

/** Width proportions offered for a number of columns (besides equal). */
export function proportionsFor(count: number): ColumnPreset[] {
    return COLUMN_PRESETS.filter((p) => p.tracks !== null && p.count === count);
}

function newNode(type: string, props: Record<string, unknown> = {}, children?: NodeId[]): Node {
    const definition = currentDefinition(type);
    if (!definition) throw new Error(`Unknown component ${type}`);
    return {
        id: createNodeId(),
        type,
        version: definition.version,
        props: { ...structuredClone(definition.defaultProps), ...props },
        ...(children ? { children } : {}),
    };
}

/** A Columns block with `count` empty columns and the preset's widths (phones still stack: the default). Root first. */
export function createColumns(count: number, tracks: string | null = null): Node[] {
    const columns = Array.from({ length: Math.max(1, Math.min(MAX_COLUMNS, count)) }, () => newNode('column', {}, []));
    const root = newNode(
        'columns',
        {},
        columns.map((c) => c.id),
    );
    if (tracks) {
        const style = structuredClone((root.props.style as Style | undefined) ?? {});
        ((style.root ??= {}).base ??= {}).columns = tracks;
        root.props.style = style;
    }
    return [root, ...columns];
}

const isCount = (value: unknown): boolean => typeof value === 'string' && /^[1-6]$/.test(value);

export interface Reconciled {
    style: Style;
    /** Screens whose widths no longer fitted and went back to equal (or to the larger screen's layout). */
    reset: Breakpoint[];
}

/**
 * The Columns block's widths after its columns changed. `mapping` lists, for each new column in
 * order, which old column it came from (its index), or null for a new empty one.
 * - Proportions ("1fr 2fr") follow their columns when every new column came from an old one
 *   (removing, duplicating or reordering columns); otherwise they can't be known and that
 *   screen goes back to equal widths (reported in `reset`).
 * - A count equal to the old number of columns ("all side by side") becomes the new number.
 * - A smaller count (wrapping, or "1" for stacking) is kept while it still fits; larger ones reset.
 */
export function reconcileColumns(style: Style, oldCount: number, mapping: (number | null)[], keep: Breakpoint[] = []): Reconciled {
    const next: Style = structuredClone(style);
    const newCount = mapping.length;
    const reset: Breakpoint[] = [];
    const root = next.root;
    for (const bp of BREAKPOINTS) {
        const value = root?.[bp]?.columns;
        // Screens whose widths were set explicitly are left exactly as set (and validated).
        if (value === undefined || keep.includes(bp)) continue;
        let replacement: string | null | undefined; // undefined: keep, null: remove
        if (isCount(value)) {
            const k = Number(value);
            if (k === 1) replacement = undefined;
            else if (k === oldCount) replacement = bp === 'base' ? null : String(newCount);
            else if (k > newCount) {
                replacement = null;
                reset.push(bp);
            }
        } else if (typeof value === 'string') {
            const tracks = value.split(' ');
            if (tracks.length === oldCount && mapping.every((i) => i !== null && i < tracks.length)) {
                const mapped = mapping.map((i) => tracks[i!]!);
                replacement = mapped.length === 1 ? null : mapped.length === oldCount && mapped.join(' ') === value ? undefined : mapped.join(' ');
            } else if (tracks.length !== newCount) {
                replacement = null;
                reset.push(bp);
            }
        }
        if (replacement === undefined) continue;
        const declarations = { ...(root![bp] ?? {}) };
        if (replacement === null) delete declarations.columns;
        else declarations.columns = replacement;
        if (Object.keys(declarations).length > 0) root![bp] = declarations;
        else delete root![bp];
    }
    if (root && Object.keys(root).length === 0) delete next.root;
    return { style: next, reset };
}

const SCREEN: Record<Breakpoint, string> = { base: 'all screens', tablet: 'tablets', mobile: 'phones' };

/** "Widths on all screens and tablets went back to equal, because they were set for 2 columns." */
export function resetNotice(reset: Breakpoint[], oldCount: number): string | null {
    if (reset.length === 0) return null;
    const screens = reset.map((bp) => SCREEN[bp]);
    const list = screens.length === 1 ? screens[0] : `${screens.slice(0, -1).join(', ')} and ${screens.at(-1)}`;
    return `Column widths on ${list} went back to equal, because they were set for ${oldCount} column${oldCount === 1 ? '' : 's'}.`;
}

function styleUpdate(doc: PageDocument, columnsId: NodeId, oldCount: number, mapping: (number | null)[]): { ops: PageOperation[]; reset: Breakpoint[] } {
    const columns = doc.nodes[columnsId]!;
    const current = (columns.props.style as Style | undefined) ?? {};
    const { style, reset } = reconcileColumns(current, oldCount, mapping);
    if (JSON.stringify(style) === JSON.stringify(current)) return { ops: [], reset };
    return { ops: [{ op: 'updateProps', nodeId: columnsId, set: { style } }], reset };
}

export type ColumnChange = { ok: true; ops: PageOperation[]; reset: Breakpoint[]; notice: string | null; select?: NodeId } | { ok: false; reason: string };

/** Blocks inside the columns that would go when there are only `count` left, in order. */
export function blocksBeyond(doc: PageDocument, columnsId: NodeId, count: number): NodeId[] {
    const columns = doc.nodes[columnsId]?.children ?? [];
    return columns.slice(count).flatMap((id) => doc.nodes[id]?.children ?? []);
}

/**
 * Changes how many columns a Columns block has. More: empty columns are added at the end.
 * Fewer: the last columns go; their blocks are moved, in order, to the end of the last column
 * that stays (`'move'`), or deleted with them (`'delete'`, only when asked for explicitly).
 * Widths are reconciled in the same operations (one undo step).
 */
export function setColumnCount(doc: PageDocument, columnsId: NodeId, count: number, content: 'move' | 'delete' = 'move'): ColumnChange {
    const node = doc.nodes[columnsId];
    if (!node || node.type !== 'columns' || !node.children) return { ok: false, reason: 'That Columns block no longer exists' };
    if (!Number.isInteger(count) || count < 1 || count > MAX_COLUMNS) return { ok: false, reason: `Columns can have 1 to ${MAX_COLUMNS} columns` };
    const old = node.children;
    if (count === old.length) return { ok: true, ops: [], reset: [], notice: null };
    const ops: PageOperation[] = [];
    if (count > old.length) {
        for (let i = old.length; i < count; i++) ops.push({ op: 'insertNode', parentId: columnsId, index: i, nodes: [newNode('column', {}, [])] });
    } else {
        const keep = old[count - 1]!;
        const moving = blocksBeyond(doc, columnsId, count);
        if (content === 'move' && moving.length > 0) {
            const max = currentDefinition('column')?.children ? (currentDefinition('column')!.children as { max?: number }).max : undefined;
            const room = (doc.nodes[keep]?.children?.length ?? 0) + moving.length;
            if (max !== undefined && room > max) {
                return {
                    ok: false,
                    reason: `Column ${count} can hold ${max} blocks at most, and moving the content there would make ${room}. Move some blocks first, or delete them with the columns.`,
                };
            }
            let index = doc.nodes[keep]?.children?.length ?? 0;
            for (const blockId of moving) ops.push({ op: 'moveNode', nodeId: blockId, parentId: keep, index: index++ });
        }
        for (const columnId of old.slice(count)) ops.push({ op: 'removeNode', nodeId: columnId });
    }
    const mapping = Array.from({ length: count }, (_, i) => (i < old.length ? i : null));
    const update = styleUpdate(doc, columnsId, old.length, mapping);
    return { ok: true, ops: [...update.ops, ...ops], reset: update.reset, notice: resetNotice(update.reset, old.length) };
}

/** A copy of one column right after it (its blocks copied too), with its width duplicated. */
export function duplicateColumn(doc: PageDocument, columnId: NodeId, copy: Node[]): ColumnChange {
    const columnsId = Object.values(doc.nodes).find((n) => n.children?.includes(columnId))?.id;
    const columns = columnsId ? doc.nodes[columnsId] : undefined;
    if (!columns?.children || columns.type !== 'columns') return { ok: false, reason: 'That column no longer exists' };
    const old = columns.children;
    if (old.length >= MAX_COLUMNS) return { ok: false, reason: `Columns already has ${MAX_COLUMNS} columns, the most allowed` };
    const at = old.indexOf(columnId);
    const mapping = [...old.map((_, i) => i).slice(0, at + 1), at, ...old.map((_, i) => i).slice(at + 1)];
    const update = styleUpdate(doc, columnsId!, old.length, mapping);
    return {
        ok: true,
        ops: [...update.ops, { op: 'insertNode', parentId: columnsId!, index: at + 1, nodes: copy }],
        reset: update.reset,
        notice: resetNotice(update.reset, old.length),
        select: copy[0]!.id,
    };
}

/** Removing one column (and its blocks): its width goes with it. */
export function removeColumn(doc: PageDocument, columnId: NodeId): ColumnChange {
    const columnsId = Object.values(doc.nodes).find((n) => n.children?.includes(columnId))?.id;
    const columns = columnsId ? doc.nodes[columnsId] : undefined;
    if (!columns?.children || columns.type !== 'columns') return { ok: false, reason: 'That column no longer exists' };
    if (columns.children.length <= 1) return { ok: false, reason: 'Columns needs at least 1 column' };
    const at = columns.children.indexOf(columnId);
    const mapping = columns.children.map((_, i) => i).filter((i) => i !== at);
    const update = styleUpdate(doc, columnsId!, columns.children.length, mapping);
    return {
        ok: true,
        ops: [...update.ops, { op: 'removeNode', nodeId: columnId }],
        reset: update.reset,
        notice: resetNotice(update.reset, columns.children.length),
    };
}

/** Removing any block: a column also takes its width with it (see removeColumn). */
export function removeOps(doc: PageDocument, nodeId: NodeId): PageOperation[] {
    if (doc.nodes[nodeId]?.type === 'column') {
        const change = removeColumn(doc, nodeId);
        if (change.ok) return change.ops;
    }
    return [{ op: 'removeNode', nodeId }];
}

/**
 * Any structural edit, with the widths of every Columns block whose columns it changed
 * reconciled (moving a column to another block or reordering it, a drop, a removal …), as
 * extra operations in the same list (one undo step). Screens whose widths the edit itself
 * changes are left as set; other style changes (gap, padding, background, animation …) never
 * count as widths. Twin of ColumnLayout::changes on the server. `notice` explains any reset.
 */
export function withColumnLayouts(doc: PageDocument, ops: PageOperation[]): { ops: PageOperation[]; notice: string | null } {
    let after: PageDocument;
    try {
        after = applyOperations(doc, ops).doc;
    } catch {
        return { ops, notice: null }; // invalid anyway: dispatch reports it
    }
    // Per block, the screens whose `columns` value an edit sets to something new.
    const explicit = new Map<NodeId, Breakpoint[]>();
    const widthsOf = (style: unknown, bp: Breakpoint) => (style as Style | undefined)?.root?.[bp]?.columns;
    for (const op of ops) {
        if (op.op !== 'updateProps' || !('style' in op.set) || doc.nodes[op.nodeId]?.type !== 'columns') continue;
        const changed = BREAKPOINTS.filter((bp) => widthsOf(op.set.style, bp) !== widthsOf(doc.nodes[op.nodeId]!.props.style, bp));
        explicit.set(op.nodeId, [...new Set([...(explicit.get(op.nodeId) ?? []), ...changed])]);
    }
    const extra: PageOperation[] = [];
    const notices: string[] = [];
    for (const node of Object.values(after.nodes)) {
        const before = doc.nodes[node.id];
        if (node.type !== 'columns' || !before) continue;
        const was = before.children ?? [];
        const now = node.children ?? [];
        if (was.length === now.length && was.every((id, i) => id === now[i])) continue;
        const mapping = now.map((id) => (was.includes(id) ? was.indexOf(id) : null));
        const current = (node.props.style as Style | undefined) ?? {};
        const { style, reset } = reconcileColumns(current, was.length, mapping, explicit.get(node.id) ?? []);
        if (JSON.stringify(style) === JSON.stringify(current)) continue;
        extra.push({ op: 'updateProps', nodeId: node.id, set: { style } });
        const notice = resetNotice(reset, was.length);
        if (notice) notices.push(notice);
    }
    return { ops: [...ops, ...extra], notice: notices.length > 0 ? notices.join(' ') : null };
}
