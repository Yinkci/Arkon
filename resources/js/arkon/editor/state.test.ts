import { describe, expect, it } from 'vitest';
import { createHeroNode, createPageDocument } from '@/arkon/components/factories';
import { applyOperations, type PageOperation } from '@/arkon/schema/operations';
import type { PageDocument } from '@/arkon/schema/document';
import {
    beginSave,
    dispatch,
    hasUnsavedChanges,
    initialState,
    isDefinitiveOutcome,
    publishIntentFor,
    saveRejected,
    saveSucceeded,
    undo,
    withServerVersion,
    type EditorDocState,
} from './state';

const hero = createHeroNode({ heading: 'Start' });
const doc = createPageDocument([hero]);
let n = 0;
const newKey = () => `key-${String(++n).padStart(16, '0')}`;

function edit(state: EditorDocState, ops: PageOperation[], coalesceKey?: string, now = 0) {
    const outcome = dispatch(state, ops, { coalesceKey, now });
    if (!outcome.ok) throw new Error(outcome.issues.map((i) => i.message).join());
    return outcome.state;
}
const heading = (value: string): PageOperation[] => [{ op: 'updateProps', nodeId: hero.id, set: { heading: value } }];
const headingOf = (state: EditorDocState) => state.document.nodes[hero.id]!.props.heading;

/** What the server ends up with: every batch applied in order. */
function serverApply(...batches: (readonly PageOperation[])[]) {
    return batches.reduce((d, ops) => applyOperations(d, ops).doc, doc);
}

describe('saving while editing', () => {
    it('typing in the same field during a save never alters the sent batch', () => {
        let s = edit(initialState(doc, 1), heading('Hello'), 'h');
        const started = beginSave(s, newKey)!;
        s = started.state;
        const sent = structuredClone(started.batch.operations);

        s = edit(s, heading('Hello world'), 'h'); // same node + key: previously coalesced into the sent slot
        expect(started.batch.operations).toEqual(sent);
        expect(s.pending).toEqual(heading('Hello world'));

        s = saveSucceeded(s, started.batch, 2);
        expect(s.version).toBe(2);
        expect(hasUnsavedChanges(s)).toBe(true); // the later typing is still unsaved

        const second = beginSave(s, newKey)!;
        expect(second.batch.baseVersion).toBe(2);
        const server = serverApply(started.batch.operations, second.batch.operations);
        expect(server.nodes[hero.id]!.props.heading).toBe('Hello world');
    });

    it('undo during a save is sent as a new edit after the batch', () => {
        let s = edit(initialState(doc, 1), [{ op: 'updateSeo', set: { title: 'Title A' } }], 'seo:title');
        const started = beginSave(s, newKey)!;
        s = undo(started.state)!; // undo the in-flight title change
        s = edit(s, [{ op: 'updateSeo', set: { description: 'Desc B' } }], 'seo:description');
        s = saveSucceeded(s, started.batch, 2);
        const next = beginSave(s, newKey)!;
        const server = serverApply(started.batch.operations, next.batch.operations);
        expect(server.seo).toEqual(s.document.seo);
        expect(server.seo.title).toBeUndefined();
        expect(server.seo.description).toBe('Desc B');
    });

    it('an unconfirmed batch is resent unchanged, with its key, before later edits', () => {
        let s = edit(initialState(doc, 1), heading('One'));
        const first = beginSave(s, newKey)!;
        s = edit(first.state, heading('Two'));
        // Response lost: nothing confirmed. Retrying resumes the same batch.
        const retry = beginSave(s, newKey)!;
        expect(retry.batch).toBe(first.batch);
        expect(retry.state.pending).toEqual(heading('Two'));
    });

    it('a rejected batch goes back in front of later edits', () => {
        let s = edit(initialState(doc, 1), heading('One'));
        const first = beginSave(s, newKey)!;
        s = edit(first.state, [{ op: 'updateSeo', set: { title: 'T' } }]);
        s = saveRejected(s, first.batch);
        expect(s.inFlight).toBeNull();
        expect(s.pending).toEqual([...heading('One'), { op: 'updateSeo', set: { title: 'T' } }]);
        expect(headingOf(s)).toBe('One');
    });

    it('a stale response for another batch is ignored', () => {
        const s = edit(initialState(doc, 1), heading('One'));
        const started = beginSave(s, newKey)!;
        expect(saveSucceeded(started.state, { key: 'other', baseVersion: 1, operations: [] }, 9)).toBe(started.state);
    });
});

describe('title and URL changes', () => {
    it('move the base version and keep pending edits on top', () => {
        let s = edit(initialState(doc, 1), heading('Pending'));
        s = withServerVersion(s, 2);
        const next = beginSave(s, newKey)!;
        expect(next.batch.baseVersion).toBe(2);
        expect(next.batch.operations).toEqual(heading('Pending'));
    });

    it('are refused while a save is in flight', () => {
        const started = beginSave(edit(initialState(doc, 1), heading('x')), newKey)!;
        expect(() => withServerVersion(started.state, 2)).toThrow();
    });
});

describe('publish intent', () => {
    it('reuses the key only for the same page and version', () => {
        const first = publishIntentFor(null, 'p', 3, newKey);
        expect(publishIntentFor(first, 'p', 3, newKey)).toBe(first);
        expect(publishIntentFor(first, 'p', 4, newKey).key).not.toBe(first.key);
        expect(publishIntentFor(first, 'q', 3, newKey).key).not.toBe(first.key);
    });

    it('keeps the intent only when the outcome is uncertain', () => {
        expect(isDefinitiveOutcome('network-error')).toBe(false);
        expect(isDefinitiveOutcome({ ok: false, code: 'INTERNAL' })).toBe(false);
        expect(isDefinitiveOutcome({ ok: true })).toBe(true);
        expect(isDefinitiveOutcome({ ok: false, code: 'STALE_VERSION' })).toBe(true);
    });
});
