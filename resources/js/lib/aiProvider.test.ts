import { describe, expect, it } from 'vitest';
import { selectedProvider } from './aiProvider';
describe('connection-based selection', () => {
    const providers = [
        { id: 'codex', name: 'Codex', ready: true, message: 'Codex connected' },
        { id: 'claude-code', name: 'Claude Code', ready: true, message: 'Claude connected' },
    ];
    it('shows the sole provider returned by the server automatically', () => {
        const connection = {
            ready: true,
            provider: 'codex',
            providerName: 'Codex',
            message: 'Using Codex.',
            selectionState: 'automatic' as const,
            providers: [providers[0]!],
        };
        expect(selectedProvider(connection)).toBe(connection);
    });
    it('does not pick the first provider when a choice is required', () => {
        const connection = { ready: false, provider: null, message: 'Choose an AI', selectionState: 'choice_required' as const, providers };
        expect(selectedProvider(connection).ready).toBe(false);
        expect(selectedProvider(connection, 'codex')).toMatchObject({ ready: true, provider: 'codex', message: 'Codex connected' });
    });
    it('does not substitute the sole remaining provider for an explicit disconnected choice', () => {
        const connection = { ready: true, provider: 'claude-code', message: 'Using Claude', providers: [providers[1]!, { ...providers[0]!, ready: false }] };
        expect(selectedProvider(connection, 'codex').ready).toBe(false);
        expect(selectedProvider(connection, 'unknown').ready).toBe(false);
    });
});
