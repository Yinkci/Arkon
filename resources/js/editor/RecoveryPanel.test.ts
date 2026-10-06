import { describe, expect, it } from 'vitest';
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
