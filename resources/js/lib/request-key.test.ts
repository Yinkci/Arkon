import { afterEach, describe, expect, it, vi } from 'vitest';
import rules from '../../arkon/rules.json';
import { newRequestKey } from './request-key';

const serverPattern = new RegExp(rules.patterns.requestKey);
/** The real generator, captured before the tests replace the global. */
const realCrypto = globalThis.crypto;

describe('newRequestKey', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('works where crypto.randomUUID is unavailable (http:// origins such as http://arkonlaravel.test)', () => {
        // What Chromium exposes in an insecure context: getRandomValues, no randomUUID.
        const getRandomValues = vi.fn((array: Uint8Array<ArrayBuffer>) => realCrypto.getRandomValues(array));
        vi.stubGlobal('crypto', { getRandomValues });
        expect(() => newRequestKey()).not.toThrow();
        expect(getRandomValues).toHaveBeenCalled();
    });

    it('keeps the established format the server accepts: 32 lowercase hex characters', () => {
        vi.stubGlobal('crypto', { getRandomValues: (array: Uint8Array<ArrayBuffer>) => realCrypto.getRandomValues(array) });
        const key = newRequestKey();
        expect(key).toMatch(/^[0-9a-f]{32}$/);
        expect(key).toMatch(serverPattern);
    });

    it('uses every random byte (no truncation or bias towards fixed characters)', () => {
        vi.stubGlobal('crypto', { getRandomValues: (array: Uint8Array<ArrayBuffer>) => array.fill(0xab) });
        expect(newRequestKey()).toBe('ab'.repeat(16));
    });

    it('produces distinct keys', () => {
        const keys = new Set(Array.from({ length: 2000 }, newRequestKey));
        expect(keys.size).toBe(2000);
    });
});
