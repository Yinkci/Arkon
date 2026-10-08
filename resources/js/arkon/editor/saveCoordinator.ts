// One save coordinator for every editor (pages and reusable components). Pure apart from the
// host callbacks, and unit-tested with scripted responses (saveCoordinator.test.ts).
//
// - Every trigger (button, shortcuts in the page and the canvas, Preview, Publish, asking the
//   AI) calls save(); while a request is running they all share it.
// - A request is one immutable batch (operations, base version, key and, for components, the
//   name). After an uncertain outcome (network error, server error, signed out) the identical
//   batch is resent with its key, so the server recognises a committed save instead of
//   reporting a conflict. Later edits, including a new name, wait for the next batch.
import type { Issue } from '../rules';
import { beginSave, hasUnsavedChanges, saveRejected, saveSucceeded, type EditorDocState, type SaveBatch } from './state';

export type SaveResponse = { ok: true; data: { version: number } } | { ok: false; code: string; message: string; issues?: Issue[] };

export type SaveOutcome =
    | { kind: 'saved'; batch: SaveBatch; version: number }
    /** The batch may or may not have been applied; it is kept and the next save resends it. */
    | { kind: 'uncertain'; message: string }
    /** The draft moved on elsewhere: nothing here can be saved safely until a reload. */
    | { kind: 'stale'; message: string }
    /** An applied AI proposal was refused (stale or already used): never saved as a plain edit. */
    | { kind: 'stale-proposal'; message: string }
    /** Definitively refused (e.g. validation): the batch's edits go back in front of later ones. */
    | { kind: 'rejected'; message: string; issues?: Issue[] };

export interface SaveHost {
    getState(): EditorDocState;
    setState(next: EditorDocState): void;
    newKey(): string;
    /** False while something else is about to move the draft version (restore, settings) or after a conflict. */
    canSave(): boolean;
    /** A rename to send when a new batch starts (components); undefined when the name is saved or not valid. */
    pendingName?(): string | undefined;
    /** Unsaved work outside the document (e.g. a rename not sent yet). */
    hasOtherChanges?(): boolean;
    send(batch: SaveBatch): Promise<SaveResponse>;
    /** Called when a request starts; returns the function to call when it ends (activity state). */
    begin(): () => void;
    onOutcome(outcome: SaveOutcome): void;
}

export interface SaveCoordinator {
    /** Saves (or resumes the unconfirmed batch). Resolves to the saved draft version, or null if not confirmed. */
    save(options?: { proposalId?: string }): Promise<number | null>;
    /** Saves until nothing is unsaved (edits may arrive while saving), at most a few rounds. */
    saveAll(): Promise<number | null>;
    hasUnsaved(): boolean;
    /** True while a save request is running. */
    busy(): boolean;
}

const UNCERTAIN_CODES = new Set(['INTERNAL', 'UNAUTHENTICATED']);

export function createSaveCoordinator(host: SaveHost): SaveCoordinator {
    let active: Promise<number | null> | null = null;

    const run = async (options: { proposalId?: string }): Promise<number | null> => {
        const started = beginSave(host.getState(), host.newKey, { proposalId: options.proposalId, name: host.pendingName?.() });
        if (!started) return host.getState().version;
        host.setState(started.state);
        const { batch } = started;
        const end = host.begin();
        let result: SaveResponse;
        try {
            result = await host.send(batch);
        } catch {
            host.onOutcome({
                kind: 'uncertain',
                message: "Couldn't confirm the save because of a network problem. Your changes are kept. Save again to retry safely.",
            });
            return null;
        } finally {
            end();
        }
        if (!result.ok) {
            if (result.code === 'STALE_PROPOSAL') host.onOutcome({ kind: 'stale-proposal', message: result.message });
            else if (result.code === 'STALE_VERSION') host.onOutcome({ kind: 'stale', message: result.message });
            else if (UNCERTAIN_CODES.has(result.code)) host.onOutcome({ kind: 'uncertain', message: result.message });
            else {
                host.setState(saveRejected(host.getState(), batch));
                host.onOutcome({ kind: 'rejected', message: result.message, issues: result.issues });
            }
            return null;
        }
        host.setState(saveSucceeded(host.getState(), batch, result.data.version));
        host.onOutcome({ kind: 'saved', batch, version: result.data.version });
        return result.data.version;
    };

    const hasUnsaved = () => hasUnsavedChanges(host.getState()) || (host.hasOtherChanges?.() ?? false);

    const coordinator: SaveCoordinator = {
        save(options = {}) {
            if (!host.canSave()) return Promise.resolve(null);
            if (active) return active;
            const promise = run(options).finally(() => {
                active = null;
            });
            active = promise;
            return promise;
        },
        async saveAll() {
            let version = await coordinator.save();
            for (let i = 0; version !== null && hasUnsaved() && i < 3; i++) version = await coordinator.save();
            return version !== null && !hasUnsaved() ? version : null;
        },
        hasUnsaved,
        busy: () => active !== null,
    };
    return coordinator;
}
