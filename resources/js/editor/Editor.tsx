import { uploadMedia } from '@/lib/mediaUpload';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { Issue } from '@/arkon/rules';
import type { PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import { createSaveCoordinator } from '@/arkon/editor/saveCoordinator';
import {
    dispatch,
    hasUnsavedChanges,
    initialState,
    isDefinitiveOutcome,
    publishIntentFor,
    redo,
    undo,
    withServerVersion,
    type EditorDocState,
    type PublishIntent,
} from '@/arkon/editor/state';
import { isActive, isReviewable, proposalBlocker, type AiConnection, type AiProposal, type AiRequestView } from '@/arkon/editor/proposals';
import { copySubtree, createNodes, detachOps, dropOps } from '@/arkon/editor/structure';
import { withColumnLayouts } from '@/arkon/editor/columns';
import { findParent } from '@/arkon/schema/document';
import { VIEWPORT_BREAKPOINT } from '@/arkon/style/edit';
import { api, newRequestKey, type ApiResult } from '@/lib/api';
import { useTheme } from '@/lib/theme';
import { Icon } from '@/Components/Icon';
import { Button, Spinner, buttonClass } from '@/Components/ui';
import type { EditorInit, LiveInfo, MediaInfo, PageStatus, RecoveryItem, Revision } from '@/types';
import { Canvas, type Viewport } from './Canvas';
import { DragProvider } from './drag/DragProvider';
import type { CancelReason, Session } from './drag/controller';
import type { Resolution } from '@/arkon/editor/placement';
import { placeAt } from '@/arkon/editor/placement';
import { HistoryPanel } from './HistoryPanel';
import { Inspector } from './Inspector';
import { LayersPanel } from './LayersPanel';
import { StructureBar } from './StructureBar';
import { PageSettings } from './PageSettings';
import { RecoveryPanel } from './RecoveryPanel';
import { AiPanel } from './AiPanel';
import type { Protection } from './AnimationPanel';
import { isDeleteKey, isTyping, useBlockActions } from './blockActions';
import { DeleteDialog } from './DeleteDialog';
import type { DragController } from './drag/controller';
import { BackMark, EditorNotice, HistoryButtons, SaveStatus, SidebarTabs, ViewportSwitch, saveState } from './chrome';
import { revealField, unresolvedNotice, withoutStaleAction, useActivity, useConflict, useLeaveGuard, useUnresolvedFields, type Notice } from './session';

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

/** The renderer's editor-mode output for the canvas, and the animations it leaves off on the page. */
type CanvasRender = { body: string; css: string; motion?: { protected?: Record<string, Protection> } };

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

    const { activity, activityRef, setActivity, beginSaving } = useActivity();

    const rootChildren = init.draft.document.nodes[init.draft.document.root]?.children ?? [];
    // What is selected: a component and the part of it being edited (null: its default part).
    const [selectedId, setSelectedIdState] = useState<string | null>(rootChildren[0] ?? null);
    const [selectedPart, setSelectedPart] = useState<string | null>(null);
    const setSelectedId = useCallback((nodeId: string | null) => {
        setSelectedIdState(nodeId);
        setSelectedPart(null);
    }, []);
    const selectPart = useCallback((nodeId: string | null, part: string | null) => {
        setSelectedIdState(nodeId);
        setSelectedPart(nodeId ? part : null);
    }, []);
    const { theme } = useTheme();
    const [viewport, setViewport] = useState<Viewport>('desktop');
    const [tab, setTab] = useState<'inspect' | 'layers' | 'history' | 'ai'>('inspect');
    const { conflict, conflictRef, setConflict } = useConflict();
    const [notice, setNotice] = useState<Notice | null>(null);
    const [live, setLive] = useState<LiveInfo | null>(init.live);
    const [revisions, setRevisions] = useState<Revision[]>(init.revisions);
    const [media, setMedia] = useState<MediaInfo[]>(init.media);
    const [pageMeta, setPageMeta] = useState({ title: init.page.title, path: init.page.path });
    const settingsAttempt = useRef<{ key: string; title: string; path: string; version: number } | null>(null);
    const publishing = useRef(false);
    const intentRef = useRef<PublishIntent | null>(null);

    // Field input that can't be applied yet (e.g. a half-typed link): flagged, guards leaving,
    // and must be fixed or reverted before Preview and Publish (see session.ts).
    const { unresolved, unresolvedRef, setUnresolved, replaceUnresolved, unresolvedCount } = useUnresolvedFields(doc.document.nodes);

    // A draft stored before a rule was tightened opens in recovery: shown as stored, and nothing
    // else can change, save or publish until each affected value is corrected or its block removed.
    const [recovery, setRecoveryState] = useState<RecoveryItem[] | null>(init.recovery ?? null);
    const recoveryRef = useRef(recovery);

    // An AI proposal being previewed: the canvas shows it and editing waits for Apply or Discard.
    const [proposal, setProposalState] = useState<AiProposal | null>(null);
    const proposalRef = useRef<AiProposal | null>(null);
    const [sending, setSending] = useState(false);
    const [aiError, setAiError] = useState<{ message: string; issues?: Issue[] } | null>(null);
    const [aiHistory, setAiHistory] = useState<{ prompt: string; outcome: 'applied' | 'discarded' }[]>([]);
    // Requests run in the local helper; the panel polls them (no long web request).
    const [aiRequests, setAiRequests] = useState<AiRequestView[]>([]);
    const [aiConnection, setAiConnection] = useState<AiConnection>(init.ai.connection);
    const [tracked, setTracked] = useState<AiRequestView | null>(null);
    const trackedRef = useRef<AiRequestView | null>(null);
    const polling = useRef(false);
    const autoOpened = useRef(new Set<string>());
    // Same prompt on the same version after an uncertain response → same key (never a second run).
    const askAttempt = useRef<{ key: string; prompt: string; version: number } | null>(null);

    const { edit: canEdit, publish: canPublish, upload: canUpload } = init.permissions;
    const unsaved = hasUnsavedChanges(doc);
    const locked = conflict || activity === 'restoring' || recovery !== null || proposal !== null;

    // ── Canvas: editor-mode HTML from the server's renderer (the one that publishes) ──
    const [canvas, setCanvas] = useState<CanvasRender>(init.canvas);
    const [canvasToken, setCanvasToken] = useState(0);
    // The document changed and the canvas shows an older render: dragging waits for the new one.
    const [renderPending, setRenderPending] = useState(false);
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
                let result: ApiResult<CanvasRender>;
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
                setRenderPending(false);
                return;
            }
        } finally {
            rendering.current = false;
        }
    }, [pageId]);

    /** The document changed from outside the canvas (inspector, undo, restore): re-render it. */
    const outsideChange = useCallback(() => {
        setRenderPending(true);
        void renderCanvas();
    }, [renderCanvas]);

    const apply = useCallback(
        (ops: PageOperation[], options: { coalesceKey?: string; fromCanvas?: boolean } = {}): boolean => {
            // While restoring, the draft is about to be replaced: edits would be silently lost.
            const blocked =
                !canEdit || conflictRef.current || activityRef.current === 'restoring' || recoveryRef.current !== null || proposalRef.current !== null;
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

    /**
     * Structural edits (add, move, remove): one undo step each; then select what the user acted on.
     * Columns blocks whose columns changed get their widths reconciled in the same step.
     */
    const structure = useCallback(
        (ops: PageOperation[], select?: string | null): boolean => {
            const layout = withColumnLayouts(docRef.current.document, ops);
            if (!apply(layout.ops)) return false;
            if (select !== undefined) setSelectedId(select);
            if (layout.notice) setNotice({ tone: 'info', message: layout.notice });
            return true;
        },
        [apply],
    );

    const selectedRef = useRef(selectedId);
    selectedRef.current = selectedId;
    const selectedPartRef = useRef(selectedPart);
    selectedPartRef.current = selectedPart;
    const dragRef = useRef<DragController | null>(null);
    const actions = useBlockActions({
        getDocument: () => docRef.current.document,
        unresolved: () => unresolvedRef.current,
        canChange: () => canEdit && !conflictRef.current && activityRef.current !== 'restoring' && recoveryRef.current === null && proposalRef.current === null,
        structure,
        setNotice,
        showField: (key, field) => {
            setSelectedId(field.nodeId);
            setTab('inspect');
            revealField(key);
        },
        undo: () => step('undo'),
        history: () => docRef.current,
        documentVersion: () => docSeq.current,
        cancelDragOf: (removed) => {
            const controller = dragRef.current;
            const session = controller?.getState().session;
            const target = session?.result && session.result !== 'pending' && session.result.kind === 'place' ? session.result.parentId : null;
            if (controller && session && ((session.source.nodeId && removed.has(session.source.nodeId)) || (target && removed.has(target))))
                controller.cancel('stale');
        },
    });

    /**
     * A drop from the canvas, Layers or the palette. The destination was resolved at the release;
     * it is checked again against the document as it is now, and applied as one operation (one
     * undo step). Dragging changes the local draft only: saving and publishing stay separate.
     */
    const commitDrop = useCallback(
        (session: Session, result: Extract<Resolution, { kind: 'place' }>): boolean => {
            const check = placeAt(docRef.current.document, session.source, result.parentId, result.index);
            if (!check.ok) return false;
            const ops = dropOps(session.source, check.placement);
            if (!structure(ops)) return false;
            setSelectedId(session.source.nodeId ?? (ops[0]?.op === 'insertNode' ? (ops[0].nodes[0]?.id ?? null) : null));
            return true;
        },
        [structure, setSelectedId],
    );
    const dropEnded = useCallback((outcome: { committed: boolean; reason?: CancelReason }) => {
        if (outcome.reason === 'stale') setNotice({ tone: 'error', message: 'The page changed while you were dragging, so nothing moved. Try again.' });
        else if (outcome.reason === 'timeout') setNotice({ tone: 'error', message: 'The canvas was still updating, so nothing moved. Try the drop again.' });
    }, []);

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
     * saved draft version, or null if it could not be confirmed. Every trigger (button,
     * parent and canvas shortcuts, Preview, Publish, asking the AI) shares one request.
     */
    const saver = useMemo(
        () =>
            createSaveCoordinator({
                getState: () => docRef.current,
                setState: commit,
                newKey: newRequestKey,
                // A restore or a title/URL update is about to move the draft version: saving now would race it.
                canSave: () =>
                    activityRef.current !== 'restoring' && activityRef.current !== 'settings' && !conflictRef.current && recoveryRef.current === null,
                send: (batch) =>
                    api<{ version: number }>(`/pages/${pageId}/save`, {
                        body: {
                            baseVersion: batch.baseVersion,
                            operations: batch.operations,
                            saveKey: batch.key,
                            ...(batch.proposalId ? { proposalId: batch.proposalId } : {}),
                        },
                    }),
                begin: beginSaving,
                onOutcome: (outcome) => {
                    if (outcome.kind === 'saved') {
                        setNotice((current) => (current?.tone === 'error' ? null : current));
                        void refreshStatus();
                    } else if (outcome.kind === 'stale-proposal') {
                        // The applied proposal was refused (stale or already used): never save it as a plain edit.
                        setConflict();
                        setNotice({ tone: 'error', message: `${outcome.message} Reload to continue; the proposal was not saved.` });
                    } else if (outcome.kind === 'stale') {
                        setConflict();
                        setNotice({
                            tone: 'error',
                            message:
                                "This page was changed elsewhere since you opened it. Reload to continue. Your unsaved changes here can't be applied safely.",
                        });
                    } else if (outcome.kind === 'uncertain') {
                        // Possibly applied: the batch stays in flight and the next save resends it with the same key.
                        setNotice({ tone: 'error', message: outcome.message });
                    } else setNotice({ tone: 'error', message: outcome.message, issues: outcome.issues });
                },
            }),
        [pageId, commit, activityRef, conflictRef, beginSaving, setConflict, refreshStatus],
    );
    const save = useCallback((options: { proposalId?: string } = {}) => saver.save(options), [saver]);

    /** True (and shows the fields) when unresolved input must be fixed or reverted first. */
    const blockedByUnresolved = useCallback(
        (action: 'publishing' | 'previewing' | 'asking the AI'): boolean => {
            const entries = Object.entries(unresolvedRef.current);
            if (entries.length === 0) return false;
            const [key, first] = entries[0]!;
            setSelectedId(first.nodeId);
            setTab('inspect');
            setNotice(
                unresolvedNotice(
                    entries.map(([, field]) => field),
                    action,
                    'The page still has the last valid value.',
                ),
            );
            revealField(key);
            return true;
        },
        [unresolvedRef],
    );

    const publish = useCallback(async () => {
        if (publishing.current || activityRef.current === 'restoring' || recoveryRef.current) return;
        if (blockedByUnresolved('publishing')) return;
        publishing.current = true;
        try {
            // Save until nothing is pending (edits may arrive while saving), then publish that exact version.
            const version = await saver.saveAll();
            if (version === null) return;
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
    }, [pageId, saver, setActivity, activityRef, setConflict, refreshStatus, blockedByUnresolved]);

    const restore = useCallback(
        async (revision: Revision) => {
            if (activityRef.current !== 'idle' || publishing.current || saver.busy()) return;
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
        [pageId, commit, setActivity, activityRef, unresolvedRef, saver, setConflict, refreshStatus, outsideChange, replaceUnresolved],
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
        [pageId, commit, setActivity, activityRef, conflictRef, setConflict, refreshStatus],
    );

    const showProposal = useCallback((next: AiProposal | null) => {
        proposalRef.current = next;
        setProposalState(next);
        // The canvas switches between the proposal preview and the draft.
        setCanvasToken((t) => t + 1);
    }, []);

    const track = useCallback((next: AiRequestView | null) => {
        trackedRef.current = next;
        setTracked(next);
    }, []);

    /** Opens a proposal for review: the server renders its preview (and says if the draft moved on). */
    const reviewRequest = useCallback(
        async (id: string) => {
            if (proposalRef.current) return;
            try {
                const result = await api<AiRequestView>(`/pages/${pageId}/ai/requests/${id}`);
                if (!result.ok) {
                    setAiError({ message: result.message });
                    return;
                }
                if (result.data.proposal && isReviewable(result.data)) {
                    setAiError(null);
                    setTab('ai');
                    showProposal(result.data.proposal);
                }
            } catch {
                setAiError({ message: "Couldn't reach the server. Try again." });
            }
        },
        [pageId, showProposal],
    );

    /** Polls the user's requests on this page: helper readiness, progress, and proposals waiting for review. */
    const refreshAi = useCallback(async () => {
        // One poll at a time: overlapping polls could both see a request finish and open it twice.
        if (polling.current) return;
        polling.current = true;
        try {
            let result: ApiResult<{ requests: AiRequestView[]; connection: AiConnection }>;
            try {
                result = await api(`/pages/${pageId}/ai/requests`);
            } catch {
                return;
            }
            if (!result.ok) return;
            setAiRequests(result.data.requests);
            setAiConnection(result.data.connection);
            const current = trackedRef.current;
            if (!current || !isActive(current)) return;
            const listed = result.data.requests.find((r) => r.id === current.id);
            if (listed && isActive(listed)) {
                track(listed);
                return;
            }
            // It finished (or failed, or was cancelled): read its outcome, and open a proposal once.
            try {
                const done = await api<AiRequestView>(`/pages/${pageId}/ai/requests/${current.id}`);
                if (!done.ok || trackedRef.current?.id !== current.id) return;
                track(done.data);
                if (isReviewable(done.data) && !proposalRef.current && !autoOpened.current.has(done.data.id)) {
                    autoOpened.current.add(done.data.id);
                    await reviewRequest(done.data.id);
                }
            } catch {
                // Next poll tries again.
            }
        } finally {
            polling.current = false;
        }
    }, [pageId, track, reviewRequest]);

    useEffect(() => {
        if (!init.ai.available) return;
        void refreshAi();
        const fast = tracked !== null && isActive(tracked);
        const timer = setInterval(() => void refreshAi(), fast ? 1500 : 5000);
        return () => clearInterval(timer);
    }, [init.ai.available, refreshAi, tracked]);

    /** Saves pending edits, then queues a request based on that saved version for the local helper. */
    const askAi = useCallback(
        async (prompt: string) => {
            if (sending || proposalRef.current || recoveryRef.current || conflictRef.current) return;
            if (blockedByUnresolved('asking the AI')) return;
            setAiError(null);
            setSending(true);
            try {
                const version = await saver.saveAll();
                if (version === null) {
                    setAiError({ message: 'Your changes could not be saved, so the AI was not asked. Save, then try again.' });
                    return;
                }
                if (blockedByUnresolved('asking the AI')) return;
                const previous = askAttempt.current;
                const attempt = previous && previous.prompt === prompt && previous.version === version ? previous : { key: newRequestKey(), prompt, version };
                askAttempt.current = attempt;
                let result: ApiResult<AiRequestView>;
                try {
                    result = await api<AiRequestView>(`/pages/${pageId}/ai/requests`, { body: { prompt, baseVersion: version, requestKey: attempt.key } });
                } catch {
                    setAiError({ message: "Couldn't confirm the request (network problem). Send it again: the same request is never run twice." });
                    return;
                }
                if (!result.ok) {
                    if (result.code !== 'INTERNAL') askAttempt.current = null;
                    if (result.code === 'STALE_VERSION') {
                        setConflict();
                        setNotice({ tone: 'error', message: 'This page was changed elsewhere since you opened it. Reload to continue.' });
                    }
                    setAiError({ message: result.message, issues: result.issues });
                    void refreshAi();
                    return;
                }
                askAttempt.current = null;
                setTab('ai');
                track(result.data);
                void refreshAi();
            } finally {
                setSending(false);
            }
        },
        [sending, pageId, saver, blockedByUnresolved, setConflict, conflictRef, track, refreshAi],
    );

    const cancelRequest = useCallback(
        async (id: string) => {
            try {
                const result = await api<AiRequestView>(`/pages/${pageId}/ai/requests/${id}/cancel`, { body: {} });
                if (result.ok) track(result.data);
            } catch {
                setAiError({ message: "Couldn't reach the server to cancel. Try again." });
            }
            void refreshAi();
        },
        [pageId, track, refreshAi],
    );

    /** One undoable edit with exactly the proposed operations, saved as that proposal. Never publishes. */
    const applyProposal = useCallback(() => {
        const current = proposalRef.current;
        if (!current) return;
        const blocker = proposalBlocker(docRef.current, current, Object.keys(unresolvedRef.current).length);
        if (blocker) {
            setAiError({ message: blocker });
            return;
        }
        const outcome = dispatch(docRef.current, current.operations);
        if (!outcome.ok) {
            setAiError({ message: 'This proposal no longer fits the draft. Discard it and ask again.', issues: outcome.issues });
            return;
        }
        commit(outcome.state);
        showProposal(null);
        if (current.canvas) setCanvas(current.canvas); // the preview is exactly the applied page
        setAiHistory((list) => [...list, { prompt: current.prompt, outcome: 'applied' }]);
        track(null);
        void save({ proposalId: current.id }).then((version) => {
            void refreshAi();
            if (version !== null)
                setNotice({
                    tone: 'info',
                    message:
                        'Applied to the draft and saved. Nothing is published: review it, edit anything, then Publish when you are ready. Undo reverts it.',
                });
        });
    }, [commit, save, showProposal, track, refreshAi, unresolvedRef]);

    const [tokensBusy, setTokensBusy] = useState(false);
    /** The proposal's site-wide token changes → the token draft (separate from the page; never published here). */
    const applyProposalTokens = useCallback(async () => {
        const current = proposalRef.current;
        if (!current || tokensBusy) return;
        setTokensBusy(true);
        try {
            const result = await api<{ tokenDraftVersion: number }>(`/pages/${pageId}/ai/requests/${current.id}/apply-tokens`, { body: {} });
            if (!result.ok) {
                setAiError({ message: result.message, issues: result.issues });
                return;
            }
            const next = { ...current, tokenChangesApplied: result.data.tokenDraftVersion };
            proposalRef.current = next;
            setProposalState(next);
            setNotice({ tone: 'info', message: 'Design token changes saved to the token draft. Publish them on the Design page to update the site.' });
        } catch {
            setAiError({ message: "Couldn't reach the server. Try again: the changes are applied only once." });
        } finally {
            setTokensBusy(false);
        }
    }, [pageId, tokensBusy]);

    const discardProposal = useCallback(() => {
        const current = proposalRef.current;
        if (!current) return;
        showProposal(null);
        setAiError(null);
        setAiHistory((list) => [...list, { prompt: current.prompt, outcome: 'discarded' }]);
        track(null);
        // Recorded on the server for the history of the request; the page never changed, so a failure here is harmless.
        void api(`/pages/${pageId}/ai/requests/${current.id}/discard`, { body: {} })
            .catch(() => undefined)
            .then(() => refreshAi());
    }, [pageId, showProposal, refreshAi]);

    // Reusable components (published versions) this page can use; grows when the user makes one here.
    const [components, setComponents] = useState(init.components);

    /**
     * "Make reusable": the block becomes a reusable component (published as version 1 when
     * the user may publish), and the page gets a linked instance in its place: one undoable edit.
     */
    const makeReusable = useCallback(
        async (nodeId: string, name: string) => {
            const document = docRef.current.document;
            const location = findParent(document, nodeId);
            if (!location || !document.nodes[nodeId]) return;
            const nodes = copySubtree(document, nodeId);
            try {
                const created = await api<{ id: string }>('/components', { body: { name, nodes } });
                if (!created.ok) {
                    setNotice({ tone: 'error', message: created.message, issues: created.issues });
                    return;
                }
                if (!canPublish) {
                    setComponents((list) => [...list, { id: created.data.id, name, published: null, document: null }]);
                    setNotice({
                        tone: 'info',
                        message: `“${name}” was created. Someone who can publish must publish it on the Design page before it can be used on pages.`,
                    });
                    return;
                }
                const published = await api<{ version: number }>(`/components/${created.data.id}/publish`, {
                    body: { expectedVersion: 1, idempotencyKey: newRequestKey() },
                });
                if (!published.ok) {
                    setNotice({ tone: 'error', message: `“${name}” was created but not published: ${published.message}`, issues: published.issues });
                    return;
                }
                const fragmentRoot = 'fragroot';
                const componentDoc: PageDocument = {
                    schemaVersion: document.schemaVersion,
                    root: fragmentRoot,
                    nodes: {
                        [fragmentRoot]: { id: fragmentRoot, type: 'fragment', version: 1, props: {}, children: [nodes[0]!.id] },
                        ...Object.fromEntries(nodes.map((n) => [n.id, n])),
                    },
                    seo: {},
                };
                setComponents((list) => [...list, { id: created.data.id, name, published: published.data.version, document: componentDoc }]);
                const instance = createNodes('instance', { componentId: created.data.id });
                structure(
                    [
                        { op: 'removeNode', nodeId },
                        { op: 'insertNode', parentId: location.parentId, index: location.index, nodes: instance },
                    ],
                    instance[0]!.id,
                );
                setNotice({
                    tone: 'info',
                    message: `“${name}” is now a reusable component. Edit it on the Design page; publishing it updates every page that uses it.`,
                });
            } catch {
                setNotice({
                    tone: 'error',
                    message: "Couldn't reach the server. Check the Design page before trying again (the component may exist already).",
                });
            }
        },
        [canPublish, structure],
    );

    /** Replaces a reusable component instance with a copy of its published blocks: one undoable edit. */
    const detach = useCallback(
        (nodeId: string) => {
            const instance = docRef.current.document.nodes[nodeId];
            const component = components.find((c) => c.id === instance?.props.componentId);
            if (!instance || !component?.document) return;
            const ops = detachOps(docRef.current.document, nodeId, component.document);
            if (ops) structure(ops, ops[1]?.op === 'insertNode' ? (ops[1].nodes[0]?.id ?? null) : null);
        },
        [components, structure],
    );

    const upload = useCallback(async (file: File, progress?: (percent: number) => void): Promise<MediaInfo | null> => {
        try {
            const asset = await uploadMedia(file, progress);
            setMedia((list) => [asset, ...list]);
            return asset;
        } catch (error) {
            setNotice({ tone: 'error', message: error instanceof Error ? error.message : 'The upload failed. Please try again.' });
            throw error;
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
        [commit, outsideChange, conflictRef, activityRef],
    );

    useEffect(() => {
        function onKey(event: KeyboardEvent) {
            // Delete/Backspace: the selection, only from the builder's selection interface (never while typing).
            if (isDeleteKey(event) && selectedRef.current) {
                event.preventDefault();
                if (!event.repeat) actions.remove(selectedRef.current, selectedPartRef.current);
                return;
            }
            if (!(event.ctrlKey || event.metaKey)) return;
            const key = event.key.toLowerCase();
            if (key === 's') {
                event.preventDefault();
                if (!event.repeat) void save();
                return;
            }
            // Ctrl/Cmd+D duplicates the selected block, except while typing (then it is the browser's).
            if (key === 'd' && !event.shiftKey && !event.altKey) {
                if (isTyping(event.target) || !selectedRef.current) return;
                event.preventDefault();
                if (!event.repeat) actions.duplicate(selectedRef.current);
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
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [save, step, actions]);
    // Any document change (an edit, undo, redo, restore): a notice's action that no longer applies goes.
    useEffect(() => setNotice(withoutStaleAction), [doc.document, doc.undo]);
    useLeaveGuard(() => hasUnsavedChanges(docRef.current) || Object.keys(unresolvedRef.current).length > 0);

    const selectedNode = selectedId ? (doc.document.nodes[selectedId] ?? null) : null;
    const busy = activity !== 'idle';
    const state = saveState({
        activity,
        conflict,
        recovery: recovery !== null,
        proposal: proposal !== null,
        inFlight: doc.inFlight !== null,
        unresolved: unresolvedCount,
        unsaved,
    });
    const waitingProposals = aiRequests.filter(isReviewable).length;

    return (
        <DragProvider
            theme={theme}
            options={{ commit: commitDrop, documentVersion: () => docSeq.current, onEnd: dropEnded }}
            enabled={canEdit && !locked}
            controllerRef={dragRef}
        >
            <DeleteDialog
                open={actions.confirming !== null}
                title={actions.confirming?.label ?? 'Delete'}
                contents={actions.confirming?.contents ?? 0}
                onConfirm={actions.confirmRemove}
                onCancel={actions.cancelRemove}
            />
            <div data-theme={theme} className="flex h-dvh flex-col bg-canvas text-fg">
                <header className="flex min-h-12 shrink-0 flex-wrap items-center gap-x-2 gap-y-1.5 border-b border-line bg-surface px-2 py-1.5 sm:px-3">
                    <BackMark href="/admin" label="Arkon dashboard" />
                    <div className="mr-1 min-w-0 border-l border-line pl-2.5">
                        <p className="truncate text-[0.8125rem] leading-tight font-semibold" data-testid="page-title">
                            {pageMeta.title}
                        </p>
                        <p className="truncate font-mono text-[11px] leading-tight text-muted" data-testid="page-path">
                            {pageMeta.path}
                        </p>
                    </div>
                    <div className="ml-auto flex items-center gap-1.5 lg:ml-4">
                        <ViewportSwitch value={viewport} onChange={setViewport} />
                        <HistoryButtons canUndo={doc.undo.length > 0 && !locked} canRedo={doc.redo.length > 0 && !locked} onStep={step} />
                    </div>
                    <div className="ml-auto flex min-w-0 items-center gap-2 max-md:w-full max-md:justify-end">
                        <div className="hidden min-w-0 flex-col items-end gap-0.5 md:flex">
                            <SaveStatus state={state} testId="save-status" />
                            <p data-testid="live-status" className="flex items-center gap-1 truncate text-[11px] text-muted">
                                {live ? (
                                    <>
                                        <span aria-hidden className="size-1.5 rounded-full bg-live" />
                                        {`Live: revision #${live.revisionNumber} · ${new Date(live.publishedAt).toLocaleTimeString('en-GB')}`}
                                    </>
                                ) : (
                                    'Not published'
                                )}
                            </p>
                        </div>
                        {live && (
                            <a
                                href={live.path}
                                target="_blank"
                                rel="noreferrer"
                                className={buttonClass('ghost', 'md', 'max-lg:px-2')}
                                title="Open the live page in a new tab"
                            >
                                <Icon name="external" />
                                <span className="max-lg:sr-only">View live</span>
                            </a>
                        )}
                        <Button
                            icon="eye"
                            disabled={busy || locked}
                            title="Save the draft and open a preview of it in a new tab"
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
                        >
                            Preview
                        </Button>
                        <Button
                            icon="save"
                            disabled={!unsaved || busy || locked || !canEdit}
                            onClick={() => void save()}
                            title="Save the draft (Ctrl+S). The live page does not change."
                        >
                            Save draft
                        </Button>
                        <Button
                            variant="primary"
                            icon="globe"
                            busy={activity === 'publishing'}
                            disabled={busy || locked || !canPublish}
                            onClick={() => void publish()}
                            title={canPublish ? 'Publish this version to the live site' : "You don't have permission to publish"}
                        >
                            Publish
                        </Button>
                    </div>
                </header>
                {/* On narrow screens the status moves under the toolbar, so it is never hidden. */}
                <div className="flex items-center gap-2 border-b border-line bg-surface px-3 py-1.5 md:hidden">
                    <SaveStatus state={state} testId="save-status-compact" />
                    <span className="truncate text-[11px] text-muted">{live ? `Live: revision #${live.revisionNumber}` : 'Not published'}</span>
                </div>
                {!canPublish && (
                    <p className="border-b border-line bg-raised px-3 py-1.5 text-[11px] text-muted" data-testid="role-note">
                        <Icon name="lock" className="mr-1 inline size-3" />
                        You can edit and save drafts. Someone with publishing rights makes them live.
                    </p>
                )}

                {notice && <EditorNotice notice={notice} conflict={conflict} onDismiss={() => setNotice(null)} />}

                <div className="flex min-h-0 flex-1 max-md:flex-col">
                    <section className="flex min-h-0 min-w-0 flex-1 flex-col max-md:h-[55vh] max-md:flex-none" aria-label="Canvas">
                        {proposal && (
                            <div className="flex items-center gap-2 border-b border-ai/25 bg-ai-soft px-3 py-1.5 text-xs text-fg" data-testid="proposal-banner">
                                <Icon name="sparkle" className="size-3.5 text-ai" />
                                {proposal.canvas
                                    ? 'Preview of the AI proposal. Nothing has changed yet: Apply or Discard it in the AI panel.'
                                    : 'The AI proposed no changes.'}
                            </div>
                        )}
                        <div className="min-h-0 flex-1">
                            <Canvas
                                document={proposal ? undefined : doc.document}
                                renderPending={renderPending}
                                body={proposal?.canvas?.body ?? canvas.body}
                                css={proposal?.canvas?.css ?? canvas.css}
                                multiline={init.multiline}
                                selectedId={selectedId}
                                selectedPart={selectedPart}
                                viewport={viewport}
                                renderToken={canvasToken}
                                onSelect={(nodeId, part) => {
                                    selectPart(nodeId, part ?? null);
                                    if (nodeId) setTab((current) => (current === 'layers' || current === 'history' ? 'inspect' : current));
                                }}
                                onInlineEdit={(nodeId, prop, value) =>
                                    apply([{ op: 'updateProps', nodeId, set: { [prop]: value } }], { coalesceKey: `${nodeId}:${prop}`, fromCanvas: true })
                                }
                                onSaveShortcut={() => void save()}
                                onDuplicate={canEdit && !locked ? actions.duplicate : undefined}
                                onDelete={canEdit && !locked ? actions.remove : undefined}
                                onAddInto={canEdit && !locked ? actions.addInto : undefined}
                                replay={actions.replay}
                                documentVersion={docSeq.current}
                                readOnly={recovery !== null || proposal !== null}
                            />
                        </div>
                    </section>
                    <aside
                        className="flex min-h-0 w-full shrink-0 flex-col border-line bg-surface max-md:flex-1 max-md:border-t md:w-[20rem] md:border-l xl:w-[22rem]"
                        aria-label="Sidebar"
                    >
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
                                <SidebarTabs<typeof tab>
                                    value={tab}
                                    onChange={setTab}
                                    tabs={[
                                        { value: 'inspect', label: 'Properties', icon: 'sliders' },
                                        { value: 'layers', label: 'Layers', icon: 'layers' },
                                        { value: 'history', label: 'History', icon: 'history' },
                                        {
                                            value: 'ai',
                                            label: 'AI',
                                            icon: 'sparkle',
                                            badge:
                                                proposal || waitingProposals > 0 ? (
                                                    <span
                                                        className="rounded-full bg-ai px-1 text-[10px] leading-4 text-surface tabular-nums"
                                                        aria-label="proposal waiting for review"
                                                    >
                                                        {proposal ? 1 : waitingProposals}
                                                    </span>
                                                ) : tracked && isActive(tracked) ? (
                                                    <Spinner className="size-3 text-ai" />
                                                ) : undefined,
                                        },
                                    ]}
                                />
                                <div className="min-h-0 flex-1 overflow-auto" data-testid="sidebar-body">
                                    {tab === 'layers' ? (
                                        <LayersPanel
                                            document={doc.document}
                                            selectedId={selectedId}
                                            canEdit={canEdit && !locked}
                                            onSelect={setSelectedId}
                                            onStructure={structure}
                                            components={components}
                                            onDuplicate={actions.duplicate}
                                            onDelete={actions.remove}
                                        />
                                    ) : tab === 'inspect' ? (
                                        <Inspector
                                            document={doc.document}
                                            selected={selectedNode}
                                            part={selectedPart}
                                            onSelectPart={selectPart}
                                            onSelectNode={setSelectedId}
                                            media={media}
                                            canEdit={canEdit && !locked}
                                            canUpload={canUpload}
                                            onChange={(ops, coalesceKey) => apply(ops, { coalesceKey })}
                                            onUpload={upload}
                                            unresolved={unresolved}
                                            onUnresolved={setUnresolved}
                                            breakpoint={VIEWPORT_BREAKPOINT[viewport]}
                                            onBreakpoint={(bp) => setViewport(bp === 'base' ? 'desktop' : bp)}
                                            tokens={init.tokens}
                                            components={components}
                                            onDetach={detach}
                                            motion={proposal ? undefined : canvas.motion}
                                            onReplay={actions.replayBlock}
                                            toolbar={
                                                selectedNode && (
                                                    <StructureBar
                                                        document={doc.document}
                                                        node={selectedNode}
                                                        canEdit={canEdit && !locked}
                                                        onSelect={setSelectedId}
                                                        onStructure={structure}
                                                        onMakeReusable={(nodeId, name) => void makeReusable(nodeId, name)}
                                                        onDuplicate={actions.duplicate}
                                                        onDelete={actions.remove}
                                                    />
                                                )
                                            }
                                            pageSettings={
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
                                            }
                                        />
                                    ) : tab === 'history' ? (
                                        <HistoryPanel revisions={revisions} canRestore={canEdit && !locked && !busy} onRestore={(r) => void restore(r)} />
                                    ) : (
                                        <AiPanel
                                            available={init.ai.available && !conflict}
                                            unavailableReason={conflict ? 'Reload the page to continue.' : init.ai.reason}
                                            promptMax={init.ai.promptMax}
                                            connection={aiConnection}
                                            requests={aiRequests}
                                            tracked={tracked}
                                            sending={sending}
                                            onCancel={(id) => void cancelRequest(id)}
                                            onReview={(id) => void reviewRequest(id)}
                                            proposal={proposal}
                                            applyBlocker={proposal ? proposalBlocker(doc, proposal, unresolvedCount) : null}
                                            error={aiError}
                                            history={aiHistory}
                                            onAsk={(prompt) => void askAi(prompt)}
                                            onApply={applyProposal}
                                            onDiscard={discardProposal}
                                            onApplyTokens={() => void applyProposalTokens()}
                                            tokensBusy={tokensBusy}
                                        />
                                    )}
                                </div>
                            </>
                        )}
                    </aside>
                </div>
            </div>
        </DragProvider>
    );
}
