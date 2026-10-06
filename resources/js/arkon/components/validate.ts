// Twin of app/Arkon/Components/DocumentValidator.php. The editor runs it on every
// change; the server runs the PHP version on every save. tests/conformance runs
// the same fixtures through both.
import { isBlank, message, type Issue } from '../rules';
import { validateStructure, type PageDocument } from '../schema/document';
import { documentIssues } from '../schema/shape';
import { parseProps } from './props';
import { currentDefinition, getDefinition, valueAt } from './registry';

/** Full validation: shape, structure, known component types and versions, props, parent/child rules. */
export function validatePageDocument(input: unknown): Issue[] {
    const shape = documentIssues(input);
    if (shape.length > 0) return shape;
    const doc = input as PageDocument;
    const issues: Issue[] = validateStructure(doc);
    if (doc.nodes[doc.root]?.type !== 'page') issues.push({ message: message('rootNotPage') });

    for (const node of Object.values(doc.nodes)) {
        const current = currentDefinition(node.type);
        if (!current) {
            issues.push({ nodeId: node.id, message: message('unknownComponent', { type: node.type }) });
            continue;
        }
        // The editor only accepts current versions (new work); props and children are still read with the
        // node's own version, exactly like the server's DocumentValidator.
        const definition = getDefinition(node.type, node.version);
        if (!definition || definition.version !== current.version) {
            issues.push({ nodeId: node.id, message: message('unsupportedVersion', { type: node.type, version: node.version }) });
        }
        if (!definition) continue;
        for (const issue of parseProps(definition.props, node.props).issues) {
            issues.push({ nodeId: node.id, path: issue.path, message: issue.message });
        }
        if (definition.children === false) {
            if ('children' in node) issues.push({ nodeId: node.id, message: message('cannotHaveChildren', { label: definition.label }) });
        } else {
            const children = node.children ?? [];
            if (!('children' in node)) issues.push({ nodeId: node.id, message: message('missingChildren') });
            const max = definition.children.max;
            if (max !== undefined && children.length > max) issues.push({ nodeId: node.id, message: message('tooManyChildren', { max }) });
            for (const childId of children) {
                const child = doc.nodes[childId];
                if (child && !definition.children.allow.includes(child.type)) {
                    issues.push({ nodeId: childId, message: message('childNotAllowed', { child: child.type, parent: node.type }) });
                }
            }
        }
    }
    return issues;
}

/** Problems that block publishing but not saving a draft. Assumes a valid document. */
export function publishIssues(doc: PageDocument): Issue[] {
    const issues: Issue[] = [];
    for (const node of Object.values(doc.nodes)) {
        const definition = getDefinition(node.type, node.version);
        if (!definition?.publishChecks) continue;
        const props = parseProps(definition.props, node.props).value ?? {};
        for (const check of definition.publishChecks) {
            if (check.when !== undefined && !valueAt(props, check.when)) continue;
            const value = valueAt(props, check.prop);
            if (typeof value !== 'string' || isBlank(value)) issues.push({ nodeId: node.id, message: check.message });
        }
    }
    return issues;
}

/** Media asset ids referenced anywhere in a valid document. */
export function mediaRefs(doc: PageDocument): string[] {
    const ids = new Set<string>();
    for (const node of Object.values(doc.nodes)) {
        const definition = getDefinition(node.type, node.version);
        const props = definition ? parseProps(definition.props, node.props).value : null;
        for (const path of definition?.mediaRefs ?? []) {
            const id = valueAt(props, path);
            if (typeof id === 'string') ids.add(id);
        }
    }
    return [...ids];
}

/** Which inline fields keep line breaks, by component type (sent to the canvas bridge). */
export function multilineFields(types: string[]): Record<string, string[]> {
    return Object.fromEntries(
        types.map((type) => [
            type,
            Object.entries(currentDefinition(type)?.inlineFields ?? {})
                .filter(([, field]) => field.kind === 'multiline')
                .map(([key]) => key),
        ]),
    );
}
