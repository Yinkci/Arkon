// Entrance animations in the editor: what a block's animation will actually do on the page
// (its effective status), and the one safe alternative offered when a container can't animate
// because it holds the page's likely LCP (its image that loads first, or its main heading).
import { findParent, type Node, type NodeId, type PageDocument } from '../schema/document';
import type { PageOperation } from '../schema/operations';
import { effectiveValue, styleOf, withStyleValue } from '../style/edit';
import { allowedProperties, BREAKPOINTS, styleRules, type Breakpoint, type Style } from '../style/schema';
import { styleFieldOf } from './parts';

/** Why the renderer leaves a block's animation off: it holds (or is) the likely LCP, `cause`. */
export interface Protection {
    reason: 'image' | 'heading';
    cause: NodeId;
}

export type MotionStatus =
    | { kind: 'none' }
    /** Plays on the page: on load, or when it comes into view (on load when already on screen). */
    | { kind: 'plays'; trigger: 'load' | 'view' }
    /** Set, but left off on the page so the likely LCP appears immediately. */
    | { kind: 'protected'; protection: Protection }
    /** Set, but turned off on the screen being edited (a tablet or phone override). */
    | { kind: 'off-here'; breakpoint: Breakpoint };

export const MOTION_PROPERTIES = Object.keys(styleRules.properties).filter((key) => styleRules.properties[key]!.group === 'motion');

/** Whether a block (its root slot) can have an entrance animation. */
export function canAnimateType(type: string): boolean {
    const root = styleFieldOf(type)?.slots.root;
    return !!root && allowedProperties(root).includes('animation');
}

const animatesSomewhere = (style: Style) => BREAKPOINTS.some((bp) => typeof style.root?.[bp]?.animation === 'string' && style.root[bp]!.animation !== 'none');

/** What the block's animation does on the page, seen from the screen being edited. */
export function motionStatus(style: Style, breakpoint: Breakpoint, protection?: Protection): MotionStatus {
    if (!animatesSomewhere(style)) return { kind: 'none' };
    if (protection) return { kind: 'protected', protection };
    const here = effectiveValue(style, 'root', 'animation', breakpoint).value;
    if (here === undefined || here === 'none') return { kind: 'off-here', breakpoint };
    return { kind: 'plays', trigger: style.root?.base?.animationTrigger === 'view' ? 'view' : 'load' };
}

/**
 * When a container can't animate because it holds protected content: the blocks inside it that
 * can animate on their own, judged against every protected block of the page (`protectedNodes`,
 * from the last render: the image that loads first, the main heading, and every block holding
 * either, reusable components included). Protected blocks are never listed; protected containers
 * are looked into, except a reusable component (its blocks are not the page's). Blocks that
 * already have their own animation keep it (and are not listed).
 */
export function animateInsteadTargets(doc: PageDocument, containerId: NodeId, protectedNodes: Record<NodeId, Protection>): NodeId[] {
    const out: NodeId[] = [];
    const visit = (id: NodeId) => {
        for (const child of doc.nodes[id]?.children ?? []) {
            const node = doc.nodes[child];
            if (!node) continue;
            if (protectedNodes[child]) {
                if (node.type !== 'instance') visit(child);
                continue;
            }
            if (canAnimateType(node.type) && !animatesSomewhere(styleOf(node.props))) out.push(child);
        }
    };
    const container = doc.nodes[containerId];
    if (container && container.type !== 'instance' && protectedNodes[containerId]) visit(containerId);
    return out;
}

/**
 * The safe alternative as one edit: the container's animation settings (every screen) move to
 * each target, and the container's own are removed. No wrappers, nothing changed on `cause`.
 */
export function animateInsteadOps(doc: PageDocument, container: Node, targets: NodeId[]): PageOperation[] {
    const from = styleOf(container.props);
    const ops: PageOperation[] = targets.map((id) => {
        let style = styleOf(doc.nodes[id]!.props);
        for (const bp of BREAKPOINTS)
            for (const property of MOTION_PROPERTIES) {
                const value = from.root?.[bp]?.[property];
                if (value !== undefined) style = withStyleValue(style, 'root', bp, property, value);
            }
        return { op: 'updateProps', nodeId: id, set: { style } };
    });
    let own = from;
    for (const bp of BREAKPOINTS) for (const property of MOTION_PROPERTIES) own = withStyleValue(own, 'root', bp, property, null);
    return [...ops, { op: 'updateProps', nodeId: container.id, set: { style: own } }];
}

/** The top-level block a node is in (the page's direct child), for the default trigger. */
export function isFirstBlock(doc: PageDocument, id: NodeId): boolean {
    let current = id;
    for (let location = findParent(doc, current); location && location.parentId !== doc.root; location = findParent(doc, current)) current = location.parentId;
    return doc.nodes[doc.root]?.children?.[0] === current;
}
