// Structural editing helpers: where a component may go, and the operations that put it there.
// Pure functions over the document; the editor dispatches what they return, and dispatch
// validates the result with the same rules as the server, so these only decide what to offer.
import { componentTypes, currentDefinition, themeIsAddable } from '../components/registry';
import { createNodeId, findParent, type Node, type NodeId, type PageDocument } from '../schema/document';
import type { PageOperation } from '../schema/operations';
import { placeAt } from './placement';

/** Components the palette offers, in display order. Columns create their column children themselves. */
export function addableTypes(): string[] {
    return [
        'section',
        'group',
        'hero',
        'text',
        'image',
        'button',
        'columns',
        ...componentTypes().filter((type) => type.startsWith('theme-') && themeIsAddable(type)),
    ];
}
export const ADDABLE_TYPES = ['section', 'group', 'hero', 'text', 'image', 'button', 'columns'] as const;
export type AddableType = (typeof ADDABLE_TYPES)[number];

/** A new component with its defaults (and `props` on top); Columns come with two empty columns. The first node is the root. */
export function createNodes(type: string, props: Record<string, unknown> = {}): Node[] {
    const definition = currentDefinition(type);
    if (!definition) throw new Error(`Unknown component ${type}`);
    const node: Node = { id: createNodeId(), type, version: definition.version, props: { ...structuredClone(definition.defaultProps), ...props } };
    if (definition.children === false) return [node];
    const children = type === 'columns' ? [...createNodes('column'), ...createNodes('column')] : [];
    node.children = children.filter((c) => c.type === 'column').map((c) => c.id);
    return [node, ...children];
}

/** Whether `parent` accepts one more child of `childType` (allowed type and below its maximum). */
export function canContain(parent: Node, childType: string, adding = 1): boolean {
    const definition = currentDefinition(parent.type);
    if (!definition || definition.children === false) return false;
    if (!definition.children.allow.includes(childType)) return false;
    const max = definition.children.max;
    return max === undefined || (parent.children?.length ?? 0) + adding <= max;
}

export interface Placement {
    parentId: NodeId;
    /** Index in the parent's children (for moves: after the node was taken out of its old place). */
    index: number;
}

/**
 * Where "Add" puts a new component: inside the selected container if it accepts it,
 * otherwise right after the selection (or its nearest ancestor whose parent accepts
 * the type), otherwise at the end of the page.
 */
export function insertionFor(doc: PageDocument, selectedId: NodeId | null, type: string): Placement | null {
    const selected = selectedId ? doc.nodes[selectedId] : undefined;
    if (selected && selected.children && canContain(selected, type)) return { parentId: selected.id, index: selected.children.length };
    let current = selected;
    while (current && current.id !== doc.root) {
        const location = findParent(doc, current.id);
        if (!location) break;
        const parent = doc.nodes[location.parentId]!;
        if (canContain(parent, type)) return { parentId: parent.id, index: location.index + 1 };
        current = parent;
    }
    const root = doc.nodes[doc.root]!;
    return canContain(root, type) ? { parentId: root.id, index: root.children?.length ?? 0 } : null;
}

export function insertOps(placement: Placement, nodes: Node[]): PageOperation[] {
    return [{ op: 'insertNode', parentId: placement.parentId, index: placement.index, nodes }];
}

/** Move up (-1) or down (+1) among its siblings; null at either end. */
export function moveWithinParent(doc: PageDocument, nodeId: NodeId, direction: -1 | 1): PageOperation[] | null {
    const location = findParent(doc, nodeId);
    if (!location) return null;
    const siblings = doc.nodes[location.parentId]!.children!;
    const target = location.index + direction;
    if (target < 0 || target >= siblings.length) return null;
    return [{ op: 'moveNode', nodeId, parentId: location.parentId, index: target }];
}

export type DropPosition = 'before' | 'after' | 'inside';

/**
 * Where a component (an existing node being moved, or a new one of `type`) would land when
 * dropped on `targetId` at `position`. Null when not allowed: wrong nesting, a full
 * container, a source container that would drop below its minimum, or moving a node into
 * its own subtree.
 */
export function dropPlacement(doc: PageDocument, dragged: { nodeId?: NodeId; type: string }, targetId: NodeId, position: DropPosition): Placement | null {
    const target = doc.nodes[targetId];
    if (!target) return null;
    let parentId: NodeId;
    let index: number;
    if (position === 'inside') {
        if (!target.children) return null;
        parentId = target.id;
        index = target.children.length;
    } else {
        if (targetId === doc.root) return null;
        const location = findParent(doc, targetId);
        if (!location) return null;
        parentId = location.parentId;
        index = location.index + (position === 'after' ? 1 : 0);
    }
    const check = placeAt(doc, dragged, parentId, index);
    return check.ok ? check.placement : null;
}

export function dropOps(dragged: { nodeId?: NodeId; type: string; props?: Record<string, unknown> }, placement: Placement): PageOperation[] {
    return dragged.nodeId
        ? [{ op: 'moveNode', nodeId: dragged.nodeId, parentId: placement.parentId, index: placement.index }]
        : insertOps(placement, createNodes(dragged.type, dragged.props));
}

/** The root cannot go, and a container keeps its minimum children (the last column of Columns). */
export function canRemove(doc: PageDocument, nodeId: NodeId): boolean {
    if (nodeId === doc.root) return false;
    const location = findParent(doc, nodeId);
    if (!location) return false;
    const parent = doc.nodes[location.parentId]!;
    const definition = currentDefinition(parent.type);
    const min = definition && definition.children !== false ? definition.children.min : undefined;
    return min === undefined || (parent.children?.length ?? 0) > min;
}

/** A short human label: the component name plus the start of its main text. */
export function nodeLabel(node: Node): string {
    const name = currentDefinition(node.type)?.label ?? node.type;
    const text = [node.props.heading, node.props.text, node.props.label, node.props.caption].find((v) => typeof v === 'string' && v.trim() !== '') as
        string | undefined;
    return text ? `${name}: ${text.trim().replace(/\s+/g, ' ').slice(0, 40)}` : name;
}

/** A copy of a subtree of `source` with new node ids, root first (for insertNode). */
export function copySubtree(source: PageDocument, rootId: NodeId): Node[] {
    const out: Node[] = [];
    const visit = (id: NodeId): NodeId => {
        const node = source.nodes[id]!;
        const copy: Node = { ...structuredClone(node), id: createNodeId() };
        out.push(copy);
        if (node.children) copy.children = node.children.map(visit);
        return copy.id;
    };
    visit(rootId);
    return out;
}

/**
 * Detaching a reusable component: the instance is replaced, at the same place, by a
 * copy of the blocks of the component's published version. The page then owns them
 * (later changes to the component no longer reach it). Null if the instance is gone.
 */
export function detachOps(doc: PageDocument, instanceId: NodeId, component: PageDocument): PageOperation[] | null {
    const location = findParent(doc, instanceId);
    if (!location) return null;
    const ops: PageOperation[] = [{ op: 'removeNode', nodeId: instanceId }];
    (component.nodes[component.root]?.children ?? []).forEach((childId, i) => {
        ops.push({ op: 'insertNode', parentId: location.parentId, index: location.index + i, nodes: copySubtree(component, childId) });
    });
    return ops;
}
