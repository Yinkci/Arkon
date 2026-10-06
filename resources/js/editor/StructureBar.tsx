import { currentDefinition } from '@/arkon/components/registry';
import { canRemove, moveWithinParent } from '@/arkon/editor/structure';
import { findParent, type Node, type NodeId, type PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';

const button = 'rounded-md border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-50 disabled:opacity-40';

/** Structural controls for the selected component, next to its properties. */
export function StructureBar({
    document: doc,
    node,
    canEdit,
    onSelect,
    onStructure,
}: {
    document: PageDocument;
    node: Node;
    canEdit: boolean;
    onSelect(nodeId: NodeId | null): void;
    onStructure(ops: PageOperation[], select?: NodeId | null): void;
}) {
    const location = findParent(doc, node.id);
    const siblings = location ? (doc.nodes[location.parentId]!.children ?? []) : [];
    const parentId = location && location.parentId !== doc.root ? location.parentId : null;
    const name = currentDefinition(node.type)?.label ?? node.type;
    return (
        <div className="flex flex-wrap items-center gap-1.5 border-b border-zinc-100 px-4 py-2" role="toolbar" aria-label={`${name} structure`}>
            <button type="button" className={button} disabled={!parentId} onClick={() => onSelect(parentId)}>
                Select parent
            </button>
            <button
                type="button"
                className={button}
                aria-label={`Move ${name} up`}
                disabled={!canEdit || !location || location.index === 0}
                onClick={() => onStructure(moveWithinParent(doc, node.id, -1) ?? [], node.id)}
            >
                Move up
            </button>
            <button
                type="button"
                className={button}
                aria-label={`Move ${name} down`}
                disabled={!canEdit || !location || location.index >= siblings.length - 1}
                onClick={() => onStructure(moveWithinParent(doc, node.id, 1) ?? [], node.id)}
            >
                Move down
            </button>
            <button
                type="button"
                className={`${button} text-red-700`}
                aria-label={`Remove ${name}`}
                disabled={!canEdit || !canRemove(doc, node.id)}
                onClick={() => onStructure([{ op: 'removeNode', nodeId: node.id }], parentId)}
            >
                Remove
            </button>
        </div>
    );
}
