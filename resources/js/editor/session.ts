// Editor session pieces shared by the page editor and the reusable-component editor, so both
// follow the same rules: one activity at a time, one save coordinator, unresolved field input
// that blocks publishing, and protection against leaving with unsaved work.
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { Issue } from '@/arkon/rules';
import type { UnresolvedField } from './Inspector';

export type Activity = 'idle' | 'saving' | 'publishing' | 'restoring' | 'settings';

export interface Notice {
    tone: 'error' | 'info';
    message: string;
    issues?: Issue[];
    /** A follow-up action shown with the notice (e.g. Undo after a delete), while `available` holds. */
    action?: { label: string; onClick(): void; available?(): boolean };
}

/** The current activity, readable synchronously from callbacks (the ref) and for rendering (the state). */
export function useActivity() {
    const [activity, setState] = useState<Activity>('idle');
    const ref = useRef<Activity>('idle');
    const set = useCallback((next: Activity) => {
        ref.current = next;
        setState(next);
    }, []);
    /** Claims "saving" only when nothing else holds the activity; returns the release. */
    const beginSaving = useCallback(() => {
        const owns = ref.current === 'idle';
        if (owns) set('saving');
        return () => {
            if (owns && ref.current === 'saving') set('idle');
        };
    }, [set]);
    return { activity, activityRef: ref, setActivity: set, beginSaving };
}

/** A sticky flag (the draft changed elsewhere): once set, only a reload clears it. */
export function useConflict() {
    const [conflict, setState] = useState(false);
    const ref = useRef(false);
    const set = useCallback(() => {
        ref.current = true;
        setState(true);
    }, []);
    return { conflict, conflictRef: ref, setConflict: set };
}

/**
 * Field input that can't be applied yet (e.g. a half-typed link). Never in the document, so
 * saves stay valid; but it is visible work, so it is flagged, guards leaving, and must be
 * fixed or reverted before Preview and Publish.
 */
export function useUnresolvedFields(nodes: Record<string, unknown>) {
    const [unresolved, setState] = useState<Record<string, UnresolvedField>>({});
    const ref = useRef(unresolved);
    const replace = useCallback((next: Record<string, UnresolvedField>) => {
        ref.current = next;
        setState(next);
    }, []);
    const set = useCallback(
        (key: string, field: UnresolvedField | null) => {
            const next = { ...ref.current };
            if (field) next[key] = field;
            else delete next[key];
            replace(next);
        },
        [replace],
    );
    // A block removed (or undone away) takes its unresolved input with it.
    useEffect(() => {
        const current = ref.current;
        const kept = Object.fromEntries(Object.entries(current).filter(([, field]) => nodes[field.nodeId]));
        if (Object.keys(kept).length !== Object.keys(current).length) replace(kept);
    }, [nodes, replace]);
    return { unresolved, unresolvedRef: ref, setUnresolved: set, replaceUnresolved: replace, unresolvedCount: Object.keys(unresolved).length };
}

/** A notice without its action once the action no longer applies (checked whenever the document changes). */
export function withoutStaleAction(notice: Notice | null): Notice | null {
    return notice?.action?.available && !notice.action.available() ? { ...notice, action: undefined } : notice;
}

/** The notice shown when unresolved fields block an action. */
export function unresolvedNotice(fields: UnresolvedField[], action: string, keeps: string): Notice {
    const names = [...new Set(fields.map((f) => f.label))].join(', ');
    return {
        tone: 'error',
        message: `Fix or revert the ${names} before ${action}. ${keeps}`,
        issues: fields.map((f) => ({ message: `${f.label} “${f.value}”: ${f.error}` })),
    };
}

/**
 * Brings an unresolved field into view and focuses it, once the inspector shows it
 * (after the selection and tab change render).
 */
export function revealField(key: string) {
    let tries = 0;
    const attempt = () => {
        const element = document.querySelector<HTMLElement>(`[data-unresolved-field="${CSS.escape(key)}"]`);
        if (element) {
            element.scrollIntoView({ block: 'center' });
            element.focus({ preventScroll: true });
        } else if (++tries < 20) requestAnimationFrame(attempt);
    };
    requestAnimationFrame(attempt);
}

/** Asks before leaving with unsaved work: closing the tab, and links inside the app (Inertia visits). */
export function useLeaveGuard(wouldLoseWork: () => boolean) {
    const check = useRef(wouldLoseWork);
    check.current = wouldLoseWork;
    useEffect(() => {
        function onBeforeUnload(event: BeforeUnloadEvent) {
            if (check.current()) event.preventDefault();
        }
        window.addEventListener('beforeunload', onBeforeUnload);
        const stop = router.on('before', (event) => {
            if (event.detail.visit.method !== 'get' || !check.current()) return;
            if (!confirm('Leave the editor? Unsaved changes and fields that are not saved yet will be lost.')) event.preventDefault();
        });
        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            stop();
        };
    }, []);
}
