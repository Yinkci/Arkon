// Twin of app/Arkon/Components/Factories.php.
import { rules } from '../rules';
import { createNodeId, type Node, type PageDocument } from '../schema/document';
import { currentDefinition } from './registry';

export function createHeroNode(props: Record<string, unknown> = {}): Node {
    const hero = currentDefinition('hero')!;
    return { id: createNodeId(), type: hero.type, version: hero.version, props: { ...structuredClone(hero.defaultProps), ...props } };
}

export function createPageDocument(sections: Node[] = []): PageDocument {
    const page = currentDefinition('page')!;
    const rootId = createNodeId();
    const nodes: Record<string, Node> = {
        [rootId]: { id: rootId, type: page.type, version: page.version, props: {}, children: sections.map((s) => s.id) },
    };
    for (const section of sections) nodes[section.id] = section;
    return { schemaVersion: rules.schemaVersion, root: rootId, nodes, seo: {} };
}
