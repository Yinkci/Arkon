import { useState, type DragEvent } from 'react';
import { currentDefinition } from '@/arkon/components/registry';
import {
    ADDABLE_TYPES,
    canRemove,
    createNodes,
    dropOps,
    dropPlacement,
    insertOps,
    insertionFor,
    moveWithinParent,
    nodeLabel,
    type DropPosition,
} from '@/arkon/editor/structure';
import { findParent, type NodeId, type PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';

const NODE_MIME = 'application/x-arkon-node';
const NEW_MIME = 'application/x-arkon-new';

export interface LayersPanelProps {
    document: PageDocument;
    selectedId: NodeId | null;
    canEdit: boolean;
    onSelect(nodeId: NodeId | null): void;
    /** Applies structural operations; `select` is the node to select afterwards. */
    onStructure(ops: PageOperation[], select?: NodeId | null): void;
}

interface Dragging {
    nodeId?: NodeId;
    type: string;
}

/**
 * The page outline. Every component can be selected, moved up or down, removed and
 * dragged; containers accept drops inside. Buttons are the keyboard (and screen
 * reader) path; drag and drop is the pointer shortcut. Only drops the nesting rules
 * allow are offered, and the document validation checks every change again.
 */
export function LayersPanel({ document: doc, selectedId, canEdit, onSelect, onStructure }: LayersPanelProps) {
    const [dragging, setDragging] = useState<Dragging | null>(null);
    const [indicator, setIndicator] = useState<{ targetId: NodeId; position: DropPosition } | null>(null);
    const root = doc.nodes[doc.root]!;

    const add = (type: string) => {
        const placement = insertionFor(doc, selectedId, type);
        if (!placement) return;
        const nodes = createNodes(type);
        onStructure(insertOps(placement, nodes), nodes[0]!.id);
    };

    const positionFor = (event: DragEvent, targetId: NodeId): { position: DropPosition; ok: boolean } => {
        const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
        const y = (event.clientY - rect.top) / Math.max(rect.height, 1);
        const target = doc.nodes[targetId]!;
        const preferred: DropPosition[] = target.children
            ? y < 0.25
                ? ['before', 'inside']
                : y > 0.75
                  ? ['after', 'inside']
                  : ['inside', y < 0.5 ? 'before' : 'after']
            : [y < 0.5 ? 'before' : 'after'];
        for (const position of preferred) {
            if (dragging && dropPlacement(doc, dragging, targetId, position)) return { position, ok: true };
        }
        return { position: preferred[0]!, ok: false };
    };

    const onDragStart = (event: DragEvent, value: Dragging) => {
        event.dataTransfer.effectAllowed = value.nodeId ? 'move' : 'copy';
        event.dataTransfer.setData(value.nodeId ? NODE_MIME : NEW_MIME, value.nodeId ?? value.type);
        event.dataTransfer.setData('text/plain', value.nodeId ?? value.type);
        setDragging(value);
    };
    const onDragEnd = () => {
        setDragging(null);
        setIndicator(null);
    };

    const rowHandlers = (targetId: NodeId) => ({
        onDragOver: (event: DragEvent) => {
            if (!dragging) return;
            const { position, ok } = positionFor(event, targetId);
            if (!ok) {
                setIndicator(null);
                return; // not preventing default: the browser shows "no drop" here
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = dragging.nodeId ? 'move' : 'copy';
            if (indicator?.targetId !== targetId || indicator.position !== position) setIndicator({ targetId, position });
        },
        onDragLeave: () => setIndicator((current) => (current?.targetId === targetId ? null : current)),
        onDrop: (event: DragEvent) => {
            event.preventDefault();
            const value = dragging;
            onDragEnd();
            if (!value) return;
            const { position, ok } = positionFor(event, targetId);
            const placement = ok ? dropPlacement(doc, value, targetId, position) : null;
            if (!placement) return;
            const ops = dropOps(value, placement);
            const selectId = value.nodeId ?? (ops[0]?.op === 'insertNode' ? ops[0].nodes[0]!.id : null);
            onStructure(ops, selectId);
        },
    });

    const renderNode = (id: NodeId, depth: number): React.ReactNode => {
        const node = doc.nodes[id];
        if (!node) return null;
        const label = nodeLabel(node);
        const location = findParent(doc, id);
        const siblings = location ? doc.nodes[location.parentId]!.children! : [];
        const name = currentDefinition(node.type)?.label ?? node.type;
        const selected = id === selectedId;
        const marker = indicator?.targetId === id ? indicator.position : null;
        return (
            <li key={id} role="treeitem" aria-selected={selected} aria-expanded={node.children ? true : undefined} aria-level={depth + 1}>
                <div
                    data-testid="layer"
                    data-node-id={id}
                    data-node-type={node.type}
                    data-parent-id={location?.parentId}
                    draggable={canEdit}
                    onDragStart={(event) => onDragStart(event, { nodeId: id, type: node.type })}
                    onDragEnd={onDragEnd}
                    {...rowHandlers(id)}
                    className={`group flex items-center gap-1 border-y-2 py-0.5 pr-1 text-sm ${selected ? 'bg-indigo-50' : 'hover:bg-zinc-50'} ${
                        marker === 'before'
                            ? 'border-t-indigo-500 border-b-transparent'
                            : marker === 'after'
                              ? 'border-b-indigo-500 border-t-transparent'
                              : 'border-transparent'
                    } ${marker === 'inside' ? 'outline outline-2 outline-indigo-400' : ''}`}
                    style={{ paddingLeft: `${0.5 + depth * 1}rem` }}
                >
                    <button
                        type="button"
                        onClick={() => onSelect(id)}
                        className="min-w-0 flex-1 truncate text-left"
                        aria-current={selected ? 'true' : undefined}
                    >
                        {label}
                    </button>
                    <button
                        type="button"
                        aria-label={`Move ${name} up`}
                        title="Move up"
                        disabled={!canEdit || (location?.index ?? 0) === 0}
                        onClick={() => onStructure(moveWithinParent(doc, id, -1) ?? [], id)}
                        className="rounded px-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30"
                    >
                        ↑
                    </button>
                    <button
                        type="button"
                        aria-label={`Move ${name} down`}
                        title="Move down"
                        disabled={!canEdit || !location || location.index >= siblings.length - 1}
                        onClick={() => onStructure(moveWithinParent(doc, id, 1) ?? [], id)}
                        className="rounded px-1 text-zinc-500 hover:bg-zinc-200 disabled:opacity-30"
                    >
                        ↓
                    </button>
                    <button
                        type="button"
                        aria-label={`Remove ${name}`}
                        title="Remove"
                        disabled={!canEdit || !canRemove(doc, id)}
                        onClick={() => onStructure([{ op: 'removeNode', nodeId: id }], location?.parentId === doc.root ? null : (location?.parentId ?? null))}
                        className="rounded px-1 text-red-600 hover:bg-red-50 disabled:opacity-30"
                    >
                        ✕
                    </button>
                </div>
                {node.children && node.children.length > 0 && <ul role="group">{node.children.map((child) => renderNode(child, depth + 1))}</ul>}
            </li>
        );
    };

    return (
        <div className="space-y-3 p-3">
            <section aria-label="Add component">
                <h2 className="mb-1 text-xs font-medium text-zinc-600">Add (after the selection, or drag into place)</h2>
                <div className="flex flex-wrap gap-1.5">
                    {ADDABLE_TYPES.map((type) => {
                        const placement = insertionFor(doc, selectedId, type);
                        const name = currentDefinition(type)?.label ?? type;
                        return (
                            <button
                                key={type}
                                type="button"
                                draggable={canEdit && placement !== null}
                                onDragStart={(event) => onDragStart(event, { type })}
                                onDragEnd={onDragEnd}
                                disabled={!canEdit || placement === null}
                                onClick={() => add(type)}
                                aria-label={`Add ${name}`}
                                data-testid={`add-${type}`}
                                className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs hover:bg-zinc-50 disabled:opacity-40"
                            >
                                + {name}
                            </button>
                        );
                    })}
                </div>
            </section>
            <section aria-label="Layers">
                <h2 className="mb-1 text-xs font-medium text-zinc-600">Layers</h2>
                {(root.children ?? []).length === 0 ? (
                    <p className="text-xs text-zinc-500">The page is empty. Add a component above.</p>
                ) : (
                    <ul role="tree" aria-label="Page structure" className="rounded-md border border-zinc-200 py-1">
                        {root.children!.map((id) => renderNode(id, 0))}
                    </ul>
                )}
                <p className="mt-2 text-xs text-zinc-500">Drag a layer to reorder it or move it into a column. Undo with Ctrl+Z.</p>
            </section>
        </div>
    );
}
