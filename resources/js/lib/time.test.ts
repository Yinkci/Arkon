import { describe, expect, it } from 'vitest';
import { relativeTime } from './time';

describe('relativeTime', () => {
    const now = Date.parse('2026-10-07T12:00:00Z');
    const ago = (seconds: number) => new Date(now - seconds * 1000).toISOString();

    it('uses the largest whole unit', () => {
        expect(relativeTime(ago(20), now)).toBe('just now');
        expect(relativeTime(ago(5 * 60), now)).toBe('5 minutes ago');
        expect(relativeTime(ago(3 * 3600), now)).toBe('3 hours ago');
        expect(relativeTime(ago(26 * 3600), now)).toBe('yesterday');
        expect(relativeTime(ago(15 * 24 * 3600), now)).toBe('2 weeks ago');
        expect(relativeTime(ago(400 * 24 * 3600), now)).toBe('last year');
    });
});
