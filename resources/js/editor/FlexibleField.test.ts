import { describe, expect, it } from 'vitest';
import { applyOperations } from '@/arkon/schema/operations';
import { createNodes, insertOps } from '@/arkon/editor/structure';
import { createPageDocument } from '@/arkon/components/factories';
import { validatePageDocument } from '@/arkon/components/validate';

describe('a content-sections block', () => {
    it('is added with one layout template, and the template with something in it', () => {
        const [block, template, line] = createNodes('sections');

        expect(block!.type).toBe('sections');
        expect(block!.children).toEqual([template!.id]);
        expect(template!.type).toBe('section-template');
        expect(template!.children).toEqual([line!.id]);
        expect(line!.type).toBe('text');
        // Nothing is chosen yet: which field it draws and which layout the template is are the
        // author's first two decisions.
        expect(block!.props.field).toBe('');
        expect(template!.props.layout).toBe('');
    });

    it('is a valid document the moment it is inserted, and so is a second layout template', () => {
        const page = createPageDocument();
        const nodes = createNodes('sections');
        const withBlock = applyOperations(page, insertOps({ parentId: page.root, index: 0 }, nodes)).doc;
        expect(validatePageDocument(withBlock)).toEqual([]);

        const second = createNodes('section-template');
        const withSecond = applyOperations(withBlock, insertOps({ parentId: nodes[0]!.id, index: 1 }, second)).doc;
        expect(validatePageDocument(withSecond)).toEqual([]);
        expect(withSecond.nodes[nodes[0]!.id]!.children).toEqual([nodes[1]!.id, second[0]!.id]);
    });

    it('refuses a content-sections block inside a layout template', () => {
        const page = createPageDocument();
        const nodes = createNodes('sections');
        const doc = applyOperations(page, insertOps({ parentId: page.root, index: 0 }, nodes)).doc;
        const inner = createNodes('sections');
        const outcome = applyOperations(doc, insertOps({ parentId: nodes[1]!.id, index: 1 }, inner));

        expect(validatePageDocument(outcome.doc).map((issue) => issue.message)).toContain('sections is not allowed inside section-template');
    });
});
