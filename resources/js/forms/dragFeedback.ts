import { previewTransform, placementAnimation } from '@/editor/drag/visuals';
import type { DragSource, DropZone, Session } from '@/editor/drag/controller';
import { formDrop, scrollVelocity, type FormRowGeometry } from './dragPlacement';

/** DOM-only pointer feedback; React updates only when the destination changes or a drop commits. */
export class FormDragFeedback {
    private origin: { element: HTMLElement; x: number; y: number; rect: DOMRect } | null = null;
    private geometry: FormRowGeometry[] = [];
    private ghost: HTMLElement | null = null;
    private marker: HTMLElement | null = null;
    private source: HTMLElement | null = null;
    private before = new Map<string, DOMRect>();
    private animations: Animation[] = [];
    private frameId = 0;
    private initialScroll = 0;
    private initialTop = 0;
    private targetKey = '';
    constructor(
        private canvas: () => HTMLElement | null,
        private mobile: () => boolean,
    ) {}
    prepare(event: { clientX: number; clientY: number }, element: HTMLElement) {
        this.dispose();
        const card = element.closest<HTMLElement>('[data-form-field]') ?? element;
        this.origin = { element: card, x: event.clientX, y: event.clientY, rect: card.getBoundingClientRect() };
    }
    private clearSpace() {
        this.canvas()
            ?.querySelectorAll<HTMLElement>('[data-form-row],[data-form-field]')
            .forEach((e) => e.style.removeProperty('transform'));
        this.targetKey = '';
    }
    zone: DropZone = {
        id: 'canvas',
        contains: (x, y) => {
            const r = this.canvas()?.getBoundingClientRect();
            return !!r && x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
        },
        begin: (session) => {
            const canvas = this.canvas(),
                origin = this.origin;
            if (!canvas || !origin) return;
            const bounds = canvas.getBoundingClientRect();
            this.initialScroll = canvas.scrollTop;
            this.initialTop = bounds.top;
            const rect = (e: HTMLElement) => {
                const r = e.getBoundingClientRect();
                return { top: r.top - bounds.top + canvas.scrollTop, left: r.left - bounds.left, width: r.width, height: r.height };
            };
            this.geometry = Array.from(canvas.querySelectorAll<HTMLElement>('[data-form-row]')).map((row) => ({
                id: row.dataset.formRow!,
                rect: rect(row),
                cards: Array.from(row.querySelectorAll<HTMLElement>('[data-form-field]')).map((card) => ({ id: card.dataset.formField!, rect: rect(card) })),
            }));
            canvas.querySelectorAll<HTMLElement>('[data-form-field]').forEach((e) => this.before.set(e.dataset.formField!, e.getBoundingClientRect()));
            this.source = session.source.nodeId ? origin.element : null;
            this.ghost = origin.element.cloneNode(true) as HTMLElement;
            this.ghost.querySelectorAll('[id]').forEach((e) => e.removeAttribute('id'));
            this.ghost.querySelectorAll('[data-form-field],[data-form-row]').forEach((e) => {
                e.removeAttribute('data-form-field');
                e.removeAttribute('data-form-row');
            });
            this.ghost.removeAttribute('data-form-field');
            this.ghost.removeAttribute('data-form-row');
            this.ghost.inert = true;
            this.ghost.setAttribute('aria-hidden', 'true');
            this.ghost.dataset.testid = 'form-drag-preview';
            this.ghost.dataset.theme = origin.element.closest('[data-theme]')?.getAttribute('data-theme') ?? 'light';
            this.ghost.classList.add('ak-form-drag-preview');
            Object.assign(this.ghost.style, { width: origin.rect.width + 'px', height: origin.rect.height + 'px' });
            document.body.append(this.ghost);
            this.source?.classList.add('ak-form-drag-source');
            this.marker = document.createElement('div');
            this.marker.className = 'ak-form-drop-marker';
            this.marker.dataset.testid = 'form-drop-marker';
            this.marker.setAttribute('aria-hidden', 'true');
            document.body.append(this.marker);
            this.frame(session);
        },
        resolve: (source, x, y, previous) => {
            const canvas = this.canvas()!,
                r = canvas.getBoundingClientRect();
            const result = formDrop(this.geometry, source, x - r.left, y - r.top + canvas.scrollTop, previous, this.mobile());
            if ('indicator' in result && result.indicator)
                result.indicator = {
                    ...result.indicator,
                    rect: { ...result.indicator.rect, left: result.indicator.rect.left + r.left, top: result.indicator.rect.top + r.top - canvas.scrollTop },
                };
            return result;
        },
        autoscroll: (x, y, dt) => {
            const canvas = this.canvas()!,
                r = canvas.getBoundingClientRect();
            const before = canvas.scrollTop;
            if (x >= r.left && x <= r.right && y >= r.top - 16 && y <= r.bottom + 16)
                canvas.scrollTop += scrollVelocity(y, r.top, r.bottom) * Math.min(dt, 0.05);
            return before !== canvas.scrollTop;
        },
        frame: (session) => this.frame(session),
        end: () => {
            this.marker?.remove();
            this.marker = null;
            this.clearSpace();
            this.source?.classList.remove('ak-form-drag-source');
        },
    };
    private frame(session: Session) {
        const origin = this.origin;
        if (!origin || !this.ghost) return;
        this.ghost.style.transform = previewTransform(session.x, session.y, origin);
        const result = session.result;
        if (!this.marker) return;
        if (!result || result === 'pending' || result.kind === 'invalid') {
            this.marker.hidden = true;
            this.clearSpace();
            return;
        }
        this.marker.hidden = false;
        const rect = result.indicator.rect,
            canvas = this.canvas()!,
            bounds = canvas.getBoundingClientRect();
        Object.assign(this.marker.style, {
            left: Math.max(bounds.left, rect.left) + 'px',
            top: Math.max(bounds.top, rect.top) + 'px',
            width: Math.min(rect.width, bounds.right - rect.left) + 'px',
            height: Math.min(rect.height, bounds.bottom - rect.top) + 'px',
        });
        this.marker.dataset.axis = result.indicator.axis;
        const key = result.parentId + ':' + result.index;
        if (key === this.targetKey) return;
        this.clearSpace();
        this.targetKey = key;
        if (result.parentId.startsWith('newrow:')) {
            const index = Number(result.parentId.split(':')[1]);
            canvas.querySelectorAll<HTMLElement>('[data-form-row]').forEach((e, n) => {
                if (n >= index) e.style.transform = 'translateY(12px)';
            });
        } else {
            const row = Array.from(canvas.querySelectorAll<HTMLElement>('[data-form-row]')).find((e) => e.dataset.formRow === result.parentId);
            row?.querySelectorAll<HTMLElement>('[data-form-field]').forEach((e, n) => {
                e.style.transform = `translateX(${n >= result.index ? 6 : -6}px)`;
            });
        }
    }
    finish(committed: boolean, source: DragSource) {
        const ghost = this.ghost;
        this.ghost = null;
        const origin = this.origin;
        if (!ghost) return;
        this.frameId = requestAnimationFrame(() => {
            const canvas = this.canvas();
            const scrollDelta = (canvas?.scrollTop ?? 0) - this.initialScroll;
            const pageDelta = (canvas?.getBoundingClientRect().top ?? this.initialTop) - this.initialTop;
            const cards = Array.from(canvas?.querySelectorAll<HTMLElement>('[data-form-field]') ?? []);
            const target = cards.find((e) => e.dataset.formField === source.nodeId);
            const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduced) {
                ghost.remove();
                return;
            }
            const rect = committed ? target?.getBoundingClientRect() : origin?.rect;
            if (rect) {
                const anim = placementAnimation(ghost, [
                    { transform: ghost.style.transform, opacity: 1 },
                    { transform: `translate3d(${rect.left}px,${rect.top}px,0)`, opacity: 0 },
                ]);
                if (!anim) {
                    ghost.remove();
                    return;
                }
                this.animations.push(anim);
                anim.onfinish = () => ghost.remove();
            } else ghost.remove();
            if (committed)
                for (const card of cards) {
                    const old = this.before.get(card.dataset.formField!),
                        next = card.getBoundingClientRect();
                    if (old && Math.hypot(old.left - next.left, old.top - scrollDelta + pageDelta - next.top) > 1)
                        this.animations.push(
                            card.animate([{ transform: `translate(${old.left - next.left}px,${old.top - next.top}px)` }, { transform: 'translate(0,0)' }], {
                                duration: 160,
                                easing: 'ease-out',
                            }),
                        );
                }
        });
    }
    dispose() {
        cancelAnimationFrame(this.frameId);
        this.animations.forEach((a) => a.cancel());
        this.animations = [];
        document.querySelectorAll('[data-testid="form-drag-preview"]').forEach((e) => e.remove());
        this.ghost = null;
        this.marker?.remove();
        this.marker = null;
        this.source?.classList.remove('ak-form-drag-source');
        this.clearSpace();
        this.before.clear();
    }
}
