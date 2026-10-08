import { describe, expect, it } from 'vitest';
import { validatePageDocument } from '../components/validate';
import { createPageDocument } from '../components/factories';
import type { NodeId, PageDocument } from '../schema/document';
import { applyOperations, type PageOperation } from '../schema/operations';
import type { Breakpoint, Style } from '../style/schema';
import sharedCases from '../../../../tests/Conformance/column-layouts.json';
import { blocksBeyond, COLUMN_PRESETS, createColumns, reconcileColumns, removeColumn, resetNotice, setColumnCount, withColumnLayouts } from './columns';
import { dispatch, initialState, undo } from './state';
import { createNodes, insertOps } from './structure';

function apply(doc: PageDocument, ops: PageOperation[]): PageDocument {
    const next = applyOperations(doc, ops).doc;
    expect(validatePageDocument(next)).toEqual([]);
    return next;
}

/** A page with one Columns block of `count` columns; column i holds texts "c<i>-<j>". */
function withColumns(count: number, filled: number[] = [], tracks: string | null = null) {
    let doc = createPageDocument([]);
    const nodes = createColumns(count, tracks);
    doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, nodes));
    const columns = nodes[0]!.id;
    for (const i of filled) {
        const column = doc.nodes[columns]!.children![i]!;
        for (let j = 0; j < 2; j++) doc = apply(doc, insertOps({ parentId: column, index: j }, createNodes('text', { text: `c${i}-${j}` })));
    }
    return { doc, columns };
}

const texts = (doc: PageDocument, column: NodeId) => (doc.nodes[column]!.children ?? []).map((id) => doc.nodes[id]!.props.text);
const style = (doc: PageDocument, id: NodeId) => doc.nodes[id]!.props.style as Style;

describe('creating columns', () => {
    it('creates the real Column nodes and the layout together, for 3, 4 and 5 equal columns and proportions', () => {
        for (const count of [3, 4, 5]) {
            const { doc, columns } = withColumns(count);
            expect(doc.nodes[columns]!.children).toHaveLength(count);
            expect(doc.nodes[columns]!.children!.every((id) => doc.nodes[id]!.type === 'column')).toBe(true);
            // Equal widths are the default; phones still stack.
            expect(style(doc, columns)).toEqual({ root: { mobile: { columns: '1' } } });
        }
        const wide = withColumns(3, [], '1fr 2fr 1fr');
        expect(style(wide.doc, wide.columns)).toEqual({ root: { mobile: { columns: '1' }, base: { columns: '1fr 2fr 1fr' } } });
        expect(COLUMN_PRESETS.every((p) => p.tracks === null || p.tracks.split(' ').length === p.count)).toBe(true);
    });
});

describe('changing the number of columns', () => {
    it('more columns: content stays where it was, new columns are empty, in one undo step', () => {
        const { doc, columns } = withColumns(2, [0, 1]);
        const change = setColumnCount(doc, columns, 5);
        if (!change.ok) throw new Error(change.reason);
        const state = dispatch(initialState(doc, 1), change.ops);
        if (!state.ok) throw new Error('dispatch failed');
        const next = state.state.document;
        const ids = next.nodes[columns]!.children!;
        expect(ids).toHaveLength(5);
        expect(texts(next, ids[0]!)).toEqual(['c0-0', 'c0-1']);
        expect(texts(next, ids[1]!)).toEqual(['c1-0', 'c1-1']);
        expect(ids.slice(2).every((id) => next.nodes[id]!.children!.length === 0)).toBe(true);
        expect(state.state.undo).toHaveLength(1);
        expect(undo(state.state)!.document).toEqual(doc);
    });

    it('fewer columns: blocks of removed columns move, in order, to the end of the last column kept', () => {
        const { doc, columns } = withColumns(4, [0, 2, 3]);
        expect(blocksBeyond(doc, columns, 2).map((id) => doc.nodes[id]!.props.text)).toEqual(['c2-0', 'c2-1', 'c3-0', 'c3-1']);
        const change = setColumnCount(doc, columns, 2, 'move');
        if (!change.ok) throw new Error(change.reason);
        const next = apply(doc, change.ops);
        const ids = next.nodes[columns]!.children!;
        expect(ids).toHaveLength(2);
        expect(texts(next, ids[0]!)).toEqual(['c0-0', 'c0-1']);
        expect(texts(next, ids[1]!)).toEqual(['c2-0', 'c2-1', 'c3-0', 'c3-1']);
    });

    it('fewer columns with delete: only when asked, removed with their columns', () => {
        const { doc, columns } = withColumns(3, [0, 2]);
        const change = setColumnCount(doc, columns, 2, 'delete');
        if (!change.ok) throw new Error(change.reason);
        const next = apply(doc, change.ops);
        expect(
            Object.values(next.nodes)
                .filter((n) => n.type === 'text')
                .map((n) => n.props.text),
        ).toEqual(['c0-0', 'c0-1']);
    });

    it('refuses to move more blocks into the last column than it can hold', () => {
        let { doc, columns } = withColumns(2);
        for (const [i, n] of [
            [0, 20],
            [1, 15],
        ] as const) {
            const column = doc.nodes[columns]!.children![i]!;
            for (let j = 0; j < n; j++) doc = apply(doc, insertOps({ parentId: column, index: j }, createNodes('text')));
        }
        const change = setColumnCount(doc, columns, 1, 'move');
        expect(change).toMatchObject({ ok: false, reason: expect.stringContaining('Column 1 can hold 30 blocks at most') });
        expect(setColumnCount(doc, columns, 7)).toMatchObject({ ok: false });
        expect(setColumnCount(doc, columns, 0)).toMatchObject({ ok: false });
    });

    it('keeps the mobile stacking and resets proportions that no longer fit, saying so', () => {
        const { doc, columns } = withColumns(2, [], '1fr 2fr');
        const change = setColumnCount(doc, columns, 5);
        if (!change.ok) throw new Error(change.reason);
        expect(apply(doc, change.ops).nodes[columns]!.props.style).toEqual({ root: { mobile: { columns: '1' } } });
        expect(change.reset).toEqual(['base']);
        expect(change.notice).toBe('Column widths on all screens went back to equal, because they were set for 2 columns.');
    });

    it('removing a column takes its width with it; changing the mobile layout never removes columns', () => {
        const { doc, columns } = withColumns(3, [1], '1fr 2fr 1fr');
        const middle = doc.nodes[columns]!.children![1]!;
        const change = removeColumn(doc, middle);
        if (!change.ok) throw new Error(change.reason);
        const next = apply(doc, change.ops);
        expect(style(next, columns).root!.base).toEqual({ columns: '1fr 1fr' });
        const mobile = apply(next, [
            { op: 'updateProps', nodeId: columns, set: { style: { root: { base: { columns: '1fr 1fr' }, mobile: { columns: '2' } } } } },
        ]);
        expect(mobile.nodes[columns]!.children).toHaveLength(2);
    });
});

describe('reconciling widths per screen', () => {
    it('follows each kind of value', () => {
        const before: Style = { root: { base: { columns: '3', gap: '@space.lg' }, tablet: { columns: '2' }, mobile: { columns: '1' } } };
        // 3 → 5 (two new): all-equal follows the count; the 2-per-row tablet wrap and phone stacking stay.
        expect(reconcileColumns(before, 3, [0, 1, 2, null, null])).toEqual({
            style: { root: { base: { gap: '@space.lg' }, tablet: { columns: '2' }, mobile: { columns: '1' } } },
            reset: [],
        });
        // 3 → 1: a 2-per-row tablet layout no longer fits.
        expect(reconcileColumns(before, 3, [0]).reset).toEqual(['tablet']);
        // Proportions follow duplicated or removed columns, and reset when new empty ones appear.
        const tracks: Style = { root: { base: { columns: '1fr 2fr 1fr' }, tablet: { columns: '2fr 1fr 1fr' } } };
        expect(reconcileColumns(tracks, 3, [0, 1, 1, 2]).style).toEqual({
            root: { base: { columns: '1fr 2fr 2fr 1fr' }, tablet: { columns: '2fr 1fr 1fr 1fr' } },
        });
        expect(reconcileColumns(tracks, 3, [0, 2]).style).toEqual({ root: { base: { columns: '1fr 1fr' }, tablet: { columns: '2fr 1fr' } } });
        const grown = reconcileColumns(tracks, 3, [0, 1, 2, null]);
        expect(grown).toEqual({ style: {}, reset: ['base', 'tablet'] });
        expect(resetNotice(grown.reset, 3)).toBe('Column widths on all screens and tablets went back to equal, because they were set for 3 columns.');
    });
});

describe('structural edits keep one width per column', () => {
    /** Two Columns blocks: "1fr 2fr" (A, B) and "2fr 1fr 1fr" (C, D, E); phones stack. */
    function two() {
        let doc = createPageDocument([]);
        const first = createColumns(2, '1fr 2fr');
        const second = createColumns(3, '2fr 1fr 1fr');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, first));
        doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, second));
        return { doc, first: first[0]!.id, second: second[0]!.id, a: first[1]!.id, b: first[2]!.id, c: second[1]!.id, d: second[2]!.id, e: second[3]!.id };
    }

    it('moving a column to another Columns block: both stay valid, the reset is explained', () => {
        const t = two();
        // Without reconciliation this move leaves two widths for one column and three for four.
        expect(validatePageDocument(applyOperations(t.doc, [{ op: 'moveNode', nodeId: t.b, parentId: t.second, index: 0 }]).doc)).not.toEqual([]);
        const { ops, notice } = withColumnLayouts(t.doc, [{ op: 'moveNode', nodeId: t.b, parentId: t.second, index: 0 }]);
        const next = apply(t.doc, ops);
        expect(style(next, t.first)).toEqual({ root: { mobile: { columns: '1' } } });
        expect(style(next, t.second)).toEqual({ root: { mobile: { columns: '1' } } });
        expect(notice).toBe('Column widths on all screens went back to equal, because they were set for 3 columns.');
    });

    it('reordering columns: widths follow their columns, content order is kept', () => {
        const t = two();
        const { ops, notice } = withColumnLayouts(t.doc, [{ op: 'moveNode', nodeId: t.c, parentId: t.second, index: 2 }]);
        const next = apply(t.doc, ops);
        expect(next.nodes[t.second]!.children).toEqual([t.d, t.e, t.c]);
        expect(style(next, t.second).root!.base).toEqual({ columns: '1fr 1fr 2fr' });
        expect(notice).toBeNull();
    });

    it('edits that set widths themselves, and other blocks, are left as they are', () => {
        const t = two();
        const own: PageOperation[] = [
            { op: 'insertNode', parentId: t.first, index: 2, nodes: createColumns(1).slice(1) },
            { op: 'updateProps', nodeId: t.first, set: { style: { root: { base: { columns: '1fr 1fr 2fr' }, mobile: { columns: '1' } } } } },
        ];
        expect(withColumnLayouts(t.doc, own).ops).toEqual(own);
        const text: PageOperation[] = insertOps({ parentId: t.a, index: 0 }, createNodes('text'));
        expect(withColumnLayouts(t.doc, text).ops).toEqual(text);
    });
});

describe('only actual width settings count as explicit', () => {
    function block() {
        let doc = createPageDocument([]);
        const first = createColumns(2, '1fr 2fr');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, first));
        const second = createColumns(3, '2fr 1fr 1fr');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, second));
        return { doc, first: first[0]!.id, b: first[2]!.id, second: second[0]!.id };
    }
    const withRoot = (doc: PageDocument, id: NodeId, change: (root: Record<string, Record<string, string>>) => void) => {
        const style = structuredClone(doc.nodes[id]!.props.style as Style) as { root: Record<string, Record<string, string>> };
        change(style.root);
        return style;
    };

    it('a gap, padding, background or animation change with a structural edit still gets the widths reconciled', () => {
        const t = block();
        for (const [property, value] of [
            ['gap', '20px'],
            ['paddingTop', '@space.lg'],
            ['backgroundColor', '#f5f5f4'],
            ['animation', 'fade-up'],
        ] as const) {
            const ops: PageOperation[] = [
                { op: 'moveNode', nodeId: t.b, parentId: t.second, index: 0 },
                { op: 'updateProps', nodeId: t.second, set: { style: withRoot(t.doc, t.second, (root) => (root.base![property] = value)) } },
            ];
            const next = apply(t.doc, withColumnLayouts(t.doc, ops).ops);
            expect(style(next, t.second)).toEqual({ root: { base: { [property]: value }, mobile: { columns: '1' } } });
        }
    });

    it('an explicit mobile layout does not exempt the other screens; explicit final widths are kept', () => {
        const t = block();
        const column = createColumns(1).slice(1);
        const mobileOnly: PageOperation[] = [
            { op: 'insertNode', parentId: t.first, index: 2, nodes: column },
            { op: 'updateProps', nodeId: t.first, set: { style: withRoot(t.doc, t.first, (root) => (root.mobile = { columns: '2' })) } },
        ];
        const result = withColumnLayouts(t.doc, mobileOnly);
        expect(style(apply(t.doc, result.ops), t.first)).toEqual({ root: { mobile: { columns: '2' } } });
        expect(result.notice).toBe('Column widths on all screens went back to equal, because they were set for 2 columns.');

        const explicit: PageOperation[] = [
            { op: 'insertNode', parentId: t.first, index: 2, nodes: createColumns(1).slice(1) },
            {
                op: 'updateProps',
                nodeId: t.first,
                set: { style: withRoot(t.doc, t.first, (root) => ((root.base!.columns = '1fr 1fr 3fr'), (root.base!.gap = '8px'))) },
            },
        ];
        expect(withColumnLayouts(t.doc, explicit).ops).toEqual(explicit);
    });

    it('agrees with the PHP reconciliation on the shared cases', () => {
        for (const c of sharedCases)
            expect(reconcileColumns(c.style as Style, c.oldCount, c.mapping, (c.keep ?? []) as Breakpoint[]), c.name).toEqual(c.expected);
    });
});
