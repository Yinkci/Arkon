// Deleting the selection: one decision for every entry point (canvas controls, the toolbar,
// Layers, Delete/Backspace). Pure: what would be removed, what it is called, whether to ask
// first, what to select afterwards, and the operations (the normal removeNode, with a column's
// width removed from its Columns block; a hero's image cleared when only the image is selected).
import { findParent, type Node, type NodeId, type PageDocument } from '../schema/document';
import type { PageOperation } from '../schema/operations';
import { removeOps } from './columns';
import { canRemove } from './structure';

/** What a deletion is called: "button", "section", "hero section" … */
const NOUN: Record<string, string> = {
    button: 'button',
    text: 'text',
    image: 'image block',
    hero: 'hero section',
    section: 'section',
    group: 'group',
    columns: 'columns',
    column: 'column',
    instance: 'reusable component',
};

export type Removal =
    | {
          ok: true;
          ops: PageOperation[];
          /** "Delete button", "Delete section and its contents", "Remove image". */
          label: string;
          /** Ask first: a container holding content (its contents go too). */
          confirm: boolean;
          /** Every node that goes (empty when only a prop is cleared). */
          removed: NodeId[];
          /** What to select afterwards: the next block, else the previous one, else the parent. */
          select: NodeId | null;
          /** For the confirmation: how many blocks are inside. */
          contents: number;
      }
    | { ok: false; reason: string; label: string };

function subtree(doc: PageDocument, id: NodeId): NodeId[] {
    return [id, ...(doc.nodes[id]?.children ?? []).flatMap((child) => subtree(doc, child))];
}

/** Blocks inside a block that hold content (empty Column slots of a Columns block don't count). */
function contentsOf(doc: PageDocument, node: Node): number {
    return subtree(doc, node.id)
        .slice(1)
        .filter((id) => doc.nodes[id]?.type !== 'column' || (doc.nodes[id]?.children?.length ?? 0) > 0).length;
}

export function removalOf(doc: PageDocument, nodeId: NodeId, part: string | null = null): Removal {
    const node = doc.nodes[nodeId];
    const noun = node ? (NOUN[node.type] ?? node.type) : 'block';
    if (!node) return { ok: false, reason: 'That block no longer exists', label: 'Delete' };
    // Only the hero's image is selected: remove that, never the whole hero behind the user's back.
    if (node.type === 'hero' && part === 'media' && node.props.image) {
        return {
            ok: true,
            ops: [{ op: 'updateProps', nodeId, set: { image: null } }],
            label: 'Remove image',
            confirm: false,
            removed: [],
            select: nodeId,
            contents: 0,
        };
    }
    if (nodeId === doc.root) return { ok: false, reason: 'The page itself can’t be deleted', label: 'Delete' };
    const contents = contentsOf(doc, node);
    const label = `Delete ${noun}${contents > 0 ? ' and its contents' : ''}`;
    if (!canRemove(doc, nodeId)) {
        const location = findParent(doc, nodeId);
        const parent = location ? doc.nodes[location.parentId] : undefined;
        return {
            ok: false,
            label,
            reason:
                node.type === 'column' && parent?.type === 'columns'
                    ? 'A Columns block needs at least one column. Delete the whole Columns block instead.'
                    : 'This block can’t be removed here',
        };
    }
    const location = findParent(doc, nodeId)!;
    const siblings = doc.nodes[location.parentId]!.children ?? [];
    const select = siblings[location.index + 1] ?? siblings[location.index - 1] ?? (location.parentId === doc.root ? null : location.parentId);
    return { ok: true, ops: removeOps(doc, nodeId), label, confirm: contents > 0, removed: subtree(doc, nodeId), select, contents };
}
