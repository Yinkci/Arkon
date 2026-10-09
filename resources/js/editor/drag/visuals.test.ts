import { describe, it, expect } from 'vitest';
import { previewTransform } from './visuals';
describe('drag grip position', () => {
    it('keeps the original grip offset', () =>
        expect(previewTransform(220, 150, { x: 120, y: 80, rect: { left: 100, top: 50, width: 400, height: 200 } })).toBe('translate3d(200px,120px,0)'));
    it('scales the grip together with large previews', () =>
        expect(previewTransform(220, 150, { x: 120, y: 80, rect: { left: 100, top: 50, width: 400, height: 200 }, scale: 0.5 })).toBe(
            'translate3d(210px,135px,0)',
        ));
});
