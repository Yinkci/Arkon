// The editor's document session: immutable save batches, pending edits, undo/redo
// and publish intents. Pure and unit-tested (state.test.ts). Ported unchanged in
// behaviour from the reference project (apps/web/src/editor/state.ts).
import type { Issue } from '../rules';
import { validatePageDocument } from '../components/validate';
import type { PageDocument } from '../schema/document';
import { OperationError, applyOperations, type PageOperation } from '../schema/operations';

export interface HistoryEntry {
    /** Stable for the life of the edit (kept by coalescing, undo and redo): what "Undo delete" refers to. */
    id: number;
    ops: PageOperation[];
    inverse: PageOperation[];
    /** Consecutive edits with the same key (e.g. typing in one field) undo as one step. */
    coalesceKey?: string;
    at: number;
}

/**
 * A batch of operations handed to the server. It is immutable from the moment it is
 * sent: later edits go to `pending` and can never be merged into it. If the response
 * is lost, the identical batch (same key) is re-sent, and the server recognises it.
 */
export interface SaveBatch {
    key: string;
    baseVersion: number;
    operations: readonly PageOperation[];
    /** Set when the batch applies an AI proposal; the server checks it is exactly that proposal. */
    proposalId?: string;
    /**
     * A rename sent with the batch (reusable components). Part of the immutable request: a
     * retry resends exactly this name, and later name edits wait for the next batch.
     */
    name?: string;
}

export interface EditorDocState {
    document: PageDocument;
    /** Server draft version that `inFlight` (or, if none, `pending`) is based on. */
    version: number;
    /** Sent (or sent-but-unconfirmed) operations. Applied locally already. */
    inFlight: SaveBatch | null;
    /** Operations made after `inFlight` was created. Applied locally, not yet sent. */
    pending: PageOperation[];
    undo: HistoryEntry[];
    redo: HistoryEntry[];
}

export function initialState(document: PageDocument, version: number): EditorDocState {
    return { document, version, inFlight: null, pending: [], undo: [], redo: [] };
}

export function hasUnsavedChanges(state: EditorDocState): boolean {
    return state.inFlight !== null || state.pending.length > 0;
}

const COALESCE_MS = 1500;

export type ApplyOutcome = { ok: true; state: EditorDocState } | { ok: false; issues: Issue[] };

/** Applies and validates locally with the same rules the server uses (a page, or a reusable component's fragment). */
function tryApply(doc: PageDocument, ops: readonly PageOperation[]) {
    try {
        const result = applyOperations(doc, ops);
        // Operations can never replace the root, so the document keeps its kind.
        const issues = validatePageDocument(result.doc, doc.nodes[doc.root]?.type === 'fragment' ? 'fragment' : 'page');
        return issues.length > 0 ? { ok: false as const, issues } : { ok: true as const, ...result };
    } catch (error) {
        if (error instanceof OperationError) return { ok: false as const, issues: [{ message: error.message }] };
        throw error;
    }
}

let lastEntryId = 0;

/** Whether undoing now would undo exactly entry `id` (and nothing else). */
export function canUndoEntry(state: EditorDocState, id: number): boolean {
    return state.undo.at(-1)?.id === id;
}

export function dispatch(state: EditorDocState, ops: PageOperation[], options: { coalesceKey?: string; now?: number } = {}): ApplyOutcome {
    const result = tryApply(state.document, ops);
    if (!result.ok) return result;
    const now = options.now ?? Date.now();
    const top = state.undo.at(-1);
    const merge = options.coalesceKey && top?.coalesceKey === options.coalesceKey && now - top.at < COALESCE_MS;
    const undo = merge
        ? [...state.undo.slice(0, -1), { ...top!, ops: [...top!.ops, ...ops], inverse: [...result.inverse, ...top!.inverse], at: now }]
        : [...state.undo, { id: ++lastEntryId, ops, inverse: result.inverse, coalesceKey: options.coalesceKey, at: now }];
    return {
        ok: true,
        state: { ...state, document: result.doc, pending: appendPending(state.pending, ops), undo: undo.slice(-200), redo: [] },
    };
}

export function undo(state: EditorDocState): EditorDocState | null {
    const entry = state.undo.at(-1);
    if (!entry) return null;
    const result = tryApply(state.document, entry.inverse);
    if (!result.ok) return null;
    return {
        ...state,
        document: result.doc,
        // Undo is itself a new edit on top of whatever was already sent.
        pending: appendPending(state.pending, entry.inverse),
        undo: state.undo.slice(0, -1),
        redo: [...state.redo, entry],
    };
}

export function redo(state: EditorDocState): EditorDocState | null {
    const entry = state.redo.at(-1);
    if (!entry) return null;
    const result = tryApply(state.document, entry.ops);
    if (!result.ok) return null;
    return {
        ...state,
        document: result.doc,
        pending: appendPending(state.pending, entry.ops),
        undo: [...state.undo, { ...entry, coalesceKey: undefined }],
        redo: state.redo.slice(0, -1),
    };
}

/**
 * Starts (or resumes) a save. An unconfirmed batch is always resent unchanged with
 * its original key before anything newer, so a lost response cannot cause a double
 * apply or reorder edits. Returns null when there is nothing to save. `name` (a rename
 * to send) makes a batch even without document edits.
 */
export function beginSave(
    state: EditorDocState,
    newKey: () => string,
    extra: { proposalId?: string; name?: string } = {},
): { state: EditorDocState; batch: SaveBatch } | null {
    if (state.inFlight) return { state, batch: state.inFlight };
    if (state.pending.length === 0 && extra.name === undefined) return null;
    const batch: SaveBatch = {
        key: newKey(),
        baseVersion: state.version,
        operations: state.pending,
        ...(extra.proposalId ? { proposalId: extra.proposalId } : {}),
        ...(extra.name !== undefined ? { name: extra.name } : {}),
    };
    return { state: { ...state, inFlight: batch, pending: [] }, batch };
}

/** The server applied `batch`: it becomes the new base; later edits stay pending. */
export function saveSucceeded(state: EditorDocState, batch: SaveBatch, version: number): EditorDocState {
    if (state.inFlight?.key !== batch.key) return state;
    return { ...state, version, inFlight: null };
}

/**
 * The server definitively rejected `batch` (e.g. validation). Its operations go back
 * in front of later edits so nothing is lost and the order is preserved.
 */
export function saveRejected(state: EditorDocState, batch: SaveBatch): EditorDocState {
    if (state.inFlight?.key !== batch.key) return state;
    return { ...state, inFlight: null, pending: [...batch.operations, ...state.pending] };
}

/**
 * The server applied a change that does not touch the document (title or URL) and
 * moved the draft to `version`. Pending edits still apply on top, so they are kept.
 * Only valid when nothing is in flight; the caller guarantees that.
 */
export function withServerVersion(state: EditorDocState, version: number): EditorDocState {
    if (state.inFlight) throw new Error('Cannot rebase while a save is in flight');
    return { ...state, version };
}

/**
 * Keeps the save payload small: consecutive property updates of the same node and
 * keys collapse into one (typing a heading sends one op, not one per keystroke).
 * Only ever called on `pending`, never on a batch that has been sent.
 */
export function appendPending(pending: PageOperation[], ops: readonly PageOperation[]): PageOperation[] {
    const out = [...pending];
    for (const op of ops) {
        const last = out.at(-1);
        if (
            last?.op === 'updateProps' &&
            op.op === 'updateProps' &&
            last.nodeId === op.nodeId &&
            !last.unset?.length &&
            !op.unset?.length &&
            sameKeys(last.set, op.set)
        ) {
            out[out.length - 1] = op;
        } else if (last?.op === 'updateSeo' && op.op === 'updateSeo' && !last.unset?.length && !op.unset?.length) {
            out[out.length - 1] = { op: 'updateSeo', set: { ...last.set, ...op.set } };
        } else {
            out.push(op);
        }
    }
    return out;
}

function sameKeys(a: Record<string, unknown>, b: Record<string, unknown>) {
    return Object.keys(a).sort().join() === Object.keys(b).sort().join();
}

// ── Publish intent ──────────────────────────────────────────────────────────

/**
 * One publish intent = publishing a specific page at a specific saved version. Its
 * key is reused only to retry that same intent (e.g. after a lost response); any
 * further edit produces a new version and therefore a new intent and key.
 */
export interface PublishIntent {
    pageId: string;
    version: number;
    key: string;
}

export function publishIntentFor(previous: PublishIntent | null, pageId: string, version: number, newKey: () => string): PublishIntent {
    if (previous && previous.pageId === pageId && previous.version === version) return previous;
    return { pageId, version, key: newKey() };
}

/** Whether a publish outcome is final (forget the intent) or uncertain (keep it to retry). */
export function isDefinitiveOutcome(outcome: { ok: true } | { ok: false; code: string } | 'network-error'): boolean {
    if (outcome === 'network-error') return false;
    return outcome.ok || (outcome.code !== 'INTERNAL' && outcome.code !== 'UNAUTHENTICATED');
}
