import { GradientControl } from './GradientControl';
import { useEffect, useId, useState, type ReactNode } from 'react';
import type { Part } from '@/arkon/editor/parts';
import { contextualLabel } from '@/arkon/editor/parts';
import { composeLength, splitLength, unitsOf } from '@/arkon/style/length';
import { BREAKPOINT_LABEL, countAt, effectiveValue, styleOf, withStyleValue, type Effective } from '@/arkon/style/edit';
import {
    allowedProperties,
    expectedValues,
    styleRules,
    styleValueProblem,
    type Breakpoint,
    type Style,
    type StyleField,
    type StyleProperty,
    type StyleValue,
} from '@/arkon/style/schema';
import type { TokenSet } from '@/arkon/style/tokens';
import { Icon } from '@/Components/Icon';
import { PanelSection, Segmented } from '@/Components/ui';
import type { MediaInfo } from '@/types';
import { MediaPicker, mediaName } from './MediaPicker';

/** Friendlier names for some enum values (the stored value stays the CSS-like one). */
const OPTION_LABELS: Record<string, Record<string, string>> = {
    direction: { row: 'Side by side', 'row-reverse': 'Side by side, reversed', column: 'Stacked', 'column-reverse': 'Stacked, reversed' },
    display: { flex: 'Flexible row or stack', grid: 'Grid', block: 'Normal flow', none: 'Hidden' },
    wrap: { wrap: 'Wrap onto new lines', nowrap: 'Keep on one line' },
    objectFit: { cover: 'Fill and crop (cover)', contain: 'Fit inside, no crop (contain)', fill: 'Stretch' },
};

/** Suggestions offered in free-form fields (any valid value can still be typed). */
const SUGGESTIONS: Record<string, string[]> = {
    aspectRatio: ['auto', '16/9', '4/3', '3/2', '1/1', '3/4', '9/16'],
    columns: ['1', '2', '3', '4', '1fr 2fr', '2fr 1fr', '1fr 1fr 2fr'],
    lineHeight: ['1', '1.2', '1.4', '1.6', '1.8'],
};

const SCREEN_NOUN: Record<Breakpoint, string> = { base: 'all screens', tablet: 'tablet', mobile: 'mobile' };

export interface StyleTarget {
    /** The node's props (the `style` prop is read from here). */
    props: Record<string, unknown>;
    /** The manifest's style field: which slots and properties this component accepts. */
    field: StyleField;
    part: Part;
    breakpoint: Breakpoint;
    tokens: TokenSet;
    media: MediaInfo[];
    canEdit: boolean;
    canUpload?: boolean;
    onUpload?(file: File, progress?: (percent: number) => void): Promise<MediaInfo | null>;
    /** Sets the whole style prop; `key` coalesces typing into one undo step (none: its own step, e.g. a reset). */
    onStyle(style: Style, key?: string): void;
}

function setter(target: StyleTarget, property: string) {
    const style = styleOf(target.props);
    return (value: StyleValue | null) =>
        target.onStyle(
            withStyleValue(style, target.part.slot, target.breakpoint, property, value),
            value === null ? undefined : `style.${target.part.slot}.${target.breakpoint}.${property}`,
        );
}

/** Which screen sizes design values are edited for, with how many values each overrides. */
export function ScreenBar({
    props,
    breakpoint,
    onBreakpoint,
    slot,
}: {
    props: Record<string, unknown>;
    breakpoint: Breakpoint;
    onBreakpoint(bp: Breakpoint): void;
    slot?: string;
}) {
    const style = styleOf(props);
    const count = (bp: Breakpoint) => {
        const responsive = props.responsive as Record<string, Record<string, unknown>> | undefined;
        const presets = bp === 'base' || slot ? 0 : Object.values(responsive?.[bp] ?? {}).filter((value) => value !== 'inherit').length;
        return countAt(style, bp, slot) + presets;
    };
    return (
        <div className="space-y-1.5 border-b border-line bg-raised px-4 py-2.5" data-testid="screen-bar">
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-medium text-muted">Design for</span>
                <Segmented
                    label="Screen size being edited"
                    size="sm"
                    value={breakpoint}
                    onChange={onBreakpoint}
                    options={(['base', 'tablet', 'mobile'] as const).map((bp) => ({
                        value: bp,
                        label: bp === 'base' ? 'All screens' : bp === 'tablet' ? 'Tablet' : 'Mobile',
                        icon: bp === 'base' ? 'desktop' : bp,
                        badge:
                            bp !== 'base' && count(bp) > 0 ? (
                                <span className="rounded-full bg-accent-soft px-1 text-[10px] text-accent tabular-nums" aria-label={`${count(bp)} overrides`}>
                                    {count(bp)}
                                </span>
                            ) : undefined,
                    }))}
                />
            </div>
            <p className="text-[11px] leading-snug text-muted">
                {breakpoint === 'base'
                    ? 'Values apply on every screen unless tablet or mobile overrides them.'
                    : `Overrides for ${breakpoint === 'tablet' ? 'tablets and phones (899 px and narrower)' : 'phones (599 px and narrower)'}. Anything not set here is inherited.`}
            </p>
        </div>
    );
}

/**
 * One design property of the selected part, for the screen size being edited: its own value
 * (with Reset), or what it inherits from a larger screen, or the component default.
 */
export function StyleControl({
    target,
    property,
    label,
    hint,
    testId,
    check,
}: {
    target: StyleTarget;
    property: string;
    /** Defaults to the property named for the part ("Image height"). */
    label?: string;
    hint?: ReactNode;
    testId?: string;
    /** An extra check of a typed value beyond the property's own rules (shown, and nothing applied, when it fails). */
    check?(value: string): string | null;
}) {
    const id = useId();
    const definition = styleRules.properties[property]!;
    const style = styleOf(target.props);
    const effective = effectiveValue(style, target.part.slot, property, target.breakpoint);
    const own = effective.from === target.breakpoint ? effective.value : undefined;
    const inherited = effective.from !== null && effective.from !== target.breakpoint ? effective.value : undefined;
    const name = label ?? contextualLabel(target.part, definition.label);
    const baseOnlyHere = definition.baseOnly === true && target.breakpoint !== 'base';
    const disabled = !target.canEdit || baseOnlyHere;
    const onSet = setter(target, property);
    const state = own !== undefined ? (target.breakpoint === 'base' ? null : `${target.breakpoint === 'tablet' ? 'Tablet' : 'Mobile'} override`) : null;

    return (
        <div data-testid={testId ?? `style-${property}`} data-state={own !== undefined ? 'set' : inherited !== undefined ? 'inherited' : 'default'}>
            <div className="mb-1 flex min-h-5 items-center gap-1.5">
                <label htmlFor={id} className={`min-w-0 flex-1 truncate text-xs ${own !== undefined ? 'font-semibold text-fg' : 'font-medium text-muted'}`}>
                    {name}
                </label>
                {state && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-accent-soft px-1.5 text-[10px] font-medium text-accent">
                        <Icon name={target.breakpoint === 'tablet' ? 'tablet' : 'mobile'} className="size-2.5" />
                        {state}
                    </span>
                )}
                {own !== undefined && (
                    <button
                        type="button"
                        disabled={!target.canEdit}
                        onClick={() => onSet(null)}
                        className="inline-flex items-center gap-0.5 rounded px-1 text-[11px] text-muted hover:bg-sunken hover:text-fg disabled:opacity-40"
                        aria-label={`Reset ${name} on ${BREAKPOINT_LABEL[target.breakpoint].toLowerCase()}`}
                        title={target.breakpoint === 'base' ? 'Back to the default' : `Back to the value inherited from larger screens`}
                    >
                        <Icon name="reset" className="size-3" />
                        Reset
                    </button>
                )}
            </div>
            <ValueInput
                id={id}
                property={property}
                definition={definition}
                own={own}
                inherited={inherited}
                target={target}
                disabled={disabled}
                onSet={onSet}
                name={name}
                check={check}
            />
            <StateLine effective={effective} breakpoint={target.breakpoint} own={own} media={target.media} baseOnly={baseOnlyHere} property={property} />
            {hint && <p className="mt-1 text-[11px] leading-snug text-muted">{hint}</p>}
        </div>
    );
}

function StateLine({
    effective,
    breakpoint,
    own,
    media,
    baseOnly,
    property,
}: {
    effective: Effective;
    breakpoint: Breakpoint;
    own: StyleValue | undefined;
    media: MediaInfo[];
    baseOnly: boolean;
    property: string;
}) {
    if (baseOnly) return <p className="mt-1 text-[11px] text-muted">Set on all screens only.</p>;
    if (own !== undefined) return null;
    if (effective.from !== null && effective.value !== undefined) {
        return (
            <p className="mt-1 flex items-center gap-1 truncate text-[11px] text-muted">
                <Icon name="arrowDown" className="size-3 text-faint" />
                From {SCREEN_NOUN[effective.from]}: {display(effective.value, media, property)}
            </p>
        );
    }
    return <p className="mt-1 text-[11px] text-faint">Default{breakpoint === 'base' ? '' : ' on every screen'}</p>;
}

function display(value: StyleValue, media: MediaInfo[], property: string): string {
    if (typeof value !== 'string') {
        const asset = media.find((m) => m.id === value.assetId);
        return asset ? mediaName(asset) : 'an image';
    }
    return OPTION_LABELS[property]?.[value] ?? value;
}

/** Token choices for a property: "@group.name" with the site's current value. */
function tokenOptions(definition: StyleProperty, tokens: TokenSet): [string, string][] {
    return (definition.tokens ?? []).flatMap((group) =>
        Object.entries(tokens[group] ?? {}).map(([name, value]) => [`@${group}.${name}`, `${group}.${name} (${value})`] as [string, string]),
    );
}

interface ValueInputProps {
    id: string;
    name: string;
    property: string;
    definition: StyleProperty;
    own: StyleValue | undefined;
    inherited: StyleValue | undefined;
    target: StyleTarget;
    disabled: boolean;
    onSet(value: StyleValue | null): void;
    check?(value: string): string | null;
}

function ValueInput(props: ValueInputProps) {
    const { definition, target } = props;
    const tokens = tokenOptions(definition, target.tokens);

    if (definition.kind === 'gradient')
        return (
            <GradientControl
                value={typeof props.own === 'string' ? props.own : typeof props.inherited === 'string' ? props.inherited : undefined}
                disabled={props.disabled}
                onSet={props.onSet}
            />
        );
    if (definition.kind === 'image') {
        const value = typeof props.own === 'object' ? props.own.assetId : typeof props.inherited === 'object' ? props.inherited.assetId : null;
        return (
            <div id={props.id} role="group" aria-label={props.name}>
                <MediaPicker
                    value={value}
                    media={target.media}
                    canEdit={!props.disabled}
                    canUpload={target.canUpload === true && !!target.onUpload}
                    onUpload={target.onUpload ?? (async () => null)}
                    onChoose={(id) => props.onSet(id ? { assetId: id } : null)}
                    scopeKey={`${target.part.slot}:${target.breakpoint}`}
                />
                <p className="mt-1 text-[11px] text-muted">
                    {definition.baseOnly ? 'This background image applies to all screens.' : 'Uses the selected screen. Reset restores inheritance.'}
                </p>
            </div>
        );
    }

    if (definition.kind === 'enum' || definition.kind === 'font' || definition.kind === 'shadow') {
        const values =
            definition.kind === 'enum' ? Object.keys(definition.values ?? {}) : Object.keys(definition.kind === 'font' ? styleRules.fonts : styleRules.shadows);
        const labels = OPTION_LABELS[props.property] ?? {};
        return (
            <select
                id={props.id}
                className="ui-input"
                disabled={props.disabled}
                value={typeof props.own === 'string' ? props.own : ''}
                onChange={(e) => props.onSet(e.target.value === '' ? null : e.target.value)}
            >
                <option value="">{props.inherited !== undefined ? `Inherited: ${labels[props.inherited as string] ?? props.inherited}` : 'Default'}</option>
                {tokens.length > 0 && (
                    <optgroup label="Site tokens">
                        {tokens.map(([value, text]) => (
                            <option key={value} value={value}>
                                {text}
                            </option>
                        ))}
                    </optgroup>
                )}
                <optgroup label="Values">
                    {values.map((value) => (
                        <option key={value} value={value}>
                            {labels[value] ?? value}
                        </option>
                    ))}
                </optgroup>
            </select>
        );
    }

    if (definition.kind === 'length') return <LengthInput {...props} tokens={tokens} />;
    return <FreeInput {...props} tokens={tokens} />;
}

/** A number with a unit selector (px, %, rem …), or a keyword such as Auto, or a site token. */
function LengthInput(props: ValueInputProps & { tokens: [string, string][] }) {
    const { definition } = props;
    const units = unitsOf(definition);
    const applied = typeof props.own === 'string' ? props.own : '';
    const parts = applied ? splitLength(applied, units) : null;
    const inheritedParts = typeof props.inherited === 'string' ? splitLength(props.inherited, units) : null;
    const appliedUnit = parts?.kind === 'number' ? parts.unit : parts?.kind === 'keyword' ? parts.keyword : parts?.kind === 'token' ? parts.token : '';
    const [typed, setTyped] = useState(parts?.kind === 'number' ? parts.number : '');
    const [unit, setUnit] = useState(appliedUnit || (inheritedParts?.kind === 'number' ? inheritedParts.unit : (units[0] ?? 'px')));
    const [error, setError] = useState<string | null>(null);
    // A new applied value (undo, reset, another screen) replaces what is shown.
    useEffect(() => {
        const next = applied ? splitLength(applied, units) : null;
        setTyped(next?.kind === 'number' ? next.number : '');
        if (next) setUnit(next.kind === 'number' ? next.unit : next.kind === 'keyword' ? next.keyword : next.token);
        setError(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [applied]);

    const keywords = definition.keywords ?? [];
    const isNumberUnit = units.includes(unit);
    const apply = (value: string) => {
        if (value === '') {
            setError(null);
            if (applied !== '') props.onSet(null);
            return;
        }
        const problem = styleValueProblem(definition, value);
        setError(problem);
        if (problem === null && value !== applied) props.onSet(value);
    };
    const placeholder = typeof props.inherited === 'string' ? props.inherited : keywords.includes('auto') ? 'Auto' : 'Default';

    return (
        <div>
            <div className="flex gap-1">
                <input
                    id={props.id}
                    className="ui-input flex-1 tabular-nums"
                    inputMode="decimal"
                    disabled={props.disabled || !isNumberUnit}
                    value={isNumberUnit ? typed : ''}
                    placeholder={isNumberUnit ? placeholder : keywords.includes(unit) ? unit.charAt(0).toUpperCase() + unit.slice(1) : unit}
                    aria-invalid={error !== null}
                    onChange={(e) => {
                        setTyped(e.target.value);
                        apply(composeLength(e.target.value, unit));
                    }}
                    onBlur={() => {
                        // An invalid value is never applied: show the applied one again.
                        if (error) {
                            setTyped(parts?.kind === 'number' ? parts.number : '');
                            setError(null);
                        }
                    }}
                />
                <select
                    aria-label={`${props.name} unit`}
                    className="ui-input w-[5.25rem] shrink-0"
                    disabled={props.disabled}
                    value={unit}
                    onChange={(e) => {
                        const next = e.target.value;
                        setUnit(next);
                        if (units.includes(next)) {
                            if (typed.trim() !== '') apply(composeLength(typed, next));
                        } else apply(next);
                    }}
                >
                    <optgroup label="Units">
                        {units.map((u) => (
                            <option key={u} value={u}>
                                {u}
                            </option>
                        ))}
                    </optgroup>
                    {keywords.length > 0 && (
                        <optgroup label="Keywords">
                            {keywords.map((k) => (
                                <option key={k} value={k}>
                                    {k.charAt(0).toUpperCase() + k.slice(1)}
                                </option>
                            ))}
                        </optgroup>
                    )}
                    {props.tokens.length > 0 && (
                        <optgroup label="Site tokens">
                            {props.tokens.map(([value, text]) => (
                                <option key={value} value={value}>
                                    {text}
                                </option>
                            ))}
                        </optgroup>
                    )}
                </select>
            </div>
            {error && (
                <p role="alert" className="mt-1 text-[11px] text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

/** Colours, numbers, ratios and column templates: typed, applied only when valid. */
function FreeInput(props: ValueInputProps & { tokens: [string, string][] }) {
    const listId = useId();
    const applied = typeof props.own === 'string' ? props.own : '';
    const [draft, setDraft] = useState(applied);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        setDraft(applied);
        setError(null);
    }, [applied]);
    const change = (value: string) => {
        setDraft(value);
        const trimmed = value.trim();
        if (trimmed === '') {
            setError(null);
            if (applied !== '') props.onSet(null);
            return;
        }
        const problem = styleValueProblem(props.definition, trimmed) ?? props.check?.(trimmed) ?? null;
        setError(problem);
        if (problem === null && trimmed !== applied) props.onSet(trimmed);
    };
    const swatch =
        props.definition.kind === 'color' ? colorOf(draft || (typeof props.inherited === 'string' ? props.inherited : ''), props.target.tokens) : null;
    const placeholder = typeof props.inherited === 'string' ? props.inherited : expectedValues(props.definition).split(' or ')[0];
    const suggestions = [...new Set([...(props.definition.keywords ?? []), ...(SUGGESTIONS[props.property] ?? []), ...props.tokens.map(([value]) => value)])];

    return (
        <div>
            <div className="flex items-center gap-1">
                {props.definition.kind === 'color' && (
                    <input
                        type="color"
                        aria-label={`${props.name} picker`}
                        disabled={props.disabled}
                        value={swatch && /^#[0-9a-f]{6}$/i.test(swatch) ? swatch : '#000000'}
                        onChange={(e) => change(e.target.value)}
                        className="h-8 w-9 shrink-0 cursor-pointer rounded-md border border-line-strong bg-surface p-0.5"
                    />
                )}
                <input
                    id={props.id}
                    list={listId}
                    className="ui-input"
                    disabled={props.disabled}
                    value={draft}
                    placeholder={placeholder}
                    aria-invalid={error !== null}
                    onChange={(e) => change(e.target.value)}
                    onBlur={() => {
                        if (error) {
                            setDraft(applied);
                            setError(null);
                        }
                    }}
                />
            </div>
            <datalist id={listId}>
                {suggestions.map((value) => (
                    <option key={value} value={value}>
                        {Object.fromEntries(props.tokens)[value]}
                    </option>
                ))}
            </datalist>
            {error && (
                <p role="alert" className="mt-1 text-[11px] text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

/** A colour value as a hex for the picker (tokens resolved with the site's values). */
function colorOf(value: string, tokens: TokenSet): string | null {
    if (value.startsWith('@color.')) return tokens.color?.[value.slice(7)] ?? null;
    if (/^#[0-9a-f]{3}$/i.test(value)) return `#${[...value.slice(1)].map((c) => c + c).join('')}`;
    if (/^#[0-9a-f]{6}/i.test(value)) return value.slice(0, 7);
    return null;
}

/**
 * The remaining design properties of the part, grouped (layout, spacing, border …) and
 * folded away until opened. Properties shown elsewhere in the inspector are left out, so
 * no setting has two independent controls.
 */
export function StyleGroups({ target, exclude = [], title = 'More design settings' }: { target: StyleTarget; exclude?: string[]; title?: string }) {
    const style = styleOf(target.props);
    const slot = target.field.slots[target.part.slot];
    if (!slot) return null;
    // Animation has its own section (AnimationPanel), not generic controls.
    const allowed = allowedProperties(slot).filter((key) => !exclude.includes(key) && styleRules.properties[key]?.group !== 'motion');
    const groups = Object.entries(styleRules.groups).filter(([group]) => allowed.some((key) => styleRules.properties[key]?.group === group));
    // Animation settings have their own section and Reset; this one counts and resets the others.
    const isMotion = (key: string) => styleRules.properties[key]?.group === 'motion';
    const own = Object.keys(style[target.part.slot]?.[target.breakpoint] ?? {});
    const overrides = own.filter((key) => !isMotion(key)).length;
    const resetOthers = () =>
        own.filter((key) => !isMotion(key)).reduce<Style>((next, key) => withStyleValue(next, target.part.slot, target.breakpoint, key, null), style);
    if (groups.length === 0 && overrides === 0) return null;

    return (
        <div data-testid="style-groups">
            {groups.length > 0 && (
                <p className="border-t border-line px-4 pt-3 pb-1 text-[11px] font-medium text-faint">
                    {title} · {target.part.label}
                </p>
            )}
            {groups.map(([group, label]) => {
                const keys = allowed.filter((key) => styleRules.properties[key]?.group === group);
                const set = keys.filter((key) => effectiveValue(style, target.part.slot, key, target.breakpoint).from !== null).length;
                return (
                    <PanelSection
                        key={`${target.part.slot}:${group}`}
                        title={label}
                        collapsible
                        defaultOpen={set > 0}
                        aside={set > 0 ? <span className="text-[11px] font-normal text-muted tabular-nums">{set} set</span> : undefined}
                    >
                        {keys.map((key) => (
                            <StyleControl
                                key={`${target.part.slot}:${target.breakpoint}:${key}`}
                                target={target}
                                property={key}
                                // Sizes are named for the part ("Section width"); other properties keep their own name.
                                label={group === 'size' ? undefined : styleRules.properties[key]!.label}
                            />
                        ))}
                    </PanelSection>
                );
            })}
            {overrides > 0 && (
                <div className="border-t border-line px-4 py-3">
                    <button
                        type="button"
                        disabled={!target.canEdit}
                        onClick={() => target.onStyle(resetOthers())}
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-danger hover:underline disabled:opacity-50"
                    >
                        <Icon name="reset" className="size-3.5" />
                        Reset {overrides} value{overrides === 1 ? '' : 's'} of the {target.part.label.toLowerCase()} on {SCREEN_NOUN[target.breakpoint]}
                    </button>
                </div>
            )}
        </div>
    );
}
