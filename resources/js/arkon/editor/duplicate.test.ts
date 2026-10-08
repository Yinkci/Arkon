import { describe, expect, it } from 'vitest';
import { validatePageDocument } from '../components/validate';
import { createHeroNode, createPageDocument } from '../components/factories';
import type { NodeId, PageDocument } from '../schema/document';
import { applyOperations, type PageOperation } from '../schema/operations';
import { createColumns, setColumnCount } from './columns';
import { duplicateBlock } from './duplicate';
import { dispatch, initialState, redo, undo } from './state';
import { createNodes, insertOps } from './structure';

function apply(doc: PageDocument, ops: PageOperation[]): PageDocument {
    const next = applyOperations(doc, ops).doc;
    expect(validatePageDocument(next)).toEqual([]);
    return next;
}

const ASSET = '01890a5d-ac96-774b-bcce-b302099a8057';
const COMPONENT = '01890a5d-ac96-774b-bcce-b302099a8058';

/** Page: hero, section(group(text, image, button), columns(column(text), column)), instance. */
function page() {
    let doc = createPageDocument([createHeroNode({ heading: 'Top' })]);
    const section = createNodes('section', { style: { root: { base: { animation: 'fade-up', animationTrigger: 'view' }, mobile: { animation: 'none' } } } });
    const group = createNodes('group', { style: { root: { base: { direction: 'row' }, mobile: { direction: 'column' } } } });
    const text = createNodes('text', { text: 'Inside', style: { root: { tablet: { textAlign: 'center' } } } });
    const image = createNodes('image', { image: { assetId: ASSET, alt: 'A photo' } });
    const button = createNodes('button', { label: 'Book now', href: '/book' });
    const columns = createColumns(2, '1fr 2fr');
    const instance = createNodes('instance', { componentId: COMPONENT });
    doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, section));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 0 }, group));
    doc = apply(doc, insertOps({ parentId: group[0]!.id, index: 0 }, text));
    doc = apply(doc, insertOps({ parentId: group[0]!.id, index: 1 }, image));
    doc = apply(doc, insertOps({ parentId: group[0]!.id, index: 2 }, button));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 1 }, columns));
    doc = apply(doc, insertOps({ parentId: columns[1]!.id, index: 0 }, createNodes('text', { text: 'Column text' })));
    doc = apply(doc, insertOps({ parentId: doc.root, index: 2 }, instance));
    return {
        doc,
        section: section[0]!.id,
        group: group[0]!.id,
        text: text[0]!.id,
        button: button[0]!.id,
        columns: columns[0]!.id,
        column: columns[1]!.id,
        instance: instance[0]!.id,
    };
}

const subtree = (doc: PageDocument, id: NodeId): NodeId[] => [id, ...(doc.nodes[id]?.children ?? []).flatMap((c) => subtree(doc, c))];

function duplicate(doc: PageDocument, id: NodeId) {
    const result = duplicateBlock(doc, id);
    if (!result.ok) throw new Error(result.reason);
    return { doc: apply(doc, result.ops), copyId: result.copyId, result };
}

describe('duplicating blocks', () => {
    it('copies a nested block right after itself with fresh ids, the same props, design and animation settings', () => {
        const p = page();
        const { doc, copyId } = duplicate(p.doc, p.section);
        expect(doc.nodes[doc.root]!.children!.indexOf(copyId)).toBe(doc.nodes[doc.root]!.children!.indexOf(p.section) + 1);
        const original = subtree(doc, p.section);
        const copy = subtree(doc, copyId);
        expect(copy).toHaveLength(original.length);
        expect(copy.filter((id) => original.includes(id))).toEqual([]);
        // Same types, props (styles for every screen, animation, images) in the same order.
        const shape = (ids: NodeId[]) => ids.map((id) => ({ type: doc.nodes[id]!.type, version: doc.nodes[id]!.version, props: doc.nodes[id]!.props }));
        expect(shape(copy)).toEqual(shape(original));
        expect(doc.nodes[copyId]!.props.style).toEqual({ root: { base: { animation: 'fade-up', animationTrigger: 'view' }, mobile: { animation: 'none' } } });
        expect(JSON.stringify(doc.nodes)).toContain(ASSET);
    });

    it('the copy is independent: editing it leaves the original alone', () => {
        const p = page();
        const { doc, copyId } = duplicate(p.doc, p.group);
        const copiedText = doc.nodes[copyId]!.children![0]!;
        const edited = apply(doc, [{ op: 'updateProps', nodeId: copiedText, set: { text: 'Changed', style: { root: { base: { color: '#ff0000' } } } } }]);
        expect(edited.nodes[p.text]!.props.text).toBe('Inside');
        expect(edited.nodes[p.text]!.props.style).toEqual({ root: { tablet: { textAlign: 'center' } } });
        // Nested objects are copies, not shared references.
        expect(edited.nodes[copiedText]!.props.style).not.toBe(edited.nodes[p.text]!.props.style);
    });

    it('a linked reusable component stays linked to the same component', () => {
        const p = page();
        const { doc, copyId } = duplicate(p.doc, p.instance);
        expect(doc.nodes[copyId]).toMatchObject({ type: 'instance', props: { componentId: COMPONENT } });
        expect(Object.values(doc.nodes).filter((n) => n.type === 'instance')).toHaveLength(2);
    });

    it('buttons, text and images are copied within their container', () => {
        const p = page();
        const { doc, copyId } = duplicate(p.doc, p.button);
        expect(doc.nodes[p.group]!.children!.slice(-2)).toEqual([p.button, copyId]);
        expect(doc.nodes[copyId]!.props).toMatchObject({ label: 'Book now', href: '/book' });
    });

    it('one undo step removes the copy, redo brings it back', () => {
        const p = page();
        const result = duplicateBlock(p.doc, p.section);
        if (!result.ok) throw new Error(result.reason);
        const state = initialState(p.doc, 1);
        const outcome = dispatch(state, result.ops);
        if (!outcome.ok) throw new Error('dispatch failed');
        expect(outcome.state.undo).toHaveLength(1);
        const undone = undo(outcome.state)!;
        expect(undone.document).toEqual(p.doc);
        expect(redo(undone)!.document.nodes[result.copyId]).toBeDefined();
    });

    it('a column is copied with its width, and Columns at six columns refuses', () => {
        const p = page();
        const { doc, copyId, result } = duplicate(p.doc, p.column);
        expect(doc.nodes[p.columns]!.children!.slice(0, 2)).toEqual([p.column, copyId]);
        // "1fr 2fr": the copied first column keeps its width.
        expect(doc.nodes[p.columns]!.props.style).toEqual({ root: { base: { columns: '1fr 1fr 2fr' }, mobile: { columns: '1' } } });
        expect(result.ok && result.notice).toBeNull();
        const full = setColumnCount(p.doc, p.columns, 6);
        if (!full.ok) throw new Error(full.reason);
        const six = apply(p.doc, full.ops);
        expect(duplicateBlock(six, p.column)).toEqual({ ok: false, reason: 'Columns already has 6 columns, the most allowed' });
    });

    it('refuses the page, a full container, too many blocks, and blocks with an unresolved field', () => {
        const p = page();
        expect(duplicateBlock(p.doc, p.doc.root)).toMatchObject({ ok: false, reason: 'The page itself can’t be duplicated' });
        let doc = p.doc;
        for (let i = doc.nodes[p.group]!.children!.length; i < 30; i++) doc = apply(doc, insertOps({ parentId: p.group, index: i }, createNodes('text')));
        expect(duplicateBlock(doc, p.text)).toMatchObject({ ok: false, reason: 'Group is full (30 blocks at most)' });
        expect(duplicateBlock(p.doc, p.section, [{ nodeId: p.button, label: 'Button link' }])).toMatchObject({
            ok: false,
            reason: expect.stringContaining('Fix or revert the button link in Button'),
        });
        // An unresolved field elsewhere does not block it.
        expect(duplicateBlock(p.doc, p.instance, [{ nodeId: p.button, label: 'Button link' }]).ok).toBe(true);
    });

    it('refuses copies beyond the page and insert limits', () => {
        const p = page();
        // A group of 30 groups of 6 texts: 211 blocks, more than one insert may hold.
        let doc = p.doc;
        const outer = createNodes('group');
        doc = apply(doc, insertOps({ parentId: p.section, index: 0 }, outer));
        for (let i = 0; i < 30; i++) {
            const inner = createNodes('group');
            doc = apply(doc, insertOps({ parentId: outer[0]!.id, index: i }, inner));
            for (let j = 0; j < 6; j++) doc = apply(doc, insertOps({ parentId: inner[0]!.id, index: j }, createNodes('text')));
        }
        expect(duplicateBlock(doc, outer[0]!.id)).toMatchObject({ ok: false, reason: expect.stringContaining('at most 200 can be duplicated at once') });
    });
});
