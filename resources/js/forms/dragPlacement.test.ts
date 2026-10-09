import { describe, it, expect } from 'vitest';
import { formDrop, scrollVelocity, type FormRowGeometry } from './dragPlacement';
const rows: FormRowGeometry[] = [
    {
        id: 'a',
        rect: { left: 0, top: 0, width: 400, height: 100 },
        cards: [
            { id: 'name', rect: { left: 0, top: 0, width: 190, height: 100 } },
            { id: 'email', rect: { left: 210, top: 0, width: 190, height: 100 } },
        ],
    },
    { id: 'b', rect: { left: 0, top: 120, width: 400, height: 100 }, cards: [{ id: 'message', rect: { left: 0, top: 120, width: 400, height: 100 } }] },
];
const source = { type: 'text', nodeId: 'name', label: 'Name', origin: 'canvas' as const };
describe('form insertion geometry', () => {
    it('distinguishes row edges from column centers', () => {
        expect(formDrop(rows, source, 200, 116, null)).toMatchObject({ parentId: 'newrow:1', indicator: { axis: 'y' } });
        expect(formDrop(rows, source, 350, 165, null)).toMatchObject({ parentId: 'b', index: 1, indicator: { axis: 'x' } });
    });
    it('holds column boundaries within an eight pixel dead band', () => {
        expect(formDrop(rows, source, 99, 50, { parentId: 'a', index: 0 })).toMatchObject({ index: 0 });
        expect(formDrop(rows, source, 110, 50, { parentId: 'a', index: 0 })).toMatchObject({ index: 1 });
    });
    it('inserts after the last row and handles an empty canvas', () => {
        expect(formDrop(rows, source, 100, 260, null)).toMatchObject({ parentId: 'newrow:2' });
        expect(formDrop([], source, 100, 50, null)).toMatchObject({ parentId: 'newrow:0' });
    });
    it('refuses a fifth column but allows reordering one of four', () => {
        const full = { ...rows[0]!, cards: [...rows[0]!.cards, ...rows[0]!.cards.map((c) => ({ ...c, id: c.id + '2' }))] };
        expect(formDrop([full], { ...source, nodeId: 'other' }, 150, 50, null).kind).toBe('invalid');
        expect(formDrop([full], source, 150, 50, null).kind).toBe('place');
    });
    it('does not create invisible desktop columns from stacked mobile fields', () => {
        expect(formDrop(rows, source, 350, 165, null, true)).toMatchObject({ parentId: 'newrow:1' });
    });
    it('scroll speed is in pixels per second and tapers at the edge', () => {
        expect(scrollVelocity(500, 0, 500)).toBe(450);
        expect(scrollVelocity(476, 0, 500)).toBe(225);
        expect(scrollVelocity(250, 0, 500)).toBe(0);
        expect(scrollVelocity(0, 0, 500)).toBe(-450);
    });
});
