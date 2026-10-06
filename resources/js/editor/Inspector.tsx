import { useEffect, useId, useRef, useState } from 'react';
import { matches, message } from '@/arkon/rules';
import { canContain, createNodes, insertOps } from '@/arkon/editor/structure';
import type { Node, PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import type { MediaInfo } from '@/types';

type Image = { assetId: string; alt: string } | null;

const field =
    'mt-1 w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 disabled:bg-zinc-50';
const label = 'block text-xs font-medium text-zinc-600';
const smallButton = 'rounded-md border border-zinc-300 px-2 py-1 text-xs hover:bg-zinc-50 disabled:opacity-50';

export interface InspectorProps {
    document: PageDocument;
    selected: Node | null;
    media: MediaInfo[];
    canEdit: boolean;
    canUpload: boolean;
    onChange(ops: PageOperation[], coalesceKey?: string): void;
    onUpload(file: File): Promise<MediaInfo | null>;
}

type NodeInspectorProps = InspectorProps & { node: Node; set(values: Record<string, unknown>, key?: string): void };

const INSPECTORS: Record<string, (props: NodeInspectorProps) => React.JSX.Element> = {
    hero: HeroInspector,
    text: TextInspector,
    image: ImageInspector,
    button: ButtonInspector,
    columns: ColumnsInspector,
    column: ColumnInspector,
};

export function Inspector(props: InspectorProps) {
    const node = props.selected;
    const Component = node ? INSPECTORS[node.type] : undefined;
    if (!node || !Component) return <PageInspector {...props} />;
    const set = (values: Record<string, unknown>, key?: string) =>
        props.onChange([{ op: 'updateProps', nodeId: node.id, set: values }], key && `${node.id}:${key}`);
    // Keyed by node: switching selection never carries one component's local field state into another.
    return <Component key={node.id} {...props} node={node} set={set} />;
}

function TextField(props: {
    label: string;
    value: string;
    max: number;
    disabled: boolean;
    rows?: number;
    onChange(value: string): void;
    placeholder?: string;
}) {
    const id = useId();
    const Tag = props.rows ? 'textarea' : 'input';
    return (
        <div>
            <label htmlFor={id} className={label}>
                {props.label}
            </label>
            <Tag
                id={id}
                className={field}
                rows={props.rows}
                disabled={props.disabled}
                value={props.value}
                maxLength={props.max}
                placeholder={props.placeholder}
                onChange={(e) => props.onChange(e.target.value)}
            />
        </div>
    );
}

function SelectField<T extends string>(props: { label: string; value: T; options: [T, string][]; disabled: boolean; onChange(value: T): void }) {
    const id = useId();
    return (
        <div>
            <label htmlFor={id} className={label}>
                {props.label}
            </label>
            <select id={id} className={field} disabled={props.disabled} value={props.value} onChange={(e) => props.onChange(e.target.value as T)}>
                {props.options.map(([value, text]) => (
                    <option key={value} value={value}>
                        {text}
                    </option>
                ))}
            </select>
        </div>
    );
}

function HeroInspector({ node, canEdit, set, ...rest }: NodeInspectorProps) {
    const p = node.props;
    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Hero</h2>
            <TextField label="Heading" value={String(p.heading ?? '')} max={160} disabled={!canEdit} onChange={(v) => set({ heading: v }, 'heading')} />
            <SelectField
                label="Heading level (semantics, not size)"
                value={(p.headingLevel as 'h1' | 'h2') ?? 'h1'}
                options={[
                    ['h1', 'H1: main page heading'],
                    ['h2', 'H2: section heading'],
                ]}
                disabled={!canEdit}
                onChange={(v) => set({ headingLevel: v })}
            />
            <TextField label="Text" rows={4} value={String(p.text ?? '')} max={600} disabled={!canEdit} onChange={(v) => set({ text: v }, 'text')} />
            <ImageField image={(p.image as Image) ?? null} canEdit={canEdit} set={set} {...rest} />
        </div>
    );
}

function TextInspector({ node, canEdit, set }: NodeInspectorProps) {
    const p = node.props;
    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Text</h2>
            <TextField label="Text" rows={6} value={String(p.text ?? '')} max={5000} disabled={!canEdit} onChange={(v) => set({ text: v }, 'text')} />
            <SelectField
                label="Kind"
                value={(p.element as 'p' | 'h2' | 'h3') ?? 'p'}
                options={[
                    ['p', 'Paragraph'],
                    ['h2', 'Heading (H2)'],
                    ['h3', 'Subheading (H3)'],
                ]}
                disabled={!canEdit}
                onChange={(v) => set({ element: v })}
            />
            <SelectField
                label="Alignment"
                value={(p.align as 'start' | 'center') ?? 'start'}
                options={[
                    ['start', 'Left'],
                    ['center', 'Centered'],
                ]}
                disabled={!canEdit}
                onChange={(v) => set({ align: v })}
            />
        </div>
    );
}

function ImageInspector({ node, canEdit, set, ...rest }: NodeInspectorProps) {
    const p = node.props;
    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Image</h2>
            <ImageField image={(p.image as Image) ?? null} canEdit={canEdit} set={set} {...rest} />
            <TextField label="Caption" value={String(p.caption ?? '')} max={300} disabled={!canEdit} onChange={(v) => set({ caption: v }, 'caption')} />
            <SelectField
                label="Size"
                value={(p.size as 'full' | 'medium' | 'small') ?? 'full'}
                options={[
                    ['full', 'Full width'],
                    ['medium', 'Medium'],
                    ['small', 'Small'],
                ]}
                disabled={!canEdit}
                onChange={(v) => set({ size: v })}
            />
        </div>
    );
}

function ButtonInspector({ node, canEdit, set }: NodeInspectorProps) {
    const p = node.props;
    const linkId = useId();
    const tabId = useId();
    // The link is edited locally and applied only when it is safe, so typing "https://…" letter by
    // letter never produces an invalid document (and an unsafe link never reaches the page).
    const [link, setLink] = useState(String(p.href ?? ''));
    useEffect(() => setLink(String(p.href ?? '')), [p.href]);
    const linkError = link.length > 2000 ? message('tooLong', { max: 2000 }) : matches('link', link) ? null : message('unsafeLink');
    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Button</h2>
            <TextField label="Label" value={String(p.label ?? '')} max={80} disabled={!canEdit} onChange={(v) => set({ label: v }, 'label')} />
            <div>
                <label htmlFor={linkId} className={label}>
                    Link
                </label>
                <input
                    id={linkId}
                    className={`${field} ${linkError ? 'border-red-400' : ''}`}
                    disabled={!canEdit}
                    value={link}
                    placeholder="/contact or https://example.com"
                    aria-invalid={linkError !== null}
                    aria-describedby={`${linkId}-hint`}
                    onChange={(e) => {
                        const value = e.target.value;
                        setLink(value);
                        if (matches('link', value) && value.length <= 2000) set({ href: value }, 'href');
                    }}
                />
                <p id={`${linkId}-hint`} role={linkError ? 'alert' : undefined} className={`mt-1 text-xs ${linkError ? 'text-red-700' : 'text-zinc-500'}`}>
                    {linkError ?? 'A page on this site (/about), a section (#top), or a full web, email or phone link.'}
                </p>
            </div>
            <SelectField
                label="Style"
                value={(p.style as 'primary' | 'secondary') ?? 'primary'}
                options={[
                    ['primary', 'Primary'],
                    ['secondary', 'Secondary (outline)'],
                ]}
                disabled={!canEdit}
                onChange={(v) => set({ style: v })}
            />
            <label htmlFor={tabId} className="flex items-center gap-2 text-sm">
                <input id={tabId} type="checkbox" disabled={!canEdit} checked={p.newTab === true} onChange={(e) => set({ newTab: e.target.checked })} />
                Open in a new tab
            </label>
        </div>
    );
}

function ColumnsInspector({ node, document, canEdit, set, onChange }: NodeInspectorProps) {
    const p = node.props;
    const columns = node.children ?? [];
    const last = columns.at(-1);
    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Columns</h2>
            <div className="flex items-center gap-2 text-sm">
                <span>
                    {columns.length} column{columns.length === 1 ? '' : 's'}
                </span>
                <button
                    type="button"
                    className={smallButton}
                    disabled={!canEdit || !canContain(node, 'column')}
                    onClick={() => onChange(insertOps({ parentId: node.id, index: columns.length }, createNodes('column')))}
                >
                    Add column
                </button>
                <button
                    type="button"
                    className={smallButton}
                    disabled={!canEdit || columns.length <= 1 || !last}
                    onClick={() => last && onChange([{ op: 'removeNode', nodeId: last }])}
                    title={
                        last && (document.nodes[last]?.children?.length ?? 0) > 0 ? 'Removes the last column and its content (undo brings it back)' : undefined
                    }
                >
                    Remove last column
                </button>
            </div>
            <SelectField
                label="Stack columns on"
                value={(p.stackOn as 'tablet' | 'mobile') ?? 'mobile'}
                options={[
                    ['mobile', 'Phones only'],
                    ['tablet', 'Tablets and phones'],
                ]}
                disabled={!canEdit}
                onChange={(v) => set({ stackOn: v })}
            />
            <SelectField
                label="Spacing"
                value={(p.gap as 'small' | 'medium' | 'large') ?? 'medium'}
                options={[
                    ['small', 'Small'],
                    ['medium', 'Medium'],
                    ['large', 'Large'],
                ]}
                disabled={!canEdit}
                onChange={(v) => set({ gap: v })}
            />
        </div>
    );
}

function ColumnInspector({ node, canEdit, onChange }: NodeInspectorProps) {
    return (
        <div className="space-y-3 p-4">
            <h2 className="text-sm font-semibold">Column</h2>
            <p className="text-xs text-zinc-500">Add content to this column:</p>
            <div className="flex flex-wrap gap-2">
                {(['text', 'image', 'button'] as const).map((type) => (
                    <button
                        key={type}
                        type="button"
                        className={smallButton}
                        disabled={!canEdit || !canContain(node, type)}
                        onClick={() => onChange(insertOps({ parentId: node.id, index: node.children?.length ?? 0 }, createNodes(type)))}
                    >
                        Add {type}
                    </button>
                ))}
            </div>
        </div>
    );
}

function ImageField({
    image,
    media,
    canEdit,
    canUpload,
    set,
    onUpload,
}: { image: Image; set: NodeInspectorProps['set'] } & Pick<InspectorProps, 'media' | 'canEdit' | 'canUpload' | 'onUpload'>) {
    const ids = { alt: useId(), library: useId() };
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const current = image ? media.find((m) => m.id === image.assetId) : undefined;

    return (
        <fieldset className="space-y-2 rounded-md border border-zinc-200 p-3">
            <legend className="px-1 text-xs font-medium text-zinc-600">Image</legend>
            {current ? (
                <img src={current.url} alt="" className="max-h-32 w-full rounded object-contain bg-zinc-50" />
            ) : (
                <p className="text-xs text-zinc-500">No image</p>
            )}
            {media.length > 0 && (
                <div>
                    <label htmlFor={ids.library} className={label}>
                        Choose from library
                    </label>
                    <select
                        id={ids.library}
                        className={field}
                        disabled={!canEdit}
                        value={image?.assetId ?? ''}
                        onChange={(e) => set({ image: e.target.value ? { assetId: e.target.value, alt: image?.alt ?? '' } : null })}
                    >
                        <option value="">None</option>
                        {media.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.url.split('/').pop()?.split('?')[0]} ({m.width}×{m.height})
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {canUpload && canEdit && (
                <>
                    <input
                        ref={fileRef}
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/avif,image/gif"
                        className="sr-only"
                        aria-label="Upload image"
                        onChange={async (e) => {
                            const file = e.target.files?.[0];
                            e.target.value = '';
                            if (!file) return;
                            setUploading(true);
                            try {
                                const asset = await onUpload(file);
                                if (asset) set({ image: { assetId: asset.id, alt: image?.alt ?? '' } });
                            } finally {
                                setUploading(false);
                            }
                        }}
                    />
                    <button
                        type="button"
                        disabled={uploading}
                        onClick={() => fileRef.current?.click()}
                        className="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-sm hover:bg-zinc-50 disabled:opacity-60"
                    >
                        {uploading ? 'Uploading…' : 'Upload image'}
                    </button>
                </>
            )}
            {image && (
                <>
                    <div>
                        <label htmlFor={ids.alt} className={label}>
                            Alternative text (required to publish)
                        </label>
                        <input
                            id={ids.alt}
                            className={field}
                            disabled={!canEdit}
                            value={image.alt}
                            maxLength={300}
                            placeholder="Describe the image for people who can't see it"
                            onChange={(e) => set({ image: { ...image, alt: e.target.value } }, 'alt')}
                        />
                    </div>
                    <button type="button" disabled={!canEdit} onClick={() => set({ image: null })} className="text-xs text-red-600 hover:underline">
                        Remove image
                    </button>
                </>
            )}
        </fieldset>
    );
}

function PageInspector({ document, canEdit, onChange }: InspectorProps) {
    const seo = document.seo;
    const set = (values: PageDocument['seo'], key?: string) => onChange([{ op: 'updateSeo', set: values }], key && `seo:${key}`);
    const ids = { noindex: useId() };
    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Page SEO</h2>
            <p className="text-xs text-zinc-500">Select a component on the canvas or in Layers to edit it.</p>
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
            <label htmlFor={ids.noindex} className="flex items-center gap-2 text-sm">
                <input
                    id={ids.noindex}
                    type="checkbox"
                    disabled={!canEdit}
                    checked={seo.noindex ?? false}
                    onChange={(e) => set({ noindex: e.target.checked })}
                />
                Hide from search engines (noindex)
            </label>
        </div>
    );
}
