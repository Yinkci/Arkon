// Responsive style editing: what a property's value is at a breakpoint (own, inherited or
// default), and immutable updates that set or reset one value. The inspector uses these;
// the server renders the result (desktop-first: tablet and mobile override base).
import { BREAKPOINTS, type Breakpoint, type SlotStyle, type Style, type StyleValue } from './schema';

/** Which breakpoint a canvas viewport edits. Desktop edits the base (all screens). */
export const VIEWPORT_BREAKPOINT: Record<'desktop' | 'tablet' | 'mobile', Breakpoint> = { desktop: 'base', tablet: 'tablet', mobile: 'mobile' };

export const BREAKPOINT_LABEL: Record<Breakpoint, string> = { base: 'All screens', tablet: 'Tablet and smaller', mobile: 'Mobile' };

/** The breakpoints a value at `breakpoint` inherits from, nearest first. */
export function inheritanceChain(breakpoint: Breakpoint): Breakpoint[] {
    return BREAKPOINTS.slice(0, BREAKPOINTS.indexOf(breakpoint) + 1).reverse();
}

export interface Effective {
    value: StyleValue | undefined;
    /** Where the value comes from: this breakpoint, a larger one, or nowhere (component default). */
    from: Breakpoint | null;
}

export function effectiveValue(style: Style | undefined, slot: string, property: string, breakpoint: Breakpoint): Effective {
    for (const bp of inheritanceChain(breakpoint)) {
        const value = style?.[slot]?.[bp]?.[property];
        if (value !== undefined) return { value, from: bp };
    }
    return { value: undefined, from: null };
}

/** A copy of `style` with one value set (or removed when `value` is null). Empty objects are pruned. */
export function withStyleValue(style: Style | undefined, slot: string, breakpoint: Breakpoint, property: string, value: StyleValue | null): Style {
    const next: Style = structuredClone(style ?? {});
    const slotStyle: SlotStyle = next[slot] ?? {};
    const declarations = { ...(slotStyle[breakpoint] ?? {}) };
    if (value === null) delete declarations[property];
    else declarations[property] = value;
    if (Object.keys(declarations).length > 0) slotStyle[breakpoint] = declarations;
    else delete slotStyle[breakpoint];
    if (Object.keys(slotStyle).length > 0) next[slot] = slotStyle;
    else delete next[slot];
    return next;
}

/** A copy of `style` without any values at `breakpoint` (for one slot, or all of them). */
export function withoutBreakpoint(style: Style | undefined, breakpoint: Breakpoint, slot?: string): Style {
    const next: Style = structuredClone(style ?? {});
    for (const name of slot ? [slot] : Object.keys(next)) {
        const slotStyle = next[name];
        if (!slotStyle) continue;
        delete slotStyle[breakpoint];
        if (Object.keys(slotStyle).length === 0) delete next[name];
    }
    return next;
}

/** How many values are set at `breakpoint` (one slot, or all). */
export function countAt(style: Style | undefined, breakpoint: Breakpoint, slot?: string): number {
    let count = 0;
    for (const [name, slotStyle] of Object.entries(style ?? {})) {
        if (slot && name !== slot) continue;
        count += Object.keys(slotStyle[breakpoint] ?? {}).length;
    }
    return count;
}

/** The style prop of a node's props, or an empty style. */
export function styleOf(props: Record<string, unknown>): Style {
    const style = props.style;
    return style && typeof style === 'object' && !Array.isArray(style) ? (style as Style) : {};
}
