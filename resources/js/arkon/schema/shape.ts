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
    const issues = unknownKeys(node, ['id', 'type', 'version', 'variant', 'props', 'children', 'editor'], at);
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
