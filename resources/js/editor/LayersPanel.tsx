import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { ReusableComponentInfo } from '@/types';
import { currentDefinition } from '@/arkon/components/registry';
import { canRemove, insertOps, insertionFor, nodeLabel } from '@/arkon/editor/structure';
import { resolveTreeSlot, scrollStep, type TreeRow } from '@/arkon/editor/placement';
import { removeOps } from '@/arkon/editor/columns';
import { removalOf } from '@/arkon/editor/remove';
import { findParent, type NodeId, type PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import { componentName } from '@/arkon/editor/parts';
import { Icon, type IconName } from '@/Components/Icon';
import { Button } from '@/Components/ui';
import { BlockPicker } from './BlockPicker';
import type { DragSource, DropZone } from './drag/controller';
import { placementAnimation } from './drag/visuals';
import { useDragController, useDragState } from './drag/DragProvider';
import { usePalettePreference } from './chrome';

const TYPE_ICON: Record<string, IconName> = {
    section: 'layers',
    group: 'grip',
    hero: 'home',
    text: 'text',
    image: 'image',
    button: 'link',
    columns: 'sliders',
    column: 'pages',
    instance: 'component',
};

export interface LayersPanelProps {
    document: PageDocument;
    selectedId: NodeId | null;
    canEdit: boolean;
    onSelect(nodeId: NodeId | null): void;
    /** Applies structural operations; `select` is the node to select afterwards. */
    onStructure(ops: PageOperation[], select?: NodeId | null): void;
    /** The site's reusable components (only published ones can be placed). */
    components?: ReusableComponentInfo[];
    /** Duplicates a block right after itself (one undo step); absent where not offered. */
    onDuplicate?(nodeId: NodeId): void;
    /** Deletes a block (the editor's shared deletion: asks first for containers with content). */
    onDelete?(nodeId: NodeId): void;
}

/** The nearest scrolling ancestor (the sidebar), for automatic scrolling while dragging over Layers. */
function scrollParent(el: HTMLElement | null): HTMLElement | null {
    for (let node = el?.parentElement ?? null; node; node = node.parentElement) {
        const overflow = getComputedStyle(node).overflowY;
        if (overflow === 'auto' || overflow === 'scroll') return node;
    }
    return null;
}

/**
 * The page outline. Every component can be selected, moved up or down, removed and
 * dragged (by its row or its grip; on touch screens by the grip); containers accept drops
 * inside. Buttons are the keyboard (and screen reader) path; dragging is the pointer
 * shortcut. Drags use the editor's shared drag controller, so a row or a palette item
 * can be dropped here or on the canvas with the same rules and feedback.
 */
export function LayersPanel({ document: doc, selectedId, canEdit, onSelect, onStructure, components = [], onDuplicate, onDelete }: LayersPanelProps) {
    const [adding, setAdding] = usePalettePreference();
    const root = doc.nodes[doc.root]!;
    const controller = useDragController();
    const drag = useDragState();
    const treeRef = useRef<HTMLElement>(null);
    const indicatorRef = useRef<HTMLDivElement>(null);
    const rowFeedback = useRef(new Map<HTMLElement, { translate: string; transition: string }>());
    const feedbackSlot = useRef('');
    const beforeDrop = useRef<Map<string, DOMRect> | null>(null);
    const rowAnimations = useRef<Animation[]>([]);
    const clearRows = () => {
        for (const [el, saved] of rowFeedback.current) {
            el.style.transition = 'none';
            el.style.translate = saved.translate;
            void el.offsetWidth;
            el.style.transition = saved.transition;
        }
        rowFeedback.current.clear();
        feedbackSlot.current = '';
    };
    const docRef = useRef(doc);
    docRef.current = doc;

    useEffect(
        () =>
            controller.onFinish((_session, committed) => {
                clearRows();
                if (committed)
                    beforeDrop.current = new Map(
                        Array.from(treeRef.current?.querySelectorAll<HTMLElement>('[data-testid="layer"]') ?? []).map((el) => [
                            el.dataset.nodeId!,
                            el.getBoundingClientRect(),
                        ]),
                    );
            }),
        [controller],
    );
    useLayoutEffect(() => {
        const before = beforeDrop.current;
        beforeDrop.current = null;
        if (!before) return;
        rowAnimations.current.forEach((a) => a.cancel());
        rowAnimations.current = [];
        for (const el of treeRef.current?.querySelectorAll<HTMLElement>('[data-testid="layer"]') ?? []) {
            const old = before.get(el.dataset.nodeId!),
                r = el.getBoundingClientRect();
            if (!old) continue;
            if (Math.hypot(old.left - r.left, old.top - r.top) < 1) continue;
            const a = placementAnimation(el, [{ translate: old.left - r.left + 'px ' + (old.top - r.top) + 'px' }, { translate: '0px 0px' }]);
            if (a) rowAnimations.current.push(a);
        }
    }, [doc]);
    useEffect(
        () => () => {
            clearRows();
            rowAnimations.current.forEach((a) => a.cancel());
        },
        [],
    );

    // ── Drop zone: the outline ──
    useEffect(() => {
        const rows = (): TreeRow[] => {
            // Measure layout positions, never the temporary visual displacement.
            for (const [el, saved] of rowFeedback.current) {
                el.style.transition = 'none';
                el.style.translate = saved.translate;
            }
            const rows = [...(treeRef.current?.querySelectorAll<HTMLElement>('[data-testid="layer"]') ?? [])].map((row) => ({
                id: row.dataset.nodeId!,
                depth: Number(row.dataset.depth),
                rect: row.getBoundingClientRect(),
            }));
            for (const el of rowFeedback.current.keys()) {
                el.style.translate = '0px 10px';
                void el.offsetWidth;
                el.style.transition = 'translate 160ms ease-out';
            }
            return rows;
        };
        const visible = () => {
            const section = treeRef.current?.getBoundingClientRect();
            const scroller = scrollParent(treeRef.current)?.getBoundingClientRect();
            if (!section) return null;
            const top = Math.max(section.top, scroller?.top ?? section.top);
            const bottom = Math.min(section.bottom, scroller?.bottom ?? section.bottom);
            return bottom > top ? { left: section.left, right: section.right, top, bottom } : null;
        };
        const zone: DropZone = {
            id: 'layers',
            contains(x, y) {
                const r = visible();
                return !!r && x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
            },
            resolve(source, x, y) {
                return resolveTreeSlot(docRef.current, source, rows(), x, y);
            },
            autoscroll(x, y, dt) {
                const scroller = scrollParent(treeRef.current);
                if (!scroller) return false;
                const r = scroller.getBoundingClientRect();
                if (x < r.left || x > r.right) return false;
                const dy = scrollStep(y, r.top, r.bottom, scroller.scrollTop, scroller.scrollHeight - scroller.clientHeight, dt);
                if (Math.abs(dy) < 0.5) return false;
                scroller.scrollTop += dy;
                return true;
            },
            frame(session) {
                const el = indicatorRef.current;
                const tree = treeRef.current?.getBoundingClientRect();
                const result = session.result;
                if (!el || !tree) return;
                if (session.zone !== 'layers' || !result || result === 'pending' || !result.indicator) {
                    el.style.display = 'none';
                    clearRows();
                    return;
                }
                const key = result.kind === 'place' ? result.parentId + ':' + result.index : '';
                if (key !== feedbackSlot.current) {
                    clearRows();
                    feedbackSlot.current = key;
                    if (result.kind === 'place' && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        const ids = docRef.current.nodes[result.parentId]?.children?.slice(result.index) ?? [];
                        const descendants = new Set<string>();
                        const collect = (id: string) => {
                            descendants.add(id);
                            docRef.current.nodes[id]?.children?.forEach(collect);
                        };
                        ids.filter((id) => id !== session.source.nodeId).forEach(collect);
                        for (const el of treeRef.current?.querySelectorAll<HTMLElement>('[data-testid="layer"]') ?? []) {
                            if (!descendants.has(el.dataset.nodeId!)) continue;
                            rowFeedback.current.set(el, { translate: el.style.translate, transition: el.style.transition });
                            el.style.transition = 'translate 160ms ease-out';
                            el.style.translate = '0px 10px';
                        }
                    }
                }
                const r = result.indicator.rect;
                el.style.display = 'block';
                el.dataset.kind = result.indicator.kind;
                el.dataset.result = result.kind;
                el.style.transform = `translate(${Math.round(r.left - tree.left)}px, ${Math.round(r.top - tree.top)}px)`;
                el.style.width = `${Math.round(r.width)}px`;
                el.style.height = `${Math.round(r.height)}px`;
            },
            begin() {
                rowAnimations.current.forEach((a) => a.cancel());
                rowAnimations.current = [];
            },
            end() {
                clearRows();
                if (indicatorRef.current) indicatorRef.current.style.display = 'none';
            },
        };
        return controller.registerZone(zone);
    }, [controller]);

    const press = (source: DragSource, onTap?: () => void) => (event: React.PointerEvent<HTMLElement>) => {
        if (!canEdit) return;
        // On touch screens only the grip starts a drag, so the list still scrolls with a finger.
        if (event.pointerType === 'touch' && !(event.target as HTMLElement).closest('[data-drag-grip]')) return;
        if ((event.target as HTMLElement).closest('[data-row-action]')) return;
        controller.press(source, event, event.currentTarget, onTap);
    };
    const dragging = drag.session?.source;

    const renderNode = (id: NodeId, depth: number): React.ReactNode => {
        const node = doc.nodes[id];
        if (!node) return null;
        const label = nodeLabel(node);
        const location = findParent(doc, id);
        const name = currentDefinition(node.type)?.label ?? node.type;
        const selected = id === selectedId;
        return (
            <li key={id} role="treeitem" aria-selected={selected} aria-expanded={node.children ? true : undefined} aria-level={depth + 1}>
                <div
                    data-testid="layer"
                    data-node-id={id}
                    data-node-type={node.type}
                    data-parent-id={location?.parentId}
                    data-depth={depth}
                    onPointerDown={press(
                        { nodeId: id, type: node.type, label: componentName(node) === node.type ? label : `${componentName(node)}`, origin: 'layers' },
                        () => onSelect(id),
                    )}
                    className={`group relative flex h-8 items-center gap-1 rounded-md pr-1 text-ui select-none ${selected ? 'bg-accent-soft text-fg' : 'hover:bg-hover'} ${
                        dragging?.nodeId === id ? 'opacity-40 outline-1 outline-dashed outline-accent' : ''
                    } ${canEdit ? 'cursor-grab' : ''}`}
                    style={{ paddingLeft: `${0.375 + depth * 0.875}rem` }}
                >
                    {depth > 0 && (
                        <span
                            aria-hidden
                            className="absolute top-0 bottom-0 border-l border-line"
                            style={{ left: `${0.375 + (depth - 1) * 0.875 + 0.45}rem` }}
                        />
                    )}
                    {canEdit && (
                        <span
                            data-drag-grip
                            aria-hidden
                            title={`Drag to move ${name}`}
                            className="grid h-7 w-5 shrink-0 cursor-grab touch-none place-items-center rounded text-faint group-hover:text-muted hover:bg-hover"
                        >
                            <Icon name="grip" className="size-3.5" />
                        </span>
                    )}
                    <Icon name={TYPE_ICON[node.type] ?? 'component'} className={`size-3.5 ${selected ? 'text-accent' : 'text-muted'}`} />
                    <button
                        type="button"
                        onClick={() => {
                            if (controller.consumeClick()) return; // the end of a drag, not a click
                            onSelect(id);
                        }}
                        className="mr-[3.25rem] min-w-0 flex-1 cursor-[inherit] truncate py-1 text-left"
                        aria-current={selected ? 'true' : undefined}
                        title={`${componentName(node)}: ${label}`}
                    >
                        <span className={selected ? 'font-medium' : ''}>{label}</span>
                    </button>
                    {/* Actions overlay the end of the row: shown for the selected row, on hover and on focus (always on touch screens). */}
                    <span
                        data-row-action
                        className={`absolute inset-y-0 right-0 flex items-center rounded-r-md transition-opacity duration-100 ${
                            selected
                                ? 'bg-accent-soft'
                                : 'bg-surface opacity-0 group-hover:opacity-100 focus-within:opacity-100 [@media(hover:none)]:opacity-100'
                        }`}
                    >
                        <span className={`flex h-full items-center rounded-r-md pr-0.5 pl-1 ${selected ? '' : 'bg-hover'}`}>
                            {onDuplicate && (
                                <button
                                    type="button"
                                    aria-label={`Duplicate ${name}`}
                                    title="Duplicate (Ctrl+D)"
                                    disabled={!canEdit}
                                    onClick={() => onDuplicate(id)}
                                    data-testid="layer-duplicate"
                                    className="grid size-6 place-items-center rounded text-muted hover:bg-hover hover:text-fg disabled:opacity-25"
                                >
                                    <Icon name="copy" className="size-3" />
                                </button>
                            )}
                            <button
                                type="button"
                                aria-label={`Remove ${name}`}
                                title={`${removalOf(doc, id).label} (Delete key)`}
                                disabled={!canEdit || !canRemove(doc, id)}
                                onClick={() =>
                                    onDelete
                                        ? onDelete(id)
                                        : onStructure(removeOps(doc, id), location?.parentId === doc.root ? null : (location?.parentId ?? null))
                                }
                                className="grid size-6 place-items-center rounded text-muted hover:bg-danger-soft hover:text-danger disabled:opacity-25"
                            >
                                <Icon name="trash" className="size-3" />
                            </button>
                        </span>
                    </span>
                </div>
                {node.children && node.children.length > 0 && <ul role="group">{node.children.map((child) => renderNode(child, depth + 1))}</ul>}
            </li>
        );
    };

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {!adding && (
                <div className="flex h-10 shrink-0 items-center gap-1.5 border-b border-line px-2">
                    <Button icon="plus" size="sm" disabled={!canEdit} onClick={() => setAdding(true)} data-testid="open-block-picker">
                        Add
                    </Button>
                    <span className="flex-1" />
                </div>
            )}
            {adding && (
                <BlockPicker
                    document={doc}
                    selectedId={selectedId}
                    canEdit={canEdit}
                    components={components}
                    onStructure={onStructure}
                    onClose={() => setAdding(false)}
                />
            )}
            <section
                aria-label="Layers"
                ref={treeRef}
                className="ak-scroll relative min-h-0 flex-1 overflow-y-auto px-2 py-3"
                data-selection-scope
                title="Drag a row by its grip to reorder it or move it into a container; the arrow buttons do the same from the keyboard. Esc cancels a drag; Ctrl+Z undoes a move."
            >
                {(root.children ?? []).length === 0 ? (
                    <div className="px-2 py-6 text-center">
                        <p className="text-ui text-muted">This page is empty.</p>
                        <Button icon="plus" size="sm" className="mt-2" disabled={!canEdit} onClick={() => setAdding(true)}>
                            Add a block
                        </Button>
                    </div>
                ) : (
                    <ul role="tree" aria-label="Page structure" className="space-y-px">
                        {root.children!.map((id) => renderNode(id, 0))}
                    </ul>
                )}
                <div
                    ref={indicatorRef}
                    aria-hidden
                    data-testid="layers-drop-indicator"
                    className="pointer-events-none absolute top-0 left-0 hidden rounded-full bg-accent data-[kind=inside]:rounded-md data-[kind=inside]:bg-accent-soft/60 data-[kind=inside]:outline-2 data-[kind=inside]:outline-accent data-[result=invalid]:bg-danger-soft data-[result=invalid]:outline-dashed data-[result=invalid]:outline-danger"
                />
            </section>
        </div>
    );
}
