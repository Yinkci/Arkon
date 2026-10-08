import { describe, expect, it } from 'vitest';
import { validatePageDocument } from '../components/validate';
import { createHeroNode, createPageDocument } from '../components/factories';
import { applyOperations, type PageOperation } from '../schema/operations';
import type { PageDocument } from '../schema/document';
import { canRemove, createNodes, dropOps, dropPlacement, insertOps, insertionFor, moveWithinParent, nodeLabel } from './structure';
import { beginSave, dispatch, initialState, redo, undo, type EditorDocState } from './state';

function apply(doc: PageDocument, ops: PageOperation[]): PageDocument {
    const next = applyOperations(doc, ops).doc;
    expect(validatePageDocument(next)).toEqual([]);
    return next;
}

/** Page: hero, columns(col A: text, col B: empty), text. */
function layout() {
    const hero = createHeroNode({ heading: 'Hello' });
    let doc = createPageDocument([hero]);
    const columns = createNodes('columns');
    doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, columns));
    const [colA, colB] = doc.nodes[columns[0]!.id]!.children!;
    const inner = createNodes('text');
    doc = apply(doc, insertOps({ parentId: colA!, index: 0 }, inner));
    const tail = createNodes('text');
    doc = apply(doc, insertOps({ parentId: doc.root, index: 2 }, tail));
    return { doc, hero: hero.id, columns: columns[0]!.id, colA: colA!, colB: colB!, inner: inner[0]!.id, tail: tail[0]!.id };
}

describe('creating components', () => {
    it.each(['section', 'group', 'hero', 'text', 'image', 'button', 'columns'])('%s gets valid defaults at the page level', (type) => {
        const doc = createPageDocument([]);
        const next = apply(doc, insertOps({ parentId: doc.root, index: 0 }, createNodes(type)));
        expect(Object.keys(next.nodes)).toHaveLength(type === 'columns' ? 4 : 2);
    });

    it('columns start with two empty columns', () => {
        const [columns, ...rest] = createNodes('columns');
        expect(columns!.children).toHaveLength(2);
        expect(rest.map((n) => n.type)).toEqual(['column', 'column']);
    });
});

describe('where Add puts things', () => {
    it('inside a selected column, after a selected block, otherwise at the end of the page', () => {
        const l = layout();
        expect(insertionFor(l.doc, l.colB, 'button')).toEqual({ parentId: l.colB, index: 0 });
        expect(insertionFor(l.doc, l.inner, 'image')).toEqual({ parentId: l.colA, index: 1 });
        expect(insertionFor(l.doc, l.hero, 'text')).toEqual({ parentId: l.doc.root, index: 1 });
        expect(insertionFor(l.doc, null, 'text')).toEqual({ parentId: l.doc.root, index: 3 });
    });

    it('never nests what may not be nested: a hero or section chosen inside a column go after the columns', () => {
        const l = layout();
        expect(insertionFor(l.doc, l.inner, 'hero')).toEqual({ parentId: l.doc.root, index: 2 });
        expect(insertionFor(l.doc, l.colA, 'section')).toEqual({ parentId: l.doc.root, index: 2 });
        // Columns and groups nest inside columns.
        expect(insertionFor(l.doc, l.colA, 'columns')).toEqual({ parentId: l.colA, index: 1 });
    });
});

describe('moving', () => {
    it('moves up and down among siblings and stops at the ends', () => {
        const l = layout();
        const down = apply(l.doc, moveWithinParent(l.doc, l.hero, 1)!);
        expect(down.nodes[down.root]!.children).toEqual([l.columns, l.hero, l.tail]);
        expect(moveWithinParent(l.doc, l.hero, -1)).toBeNull();
        expect(moveWithinParent(l.doc, l.tail, 1)).toBeNull();
    });

    it('drops before, after and inside, adjusting for the node leaving its old place', () => {
        const l = layout();
        const move = (target: string, position: 'before' | 'after' | 'inside', nodeId = l.tail) =>
            dropPlacement(l.doc, { nodeId, type: l.doc.nodes[nodeId]!.type }, target, position);
        expect(move(l.hero, 'before')).toEqual({ parentId: l.doc.root, index: 0 });
        expect(move(l.columns, 'after')).toBeNull(); // already there
        expect(move(l.colB, 'inside')).toEqual({ parentId: l.colB, index: 0 });
        expect(move(l.columns, 'after', l.hero)).toEqual({ parentId: l.doc.root, index: 1 });
        const next = apply(l.doc, dropOps({ nodeId: l.tail, type: 'text' }, move(l.colB, 'inside')!));
        expect(next.nodes[l.colB]!.children).toEqual([l.tail]);
    });

    it('refuses invalid nesting and moving a container into itself', () => {
        const l = layout();
        expect(dropPlacement(l.doc, { nodeId: l.hero, type: 'hero' }, l.colA, 'inside')).toBeNull();
        expect(dropPlacement(l.doc, { nodeId: l.columns, type: 'columns' }, l.colA, 'inside')).toBeNull();
        expect(dropPlacement(l.doc, { nodeId: l.colA, type: 'column' }, l.hero, 'after')).toBeNull();
        expect(dropPlacement(l.doc, { nodeId: l.inner, type: 'text' }, l.columns, 'inside')).toBeNull();
        expect(dropPlacement(l.doc, { type: 'section' }, l.inner, 'after')).toBeNull();
        expect(dropPlacement(l.doc, { type: 'button' }, l.inner, 'after')).toEqual({ parentId: l.colA, index: 1 });
    });

    it('a column can move between the columns of the same block, but a full block takes no more', () => {
        const l = layout();
        expect(dropPlacement(l.doc, { nodeId: l.colB, type: 'column' }, l.colA, 'before')).toEqual({ parentId: l.columns, index: 0 });
        let doc = l.doc;
        doc = apply(doc, insertOps({ parentId: l.columns, index: 2 }, createNodes('column')));
        doc = apply(doc, insertOps({ parentId: l.columns, index: 3 }, createNodes('column')));
        doc = apply(doc, insertOps({ parentId: l.columns, index: 4 }, createNodes('column')));
        doc = apply(doc, insertOps({ parentId: l.columns, index: 5 }, createNodes('column')));
        expect(dropPlacement(doc, { type: 'column' }, l.colA, 'after')).toBeNull();
    });

    it('a column moves to another Columns block only if its own block keeps a column', () => {
        const l = layout();
        // A second Columns block with two columns, after the first.
        const second = createNodes('columns');
        let doc = apply(l.doc, insertOps({ parentId: l.doc.root, index: 2 }, second));
        const [colC] = doc.nodes[second[0]!.id]!.children!;
        // From a block with two columns: allowed, and the result is valid.
        const transfer = dropPlacement(doc, { nodeId: l.colB, type: 'column' }, colC!, 'after');
        expect(transfer).toEqual({ parentId: second[0]!.id, index: 1 });
        doc = apply(doc, dropOps({ nodeId: l.colB, type: 'column' }, transfer!));
        // Now the first block has one column left: it may not give it away, in any position...
        for (const position of ['before', 'after'] as const) {
            expect(dropPlacement(doc, { nodeId: l.colA, type: 'column' }, colC!, position)).toBeNull();
        }
        // ...but the block itself still moves, and a lone column still reorders within its own block.
        expect(dropPlacement(doc, { nodeId: l.columns, type: 'columns' }, l.tail, 'after')).toEqual({ parentId: doc.root, index: 3 });
        const third = apply(doc, insertOps({ parentId: l.columns, index: 1 }, createNodes('column')));
        expect(dropPlacement(third, { nodeId: l.colA, type: 'column' }, third.nodes[l.columns]!.children![1]!, 'after')).toEqual({
            parentId: l.columns,
            index: 1,
        });
    });
});

describe('removing', () => {
    it('keeps the minimum of one column and never removes the page', () => {
        const l = layout();
        expect(canRemove(l.doc, l.doc.root)).toBe(false);
        expect(canRemove(l.doc, l.colB)).toBe(true);
        const one = apply(l.doc, [{ op: 'removeNode', nodeId: l.colB }]);
        expect(canRemove(one, l.colA)).toBe(false);
        expect(canRemove(one, l.columns)).toBe(true);
    });
});

describe('undo and redo of structural changes', () => {
    const edit = (s: EditorDocState, ops: PageOperation[]) => {
        const out = dispatch(s, ops);
        if (!out.ok) throw new Error(out.issues.map((i) => i.message).join());
        return out.state;
    };

    it('undoes and redoes insert, move and remove exactly, and saves them in order', () => {
        const l = layout();
        let s = initialState(l.doc, 1);
        const button = createNodes('button');
        s = edit(s, insertOps({ parentId: l.colB, index: 0 }, button));
        s = edit(s, moveWithinParent(s.document, l.hero, 1)!);
        s = edit(s, [{ op: 'removeNode', nodeId: l.columns }]);
        expect(s.document.nodes[button[0]!.id]).toBeUndefined();

        const afterAll = structuredClone(s.document);
        s = undo(undo(undo(s)!)!)!;
        expect(s.document).toEqual(l.doc);
        s = redo(redo(redo(s)!)!)!;
        expect(s.document).toEqual(afterAll);

        // What is sent replays to the same document on the server.
        const batch = beginSave(s, () => 'k'.repeat(16))!.batch;
        expect(applyOperations(l.doc, batch.operations).doc).toEqual(afterAll);
    });

    it('rejects an invalid structural change without touching the document or history', () => {
        const l = layout();
        const s = initialState(l.doc, 1);
        const outcome = dispatch(s, [{ op: 'moveNode', nodeId: l.hero, parentId: l.colA, index: 0 }]);
        expect(outcome.ok).toBe(false);
        if (!outcome.ok) expect(outcome.issues.map((i) => i.message)).toContain('hero is not allowed inside column');
    });
});

describe('labels', () => {
    it('name the component and the start of its text', () => {
        expect(nodeLabel(createNodes('text')[0]!)).toBe('Text: Write something here.');
        expect(nodeLabel(createNodes('columns')[0]!)).toBe('Columns');
    });
});
