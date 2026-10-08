import { useCallback, useRef, useState } from 'react';
import { duplicateBlock } from '@/arkon/editor/duplicate';
import { removalOf, type Removal } from '@/arkon/editor/remove';
import { canUndoEntry, type EditorDocState } from '@/arkon/editor/state';
import { componentName } from '@/arkon/editor/parts';
import { createNodes, insertOps } from '@/arkon/editor/structure';
import type { NodeId, PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import type { UnresolvedField } from './Inspector';
import type { Notice } from './session';

/** True for key presses that belong to a text field (typing, or the browser's own shortcuts there). */
export function isTyping(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])') !== null;
}

/**
 * Delete/Backspace deletes the selection only when focus is in the builder's selection interface
 * (the canvas controls, Layers, the selected block's toolbar: marked data-selection-scope), never
 * while typing, in a dialog or elsewhere. Inside the canvas the bridge decides (not while editing text).
 */
export function isDeleteKey(event: KeyboardEvent): boolean {
    if ((event.key !== 'Delete' && event.key !== 'Backspace') || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return false;
    const target = event.target;
    return target instanceof HTMLElement && !isTyping(target) && target.closest('dialog') === null && target.closest('[data-selection-scope]') !== null;
}

/**
 * Block actions shared by the page and component editors: Duplicate (toolbar, Layers, canvas
 * controls, Ctrl/Cmd+D), Delete (the same entry points plus Delete/Backspace), adding into an
 * empty column from the canvas, and replaying an entrance animation. Everything goes through the editor's normal `structure` path (one undo
 * step, the usual saves and locks).
 */
export function useBlockActions(options: {
    getDocument(): PageDocument;
    unresolved(): Record<string, UnresolvedField>;
    /** Whether edits are allowed now (permissions, conflicts, locks). */
    canChange(): boolean;
    structure(ops: PageOperation[], select?: NodeId | null): boolean | void;
    setNotice(notice: Notice | null): void;
    /** Shows an unresolved field that must be fixed first. */
    showField(key: string, field: UnresolvedField): void;
    /** Undo, and the editor's history (so a notice's Undo only ever undoes its own entry). */
    undo(): void;
    history(): EditorDocState;
    /** The editor's document version (a preview belongs to the document it was asked for). */
    documentVersion(): number;
    /** Ends a drag in progress if it moves or targets one of these blocks. */
    cancelDragOf?(removed: Set<NodeId>): void;
}) {
    const latest = useRef(options);
    latest.current = options;

    const duplicate = useCallback((nodeId: NodeId) => {
        const o = latest.current;
        if (!o.canChange()) return;
        const doc = o.getDocument();
        const entries = Object.entries(o.unresolved());
        const result = duplicateBlock(
            doc,
            nodeId,
            entries.map(([, field]) => field),
        );
        if (!result.ok) {
            o.setNotice({ tone: 'error', message: `Not duplicated. ${result.reason}` });
            const blocking = entries.find(([, field]) => result.reason.includes(field.label.toLowerCase()));
            if (blocking) o.showField(blocking[0], blocking[1]);
            return;
        }
        o.structure(result.ops, result.copyId);
        const node = doc.nodes[nodeId];
        o.setNotice({
            tone: 'info',
            message: `Duplicated ${node ? componentName(node).toLowerCase() : 'block'}; the copy is selected. Ctrl+Z undoes it.${result.notice ? ` ${result.notice}` : ''}`,
        });
    }, []);

    const addInto = useCallback((parentId: NodeId, type: string) => {
        const o = latest.current;
        if (!o.canChange()) return;
        const nodes = createNodes(type);
        o.structure(insertOps({ parentId, index: o.getDocument().nodes[parentId]?.children?.length ?? 0 }, nodes), nodes[0]!.id);
    }, []);

    // A preview of a block's entrance, for the document as it is now: the canvas plays it once its render of
    // exactly this version is shown (a later setting asks again; any other edit makes it obsolete).
    const [replay, setReplay] = useState<{ nodeId: string; seq: number; version: number } | null>(null);
    const replayBlock = useCallback(
        (nodeId: string) => setReplay((previous) => ({ nodeId, seq: (previous?.seq ?? 0) + 1, version: latest.current.documentVersion() })),
        [],
    );

    // ── Delete: one behaviour for the canvas controls, the toolbar, Layers and Delete/Backspace ──
    const [confirming, setConfirming] = useState<{ nodeId: NodeId; part: string | null; removal: Extract<Removal, { ok: true }> } | null>(null);
    const perform = useCallback((removal: Extract<Removal, { ok: true }>) => {
        const o = latest.current;
        const removed = new Set(removal.removed);
        o.cancelDragOf?.(removed);
        setReplay((current) => (current && removed.has(current.nodeId) ? null : current));
        if (o.structure(removal.ops, removal.select) === false) return;
        // The history entry this deletion made: the notice's Undo is bound to it, not to a position.
        const entry = o.history().undo.at(-1)?.id;
        if (entry === undefined) return;
        const available = () => canUndoEntry(latest.current.history(), entry);
        const what = removal.label === 'Remove image' ? 'Removed the image' : `${removal.label.replace(/^Delete/, 'Deleted')}`;
        o.setNotice({
            tone: 'info',
            message: `${what}. Nothing is saved or published until you choose to.`,
            action: {
                label: removal.label === 'Remove image' ? 'Undo image removal' : 'Undo delete',
                // Only while this deletion is exactly what Undo would undo (not after other edits, an undo,
                // a branch, a restore or the history cap); the editors drop the action once it isn't.
                available,
                onClick: () => {
                    if (available()) latest.current.undo();
                    latest.current.setNotice(null);
                },
            },
        });
    }, []);
    /** Deletes a block (or, with only a hero's image selected, the image); containers with content ask first. */
    const remove = useCallback(
        (nodeId: NodeId, part: string | null = null) => {
            const o = latest.current;
            if (!o.canChange()) return;
            const removal = removalOf(o.getDocument(), nodeId, part);
            if (!removal.ok) {
                o.setNotice({ tone: 'error', message: `Not deleted. ${removal.reason}` });
                return;
            }
            if (removal.confirm) setConfirming({ nodeId, part, removal });
            else perform(removal);
        },
        [perform],
    );
    const confirmRemove = useCallback(() => {
        const pending = confirming;
        setConfirming(null);
        if (!pending || !latest.current.canChange()) return;
        // Decided again on the document as it is now (it may have changed while the dialog was open).
        const removal = removalOf(latest.current.getDocument(), pending.nodeId, pending.part);
        if (removal.ok) perform(removal);
        else latest.current.setNotice({ tone: 'error', message: `Not deleted. ${removal.reason}` });
    }, [confirming, perform]);
    const cancelRemove = useCallback(() => setConfirming(null), []);

    return { duplicate, addInto, replay, replayBlock, remove, confirming: confirming?.removal ?? null, confirmRemove, cancelRemove };
}
