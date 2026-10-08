import { createContext, useContext, useEffect, useMemo, useRef, useSyncExternalStore, type ReactNode } from 'react';
import { Icon } from '@/Components/Icon';
import { DragController, type ControllerOptions, type DragState } from './controller';

const Context = createContext<DragController | null>(null);

/** The editor's drag controller (one per editor). */
export function useDragController(): DragController {
    const controller = useContext(Context);
    if (!controller) throw new Error('useDragController outside DragProvider');
    return controller;
}

export function useDragState(): DragState {
    const controller = useDragController();
    return useSyncExternalStore(controller.subscribe, controller.getState);
}

/**
 * Owns the editor's drag controller, and draws what follows the pointer: a small preview
 * of what moves, and a status line saying where it will land (or why it can't).
 */
export function DragProvider({
    options,
    enabled,
    theme,
    children,
    controllerRef,
}: {
    options: ControllerOptions;
    enabled: boolean;
    theme: string;
    children: ReactNode;
    /** The controller, for the editor itself (e.g. to end a drag whose block is deleted). */
    controllerRef?: { current: DragController | null };
}) {
    const controller = useMemo(() => new DragController(options), []);
    if (controllerRef) controllerRef.current = controller;
    // The latest callbacks (they close over editor state) without recreating the controller.
    controller.setOptions(options);
    useEffect(() => controller.setEnabled(enabled), [controller, enabled]);
    useEffect(() => () => controller.dispose(), [controller]);
    return (
        <Context.Provider value={controller}>
            {children}
            <div data-theme={theme} className="contents">
                <DragFeedback controller={controller} />
            </div>
        </Context.Provider>
    );
}

function DragFeedback({ controller }: { controller: DragController }) {
    const state = useSyncExternalStore(controller.subscribe, controller.getState);
    const preview = useRef<HTMLDivElement>(null);
    // The preview follows the pointer every frame without React renders.
    useEffect(
        () =>
            controller.onFrame((session) => {
                if (preview.current) preview.current.style.transform = `translate(${Math.round(session.x + 14)}px, ${Math.round(session.y + 16)}px)`;
            }),
        [controller],
    );
    const session = state.session;
    if (!session) return null;
    const result = session.result;
    const text =
        state.phase === 'resolving' || result === 'pending'
            ? 'Updating the canvas…'
            : result === null
              ? session.zone === null
                  ? 'Drop on the canvas or in Layers · Esc cancels'
                  : 'Not allowed here · Esc cancels'
              : result.kind === 'invalid'
                ? `Not allowed here: ${result.reason} · Esc cancels`
                : result.label;
    const tone =
        result && result !== 'pending' && result.kind === 'invalid'
            ? 'bg-danger text-white'
            : result && result !== 'pending' && result.kind === 'place'
              ? 'bg-fg text-canvas'
              : 'bg-raised text-fg';
    return (
        <>
            <div
                ref={preview}
                aria-hidden
                data-testid="drag-preview"
                className="pointer-events-none fixed top-0 left-0 z-50 flex items-center gap-1.5 rounded-md border border-accent bg-surface px-2 py-1 text-xs font-medium text-fg shadow-pop"
                style={{ transform: `translate(${session.x + 14}px, ${session.y + 16}px)` }}
            >
                <Icon name="move" className="size-3.5 text-accent" />
                {session.source.nodeId ? 'Move' : 'Add'} {session.source.label}
            </div>
            <div
                role="status"
                aria-live="polite"
                data-testid="drag-status"
                data-kind={result === null ? 'none' : result === 'pending' ? 'pending' : result.kind}
                className={`pointer-events-none fixed bottom-4 left-1/2 z-50 max-w-[min(90vw,40rem)] -translate-x-1/2 truncate rounded-full px-3 py-1.5 text-xs font-medium shadow-pop ${tone}`}
            >
                {text}
            </div>
        </>
    );
}
