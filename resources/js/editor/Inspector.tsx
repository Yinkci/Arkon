import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { currentDefinition } from '@/arkon/components/registry';
import { componentName, partsOf, resolvePart, styleFieldOf, type Part } from '@/arkon/editor/parts';
import { matches, message } from '@/arkon/rules';
import { canContain, createNodes, insertOps } from '@/arkon/editor/structure';
import { widthIssues } from '@/arkon/components/validate';
import { blocksBeyond, createColumns, MAX_COLUMNS, proportionsFor, setColumnCount } from '@/arkon/editor/columns';
import { findParent, type Node, type NodeId, type PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import { BREAKPOINT_LABEL, effectiveValue, styleOf, withStyleValue } from '@/arkon/style/edit';
import type { Breakpoint, Style } from '@/arkon/style/schema';
import type { TokenSet } from '@/arkon/style/tokens';
import { Icon, type IconName } from '@/Components/Icon';
import { Button, PanelSection, Segmented } from '@/Components/ui';
import type { MediaInfo, ReusableComponentInfo } from '@/types';
import { ScreenBar, StyleControl, StyleGroups, type StyleTarget } from './DesignPanel';
import { MediaPicker } from './MediaPicker';
import { AnimationSection, canAnimate, type Protection } from './AnimationPanel';
import { ColumnsPicker, LayoutGlyph } from './ColumnsPicker';

/**
 * A field the user typed into that can't be applied yet (e.g. a half-typed link). It is
 * kept by the editor, not the document: the document only ever holds valid values.
 */
export interface UnresolvedField {
    nodeId: string;
    prop: string;
    /** For messages: "Button link". */
    label: string;
    value: string;
    error: string;
}

type Image = { assetId: string; alt: string } | null;

export interface InspectorProps {
    document: PageDocument;
    selected: Node | null;
    /** The part (style slot) of the selected component being edited; null = its default part. */
    part: string | null;
    onSelectPart(nodeId: NodeId, part: string | null): void;
    /** Selects another component (breadcrumb), or the page (null). */
    onSelectNode(nodeId: NodeId | null): void;
    media: MediaInfo[];
    canEdit: boolean;
    canUpload: boolean;
    onChange(ops: PageOperation[], coalesceKey?: string): void;
    onUpload(file: File): Promise<MediaInfo | null>;
    /** Unresolved field input by `${nodeId}:${prop}`; survives selection changes. */
    unresolved: Record<string, UnresolvedField>;
    onUnresolved(key: string, field: UnresolvedField | null): void;
    /** The screen size design values are edited for (follows the canvas viewport). */
    breakpoint: Breakpoint;
    onBreakpoint(breakpoint: Breakpoint): void;
    /** The site's published design tokens (resolved), for token pickers. */
    tokens: TokenSet;
    /** Published reusable components of the site. */
    components: ReusableComponentInfo[];
    /** Replaces an instance with a copy of its component's blocks (one undo step). */
    onDetach?(nodeId: string): void;
    /** Structural actions for the selected component (move, remove, make reusable). */
    toolbar?: ReactNode;
    /** Shown when the page itself is selected: title and URL. */
    pageSettings?: ReactNode;
    /** What the page document is called in the breadcrumb ("Page", or "Component" in the component editor). */
    rootName?: string;
    /** Blocks the page keeps still (they hold its likely LCP: an image or the main heading), by node id, from the last canvas render. */
    motion?: { protected?: Record<string, Protection> };
    /** Plays a block's entrance animation once on the canvas (after the render showing its settings). */
    onReplay?(nodeId: string): void;
}

type NodeInspectorProps = Omit<InspectorProps, 'part'> & {
    node: Node;
    part: Part;
    set(values: Record<string, unknown>, key?: string): void;
    style: StyleTarget | null;
    goToPart(slot: string): void;
};

const ICONS: Record<string, IconName> = {
    hero: 'home',
    text: 'text',
    image: 'image',
    button: 'link',
    columns: 'layers',
    column: 'layers',
    section: 'layers',
    group: 'layers',
    instance: 'component',
};

/** Ancestors of a node, outermost first (the page root excluded). */
function ancestorsOf(doc: PageDocument, nodeId: NodeId): Node[] {
    const out: Node[] = [];
    let location = findParent(doc, nodeId);
    while (location && location.parentId !== doc.root) {
        const parent = doc.nodes[location.parentId];
        if (!parent) break;
        out.unshift(parent);
        location = findParent(doc, parent.id);
    }
    return out;
}

export function Inspector(props: InspectorProps) {
    const node = props.selected;
    if (!node) return <PageInspector {...props} />;
    const part = resolvePart(node, props.part);
    const field = styleFieldOf(node.type);
    const set = (values: Record<string, unknown>, key?: string) =>
        props.onChange([{ op: 'updateProps', nodeId: node.id, set: values }], key && `${node.id}:${key}`);
    const style: StyleTarget | null = field
        ? {
              props: node.props,
              field,
              part,
              breakpoint: props.breakpoint,
              tokens: props.tokens,
              media: props.media,
              canEdit: props.canEdit,
              onStyle: (next: Style, key?: string) => set({ style: next }, key),
          }
        : null;
    const Body = BODIES[node.type] ?? GenericBody;
    // Keyed by node and part: switching never carries one target's local field state into another.
    return (
        <div key={`${node.id}:${part.slot}`} className="pb-8">
            <TargetHeader {...props} node={node} part={part} />
            {props.toolbar}
            <div data-testid="design-panel">
                <Body {...props} node={node} part={part} set={set} style={style} goToPart={(slot) => props.onSelectPart(node.id, slot)} />
                {canAnimate(field) && (
                    <AnimationSection
                        document={props.document}
                        node={node}
                        canEdit={props.canEdit}
                        breakpoint={props.breakpoint}
                        onStyle={(next, key) => set({ style: next }, key)}
                        onChange={(ops) => props.onChange(ops)}
                        protection={props.motion?.protected?.[node.id]}
                        protectedNodes={props.motion?.protected}
                        inComponent={props.rootName === 'Component'}
                        onPreview={props.onReplay}
                        onSelectNode={(id) => props.onSelectNode(id)}
                    />
                )}
            </div>
        </div>
    );
}

/** Which component is selected, where it sits, and which of its parts is being edited. */
function TargetHeader({ document: doc, node, part, onSelectNode, onSelectPart, rootName = 'Page' }: Omit<InspectorProps, 'part'> & { node: Node; part: Part }) {
    const parts = partsOf(node);
    const name = componentName(node);
    const ancestors = ancestorsOf(doc, node.id);
    const atRoot = part.slot === 'root';
    return (
        <div className="sticky top-0 z-10 border-b border-line bg-surface/95 px-4 pt-3 pb-3 backdrop-blur-sm" data-testid="inspector-target-header">
            <nav aria-label="Selection path">
                <ol className="flex flex-wrap items-center gap-0.5 text-[11px] text-muted">
                    <li>
                        <button
                            type="button"
                            onClick={() => onSelectNode(null)}
                            aria-label={`${rootName} settings`}
                            className="rounded px-1 py-0.5 hover:bg-sunken hover:text-fg"
                        >
                            {rootName}
                        </button>
                    </li>
                    {ancestors.map((ancestor) => (
                        <li key={ancestor.id} className="flex items-center gap-0.5">
                            <Icon name="chevronRight" className="size-3 text-faint" />
                            <button
                                type="button"
                                onClick={() => onSelectNode(ancestor.id)}
                                className="max-w-[9rem] truncate rounded px-1 py-0.5 hover:bg-sunken hover:text-fg"
                            >
                                {componentName(ancestor)}
                            </button>
                        </li>
                    ))}
                    <li className="flex items-center gap-0.5">
                        <Icon name="chevronRight" className="size-3 text-faint" />
                        {atRoot ? (
                            <span aria-current="location" className="px-1 py-0.5 font-medium text-fg">
                                {name}
                            </span>
                        ) : (
                            <button
                                type="button"
                                onClick={() => onSelectPart(node.id, 'root')}
                                data-testid="inspector-target-parent"
                                className="rounded px-1 py-0.5 font-medium text-fg hover:bg-sunken"
                                title={`Edit the ${name.toLowerCase()} itself`}
                            >
                                {name}
                            </button>
                        )}
                    </li>
                </ol>
            </nav>
            <div className="mt-2 flex items-center gap-2.5">
                <span className="grid size-8 shrink-0 place-items-center rounded-md bg-accent-soft text-accent">
                    <Icon name={!atRoot && part.slot === 'media' ? 'image' : (ICONS[node.type] ?? 'component')} />
                </span>
                <div className="min-w-0">
                    <h2 className="truncate text-sm font-semibold" data-testid="inspector-target">
                        {part.label}
                    </h2>
                    <p className="truncate text-[11px] text-muted">{atRoot ? describe(node) : `Part of the ${name.charAt(0).toLowerCase()}${name.slice(1)}`}</p>
                </div>
            </div>
            {parts.length > 1 && (
                <div role="group" aria-label={`Parts of the ${name.toLowerCase()}`} className="mt-3 flex flex-wrap gap-1">
                    {parts.map((p) => (
                        <button
                            key={p.slot}
                            type="button"
                            aria-pressed={p.slot === part.slot}
                            onClick={() => onSelectPart(node.id, p.slot)}
                            data-testid={`part-${p.slot}`}
                            className={`h-6 rounded-full border px-2 text-[11px] font-medium transition-colors ${
                                p.slot === part.slot
                                    ? 'border-accent bg-accent text-accent-fg'
                                    : 'border-line bg-surface text-muted hover:border-faint hover:text-fg'
                            }`}
                        >
                            {p.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function describe(node: Node): string {
    return (
        {
            hero: 'Heading, text, buttons and an optional image',
            section: 'A full-width band; content stays within the content width',
            group: 'Arranges blocks in a stack, row or grid',
            columns: 'Side-by-side columns that stack on small screens',
            column: 'One column of a Columns block',
            text: 'A paragraph or heading',
            image: 'An image with an optional caption',
            button: 'A link styled as a button',
            instance: 'Shows a reusable component',
        }[node.type] ??
        currentDefinition(node.type)?.label ??
        node.type
    );
}

// ── Building blocks ─────────────────────────────────────────────────────────

function TextField(props: {
    label: string;
    value: string;
    max: number;
    disabled: boolean;
    rows?: number;
    onChange(value: string): void;
    placeholder?: string;
    hint?: string;
}) {
    const id = useId();
    const Tag = props.rows ? 'textarea' : 'input';
    return (
        <div>
            <label htmlFor={id} className="ui-label">
                {props.label}
            </label>
            <Tag
                id={id}
                className="ui-input"
                rows={props.rows}
                disabled={props.disabled}
                value={props.value}
                maxLength={props.max}
                placeholder={props.placeholder}
                onChange={(e) => props.onChange(e.target.value)}
            />
            {props.hint && <p className="mt-1 text-[11px] text-muted">{props.hint}</p>}
        </div>
    );
}

function SelectField<T extends string>(props: { label: string; value: T; options: [T, string][]; disabled: boolean; onChange(value: T): void; hint?: string }) {
    const id = useId();
    return (
        <div>
            <label htmlFor={id} className="ui-label">
                {props.label}
            </label>
            <select id={id} className="ui-input" disabled={props.disabled} value={props.value} onChange={(e) => props.onChange(e.target.value as T)}>
                {props.options.map(([value, text]) => (
                    <option key={value} value={value}>
                        {text}
                    </option>
                ))}
            </select>
            {props.hint && <p className="mt-1 text-[11px] text-muted">{props.hint}</p>}
        </div>
    );
}

/**
 * One design setting with friendly choices (e.g. where a hero's image goes), edited for the
 * screen size being designed, with the same inherited / default / Reset behaviour as every
 * other design control.
 */
function QuickChoice(props: { style: StyleTarget; label: string; property: string; options: [string, string][]; testId?: string; hint?: string }) {
    const id = useId();
    const { style } = props;
    const current = styleOf(style.props);
    const effective = effectiveValue(current, style.part.slot, props.property, style.breakpoint);
    const own = effective.from === style.breakpoint ? String(effective.value) : '';
    const inherited = effective.from !== null && effective.from !== style.breakpoint ? String(effective.value) : null;
    const name = (value: string | null) => props.options.find(([v]) => v === value)?.[1] ?? value;
    const setValue = (value: string | null) =>
        style.onStyle(
            withStyleValue(current, style.part.slot, style.breakpoint, props.property, value),
            value === null ? undefined : `style.${style.part.slot}.${style.breakpoint}.${props.property}`,
        );
    return (
        <div>
            <div className="mb-1 flex items-center gap-1.5">
                <label htmlFor={id} className={`min-w-0 flex-1 text-xs ${own ? 'font-semibold text-fg' : 'font-medium text-muted'}`}>
                    {props.label}
                </label>
                {own && style.breakpoint !== 'base' && (
                    <span className="rounded-full bg-accent-soft px-1.5 text-[10px] font-medium text-accent">
                        {style.breakpoint === 'tablet' ? 'Tablet' : 'Mobile'} override
                    </span>
                )}
                {own && (
                    <button
                        type="button"
                        disabled={!style.canEdit}
                        onClick={() => setValue(null)}
                        aria-label={`Reset ${props.label} on ${BREAKPOINT_LABEL[style.breakpoint].toLowerCase()}`}
                        className="inline-flex items-center gap-0.5 rounded px-1 text-[11px] text-muted hover:bg-sunken hover:text-fg"
                    >
                        <Icon name="reset" className="size-3" />
                        Reset
                    </button>
                )}
            </div>
            <select
                id={id}
                data-testid={props.testId}
                className="ui-input"
                disabled={!style.canEdit}
                value={own}
                onChange={(e) => setValue(e.target.value === '' ? null : e.target.value)}
            >
                <option value="">{inherited !== null ? `Inherited: ${name(inherited)}` : 'Default'}</option>
                {props.options.map(([value, text]) => (
                    <option key={value} value={value}>
                        {text}
                    </option>
                ))}
            </select>
            {props.hint && <p className="mt-1 text-[11px] leading-snug text-muted">{props.hint}</p>}
        </div>
    );
}

/** "Add text / image / …" inside a container. Columns first ask for their layout. */
function AddInside({ node, canEdit, onChange, types }: Pick<NodeInspectorProps, 'node' | 'canEdit' | 'onChange'> & { types: string[] }) {
    const [picking, setPicking] = useState(false);
    const at = { parentId: node.id, index: node.children?.length ?? 0 };
    return (
        <div>
            <p className="ui-label">Add inside</p>
            <div className="flex flex-wrap gap-1.5">
                {types.map((type) => (
                    <Button
                        key={type}
                        size="sm"
                        icon={type === 'columns' ? 'columns' : 'plus'}
                        disabled={!canEdit || !canContain(node, type)}
                        onClick={() => (type === 'columns' ? setPicking((open) => !open) : onChange(insertOps(at, createNodes(type))))}
                        aria-expanded={type === 'columns' ? picking : undefined}
                        data-testid={`add-inside-${type}`}
                    >
                        {currentDefinition(type)?.label ?? type}
                    </Button>
                ))}
            </div>
            {picking && (
                <ColumnsPicker
                    disabled={!canEdit || !canContain(node, 'columns')}
                    onCancel={() => setPicking(false)}
                    onPick={(preset) => {
                        setPicking(false);
                        onChange(insertOps(at, createColumns(preset.count, preset.tracks)));
                    }}
                />
            )}
        </div>
    );
}

/** The screen-size switch, then the given sections, then everything else the part can style. */
function DesignArea({ props, children, exclude = [] }: { props: NodeInspectorProps; children?: ReactNode; exclude?: string[] }) {
    if (!props.style) return null;
    return (
        <>
            <ScreenBar props={props.node.props} breakpoint={props.breakpoint} onBreakpoint={props.onBreakpoint} slot={props.part.slot} />
            {children}
            <StyleGroups target={props.style} exclude={exclude} />
        </>
    );
}

/** Size controls named for the part ("Image height", "Section width"). */
function SizeSection({
    style,
    properties,
    title,
    hint,
    testId,
}: {
    style: StyleTarget;
    properties: string[];
    title: string;
    hint?: ReactNode;
    testId: string;
}) {
    return (
        <PanelSection title={title} data-testid={testId}>
            {properties.map((property) => (
                <StyleControl key={`${style.breakpoint}:${property}`} target={style} property={property} />
            ))}
            {hint && <p className="text-[11px] leading-snug text-muted">{hint}</p>}
        </PanelSection>
    );
}

const COVER_HINT =
    'Fill and crop (cover) only crops when the image box has a set height or aspect ratio; otherwise the box takes the image’s own shape and nothing is cut.';

/** The image's own controls: which image, its description, then its size, fit and crop. */
function ImagePartBody(props: NodeInspectorProps & { prop: string; extra?: ReactNode; sizes: string[] }) {
    const image = (props.node.props[props.prop] as Image) ?? null;
    const { set } = props;
    return (
        <>
            <PanelSection title="Image">
                <MediaPicker
                    value={image?.assetId ?? null}
                    media={props.media}
                    canEdit={props.canEdit}
                    canUpload={props.canUpload}
                    onChoose={(assetId) => set({ [props.prop]: assetId ? { assetId, alt: image?.alt ?? '' } : null })}
                    onUpload={props.onUpload}
                />
                {image && (
                    <TextField
                        label="Alternative text (required to publish)"
                        value={image.alt}
                        max={300}
                        disabled={!props.canEdit}
                        placeholder="Describe the image for people who can't see it"
                        onChange={(v) => set({ [props.prop]: { ...image, alt: v } }, 'alt')}
                    />
                )}
                {props.extra}
            </PanelSection>
            {props.style && (
                <DesignArea props={props} exclude={[...props.sizes, 'objectFit', 'objectPosition']}>
                    <SizeSection
                        style={props.style}
                        title="Image size"
                        testId="image-sizing"
                        properties={props.sizes}
                        hint="Sizes apply to the image only, not to the block or section around it."
                    />
                    <PanelSection title="Fit and crop" data-testid="image-fit">
                        <StyleControl target={props.style} property="objectFit" label="Fit" hint={COVER_HINT} />
                        <StyleControl target={props.style} property="objectPosition" label="Crop position" />
                    </PanelSection>
                </DesignArea>
            )}
        </>
    );
}

// ── Per component ───────────────────────────────────────────────────────────

function HeroBody(props: NodeInspectorProps) {
    const p = props.node.props;
    const { canEdit, set, style, part } = props;
    const image = (p.image as Image) ?? null;
    if (part.slot === 'media') return <ImagePartBody {...props} prop="image" sizes={['width', 'height', 'aspectRatio']} />;
    if (part.slot === 'heading') {
        return (
            <>
                <PanelSection title="Heading">
                    <TextField
                        label="Heading"
                        value={String(p.heading ?? '')}
                        max={160}
                        disabled={!canEdit}
                        onChange={(v) => set({ heading: v }, 'heading')}
                        hint="You can also type on the canvas."
                    />
                    <HeadingLevel {...props} />
                </PanelSection>
                <DesignArea props={props} />
            </>
        );
    }
    if (part.slot === 'text') {
        return (
            <>
                <PanelSection title="Supporting text">
                    <TextField label="Text" rows={4} value={String(p.text ?? '')} max={600} disabled={!canEdit} onChange={(v) => set({ text: v }, 'text')} />
                </PanelSection>
                <DesignArea props={props} />
            </>
        );
    }
    if (part.slot === 'actions') {
        return (
            <>
                <PanelSection title="Buttons">
                    <AddInside node={props.node} canEdit={canEdit} onChange={props.onChange} types={['button']} />
                    <p className="text-[11px] text-muted">Select a button on the canvas to edit its label and link.</p>
                </PanelSection>
                <DesignArea props={props} />
            </>
        );
    }
    if (part.slot === 'content') {
        return (
            <DesignArea props={props} exclude={['width', 'maxWidth']}>
                {style && (
                    <SizeSection
                        style={style}
                        title="Content area size"
                        testId="content-sizing"
                        properties={['width', 'maxWidth']}
                        hint="Side by side, a set width replaces the content area's equal share of the row."
                    />
                )}
            </DesignArea>
        );
    }
    return (
        <>
            <PanelSection title="Content">
                <TextField label="Heading" value={String(p.heading ?? '')} max={160} disabled={!canEdit} onChange={(v) => set({ heading: v }, 'heading')} />
                <HeadingLevel {...props} />
                <TextField label="Text" rows={3} value={String(p.text ?? '')} max={600} disabled={!canEdit} onChange={(v) => set({ text: v }, 'text')} />
                <div className="flex items-center gap-2 rounded-md border border-line bg-raised px-2.5 py-2 text-xs">
                    <Icon name="image" className="size-4 text-muted" />
                    <span className="min-w-0 flex-1 truncate text-muted">{image ? 'Has an image' : 'No image yet'}</span>
                    <Button size="sm" onClick={() => props.goToPart('media')} data-testid="edit-hero-image">
                        {image ? 'Edit image' : 'Add image'}
                    </Button>
                </div>
                <AddInside node={props.node} canEdit={canEdit} onChange={props.onChange} types={['button']} />
            </PanelSection>
            <DesignArea props={props} exclude={['direction', 'width', 'height', 'minHeight']}>
                {style && image && (
                    <PanelSection title="Image placement">
                        <QuickChoice
                            style={style}
                            label="Image position"
                            property="direction"
                            testId="hero-image-position"
                            options={[
                                ['row', 'Right of the text'],
                                ['row-reverse', 'Left of the text'],
                                ['column', 'Below the text'],
                                ['column-reverse', 'Above the text'],
                            ]}
                        />
                    </PanelSection>
                )}
                {style && (
                    <SizeSection
                        style={style}
                        title="Section size"
                        testId="section-sizing"
                        properties={['width', 'height', 'minHeight']}
                        hint="These size the whole hero section. To size the image, select the Image part."
                    />
                )}
            </DesignArea>
        </>
    );
}

function HeadingLevel({ node, canEdit, set }: NodeInspectorProps) {
    return (
        <SelectField
            label="Heading level"
            hint="For search engines and screen readers; the size is a design setting."
            value={(node.props.headingLevel as 'h1' | 'h2') ?? 'h1'}
            options={[
                ['h1', 'H1: main page heading'],
                ['h2', 'H2: section heading'],
            ]}
            disabled={!canEdit}
            onChange={(v) => set({ headingLevel: v })}
        />
    );
}

function ImageBody(props: NodeInspectorProps) {
    const p = props.node.props;
    const { canEdit, set, style, part } = props;
    if (part.slot === 'caption') {
        return (
            <>
                <PanelSection title="Caption">
                    <TextField label="Caption" value={String(p.caption ?? '')} max={300} disabled={!canEdit} onChange={(v) => set({ caption: v }, 'caption')} />
                </PanelSection>
                <DesignArea props={props} />
            </>
        );
    }
    if (part.slot === 'root') {
        return (
            <>
                <PanelSection title="Image block">
                    <TextField label="Caption" value={String(p.caption ?? '')} max={300} disabled={!canEdit} onChange={(v) => set({ caption: v }, 'caption')} />
                    <LoadingField {...props} />
                </PanelSection>
                <DesignArea props={props} exclude={['width', 'maxWidth']}>
                    {style && (
                        <SizeSection
                            style={style}
                            title="Block size"
                            testId="block-sizing"
                            properties={['width', 'maxWidth']}
                            hint="Sizes the block (image and caption). To size the image itself, select the Image part."
                        />
                    )}
                </DesignArea>
            </>
        );
    }
    return <ImagePartBody {...props} prop="image" sizes={['width', 'height', 'aspectRatio']} extra={<LoadingField {...props} />} />;
}

function LoadingField({ node, canEdit, set }: NodeInspectorProps) {
    return (
        <SelectField
            label="Loading"
            value={(node.props.loading as string) ?? 'auto'}
            options={[
                ['auto', 'Automatic (first image on the page loads first)'],
                ['eager', 'Load early (important, near the top)'],
                ['lazy', 'Load when scrolled to'],
            ]}
            disabled={!canEdit}
            onChange={(v) => set({ loading: v })}
        />
    );
}

function TextBody(props: NodeInspectorProps) {
    const p = props.node.props;
    return (
        <>
            <PanelSection title="Text">
                <TextField
                    label="Text"
                    rows={6}
                    value={String(p.text ?? '')}
                    max={5000}
                    disabled={!props.canEdit}
                    onChange={(v) => props.set({ text: v }, 'text')}
                />
                <SelectField
                    label="Kind"
                    value={(p.element as string) ?? 'p'}
                    options={[
                        ['p', 'Paragraph'],
                        ['h1', 'Main heading (H1)'],
                        ['h2', 'Heading (H2)'],
                        ['h3', 'Subheading (H3)'],
                        ['h4', 'Minor heading (H4)'],
                    ]}
                    disabled={!props.canEdit}
                    onChange={(v) => props.set({ element: v })}
                />
            </PanelSection>
            <DesignArea props={props} />
        </>
    );
}

function ButtonBody(props: NodeInspectorProps) {
    const { node, canEdit, set, unresolved, onUnresolved, part } = props;
    const p = node.props;
    const linkId = useId();
    const tabId = useId();
    if (part.slot === 'root') return <DesignArea props={props} />;
    // A link is applied only when it is safe, so typing "https://…" letter by letter never
    // produces an invalid document. Until then the typed text is held by the editor as an
    // unresolved field: shown here, flagged in the save status, and blocking Preview/Publish.
    const key = `${node.id}:href`;
    const applied = String(p.href ?? '');
    const pending = unresolved[key];
    const link = pending?.value ?? applied;
    const linkError = pending?.error ?? null;
    const editLink = (value: string) => {
        const error = value.length > 2000 ? message('tooLong', { max: 2000 }) : matches('link', value) ? null : message('unsafeLink');
        if (error) {
            onUnresolved(key, { nodeId: node.id, prop: 'href', label: 'Button link', value, error });
            return;
        }
        onUnresolved(key, null);
        if (value !== applied) set({ href: value }, 'href');
    };
    return (
        <>
            <PanelSection title="Button">
                <TextField label="Label" value={String(p.label ?? '')} max={80} disabled={!canEdit} onChange={(v) => set({ label: v }, 'label')} />
                <div>
                    <label htmlFor={linkId} className="ui-label">
                        Link
                    </label>
                    <input
                        id={linkId}
                        className="ui-input"
                        disabled={!canEdit}
                        value={link}
                        placeholder="/contact or https://example.com"
                        aria-invalid={linkError !== null}
                        aria-describedby={`${linkId}-hint`}
                        data-unresolved-field={key}
                        onChange={(e) => editLink(e.target.value)}
                    />
                    <p id={`${linkId}-hint`} role={linkError ? 'alert' : undefined} className={`mt-1 text-[11px] ${linkError ? 'text-danger' : 'text-muted'}`}>
                        {linkError ?? 'A page on this site (/about), a section (#top), or a full web, email or phone link.'}
                    </p>
                    {pending && (
                        <div
                            className="mt-1.5 flex items-start gap-2 rounded-md border border-danger/30 bg-danger-soft px-2.5 py-2 text-[11px]"
                            data-testid="unresolved-link"
                        >
                            <Icon name="alert" className="mt-px size-3.5 text-danger" />
                            <span className="min-w-0 flex-1">
                                <strong className="font-semibold">Not saved.</strong> The button still links to{' '}
                                <code className="rounded bg-surface px-1 break-all">{applied || '(no link)'}</code>.
                            </span>
                            <Button size="sm" onClick={() => onUnresolved(key, null)}>
                                Revert link
                            </Button>
                        </div>
                    )}
                </div>
                <SelectField
                    label="Appearance"
                    value={(p.variant as string) ?? 'primary'}
                    options={[
                        ['primary', 'Primary (filled)'],
                        ['secondary', 'Secondary (outline)'],
                        ['text', 'Text link'],
                    ]}
                    disabled={!canEdit}
                    onChange={(v) => set({ variant: v })}
                />
                <SelectField
                    label="Size"
                    value={(p.size as string) ?? 'medium'}
                    options={[
                        ['small', 'Small'],
                        ['medium', 'Medium'],
                        ['large', 'Large'],
                    ]}
                    disabled={!canEdit}
                    onChange={(v) => set({ size: v })}
                />
                <label htmlFor={tabId} className="flex items-center gap-2 text-[0.8125rem]">
                    <input
                        id={tabId}
                        type="checkbox"
                        className="size-4 accent-[var(--ak-accent)]"
                        disabled={!canEdit}
                        checked={p.newTab === true}
                        onChange={(e) => set({ newTab: e.target.checked })}
                    />
                    Open in a new tab
                </label>
            </PanelSection>
            <DesignArea props={props} />
        </>
    );
}

function SectionBody(props: NodeInspectorProps) {
    const p = props.node.props;
    return (
        <>
            <PanelSection title="Section">
                <SelectField
                    label="Content width"
                    value={(p.contentWidth as string) ?? 'default'}
                    options={[
                        ['narrow', 'Narrow (text)'],
                        ['default', 'Default'],
                        ['wide', 'Wide'],
                        ['full', 'Full width'],
                    ]}
                    disabled={!props.canEdit}
                    onChange={(v) => props.set({ contentWidth: v })}
                />
                <ElementField
                    {...props}
                    options={[
                        ['section', 'Section'],
                        ['div', 'Plain container'],
                        ['header', 'Header'],
                        ['footer', 'Footer'],
                        ['aside', 'Aside'],
                    ]}
                    fallback="section"
                />
                <AddInside
                    node={props.node}
                    canEdit={props.canEdit}
                    onChange={props.onChange}
                    types={['hero', 'text', 'image', 'button', 'group', 'columns']}
                />
            </PanelSection>
            <DesignArea props={props} exclude={['width', 'height', 'minHeight']}>
                {props.style && <SizeSection style={props.style} title="Section size" testId="section-sizing" properties={['width', 'height', 'minHeight']} />}
            </DesignArea>
        </>
    );
}

function ElementField(props: NodeInspectorProps & { options: [string, string][]; fallback: string }) {
    return (
        <SelectField
            label="Element (for assistive technology)"
            value={(props.node.props.element as string) ?? props.fallback}
            options={props.options}
            disabled={!props.canEdit}
            onChange={(v) => props.set({ element: v })}
        />
    );
}

function GroupBody(props: NodeInspectorProps) {
    return (
        <>
            <PanelSection title="Group">
                <ElementField
                    {...props}
                    options={[
                        ['div', 'Plain container'],
                        ['section', 'Section'],
                        ['article', 'Article'],
                        ['aside', 'Aside'],
                    ]}
                    fallback="div"
                />
                <AddInside node={props.node} canEdit={props.canEdit} onChange={props.onChange} types={['text', 'image', 'button', 'group', 'columns']} />
            </PanelSection>
            <DesignArea props={props} exclude={['direction']}>
                {props.style && (
                    <PanelSection title="Arrangement">
                        <QuickChoice
                            style={props.style}
                            label="Arrange content"
                            property="direction"
                            testId="group-direction"
                            options={[
                                ['column', 'Stacked'],
                                ['row', 'Side by side'],
                                ['row-reverse', 'Side by side, reversed'],
                            ]}
                        />
                    </PanelSection>
                )}
            </DesignArea>
        </>
    );
}

/** How a Columns block lays out on a smaller screen: its own `columns` value there, named. */
function screenLayout(value: unknown, n: number): string {
    if (value === undefined) return 'inherit';
    if (value === '1') return 'stack';
    if (value === String(n)) return 'row';
    if (typeof value === 'string' && /^[2-5]$/.test(value) && Number(value) < n) return value;
    return 'custom';
}

function ColumnsBody(props: NodeInspectorProps) {
    const { node, document, canEdit, onChange } = props;
    const n = node.children?.length ?? 0;
    const [reduceTo, setReduceTo] = useState<number | null>(null);
    const [message, setMessage] = useState<{ tone: 'info' | 'error'; text: string } | null>(null);
    const style = styleOf(node.props);
    const base = style.root?.base?.columns;

    const change = (count: number, content: 'move' | 'delete' = 'move'): boolean => {
        const result = setColumnCount(document, node.id, count, content);
        if (!result.ok) {
            setMessage({ tone: 'error', text: result.reason });
            return false;
        }
        if (result.ops.length > 0) onChange(result.ops);
        setMessage(result.notice ? { tone: 'info', text: result.notice } : null);
        return true;
    };
    /** Fewer columns than blocks allow: ask what happens to the content first (nothing changes until then). */
    const choose = (count: number) => {
        if (count < n && blocksBeyond(document, node.id, count).length > 0) setReduceTo(count);
        else change(count);
    };
    const setScreen = (bp: 'base' | 'tablet' | 'mobile', value: string | null) => props.set({ style: withStyleValue(style, 'root', bp, 'columns', value) });
    const layoutOptions = (screen: 'tablet' | 'mobile'): [string, string][] => [
        ['inherit', screen === 'tablet' ? 'Same as all screens' : 'Same as tablets'],
        ['stack', 'Stacked, one under another'],
        ['row', `Side by side (${n} equal)`],
        ...Array.from({ length: Math.max(0, n - 2) }, (_, i) => [String(i + 2), `${i + 2} per row`] as [string, string]),
    ];
    const screenSelect = (screen: 'tablet' | 'mobile', label: string, hint: string) => {
        const value = screenLayout(style.root?.[screen]?.columns, n);
        return (
            <SelectField
                label={label}
                hint={hint}
                value={value}
                disabled={!canEdit}
                options={[
                    ...layoutOptions(screen),
                    ...(value === 'custom' ? ([['custom', `Custom (${String(style.root?.[screen]?.columns)})`]] as [string, string][]) : []),
                ]}
                onChange={(v) => v !== 'custom' && setScreen(screen, v === 'inherit' ? null : v === 'stack' ? '1' : v === 'row' ? String(n) : v)}
            />
        );
    };
    const proportions = proportionsFor(n);

    return (
        <>
            <PanelSection title="Columns" data-testid="columns-structure">
                <div>
                    <p className="ui-label">Number of columns</p>
                    <Segmented
                        label="Number of columns"
                        value={String(n)}
                        onChange={(v) => choose(Number(v))}
                        options={Array.from({ length: MAX_COLUMNS }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }))}
                    />
                    <p className="mt-1 text-[11px] leading-snug text-muted">
                        How many editable columns there are, on every screen. Adding keeps all content; removing columns that hold blocks asks first.
                    </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    <Button size="sm" icon="plus" disabled={!canEdit || n >= MAX_COLUMNS} onClick={() => choose(n + 1)}>
                        Add a column
                    </Button>
                    <Button size="sm" disabled={!canEdit || n <= 1} onClick={() => choose(n - 1)}>
                        Remove last column
                    </Button>
                </div>
                {message && (
                    <p
                        role="status"
                        data-testid="columns-message"
                        className={`text-[11px] leading-snug ${message.tone === 'error' ? 'text-danger' : 'text-muted'}`}
                    >
                        {message.text}
                    </p>
                )}
            </PanelSection>
            <PanelSection title="Widths" data-testid="columns-widths">
                <p className="text-[11px] leading-snug text-muted">How the columns share the width on larger screens.</p>
                <div className="flex flex-wrap gap-1.5" role="group" aria-label="Column widths">
                    {[
                        { id: 'equal', label: 'Equal', tracks: null as string | null },
                        ...proportions.map((p) => ({ id: p.id, label: p.label, tracks: p.tracks })),
                    ].map((option) => {
                        const active = option.tracks === null ? base === undefined || base === String(n) : base === option.tracks;
                        return (
                            <button
                                key={option.id}
                                type="button"
                                aria-pressed={active}
                                disabled={!canEdit || n < 2}
                                onClick={() => setScreen('base', option.tracks)}
                                title={option.label}
                                data-testid={`columns-width-${option.id.replaceAll(' ', '-')}`}
                                className={`flex h-12 w-16 flex-col items-center justify-center gap-1 rounded-md border text-[10px] ${
                                    active ? 'border-accent bg-accent-soft text-accent' : 'border-line bg-surface text-muted hover:border-faint hover:text-fg'
                                } disabled:opacity-40`}
                            >
                                <LayoutGlyph count={n} tracks={option.tracks} className="h-4 w-10" />
                                <span className="max-w-full truncate px-1">{option.label}</span>
                            </button>
                        );
                    })}
                </div>
                {props.style && n > 1 && (
                    <StyleControl
                        target={{ ...props.style, breakpoint: 'base' }}
                        property="columns"
                        label="Custom widths (all screens)"
                        hint={`For example ${['1fr', '2fr', '1fr', '1fr', '1fr', '1fr'].slice(0, n).join(' ')}: one value per column.`}
                        check={(value) =>
                            widthIssues({ id: node.id, type: node.type, props: { style: { root: { base: { columns: value } } } } }, 'Columns', n)[0]?.message ??
                            null
                        }
                    />
                )}
            </PanelSection>
            <PanelSection title="On smaller screens" data-testid="columns-responsive">
                {screenSelect('tablet', 'Tablets (899 px and narrower)', 'Changing this never adds or removes columns.')}
                {screenSelect('mobile', 'Phones (599 px and narrower)', 'Stacked by default, so each column gets the full width.')}
            </PanelSection>
            <DesignArea props={props} exclude={['columns']} />
            <ReduceColumnsDialog
                open={reduceTo !== null}
                count={reduceTo ?? n}
                columnsLeft={reduceTo ?? n}
                blocks={reduceTo === null ? 0 : blocksBeyond(document, node.id, reduceTo).length}
                error={message?.tone === 'error' ? message.text : null}
                onMove={() => reduceTo !== null && change(reduceTo, 'move') && setReduceTo(null)}
                onDelete={() => reduceTo !== null && change(reduceTo, 'delete') && setReduceTo(null)}
                onCancel={() => {
                    setReduceTo(null);
                    setMessage(null);
                }}
            />
        </>
    );
}

/**
 * Removing columns that hold blocks: never silent. Move the blocks to the last column that
 * stays (in order), delete them with the columns, or cancel (nothing changes). Either choice
 * is one undo step.
 */
function ReduceColumnsDialog(props: {
    open: boolean;
    count: number;
    columnsLeft: number;
    blocks: number;
    error: string | null;
    onMove(): void;
    onDelete(): void;
    onCancel(): void;
}) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (props.open && !dialog.open) dialog.showModal?.();
        if (!props.open && dialog.open) dialog.close();
    }, [props.open]);
    const blocks = `${props.blocks} block${props.blocks === 1 ? '' : 's'}`;
    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            data-testid="reduce-columns-dialog"
            onCancel={(event) => {
                event.preventDefault();
                props.onCancel();
            }}
            className="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-line bg-surface p-0 text-left text-fg shadow-pop backdrop:bg-black/45"
        >
            <div className="space-y-4 p-6">
                <h2 id={titleId} className="text-base font-semibold">
                    Keep {props.columnsLeft} column{props.columnsLeft === 1 ? '' : 's'}?
                </h2>
                <p className="text-sm text-muted">
                    The columns being removed hold {blocks}. Move them, in order, to the end of column {props.columnsLeft}, or delete them with the columns.
                    Undo brings everything back either way.
                </p>
                {props.error && (
                    <p role="alert" className="text-sm text-danger" data-testid="reduce-error">
                        {props.error}
                    </p>
                )}
                <div className="flex flex-wrap justify-end gap-2">
                    <Button onClick={props.onCancel} data-testid="reduce-cancel">
                        Cancel
                    </Button>
                    <Button variant="danger" onClick={props.onDelete} data-testid="reduce-delete">
                        Delete {blocks}
                    </Button>
                    <Button variant="primary" onClick={props.onMove} data-testid="reduce-move" autoFocus>
                        Move {blocks} to column {props.columnsLeft}
                    </Button>
                </div>
            </div>
        </dialog>
    );
}

function ColumnBody(props: NodeInspectorProps) {
    return (
        <>
            <PanelSection title="Column">
                <AddInside node={props.node} canEdit={props.canEdit} onChange={props.onChange} types={['text', 'image', 'button', 'group', 'columns']} />
            </PanelSection>
            <DesignArea props={props} />
        </>
    );
}

function InstanceBody(props: NodeInspectorProps) {
    const component = props.components.find((c) => c.id === props.node.props.componentId);
    return (
        <>
            <PanelSection title="Reusable component">
                <div className="flex items-center gap-2 rounded-md border border-site/25 bg-site-soft px-2.5 py-2 text-[0.8125rem]">
                    <Icon name="component" className="size-4 text-site" />
                    <span className="min-w-0 flex-1 truncate font-medium">{component ? component.name : 'Not published yet'}</span>
                    {component?.published && <span className="text-[11px] text-muted">v{component.published}</span>}
                </div>
                <p className="text-[11px] leading-snug text-muted">
                    Shows the component as last published. Editing and publishing the component updates every page that uses it, including live pages. Spacing
                    and size set here apply to this page only.
                </p>
                <div className="flex flex-wrap gap-1.5">
                    {component && (
                        <a
                            href={`/admin/components/${component.id}`}
                            className="inline-flex h-7 items-center gap-1.5 rounded-md border border-line-strong bg-surface px-2 text-xs font-medium hover:bg-raised"
                        >
                            <Icon name="external" className="size-3.5" />
                            Edit the component
                        </a>
                    )}
                    <Button
                        size="sm"
                        icon="unlink"
                        disabled={!props.canEdit || !component?.published || !props.onDetach}
                        onClick={() => props.onDetach?.(props.node.id)}
                    >
                        Detach (copy its blocks into this page)
                    </Button>
                </div>
                <p className="text-[11px] text-muted">Detaching keeps the content but drops this page's spacing and size settings for it.</p>
            </PanelSection>
            <DesignArea props={props} />
        </>
    );
}

function GenericBody(props: NodeInspectorProps) {
    const definition = currentDefinition(props.node.type);
    return (
        <>
            <PanelSection title={definition?.label ?? 'Content'}>
                {Object.entries(definition?.props ?? {})
                    .filter(([key]) => key !== 'style')
                    .map(([key, field]) => {
                        const ui = definition?.editor?.fields?.[key];
                        const label = ui?.label ?? key;
                        const value = props.node.props[key];
                        if (field.type === 'string')
                            return (
                                <TextField
                                    key={key}
                                    label={label}
                                    rows={ui?.multiline ? 4 : undefined}
                                    value={String(value ?? '')}
                                    max={field.maxLength ?? 5000}
                                    disabled={!props.canEdit}
                                    onChange={(v) => props.set({ [key]: v }, key)}
                                />
                            );
                        if (field.type === 'enum')
                            return (
                                <SelectField
                                    key={key}
                                    label={label}
                                    value={String(value ?? '')}
                                    options={field.values.map((v) => [v, v])}
                                    disabled={!props.canEdit}
                                    onChange={(v) => props.set({ [key]: v })}
                                />
                            );
                        if (field.type === 'object' && field.properties?.assetId?.type === 'uuid') {
                            const image = value as Image;
                            return (
                                <div key={key} className="space-y-2">
                                    <p className="ui-label">{label}</p>
                                    <MediaPicker
                                        value={image?.assetId ?? null}
                                        media={props.media}
                                        canEdit={props.canEdit}
                                        canUpload={props.canUpload}
                                        onUpload={props.onUpload}
                                        onChoose={(id) => props.set({ [key]: id ? { assetId: id, alt: image?.alt ?? '' } : null })}
                                    />
                                    {image && (
                                        <TextField
                                            label={label + ' alternative text (required to publish)'}
                                            value={image.alt}
                                            max={field.properties.alt?.type === 'string' ? (field.properties.alt.maxLength ?? 300) : 300}
                                            disabled={!props.canEdit}
                                            onChange={(alt) => props.set({ [key]: { ...image, alt } }, key + '.alt')}
                                        />
                                    )}
                                </div>
                            );
                        }
                        return null;
                    })}
            </PanelSection>
            <DesignArea props={props} />
        </>
    );
}

const BODIES: Record<string, (props: NodeInspectorProps) => React.JSX.Element> = {
    hero: HeroBody,
    image: ImageBody,
    text: TextBody,
    button: ButtonBody,
    section: SectionBody,
    group: GroupBody,
    columns: ColumnsBody,
    column: ColumnBody,
    instance: InstanceBody,
};

/** Nothing selected: the page itself (title and URL, search settings, page background). */
function PageInspector(props: InspectorProps) {
    const { document, canEdit, onChange, rootName = 'Page' } = props;
    const root = document.nodes[document.root]!;
    const field = styleFieldOf(root.type);
    const seo = document.seo;
    const isPage = root.type === 'page';
    const set = (values: PageDocument['seo'], key?: string) => onChange([{ op: 'updateSeo', set: values }], key && `seo:${key}`);
    const ids = { noindex: useId() };
    const part: Part = { slot: 'root', label: rootName, noun: rootName };
    return (
        <div className="pb-8">
            <div className="border-b border-line bg-surface px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <span className="grid size-8 shrink-0 place-items-center rounded-md bg-sunken text-muted">
                        <Icon name="pages" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="text-sm font-semibold" data-testid="inspector-target">
                            {rootName} settings
                        </h2>
                        <p className="text-[11px] text-muted">Select a block on the canvas or in Layers to edit it.</p>
                    </div>
                </div>
            </div>
            {props.pageSettings}
            {isPage && (
                <PanelSection title="Search engines">
                    <TextField
                        label="SEO title"
                        value={seo.title ?? ''}
                        max={120}
                        disabled={!canEdit}
                        placeholder="Defaults to “Page title · Site name”"
                        onChange={(v) => set({ title: v }, 'title')}
                    />
                    <TextField
                        label="Meta description"
                        rows={3}
                        value={seo.description ?? ''}
                        max={320}
                        disabled={!canEdit}
                        onChange={(v) => set({ description: v }, 'description')}
                    />
                    <label htmlFor={ids.noindex} className="flex items-center gap-2 text-[0.8125rem]">
                        <input
                            id={ids.noindex}
                            type="checkbox"
                            className="size-4 accent-[var(--ak-accent)]"
                            disabled={!canEdit}
                            checked={seo.noindex ?? false}
                            onChange={(e) => set({ noindex: e.target.checked })}
                        />
                        Hide from search engines (noindex)
                    </label>
                </PanelSection>
            )}
            {field && (
                <div data-testid="design-panel">
                    <ScreenBar props={root.props} breakpoint={props.breakpoint} onBreakpoint={props.onBreakpoint} />
                    <StyleGroups
                        title="Page design"
                        target={{
                            props: root.props,
                            field,
                            part,
                            breakpoint: props.breakpoint,
                            tokens: props.tokens,
                            media: props.media,
                            canEdit,
                            onStyle: (style: Style, key) => onChange([{ op: 'updateProps', nodeId: root.id, set: { style } }], key && `${root.id}:${key}`),
                        }}
                    />
                </div>
            )}
        </div>
    );
}
