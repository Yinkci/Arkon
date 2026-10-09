import { describe, it, expect } from 'vitest';
import source from '../../../arkon/slider-responsive.js?raw';
import { currentDefinition } from './registry';
import { parseProps } from './props';
function resolve(width: number, tablet: Record<string, string> = {}, mobile: Record<string, string> = {}) {
    const classes = new Set(['ak-slider', 'ak-slider--v8']);
    const root = {
        dataset: { sliderResponsive: JSON.stringify({ base: currentDefinition('slider')!.defaultProps, tablet, mobile }) } as Record<string, string>,
        classList: {
            [Symbol.iterator]: () => classes.values(),
            remove: (v: string) => classes.delete(v),
            add: (v: string) => classes.add(v),
            toggle: (v: string, on: boolean) => (on ? classes.add(v) : classes.delete(v)),
        },
        querySelector: () => null,
    };
    const apply = new Function('matchMedia', source + ';return applySliderScreen;')((q: string) => ({ matches: width <= (q.includes('599') ? 599 : 899) }));
    apply(root);
    return { root, classes };
}
describe('responsive slider settings', () => {
    it('uses desktop on every screen without overrides', () => {
        for (const width of [1440, 800, 390]) expect(resolve(width).root.dataset.interval).toBe('7000');
    });
    it('inherits tablet into mobile, with false preserved', () => {
        const result = resolve(390, { interval: '2000', autoplay: 'true', arrows: 'false' });
        expect(result.root.dataset.interval).toBe('2000');
        expect(result.root.dataset.autoplay).toBe('true');
        expect(result.classes.has('ak-slider--no-arrows')).toBe(true);
    });
    it('mobile overrides tablet and inherit resets it', () => {
        expect(resolve(390, { interval: '2000' }, { interval: '1000', autoplay: 'false' }).root.dataset.interval).toBe('1000');
        expect(resolve(390, { interval: '2000' }, { interval: 'inherit' }).root.dataset.interval).toBe('2000');
        expect(resolve(1440, { interval: '2000' }, { interval: '1000' }).root.dataset.interval).toBe('7000');
    });
    it('validates bounded overrides and rejects unknown screen fields', () => {
        const def = currentDefinition('slider')!;
        expect(parseProps(def.props, { ...def.defaultProps, responsive: { tablet: { interval: '1000' }, mobile: { arrows: 'false' } } }).issues).toEqual([]);
        expect(parseProps(def.props, { ...def.defaultProps, responsive: { tablet: { interval: '999' }, mobile: {} } }).issues.length).toBeGreaterThan(0);
        expect(parseProps(def.props, { ...def.defaultProps, responsive: { tablet: { script: 'bad' }, mobile: {} } }).issues.length).toBeGreaterThan(0);
    });
});
