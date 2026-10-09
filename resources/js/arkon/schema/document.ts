// Twin of app/Arkon/Schema/DocumentStructure.php. tests/conformance holds both to the same results.
import { message, rules, type Issue } from '../rules';

export type NodeId = string;

export interface Node {
    id: NodeId;
    type: string;
    version: number;
    variant?: string;
    props: Record<string, unknown>;
    children?: NodeId[];
    editor?: { name?: string };
}

export interface PageSeo {
    title?: string;
    description?: string;
    noindex?: boolean;
    nofollow?: boolean;
    focusTopic?: string;
    pageType?: 'standard' | 'home' | 'landing' | 'article' | 'contact' | 'about';
    canonical?: string;
    socialTitle?: string;
    socialDescription?: string;
    socialImage?: string;
    schemaType?: 'Auto' | 'WebPage' | 'AboutPage' | 'ContactPage' | 'Article' | 'BlogPosting' | 'None';
}

export interface PageDocument {
    schemaVersion: number;
    root: NodeId;
    nodes: Record<NodeId, Node>;
    seo: PageSeo;
}

/**
 * The root exists, every node is reachable from the root exactly once (no cycles,
 * no shared children, no orphans), map keys match node ids, and depth is bounded.
 */
export function validateStructure(doc: PageDocument): Issue[] {
    const issues: Issue[] = [];
    const ids = Object.keys(doc.nodes);
    if (ids.length > rules.maxNodes) issues.push({ message: message('tooManyNodes', { max: rules.maxNodes }) });
    for (const id of ids) {
        if (doc.nodes[id]?.id !== id) issues.push({ nodeId: id, message: message('keyMismatch') });
    }
    if (!doc.nodes[doc.root]) return [...issues, { message: message('rootMissing') }];

    const seen = new Set<NodeId>();
    const visit = (id: NodeId, depth: number) => {
        if (seen.has(id)) {
            issues.push({ nodeId: id, message: message('duplicateInTree') });
            return;
        }
        seen.add(id);
        const node = doc.nodes[id];
        if (!node) {
            issues.push({ nodeId: id, message: message('childMissing') });
            return;
        }
        if (depth > rules.maxDepth) issues.push({ nodeId: id, message: message('tooDeep', { max: rules.maxDepth }) });
        for (const child of node.children ?? []) visit(child, depth + 1);
    };
    visit(doc.root, 0);

    for (const id of ids) {
        if (!seen.has(id)) issues.push({ nodeId: id, message: message('detached') });
    }
    return issues;
}

export function findParent(doc: PageDocument, nodeId: NodeId): { parentId: NodeId; index: number } | null {
    for (const node of Object.values(doc.nodes)) {
        const index = node.children?.indexOf(nodeId) ?? -1;
        if (index >= 0) return { parentId: node.id, index };
    }
    return null;
}

/** A node and all its descendants in depth-first pre-order. */
export function collectSubtree(doc: PageDocument, nodeId: NodeId): Node[] {
    const out: Node[] = [];
    const walk = (id: NodeId) => {
        const node = doc.nodes[id];
        if (!node) return;
        out.push(node);
        for (const child of node.children ?? []) walk(child);
    };
    walk(nodeId);
    return out;
}

const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

/** Short random id for nodes inside documents. 10 base62 chars ≈ 59 bits. */
export function createNodeId(): string {
    let id = '';
    while (id.length < 10) {
        const byte = crypto.getRandomValues(new Uint8Array(1))[0]!;
        // 248 is the largest multiple of 62 below 256; rejecting above it avoids modulo bias.
        if (byte < 248) id += ALPHABET[byte % 62];
    }
    return id;
}
