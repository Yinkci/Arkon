// Twin of app/Arkon/Schema/Operations.php (application and inverses). The server
// additionally parses operation shapes; the editor only creates them in code.
import { collectSubtree, findParent, validateStructure, type Node, type NodeId, type PageDocument, type PageSeo } from './document';

export type PageOperation =
    | { op: 'insertNode'; parentId: NodeId; index: number; nodes: Node[] }
    | { op: 'removeNode'; nodeId: NodeId }
    | { op: 'moveNode'; nodeId: NodeId; parentId: NodeId; index: number }
    | { op: 'updateProps'; nodeId: NodeId; set: Record<string, unknown>; unset?: string[] }
    | { op: 'updateSeo'; set: PageSeo; unset?: (keyof PageSeo)[] };

export class OperationError extends Error {
    constructor(
        message: string,
        readonly operationIndex: number,
    ) {
        super(message);
        this.name = 'OperationError';
    }
}

export interface ApplyResult {
    doc: PageDocument;
    /** Operations that undo `ops`, already in the order they must be applied. */
    inverse: PageOperation[];
}

/**
 * Applies operations to a copy of `doc`. The input is never mutated. Throws
 * OperationError when an operation targets missing nodes or breaks the tree.
 */
export function applyOperations(doc: PageDocument, ops: readonly PageOperation[]): ApplyResult {
    const next = structuredClone(doc);
    const inverses: PageOperation[] = [];
    ops.forEach((op, i) => inverses.push(applyOne(next, op, i)));
    const issues = validateStructure(next);
    if (issues.length > 0) throw new OperationError(issues.map((x) => x.message).join('; '), ops.length - 1);
    return { doc: next, inverse: inverses.reverse() };
}

function requireNode(doc: PageDocument, id: NodeId, i: number): Node {
    const node = doc.nodes[id];
    if (!node) throw new OperationError(`Node ${id} does not exist`, i);
    return node;
}

function requireContainer(doc: PageDocument, id: NodeId, i: number): NodeId[] {
    const node = requireNode(doc, id, i);
    if (!node.children) throw new OperationError(`Node ${id} cannot have children`, i);
    return node.children;
}

function applyOne(doc: PageDocument, op: PageOperation, i: number): PageOperation {
    switch (op.op) {
        case 'insertNode': {
            const children = requireContainer(doc, op.parentId, i);
            if (op.index > children.length) throw new OperationError('Insert index out of range', i);
            for (const node of op.nodes) {
                if (doc.nodes[node.id]) throw new OperationError(`Node ${node.id} already exists`, i);
                doc.nodes[node.id] = structuredClone(node);
            }
            const rootId = op.nodes[0]!.id;
            children.splice(op.index, 0, rootId);
            return { op: 'removeNode', nodeId: rootId };
        }
        case 'removeNode': {
            if (op.nodeId === doc.root) throw new OperationError('The page root cannot be removed', i);
            requireNode(doc, op.nodeId, i);
            const location = findParent(doc, op.nodeId);
            if (!location) throw new OperationError(`Node ${op.nodeId} is not attached`, i);
            const subtree = collectSubtree(doc, op.nodeId);
            doc.nodes[location.parentId]!.children!.splice(location.index, 1);
            for (const node of subtree) delete doc.nodes[node.id];
            return { op: 'insertNode', parentId: location.parentId, index: location.index, nodes: subtree.map((n) => structuredClone(n)) };
        }
        case 'moveNode': {
            if (op.nodeId === doc.root) throw new OperationError('The page root cannot be moved', i);
            requireNode(doc, op.nodeId, i);
            const from = findParent(doc, op.nodeId);
            if (!from) throw new OperationError(`Node ${op.nodeId} is not attached`, i);
            if (collectSubtree(doc, op.nodeId).some((n) => n.id === op.parentId)) throw new OperationError('A node cannot be moved into itself', i);
            const target = requireContainer(doc, op.parentId, i);
            doc.nodes[from.parentId]!.children!.splice(from.index, 1);
            // `index` refers to the position after the node has been removed from its old parent.
            if (op.index > target.length) throw new OperationError('Move index out of range', i);
            target.splice(op.index, 0, op.nodeId);
            return { op: 'moveNode', nodeId: op.nodeId, parentId: from.parentId, index: from.index };
        }
        case 'updateProps': {
            const node = requireNode(doc, op.nodeId, i);
            const { set, unset } = invertPatch(node.props, op.set, op.unset ?? []);
            for (const [k, v] of Object.entries(op.set)) node.props[k] = structuredClone(v);
            for (const k of op.unset ?? []) delete node.props[k];
            return { op: 'updateProps', nodeId: op.nodeId, set, unset };
        }
        case 'updateSeo': {
            const seo = doc.seo as Record<string, unknown>;
            const { set, unset } = invertPatch(seo, op.set as Record<string, unknown>, op.unset ?? []);
            Object.assign(seo, structuredClone(op.set));
            for (const k of op.unset ?? []) delete seo[k];
            return { op: 'updateSeo', set: set as PageSeo, unset: unset as (keyof PageSeo)[] };
        }
    }
}

function invertPatch(current: Record<string, unknown>, set: Record<string, unknown>, unset: readonly string[]) {
    const restore: Record<string, unknown> = {};
    const remove: string[] = [];
    for (const key of new Set([...Object.keys(set), ...unset])) {
        if (Object.hasOwn(current, key)) restore[key] = structuredClone(current[key]);
        else remove.push(key);
    }
    return { set: restore, unset: remove };
}
