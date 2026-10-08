import { describe, expect, it } from 'vitest';
import { componentName, contextualLabel, defaultPart, partsOf, resolvePart } from './parts';

describe('component parts', () => {
    it('names every part of a hero for what it is', () => {
        expect(partsOf({ type: 'hero' }).map((p) => p.label)).toEqual(['Hero section', 'Content area', 'Heading', 'Supporting text', 'Buttons', 'Image']);
        expect(componentName({ type: 'hero' })).toBe('Hero section');
    });

    it('labels dimensions by the part they change', () => {
        const hero = { type: 'hero' };
        expect(contextualLabel(resolvePart(hero, 'media'), 'Height')).toBe('Image height');
        expect(contextualLabel(resolvePart(hero, 'root'), 'Width')).toBe('Section width');
        expect(contextualLabel(resolvePart(hero, 'content'), 'Max width')).toBe('Content area max width');
        expect(contextualLabel(resolvePart({ type: 'image' }, 'root'), 'Width')).toBe('Block width');
    });

    it('opens an image block on its image and a button on the button itself', () => {
        expect(defaultPart({ type: 'image' })).toBe('media');
        expect(defaultPart({ type: 'button' })).toBe('button');
        expect(defaultPart({ type: 'hero' })).toBe('root');
        expect(resolvePart({ type: 'text' }, 'media').slot).toBe('root');
    });
});
