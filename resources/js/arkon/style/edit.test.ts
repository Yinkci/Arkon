import { describe, expect, it } from 'vitest';
import { countAt, effectiveValue, inheritanceChain, withStyleValue, withoutBreakpoint } from './edit';
import type { Style } from './schema';

const hero: Style = {
    root: { base: { direction: 'row', gap: '@space.lg' }, mobile: { direction: 'column' } },
    media: { base: { height: '500px', objectFit: 'cover' } },
};

describe('responsive inheritance', () => {
    it('smaller screens inherit larger ones, nearest first', () => {
        expect(inheritanceChain('mobile')).toEqual(['mobile', 'tablet', 'base']);
        expect(inheritanceChain('tablet')).toEqual(['tablet', 'base']);
        expect(inheritanceChain('base')).toEqual(['base']);
    });

    it('reports where a value comes from', () => {
        expect(effectiveValue(hero, 'root', 'direction', 'base')).toEqual({ value: 'row', from: 'base' });
        expect(effectiveValue(hero, 'root', 'direction', 'tablet')).toEqual({ value: 'row', from: 'base' });
        expect(effectiveValue(hero, 'root', 'direction', 'mobile')).toEqual({ value: 'column', from: 'mobile' });
        expect(effectiveValue(hero, 'media', 'height', 'mobile')).toEqual({ value: '500px', from: 'base' });
        expect(effectiveValue(hero, 'media', 'aspectRatio', 'mobile')).toEqual({ value: undefined, from: null });
        expect(effectiveValue(undefined, 'root', 'gap', 'base')).toEqual({ value: undefined, from: null });
    });

    it('a tablet override is inherited by mobile until mobile sets its own', () => {
        let style = withStyleValue(hero, 'media', 'tablet', 'height', '320px');
        expect(effectiveValue(style, 'media', 'height', 'mobile')).toEqual({ value: '320px', from: 'tablet' });
        style = withStyleValue(style, 'media', 'mobile', 'height', '240px');
        expect(effectiveValue(style, 'media', 'height', 'mobile')).toEqual({ value: '240px', from: 'mobile' });
        expect(effectiveValue(style, 'media', 'height', 'base')).toEqual({ value: '500px', from: 'base' });
    });
});

describe('editing', () => {
    it('sets a value without touching the original', () => {
        const next = withStyleValue(hero, 'heading', 'base', 'color', '@color.primary');
        expect(next.heading).toEqual({ base: { color: '@color.primary' } });
        expect(hero.heading).toBeUndefined();
        expect(next.root).toEqual(hero.root);
    });

    it('resetting a value removes it and prunes empty screens and slots', () => {
        const once = withStyleValue(hero, 'root', 'mobile', 'direction', null);
        expect(once.root).toEqual({ base: { direction: 'row', gap: '@space.lg' } });
        const media = withStyleValue(withStyleValue(hero, 'media', 'base', 'height', null), 'media', 'base', 'objectFit', null);
        expect(media.media).toBeUndefined();
        expect(JSON.stringify(media)).not.toContain('{}');
    });

    it('resets every override of one screen', () => {
        const style = withStyleValue(hero, 'media', 'mobile', 'height', '240px');
        expect(countAt(style, 'mobile')).toBe(2);
        expect(countAt(style, 'mobile', 'media')).toBe(1);
        const media = withoutBreakpoint(style, 'mobile', 'media');
        expect(media.media).toEqual(hero.media);
        expect(media.root).toEqual(hero.root);
        const all = withoutBreakpoint(style, 'mobile');
        expect(countAt(all, 'mobile')).toBe(0);
        expect(all.root).toEqual({ base: hero.root!.base });
    });
});
