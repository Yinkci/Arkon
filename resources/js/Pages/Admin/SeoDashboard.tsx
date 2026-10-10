import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { Icon, type IconName } from '@/Components/Icon';
import { ScoreMark, ScoreRing } from '@/Components/SeoScore';
import { ButtonLink, EmptyState, PageShell, Panel, PanelHeading, ProportionBar, Segmented } from '@/Components/ui';
import type { SeoReport } from '@/editor/SeoPanel';
import { SEO_BANDS, seoBand, seoScale } from '@/lib/seo';
import type { SharedProps } from '@/types';

type Severity = 'critical' | 'important' | 'suggestion';
interface Issue {
    id: string;
    label: string;
    message: string;
    severity: Severity;
    aiFixable: boolean;
}
interface Row {
    id: string;
    title: string;
    path: string;
    draft: SeoReport | null;
    published: SeoReport | null;
    needsRepair: boolean;
    issues: Issue[];
    suggestions: number;
}
interface AttentionItem {
    id: string;
    label: string;
    severity: Severity;
    aiFixable: boolean;
    impact: number;
    pages: { id: string; title: string }[];
}
interface Diagnostic {
    id: string;
    label: string;
    status: 'ok' | 'warning' | 'error' | 'info';
    detail: string;
    href?: string;
}
type Filter = 'all' | 'excellent' | 'good' | 'improvement' | 'attention' | 'unpublished';

interface Props {
    rows: Row[];
    page: number;
    total: number;
    perPage: number;
    q: string;
    status: Filter;
    counts: Record<Filter, number>;
    origin: string;
    summary: { score: number | null; label: string | null; published: number; pages: number; distribution: Record<string, number> };
    attention: AttentionItem[];
    technical: { group: string; items: Diagnostic[] }[];
}

/** Why each check matters, in one line (shown with the issue; the checks themselves are deterministic). */
const WHY: Record<string, string> = {
    title: 'The search title is the headline people see in results.',
    description: 'Without one, search engines write their own snippet from the page.',
    h1: 'One main heading tells visitors and search engines what the page is about.',
    hierarchy: 'Skipped levels make the outline harder to follow, especially with a screen reader.',
    canonical: 'Search engines need to know the preferred address of each page.',
    viewport: 'Without it, phones show the desktop layout zoomed out.',
    alt: 'Alt text describes images to screen readers and to search engines.',
    links: 'Visitors and crawlers hit dead ends on placeholder or missing destinations.',
    social: 'Shared links fall back to a generic title.',
};

const FILTER_BAND: Partial<Record<Filter, string>> = { excellent: 'Excellent', good: 'Good', improvement: 'Needs improvement', attention: 'Needs attention' };
const SEVERITY: Record<Severity, { label: string; className: string }> = {
    critical: { label: 'High', className: 'text-danger' },
    important: { label: 'Medium', className: 'text-changed' },
    suggestion: { label: 'Low', className: 'text-muted' },
};
const DIAGNOSTIC: Record<Diagnostic['status'], { icon: IconName; className: string; label: string }> = {
    ok: { icon: 'check', className: 'text-live', label: 'Healthy' },
    warning: { icon: 'alert', className: 'text-changed', label: 'Needs attention' },
    error: { icon: 'close', className: 'text-danger', label: 'Error' },
    info: { icon: 'info', className: 'text-muted', label: 'Check' },
};

const editorSeo = (id: string, ai = false) => `/admin/editor/${id}?panel=seo${ai ? '&ai=1' : ''}`;
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

function visit(params: { status?: Filter; q?: string; page?: number }) {
    const query = Object.fromEntries(
        Object.entries(params).filter(
            ([key, value]) => value !== undefined && value !== '' && !(key === 'status' && value === 'all') && !(key === 'page' && value === 1),
        ),
    );
    router.get('/admin/seo', query, { preserveScroll: true, preserveState: true, replace: true });
}

export default function SeoDashboard(props: Props) {
    const { summary, attention, technical, origin } = props;
    const canEdit = usePage<SharedProps>().props.can['page.edit'] ?? false;
    const band = summary.score === null ? null : seoBand(summary.score);
    const issueCount = attention.reduce((sum, item) => sum + item.pages.length, 0);

    return (
        <AdminLayout>
            <Head title="SEO" />
            <PageShell className="space-y-10">
                <AdminPageHeader
                    title="SEO"
                    description="Keep your site searchable, structured and healthy."
                    actions={<ButtonLink href="/admin/seo/defaults">Site SEO defaults</ButtonLink>}
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                    {/* The score: the first thing to read. */}
                    <Panel as="section" padded={false} aria-labelledby="seo-score-title" data-testid="seo-overview">
                        <PanelHeading id="seo-score-title" title="Site score" aside={<span className="t-meta">Published pages</span>} />
                        <div className="flex flex-wrap items-center gap-6 px-6 py-6">
                            <ScoreRing score={summary.score} />
                            <div className="min-w-0 flex-1">
                                {band ? (
                                    <>
                                        <p className={`flex items-center gap-2 text-xl font-semibold ${band.text}`}>
                                            <Icon name={band.icon} className="size-5" />
                                            {band.label}
                                        </p>
                                        <p className="mt-1 t-meta">Average of {plural(summary.published, 'published page')}</p>
                                        <p className="mt-3 text-sm">
                                            {issueCount === 0
                                                ? 'No issues worth fixing on live pages.'
                                                : `${plural(issueCount, 'issue')} worth fixing on live pages.`}
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        <p className="text-xl font-semibold">No live pages yet</p>
                                        <p className="mt-1 t-meta">Publish a page to get a site score. Drafts are scored in the list below.</p>
                                    </>
                                )}
                            </div>
                        </div>
                        <div className="@container border-t border-line px-6 py-5">
                            <h3 className="t-label">Pages by SEO health</h3>
                            <ProportionBar className="mt-3" parts={SEO_BANDS.map((b) => ({ count: summary.distribution[b.label] ?? 0, className: b.fill }))} />
                            <ul className="mt-3 grid gap-x-6 gap-y-1 @md:grid-cols-2">
                                {SEO_BANDS.map((b) => {
                                    const filter = (Object.keys(FILTER_BAND) as Filter[]).find((key) => FILTER_BAND[key] === b.label)!;
                                    const count = summary.distribution[b.label] ?? 0;
                                    return (
                                        <li key={b.label}>
                                            <button
                                                type="button"
                                                onClick={() => visit({ status: filter })}
                                                className="flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-sm hover:bg-hover"
                                                aria-label={`${count} ${b.label}: show these pages`}
                                            >
                                                <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${b.fill}`} />
                                                <span className="min-w-0 flex-1 truncate text-muted">{b.label}</span>
                                                <span className="font-medium t-num">{count}</span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                            <p className="mt-4 text-2xs text-faint">{seoScale}. Deterministic checks of Arkon’s output, not a ranking prediction.</p>
                        </div>
                    </Panel>

                    <Attention items={attention} published={summary.published} canEdit={canEdit} />
                </div>

                <Pages {...props} canEdit={canEdit} />

                {technical.length > 0 && (
                    <Panel as="section" padded={false} aria-labelledby="seo-technical-title" data-testid="seo-technical">
                        <PanelHeading id="seo-technical-title" title="Technical SEO" aside={<span className="t-meta">Checked on what is live</span>} />
                        <div className="grid divide-y divide-line md:grid-cols-3 md:divide-x md:divide-y-0">
                            {technical.map((group) => (
                                <div key={group.group} className="px-6 py-5">
                                    <h3 className="t-eyebrow">{group.group}</h3>
                                    <ul className="mt-3 space-y-4">
                                        {group.items.map((item) => (
                                            <DiagnosticRow key={item.id} item={item} origin={origin} />
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </Panel>
                )}
                <p className="text-2xs text-faint">
                    Checks use Arkon’s stored HTML and live routes. External links, search engine indexing, rich-result eligibility and Core Web Vitals need
                    separate verification.
                </p>
            </PageShell>
        </AdminLayout>
    );
}

/** Issues on live pages, ranked by points lost across the site; AI-draftable ones say so. */
function Attention({ items, published, canEdit }: { items: AttentionItem[]; published: number; canEdit: boolean }) {
    const fixable = items.filter((item) => item.aiFixable);
    const fixablePages = new Map(fixable.flatMap((item) => item.pages.map((p) => [p.id, p] as const)));
    const first = fixable[0]?.pages[0];
    return (
        <Panel as="section" padded={false} aria-labelledby="seo-attention-title" data-testid="seo-attention" className="flex flex-col">
            <PanelHeading
                id="seo-attention-title"
                title="Needs attention"
                aside={
                    items.length > 0 && (
                        <a href="#seo-pages" className="ui-link text-sm">
                            Review pages
                        </a>
                    )
                }
            />
            {items.length === 0 ? (
                <div className="flex flex-1 flex-col items-start gap-2 px-6 py-8">
                    <span className="grid size-9 place-items-center rounded-md bg-live-soft text-live">
                        <Icon name="check" className="size-4.5" />
                    </span>
                    <p className="t-title">{published ? 'Your live pages look healthy' : 'Nothing published to check yet'}</p>
                    <p className="t-meta">
                        {published
                            ? 'No high or medium priority issues on published pages. Drafts are checked in the list below.'
                            : 'Issues on live pages appear here once you publish.'}
                    </p>
                </div>
            ) : (
                <ol className="divide-y divide-line">
                    {items.slice(0, 5).map((item) => (
                        <li key={item.id} className="flex gap-3 px-6 py-4" data-testid="seo-attention-item">
                            <Icon name="alert" className={`mt-0.5 size-4 shrink-0 ${SEVERITY[item.severity].className}`} />
                            <div className="min-w-0 flex-1">
                                <p className="flex flex-wrap items-baseline gap-x-2">
                                    <span className="font-medium">{item.label}</span>
                                    <span className="t-meta">
                                        {SEVERITY[item.severity].label} · {plural(item.pages.length, 'page')}
                                    </span>
                                    {item.aiFixable && (
                                        <span className="inline-flex h-5 items-center gap-1 self-center rounded-sm bg-ai-soft px-1.5 text-2xs font-medium text-ai">
                                            <Icon name="sparkle" className="size-3" />
                                            AI can draft
                                        </span>
                                    )}
                                </p>
                                {WHY[item.id] && <p className="mt-0.5 t-meta">{WHY[item.id]}</p>}
                                <p className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-sm">
                                    {item.pages.slice(0, 3).map((p) => (
                                        <Link key={p.id} href={editorSeo(p.id)} className="ui-link">
                                            {p.title}
                                        </Link>
                                    ))}
                                    {item.pages.length > 3 && <span className="t-meta">+{item.pages.length - 3} more</span>}
                                </p>
                            </div>
                        </li>
                    ))}
                    {items.length > 5 && <li className="px-6 py-3 t-meta">{plural(items.length - 5, 'more issue type')} in the page list below.</li>}
                </ol>
            )}
            {canEdit && first && (
                <div className="mt-auto flex flex-wrap items-center gap-x-4 gap-y-3 border-t border-line bg-ai-soft/40 px-6 py-4" data-testid="seo-ai">
                    <Icon name="sparkle" className="size-4 shrink-0 text-ai" />
                    <p className="min-w-0 flex-1 text-sm">
                        <span className="font-medium">Arkon AI</span> can draft fixes for {plural(fixable.length, 'issue type')} on{' '}
                        {plural(fixablePages.size, 'page')}. You review every change; nothing is published.
                    </p>
                    <ButtonLink href={editorSeo(first.id, true)} icon="sparkle" size="sm">
                        Start with {first.title}
                    </ButtonLink>
                </div>
            )}
        </Panel>
    );
}

function Pages({ rows, page, total, perPage, q, status, counts, canEdit }: Props & { canEdit: boolean }) {
    const [search, setSearch] = useState(q);
    const first = useRef(true);
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ status, q: search.trim() }), 300);
        return () => clearTimeout(timer);
    }, [search]); // eslint-disable-line react-hooks/exhaustive-deps
    const showSearch = counts.all > 10 || q !== '';
    const pages = Math.max(1, Math.ceil(total / perPage));
    const options: { value: Filter; label: string }[] = [
        { value: 'all', label: 'All' },
        { value: 'excellent', label: 'Excellent' },
        { value: 'good', label: 'Good' },
        { value: 'improvement', label: 'Needs improvement' },
        { value: 'attention', label: 'Needs attention' },
        { value: 'unpublished', label: 'Not published' },
    ];

    return (
        <section aria-labelledby="seo-pages-title" id="seo-pages" className="scroll-mt-24 space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 id="seo-pages-title" className="t-section">
                        Pages
                    </h2>
                    <p className="mt-0.5 t-meta">Draft and live scores stay separate: saving never changes live metadata.</p>
                </div>
                {showSearch && (
                    <label className="relative block w-full sm:w-64">
                        <span className="sr-only">Search pages</span>
                        <Icon name="search" className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-faint" />
                        <input className="ui-input pl-8" type="search" placeholder="Search pages…" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </label>
                )}
            </div>
            <div className="max-w-full overflow-x-auto">
                <Segmented<Filter>
                    label="Filter pages by live SEO health"
                    value={status}
                    onChange={(value) => visit({ status: value, q })}
                    options={options.map((o) => ({ ...o, badge: <span className="t-num text-faint">{counts[o.value]}</span> }))}
                />
            </div>
            {rows.length ? (
                <div className="ui-management-list max-w-none">
                    <table className="ui-management-table w-full text-left text-sm" data-testid="seo-pages">
                        <caption className="sr-only">Page SEO, draft and live</caption>
                        <thead>
                            <tr>
                                <th scope="col" className="p-3 text-xs font-medium text-muted">
                                    Page
                                </th>
                                <th scope="col" className="ui-col-status p-3 text-xs font-medium text-muted">
                                    Draft
                                </th>
                                <th scope="col" className="ui-col-status p-3 text-xs font-medium text-muted">
                                    Live
                                </th>
                                <th scope="col" className="p-3 text-xs font-medium text-muted">
                                    Main issue
                                </th>
                                <th scope="col" className="ui-col-actions p-3">
                                    <span className="sr-only">Action</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <PageRow key={row.id} row={row} canEdit={canEdit} />
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <EmptyState
                    icon={q || status !== 'all' ? 'search' : 'globe'}
                    title={q || status !== 'all' ? 'No pages match' : 'Create a page to start managing SEO'}
                >
                    {q || status !== 'all' ? 'Try another filter or search.' : null}
                </EmptyState>
            )}
            {pages > 1 && (
                <nav aria-label="Pages of results" className="flex items-center justify-between text-sm">
                    <span className="t-meta">
                        Page {page} of {pages} · {plural(total, 'page')}
                    </span>
                    <div className="flex gap-4">
                        {page > 1 && (
                            <button type="button" className="ui-link" onClick={() => visit({ status, q, page: page - 1 })}>
                                Previous
                            </button>
                        )}
                        {page < pages && (
                            <button type="button" className="ui-link" onClick={() => visit({ status, q, page: page + 1 })}>
                                Next
                            </button>
                        )}
                    </div>
                </nav>
            )}
        </section>
    );
}

function PageRow({ row, canEdit }: { row: Row; canEdit: boolean }) {
    const main = row.issues[0];
    const delta = row.draft && row.published ? row.draft.score - row.published.score : 0;
    return (
        <tr className="border-t border-line" data-testid="seo-page-row">
            <th scope="row" className="ui-cell-name p-3 text-left font-normal">
                <Link className="font-medium hover:text-accent hover:underline" href={editorSeo(row.id)}>
                    {row.title}
                </Link>
                <span className="block truncate t-meta">{row.path}</span>
            </th>
            <td className="p-3">
                <span className="ui-mobile-label t-meta">Draft </span>
                {row.draft ? (
                    <>
                        <ScoreMark score={row.draft.score} label={row.draft.label} />
                        {delta !== 0 && (
                            <span className={`block text-2xs ${delta > 0 ? 'text-live' : 'text-changed'}`}>
                                {delta > 0 ? `+${delta}` : `−${-delta}`} vs live{delta > 0 ? ' · publish to apply' : ''}
                            </span>
                        )}
                    </>
                ) : (
                    <span className="text-danger">Needs repair</span>
                )}
            </td>
            <td className="p-3">
                <span className="ui-mobile-label t-meta">Live </span>
                {row.published ? <ScoreMark score={row.published.score} label={row.published.label} /> : <span className="t-meta">Not published</span>}
            </td>
            <td className="p-3">
                {main ? (
                    <span className="block">
                        <span className={`mr-1.5 text-2xs font-semibold uppercase ${SEVERITY[main.severity].className}`}>{SEVERITY[main.severity].label}</span>
                        <span title={main.message}>{main.label}</span>
                        <span className="block t-meta">
                            {[
                                row.issues.length > 1 && `+${plural(row.issues.length - 1, 'more issue')}`,
                                row.suggestions > 0 && plural(row.suggestions, 'suggestion'),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </span>
                ) : row.needsRepair ? (
                    <span className="t-meta">Repair the draft to check it.</span>
                ) : (
                    <span className="t-meta">No issues{row.suggestions > 0 ? ` · ${plural(row.suggestions, 'suggestion')}` : ''}</span>
                )}
            </td>
            <td className="ui-cell-actions p-3 text-right">
                {row.needsRepair ? (
                    <ButtonLink size="sm" href={`/admin/editor/${row.id}`}>
                        Repair draft
                    </ButtonLink>
                ) : main && main.severity !== 'suggestion' && main.aiFixable && canEdit ? (
                    <ButtonLink size="sm" icon="sparkle" href={editorSeo(row.id, true)}>
                        Fix with AI
                    </ButtonLink>
                ) : main && main.severity !== 'suggestion' ? (
                    <ButtonLink size="sm" href={editorSeo(row.id)}>
                        Review
                    </ButtonLink>
                ) : (
                    <ButtonLink size="sm" variant="ghost" href={editorSeo(row.id)}>
                        View details
                    </ButtonLink>
                )}
            </td>
        </tr>
    );
}

function DiagnosticRow({ item, origin }: { item: Diagnostic; origin: string }) {
    const status = DIAGNOSTIC[item.status];
    return (
        <li className="flex gap-3" data-testid={`seo-check-${item.id}`}>
            <span className={`mt-0.5 shrink-0 ${status.className}`}>
                <Icon name={status.icon} className="size-4" />
                <span className="sr-only">{status.label}: </span>
            </span>
            <div className="min-w-0 flex-1">
                <p className="flex items-baseline justify-between gap-3">
                    <span className="font-medium">{item.label}</span>
                    {item.href && (
                        <a className="ui-link inline-flex shrink-0 items-center gap-1 text-xs" href={origin + item.href} target="_blank" rel="noreferrer">
                            View
                            <Icon name="external" className="size-3" />
                        </a>
                    )}
                </p>
                <p className="mt-0.5 t-meta break-words">{item.detail}</p>
            </div>
        </li>
    );
}
