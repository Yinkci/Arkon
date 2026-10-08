// Lengths as the inspector edits them: a number and a unit chosen separately (or a keyword
// such as "auto", or a site token), combined into the stored value and validated by the
// same rules as everything else (styleValueProblem).
import { styleRules, type StyleProperty } from './schema';

export type LengthParts = { kind: 'number'; number: string; unit: string } | { kind: 'keyword'; keyword: string } | { kind: 'token'; token: string };

/** The units a length property accepts, in a sensible order (px first). */
export function unitsOf(definition: Pick<StyleProperty, 'lengths'>): string[] {
    const units = Object.keys(styleRules.lengths[definition.lengths ?? ''] ?? {});
    const order = ['px', '%', 'rem', 'em', 'vh', 'vw', 'ch'];
    return units.sort((a, b) => order.indexOf(a) - order.indexOf(b));
}

/** Splits a stored value ("500px", "auto", "@space.lg", "0") for the number and unit fields. */
export function splitLength(value: string, units: string[]): LengthParts | null {
    if (value.startsWith('@')) return { kind: 'token', token: value };
    if (value === '0') return { kind: 'number', number: '0', unit: units[0] ?? 'px' };
    const match = /^(-?(?:\d+\.?\d*|\.\d+))([a-z%]+)$/.exec(value);
    if (match) return { kind: 'number', number: match[1]!, unit: match[2]! };
    return /^[a-z-]+$/.test(value) ? { kind: 'keyword', keyword: value } : null;
}

/**
 * What the user typed in the number field, with the selected unit. A unit typed into the
 * field wins ("50%" while px is selected), so pasting a full value works too.
 */
export function composeLength(typed: string, unit: string): string {
    const text = typed.trim().toLowerCase().replace(/\s+/g, '');
    if (text === '') return '';
    if (/^-?(?:\d+\.?\d*|\.\d+)$/.test(text)) return text === '0' ? '0' : `${text}${unit}`;
    return text;
}
