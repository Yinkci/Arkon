import { useState } from 'react';
import { currentDefinition } from '@/arkon/components/registry';
import { componentName } from '@/arkon/editor/parts';
import { removeOps } from '@/arkon/editor/columns';
import { removalOf } from '@/arkon/editor/remove';
import { canRemove, moveWithinParent } from '@/arkon/editor/structure';
import { findParent, type Node, type NodeId, type PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import { Button, IconButton } from '@/Components/ui';

/** Top-level kinds of blocks a reusable component can hold (not columns of a Columns block, not instances). */
function canBeReusable(doc: PageDocument, node: Node): boolean {
    const fragment = currentDefinition('fragment');
    return node.id !== doc.root && fragment !== undefined && fragment.children !== false && fragment.children.allow.includes(node.type);
}

/** Structural actions for the selected component, under its name in the inspector (the keyboard path for moving). */
export function StructureBar({
    document: doc,
    node,
    canEdit,
    onSelect,
    onStructure,
    onMakeReusable,
    onDuplicate,
    onDelete,
}: {
    document: PageDocument;
    node: Node;
    canEdit: boolean;
    onSelect(nodeId: NodeId | null): void;
    onStructure(ops: PageOperation[], select?: NodeId | null): void;
    /** Turns the block into a reusable component (absent when not allowed). */
    onMakeReusable?(nodeId: NodeId, name: string): void;
    /** Duplicates the block right after itself (one undo step). */
    onDuplicate?(nodeId: NodeId): void;
    /** Deletes the block (the editor's shared deletion: asks first for containers with content). */
    onDelete?(nodeId: NodeId): void;
}) {
    const [naming, setNaming] = useState<string | null>(null);
    const reusable = onMakeReusable && canEdit && canBeReusable(doc, node);
    const location = findParent(doc, node.id);
    const siblings = location ? (doc.nodes[location.parentId]!.children ?? []) : [];
    const parentId = location && location.parentId !== doc.root ? location.parentId : null;
    const name = currentDefinition(node.type)?.label ?? node.type;
    const removal = removalOf(doc, node.id);
    return (
        <div className="border-b border-line bg-surface px-3 py-1.5" data-selection-scope>
            <div className="flex flex-wrap items-center gap-0.5" role="toolbar" aria-label={`${name} structure`}>
                <IconButton
                    icon="arrowUp"
                    size="sm"
                    label={`Move ${name} up`}
                    disabled={!canEdit || !location || location.index === 0}
                    onClick={() => onStructure(moveWithinParent(doc, node.id, -1) ?? [], node.id)}
                />
                <IconButton
                    icon="arrowDown"
                    size="sm"
                    label={`Move ${name} down`}
                    disabled={!canEdit || !location || location.index >= siblings.length - 1}
                    onClick={() => onStructure(moveWithinParent(doc, node.id, 1) ?? [], node.id)}
                />
                <Button
                    size="sm"
                    variant="ghost"
                    icon="chevronUp"
                    disabled={!parentId}
                    onClick={() => onSelect(parentId)}
                    title={parentId ? `Select the ${componentName(doc.nodes[parentId]!).toLowerCase()} around it` : 'Already at the top level'}
                >
                    Select parent
                </Button>
                <span className="flex-1" />
                {onDuplicate && (
                    <Button
                        size="sm"
                        variant="ghost"
                        icon="copy"
                        disabled={!canEdit}
                        onClick={() => onDuplicate(node.id)}
                        title={`Duplicate ${name} right after itself (Ctrl+D)`}
                        data-testid="duplicate-block"
                    >
                        Duplicate
                    </Button>
                )}
                {reusable && naming === null && (
                    <Button size="sm" variant="ghost" icon="component" onClick={() => setNaming(name)} data-testid="make-reusable">
                        Make reusable
                    </Button>
                )}
                <IconButton
                    icon="trash"
                    size="sm"
                    variant="quiet-danger"
                    label={removal.ok ? removal.label : `Remove ${name}`}
                    disabled={!canEdit || !removal.ok || !canRemove(doc, node.id)}
                    onClick={() => (onDelete ? onDelete(node.id) : onStructure(removeOps(doc, node.id), parentId))}
                    data-testid="toolbar-delete"
                />
            </div>
            {reusable && naming !== null && (
                <form
                    className="mt-1.5 flex items-center gap-1.5 pb-1"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (naming.trim() === '') return;
                        onMakeReusable!(node.id, naming.trim());
                        setNaming(null);
                    }}
                >
                    <input
                        autoFocus
                        aria-label="Name of the reusable component"
                        className="ui-input h-7"
                        value={naming}
                        maxLength={80}
                        onChange={(e) => setNaming(e.target.value)}
                    />
                    <Button type="submit" size="sm" variant="primary">
                        Create
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setNaming(null)}>
                        Cancel
                    </Button>
                </form>
            )}
        </div>
    );
}
