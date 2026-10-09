import { patterns, createPattern } from '@/arkon/editor/patterns';
import { useEffect, useLayoutEffect, useId, useRef, useState } from 'react';
import type { ReusableComponentInfo } from '@/types';
import { currentDefinition } from '@/arkon/components/registry';
import { addableTypes, canRemove, createNodes, insertOps, insertionFor, moveWithinParent, nodeLabel } from '@/arkon/editor/structure';
import { resolveTreeSlot, scrollStep, type TreeRow } from '@/arkon/editor/placement';
import { createColumns, removeOps } from '@/arkon/editor/columns';
import { removalOf } from '@/arkon/editor/remove';
import { findParent, type NodeId, type PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import { componentName } from '@/arkon/editor/parts';
import { Icon, type IconName } from '@/Components/Icon';
import { Button } from '@/Components/ui';
import { ColumnsPicker } from './ColumnsPicker';
import type { DragSource, DropZone } from './drag/controller';
import { placementAnimation } from './drag/visuals';
import { useDragController, useDragState } from './drag/DragProvider';

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
    const [pickingColumns, setPickingColumns] = useState(false);
    const published = components.filter((c) => c.published !== null);
    const [componentId, setComponentId] = useState<string>('');
    const pickId = useId();
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

    const add = (type: string, props: Record<string, unknown> = {}) => {
        const placement = insertionFor(doc, selectedId, type);
        if (!placement) return;
        const nodes = createNodes(type, props);
        onStructure(insertOps(placement, nodes), nodes[0]!.id);
    };

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
        const siblings = location ? doc.nodes[location.parentId]!.children! : [];
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
                        className={`min-w-0 flex-1 cursor-[inherit] truncate py-1 text-left ${selected ? 'mr-[6.5rem]' : 'group-focus-within:mr-[6.5rem] group-hover:mr-[6.5rem] [@media(hover:none)]:mr-[6.5rem]'}`}
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
                            <button
                                type="button"
                                aria-label={`Move ${name} up`}
                                title="Move up"
                                disabled={!canEdit || (location?.index ?? 0) === 0}
                                onClick={() => onStructure(moveWithinParent(doc, id, -1) ?? [], id)}
                                className="grid size-6 place-items-center rounded text-muted hover:bg-hover hover:text-fg disabled:opacity-25"
                            >
                                <Icon name="arrowUp" className="size-3" />
                            </button>
                            <button
                                type="button"
                                aria-label={`Move ${name} down`}
                                title="Move down"
                                disabled={!canEdit || !location || location.index >= siblings.length - 1}
                                onClick={() => onStructure(moveWithinParent(doc, id, 1) ?? [], id)}
                                className="grid size-6 place-items-center rounded text-muted hover:bg-hover hover:text-fg disabled:opacity-25"
                            >
                                <Icon name="arrowDown" className="size-3" />
                            </button>
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
        <div>
            <details aria-label="Layout patterns" className="group border-b border-line">
                <summary className="flex h-10 cursor-pointer list-none items-center gap-2 px-4 text-xs font-semibold select-none hover:bg-hover [&::-webkit-details-marker]:hidden">
                    <Icon name="chevronRight" className="size-3.5 text-faint transition-transform group-open:rotate-90" />
                    Starting layouts
                </summary>
                <p className="px-4 pb-2 text-2xs text-muted">Editable blocks. Choose images, menus and forms after inserting.</p>
                <div className="space-y-px px-2 pb-3">
                    {patterns.map((pattern) => {
                        const placement = insertionFor(doc, selectedId, pattern.template.type);
                        return (
                            <button
                                type="button"
                                className="flex h-8 w-full items-center gap-2 rounded-md px-2 text-left text-xs text-fg hover:bg-hover disabled:opacity-40"
                                key={pattern.id}
                                title={pattern.description}
                                disabled={!canEdit || !placement}
                                onClick={() => {
                                    if (!placement) return;
                                    const nodes = createPattern(pattern.id, doc);
                                    onStructure(insertOps(placement, nodes), nodes[0]!.id);
                                }}
                            >
                                <Icon name="layers" className="size-3.5 text-faint" />
                                {pattern.name}
                            </button>
                        );
                    })}
                </div>
            </details>
            <section aria-label="Add component" className="border-b border-line p-4">
                <h2 className="text-xs font-semibold">Add a block</h2>
                <p className="mt-0.5 mb-2.5 text-2xs text-muted">Click to add after the selection, or drag onto the canvas or into the outline.</p>
                <div className="grid grid-cols-3 gap-1.5" aria-label="Block palette">
                    {addableTypes().map((type) => {
                        const placement = insertionFor(doc, selectedId, type);
                        const name = currentDefinition(type)?.label ?? type;
                        return (
                            <button
                                key={type}
                                type="button"
                                disabled={!canEdit || placement === null}
                                onPointerDown={(event) => {
                                    if (!canEdit || event.pointerType === 'touch') return; // touch: tap to add (the list keeps scrolling)
                                    controller.press({ type, label: name, origin: 'palette' }, event, event.currentTarget);
                                }}
                                onClick={() => {
                                    if (controller.consumeClick()) return;
                                    // Columns: choose the layout first, so the columns themselves are created with it.
                                    if (type === 'columns') setPickingColumns((open) => !open);
                                    else add(type);
                                }}
                                aria-expanded={type === 'columns' ? pickingColumns : undefined}
                                aria-label={`Add ${name}`}
                                data-testid={`add-${type}`}
                                className="group/tile flex h-14 cursor-grab flex-col items-center justify-center gap-1 rounded-md border border-line bg-raised px-1 text-center text-2xs leading-tight font-medium text-fg transition-[background-color,border-color,box-shadow] duration-100 select-none hover:border-line-strong hover:bg-surface hover:shadow-raise disabled:pointer-events-none disabled:opacity-40"
                            >
                                <Icon name={TYPE_ICON[type] ?? 'component'} className="size-4 text-muted group-hover/tile:text-accent" />
                                {name}
                            </button>
                        );
                    })}
                </div>
                {pickingColumns && (
                    <ColumnsPicker
                        disabled={!canEdit || insertionFor(doc, selectedId, 'columns') === null}
                        onCancel={() => setPickingColumns(false)}
                        onPick={(preset) => {
                            const placement = insertionFor(doc, selectedId, 'columns');
                            if (!placement) return;
                            const nodes = createColumns(preset.count, preset.tracks);
                            setPickingColumns(false);
                            onStructure(insertOps(placement, nodes), nodes[0]!.id);
                        }}
                    />
                )}
                <div className="mt-3 flex items-end gap-1.5">
                    <label htmlFor={pickId} className="min-w-0 flex-1">
                        <span className="ui-label">Reusable component</span>
                        <select
                            id={pickId}
                            className="ui-input"
                            value={componentId}
                            onChange={(e) => setComponentId(e.target.value)}
                            disabled={!canEdit || published.length === 0}
                            data-testid="pick-component"
                        >
                            <option value="">{published.length === 0 ? 'None published yet' : 'Choose…'}</option>
                            {published.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        icon="plus"
                        disabled={!canEdit || componentId === '' || insertionFor(doc, selectedId, 'instance') === null}
                        onClick={() => add('instance', { componentId })}
                        data-testid="add-instance"
                    >
                        Add
                    </Button>
                </div>
                <p className="mt-1.5 text-2xs text-muted">
                    Shared components and site colours live on the{' '}
                    <a href="/admin/design/components" className="font-medium text-accent hover:underline">
                        Design
                    </a>{' '}
                    page.
                </p>
            </section>
            <section aria-label="Layers" ref={treeRef} className="relative px-2 py-4" data-selection-scope>
                <h2 className="mb-1.5 px-2 text-xs font-semibold">Outline</h2>
                {(root.children ?? []).length === 0 ? (
                    <p className="px-2 text-ui text-muted">The page is empty. Add a block above.</p>
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
                <p className="mt-3 px-2 text-2xs leading-snug text-muted">
                    Drag a row by its grip (or a block on the canvas by its Move handle) to reorder it or move it into a container; move the pointer left or
                    right at the end of a group to choose the level. The arrow buttons do the same from the keyboard. Esc cancels a drag; Ctrl+Z undoes a move.
                </p>
            </section>
        </div>
    );
}
