import { describe, expect, it } from 'vitest';
import { validatePageDocument } from '../components/validate';
import { createPageDocument } from '../components/factories';
import type { PageDocument } from '../schema/document';
import { applyOperations, type PageOperation } from '../schema/operations';
import type { Style } from '../style/schema';
import { createColumns } from './columns';
import { animateInsteadOps, animateInsteadTargets, isFirstBlock, motionStatus, type Protection } from './motion';
import { createNodes, insertOps } from './structure';

function apply(doc: PageDocument, ops: PageOperation[]): PageDocument {
    const next = applyOperations(doc, ops).doc;
    expect(validatePageDocument(next)).toEqual([]);
    return next;
}

const ASSET = '01890a5d-ac96-774b-bcce-b302099a8057';
const COMPONENT = '01890a5d-ac96-774b-bcce-b302099a8058';

/**
 * The renderer's protection map: each cause and every block holding it (the image that loads first
 * wins over the heading in a block holding both). PageRenderer reports the same shape.
 */
function protectionOf(doc: PageDocument, causes: { id: string; reason: Protection['reason'] }[]): Record<string, Protection> {
    const out: Record<string, Protection> = {};
    const path = (id: string): string[] => {
        if (id === doc.root) return [id];
        const parent = Object.values(doc.nodes).find((n) => n.children?.includes(id))!;
        return [id, ...path(parent.id)];
    };
    for (const reason of ['image', 'heading'] as const)
        for (const cause of causes.filter((c) => c.reason === reason)) for (const id of path(cause.id)) out[id] ??= { reason, cause: cause.id };
    return out;
}
const fadeUp: Style = { root: { base: { animation: 'fade-up', animationTrigger: 'load', animationDelay: '700ms' } } };

/** The About Us shape: a section (animated) with columns: [image (LCP) + button] [image + button], then more columns. */
function about() {
    let doc = createPageDocument([]);
    const section = createNodes('section', { style: fadeUp });
    const columns = createColumns(2);
    const lcp = createNodes('image', { image: { assetId: ASSET, alt: 'Team' } });
    const other = createNodes('image', {
        image: { assetId: ASSET, alt: 'Office' },
        style: { root: { base: { animation: 'fade', animationTrigger: 'view' } } },
    });
    const b1 = createNodes('button', { label: 'Meet us' });
    const b2 = createNodes('button', { label: 'Visit' });
    const more = createColumns(1);
    doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, section));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 0 }, columns));
    doc = apply(doc, insertOps({ parentId: columns[1]!.id, index: 0 }, lcp));
    doc = apply(doc, insertOps({ parentId: columns[1]!.id, index: 1 }, b1));
    doc = apply(doc, insertOps({ parentId: columns[2]!.id, index: 0 }, other));
    doc = apply(doc, insertOps({ parentId: columns[2]!.id, index: 1 }, b2));
    doc = apply(doc, insertOps({ parentId: section[0]!.id, index: 1 }, more));
    return {
        doc,
        section: section[0]!.id,
        columns: columns[0]!.id,
        col1: columns[1]!.id,
        col2: columns[2]!.id,
        lcp: lcp[0]!.id,
        other: other[0]!.id,
        b1: b1[0]!.id,
        more: more[0]!.id,
    };
}

describe('the effective status of an entrance', () => {
    const view: Style = { root: { base: { animation: 'zoom', animationTrigger: 'view' }, mobile: { animation: 'none' } } };

    it('plays, is off on this screen, or is protected content; none without an effect', () => {
        expect(motionStatus({}, 'base')).toEqual({ kind: 'none' });
        expect(motionStatus(view, 'base')).toEqual({ kind: 'plays', trigger: 'view' });
        expect(motionStatus(view, 'tablet')).toEqual({ kind: 'plays', trigger: 'view' });
        expect(motionStatus(view, 'mobile')).toEqual({ kind: 'off-here', breakpoint: 'mobile' });
        expect(motionStatus(fadeUp, 'base', { reason: 'image', cause: 'img' })).toEqual({ kind: 'protected', protection: { reason: 'image', cause: 'img' } });
        // A tablet-only entrance: off on all screens, plays on tablets and phones.
        const tabletOnly: Style = { root: { tablet: { animation: 'fade' } } };
        expect(motionStatus(tabletOnly, 'base')).toEqual({ kind: 'off-here', breakpoint: 'base' });
        expect(motionStatus(tabletOnly, 'mobile')).toEqual({ kind: 'plays', trigger: 'load' });
    });
});

describe('animating the other blocks of a protected container instead', () => {
    it('finds the blocks next to the protected image, never the image or what holds it, and keeps their own entrances', () => {
        const a = about();
        const map = protectionOf(a.doc, [{ id: a.lcp, reason: 'image' }]);
        // The section holds the LCP image: look into the columns and column holding it.
        expect(animateInsteadTargets(a.doc, a.section, map)).toEqual([a.b1, a.col2, a.more]);
        // Itself the cause, or not protected at all: nothing to offer.
        expect(animateInsteadTargets(a.doc, a.lcp, map)).toEqual([]);
        expect(animateInsteadTargets(a.doc, a.col2, map)).toEqual([]);
    });

    it('skips every protected block: the main heading too when the section is reported for its image', () => {
        let doc = createPageDocument([]);
        const section = createNodes('section', { style: fadeUp });
        const h1 = createNodes('text', { text: 'About us', element: 'h1' });
        const img = createNodes('image', { image: { assetId: ASSET, alt: 'Team' } });
        const button = createNodes('button', { label: 'Meet us' });
        doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, section));
        for (const [i, block] of [h1, img, button].entries()) doc = apply(doc, insertOps({ parentId: section[0]!.id, index: i }, block));
        const map = protectionOf(doc, [
            { id: img[0]!.id, reason: 'image' },
            { id: h1[0]!.id, reason: 'heading' },
        ]);
        expect(map[section[0]!.id]).toEqual({ reason: 'image', cause: img[0]!.id });
        expect(animateInsteadTargets(doc, section[0]!.id, map)).toEqual([button[0]!.id]);
        const next = apply(doc, animateInsteadOps(doc, doc.nodes[section[0]!.id]!, [button[0]!.id]));
        expect(next.nodes[h1[0]!.id]!.props).toEqual(doc.nodes[h1[0]!.id]!.props);
        expect(next.nodes[img[0]!.id]!.props).toEqual(doc.nodes[img[0]!.id]!.props);
    });

    it('looks into nested protected containers, never into a protected reusable component, and offers nothing when nothing is left', () => {
        // section [ group [ h1, button1 ], columns [ column [ image, button2 ], column [ text ] ], instance (holds an image) ]
        let doc = createPageDocument([]);
        const section = createNodes('section', { style: fadeUp });
        const group = createNodes('group');
        const h1 = createNodes('text', { text: 'About us', element: 'h1' });
        const button1 = createNodes('button', { label: 'One' });
        const columns = createColumns(2);
        const img = createNodes('image', { image: { assetId: ASSET, alt: 'Team' } });
        const button2 = createNodes('button', { label: 'Two' });
        const plain = createNodes('text', { text: 'Plain' });
        const instance = createNodes('instance', { componentId: COMPONENT });
        const s = section[0]!.id;
        doc = apply(doc, insertOps({ parentId: doc.root, index: 0 }, section));
        doc = apply(doc, insertOps({ parentId: s, index: 0 }, group));
        doc = apply(doc, insertOps({ parentId: group[0]!.id, index: 0 }, h1));
        doc = apply(doc, insertOps({ parentId: group[0]!.id, index: 1 }, button1));
        doc = apply(doc, insertOps({ parentId: s, index: 1 }, columns));
        doc = apply(doc, insertOps({ parentId: columns[1]!.id, index: 0 }, img));
        doc = apply(doc, insertOps({ parentId: columns[1]!.id, index: 1 }, button2));
        doc = apply(doc, insertOps({ parentId: columns[2]!.id, index: 0 }, plain));
        doc = apply(doc, insertOps({ parentId: s, index: 2 }, instance));
        // The component's own image (inside it) is reported on the instance, as the renderer does.
        const map = protectionOf(doc, [
            { id: img[0]!.id, reason: 'image' },
            { id: h1[0]!.id, reason: 'heading' },
        ]);
        map[instance[0]!.id] = { reason: 'image', cause: instance[0]!.id };
        expect(animateInsteadTargets(doc, s, map)).toEqual([button1[0]!.id, button2[0]!.id, columns[2]!.id]);
        expect(animateInsteadTargets(doc, group[0]!.id, map)).toEqual([button1[0]!.id]);
        expect(animateInsteadTargets(doc, instance[0]!.id, map)).toEqual([]);
        // Nothing left that could animate: no targets (the editor then offers no move).
        let only = createPageDocument([]);
        const lone = createNodes('section', { style: fadeUp });
        const heading = createNodes('text', { text: 'Welcome', element: 'h1' });
        only = apply(only, insertOps({ parentId: only.root, index: 0 }, lone));
        only = apply(only, insertOps({ parentId: lone[0]!.id, index: 0 }, heading));
        expect(animateInsteadTargets(only, lone[0]!.id, protectionOf(only, [{ id: heading[0]!.id, reason: 'heading' }]))).toEqual([]);
    });

    it('moves the container’s settings (every screen) to them in one edit and leaves the protected image alone', () => {
        const a = about();
        const targets = animateInsteadTargets(a.doc, a.section, protectionOf(a.doc, [{ id: a.lcp, reason: 'image' }]));
        const next = apply(a.doc, animateInsteadOps(a.doc, a.doc.nodes[a.section]!, targets));
        for (const id of targets) expect(next.nodes[id]!.props.style).toMatchObject(fadeUp);
        expect(next.nodes[a.section]!.props.style).toEqual({});
        expect(next.nodes[a.lcp]!.props).toEqual(a.doc.nodes[a.lcp]!.props);
        expect(next.nodes[a.other]!.props.style).toEqual({ root: { base: { animation: 'fade', animationTrigger: 'view' } } });
        expect(Object.keys(next.nodes)).toEqual(Object.keys(a.doc.nodes)); // no wrappers
    });

    it('knows the first top-level block (the default trigger there is on page load)', () => {
        const a = about();
        expect(isFirstBlock(a.doc, a.b1)).toBe(true);
        let doc = a.doc;
        const later = createNodes('text');
        doc = apply(doc, insertOps({ parentId: doc.root, index: 1 }, later));
        expect(isFirstBlock(doc, later[0]!.id)).toBe(false);
    });
});
