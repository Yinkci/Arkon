// Twin of app/Arkon/Style/Tokens.php: design token slots and token-set validation.
import { isPlainObject, message } from '../rules';
import { styleValueProblem, tokenRules, type StyleKind } from './schema';

export type TokenSet = Record<string, Record<string, string>>;

export function tokenGroups(): [string, { label: string; kind: string; lengths?: string; values: Record<string, string> }][] {
    return Object.entries(tokenRules).filter(([key]) => key !== '$comment');
}

export function tokenDefaults(): TokenSet {
    return Object.fromEntries(tokenGroups().map(([group, definition]) => [group, { ...definition.values }]));
}

export function tokenDefinition(group: string, name: string): { label: string; kind: StyleKind; lengths?: string } {
    const definition = tokenRules[group]!;
    return {
        label: `${definition.label}: ${name}`,
        kind: (definition.kind === 'shadowPreset' ? 'shadow' : definition.kind) as StyleKind,
        lengths: definition.lengths,
    };
}

/** Validates a (partial) token set. */
export function parseTokens(value: unknown): { value: TokenSet | null; issues: { path: string; message: string }[] } {
    const issues: { path: string; message: string }[] = [];
    if (!isPlainObject(value)) return { value: null, issues: [{ path: '', message: message('expectedObject') }] };
    const groups = Object.fromEntries(tokenGroups());
    const out: TokenSet = {};
    for (const [group, names] of Object.entries(value)) {
        if (!Object.hasOwn(groups, group)) {
            issues.push({ path: group, message: message('unrecognizedKey', { key: group }) });
            continue;
        }
        if (!isPlainObject(names)) {
            issues.push({ path: group, message: message('expectedObject') });
            continue;
        }
        for (const [name, tokenValue] of Object.entries(names)) {
            if (!Object.hasOwn(groups[group]!.values, name)) {
                issues.push({ path: `${group}.${name}`, message: message('unrecognizedKey', { key: name }) });
                continue;
            }
            const problem = styleValueProblem(tokenDefinition(group, name), tokenValue);
            if (problem !== null) {
                issues.push({ path: `${group}.${name}`, message: problem });
                continue;
            }
            (out[group] ??= {})[name] = tokenValue as string;
        }
    }
    return { value: issues.length === 0 ? out : null, issues };
}
