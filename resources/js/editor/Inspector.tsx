import { useId, useRef, useState } from 'react';
import type { Node, PageDocument } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import type { MediaInfo } from '@/types';

/** Props of hero v1 (resources/arkon/components/hero/v1.json). */
interface HeroProps {
    heading: string;
    headingLevel: 'h1' | 'h2';
    text: string;
    image: { assetId: string; alt: string } | null;
}

const field =
    'mt-1 w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 disabled:bg-zinc-50';
const label = 'block text-xs font-medium text-zinc-600';

export interface InspectorProps {
    document: PageDocument;
    selected: Node | null;
    media: MediaInfo[];
    canEdit: boolean;
    canUpload: boolean;
    onChange(ops: PageOperation[], coalesceKey?: string): void;
    onUpload(file: File): Promise<MediaInfo | null>;
}

export function Inspector(props: InspectorProps) {
    if (props.selected?.type === 'hero') return <HeroInspector {...props} node={props.selected} />;
    return <PageInspector {...props} />;
}

function HeroInspector({ node, media, canEdit, canUpload, onChange, onUpload }: InspectorProps & { node: Node }) {
    const p = node.props as Partial<HeroProps>;
    const set = (values: Partial<HeroProps>, key?: string) => onChange([{ op: 'updateProps', nodeId: node.id, set: values }], key && `${node.id}:${key}`);
    const ids = { heading: useId(), level: useId(), text: useId(), alt: useId(), library: useId() };
    const fileRef = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const image = p.image ?? null;
    const current = image ? media.find((m) => m.id === image.assetId) : undefined;

    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Hero</h2>
            <div>
                <label htmlFor={ids.heading} className={label}>
                    Heading
                </label>
                <input
                    id={ids.heading}
                    className={field}
                    disabled={!canEdit}
                    value={p.heading ?? ''}
                    maxLength={160}
                    onChange={(e) => set({ heading: e.target.value }, 'heading')}
                />
            </div>
            <div>
                <label htmlFor={ids.level} className={label}>
                    Heading level (semantics, not size)
                </label>
                <select
                    id={ids.level}
                    className={field}
                    disabled={!canEdit}
                    value={p.headingLevel ?? 'h1'}
                    onChange={(e) => set({ headingLevel: e.target.value as 'h1' | 'h2' })}
                >
                    <option value="h1">H1: main page heading</option>
                    <option value="h2">H2: section heading</option>
                </select>
            </div>
            <div>
                <label htmlFor={ids.text} className={label}>
                    Text
                </label>
                <textarea
                    id={ids.text}
                    className={field}
                    rows={4}
                    disabled={!canEdit}
                    value={p.text ?? ''}
                    maxLength={600}
                    onChange={(e) => set({ text: e.target.value }, 'text')}
                />
            </div>

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
        </div>
    );
}

function PageInspector({ document, canEdit, onChange }: InspectorProps) {
    const seo = document.seo;
    const set = (values: PageDocument['seo'], key?: string) => onChange([{ op: 'updateSeo', set: values }], key && `seo:${key}`);
    const ids = { title: useId(), description: useId(), noindex: useId() };
    return (
        <div className="space-y-4 p-4">
            <h2 className="text-sm font-semibold">Page SEO</h2>
            <p className="text-xs text-zinc-500">Select a section on the canvas to edit it.</p>
            <div>
                <label htmlFor={ids.title} className={label}>
                    SEO title
                </label>
                <input
                    id={ids.title}
                    className={field}
                    disabled={!canEdit}
                    value={seo.title ?? ''}
                    maxLength={120}
                    placeholder="Defaults to “Page title · Site name”"
                    onChange={(e) => set({ title: e.target.value }, 'title')}
                />
            </div>
            <div>
                <label htmlFor={ids.description} className={label}>
                    Meta description
                </label>
                <textarea
                    id={ids.description}
                    className={field}
                    rows={3}
                    disabled={!canEdit}
                    value={seo.description ?? ''}
                    maxLength={320}
                    onChange={(e) => set({ description: e.target.value }, 'description')}
                />
            </div>
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
