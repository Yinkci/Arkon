import definitions from '../../../arkon/patterns.json';
import { createNodes } from './structure';
import type { Node, PageDocument } from '../schema/document';

interface Template {
    type: string;
    props: Record<string, unknown>;
    children?: Template[];
}
export const patterns = definitions as { id: string; name: string; description: string; template: Template }[];

/** Patterns expand into ordinary editable blocks, with fresh ids for every insertion. */
export function createPattern(id: string, document?: PageDocument): Node[] {
    const pattern = patterns.find((p) => p.id === id);
    if (!pattern) throw new Error('Unknown layout pattern');
    const nodes: Node[] = [];
    const anchors = new Set(
        Object.values(document?.nodes ?? {})
            .map((node) => node.props.anchor)
            .filter((value) => typeof value === 'string' && value !== ''),
    );

    const visit = (template: Template): string => {
        const node = createNodes(template.type, structuredClone(template.props))[0]!;
        if (typeof node.props.anchor === 'string' && node.props.anchor !== '') {
            const base = node.props.anchor;
            let anchor = base,
                suffix = 2;
            while (anchors.has(anchor)) anchor = base.slice(0, 50) + '-' + suffix++;
            node.props.anchor = anchor;
            anchors.add(anchor);
        }
        nodes.push(node);
        if (node.children) node.children = (template.children ?? []).map(visit);
        return node.id;
    };
    visit(pattern.template);
    return nodes;
}
