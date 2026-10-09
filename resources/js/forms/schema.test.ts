import { describe, it, expect } from 'vitest';
import { field, place, newRow, copyField, remove, rows, template } from './schema';
describe('form layout editing', () => {
    it('preserves references while placing and reordering fields in columns', () => {
        const a = field('text'),
            b = field('email'),
            c = field('tel');
        let values = [a, b, c];
        values = place(values, b, a.row, 1);
        expect(rows(values).map((r) => r.fields.length)).toEqual([2, 1]);
        expect(values[0]?.width).toBe(6);
        expect(values[1]?.id).toBe(b.id);
        values = place(values, a, a.row, 2);
        expect(values.slice(0, 2).map((f) => f.id)).toEqual([b.id, a.id]);
        expect(values[2]?.id).toBe(c.id);
    });
    it('dropping a solitary field on its own row never moves it to the end', () => {
        const a = field('text'),
            b = field('email');
        expect(place([a, b], a, a.row, 1).map((f) => f.id)).toEqual([a.id, b.id]);
    });
    it('caps a row at four fields and preserves other row widths', () => {
        const a = field('text'),
            b = field('email'),
            c = field('tel'),
            d = field('date'),
            e = field('url');
        let values = [a, b, c, d, e];
        for (const source of [b, c, d]) values = place(values, source, a.row, 4);
        expect(rows(values)[0]?.fields).toHaveLength(4);
        expect(place(values, e, a.row, 4)).toEqual(values);
    });
    it('moving between rows gives the requested visual order without rewriting IDs', () => {
        const a = field('text'),
            b = field('email'),
            c = field('tel');
        expect(newRow([a, b, c], a, 3).map((f) => f.id)).toEqual([b.id, c.id, a.id]);
        expect(newRow([a, b, c], b, 0).map((f) => f.id)).toEqual([b.id, a.id, c.id]);
    });
    it('duplication creates a new reference and independent choices and conditions', () => {
        const a = field('select'),
            b = field('email');
        const result = copyField([a, b], a);
        expect(result.copy.id).not.toBe(a.id);
        expect(result.fields.map((f) => f.id)).toEqual([a.id, result.copy.id, b.id]);
        result.copy.choices[0]!.label = 'Changed';
        expect(a.choices[0]?.label).toBe('First choice');
    });
    it('deleting a field removes dangling conditional references but keeps existing IDs', () => {
        const a = field('select'),
            b = field('text');
        b.condition = { mode: 'all', rules: [{ fieldId: a.id, operator: 'is', value: 'first' }] };
        const values = remove([a, b], a.id);
        expect(values[0]?.id).toBe(b.id);
        expect(values[0]?.condition).toBeNull();
    });
    it('a blank template has no surprise required fields', () => {
        expect(template('Survey', 'blank').fields).toEqual([]);
        expect(template('Contact', 'contact').fields.map((f) => f.label)).toEqual(['Your name', 'Email', 'Message']);
    });
});
