// Duplicating a block: a deep copy right after it in the same parent, as one undoable edit made
// of the normal operations (insertNode, plus the Columns widths for a column). Pure: the editor
// checks unresolved fields and permissions, then dispatches what this returns.
import { rules } from '../rules';
import { findParent, type NodeId, type PageDocument } from '../schema/document';
import type { PageOperation } from '../schema/operations';
import { duplicateColumn } from './columns';
import { componentName } from './parts';
import { placeAt } from './placement';
import { copySubtree } from './structure';

export type Duplication = { ok: true; ops: PageOperation[]; copyId: NodeId; notice: string | null } | { ok: false; reason: string };

/**
 * Copies `nodeId` and everything inside it (props, design settings for every screen,
 * animations; image references and a reusable component's link are kept as they are) with
 * fresh ids, and inserts the copy right after the original. Refused, with the reason, when the
 * copy can't go there: the page itself, a full parent (or Columns at 6 columns), the page's
 * node limit, the size of one insert, or an unresolved field inside the block.
 */
export function duplicateBlock(doc: PageDocument, nodeId: NodeId, unresolved: { nodeId: string; label: string }[] = []): Duplication {
    const node = doc.nodes[nodeId];
    if (!node) return { ok: false, reason: 'That block no longer exists' };
    if (nodeId === doc.root) return { ok: false, reason: 'The page itself can’t be duplicated' };
    const location = findParent(doc, nodeId);
    if (!location) return { ok: false, reason: 'That block is not on the page' };
    const copy = copySubtree(doc, nodeId);
    const inside = new Set(copySubtreeIds(doc, nodeId));
    const pending = unresolved.find((field) => inside.has(field.nodeId));
    if (pending) {
        const owner = doc.nodes[pending.nodeId];
        return {
            ok: false,
            reason: `Fix or revert the ${pending.label.toLowerCase()} in ${owner ? componentName(owner) : 'this block'} first: duplicating would copy the last saved value, not what you typed.`,
        };
    }
    if (copy.length > rules.limits.insertNodes) {
        return { ok: false, reason: `${componentName(node)} holds ${copy.length} blocks; at most ${rules.limits.insertNodes} can be duplicated at once` };
    }
    const total = Object.keys(doc.nodes).length;
    if (total + copy.length > rules.maxNodes) {
        return { ok: false, reason: `The page would have ${total + copy.length} blocks; ${rules.maxNodes} is the most a page can hold` };
    }
    if (node.type === 'column') {
        const change = duplicateColumn(doc, nodeId, copy);
        return change.ok ? { ok: true, ops: change.ops, copyId: copy[0]!.id, notice: change.notice } : change;
    }
    const check = placeAt(doc, { type: node.type }, location.parentId, location.index + 1);
    if (!check.ok) return { ok: false, reason: check.reason };
    return { ok: true, ops: [{ op: 'insertNode', parentId: location.parentId, index: location.index + 1, nodes: copy }], copyId: copy[0]!.id, notice: null };
}

function copySubtreeIds(doc: PageDocument, rootId: NodeId): NodeId[] {
    const out: NodeId[] = [];
    const visit = (id: NodeId) => {
        out.push(id);
        doc.nodes[id]?.children?.forEach(visit);
    };
    visit(rootId);
    return out;
}
