import { describe, expect, it } from 'vitest';
import { composeLength, splitLength, unitsOf } from './length';
import { styleRules, styleValueProblem } from './schema';

const height = styleRules.properties.height!;

describe('length editing', () => {
    it('offers the units the property accepts, px first', () => {
        expect(unitsOf(height)).toEqual(['px', '%', 'rem', 'em', 'vh', 'vw', 'ch']);
        expect(unitsOf(styleRules.properties.borderWidth!)).toEqual(['px']);
    });

    it('splits stored values into number and unit, keywords and tokens', () => {
        const units = unitsOf(height);
        expect(splitLength('500px', units)).toEqual({ kind: 'number', number: '500', unit: 'px' });
        expect(splitLength('2.5rem', units)).toEqual({ kind: 'number', number: '2.5', unit: 'rem' });
        expect(splitLength('auto', units)).toEqual({ kind: 'keyword', keyword: 'auto' });
        expect(splitLength('@space.lg', units)).toEqual({ kind: 'token', token: '@space.lg' });
        expect(splitLength('0', units)).toEqual({ kind: 'number', number: '0', unit: 'px' });
    });

    it('combines a typed number with the chosen unit, and keeps a typed unit', () => {
        expect(composeLength('500', 'px')).toBe('500px');
        expect(composeLength(' 60 ', 'vh')).toBe('60vh');
        expect(composeLength('50%', 'px')).toBe('50%');
        expect(composeLength('0', 'rem')).toBe('0');
        expect(composeLength('', 'px')).toBe('');
        // Composed values are still checked by the shared rules: nothing invalid is applied.
        expect(styleValueProblem(height, composeLength('5000', 'px'))).toMatch(/0 to 4000px/);
        expect(styleValueProblem(height, composeLength('12', 'deg'))).not.toBeNull();
        expect(styleValueProblem(height, composeLength('500', 'px'))).toBeNull();
    });
});
