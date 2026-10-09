import { describe, expect, it } from 'vitest';
import { patterns, createPattern } from './patterns';
import { createNodes, insertOps } from './structure';
import { createPageDocument } from '../components/factories';
import { validatePageDocument } from '../components/validate';
import { applyOperations } from '../schema/operations';

describe('reference layout patterns', () => {
    it.each(patterns.map((p) => p.id))('%s creates valid editable blocks with fresh ids', (id) => {
        const nodes = createPattern(id);
        const doc = createPageDocument([]);
        const next = applyOperations(doc, insertOps({ parentId: doc.root, index: 0 }, nodes)).doc;
        expect(validatePageDocument(next)).toEqual([]);
        const other = createPattern(id);
        expect(other.some((n) => nodes.some((original) => original.id === n.id))).toBe(false);
    });
    it('a new slider has two attached slide children', () => {
        const [slider, ...slides] = createNodes('slider');
        expect(slider!.children).toEqual(slides.map((s) => s.id));
    });
});

it('repeated starting layouts keep section anchors unique', () => {
    let doc = createPageDocument([]);
    for (let i = 0; i < 2; i++) {
        const nodes = createPattern('agency-services', doc);
        doc = applyOperations(doc, insertOps({ parentId: doc.root, index: i }, nodes)).doc;
    }
    expect(validatePageDocument(doc)).toEqual([]);
    expect(
        Object.values(doc.nodes)
            .filter((n) => n.props.anchor)
            .map((n) => n.props.anchor),
    ).toEqual(['services', 'services-2']);
});
