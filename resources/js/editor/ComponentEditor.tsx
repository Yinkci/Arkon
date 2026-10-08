import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createSaveCoordinator } from '@/arkon/editor/saveCoordinator';
import { dispatch, hasUnsavedChanges, initialState, redo, undo, type EditorDocState } from '@/arkon/editor/state';
import { dropOps } from '@/arkon/editor/structure';
import { withColumnLayouts } from '@/arkon/editor/columns';
import type { PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import { VIEWPORT_BREAKPOINT } from '@/arkon/style/edit';
import type { TokenSet } from '@/arkon/style/tokens';
import { api, newRequestKey } from '@/lib/api';
import { useTheme } from '@/lib/theme';
import { Icon } from '@/Components/Icon';
import { Button } from '@/Components/ui';
import type { MediaInfo } from '@/types';
import { Canvas, type Viewport } from './Canvas';
import { DragProvider } from './drag/DragProvider';
import type { CancelReason, Session } from './drag/controller';
import type { Resolution } from '@/arkon/editor/placement';
import { placeAt } from '@/arkon/editor/placement';
import { Inspector } from './Inspector';
import { LayersPanel } from './LayersPanel';
import { StructureBar } from './StructureBar';
import { isDeleteKey, isTyping, useBlockActions } from './blockActions';
import { DeleteDialog } from './DeleteDialog';
import type { DragController } from './drag/controller';
import { BackMark, EditorNotice, HistoryButtons, SaveStatus, SidebarTabs, ViewportSwitch, saveState } from './chrome';
import { revealField, unresolvedNotice, withoutStaleAction, useActivity, useConflict, useLeaveGuard, useUnresolvedFields, type Notice } from './session';

/** The name as it would be saved, or null when it isn't valid (1 to 80 characters). */
function validComponentName(value: string): string | null {
    const trimmed = value.trim();
    return trimmed.length >= 1 && trimmed.length <= 80 ? trimmed : null;
}

export interface ComponentEditorInit {
    component: { id: string; name: string; published: number | null };
    draft: { document: PageDocument; version: number };
    changed: boolean;
    livePages: number;
    media: MediaInfo[];
    tokens: TokenSet;
    permissions: { edit: boolean; publish: boolean; upload: boolean };
    canvas: { body: string; css: string };
    multiline: Record<string, string[]>;
}

/**
 * Editing one reusable component: the same canvas, layers, inspector and undo model as
 * pages, on the component's draft. Saving changes only the draft; Publish makes it the
 * version every page shows and re-renders the live pages that use it.
 */
export function ComponentEditor({ init }: { init: ComponentEditorInit }) {
    const id = init.component.id;
    const [doc, setDoc] = useState<EditorDocState>(() => initialState(init.draft.document, init.draft.version));
    const docRef = useRef(doc);
    const commit = useCallback((next: EditorDocState) => {
        if (next.document !== docRef.current.document) docSeq.current++;
        docRef.current = next;
        setDoc(next);
    }, []);
    const [name, setNameState] = useState(init.component.name);
    const nameRef = useRef(name);
    const setName = useCallback((value: string) => {
        nameRef.current = value;
        setNameState(value);
    }, []);
    const [savedName, setSavedNameState] = useState(init.component.name);
    const savedNameRef = useRef(savedName);
    const setSavedName = useCallback((value: string) => {
        savedNameRef.current = value;
        setSavedNameState(value);
    }, []);
    const [published, setPublished] = useState(init.component.published);
    const [selectedId, setSelectedIdState] = useState<string | null>(null);
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
    const [tab, setTab] = useState<'inspect' | 'layers'>('inspect');
    const [canvas, setCanvas] = useState(init.canvas);
    const [token, setToken] = useState(0);
    const [media, setMedia] = useState(init.media);
    const [notice, setNotice] = useState<Notice | null>(null);
    const { activity, activityRef, setActivity, beginSaving } = useActivity();
    const { conflict, conflictRef, setConflict } = useConflict();
    const { unresolved, unresolvedRef, setUnresolved, unresolvedCount } = useUnresolvedFields(doc.document.nodes);
    // The document changed and the canvas shows an older render: dragging waits for the new one.
    const [renderPending, setRenderPending] = useState(false);
    const docSeq = useRef(0);
    const rendering = useRef(false);
    const renderAgain = useRef(false);
    const publishAttempt = useRef<{ key: string; version: number } | null>(null);
    const publishing = useRef(false);
    const canEdit = init.permissions.edit;

    const nameChanged = useCallback(() => nameRef.current.trim() !== savedNameRef.current, []);

    /**
     * Re-renders the canvas with the latest document. One request at a time; if the document
     * changed meanwhile, the newer one is rendered before anything is shown, so an older
     * response never replaces newer content.
     */
    const render = useCallback(async () => {
        setRenderPending(true);
        if (rendering.current) {
            renderAgain.current = true;
            return;
        }
        rendering.current = true;
        try {
            for (;;) {
                renderAgain.current = false;
                const seq = docSeq.current;
                let result;
                try {
                    result = await api<{ body: string; css: string }>(`/components/${id}/canvas`, { body: { document: docRef.current.document } });
                } catch {
                    setNotice({ tone: 'error', message: "The canvas couldn't be updated (network problem). Your changes are kept." });
                    return;
                }
                if (!result.ok) {
                    setNotice({ tone: 'error', message: result.message, issues: result.issues });
                    return;
                }
                if (renderAgain.current || seq !== docSeq.current) continue;
                setCanvas(result.data);
                setToken((t) => t + 1);
                setRenderPending(false);
                return;
            }
        } finally {
            rendering.current = false;
        }
    }, [id]);

    const selectedRef = useRef(selectedId);
    selectedRef.current = selectedId;
    const selectedPartRef = useRef(selectedPart);
    selectedPartRef.current = selectedPart;
    const dragRef = useRef<DragController | null>(null);

    const dropEnded = useCallback((outcome: { committed: boolean; reason?: CancelReason }) => {
        if (outcome.reason === 'stale') setNotice({ tone: 'error', message: 'The component changed while you were dragging, so nothing moved. Try again.' });
        else if (outcome.reason === 'timeout') setNotice({ tone: 'error', message: 'The canvas was still updating, so nothing moved. Try the drop again.' });
    }, []);

    const apply = useCallback(
        (ops: PageOperation[], options: { coalesceKey?: string; fromCanvas?: boolean } = {}) => {
            if (!canEdit || conflictRef.current) return false;
            const outcome = dispatch(docRef.current, ops, { coalesceKey: options.coalesceKey });
            if (!outcome.ok) {
                setNotice({ tone: 'error', message: "That change isn't valid.", issues: outcome.issues });
                return false;
            }
            commit(outcome.state);
            if (!options.fromCanvas) void render();
            return true;
        },
        [canEdit, commit, render, conflictRef],
    );

    /** Structural edits: one undo step each; Columns widths reconciled in the same step (see the page editor). */
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

    const actions = useBlockActions({
        getDocument: () => docRef.current.document,
        unresolved: () => unresolvedRef.current,
        canChange: () => canEdit && !conflictRef.current,
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

    /** A drop, checked again against the current document and applied as one operation. */
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
    // The page editor's save coordination: one immutable request (operations, base version, key
    // and name) per batch, resent unchanged after an uncertain outcome, shared by every trigger.
    const saver = useMemo(
        () =>
            createSaveCoordinator({
                getState: () => docRef.current,
                setState: commit,
                newKey: newRequestKey,
                canSave: () => canEdit && !conflictRef.current,
                pendingName: () => (nameChanged() ? (validComponentName(nameRef.current) ?? undefined) : undefined),
                hasOtherChanges: nameChanged,
                send: (batch) =>
                    api<{ version: number }>(`/components/${id}/save`, {
                        body: {
                            baseVersion: batch.baseVersion,
                            operations: batch.operations,
                            saveKey: batch.key,
                            ...(batch.name !== undefined ? { name: batch.name } : {}),
                        },
                    }),
                begin: beginSaving,
                onOutcome: (outcome) => {
                    if (outcome.kind === 'saved') {
                        if (outcome.batch.name !== undefined) setSavedName(outcome.batch.name);
                        setNotice((current) => (current?.tone === 'error' ? null : current));
                    } else if (outcome.kind === 'stale' || outcome.kind === 'stale-proposal') {
                        setConflict();
                        setNotice({ tone: 'error', message: 'This component was changed elsewhere since you opened it. Reload to continue.' });
                    } else setNotice({ tone: 'error', message: outcome.message, issues: outcome.kind === 'rejected' ? outcome.issues : undefined });
                },
            }),
        [id, canEdit, commit, conflictRef, nameChanged, beginSaving, setSavedName, setConflict],
    );
    const save = useCallback(() => saver.save(), [saver]);

    /** True (and brings the first field into view) when input that isn't applied must be fixed first. */
    const blockedByUnresolved = useCallback((): boolean => {
        if (nameChanged() && validComponentName(nameRef.current) === null) {
            setNotice({ tone: 'error', message: 'Give the component a name of 1 to 80 characters before publishing.' });
            document.getElementById('component-name')?.focus();
            return true;
        }
        const entries = Object.entries(unresolvedRef.current);
        if (entries.length === 0) return false;
        const [key, first] = entries[0]!;
        setSelectedId(first.nodeId);
        setTab('inspect');
        setNotice(
            unresolvedNotice(
                entries.map(([, field]) => field),
                'publishing',
                'The component draft still has the last valid value.',
            ),
        );
        revealField(key);
        return true;
    }, [nameChanged, unresolvedRef]);

    const publish = useCallback(async () => {
        if (publishing.current || conflictRef.current || blockedByUnresolved()) return;
        publishing.current = true;
        try {
            const version = await saver.saveAll();
            if (version === null) return;
            // Again after awaiting the save: a field may have become unresolved meanwhile. Nothing is
            // awaited between this check and sending the publish, so it is the last moment that counts.
            if (blockedByUnresolved()) return;
            const attempt = publishAttempt.current?.version === version ? publishAttempt.current : { key: newRequestKey(), version };
            publishAttempt.current = attempt;
            setActivity('publishing');
            try {
                const result = await api<{ version: number; refreshes: { queued: number; done: number; failed: number } }>(`/components/${id}/publish`, {
                    body: { expectedVersion: version, idempotencyKey: attempt.key },
                });
                if (!result.ok) {
                    if (result.code !== 'INTERNAL' && result.code !== 'UNAUTHENTICATED') publishAttempt.current = null;
                    if (result.code === 'STALE_VERSION') setConflict();
                    setNotice({ tone: 'error', message: result.message, issues: result.issues });
                    return;
                }
                publishAttempt.current = null;
                setPublished(result.data.version);
                const r = result.data.refreshes;
                setNotice({
                    tone: r.failed > 0 ? 'error' : 'info',
                    message: `Published version ${result.data.version}. ${r.queued} live page${r.queued === 1 ? ' uses' : 's use'} it: ${r.done} updated${r.failed ? `, ${r.failed} failed (see the Design page)` : ''}.`,
                });
            } catch {
                setNotice({ tone: 'error', message: "Couldn't confirm the publish (network problem). Click Publish again: it never publishes twice." });
            }
        } finally {
            publishing.current = false;
            if (activityRef.current === 'publishing') setActivity('idle');
        }
    }, [id, saver, blockedByUnresolved, setActivity, activityRef, setConflict, conflictRef]);

    const step = useCallback(
        (direction: 'undo' | 'redo') => {
            const next = direction === 'undo' ? undo(docRef.current) : redo(docRef.current);
            if (!next) return;
            commit(next);
            void render();
        },
        [commit, render],
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
            } else if (key === 'd' && !event.shiftKey && !event.altKey) {
                // Duplicate the selected block, except while typing (then it is the browser's).
                if (isTyping(event.target) || !selectedRef.current) return;
                event.preventDefault();
                if (!event.repeat) actions.duplicate(selectedRef.current);
            } else if (!(event.target as HTMLElement).closest('input, textarea, select') && (key === 'z' || key === 'y')) {
                event.preventDefault();
                step(key === 'y' || event.shiftKey ? 'redo' : 'undo');
            }
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [save, step, actions]);
    // Any document change (an edit, undo, redo, restore): a notice's action that no longer applies goes.
    useEffect(() => setNotice(withoutStaleAction), [doc.document, doc.undo]);
    useLeaveGuard(() => saver.hasUnsaved() || Object.keys(unresolvedRef.current).length > 0);

    const upload = useCallback(async (file: File): Promise<MediaInfo | null> => {
        const form = new FormData();
        form.set('file', file);
        const result = await api<MediaInfo>('/media', { form });
        if (!result.ok) {
            setNotice({ tone: 'error', message: result.message });
            return null;
        }
        const asset: MediaInfo = { ...result.data, name: (result.data as MediaInfo & { originalName?: string }).originalName };
        setMedia((list) => [asset, ...list]);
        return asset;
    }, []);

    const selected = selectedId ? (doc.document.nodes[selectedId] ?? null) : null;
    const unsaved = hasUnsavedChanges(doc) || name.trim() !== savedName;
    const busy = activity !== 'idle';
    const state = saveState({ activity, conflict, inFlight: doc.inFlight !== null, unresolved: unresolvedCount, unsaved });
    const nameInvalid = validComponentName(name) === null;

    return (
        <DragProvider
            theme={theme}
            options={{ commit: commitDrop, documentVersion: () => docSeq.current, onEnd: dropEnded }}
            enabled={canEdit && !conflict}
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
                    <BackMark href="/admin/design" label="Back to Design" />
                    <div className="flex min-w-0 items-center gap-2 border-l border-line pl-2.5">
                        <span className="hidden items-center gap-1 rounded-full bg-site-soft px-2 py-0.5 text-[11px] font-medium text-site sm:inline-flex">
                            <Icon name="component" className="size-3" />
                            Reusable component
                        </span>
                        <input
                            id="component-name"
                            value={name}
                            maxLength={80}
                            disabled={!canEdit}
                            onChange={(e) => setName(e.target.value)}
                            aria-invalid={nameInvalid}
                            className="ui-input h-7 w-40 font-semibold sm:w-56"
                            aria-label="Component name"
                        />
                    </div>
                    <div className="ml-auto flex items-center gap-1.5 lg:ml-4">
                        <ViewportSwitch value={viewport} onChange={setViewport} />
                        <HistoryButtons canUndo={doc.undo.length > 0 && !conflict} canRedo={doc.redo.length > 0 && !conflict} onStep={step} />
                    </div>
                    <div className="ml-auto flex min-w-0 items-center gap-2 max-md:w-full max-md:justify-end">
                        <div className="hidden min-w-0 flex-col items-end gap-0.5 md:flex" data-testid="component-status">
                            <SaveStatus state={state} testId="component-save-status" />
                            <p className="truncate text-[11px] text-muted">
                                {published ? `Pages show v${published}` : 'Not published'} · used on {init.livePages} live page{init.livePages === 1 ? '' : 's'}
                            </p>
                        </div>
                        <Button
                            icon="save"
                            disabled={!unsaved || busy || conflict || !canEdit}
                            onClick={() => void save()}
                            title="Save the component draft (Ctrl+S). Pages keep the published version."
                        >
                            Save draft
                        </Button>
                        <Button
                            variant="primary"
                            icon="globe"
                            busy={activity === 'publishing'}
                            disabled={activity === 'publishing' || conflict || !init.permissions.publish}
                            onClick={() => void publish()}
                            data-testid="component-publish"
                            title={
                                init.permissions.publish
                                    ? 'Publish the component: every page using it is updated, including live pages'
                                    : "You don't have permission to publish"
                            }
                        >
                            Publish component
                        </Button>
                    </div>
                </header>
                <div className="flex items-center gap-2 border-b border-site/20 bg-site-soft px-3 py-1.5 text-[11px] text-fg">
                    <Icon name="globe" className="size-3.5 text-site" />
                    <span>
                        Site-wide: publishing updates every page that uses this component
                        {init.livePages > 0 ? `, including ${init.livePages} live page${init.livePages === 1 ? '' : 's'}` : ''}. Saving changes only the
                        component draft.
                    </span>
                </div>
                {notice && <EditorNotice notice={notice} conflict={conflict} onDismiss={() => setNotice(null)} />}
                <div className="flex min-h-0 flex-1 max-md:flex-col">
                    <section className="flex min-h-0 min-w-0 flex-1 flex-col max-md:h-[55vh] max-md:flex-none" aria-label="Canvas">
                        <Canvas
                            document={doc.document}
                            renderPending={renderPending}
                            body={canvas.body}
                            css={canvas.css}
                            multiline={init.multiline}
                            selectedId={selectedId}
                            selectedPart={selectedPart}
                            viewport={viewport}
                            renderToken={token}
                            readOnly={!canEdit || conflict}
                            onSelect={(nodeId, part) => {
                                selectPart(nodeId, part ?? null);
                                if (nodeId) setTab('inspect');
                            }}
                            onInlineEdit={(nodeId, prop, value) =>
                                apply([{ op: 'updateProps', nodeId, set: { [prop]: value } }], { coalesceKey: `${nodeId}:${prop}`, fromCanvas: true })
                            }
                            onSaveShortcut={() => void save()}
                            onDuplicate={canEdit && !conflict ? actions.duplicate : undefined}
                            onDelete={canEdit && !conflict ? actions.remove : undefined}
                            onAddInto={canEdit && !conflict ? actions.addInto : undefined}
                            replay={actions.replay}
                            documentVersion={docSeq.current}
                        />
                    </section>
                    <aside
                        className="flex min-h-0 w-full shrink-0 flex-col border-line bg-surface max-md:flex-1 max-md:border-t md:w-[20rem] md:border-l xl:w-[22rem]"
                        aria-label="Sidebar"
                    >
                        <SidebarTabs<typeof tab>
                            value={tab}
                            onChange={setTab}
                            tabs={[
                                { value: 'inspect', label: 'Properties', icon: 'sliders' },
                                { value: 'layers', label: 'Layers', icon: 'layers' },
                            ]}
                        />
                        <div className="min-h-0 flex-1 overflow-auto">
                            {tab === 'layers' ? (
                                <LayersPanel
                                    document={doc.document}
                                    selectedId={selectedId}
                                    canEdit={canEdit && !conflict}
                                    onSelect={setSelectedId}
                                    onStructure={structure}
                                    onDuplicate={actions.duplicate}
                                    onDelete={actions.remove}
                                />
                            ) : (
                                <Inspector
                                    rootName="Component"
                                    document={doc.document}
                                    selected={selected}
                                    part={selectedPart}
                                    onSelectPart={selectPart}
                                    onSelectNode={setSelectedId}
                                    media={media}
                                    canEdit={canEdit && !conflict}
                                    canUpload={init.permissions.upload}
                                    onChange={(ops, coalesceKey) => apply(ops, { coalesceKey })}
                                    onUpload={upload}
                                    unresolved={unresolved}
                                    onUnresolved={setUnresolved}
                                    breakpoint={VIEWPORT_BREAKPOINT[viewport]}
                                    onBreakpoint={(bp) => setViewport(bp === 'base' ? 'desktop' : bp)}
                                    tokens={init.tokens}
                                    components={[]}
                                    onReplay={actions.replayBlock}
                                    toolbar={
                                        selected && (
                                            <StructureBar
                                                document={doc.document}
                                                node={selected}
                                                canEdit={canEdit && !conflict}
                                                onSelect={setSelectedId}
                                                onStructure={structure}
                                                onDuplicate={actions.duplicate}
                                                onDelete={actions.remove}
                                            />
                                        )
                                    }
                                    pageSettings={
                                        <p className="border-b border-line px-4 py-3 text-[0.8125rem] text-muted">
                                            Select a block on the canvas or in Layers. Add blocks from the Layers tab. Pages show this component exactly as
                                            published.
                                        </p>
                                    }
                                />
                            )}
                        </div>
                    </aside>
                </div>
            </div>
        </DragProvider>
    );
}
