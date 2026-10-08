import { describe, expect, it } from 'vitest';
import { createHeroNode, createPageDocument } from '@/arkon/components/factories';
import type { PageOperation } from '@/arkon/schema/operations';
import { createSaveCoordinator, type SaveOutcome, type SaveResponse } from './saveCoordinator';
import { dispatch, initialState, type EditorDocState, type SaveBatch } from './state';

const hero = createHeroNode({ heading: 'Start' });
const doc = createPageDocument([hero]);
const heading = (value: string): PageOperation[] => [{ op: 'updateProps', nodeId: hero.id, set: { heading: value } }];

/**
 * The server's save contract (pages and components alike): a key seen before replays its
 * result when the request is identical and is refused otherwise; a new key must be based on
 * the current version. Requests can be made to commit and then lose their response.
 */
class FakeServer {
    version = 1;
    name = 'Banner';
    applied: SaveBatch[] = [];
    private lastKey: string | null = null;
    private lastFingerprint: string | null = null;
    /** What happens to the next request. */
    next: 'ok' | 'drop-before' | 'drop-after' | 'internal' = 'ok';
    /** Requests not answered yet (to interleave edits with an in-flight save). */
    held: (() => void)[] = [];
    hold = false;
    sent = 0;

    async send(batch: SaveBatch): Promise<SaveResponse> {
        this.sent++;
        if (this.hold) await new Promise<void>((resolve) => this.held.push(resolve));
        const mode = this.next;
        this.next = 'ok';
        if (mode === 'drop-before') throw new TypeError('Failed to fetch');
        const fingerprint = JSON.stringify([batch.baseVersion, batch.operations, batch.name ?? null]);
        let response: SaveResponse;
        if (batch.key === this.lastKey) {
            response =
                fingerprint === this.lastFingerprint
                    ? { ok: true, data: { version: this.version } }
                    : { ok: false, code: 'CONFLICT', message: 'This save key was already used for a different save' };
        } else if (batch.baseVersion !== this.version) {
            response = { ok: false, code: 'STALE_VERSION', message: 'changed elsewhere' };
        } else {
            this.version++;
            if (batch.name !== undefined) this.name = batch.name;
            this.applied.push(batch);
            this.lastKey = batch.key;
            this.lastFingerprint = fingerprint;
            response = { ok: true, data: { version: this.version } };
        }
        if (mode === 'drop-after') throw new TypeError('Failed to fetch');
        if (mode === 'internal') return { ok: false, code: 'INTERNAL', message: 'Something went wrong' };
        return response;
    }

    release() {
        const waiting = this.held.splice(0);
        waiting.forEach((resolve) => resolve());
    }
}

/** A component editor's session: document state, the name field, and what the user would see. */
function session() {
    const server = new FakeServer();
    let state: EditorDocState = initialState(doc, 1);
    let name = 'Banner';
    let savedName = 'Banner';
    const outcomes: SaveOutcome['kind'][] = [];
    let activity = 'idle';
    let keys = 0;
    const coordinator = createSaveCoordinator({
        getState: () => state,
        setState: (next) => (state = next),
        newKey: () => `key-${String(++keys).padStart(16, '0')}`,
        canSave: () => true,
        pendingName: () => (name.trim() !== savedName && name.trim() !== '' ? name.trim() : undefined),
        hasOtherChanges: () => name.trim() !== savedName,
        send: (batch) => server.send(batch),
        begin: () => {
            activity = 'saving';
            return () => (activity = 'idle');
        },
        onOutcome: (outcome) => {
            outcomes.push(outcome.kind);
            if (outcome.kind === 'saved' && outcome.batch.name !== undefined) savedName = outcome.batch.name;
        },
    });
    return {
        server,
        coordinator,
        outcomes,
        rename: (value: string) => (name = value),
        edit: (value: string) => {
            const outcome = dispatch(state, heading(value));
            if (!outcome.ok) throw new Error('invalid');
            state = outcome.state;
        },
        get state() {
            return state;
        },
        get savedName() {
            return savedName;
        },
        get activity() {
            return activity;
        },
    };
}

describe('component save recovery', () => {
    it('a rename-only save whose response is lost after commit is confirmed by retrying the same request', async () => {
        const s = session();
        s.rename('Hero banner');
        s.server.next = 'drop-after';
        expect(await s.coordinator.save()).toBeNull();
        expect(s.server.version).toBe(2);
        expect(s.coordinator.hasUnsaved()).toBe(true);

        expect(await s.coordinator.save()).toBe(2);
        expect(s.outcomes).toEqual(['uncertain', 'saved']);
        expect(s.server.applied).toHaveLength(1);
        expect([s.savedName, s.state.version, s.coordinator.hasUnsaved()]).toEqual(['Hero banner', 2, false]);
    });

    it('a request lost before reaching the server is sent again unchanged and applied once', async () => {
        const s = session();
        s.edit('One');
        s.rename('Renamed');
        s.server.next = 'drop-before';
        expect(await s.coordinator.save()).toBeNull();
        expect(s.server.version).toBe(1);
        const first = s.state.inFlight;
        expect(await s.coordinator.save()).toBe(2);
        expect(s.server.applied).toEqual([first]);
        expect(s.savedName).toBe('Renamed');
    });

    it('a server error keeps the request for a safe retry', async () => {
        const s = session();
        s.rename('Renamed');
        s.server.next = 'internal';
        expect(await s.coordinator.save()).toBeNull();
        expect(await s.coordinator.save()).toBe(2);
        expect(s.outcomes).toEqual(['uncertain', 'saved']);
    });

    it('renaming and editing during an in-flight save never change that request; they are saved next', async () => {
        const s = session();
        s.edit('First');
        s.rename('Name one');
        s.server.hold = true;
        const saving = s.coordinator.save();
        await Promise.resolve();
        const sent = s.state.inFlight!;
        expect(s.activity).toBe('saving');
        s.rename('Name two');
        s.edit('Second');
        expect(s.state.inFlight).toBe(sent);
        s.server.hold = false;
        s.server.release();
        expect(await saving).toBe(2);
        expect(s.activity).toBe('idle');
        expect(s.server.applied[0]).toMatchObject({ name: 'Name one' });
        expect([s.savedName, s.coordinator.hasUnsaved()]).toEqual(['Name one', true]);

        expect(await s.coordinator.save()).toBe(3);
        expect(s.server.applied[1]).toMatchObject({ name: 'Name two', baseVersion: 2 });
        expect(s.server.applied[1]!.operations).toEqual(heading('Second'));
        expect(s.coordinator.hasUnsaved()).toBe(false);
    });

    it('a later name edit after an uncertain response does not change the retried request', async () => {
        const s = session();
        s.rename('Name one');
        s.server.next = 'drop-after';
        await s.coordinator.save();
        s.rename('Name two');
        expect(await s.coordinator.save()).toBe(2);
        expect(s.outcomes).toEqual(['uncertain', 'saved']);
        expect(s.savedName).toBe('Name one');
        expect(await s.coordinator.save()).toBe(3);
        expect([s.server.name, s.savedName, s.coordinator.hasUnsaved()]).toEqual(['Name two', 'Name two', false]);
    });

    it('repeated save shortcuts share one request', async () => {
        const s = session();
        s.edit('Typed');
        s.server.hold = true;
        const a = s.coordinator.save();
        const b = s.coordinator.save();
        const c = s.coordinator.save();
        expect(a).toBe(b);
        expect(b).toBe(c);
        s.server.hold = false;
        s.server.release();
        expect(await Promise.all([a, b, c])).toEqual([2, 2, 2]);
        expect(s.server.sent).toBe(1);
    });

    it('save followed by publish: publish waits for the running save, then for later edits', async () => {
        const s = session();
        s.edit('Before publish');
        s.server.hold = true;
        const clicked = s.coordinator.save();
        await Promise.resolve();
        const publishing = s.coordinator.saveAll();
        s.rename('Renamed while saving');
        s.server.hold = false;
        s.server.release();
        expect(await clicked).toBe(2);
        expect(await publishing).toBe(3);
        expect(s.server.sent).toBe(2);
        expect([s.server.name, s.coordinator.hasUnsaved()]).toEqual(['Renamed while saving', false]);
    });

    it('a retry followed by saving later edits applies each batch once, in order', async () => {
        const s = session();
        s.edit('A');
        s.server.next = 'drop-after';
        await s.coordinator.save();
        s.edit('B');
        expect(await s.coordinator.save()).toBe(2);
        expect(await s.coordinator.save()).toBe(3);
        expect(s.server.applied.map((b) => b.operations)).toEqual([heading('A'), heading('B')]);
        expect(s.outcomes).toEqual(['uncertain', 'saved', 'saved']);
    });

    it('a definitive refusal returns the edits to the pending list, ahead of later ones', async () => {
        const s = session();
        s.edit('A');
        s.server.send = async () => ({ ok: false, code: 'VALIDATION', message: 'Not valid' });
        expect(await s.coordinator.save()).toBeNull();
        expect(s.outcomes).toEqual(['rejected']);
        expect([s.state.inFlight, s.state.pending]).toEqual([null, heading('A')]);
    });
});
