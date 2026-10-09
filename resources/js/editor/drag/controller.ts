// One drag lifecycle for every way of moving or adding a block: the canvas Move handle, Layers
// rows and the palette, in the page editor and the reusable-component editor.
//
// - A press becomes a drag only after the pointer moves a few pixels (a click stays a click).
// - Pointer events with capture, for mouse, pen and touch (handles set touch-action: none).
// - Everything per frame runs in one requestAnimationFrame loop: automatic scrolling (also
//   while the pointer is still), resolving the destination when the pointer, scroll position or
//   geometry changed, and moving the preview. React state changes only when the destination does.
// - The release resolves the destination from the release coordinates, synchronously, from the
//   current geometry. Only if a zone's geometry is not current yet (the canvas is re-rendering)
//   does it wait, briefly and with a bound, then cancel safely.
// - Escape, window blur, a hidden tab, pointercancel, lost capture, unmount and permission
//   changes cancel. A cancelled or invalid drag changes nothing.
import type { NodeId } from '@/arkon/schema/document';
import type { Resolution } from '@/arkon/editor/placement';

export interface DragSource {
    /** The block being moved; absent when a new block is being added. */
    nodeId?: NodeId;
    type: string;
    /** Props of a new block (e.g. which reusable component). */
    props?: Record<string, unknown>;
    /** What moves, for the preview: "Hero section", "Text: Welcome". */
    label: string;
    origin: 'canvas' | 'layers' | 'palette';
}

/** 'pending': the zone's geometry is being updated (e.g. the canvas is re-rendering). */
export type ZoneResult = Resolution | 'pending';

export interface DropZone {
    id: 'canvas' | 'layers';
    /** Whether the point (client coordinates) is over this zone's visible area. */
    contains(x: number, y: number): boolean;
    resolve(source: DragSource, x: number, y: number, previous: { parentId: NodeId; index: number } | null): ZoneResult;
    /** Scrolls when the point is near the zone's edges; true if it scrolled (the destination is recomputed). */
    autoscroll?(x: number, y: number, dt: number): boolean;
    /** Runs every frame while dragging (positions indicators, follows scrolling). */
    frame?(session: Session): void;
    begin?(session: Session): void;
    end?(): void;
    /** Resolves true once the geometry is current, false after `timeoutMs`. */
    ready?(timeoutMs: number): Promise<boolean>;
}

export interface DragSnapshot {
    html: string;
    css: string;
    rect: { left: number; top: number; width: number; height: number };
}
export interface Session {
    origin: { element: HTMLElement; x: number; y: number; rect: { left: number; top: number; width: number; height: number } };
    preview?: DragSnapshot;
    id: number;
    source: DragSource;
    pointerType: string;
    x: number;
    y: number;
    zone: DropZone['id'] | null;
    result: ZoneResult | null;
    /** The editor's document version when the drag started (checked again at the drop). */
    docVersion: number;
}

export type DragPhase = 'idle' | 'dragging' | 'resolving';

export interface DragState {
    phase: DragPhase;
    session: Session | null;
}

export type CancelReason = 'escape' | 'blur' | 'pointercancel' | 'capture' | 'unmount' | 'disabled' | 'outside' | 'invalid' | 'timeout' | 'noop' | 'stale';

export interface ControllerOptions {
    /** Applies a drop. Called with a destination resolved at the release; returns whether it changed the page. */
    commit(session: Session, result: Extract<Resolution, { kind: 'place' }>): boolean;
    /** The editor's current document version. */
    documentVersion(): number;
    onEnd?(outcome: { committed: boolean; reason?: CancelReason; session: Session }): void;
    /** Milliseconds a release may wait for current geometry before cancelling. */
    resolveTimeout?: number;
}

const THRESHOLD = { mouse: 4, pen: 4, touch: 8 } as Record<string, number>;

export class DragController {
    private zones = new Map<DropZone['id'], DropZone>();
    private listeners = new Set<() => void>();
    private endListeners = new Set<(session: Session, committed: boolean) => void>();
    private frameListeners = new Set<(session: Session) => void>();
    private state: DragState = { phase: 'idle', session: null };
    private pressed: {
        source: DragSource;
        onTap?: () => void;
        pointerId: number;
        pointerType: string;
        startX: number;
        startY: number;
        element: HTMLElement;
    } | null = null;
    private session: Session | null = null;
    private previous: { parentId: NodeId; index: number } | null = null;
    private dirty = false;
    private raf = 0;
    private lastFrame = 0;
    private nextId = 1;
    private suppressClick = false;
    private detach: (() => void) | null = null;
    private enabled = true;

    constructor(private options: ControllerOptions) {}

    setOptions(options: ControllerOptions) {
        this.options = options;
    }

    // ── Subscriptions (useSyncExternalStore) ──
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    getState = () => this.state;
    /** Called every frame while dragging (for things that follow the pointer without React renders). */
    onFrame(listener: (session: Session) => void) {
        this.frameListeners.add(listener);
        return () => {
            this.frameListeners.delete(listener);
        };
    }

    onFinish(listener: (session: Session, committed: boolean) => void) {
        this.endListeners.add(listener);
        return () => {
            this.endListeners.delete(listener);
        };
    }

    registerZone(zone: DropZone) {
        this.zones.set(zone.id, zone);
        if (this.session) zone.begin?.(this.session);
        return () => {
            if (this.zones.get(zone.id) === zone) this.zones.delete(zone.id);
            zone.end?.();
        };
    }

    /** Geometry, scrolling or the document changed: recompute the destination on the next frame. */
    invalidate() {
        this.dirty = true;
    }

    setEnabled(enabled: boolean) {
        this.enabled = enabled;
        if (!enabled) this.cancel('disabled');
    }

    /** True right after a drag ended, so the click that follows a pointerup is not treated as a click. */
    consumeClick(): boolean {
        const suppressed = this.suppressClick;
        this.suppressClick = false;
        return suppressed;
    }

    /** A pointerdown on a drag source. Nothing happens until the pointer moves past the threshold. */
    press(source: DragSource, event: PointerEvent | React.PointerEvent, element: HTMLElement, onTap?: () => void) {
        if (!this.enabled || this.pressed || this.session) return;
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        this.pressed = {
            source,
            onTap,
            pointerId: event.pointerId,
            pointerType: event.pointerType || 'mouse',
            startX: event.clientX,
            startY: event.clientY,
            element,
        };
        this.suppressClick = false;
        // Capture from the press on: moves over the canvas iframe would otherwise go into the iframe.
        try {
            element.setPointerCapture(event.pointerId);
        } catch {
            // Capture is a robustness aid; window listeners still work without it.
        }
        this.listen();
    }

    private listen() {
        const move = (event: PointerEvent) => this.onMove(event);
        const up = (event: PointerEvent) => void this.onUp(event);
        const cancel = (event: PointerEvent) => {
            if (this.pressed && event.pointerId !== this.pressed.pointerId) return;
            this.cancel('pointercancel');
        };
        const key = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && (this.session || this.pressed)) {
                event.preventDefault();
                event.stopPropagation();
                this.cancel('escape');
            }
        };
        const blur = () => this.cancel('blur');
        const hidden = () => document.visibilityState === 'hidden' && this.cancel('blur');
        const lost = () => this.state.phase === 'dragging' && this.cancel('capture');
        const element = this.pressed?.element;
        window.addEventListener('pointermove', move, true);
        window.addEventListener('pointerup', up, true);
        window.addEventListener('pointercancel', cancel, true);
        window.addEventListener('keydown', key, true);
        window.addEventListener('blur', blur);
        document.addEventListener('visibilitychange', hidden);
        element?.addEventListener('lostpointercapture', lost);
        this.detach = () => {
            window.removeEventListener('pointermove', move, true);
            window.removeEventListener('pointerup', up, true);
            window.removeEventListener('pointercancel', cancel, true);
            window.removeEventListener('keydown', key, true);
            window.removeEventListener('blur', blur);
            document.removeEventListener('visibilitychange', hidden);
            element?.removeEventListener('lostpointercapture', lost);
        };
    }

    private onMove(event: PointerEvent) {
        if (this.pressed && event.pointerId !== this.pressed.pointerId) return;
        if (this.pressed && !this.session) {
            const moved = Math.hypot(event.clientX - this.pressed.startX, event.clientY - this.pressed.startY);
            if (moved < (THRESHOLD[this.pressed.pointerType] ?? 4)) return;
            this.start(event.clientX, event.clientY);
        }
        if (!this.session || this.state.phase !== 'dragging') return;
        event.preventDefault();
        this.session.x = event.clientX;
        this.session.y = event.clientY;
        this.dirty = true;
    }

    setPreview(id: number, preview: DragSnapshot) {
        if (
            this.session?.id !== id ||
            !preview ||
            typeof preview.html !== 'string' ||
            typeof preview.css !== 'string' ||
            preview.html.length > 160000 ||
            preview.css.length > 500000 ||
            !preview.rect ||
            !Object.values(preview.rect).every(Number.isFinite) ||
            preview.rect.width <= 0 ||
            preview.rect.height <= 0
        )
            return;
        this.session.preview = preview;
        this.setState({ phase: this.state.phase, session: { ...this.session } });
    }

    private start(x: number, y: number) {
        const pressed = this.pressed!;
        this.session = {
            origin: { element: pressed.element, x: pressed.startX, y: pressed.startY, rect: pressed.element.getBoundingClientRect() },
            id: this.nextId++,
            source: pressed.source,
            pointerType: pressed.pointerType,
            x,
            y,
            zone: null,
            result: null,
            docVersion: this.options.documentVersion(),
        };
        this.previous = null;
        this.dirty = true;
        document.documentElement.classList.add('ak-dragging');
        for (const zone of this.zones.values()) zone.begin?.(this.session);
        this.setState({ phase: 'dragging', session: { ...this.session } });
        this.lastFrame = performance.now();
        this.raf = requestAnimationFrame(this.tick);
    }

    private tick = (now: number) => {
        const session = this.session;
        if (!session || this.state.phase !== 'dragging') return;
        const dt = Math.max(0, (now - this.lastFrame) / 1000);
        this.lastFrame = now;
        for (const zone of this.zones.values()) if (zone.autoscroll?.(session.x, session.y, dt)) this.dirty = true;
        if (this.dirty) {
            this.dirty = false;
            this.resolveAt(session, session.x, session.y);
        }
        for (const zone of this.zones.values()) zone.frame?.(session);
        for (const listener of this.frameListeners) listener(session);
        this.raf = requestAnimationFrame(this.tick);
    };

    private zoneAt(x: number, y: number): DropZone | null {
        for (const zone of this.zones.values()) if (zone.contains(x, y)) return zone;
        return null;
    }

    private resolveAt(session: Session, x: number, y: number) {
        const zone = this.zoneAt(x, y);
        const result = zone ? zone.resolve(session.source, x, y, this.previous) : null;
        if (result && result !== 'pending' && (result.kind === 'place' || result.kind === 'noop'))
            this.previous = { parentId: result.parentId, index: result.index };
        const changed = session.zone !== (zone?.id ?? null) || keyOf(session.result) !== keyOf(result);
        session.zone = zone?.id ?? null;
        session.result = result;
        if (changed) this.setState({ phase: this.state.phase, session: { ...session } });
        return { zone, result };
    }

    private async onUp(event: PointerEvent) {
        if (this.pressed && event.pointerId !== this.pressed.pointerId) return;
        const session = this.session;
        if (!session) {
            // A press without movement is a tap: the source's own action (e.g. selecting a row).
            const onTap = this.pressed?.onTap;
            this.cleanup();
            onTap?.();
            return;
        }
        this.suppressClick = true;
        cancelAnimationFrame(this.raf);
        session.x = event.clientX;
        session.y = event.clientY;
        // The destination is resolved here, from the release coordinates; a stored earlier one is never used.
        let { zone, result } = this.resolveAt(session, event.clientX, event.clientY);
        if (zone && result === 'pending') {
            this.setState({ phase: 'resolving', session: { ...session } });
            const ready = await (zone.ready?.(this.options.resolveTimeout ?? 600) ?? Promise.resolve(false));
            if (this.session?.id !== session.id) return; // cancelled meanwhile
            if (!ready) return this.finish(session, false, 'timeout');
            ({ zone, result } = this.resolveAt(session, session.x, session.y));
        }
        if (!zone) return this.finish(session, false, 'outside');
        if (!result || result === 'pending') return this.finish(session, false, 'timeout');
        if (result.kind === 'noop') return this.finish(session, false, 'noop');
        if (result.kind === 'invalid') return this.finish(session, false, 'invalid');
        if (this.options.documentVersion() !== session.docVersion) return this.finish(session, false, 'stale');
        const committed = this.options.commit(session, result);
        this.finish(session, committed, committed ? undefined : 'stale');
    }

    cancel(reason: CancelReason = 'escape') {
        const session = this.session;
        if (session) this.finish(session, false, reason);
        else this.cleanup();
    }

    private finish(session: Session, committed: boolean, reason?: CancelReason) {
        if (this.session?.id !== session.id) return;
        this.suppressClick = true;
        this.cleanup();
        this.options.onEnd?.({ committed, reason, session });
        for (const listener of this.endListeners) listener(session, committed);
    }

    private cleanup() {
        cancelAnimationFrame(this.raf);
        this.detach?.();
        this.detach = null;
        if (this.pressed) {
            try {
                this.pressed.element.releasePointerCapture(this.pressed.pointerId);
            } catch {
                // Already released.
            }
        }
        const hadSession = this.session !== null;
        this.pressed = null;
        this.session = null;
        this.previous = null;
        document.documentElement.classList.remove('ak-dragging');
        if (hadSession) {
            for (const zone of this.zones.values()) zone.end?.();
            this.setState({ phase: 'idle', session: null });
        }
    }

    dispose() {
        this.cancel('unmount');
        this.listeners.clear();
        this.frameListeners.clear();
        this.endListeners.clear();
    }

    private setState(state: DragState) {
        this.state = state;
        for (const listener of this.listeners) listener();
    }
}

function keyOf(result: ZoneResult | null): string {
    if (!result) return 'none';
    if (result === 'pending') return 'pending';
    if (result.kind === 'invalid') return `invalid:${result.reason}`;
    return `${result.kind}:${result.parentId}:${result.index}`;
}
