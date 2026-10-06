// The shared rules (resources/arkon/rules.json), also read by PHP (app/Arkon/Support/Rules.php).
import rulesJson from '../../arkon/rules.json';

export const rules = rulesJson;

type PatternName = keyof typeof rulesJson.patterns;
type MessageName = keyof typeof rulesJson.messages;

const compiled = new Map<PatternName, RegExp>();

export function matches(pattern: PatternName, value: unknown): boolean {
    if (typeof value !== 'string') return false;
    let regex = compiled.get(pattern);
    if (!regex) {
        regex = new RegExp(rulesJson.patterns[pattern], 'u');
        compiled.set(pattern, regex);
    }
    return regex.test(value);
}

export function message(name: MessageName, values: Record<string, string | number> = {}): string {
    let text: string = rulesJson.messages[name];
    for (const [key, value] of Object.entries(values)) text = text.replaceAll(`{${key}}`, String(value));
    return text;
}

/** Same definition as PHP's Text::isBlank: JavaScript's trim(). */
export function isBlank(value: string): boolean {
    return value.trim() === '';
}

export function isPlainObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export interface Issue {
    nodeId?: string;
    path?: string;
    message: string;
}
