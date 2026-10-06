// Port of packages/schema/test/operations.test.ts.
import { describe, expect, it } from 'vitest';
import { createNodeId, validateStructure, type Node, type PageDocument } from './document';
import { OperationError, applyOperations, type PageOperation } from './operations';
import { pathIssues } from './paths';

function node(id: string, type: string, extra: Partial<Node> = {}): Node {
    return { id, type, version: 1, props: {}, ...extra };
}

function fixture(): PageDocument {
    return {
        schemaVersion: 1,
        root: 'root',
        nodes: {
            root: node('root', 'page', { children: ['aaaa', 'bbbb'] }),
            aaaa: node('aaaa', 'hero', { props: { heading: 'A' } }),
            bbbb: node('bbbb', 'section', { children: ['cccc'] }),
            cccc: node('cccc', 'hero', { props: { heading: 'C' } }),
        },
        seo: { title: 'Old title' },
    };
}

describe('applyOperations', () => {
    it('never mutates its input', () => {
        const doc = fixture();
        const snapshot = structuredClone(doc);
        applyOperations(doc, [{ op: 'updateProps', nodeId: 'aaaa', set: { heading: 'Changed' } }]);
        expect(doc).toEqual(snapshot);
    });

    const cases: [string, PageOperation[]][] = [
        ['updateProps', [{ op: 'updateProps', nodeId: 'aaaa', set: { heading: 'New', text: 'Added' }, unset: [] }]],
        ['updateProps unset', [{ op: 'updateProps', nodeId: 'aaaa', set: {}, unset: ['heading'] }]],
        ['updateSeo', [{ op: 'updateSeo', set: { description: 'Desc' }, unset: ['title'] }]],
        ['insertNode', [{ op: 'insertNode', parentId: 'root', index: 1, nodes: [node('dddd', 'hero')] }]],
        ['removeNode with subtree', [{ op: 'removeNode', nodeId: 'bbbb' }]],
        ['moveNode across parents', [{ op: 'moveNode', nodeId: 'cccc', parentId: 'root', index: 0 }]],
        ['moveNode within parent', [{ op: 'moveNode', nodeId: 'aaaa', parentId: 'root', index: 1 }]],
        [
            'sequence',
            [
                { op: 'updateProps', nodeId: 'aaaa', set: { heading: '1' } },
                { op: 'moveNode', nodeId: 'aaaa', parentId: 'bbbb', index: 1 },
                { op: 'removeNode', nodeId: 'cccc' },
            ],
        ],
    ];
    it.each(cases)('%s is undone exactly by its inverse', (_name, ops) => {
        const doc = fixture();
        const applied = applyOperations(doc, ops);
        expect(applied.doc).not.toEqual(doc);
        expect(applyOperations(applied.doc, applied.inverse).doc).toEqual(doc);
    });

    it('rejects moving a node into its own subtree', () => {
        expect(() => applyOperations(fixture(), [{ op: 'moveNode', nodeId: 'bbbb', parentId: 'cccc', index: 0 }])).toThrow(OperationError);
    });

    it('rejects removing the root and touching missing nodes', () => {
        expect(() => applyOperations(fixture(), [{ op: 'removeNode', nodeId: 'root' }])).toThrow(/root/);
        expect(() => applyOperations(fixture(), [{ op: 'updateProps', nodeId: 'zzzz', set: {} }])).toThrow(/does not exist/);
    });
});

describe('validateStructure', () => {
    it('detects nodes that are shared or detached', () => {
        const doc = fixture();
        doc.nodes.root!.children = ['aaaa', 'aaaa'];
        const messages = validateStructure(doc).map((i) => i.message);
        expect(messages).toContain('Node appears more than once in the tree');
        expect(messages).toContain('Node is not attached to the tree');
    });
});

describe('paths', () => {
    it.each(['/', '/about', '/services/whatsapp-sales', '/site'])('accepts %s', (path) => expect(pathIssues(path)).toEqual([]));
    it.each(['', 'about', '/About', '/a/', '/a--b', '/admin', '/admin/x', '/media', '/login', '/build/x'])('rejects %j', (path) =>
        expect(pathIssues(path).length).toBeGreaterThan(0),
    );
});

describe('createNodeId', () => {
    it('creates distinct base62 ids', () => {
        const ids = new Set(Array.from({ length: 500 }, createNodeId));
        expect(ids.size).toBe(500);
        for (const id of ids) expect(id).toMatch(/^[0-9A-Za-z]{10}$/);
    });
});
