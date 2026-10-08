// Twin of app/Arkon/Components/PropSchema.php: the manifest prop-schema language.
import { isPlainObject, matches, message } from '../rules';
import { parseStyle, type StyleField } from '../style/schema';

export type Field =
    | { type: 'string'; maxLength?: number; minLength?: number; default?: unknown }
    | { type: 'enum'; values: string[]; default?: unknown }
    | { type: 'boolean'; default?: unknown }
    | { type: 'uuid'; ref?: 'component'; default?: unknown }
    | { type: 'link'; maxLength?: number; default?: unknown }
    | { type: 'object'; properties?: Record<string, Field>; nullable?: boolean; default?: unknown }
    | StyleField;

export interface PropIssue {
    path: string;
    message: string;
}

/** Validates props and returns them with defaults applied (null when invalid). */
export function parseProps(fields: Record<string, Field>, props: unknown): { value: Record<string, unknown> | null; issues: PropIssue[] } {
    const issues: PropIssue[] = [];
    const value = parseObject(fields, props, '', issues);
    return { value: issues.length === 0 ? value : null, issues };
}

function parseObject(fields: Record<string, Field>, value: unknown, at: string, issues: PropIssue[]): Record<string, unknown> | null {
    if (!isPlainObject(value)) {
        issues.push({ path: at, message: message('expectedObject') });
        return null;
    }
    for (const key of Object.keys(value)) {
        if (!Object.hasOwn(fields, key)) issues.push({ path: join(at, key), message: message('unrecognizedKey', { key }) });
    }
    const out: Record<string, unknown> = {};
    for (const [key, field] of Object.entries(fields)) {
        const path = join(at, key);
        if (!Object.hasOwn(value, key) || value[key] === undefined) {
            if ('default' in field) out[key] = field.default;
            else issues.push({ path, message: message('required') });
            continue;
        }
        out[key] = parseField(field, value[key], path, issues);
    }
    return out;
}

function parseField(field: Field, value: unknown, at: string, issues: PropIssue[]): unknown {
    switch (field.type) {
        case 'string':
            if (typeof value !== 'string') {
                issues.push({ path: at, message: message('expectedString') });
                return null;
            }
            if (field.maxLength !== undefined && value.length > field.maxLength)
                issues.push({ path: at, message: message('tooLong', { max: field.maxLength }) });
            if (field.minLength !== undefined && value.length < field.minLength)
                issues.push({ path: at, message: message('tooShort', { min: field.minLength }) });
            return value;
        case 'enum':
            if (!field.values.includes(value as string)) issues.push({ path: at, message: message('oneOf', { values: field.values.join(', ') }) });
            return value;
        case 'boolean':
            if (typeof value !== 'boolean') issues.push({ path: at, message: message('expectedBoolean') });
            return value;
        case 'link':
            // Safe destinations only (shared pattern): relative, #, ?, http(s), mailto:, tel:.
            if (typeof value !== 'string') {
                issues.push({ path: at, message: message('expectedString') });
                return null;
            }
            if (field.maxLength !== undefined && value.length > field.maxLength)
                issues.push({ path: at, message: message('tooLong', { max: field.maxLength }) });
            else if (!matches('link', value)) issues.push({ path: at, message: message('unsafeLink') });
            return value;
        case 'uuid':
            if (!matches('uuid', value)) issues.push({ path: at, message: message('invalidUuid') });
            return value;
        case 'style':
            return parseStyle(field, value, at, issues);
        case 'object':
            if (value === null && field.nullable) return null;
            return parseObject(field.properties ?? {}, value, at, issues);
    }
}

function join(at: string, key: string): string {
    return at === '' ? key : `${at}.${key}`;
}
