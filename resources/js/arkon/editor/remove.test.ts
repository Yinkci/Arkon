import { describe, expect, it } from 'vitest';
import { validatePageDocument } from '../components/validate';
import { createHeroNode, createPageDocument } from '../components/factories';
import type { PageDocument } from '../schema/document';
import { applyOperations, type PageOperation } from '../schema/operations';
import { createColumns } from './columns';
import { removalOf } from './remove';
import { dispatch, initialState, redo, undo } from './state';
import { createNodes, insertOps } from './structure';

function apply(doc: PageDocument, ops: PageOperation[]): PageDocument {
    const next = applyOperations(doc, ops).doc;
    expect(validatePageDocument(next)).toEqual([]);
    return next;
}

const ASSET = '01890a5d-ac96-774b-bcce-b302099a8057';

/** Page: hero (image, button), section(text, button, group(text)), columns 1fr 2fr 1fr (text | empty | empty). */
function page() {
    const hero = createHeroNode({ heading: 'Top', image: { assetId: ASSET, alt: 'Garden' } });
    let doc = createPageDocument([hero]);
    const heroButton = createNodes('button', { label: 'Book' });
    const section = createNodes('section');
    const text = createNodes('text', { text: 'Hello' });
    const button = createNodes('button', { label: 'Go' });
    const group = createNodes('group');
    const inner = createNodes('text', { text: 'Inner' });
    const columns = createColumns(3, '1fr 2fr 1fr');
    doc = apply(doc, insertOps({ parentId: hero.id, index: 0 }, heroButton));
    doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, section));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 0 }, text));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 1 }, button));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 2 }, group));
    doc = apply(doc, insertOps({ parentId: group[0]!.id, index: 0 }, inner));
    doc = apply(doc, insertOps({ parentId: doc.root, index: 2 }, columns));
    doc = apply(doc, insertOps({ parentId: columns[1]!.id, index: 0 }, createNodes('text', { text: 'In a column' })));
    return {
        doc,
        hero: hero.id,
        section: section[0]!.id,
        text: text[0]!.id,
        button: button[0]!.id,
        group: group[0]!.id,
        columns: columns[0]!.id,
        cols: columns.slice(1).map((c) => c.id),
    };
}

describe('deleting the selection', () => {
    it('a leaf block goes at once, named for what it is, and the next block is selected', () => {
        const p = page();
        const removal = removalOf(p.doc, p.button);
        expect(removal).toMatchObject({ ok: true, label: 'Delete button', confirm: false, removed: [p.button], select: p.group });
        const next = apply(p.doc, removal.ok ? removal.ops : []);
        expect(next.nodes[p.section]!.children).toEqual([p.text, p.group]);
        // The last block of a container: the previous one; the only one: the parent.
        expect(removalOf(p.doc, p.group)).toMatchObject({ select: p.button });
        expect(removalOf(p.doc, p.doc.nodes[p.group]!.children![0]!)).toMatchObject({ label: 'Delete text', select: p.group });
    });

    it('a container holding content asks first and says its contents go too; empty columns are not content', () => {
        const p = page();
        expect(removalOf(p.doc, p.section)).toMatchObject({ ok: true, label: 'Delete section and its contents', confirm: true, contents: 4 });
        expect(removalOf(p.doc, p.hero)).toMatchObject({ label: 'Delete hero section and its contents', confirm: true });
        const empty = apply(p.doc, [{ op: 'removeNode', nodeId: p.doc.nodes[p.cols[0]!]!.children![0]! }]);
        expect(removalOf(empty, p.columns)).toMatchObject({ label: 'Delete columns', confirm: false });
    });

    it('one undo step brings everything back; redo removes it again', () => {
        const p = page();
        const removal = removalOf(p.doc, p.section);
        if (!removal.ok) throw new Error(removal.reason);
        const outcome = dispatch(initialState(p.doc, 1), removal.ops);
        if (!outcome.ok) throw new Error('dispatch failed');
        expect(outcome.state.document.nodes[p.group]).toBeUndefined();
        const undone = undo(outcome.state)!;
        expect(undone.document).toEqual(p.doc);
        expect(redo(undone)!.document.nodes[p.section]).toBeUndefined();
    });

    it('a column takes its width with it; the last column, the page and missing blocks are refused', () => {
        const p = page();
        const removal = removalOf(p.doc, p.cols[1]!);
        if (!removal.ok) throw new Error(removal.reason);
        expect(removal.label).toBe('Delete column');
        expect(apply(p.doc, removal.ops).nodes[p.columns]!.props.style).toEqual({ root: { mobile: { columns: '1' }, base: { columns: '1fr 1fr' } } });
        let doc = p.doc;
        for (const id of p.cols.slice(1)) doc = apply(doc, (removalOf(doc, id) as { ops: PageOperation[] }).ops);
        expect(removalOf(doc, p.cols[0]!)).toMatchObject({
            ok: false,
            reason: 'A Columns block needs at least one column. Delete the whole Columns block instead.',
        });
        expect(removalOf(doc, doc.root)).toMatchObject({ ok: false, reason: 'The page itself can’t be deleted' });
        expect(removalOf(doc, 'missing1')).toMatchObject({ ok: false });
    });

    it('with only the hero image selected, it removes the image and keeps the hero', () => {
        const p = page();
        const removal = removalOf(p.doc, p.hero, 'media');
        expect(removal).toMatchObject({ ok: true, label: 'Remove image', confirm: false, removed: [], select: p.hero });
        const next = apply(p.doc, removal.ok ? removal.ops : []);
        expect(next.nodes[p.hero]!.props.image).toBeNull();
        expect(next.nodes[p.hero]!.props.heading).toBe('Top');
        // Another part of the hero (or no image): the whole hero, named as such.
        expect(removalOf(p.doc, p.hero, 'heading')).toMatchObject({ label: 'Delete hero section and its contents' });
        expect(removalOf(next, p.hero, 'media')).toMatchObject({ label: 'Delete hero section and its contents' });
    });
});
