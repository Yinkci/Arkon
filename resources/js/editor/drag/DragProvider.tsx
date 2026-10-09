import { createContext, useContext, useEffect, useLayoutEffect, useMemo, useRef, useSyncExternalStore, type ReactNode } from 'react';
import { previewTransform } from './visuals';
import { Icon } from '@/Components/Icon';
import { DragController, type ControllerOptions, type DragState, type Session } from './controller';

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
                if (preview.current)
                    preview.current.style.transform = previewTransform(session.x, session.y, {
                        ...session.origin,
                        rect: session.preview?.rect ?? session.origin.rect,
                        scale: session.preview
                            ? Math.min(1, (window.innerWidth - 32) / Math.max(1, session.preview.rect.width), 320 / Math.max(1, session.preview.rect.height))
                            : 1,
                    });
            }),
        [controller],
    );
    const session = state.session;
    if (!session) return null;
    const snapshot = session.preview;
    const scale = snapshot ? Math.min(1, (window.innerWidth - 32) / Math.max(1, snapshot.rect.width), 320 / Math.max(1, snapshot.rect.height)) : 1;
    const origin = { ...session.origin, rect: snapshot?.rect ?? session.origin.rect, scale };
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
                className="pointer-events-none fixed top-0 left-0 z-50 overflow-hidden rounded-md border border-accent bg-surface text-xs font-medium text-fg shadow-pop"
                style={{
                    transform: previewTransform(session.x, session.y, origin),
                    width: snapshot ? snapshot.rect.width * scale : undefined,
                    height: snapshot ? snapshot.rect.height * scale : undefined,
                }}
            >
                {snapshot ? (
                    <iframe
                        title="Dragged component preview"
                        sandbox=""
                        tabIndex={-1}
                        className="pointer-events-none border-0"
                        style={{ width: snapshot.rect.width, height: snapshot.rect.height, transform: 'scale(' + scale + ')', transformOrigin: 'top left' }}
                        srcDoc={
                            '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; img-src ' +
                            window.location.origin +
                            ' data:; style-src &apos;unsafe-inline&apos;; font-src ' +
                            window.location.origin +
                            '; base-uri &apos;none&apos;; form-action &apos;none&apos;"><style>' +
                            snapshot.css.replace(/<\/style/gi, '<\\/style') +
                            'html,body{margin:0;padding:0;overflow:hidden}*{animation:none!important;transition:none!important}</style></head><body>' +
                            snapshot.html +
                            '</body></html>'
                        }
                    />
                ) : (
                    <SourcePreview session={session} />
                )}
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

/** Parent UI previews are inert clones; canvas markup remains in a separate sandbox. */
function SourcePreview({ session }: { session: Session }) {
    const ref = useRef<HTMLDivElement>(null);
    useLayoutEffect(() => {
        if (session.source.origin === 'canvas' || !ref.current) return;
        const clone = session.origin.element.cloneNode(true) as HTMLElement;
        clone.inert = true;
        clone.style.opacity = '1';
        clone.classList.remove('outline-1', 'outline-dashed', 'outline-accent');
        clone.querySelectorAll('[data-row-action]').forEach((el) => el.remove());
        clone.setAttribute('aria-hidden', 'true');
        for (const el of [clone, ...clone.querySelectorAll('*')]) {
            el.removeAttribute('id');
            el.removeAttribute('data-testid');
        }
        clone.style.width = session.origin.rect.width + 'px';
        clone.style.margin = '0';
        ref.current.replaceChildren(clone);
        return () => ref.current?.replaceChildren();
    }, [session.id]);
    return session.source.origin === 'canvas' ? (
        <div className="flex items-center gap-1.5 px-2 py-1">
            <Icon name="move" className="size-3.5 text-accent" />
            Move {session.source.label}
        </div>
    ) : (
        <div ref={ref} />
    );
}
