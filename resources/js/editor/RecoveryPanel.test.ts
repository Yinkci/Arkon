import { describe, expect, it } from 'vitest';
import type { PageDocument } from '@/arkon/schema/document';
import type { RecoveryItem } from '@/types';
import { repairOps } from './RecoveryPanel';

const item = (nodeId: string, value: string): RecoveryItem => ({ nodeId, type: 'button', path: 'href', value, message: 'unsafe' });

describe('repairOps', () => {
    const items = [item('a', '/\\one.example'), item('b', 'https://\\two.example')];

    it('is null until every stored value is corrected with a valid link or its block removed', () => {
        expect(repairOps(items, {})).toBeNull();
        expect(repairOps(items, { 'a:href': { action: 'correct', value: '/contact' } })).toBeNull();
        expect(repairOps(items, { 'a:href': { action: 'correct', value: '/\\still.bad' }, 'b:href': { action: 'remove' } })).toBeNull();
        expect(repairOps(items, { 'a:href': { action: 'correct', value: '' }, 'b:href': { action: 'remove' } })).toEqual([
            { op: 'updateProps', nodeId: 'a', set: { href: '' } },
            { op: 'removeNode', nodeId: 'b' },
        ]);
    });

    it('corrects kept blocks and removes the others, never updating a removed block', () => {
        expect(
            repairOps(items, { 'a:href': { action: 'correct', value: '/contact' }, 'b:href': { action: 'correct', value: 'https://two.example/' } }),
        ).toEqual([
            { op: 'updateProps', nodeId: 'a', set: { href: '/contact' } },
            { op: 'updateProps', nodeId: 'b', set: { href: 'https://two.example/' } },
        ]);
        expect(repairOps(items, { 'a:href': { action: 'remove' }, 'b:href': { action: 'remove' } })).toEqual([
            { op: 'removeNode', nodeId: 'a' },
            { op: 'removeNode', nodeId: 'b' },
        ]);
    });
});

describe('repairOps for Columns widths', () => {
    const doc = {
        schemaVersion: 1,
        root: 'root',
        seo: {},
        nodes: {
            root: { id: 'root', type: 'page', version: 3, props: {}, children: ['cols'] },
            cols: {
                id: 'cols',
                type: 'columns',
                version: 3,
                props: { style: { root: { base: { columns: '1fr 2fr 1fr' }, tablet: { columns: '1fr 1fr 1fr 1fr 1fr' }, mobile: { columns: '1' } } } },
                children: ['a', 'b'],
            },
        },
    } as unknown as PageDocument;
    const widths = (path: string, value: string): RecoveryItem => ({ nodeId: 'cols', type: 'columns', path, value, message: 'Columns: …' });
    const items = [widths('style.root.base.columns', '1fr 2fr 1fr'), widths('style.root.tablet.columns', '1fr 1fr 1fr 1fr 1fr')];

    it('resets each listed screen to equal widths in one update, keeping the rest (phones stay stacked)', () => {
        expect(repairOps(items, { 'cols:style.root.base.columns': { action: 'reset' } }, doc)).toBeNull();
        expect(repairOps(items, { 'cols:style.root.base.columns': { action: 'reset' }, 'cols:style.root.tablet.columns': { action: 'reset' } }, doc)).toEqual([
            { op: 'updateProps', nodeId: 'cols', set: { style: { root: { mobile: { columns: '1' } } } } },
        ]);
        expect(repairOps(items, { 'cols:style.root.base.columns': { action: 'remove' } }, doc)).toEqual([{ op: 'removeNode', nodeId: 'cols' }]);
    });
});
