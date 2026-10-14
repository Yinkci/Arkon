import { describe, expect, it } from 'vitest';
import { createNodes } from '@/arkon/editor/structure';
import { emptyQuery, queryOf } from './CollectionPanel';

describe('a content list', () => {
    it('is added with its item template, bound to each entry title', () => {
        const [list, item] = createNodes('collection');

        expect(list!.type).toBe('collection');
        expect(list!.children).toEqual([item!.id]);
        expect(item!.type).toBe('text');
        expect(item!.bindings).toEqual({ text: { source: 'entry', field: 'title' } });
        // Nothing is chosen for it yet: the query is what the manifest says it is.
        expect(list!.props.query).toEqual(emptyQuery());
    });

    it('reads a stored query, filling in whatever an older document left out', () => {
        expect(queryOf({})).toEqual({ source: '', sort: '-published_at', limit: 6, filters: [] });
        expect(queryOf({ query: { source: 'service', limit: 3 } })).toEqual({ source: 'service', sort: '-published_at', limit: 3, filters: [] });
        expect(queryOf({ query: { source: 'service', filters: [{ field: 'featured', operator: 'is_true' }] } }).filters).toEqual([
            { field: 'featured', operator: 'is_true', value: '', value2: '' },
        ]);
        // Nonsense in the document never reaches a control as undefined.
        expect(queryOf({ query: { source: 7, sort: null, limit: 'six', filters: 'all' } })).toEqual({
            source: '',
            sort: '-published_at',
            limit: 6,
            filters: [],
        });
    });
});
