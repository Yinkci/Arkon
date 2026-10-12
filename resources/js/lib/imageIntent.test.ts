import { describe, expect, it } from 'vitest';
import { imageExamples, requestsGeneratedImage } from './imageIntent';

describe('requestsGeneratedImage', () => {
    it('matches the shared examples exactly like the PHP twin', () => {
        for (const [text, expected] of Object.entries(imageExamples)) {
            expect(requestsGeneratedImage(text), text).toBe(expected);
        }
    });
});
