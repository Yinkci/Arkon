import { afterEach, expect, test, vi } from 'vitest';
import policy from '../../arkon/media.json';
import { uploadPolicy, uploadSizeError, formatBytes } from './mediaPolicy';
afterEach(() => vi.unstubAllGlobals());
test('shared bytes enforce the inclusive limit and truthful unit labels', () => {
    for (const size of [1024, 2800000, Math.round(2.8 * 1024 ** 2), 4900000, policy.maxImageUploadBytes]) expect(uploadSizeError(size)).toBeNull();
    for (const size of [policy.maxImageUploadBytes + 1, 6 * 1024 ** 2]) expect(uploadSizeError(size)).toContain('maximum image size is 5 MiB');
    expect(formatBytes(policy.maxImageUploadBytes)).toBe('5 MiB');
});
test('a lower serving PHP limit is visible and does not blame image size', () => {
    vi.stubGlobal('document', { querySelector: () => ({ getAttribute: () => JSON.stringify({ effectiveMaxBytes: 2097152 }) }) });
    expect(uploadPolicy().runtimeLimited).toBe(true);
    expect(uploadSizeError(2800000)).toContain('server currently allows only 2 MiB');
});
