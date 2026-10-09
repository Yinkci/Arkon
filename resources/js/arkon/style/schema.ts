// Twin of app/Arkon/Style/StyleSchema.php: validation of the shared styling model
// (`style` in resources/arkon/rules.json). tests/Conformance holds both to the same results.
import { isPlainObject, matches, message, rules } from '../rules';

export const BREAKPOINTS = ['base', 'tablet', 'mobile'] as const;
export type Breakpoint = (typeof BREAKPOINTS)[number];

export type StyleKind = 'enum' | 'length' | 'number' | 'color' | 'ratio' | 'columns' | 'font' | 'shadow' | 'image' | 'time' | 'gradient';

export interface StyleProperty {
    group: string;
    label: string;
    css: string;
    kind: StyleKind;
    values?: Record<string, string>;
    lengths?: string;
    keywords?: string[];
    tokens?: string[];
    min?: number;
    max?: number;
    baseOnly?: boolean;
}

export interface StyleSlot {
    label?: string;
    groups?: string[];
    properties?: string[];
}

export interface StyleField {
    type: 'style';
    slots: Record<string, StyleSlot>;
    default?: unknown;
}

export type StyleValue = string | { assetId: string };
export type StyleDeclarations = Record<string, StyleValue>;
export type SlotStyle = Partial<Record<Breakpoint, StyleDeclarations>>;
export type Style = Record<string, SlotStyle>;

interface StyleRules {
    breakpoints: Record<'tablet' | 'mobile', number>;
    lengths: Record<string, Record<string, [number, number]>>;
    fonts: Record<string, string>;
    shadows: Record<string, string>;
    groups: Record<string, string>;
    properties: Record<string, StyleProperty>;
}

export const styleRules = rules.style as unknown as StyleRules;
export const tokenRules = rules.tokens as unknown as Record<string, { label: string; kind: string; lengths?: string; values: Record<string, string> }>;

/** The properties a slot accepts, in registry order. */
export function allowedProperties(slot: StyleSlot): string[] {
    return Object.keys(styleRules.properties).filter(
        (key) => (slot.groups ?? []).includes(styleRules.properties[key]!.group) || (slot.properties ?? []).includes(key),
    );
}

export interface StyleIssue {
    path: string;
    message: string;
}

/** Validates a style prop; returns it unchanged when valid (issues appended). */
export function parseStyle(field: StyleField, value: unknown, at: string, issues: StyleIssue[]): Style | null {
    if (!isPlainObject(value)) {
        issues.push({ path: at, message: message('expectedObject') });
        return null;
    }
    const out: Style = {};
    for (const [slotName, slotValue] of Object.entries(value)) {
        const slotPath = `${at}.${slotName}`;
        const slot = Object.hasOwn(field.slots, slotName) ? field.slots[slotName] : undefined;
        if (!slot) {
            issues.push({ path: slotPath, message: message('unrecognizedKey', { key: slotName }) });
            continue;
        }
        if (!isPlainObject(slotValue)) {
            issues.push({ path: slotPath, message: message('expectedObject') });
            continue;
        }
        const allowed = allowedProperties(slot);
        for (const [breakpoint, declarations] of Object.entries(slotValue)) {
            const bpPath = `${slotPath}.${breakpoint}`;
            if (!(BREAKPOINTS as readonly string[]).includes(breakpoint)) {
                issues.push({ path: bpPath, message: message('unrecognizedKey', { key: breakpoint }) });
                continue;
            }
            if (!isPlainObject(declarations)) {
                issues.push({ path: bpPath, message: message('expectedObject') });
                continue;
            }
            for (const [property, propertyValue] of Object.entries(declarations)) {
                const path = `${bpPath}.${property}`;
                const definition = Object.hasOwn(styleRules.properties, property) ? styleRules.properties[property] : undefined;
                if (!definition) {
                    issues.push({ path, message: message('unrecognizedKey', { key: property }) });
                    continue;
                }
                if (!allowed.includes(property)) {
                    issues.push({ path, message: message('styleNotAllowed', { label: definition.label }) });
                    continue;
                }
                if (definition.baseOnly && breakpoint !== 'base') {
                    issues.push({ path, message: message('styleBaseOnly', { label: definition.label }) });
                    continue;
                }
                const problem = styleValueProblem(definition, propertyValue);
                if (problem !== null) {
                    issues.push({ path, message: problem });
                    continue;
                }
                ((out[slotName] ??= {})[breakpoint as Breakpoint] ??= {})[property] = propertyValue as StyleValue;
            }
        }
    }
    return out;
}

/** Null when the value is acceptable for the property, otherwise the message. */
export function styleValueProblem(definition: Pick<StyleProperty, 'label' | 'kind'> & Partial<StyleProperty>, value: unknown): string | null {
    const invalid = () => message('styleInvalid', { label: definition.label, expected: expectedValues(definition) });
    if (definition.kind === 'image') {
        if (!isPlainObject(value) || Object.keys(value).length !== 1 || !Object.hasOwn(value, 'assetId') || !matches('uuid', value.assetId)) {
            return message('expectedAsset');
        }
        return null;
    }
    if (typeof value !== 'string') return invalid();
    if (value.startsWith('@')) return tokenProblem(definition, value);
    if ((definition.keywords ?? []).includes(value)) return null;

    switch (definition.kind) {
        case 'enum':
            return Object.hasOwn(definition.values ?? {}, value) ? null : invalid();
        case 'length': {
            if (value === '0') return null;
            if (!matches('styleLength', value)) return invalid();
            const [, number = '', unit = ''] = /^(-?[0-9.]+)(.+)$/.exec(value)!;
            const profile = styleRules.lengths[definition.lengths!] ?? {};
            const bounds = Object.hasOwn(profile, unit) ? profile[unit] : undefined;
            if (!bounds) return invalid();
            const n = parseFloat(number);
            return n < bounds[0] || n > bounds[1] ? message('styleOutOfRange', { label: definition.label, min: bounds[0], max: bounds[1], unit }) : null;
        }
        case 'number': {
            if (!matches('styleNumber', value)) return invalid();
            const n = parseFloat(value);
            return n < definition.min! || n > definition.max!
                ? message('styleOutOfRange', { label: definition.label, min: definition.min!, max: definition.max!, unit: '' })
                : null;
        }
        case 'time': {
            // Milliseconds only (600ms), within the property's bounds.
            if (!matches('styleTime', value)) return invalid();
            const n = parseInt(value.slice(0, -2), 10);
            return n < definition.min! || n > definition.max!
                ? message('styleOutOfRange', { label: definition.label, min: definition.min!, max: definition.max!, unit: 'ms' })
                : null;
        }
        case 'gradient':
            return /^(?:0|45|90|135|180|225|270|315)deg #[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})? #[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/.exec(value)?.[0] === value
                ? null
                : invalid();
        case 'color':
            return value === 'transparent' || matches('styleColor', value) ? null : invalid();
        case 'ratio':
            return matches('styleRatio', value) ? null : invalid();
        case 'columns': {
            if (/^[1-6]$/.test(value)) return null;
            const tracks = value.split(' ');
            if (tracks.length < 2 || tracks.length > 6) return invalid();
            for (const track of tracks) {
                if (!matches('styleTrack', track) || parseFloat(track) <= 0 || parseFloat(track) > 20) return invalid();
            }
            return null;
        }
        case 'font':
            return Object.hasOwn(styleRules.fonts, value) ? null : invalid();
        case 'shadow':
            return Object.hasOwn(styleRules.shadows, value) ? null : invalid();
    }
    return invalid();
}

function tokenProblem(definition: Pick<StyleProperty, 'label' | 'kind'> & Partial<StyleProperty>, value: string): string | null {
    const invalid = message('styleInvalid', { label: definition.label, expected: expectedValues(definition) });
    if (!matches('styleToken', value)) return invalid;
    const [group = '', name = ''] = value.slice(1).split('.', 2);
    if (!(definition.tokens ?? []).includes(group)) return invalid;
    return Object.hasOwn(tokenRules[group]?.values ?? {}, name) ? null : message('unknownToken', { token: value });
}

/** A short description of the accepted values (same wording as the PHP twin). */
export function expectedValues(definition: Pick<StyleProperty, 'kind'> & Partial<StyleProperty>): string {
    const first = {
        enum: () => `one of ${Object.keys(definition.values ?? {}).join(', ')}`,
        length: () => `a length in ${Object.keys(styleRules.lengths[definition.lengths!] ?? {}).join(', ')}`,
        number: () => 'a number',
        time: () => 'a duration in milliseconds such as 600ms',
        color: () => 'a hex colour such as #1d4ed8, or transparent',
        ratio: () => 'a ratio such as 16/9',
        columns: () => 'a column count (1-6) or 2-6 fractions such as 1fr 2fr',
        font: () => `one of ${Object.keys(styleRules.fonts).join(', ')}`,
        shadow: () => `one of ${Object.keys(styleRules.shadows).join(', ')}`,
        image: () => 'an image reference',
        gradient: () => 'an angle (0,45,90,135,180,225,270,315deg) and two hex colours, e.g. 90deg #102030ff #10203000',
    }[definition.kind]();
    return [first, ...(definition.keywords ?? []), ...(definition.tokens ?? []).map((group) => `a @${group} token`)].join(' or ');
}

/** Media asset ids used by a style value (background images). */
export function styleAssetIds(style: unknown): string[] {
    const ids: string[] = [];
    if (!isPlainObject(style)) return ids;
    for (const slot of Object.values(style)) {
        if (!isPlainObject(slot)) continue;
        for (const declarations of Object.values(slot)) {
            const image = isPlainObject(declarations) ? declarations.backgroundImage : undefined;
            if (isPlainObject(image) && typeof image.assetId === 'string') ids.push(image.assetId);
        }
    }
    return ids;
}
