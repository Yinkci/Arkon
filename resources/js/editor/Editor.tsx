import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { Issue } from '@/arkon/rules';
import type { PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import {
    beginSave,
    dispatch,
    hasUnsavedChanges,
    initialState,
    isDefinitiveOutcome,
    publishIntentFor,
    redo,
    saveRejected,
    saveSucceeded,
    undo,
    withServerVersion,
    type EditorDocState,
    type PublishIntent,
} from '@/arkon/editor/state';
import { api, newRequestKey, type ApiResult } from '@/lib/api';
import type { EditorInit, LiveInfo, MediaInfo, PageStatus, RecoveryItem, Revision } from '@/types';
import { Canvas, type Viewport } from './Canvas';
import { HistoryPanel } from './HistoryPanel';
import { Inspector, type UnresolvedField } from './Inspector';
import { LayersPanel } from './LayersPanel';
import { StructureBar } from './StructureBar';
import { PageSettings } from './PageSettings';
import { RecoveryPanel } from './RecoveryPanel';

type Activity = 'idle' | 'saving' | 'publishing' | 'restoring' | 'settings';
interface Notice {
    tone: 'error' | 'info';
    message: string;
    issues?: Issue[];
}

// The publish intent survives a reload of the tab, so an uncertain publish can still be retried safely.
const intentStorageKey = (pageId: string) => `arkon:publish-intent:${pageId}`;
function loadIntent(pageId: string): PublishIntent | null {
    try {
        const raw = sessionStorage.getItem(intentStorageKey(pageId));
        return raw ? (JSON.parse(raw) as PublishIntent) : null;
    } catch {
        return null;
    }
}
function storeIntent(intent: PublishIntent | null, pageId: string) {
    try {
        if (intent) sessionStorage.setItem(intentStorageKey(pageId), JSON.stringify(intent));
        else sessionStorage.removeItem(intentStorageKey(pageId));
    } catch {
        // Storage unavailable: the in-memory intent still covers retries in this tab.
    }
}

export function Editor({ init }: { init: EditorInit }) {
    const pageId = init.page.id;
    const [doc, setDoc] = useState<EditorDocState>(() => initialState(init.draft.document, init.draft.version));
    const docRef = useRef(doc);
    // Incremented on every document change, so a canvas render can tell whether it is current.
    const docSeq = useRef(0);
    const commit = useCallback((next: EditorDocState) => {
        if (next.document !== docRef.current.document) docSeq.current++;
        docRef.current = next;
        setDoc(next);
    }, []);

    const [activity, setActivityState] = useState<Activity>('idle');
    const activityRef = useRef<Activity>('idle');
    const setActivity = useCallback((next: Activity) => {
        activityRef.current = next;
        setActivityState(next);
    }, []);

    const rootChildren = init.draft.document.nodes[init.draft.document.root]?.children ?? [];
    const [selectedId, setSelectedId] = useState<string | null>(rootChildren[0] ?? null);
    const [viewport, setViewport] = useState<Viewport>('desktop');
    const [tab, setTab] = useState<'inspect' | 'layers' | 'history'>('inspect');
    const [conflict, setConflictState] = useState(false);
    const conflictRef = useRef(false);
    const setConflict = useCallback(() => {
        conflictRef.current = true;
        setConflictState(true);
    }, []);
    const [notice, setNotice] = useState<Notice | null>(null);
    const [live, setLive] = useState<LiveInfo | null>(init.live);
    const [revisions, setRevisions] = useState<Revision[]>(init.revisions);
    const [media, setMedia] = useState<MediaInfo[]>(init.media);
    const [pageMeta, setPageMeta] = useState({ title: init.page.title, path: init.page.path });
    const settingsAttempt = useRef<{ key: string; title: string; path: string; version: number } | null>(null);
    const savePromise = useRef<Promise<number | null> | null>(null);
    const publishing = useRef(false);
    const intentRef = useRef<PublishIntent | null>(null);

    // Field input that can't be applied yet (e.g. a half-typed link). Never in the document,
    // so saves stay valid; but it is visible work, so it is flagged, guards leaving, and
    // must be fixed or reverted before Preview and Publish.
    const [unresolved, setUnresolvedState] = useState<Record<string, UnresolvedField>>({});
    const unresolvedRef = useRef(unresolved);
    const replaceUnresolved = useCallback((next: Record<string, UnresolvedField>) => {
        unresolvedRef.current = next;
        setUnresolvedState(next);
    }, []);
    const setUnresolved = useCallback(
        (key: string, field: UnresolvedField | null) => {
            const next = { ...unresolvedRef.current };
            if (field) next[key] = field;
            else delete next[key];
            replaceUnresolved(next);
        },
        [replaceUnresolved],
    );
    const unresolvedCount = Object.keys(unresolved).length;

    // A draft stored before a rule was tightened opens in recovery: shown as stored, and nothing
    // else can change, save or publish until each affected value is corrected or its block removed.
    const [recovery, setRecoveryState] = useState<RecoveryItem[] | null>(init.recovery ?? null);
    const recoveryRef = useRef(recovery);

    const { edit: canEdit, publish: canPublish, upload: canUpload } = init.permissions;
    const unsaved = hasUnsavedChanges(doc);
    const locked = conflict || activity === 'restoring' || recovery !== null;

    // ── Canvas: editor-mode HTML from the server's renderer (the one that publishes) ──
    const [canvas, setCanvas] = useState(init.canvas);
    const [canvasToken, setCanvasToken] = useState(0);
    const rendering = useRef(false);
    const renderAgain = useRef(false);

    const renderCanvas = useCallback(async () => {
        if (rendering.current) {
            renderAgain.current = true;
            return;
        }
        rendering.current = true;
        try {
            for (;;) {
                renderAgain.current = false;
                const seq = docSeq.current;
                let result: ApiResult<{ body: string; css: string }>;
                try {
                    result = await api(`/pages/${pageId}/canvas`, { body: { document: docRef.current.document } });
                } catch {
                    setNotice({ tone: 'error', message: "The canvas couldn't be updated (network problem). Your changes are kept." });
                    return;
                }
                if (!result.ok) {
                    setNotice({ tone: 'error', message: `The canvas couldn't be updated: ${result.message}`, issues: result.issues });
                    return;
                }
                // Something changed while this rendered (including canvas typing): render the latest instead,
                // so the canvas never shows an older document than the editor holds.
                if (renderAgain.current || seq !== docSeq.current) continue;
                setCanvas(result.data);
                setCanvasToken((t) => t + 1);
                return;
            }
        } finally {
            rendering.current = false;
        }
    }, [pageId]);

    /** The document changed from outside the canvas (inspector, undo, restore): re-render it. */
    const outsideChange = useCallback(() => void renderCanvas(), [renderCanvas]);

    const apply = useCallback(
        (ops: PageOperation[], options: { coalesceKey?: string; fromCanvas?: boolean } = {}): boolean => {
            // While restoring, the draft is about to be replaced: edits would be silently lost.
            const blocked = !canEdit || conflictRef.current || activityRef.current === 'restoring' || recoveryRef.current !== null;
            const outcome = blocked || ops.length === 0 ? null : dispatch(docRef.current, ops, { coalesceKey: options.coalesceKey });
            if (!outcome?.ok) {
                if (outcome) setNotice({ tone: 'error', message: "That change isn't valid.", issues: outcome.issues });
                if (options.fromCanvas) outsideChange(); // put the canvas back in sync
                return false;
            }
            commit(outcome.state);
            // Inline edits are already visible in the canvas; echoing them would move the caret.
            if (!options.fromCanvas) outsideChange();
            return true;
        },
        [canEdit, commit, outsideChange],
    );

    /** Structural edits (add, move, remove): one undo step each; then select what the user acted on. */
    const structure = useCallback(
        (ops: PageOperation[], select?: string | null) => {
            if (apply(ops) && select !== undefined) setSelectedId(select);
        },
        [apply],
    );

    /** The user's repair: one edit like any other (unsaved until saved), and where history starts. */
    const applyRepair = useCallback(
        (ops: PageOperation[]) => {
            const outcome = dispatch(docRef.current, ops);
            if (!outcome.ok) {
                setNotice({ tone: 'error', message: "The repair doesn't make the page valid yet.", issues: outcome.issues });
                return;
            }
            // Undo stops here: undoing would only bring back the links the repair removed.
            commit({ ...outcome.state, undo: [], redo: [] });
            recoveryRef.current = null;
            setRecoveryState(null);
            outsideChange();
            setNotice({ tone: 'info', message: 'Repair applied to the draft. Save the draft to keep it; publish when you are ready.' });
        },
        [commit, outsideChange],
    );

    const refreshStatus = useCallback(async () => {
        try {
            const result = await api<{ live: LiveInfo | null; status: PageStatus; revisions: Revision[] }>(`/pages/${pageId}/status`);
            if (result.ok) {
                setLive(result.data.live);
                setRevisions(result.data.revisions);
            }
        } catch {
            // Status is informational; the next successful action refreshes it.
        }
    }, [pageId]);

    /**
     * Sends the in-flight batch (resuming an unconfirmed one first) and resolves to the
     * saved draft version, or null if it could not be confirmed. Concurrent calls
     * (shortcut pressed twice, Save then Publish) share one request.
     */
    const save = useCallback((): Promise<number | null> => {
        // Single entry point for every save trigger (button, parent and canvas shortcuts,
        // Preview, Publish). A restore or a title/URL update is about to move the draft
        // version: saving now would race it, so nothing is queued or sent.
        if (activityRef.current === 'restoring' || activityRef.current === 'settings' || conflictRef.current || recoveryRef.current)
            return Promise.resolve(null);
        if (savePromise.current) return savePromise.current;
        const run = async (): Promise<number | null> => {
            const started = beginSave(docRef.current, newRequestKey);
            if (!started) return docRef.current.version;
            commit(started.state);
            const { batch } = started;
            // Only claim the activity when nothing else holds it (Ctrl+S during a publish
            // request must not mark the editor idle when it finishes).
            const ownsActivity = activityRef.current === 'idle';
            if (ownsActivity) setActivity('saving');
            let result: ApiResult<{ version: number }>;
            try {
                result = await api(`/pages/${pageId}/save`, { body: { baseVersion: batch.baseVersion, operations: batch.operations, saveKey: batch.key } });
            } catch {
                // The request may or may not have been applied. The batch stays in flight and the
                // next save resends it with the same key, which the server recognises if it was applied.
                setNotice({
                    tone: 'error',
                    message: "Couldn't confirm the save because of a network problem. Your changes are kept. Save again to retry safely.",
                });
                return null;
            } finally {
                if (ownsActivity && activityRef.current === 'saving') setActivity('idle');
            }
            if (!result.ok) {
                if (result.code === 'STALE_VERSION') {
                    setConflict();
                    setNotice({
                        tone: 'error',
                        message: "This page was changed elsewhere since you opened it. Reload to continue. Your unsaved changes here can't be applied safely.",
                    });
                } else if (result.code === 'INTERNAL' || result.code === 'UNAUTHENTICATED') {
                    // Possibly applied (INTERNAL) or retryable after signing in: keep the batch for a safe retry.
                    setNotice({ tone: 'error', message: result.message });
                } else {
                    commit(saveRejected(docRef.current, batch));
                    setNotice({ tone: 'error', message: result.message, issues: result.issues });
                }
                return null;
            }
            commit(saveSucceeded(docRef.current, batch, result.data.version));
            setNotice((current) => (current?.tone === 'error' ? null : current));
            void refreshStatus();
            return result.data.version;
        };
        const promise = run().finally(() => {
            savePromise.current = null;
        });
        savePromise.current = promise;
        return promise;
    }, [pageId, commit, setActivity, setConflict, refreshStatus]);

    // A component removed (or undone away) takes its unresolved input with it.
    useEffect(() => {
        const current = unresolvedRef.current;
        const kept = Object.fromEntries(Object.entries(current).filter(([, field]) => doc.document.nodes[field.nodeId]));
        if (Object.keys(kept).length !== Object.keys(current).length) replaceUnresolved(kept);
    }, [doc.document, replaceUnresolved]);

    /** True (and shows the fields) when unresolved input must be fixed or reverted first. */
    const blockedByUnresolved = useCallback((action: 'publishing' | 'previewing'): boolean => {
        const fields = Object.values(unresolvedRef.current);
        if (fields.length === 0) return false;
        setSelectedId(fields[0]!.nodeId);
        setTab('inspect');
        const names = [...new Set(fields.map((f) => f.label))].join(', ');
        setNotice({
            tone: 'error',
            message: `Fix or revert the ${names} before ${action}. The page still has the last valid value.`,
            issues: fields.map((f) => ({ message: `${f.label} “${f.value}”: ${f.error}` })),
        });
        return true;
    }, []);

    const publish = useCallback(async () => {
        if (publishing.current || activityRef.current === 'restoring' || recoveryRef.current) return;
        if (blockedByUnresolved('publishing')) return;
        publishing.current = true;
        try {
            // Save until nothing is pending (edits may arrive while saving), then publish that exact version.
            let version = await save();
            for (let i = 0; version !== null && hasUnsavedChanges(docRef.current) && i < 3; i++) version = await save();
            if (version === null || hasUnsavedChanges(docRef.current)) return;
            // Again after awaiting the save: a field may have become unresolved meanwhile. Nothing is
            // awaited between this check and sending the intent, so it is the last moment that counts.
            if (blockedByUnresolved('publishing')) return;

            // Same page and version as an unconfirmed attempt → same key (a retry); otherwise a new intent.
            const intent = publishIntentFor(intentRef.current ?? loadIntent(pageId), pageId, version, newRequestKey);
            intentRef.current = intent;
            storeIntent(intent, pageId);
            setActivity('publishing');
            let result: ApiResult<{ live: LiveInfo | null }>;
            try {
                result = await api(`/pages/${pageId}/publish`, { body: { expectedVersion: intent.version, idempotencyKey: intent.key } });
            } catch {
                setNotice({
                    tone: 'error',
                    message: "Couldn't confirm whether the page was published (network problem). Click Publish to retry. It will not publish twice.",
                });
                return;
            }
            if (isDefinitiveOutcome(result)) {
                intentRef.current = null;
                storeIntent(null, pageId);
            }
            if (!result.ok) {
                if (result.code === 'STALE_VERSION') setConflict();
                setNotice({ tone: 'error', message: result.message, issues: result.issues });
                return;
            }
            setLive(result.data.live);
            setNotice({ tone: 'info', message: 'Published. The live page now shows this version.' });
            void refreshStatus();
        } finally {
            publishing.current = false;
            if (activityRef.current === 'publishing') setActivity('idle');
        }
    }, [pageId, save, setActivity, setConflict, refreshStatus, blockedByUnresolved]);

    const restore = useCallback(
        async (revision: Revision) => {
            if (activityRef.current !== 'idle' || publishing.current || savePromise.current) return;
            if (docRef.current.inFlight) {
                setNotice({ tone: 'error', message: "A save hasn't been confirmed yet. Save again first, then restore." });
                return;
            }
            const discards = docRef.current.pending.length > 0 || Object.keys(unresolvedRef.current).length > 0;
            if (discards && !confirm('Restoring discards your unsaved changes. Continue?')) return;
            setActivity('restoring');
            let result: ApiResult<{ version: number; document: PageDocument }>;
            try {
                result = await api(`/pages/${pageId}/restore`, { body: { revisionId: revision.id, expectedVersion: docRef.current.version } });
            } catch {
                setConflict();
                setNotice({ tone: 'error', message: "Couldn't confirm the restore because of a network problem. Reload to see the current draft." });
                return;
            } finally {
                setActivity('idle');
            }
            if (!result.ok) {
                if (result.code === 'STALE_VERSION') setConflict();
                setNotice({ tone: 'error', message: result.message });
                return;
            }
            commit(initialState(result.data.document, result.data.version));
            replaceUnresolved({});
            outsideChange();
            setNotice({ tone: 'info', message: `Restored revision #${revision.number} into the draft. Publish to make it live.` });
            void refreshStatus();
        },
        [pageId, commit, setActivity, setConflict, refreshStatus, outsideChange, replaceUnresolved],
    );

    /**
     * Draft title/URL change. Needs a settled draft (no unsaved or unconfirmed edits) so
     * the version check is meaningful; edits made while it runs stay pending on top.
     */
    const applySettings = useCallback(
        async (title: string, path: string) => {
            if (activityRef.current !== 'idle' || publishing.current || conflictRef.current || hasUnsavedChanges(docRef.current)) return;
            const version = docRef.current.version;
            const previous = settingsAttempt.current;
            // Same change on the same version after an uncertain response → same key (a retry).
            const attempt =
                previous && previous.title === title && previous.path === path && previous.version === version
                    ? previous
                    : { key: newRequestKey(), title, path, version };
            settingsAttempt.current = attempt;
            setActivity('settings');
            let result: ApiResult<{ version: number; title: string; path: string }>;
            try {
                result = await api(`/pages/${pageId}/settings`, { body: { expectedVersion: version, title, path, saveKey: attempt.key } });
            } catch {
                setNotice({ tone: 'error', message: "Couldn't confirm the title/URL change (network problem). Click Update again to retry safely." });
                return;
            } finally {
                setActivity('idle');
            }
            if (!result.ok) {
                settingsAttempt.current = result.code === 'INTERNAL' ? attempt : null;
                if (result.code === 'STALE_VERSION') setConflict();
                setNotice({ tone: 'error', message: result.message, issues: result.issues });
                return;
            }
            settingsAttempt.current = null;
            commit(withServerVersion(docRef.current, result.data.version));
            setPageMeta({ title: result.data.title, path: result.data.path });
            setNotice({ tone: 'info', message: 'Title and URL updated in the draft. The live page changes when you publish.' });
            void refreshStatus();
        },
        [pageId, commit, setActivity, setConflict, refreshStatus],
    );

    const upload = useCallback(async (file: File): Promise<MediaInfo | null> => {
        const form = new FormData();
        form.set('file', file);
        try {
            const result = await api<MediaInfo>('/media', { form });
            if (!result.ok) {
                setNotice({ tone: 'error', message: result.message });
                return null;
            }
            const asset: MediaInfo = { id: result.data.id, url: result.data.url, width: result.data.width, height: result.data.height, mime: result.data.mime };
            setMedia((list) => [asset, ...list]);
            return asset;
        } catch {
            setNotice({ tone: 'error', message: 'The upload failed. Check the file (5 MB maximum) and your connection, then try again.' });
            return null;
        }
    }, []);

    const step = useCallback(
        (direction: 'undo' | 'redo') => {
            if (conflictRef.current || activityRef.current === 'restoring') return;
            const next = direction === 'undo' ? undo(docRef.current) : redo(docRef.current);
            if (!next) return;
            commit(next);
            outsideChange();
        },
        [commit, outsideChange],
    );

    useEffect(() => {
        function onKey(event: KeyboardEvent) {
            if (!(event.ctrlKey || event.metaKey)) return;
            const key = event.key.toLowerCase();
            if (key === 's') {
                event.preventDefault();
                if (!event.repeat) void save();
                return;
            }
            // Leave text fields their native undo.
            const target = event.target as HTMLElement;
            if (target.closest('input, textarea, select')) return;
            if (key === 'z') {
                event.preventDefault();
                step(event.shiftKey ? 'redo' : 'undo');
            } else if (key === 'y') {
                event.preventDefault();
                step('redo');
            }
        }
        const wouldLoseWork = () => hasUnsavedChanges(docRef.current) || Object.keys(unresolvedRef.current).length > 0;
        function onBeforeUnload(event: BeforeUnloadEvent) {
            if (wouldLoseWork()) event.preventDefault();
        }
        window.addEventListener('keydown', onKey);
        window.addEventListener('beforeunload', onBeforeUnload);
        // Links inside the app (Inertia visits) don't fire beforeunload: ask before leaving.
        const stopGuard = router.on('before', (event) => {
            if (event.detail.visit.method !== 'get' || !wouldLoseWork()) return;
            if (!confirm('Leave the editor? Unsaved changes and fields that are not saved yet will be lost.')) event.preventDefault();
        });
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('beforeunload', onBeforeUnload);
            stopGuard();
        };
    }, [save, step]);

    const selectedNode = selectedId ? (doc.document.nodes[selectedId] ?? null) : null;
    const busy = activity !== 'idle';
    const statusText =
        activity === 'saving'
            ? 'Saving…'
            : activity === 'publishing'
              ? 'Publishing…'
              : activity === 'restoring'
                ? 'Restoring…'
                : activity === 'settings'
                  ? 'Updating title & URL…'
                  : conflict
                    ? 'Out of date'
                    : recovery
                      ? 'Needs repair'
                      : doc.inFlight
                        ? 'Save not confirmed'
                        : unresolvedCount > 0
                          ? // Saving applies valid changes only: never claim that what is on screen is saved.
                            `${unsaved ? 'Unsaved changes, ' : ''}${unresolvedCount} invalid field${unresolvedCount === 1 ? '' : 's'} not saved`
                          : unsaved
                            ? 'Unsaved changes'
                            : 'Draft saved';

    return (
        <div className="flex h-screen flex-col">
            <header className="flex items-center gap-3 border-b border-zinc-200 bg-white px-4 py-2">
                <Link href="/admin" className="text-sm font-semibold text-indigo-600">
                    Arkon
                </Link>
                <div className="min-w-0">
                    <p className="truncate text-sm font-medium" data-testid="page-title">
                        {pageMeta.title}
                    </p>
                    <p className="text-xs text-zinc-500" data-testid="page-path">
                        {pageMeta.path}
                    </p>
                </div>
                <div className="ml-4 flex rounded-md border border-zinc-200 text-xs" role="group" aria-label="Viewport">
                    {(['desktop', 'tablet', 'mobile'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            aria-pressed={viewport === v}
                            onClick={() => setViewport(v)}
                            className={`px-2.5 py-1 capitalize ${viewport === v ? 'bg-zinc-900 text-white' : 'hover:bg-zinc-50'}`}
                        >
                            {v}
                        </button>
                    ))}
                </div>
                <div className="flex text-xs">
                    <button
                        type="button"
                        onClick={() => step('undo')}
                        disabled={doc.undo.length === 0 || locked}
                        className="px-2 py-1 disabled:opacity-40"
                        title="Undo (Ctrl+Z)"
                    >
                        Undo
                    </button>
                    <button
                        type="button"
                        onClick={() => step('redo')}
                        disabled={doc.redo.length === 0 || locked}
                        className="px-2 py-1 disabled:opacity-40"
                        title="Redo (Ctrl+Shift+Z)"
                    >
                        Redo
                    </button>
                </div>
                <div className="ml-auto flex items-center gap-3">
                    <div className="text-right text-xs" aria-live="polite">
                        <p data-testid="save-status" className={unsaved || conflict || unresolvedCount > 0 ? 'text-amber-700' : 'text-zinc-600'}>
                            {statusText}
                        </p>
                        <p data-testid="live-status" className="text-zinc-500">
                            {live ? `Live: revision #${live.revisionNumber} · ${new Date(live.publishedAt).toLocaleTimeString('en-GB')}` : 'Not published'}
                        </p>
                    </div>
                    {live && (
                        <a href={live.path} target="_blank" rel="noreferrer" className="text-sm text-zinc-600 hover:underline">
                            View live
                        </a>
                    )}
                    <button
                        type="button"
                        disabled={busy || locked}
                        onClick={async () => {
                            // The preview would not show what is typed in an unresolved field.
                            if (recoveryRef.current || blockedByUnresolved('previewing')) return;
                            // Open synchronously (popup blockers), then point it at the preview once the draft is saved.
                            const preview = window.open('about:blank', '_blank');
                            const version = await save();
                            // Rechecked after the save: input typed while it was pending must not be skipped.
                            if (version === null || blockedByUnresolved('previewing')) preview?.close();
                            else if (preview) preview.location.href = `/preview/${pageId}`;
                        }}
                        className="rounded-md border border-zinc-300 px-3 py-1.5 text-sm hover:bg-zinc-50 disabled:opacity-50"
                    >
                        Preview
                    </button>
                    <button
                        type="button"
                        disabled={!unsaved || busy || locked || !canEdit}
                        onClick={() => void save()}
                        className="rounded-md border border-zinc-300 px-3 py-1.5 text-sm hover:bg-zinc-50 disabled:opacity-50"
                    >
                        Save draft
                    </button>
                    <button
                        type="button"
                        disabled={busy || locked || !canPublish}
                        onClick={() => void publish()}
                        title={canPublish ? 'Publish this version to the live site' : "You don't have permission to publish"}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        Publish
                    </button>
                </div>
            </header>

            {notice && (
                <div
                    role={notice.tone === 'error' ? 'alert' : 'status'}
                    data-testid="notice"
                    className={`flex items-start gap-3 border-b px-4 py-2 text-sm ${notice.tone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}
                >
                    <div className="flex-1">
                        <p>{notice.message}</p>
                        {notice.issues && notice.issues.length > 0 && (
                            <ul className="mt-1 list-disc pl-5 text-xs">
                                {notice.issues.slice(0, 5).map((issue, i) => (
                                    <li key={i}>{issue.message}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                    {conflict ? (
                        <button type="button" onClick={() => location.reload()} className="font-medium underline">
                            Reload
                        </button>
                    ) : (
                        <button type="button" onClick={() => setNotice(null)} aria-label="Dismiss" className="text-lg leading-none">
                            ×
                        </button>
                    )}
                </div>
            )}

            <div className="flex min-h-0 flex-1">
                <section className="min-w-0 flex-1" aria-label="Canvas">
                    <Canvas
                        body={canvas.body}
                        css={canvas.css}
                        multiline={init.multiline}
                        selectedId={selectedId}
                        viewport={viewport}
                        renderToken={canvasToken}
                        onSelect={setSelectedId}
                        onInlineEdit={(nodeId, prop, value) =>
                            apply([{ op: 'updateProps', nodeId, set: { [prop]: value } }], { coalesceKey: `${nodeId}:${prop}`, fromCanvas: true })
                        }
                        onSaveShortcut={() => void save()}
                        readOnly={recovery !== null}
                    />
                </section>
                <aside className="flex w-80 shrink-0 flex-col border-l border-zinc-200 bg-white" aria-label="Sidebar">
                    {recovery ? (
                        <div className="min-h-0 flex-1 overflow-auto">
                            <RecoveryPanel
                                items={recovery}
                                document={doc.document}
                                canEdit={canEdit && !conflict}
                                onShow={setSelectedId}
                                onApply={applyRepair}
                            />
                        </div>
                    ) : (
                        <>
                            <div className="flex border-b border-zinc-200 text-sm" role="tablist">
                                {(['inspect', 'layers', 'history'] as const).map((t) => (
                                    <button
                                        key={t}
                                        type="button"
                                        role="tab"
                                        aria-selected={tab === t}
                                        onClick={() => setTab(t)}
                                        className={`flex-1 px-3 py-2 ${tab === t ? 'border-b-2 border-indigo-600 font-medium' : 'text-zinc-500'}`}
                                    >
                                        {t === 'inspect' ? 'Properties' : t === 'layers' ? 'Layers' : 'History'}
                                    </button>
                                ))}
                            </div>
                            <div className="min-h-0 flex-1 overflow-auto">
                                {tab === 'layers' ? (
                                    <LayersPanel
                                        document={doc.document}
                                        selectedId={selectedId}
                                        canEdit={canEdit && !locked}
                                        onSelect={setSelectedId}
                                        onStructure={structure}
                                    />
                                ) : tab === 'inspect' ? (
                                    <>
                                        {selectedNode && (
                                            <button
                                                type="button"
                                                onClick={() => setSelectedId(null)}
                                                className="px-4 pt-3 text-xs text-indigo-600 hover:underline"
                                            >
                                                ← Page settings
                                            </button>
                                        )}
                                        {selectedNode && (
                                            <StructureBar
                                                document={doc.document}
                                                node={selectedNode}
                                                canEdit={canEdit && !locked}
                                                onSelect={setSelectedId}
                                                onStructure={structure}
                                            />
                                        )}
                                        {!selectedNode && (
                                            <PageSettings
                                                key={`${pageMeta.title}|${pageMeta.path}`}
                                                title={pageMeta.title}
                                                path={pageMeta.path}
                                                live={live && { title: live.title, path: live.path }}
                                                canEdit={canEdit && !locked}
                                                blockedReason={
                                                    unsaved
                                                        ? 'Save your changes first, then update the title and URL.'
                                                        : busy
                                                          ? 'Wait for the current action to finish.'
                                                          : null
                                                }
                                                onApply={applySettings}
                                            />
                                        )}
                                        <Inspector
                                            document={doc.document}
                                            selected={selectedNode}
                                            media={media}
                                            canEdit={canEdit && !locked}
                                            canUpload={canUpload}
                                            onChange={(ops, coalesceKey) => apply(ops, { coalesceKey })}
                                            onUpload={upload}
                                            unresolved={unresolved}
                                            onUnresolved={setUnresolved}
                                        />
                                    </>
                                ) : (
                                    <HistoryPanel revisions={revisions} canRestore={canEdit && !locked && !busy} onRestore={(r) => void restore(r)} />
                                )}
                            </div>
                        </>
                    )}
                </aside>
            </div>
        </div>
    );
}
