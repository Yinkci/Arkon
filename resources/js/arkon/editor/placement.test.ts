import { describe, expect, it } from 'vitest';
import { validatePageDocument } from '../components/validate';
import { createHeroNode, createPageDocument } from '../components/factories';
import { applyOperations, type PageOperation } from '../schema/operations';
import type { NodeId, PageDocument } from '../schema/document';
import {
    HYSTERESIS,
    placeAt,
    resolveSlot,
    slotLabel,
    describeBlock,
    resolveTreeSlot,
    scrollSpeed,
    scrollStep,
    SCROLL_MAX,
    type Geometry,
    type Layout,
    type Rect,
} from './placement';
import { createNodes, dropOps, insertOps } from './structure';

function apply(doc: PageDocument, ops: PageOperation[]): PageDocument {
    const next = applyOperations(doc, ops).doc;
    expect(validatePageDocument(next)).toEqual([]);
    return next;
}

const rect = (top: number, height: number, left = 0, width = 800): Rect => ({ top, left, width, height });
const stack = (box: Rect, extra: Partial<Layout> = {}): Layout => ({ axis: 'y', reversed: false, wrap: false, box, ...extra });

/** A page of `n` text blocks, 100px tall with 20px gaps, the page 800px wide. */
function texts(n: number) {
    const nodes = Array.from({ length: n }, () => createNodes('text', { text: 'x' })[0]!);
    nodes.forEach((node, i) => (node.props.text = `Block ${i + 1}`));
    const doc = createPageDocument(nodes);
    const geometry: Geometry = { nodes: { [doc.root]: { id: doc.root, rect: rect(0, 2000), layout: stack(rect(0, 2000)) } } };
    nodes.forEach((node, i) => (geometry.nodes[node.id] = { id: node.id, rect: rect(i * 120, 100) }));
    return { doc, geometry, ids: nodes.map((n) => n.id) };
}

const order = (doc: PageDocument, parent: NodeId = doc.root) => doc.nodes[parent]!.children!.map((id) => doc.nodes[id]!.props.text);

function drop(doc: PageDocument, dragged: { nodeId?: NodeId; type: string }, geometry: Geometry, x: number, y: number) {
    const r = resolveSlot(doc, dragged, geometry, x, y);
    if (r.kind !== 'place') throw new Error(`expected a place, got ${JSON.stringify(r)}`);
    return apply(doc, dropOps(dragged, r.placement));
}

describe('slots between siblings', () => {
    it('a point in the gap between two blocks inserts between them, whichever block is nearer', () => {
        const t = texts(4);
        // Block 1 dragged into the 20px gap between Block 3 (240-340) and Block 4 (360-460).
        const r = resolveSlot(t.doc, { nodeId: t.ids[0], type: 'text' }, t.geometry, 400, 350);
        expect(r).toMatchObject({ kind: 'place', parentId: t.doc.root, index: 3, label: 'After Text: Block 3' });
        expect(order(drop(t.doc, { nodeId: t.ids[0], type: 'text' }, t.geometry, 400, 350))).toEqual(['Block 2', 'Block 3', 'Block 1', 'Block 4']);
        // Just past Block 4's middle: after Block 4.
        expect(order(drop(t.doc, { nodeId: t.ids[0], type: 'text' }, t.geometry, 400, 440))).toEqual(['Block 2', 'Block 3', 'Block 4', 'Block 1']);
        // The line sits in the middle of the gap.
        expect(r.kind === 'place' && r.indicator).toEqual({ kind: 'line', rect: { top: 348.5, left: 0, width: 800, height: 3 }, axis: 'y' });
    });

    it('first and last positions, and below the last block', () => {
        const t = texts(3);
        expect(order(drop(t.doc, { nodeId: t.ids[2], type: 'text' }, t.geometry, 10, 5))).toEqual(['Block 3', 'Block 1', 'Block 2']);
        expect(order(drop(t.doc, { nodeId: t.ids[0], type: 'text' }, t.geometry, 10, 1500))).toEqual(['Block 2', 'Block 3', 'Block 1']);
    });

    it('dropping where a block already is changes nothing', () => {
        const t = texts(3);
        expect(resolveSlot(t.doc, { nodeId: t.ids[1], type: 'text' }, t.geometry, 10, 150)).toMatchObject({ kind: 'noop' });
        expect(resolveSlot(t.doc, { nodeId: t.ids[1], type: 'text' }, t.geometry, 10, 230)).toMatchObject({ kind: 'noop' });
    });

    it('keeps the previous slot within a few pixels of the boundary (no flicker)', () => {
        const t = texts(3);
        const dragged = { type: 'button' };
        // Block 2's middle is at y=170: just above it is "before Block 2" (index 1) …
        const first = resolveSlot(t.doc, dragged, t.geometry, 10, 168);
        expect(first).toMatchObject({ index: 1 });
        // … and a wobble just across the middle keeps it.
        expect(resolveSlot(t.doc, dragged, t.geometry, 10, 170 + HYSTERESIS - 1, { parentId: t.doc.root, index: 1 })).toMatchObject({ index: 1 });
        expect(resolveSlot(t.doc, dragged, t.geometry, 10, 170 + HYSTERESIS + 1, { parentId: t.doc.root, index: 1 })).toMatchObject({ index: 2 });
    });
});

/** Page: section(text A, text B), group(empty). */
function nested() {
    let doc = createPageDocument([createHeroNode({ heading: 'Top' })]);
    const section = createNodes('section');
    const a = createNodes('text', { text: 'A' });
    const b = createNodes('text', { text: 'B' });
    const group = createNodes('group');
    doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, section));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 0 }, a));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 1 }, b));
    doc = apply(doc, insertOps({ parentId: doc.root, index: 2 }, group));
    const hero = doc.nodes[doc.root]!.children![0]!;
    const geometry: Geometry = {
        nodes: {
            [doc.root]: { id: doc.root, rect: rect(0, 1400), layout: stack(rect(0, 1400)) },
            [hero]: { id: hero, rect: rect(0, 400) },
            // The section has 60px padding above and below its content.
            [section[0]!.id]: { id: section[0]!.id, rect: rect(400, 360), layout: stack(rect(460, 240, 40, 720)) },
            [a[0]!.id]: { id: a[0]!.id, rect: rect(460, 100, 40, 720) },
            [b[0]!.id]: { id: b[0]!.id, rect: rect(600, 100, 40, 720) },
            [group[0]!.id]: { id: group[0]!.id, rect: rect(760, 120), layout: stack(rect(780, 80, 20, 760)) },
        },
    };
    return { doc, geometry, hero, section: section[0]!.id, a: a[0]!.id, b: b[0]!.id, group: group[0]!.id };
}

describe('containers', () => {
    it('padding inside a container is its first or last slot; the very edge is the slot beside it', () => {
        const n = nested();
        const button = { type: 'button' };
        // In the section's top padding: into the section, before A.
        expect(resolveSlot(n.doc, button, n.geometry, 400, 430)).toMatchObject({
            kind: 'place',
            parentId: n.section,
            index: 0,
            label: 'Before Text: A · in Section',
        });
        // In its bottom padding: after B.
        expect(resolveSlot(n.doc, button, n.geometry, 400, 740)).toMatchObject({ parentId: n.section, index: 2 });
        // At its top edge: between the hero and the section, on the page.
        expect(resolveSlot(n.doc, button, n.geometry, 400, 404)).toMatchObject({ parentId: n.doc.root, index: 1 });
    });

    it('names containers by their first text in destination labels', () => {
        const n = nested();
        expect(slotLabel(n.doc, { type: 'button' }, n.doc.root, 2)).toBe('After Section “A”');
        expect(slotLabel(n.doc, { type: 'button' }, n.doc.root, 1)).toBe('After Hero: Top');
        expect(slotLabel(n.doc, { type: 'button' }, n.group, 0)).toBe('Into Group (empty)');
    });

    it('an empty container accepts a drop anywhere inside it, highlighted', () => {
        const n = nested();
        const r = resolveSlot(n.doc, { nodeId: n.a, type: 'text' }, n.geometry, 400, 820);
        expect(r).toMatchObject({ kind: 'place', parentId: n.group, index: 0, label: 'Into Group (empty)', indicator: { kind: 'inside' } });
        const moved = drop(n.doc, { nodeId: n.a, type: 'text' }, n.geometry, 400, 820);
        expect(order(moved, n.group)).toEqual(['A']);
        expect(order(moved, n.section)).toEqual(['B']);
    });

    it('reparents out of a container into another level', () => {
        const n = nested();
        // A text dragged from the section to the page, between the hero and the section (section edge).
        const moved = drop(n.doc, { nodeId: n.b, type: 'text' }, n.geometry, 400, 402);
        expect(moved.nodes[moved.root]!.children!.map((id) => moved.nodes[id]!.type)).toEqual(['hero', 'text', 'section', 'group']);
    });

    it('refuses what the nesting rules refuse, saying why, and never drops a block into itself', () => {
        const n = nested();
        // A hero can't go in a section's… it can: sections hold heroes. A section can't go in a group.
        expect(resolveSlot(n.doc, { type: 'section' }, n.geometry, 400, 820)).toMatchObject({ kind: 'place', parentId: n.doc.root });
        expect(placeAt(n.doc, { type: 'section' }, n.group, 0)).toEqual({ ok: false, noop: false, reason: 'Section can’t go in a group' });
        // The section dragged over its own child resolves on the page, not inside itself.
        expect(resolveSlot(n.doc, { nodeId: n.section, type: 'section' }, n.geometry, 400, 520)).toMatchObject({ parentId: n.doc.root });
        expect(placeAt(n.doc, { nodeId: n.section, type: 'section' }, n.section, 0)).toMatchObject({ ok: false, reason: 'A block can’t go inside itself' });
        // A column only goes in Columns.
        expect(resolveSlot(n.doc, { type: 'column' }, n.geometry, 400, 520)).toMatchObject({ kind: 'invalid', reason: 'Column can’t go in a section' });
    });

    it('keeps capacity limits and minimum children', () => {
        let doc = createPageDocument([]);
        const columns = createNodes('columns');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, columns));
        const [colA, colB] = doc.nodes[columns[0]!.id]!.children!;
        // Removing one column leaves one: it is the minimum, so it can't be dragged out.
        doc = apply(doc, [{ op: 'removeNode', nodeId: colB! }]);
        const other = createNodes('columns');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, other));
        expect(placeAt(doc, { nodeId: colA, type: 'column' }, other[0]!.id, 0)).toMatchObject({ ok: false, reason: 'Columns needs at least 1 column' });
        // A full group refuses one more.
        let full = createPageDocument([]);
        const group = createNodes('group');
        full = apply(full, insertOps({ parentId: full.root, index: 0 }, group));
        for (let i = 0; i < 30; i++) full = apply(full, insertOps({ parentId: group[0]!.id, index: i }, createNodes('text')));
        expect(placeAt(full, { type: 'text' }, group[0]!.id, 0)).toMatchObject({ ok: false, reason: 'Group is full (30 blocks at most)' });
    });
});

describe('layout direction', () => {
    /** A group of three texts side by side: A, B, C in document order. */
    function row(layout: Partial<Layout>, lefts: number[]) {
        let doc = createPageDocument([]);
        const group = createNodes('group');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, group));
        const ids = ['A', 'B', 'C'].map((text, i) => {
            const node = createNodes('text', { text })[0]!;
            doc = apply(doc, insertOps({ parentId: group[0]!.id, index: i }, [node]));
            return node.id;
        });
        const geometry: Geometry = {
            nodes: {
                [doc.root]: { id: doc.root, rect: rect(0, 1000), layout: stack(rect(0, 1000)) },
                [group[0]!.id]: {
                    id: group[0]!.id,
                    rect: rect(0, 200),
                    layout: { axis: 'x', reversed: false, wrap: false, box: rect(20, 160, 20, 760), ...layout },
                },
            },
        };
        ids.forEach((id, i) => (geometry.nodes[id] = { id, rect: rect(20, 160, lefts[i]!, 220) }));
        return { doc, geometry, group: group[0]!.id };
    }

    it('side by side: the gap between two blocks, by x', () => {
        const r = row({}, [20, 290, 560]);
        expect(resolveSlot(r.doc, { type: 'button' }, r.geometry, 275, 100)).toMatchObject({ parentId: r.group, index: 1 });
        expect(resolveSlot(r.doc, { type: 'button' }, r.geometry, 790, 100)).toMatchObject({ parentId: r.group, index: 3 });
    });

    it('reversed rows map visual positions back to document order', () => {
        // row-reverse: A is drawn on the right, C on the left.
        const r = row({ reversed: true }, [560, 290, 20]);
        // Right of A (visually last) is before A in document order.
        expect(resolveSlot(r.doc, { type: 'button' }, r.geometry, 790, 100)).toMatchObject({ index: 0 });
        // Between C (left) and B (middle) is between B and C in document order.
        expect(resolveSlot(r.doc, { type: 'button' }, r.geometry, 275, 100)).toMatchObject({ index: 2 });
        expect(resolveSlot(r.doc, { type: 'button' }, r.geometry, 25, 100)).toMatchObject({ index: 3 });
    });

    it('stacked in reverse (column-reverse) maps y the same way', () => {
        const t = texts(3);
        t.geometry.nodes[t.doc.root]!.layout = stack(rect(0, 2000), { reversed: true });
        // Block 1 is drawn at the bottom: 240-340; Block 3 at the top.
        t.ids.forEach((id, i) => (t.geometry.nodes[id]!.rect = rect((2 - i) * 120, 100)));
        expect(resolveSlot(t.doc, { type: 'button' }, t.geometry, 10, 5)).toMatchObject({ index: 3 });
        expect(resolveSlot(t.doc, { type: 'button' }, t.geometry, 10, 330)).toMatchObject({ index: 0 });
    });

    it('wrapping rows and grids: rows first, then position in the row', () => {
        const r = row({ wrap: true }, [20, 290, 20]);
        // C wrapped onto a second row below A.
        r.geometry.nodes[r.doc.nodes[r.group]!.children![2]!]!.rect = rect(200, 160, 20, 220);
        r.geometry.nodes[r.group]!.rect = rect(0, 400);
        expect(resolveSlot(r.doc, { type: 'button' }, r.geometry, 600, 100)).toMatchObject({ index: 2 });
        expect(resolveSlot(r.doc, { type: 'button' }, r.geometry, 280, 280)).toMatchObject({ index: 3 });
    });
});

describe('Layers outline', () => {
    it('before, after and into rows; after the last child, the pointer’s indent chooses the level', () => {
        const n = nested();
        const rows = [
            { id: n.hero, depth: 0, rect: rect(0, 32, 0, 300) },
            { id: n.section, depth: 0, rect: rect(32, 32, 0, 300) },
            { id: n.a, depth: 1, rect: rect(64, 32, 0, 300) },
            { id: n.b, depth: 1, rect: rect(96, 32, 0, 300) },
            { id: n.group, depth: 0, rect: rect(128, 32, 0, 300) },
        ];
        const hero = { nodeId: n.hero, type: 'hero' };
        expect(resolveTreeSlot(n.doc, { type: 'button' }, rows, 100, 48)).toMatchObject({ parentId: n.section, index: 2 });
        expect(resolveTreeSlot(n.doc, hero, rows, 100, 132)).toMatchObject({ parentId: n.doc.root, index: 2 });
        // Bottom of B (last in the section): indented → after B in the section; at the left → after the section.
        expect(resolveTreeSlot(n.doc, { type: 'button' }, rows, 40, 124)).toMatchObject({ parentId: n.section, index: 2 });
        expect(resolveTreeSlot(n.doc, { type: 'button' }, rows, 4, 124)).toMatchObject({ parentId: n.doc.root, index: 2 });
        // Into the empty group.
        expect(resolveTreeSlot(n.doc, { type: 'button' }, rows, 100, 144)).toMatchObject({ parentId: n.group, index: 0 });
        expect(resolveTreeSlot(n.doc, { type: 'column' }, rows, 100, 144)).toMatchObject({ kind: 'invalid' });
    });
});

describe('automatic scrolling', () => {
    it('keeps scrolling while the pointer stays still near an edge, faster nearer the edge, within the scroll range', () => {
        expect(scrollSpeed(100)).toBe(0);
        expect(scrollSpeed(28)).toBeGreaterThan(0);
        expect(scrollSpeed(10)).toBeGreaterThan(scrollSpeed(28));
        expect(scrollSpeed(-50)).toBe(SCROLL_MAX);
        // A stationary pointer 12px from the bottom of an 800px view: every frame moves on.
        let scroll = 0;
        for (let frame = 0; frame < 30; frame++) scroll += scrollStep(788, 0, 800, scroll, 3000, 1 / 60);
        expect(scroll).toBeGreaterThan(300);
        // Never past the end, and a stalled frame does not jump.
        expect(scrollStep(788, 0, 800, 2990, 3000, 1)).toBe(10);
        expect(scrollStep(788, 0, 800, 0, 3000, 2)).toBeLessThanOrEqual(SCROLL_MAX * 0.05);
        expect(scrollStep(400, 0, 800, 100, 3000, 1 / 60)).toBe(0);
    });
});

describe('block names in labels', () => {
    /** A section whose first text is `text`. */
    function section(text: string) {
        let doc = createPageDocument([]);
        const s = createNodes('section');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, s));
        doc = apply(doc, insertOps({ parentId: s[0]!.id, index: 0 }, createNodes('text', { text })));
        return describeBlock(doc, doc.nodes[s[0]!.id]!);
    }

    it('keeps the letter s and collapses runs of whitespace', () => {
        expect(section('Services and support')).toBe('Section “Services and support”');
        expect(section('  Seasonal \t\t care\n\nplans  ')).toBe('Section “Seasonal care plans”');
    });

    it('still shortens long text to 32 characters', () => {
        expect(section('Garden  design\tand   planting across every season')).toBe('Section “Garden design and planting acros”');
    });
});
