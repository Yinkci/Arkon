import { useEffect, useId, useRef, useState } from 'react';
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
    const docRef = useRef(doc);
    docRef.current = doc;

    const add = (type: string, props: Record<string, unknown> = {}) => {
        const placement = insertionFor(doc, selectedId, type);
        if (!placement) return;
        const nodes = createNodes(type, props);
        onStructure(insertOps(placement, nodes), nodes[0]!.id);
    };

    // ── Drop zone: the outline ──
    useEffect(() => {
        const rows = (): TreeRow[] =>
            [...(treeRef.current?.querySelectorAll<HTMLElement>('[data-testid="layer"]') ?? [])].map((row) => ({
                id: row.dataset.nodeId!,
                depth: Number(row.dataset.depth),
                rect: row.getBoundingClientRect(),
            }));
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
                    return;
                }
                const r = result.indicator.rect;
                el.style.display = 'block';
                el.dataset.kind = result.indicator.kind;
                el.dataset.result = result.kind;
                el.style.transform = `translate(${Math.round(r.left - tree.left)}px, ${Math.round(r.top - tree.top)}px)`;
                el.style.width = `${Math.round(r.width)}px`;
                el.style.height = `${Math.round(r.height)}px`;
            },
            end() {
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
                    className={`group relative flex h-8 items-center gap-1 rounded-md pr-1 text-[0.8125rem] select-none ${selected ? 'bg-accent-soft text-fg' : 'hover:bg-raised'} ${
                        dragging?.nodeId === id ? 'opacity-40' : ''
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
                            className="grid h-7 w-5 shrink-0 cursor-grab touch-none place-items-center rounded text-faint group-hover:text-muted hover:bg-sunken"
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
                        className="min-w-0 flex-1 cursor-[inherit] truncate py-1 text-left"
                        aria-current={selected ? 'true' : undefined}
                        title={`${componentName(node)}: ${label}`}
                    >
                        <span className={selected ? 'font-medium' : ''}>{label}</span>
                    </button>
                    <span data-row-action className="flex items-center opacity-60 group-hover:opacity-100 focus-within:opacity-100">
                        <button
                            type="button"
                            aria-label={`Move ${name} up`}
                            title="Move up"
                            disabled={!canEdit || (location?.index ?? 0) === 0}
                            onClick={() => onStructure(moveWithinParent(doc, id, -1) ?? [], id)}
                            className="grid size-6 place-items-center rounded text-muted hover:bg-sunken hover:text-fg disabled:opacity-25"
                        >
                            <Icon name="arrowUp" className="size-3" />
                        </button>
                        <button
                            type="button"
                            aria-label={`Move ${name} down`}
                            title="Move down"
                            disabled={!canEdit || !location || location.index >= siblings.length - 1}
                            onClick={() => onStructure(moveWithinParent(doc, id, 1) ?? [], id)}
                            className="grid size-6 place-items-center rounded text-muted hover:bg-sunken hover:text-fg disabled:opacity-25"
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
                                className="grid size-6 place-items-center rounded text-muted hover:bg-sunken hover:text-fg disabled:opacity-25"
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
                                onDelete ? onDelete(id) : onStructure(removeOps(doc, id), location?.parentId === doc.root ? null : (location?.parentId ?? null))
                            }
                            className="grid size-6 place-items-center rounded text-muted hover:bg-danger-soft hover:text-danger disabled:opacity-25"
                        >
                            <Icon name="trash" className="size-3" />
                        </button>
                    </span>
                </div>
                {node.children && node.children.length > 0 && <ul role="group">{node.children.map((child) => renderNode(child, depth + 1))}</ul>}
            </li>
        );
    };

    return (
        <div>
            <section aria-label="Add component" className="border-b border-line px-4 py-3.5">
                <h2 className="text-xs font-semibold">Add a block</h2>
                <p className="mt-0.5 mb-2.5 text-[11px] text-muted">Click to add after the selection, or drag onto the canvas or into the outline.</p>
                <div className="grid grid-cols-3 gap-1.5">
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
                                className="flex h-14 cursor-grab flex-col items-center justify-center gap-1 rounded-md border border-line bg-surface text-[11px] font-medium text-fg select-none hover:border-accent-line hover:bg-accent-soft disabled:pointer-events-none disabled:opacity-40"
                            >
                                <Icon name={TYPE_ICON[type] ?? 'component'} className="size-4 text-muted" />
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
                <p className="mt-1.5 text-[11px] text-muted">
                    Shared components and site colours live on the{' '}
                    <a href="/admin/design" className="font-medium text-accent hover:underline">
                        Design
                    </a>{' '}
                    page.
                </p>
            </section>
            <section aria-label="Layers" ref={treeRef} className="relative px-2 py-3" data-selection-scope>
                <h2 className="mb-1.5 px-2 text-xs font-semibold">Outline</h2>
                {(root.children ?? []).length === 0 ? (
                    <p className="px-2 text-[0.8125rem] text-muted">The page is empty. Add a block above.</p>
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
                <p className="mt-3 px-2 text-[11px] leading-snug text-muted">
                    Drag a row by its grip (or a block on the canvas by its Move handle) to reorder it or move it into a container; move the pointer left or
                    right at the end of a group to choose the level. The arrow buttons do the same from the keyboard. Esc cancels a drag; Ctrl+Z undoes a move.
                </p>
            </section>
        </div>
    );
}
