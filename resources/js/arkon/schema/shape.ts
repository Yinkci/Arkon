// Twin of app/Arkon/Schema/DocumentShape.php: the shape of a PageDocument, issue paths dotted.
import { isPlainObject, matches, message, rules, type Issue } from '../rules';

type PathIssue = Required<Pick<Issue, 'path' | 'message'>>;

export function documentIssues(doc: unknown): PathIssue[] {
    if (!isPlainObject(doc)) return [{ path: '', message: message('expectedObject') }];
    const issues = unknownKeys(doc, ['schemaVersion', 'root', 'nodes', 'seo'], '');
    if (!isPositiveInteger(doc.schemaVersion) || doc.schemaVersion !== rules.schemaVersion) {
        issues.push({ path: 'schemaVersion', message: message('unsupportedSchemaVersion') });
    }
    if (!matches('nodeId', doc.root)) issues.push({ path: 'root', message: message('invalidNodeId') });
    if (!isPlainObject(doc.nodes)) {
        issues.push({ path: 'nodes', message: message('expectedObject') });
    } else {
        for (const [key, node] of Object.entries(doc.nodes)) {
            if (!matches('nodeId', key)) issues.push({ path: `nodes.${key}`, message: message('invalidNodeId') });
            issues.push(...nodeIssues(node, `nodes.${key}`));
        }
    }
    issues.push(...seoIssues(doc.seo, 'seo'));
    return issues;
}

export function nodeIssues(node: unknown, at: string): PathIssue[] {
    if (!isPlainObject(node)) return [{ path: at, message: message('expectedObject') }];
    const issues = unknownKeys(node, ['id', 'type', 'version', 'variant', 'props', 'children', 'editor', 'bindings'], at);
    if (!matches('nodeId', node.id)) issues.push({ path: `${at}.id`, message: message('invalidNodeId') });
    if (!matches('componentType', node.type)) issues.push({ path: `${at}.type`, message: message('invalidComponentType') });
    if (!isPositiveInteger(node.version)) issues.push({ path: `${at}.version`, message: message('expectedPositiveInteger') });
    if ('variant' in node) issues.push(...stringIssues(node.variant, rules.limits.variant, `${at}.variant`));
    if (!isPlainObject(node.props)) issues.push({ path: `${at}.props`, message: message('expectedObject') });
    if ('children' in node) {
        if (!Array.isArray(node.children)) {
            issues.push({ path: `${at}.children`, message: message('expectedList') });
        } else {
            node.children.forEach((child, i) => {
                if (!matches('nodeId', child)) issues.push({ path: `${at}.children.${i}`, message: message('invalidNodeId') });
            });
        }
    }
    if ('editor' in node) {
        if (!isPlainObject(node.editor)) {
            issues.push({ path: `${at}.editor`, message: message('expectedObject') });
        } else {
            issues.push(...unknownKeys(node.editor, ['name'], `${at}.editor`));
            if ('name' in node.editor) issues.push(...stringIssues(node.editor.name, rules.limits.editorName, `${at}.editor.name`));
        }
    }
    if ('bindings' in node) issues.push(...bindingIssues(node.bindings, `${at}.bindings`));
    return issues;
}

/**
 * What a node says about where a property's value comes from, instead of holding it: one entry
 * per property, naming a source and a field in it. The value in `props` stays as the fallback,
 * so a document with bindings still renders when nothing can be resolved.
 */
export function bindingIssues(bindings: unknown, at: string): PathIssue[] {
    if (!isPlainObject(bindings)) return [{ path: at, message: message('expectedObject') }];
    const issues: PathIssue[] = [];
    const sources = rules.bindings.sources as string[];
    for (const [prop, binding] of Object.entries(bindings)) {
        if (!matches('propKey', prop)) {
            issues.push({ path: `${at}.${prop}`, message: message('invalidPropKey') });
            continue;
        }
        if (!isPlainObject(binding)) {
            issues.push({ path: `${at}.${prop}`, message: message('expectedObject') });
            continue;
        }
        issues.push(...unknownKeys(binding, ['source', 'field', 'path'], `${at}.${prop}`));
        if (!sources.includes(binding.source as string)) {
            issues.push({ path: `${at}.${prop}.source`, message: message('unknownBindingSource') });
        }
        if (!matches('fieldKey', binding.field)) {
            issues.push({ path: `${at}.${prop}.field`, message: message('invalidFieldKey') });
        }
        // A path reaches inside a group: ["address", "city"]. Repeaters are lists, so a single
        // value cannot come from one and a path never indexes into them.
        if ('path' in binding) {
            if (!Array.isArray(binding.path) || binding.path.length > rules.bindings.maxPath) {
                issues.push({ path: `${at}.${prop}.path`, message: message('invalidBindingPath') });
            } else {
                binding.path.forEach((step, i) => {
                    if (!matches('fieldKey', step)) issues.push({ path: `${at}.${prop}.path.${i}`, message: message('invalidFieldKey') });
                });
            }
        }
    }
    return issues;
}

export function seoIssues(seo: unknown, at: string): PathIssue[] {
    if (!isPlainObject(seo)) return [{ path: at, message: message('expectedObject') }];
    const fields = rules.seo as Record<string, { type: string; maxLength?: number; values?: string[]; pattern?: string }>;
    const issues = unknownKeys(seo, Object.keys(fields), at);
    for (const [key, field] of Object.entries(fields)) {
        if (!(key in seo)) continue;
        if (field.type === 'boolean') {
            if (typeof seo[key] !== 'boolean') issues.push({ path: `${at}.${key}`, message: message('expectedBoolean') });
        } else {
            issues.push(...stringIssues(seo[key], field.maxLength!, `${at}.${key}`));
            if (typeof seo[key] === 'string' && field.values && !field.values.includes(seo[key]))
                issues.push({ path: `${at}.${key}`, message: message('oneOf', { values: field.values.join(', ') }) });
            if (typeof seo[key] === 'string' && field.pattern && !new RegExp(field.pattern).test(seo[key]))
                issues.push({ path: `${at}.${key}`, message: 'Use a valid SEO value' });
        }
    }
    return issues;
}

function stringIssues(value: unknown, max: number, at: string): PathIssue[] {
    if (typeof value !== 'string') return [{ path: at, message: message('expectedString') }];
    if (value.length > max) return [{ path: at, message: message('tooLong', { max }) }];
    return [];
}

function unknownKeys(object: Record<string, unknown>, allowed: string[], at: string): PathIssue[] {
    return Object.keys(object)
        .filter((key) => !allowed.includes(key))
        .map((key) => ({ path: at === '' ? key : `${at}.${key}`, message: message('unrecognizedKey', { key }) }));
}

export function isPositiveInteger(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0;
}
