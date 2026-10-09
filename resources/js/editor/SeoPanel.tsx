import { seoColor } from '@/lib/seo';
import { useEffect, useId, useState } from 'react';
import rules from '../../arkon/rules.json';
import { api } from '@/lib/api';
import { Button, PanelSection, Segmented } from '@/Components/ui';
import type { PageDocument, PageSeo } from '@/arkon/schema/document';
import type { PageOperation } from '@/arkon/schema/operations';
import type { UnresolvedField } from './Inspector';
import type { MediaInfo } from '@/types';

export interface SeoReport {
    score: number;
    label: string;
    title: string;
    description: string;
    canonical: string;
    noindex: boolean;
    socialTitle: string;
    socialDescription: string;
    socialImage: string;
    socialPreviewUrl?: string;
    schemaPresent: boolean;
    nofollow: boolean;
    checks: { id: string; category: string; weight: number; earned: number; status: string; message: string; fixType: string | null }[];
    categories: Record<string, { earned: number; possible: number }>;
}
/** Debounced, deterministic PHP inspection of the real renderer, never an AI call. */
export function useSeoAnalysis(pageId: string, document: PageDocument | null, enabled = true) {
    const [report, setReport] = useState<SeoReport | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    useEffect(() => {
        if (!enabled || !document) {
            setReport(null);
            setPending(false);
            return;
        }
        let current = true;
        setPending(true);
        const timer = setTimeout(() => {
            void api<SeoReport>(`/pages/${pageId}/seo-analysis`, { body: { document } })
                .then((r) => {
                    if (!current) return;
                    if (r.ok) {
                        setReport(r.data);
                        setError(null);
                    } else {
                        setError(r.message);
                        setReport(null);
                    }
                })
                .catch(() => {
                    if (current) {
                        setReport(null);
                        setError('Could not check SEO. Your edits are still available.');
                    }
                })
                .finally(() => {
                    if (current) setPending(false);
                });
        }, 600);
        return () => {
            current = false;
            clearTimeout(timer);
        };
    }, [pageId, document, enabled]);
    return { report, error, pending };
}
export function SeoPanel({
    document,
    report,
    pending,
    error,
    canEdit,
    canAsk,
    onChange,
    onAsk,
    media,
    unresolved,
    onUnresolved,
}: {
    document: PageDocument;
    report: SeoReport | null;
    pending: boolean;
    error: string | null;
    canEdit: boolean;
    canAsk: boolean;
    onChange(ops: PageOperation[], key?: string): void;
    onAsk(prompt: string): void;
    media: MediaInfo[];
    unresolved: Record<string, UnresolvedField>;
    onUnresolved(key: string, field: UnresolvedField | null): void;
}) {
    const [section, setSection] = useState<'search' | 'social' | 'advanced'>(
        Object.values(unresolved).some((f) => f.prop === 'seoCanonical') ? 'advanced' : 'search',
    );
    const id = useId();
    const seo = document.seo;
    const socialAsset = media.find((m) => m.id === seo.socialImage || (!seo.socialImage && report?.socialImage.includes(`/media/${m.id}.`)));
    const socialPreview = report?.socialPreviewUrl || (socialAsset ? (socialAsset.previewUrl ?? socialAsset.url) : report?.socialImage);
    const canonicalKey = document.root + ':seo-canonical';
    const canonicalInput = unresolved[canonicalKey]?.value ?? seo.canonical ?? '';
    const set = (key: keyof PageSeo, value: string | boolean) => onChange([{ op: 'updateSeo', set: { [key]: value } }], `seo:${key}`);
    const ask = (field = 'all') =>
        onAsk(
            `ARKON_SEO_METADATA_ONLY:${field}\nPrepare reviewable SEO text improvements for this page. Ground every statement in the supplied content. Never invent facts, services, prices or locations. Do not change the URL, canonical, robots, design tokens, images or page content. Return only seo changes for the requested field, or for title, description, focusTopic, socialTitle and socialDescription when all is requested. Put content/alt text/internal-link recommendations in notes only. Current checks: ${JSON.stringify(report?.checks.filter((c) => c.status !== 'passed').map((c) => c.message) ?? [])}`,
        );
    const field = (key: 'title' | 'description' | 'focusTopic' | 'socialTitle' | 'socialDescription', label: string, placeholder = '', rows = false) => {
        const value = seo[key] ?? '';
        return (
            <div>
                <label className="ui-label" htmlFor={id + key}>
                    {label}
                </label>
                {rows ? (
                    <textarea
                        id={id + key}
                        className="ui-input"
                        rows={3}
                        maxLength={320}
                        value={value}
                        placeholder={placeholder}
                        disabled={!canEdit}
                        onChange={(e) => set(key, e.target.value)}
                    />
                ) : (
                    <input
                        id={id + key}
                        className="ui-input"
                        maxLength={120}
                        value={value}
                        placeholder={placeholder}
                        disabled={!canEdit}
                        onChange={(e) => set(key, e.target.value)}
                    />
                )}
                <div className="mt-1 flex items-center justify-between gap-2">
                    <span className="text-2xs text-muted">
                        {value.length} characters{' '}
                        {key === 'focusTopic' ? '· planning aid, not a meta keyword' : value ? '· display guidance only' : '· using the default'}
                    </span>
                    <Button size="sm" variant="ghost" icon="sparkle" disabled={!canAsk} onClick={() => ask(key)}>
                        Generate
                    </Button>
                </div>
            </div>
        );
    };
    return (
        <div className="pb-8" data-testid="seo-panel">
            <PanelSection title="Page SEO">
                <p className={`t-title ${report ? seoColor(report.score) : 'text-muted'}`} aria-live="polite">
                    {report ? `${report.score} / 100 — ${report.label}` : error ? 'Analysis unavailable' : 'Checking SEO…'}
                    {pending && report ? ' · updating' : ''}
                </p>
                <p className="text-2xs text-muted">Checks recommended practices. Does not predict rankings or measure performance.</p>
                {error && <p role="alert">{error}</p>}
                <Button icon="sparkle" disabled={!canAsk} onClick={() => ask()}>
                    Improve SEO with AI
                </Button>
                <p className="text-2xs text-muted">Uses your paired Claude Code subscription. Review before applying; nothing is published.</p>
            </PanelSection>
            <div className="px-4 py-3">
                <Segmented<'search' | 'social' | 'advanced'>
                    label="SEO section"
                    value={section}
                    onChange={setSection}
                    options={[
                        { value: 'search', label: 'Search' },
                        { value: 'social', label: 'Social' },
                        { value: 'advanced', label: 'Advanced' },
                    ]}
                />
            </div>
            {section === 'search' ? (
                <>
                    <PanelSection title="Search preview">
                        <div className="rounded-md border border-line p-3">
                            <p className="truncate text-2xs text-muted">{report?.canonical || 'Your page URL'}</p>
                            <p className="mt-1 text-ui font-medium">{report?.title || seo.title || 'Your search title'}</p>
                            <p className="mt-1 text-xs text-muted">{report?.description || seo.description || 'Add a useful summary of this page.'}</p>
                        </div>
                        <p className="text-2xs text-muted">Search engines may choose different text.</p>
                    </PanelSection>
                    <PanelSection title="Search metadata">
                        {field('title', 'SEO title', report?.title)}
                        {field('description', 'Meta description', report?.description, true)}
                        {field('focusTopic', 'Focus topic')}
                        <label className="ui-label" htmlFor={id + 'pageType'}>
                            Page purpose
                        </label>
                        <select
                            className="ui-input"
                            id={id + 'pageType'}
                            disabled={!canEdit}
                            value={seo.pageType ?? 'standard'}
                            onChange={(e) => set('pageType', e.target.value)}
                        >
                            {['standard', 'home', 'landing', 'article', 'contact', 'about'].map((v) => (
                                <option key={v} value={v}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <p className="text-2xs text-muted">No minimum word count or keyword density target. Write for visitors.</p>
                    </PanelSection>
                </>
            ) : section === 'social' ? (
                <>
                    <PanelSection title="Social preview">
                        <div className="rounded-md border border-line p-3">
                            {socialPreview && <img src={socialPreview} alt="" className="mb-2 max-h-32 w-full object-cover" />}
                            <p className="font-medium">{report?.socialTitle || report?.title}</p>
                            <p className="text-xs text-muted">{report?.socialDescription || report?.description}</p>
                        </div>
                        <p className="text-2xs text-muted">Open Graph and Twitter/X inherit your search text unless overridden.</p>
                    </PanelSection>
                    <PanelSection title="Social overrides">
                        {field('socialTitle', 'Social title', report?.title)}
                        {field('socialDescription', 'Social description', report?.description, true)}
                        <label className="ui-label" htmlFor={id + 'image'}>
                            Social image
                        </label>
                        <select
                            id={id + 'image'}
                            className="ui-input"
                            disabled={!canEdit}
                            value={seo.socialImage ?? ''}
                            onChange={(e) => set('socialImage', e.target.value)}
                        >
                            <option value="">No image override</option>
                            {media.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.name ?? m.id}
                                </option>
                            ))}
                        </select>
                    </PanelSection>
                </>
            ) : (
                <PanelSection title="Advanced SEO">
                    <label className="ui-label" htmlFor={id + 'canonical'}>
                        Canonical URL override
                    </label>
                    <input
                        id={id + 'canonical'}
                        className="ui-input"
                        type="url"
                        data-unresolved-field={canonicalKey}
                        value={canonicalInput}
                        placeholder={report?.canonical}
                        maxLength={2048}
                        disabled={!canEdit}
                        onChange={(e) => {
                            const v = e.target.value;
                            if (v === '' || new RegExp(rules.seo.canonical.pattern).test(v)) {
                                onUnresolved(canonicalKey, null);
                                set('canonical', v);
                            } else
                                onUnresolved(canonicalKey, {
                                    nodeId: document.root,
                                    prop: 'seoCanonical',
                                    label: 'Canonical URL',
                                    value: v,
                                    error: 'Use a complete http or https URL, or leave blank to inherit.',
                                });
                        }}
                    />
                    {unresolved[canonicalKey] && (
                        <p role="alert" className="text-xs text-danger">
                            {unresolved[canonicalKey].error} This value is not saved.
                        </p>
                    )}
                    <p className="text-2xs text-muted">Leave blank to use the site URL and this page’s path. Changing this can affect indexing.</p>
                    {(['noindex', 'nofollow'] as const).map((key) => (
                        <label key={key} className="flex items-center gap-2 text-ui">
                            <input
                                type="checkbox"
                                checked={seo[key] ?? (key === 'noindex' ? report?.noindex : report?.nofollow) ?? false}
                                disabled={!canEdit}
                                onChange={(e) => set(key, e.target.checked)}
                            />
                            {key === 'noindex' ? 'Exclude from search results (noindex)' : 'Ask crawlers not to follow links (nofollow)'}{' '}
                            <span className="text-2xs text-muted">{seo[key] === undefined ? 'Inherited' : 'Override'}</span>
                            {seo[key] !== undefined && (
                                <Button variant="ghost" size="sm" disabled={!canEdit} onClick={() => onChange([{ op: 'updateSeo', set: {}, unset: [key] }])}>
                                    Use default
                                </Button>
                            )}
                        </label>
                    ))}
                    <label className="ui-label" htmlFor={id + 'schema'}>
                        Structured data
                    </label>
                    <select
                        id={id + 'schema'}
                        className="ui-input"
                        disabled={!canEdit}
                        value={seo.schemaType ?? 'Auto'}
                        onChange={(e) => set('schemaType', e.target.value)}
                    >
                        {['Auto', 'WebPage', 'AboutPage', 'ContactPage', 'Article', 'BlogPosting', 'None'].map((v) => (
                            <option key={v}>{v}</option>
                        ))}
                    </select>
                    <p className="text-2xs text-muted">
                        Auto uses the page purpose. Basic structured data uses only the page title, description and URL; it does not promise rich results.
                    </p>
                </PanelSection>
            )}
            <PanelSection title="Image descriptions">
                {Object.values(document.nodes)
                    .filter((n) => n.props.image && typeof n.props.image === 'object' && 'assetId' in n.props.image)
                    .map((n) => {
                        const image = n.props.image as { assetId: string; alt: string };
                        const asset = media.find((m) => m.id === image.assetId);
                        return (
                            <div key={n.id} className="space-y-1 text-xs">
                                <p className="font-medium">{asset?.name ?? 'Page image'}</p>
                                <p className="text-muted">{image.alt || 'Empty alt — confirm this image is decorative.'}</p>
                                {!image.alt && asset?.defaultAlt && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={!canEdit}
                                        onClick={() => onChange([{ op: 'updateProps', nodeId: n.id, set: { image: { ...image, alt: asset.defaultAlt } } }])}
                                    >
                                        Use library alt text
                                    </Button>
                                )}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    icon="sparkle"
                                    disabled={!canAsk}
                                    onClick={() =>
                                        onAsk(
                                            `ARKON_SEO_ALT_ONLY:${n.id}\nSuggest accurate alt text from saved library descriptions and the current page. You cannot see the pixels. If you lack reliable information, propose nothing and ask the user to describe it. Only use action alt for this image.`,
                                        )
                                    }
                                >
                                    Generate alt text
                                </Button>
                            </div>
                        );
                    })}
                <p className="text-2xs text-muted">
                    Descriptions use existing library data, not image recognition. Each suggestion is reviewed; decorative images can keep empty alt.
                </p>
            </PanelSection>
            <PanelSection title="Analysis">
                {report && (
                    <>
                        <dl className="space-y-1 text-xs">
                            {Object.entries(report.categories).map(([name, c]) => (
                                <div key={name} className="flex justify-between">
                                    <dt>{name}</dt>
                                    <dd>
                                        {c.earned} / {c.possible}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <ul className="space-y-3">
                            {[...report.checks]
                                .sort((a, b) => (a.status === 'passed' ? 1 : 0) - (b.status === 'passed' ? 1 : 0) || b.weight - a.weight)
                                .map((c) => (
                                    <li key={c.id} className="text-xs">
                                        <span
                                            className={`font-medium ${c.status === 'passed' ? 'text-live' : c.status === 'critical' ? 'text-danger' : c.status === 'important' ? 'text-changed' : 'text-muted'}`}
                                        >
                                            {c.status === 'passed'
                                                ? 'Passed'
                                                : c.status === 'critical'
                                                  ? 'High impact'
                                                  : c.status === 'suggestion'
                                                    ? 'Suggestion'
                                                    : c.status === 'notice'
                                                      ? 'Notice'
                                                      : 'Improve'}{' '}
                                            · {c.category}
                                        </span>
                                        <p className="mt-1 text-muted">{c.message}</p>
                                    </li>
                                ))}
                        </ul>
                    </>
                )}
            </PanelSection>
        </div>
    );
}
