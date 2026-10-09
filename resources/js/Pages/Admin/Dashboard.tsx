import { Head, Link, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/Components/AdminLayout';
import { STATUS_LABEL } from '@/Components/PagesTable';
import { ButtonLink, EmptyState } from '@/Components/ui';
import { fullDate, relativeTime } from '@/lib/time';
import type { PageRow, PageStatus, SharedProps } from '@/types';

type Filter = 'all' | PageStatus;

interface Overview {
    activity: {
        id: string;
        actor: string | null;
        verb: string;
        target: string | null;
        href: string | null;
        at: string;
        kind: string;
        version: number | null;
    }[];
    proposals: { id: string; prompt: string; pageId: string; page: string; at: string }[];
    refreshes: { pending: number; failed: number };
    design: { tokensChanged: boolean; componentsChanged: number };
}

const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

/** One sentence on what needs attention, most urgent first. */
function summary(counts: Record<Filter, number>): string {
    if (counts.all === 0) return 'Create a page to start building your site.';
    if (counts.changed > 0) return `${plural(counts.changed, 'page has', 'pages have')} changes that are not live yet.`;
    if (counts.draft > 0) return `${plural(counts.draft, 'page is', 'pages are')} not published yet.`;
    return 'Every page is live and up to date.';
}

export default function Dashboard({
    pages,
    counts,
    homepage,
    overview,
}: {
    pages: PageRow[];
    counts: Record<Filter, number>;
    homepage: PageRow | null;
    overview: Overview;
}) {
    const { auth, site, can } = usePage<SharedProps>().props;
    return (
        <AdminLayout>
            <Head title="Dashboard" />
            <div className="mx-auto max-w-6xl space-y-8 px-4 py-6 sm:px-8 sm:py-8">
                <header className="flex flex-wrap justify-between gap-4">
                    <div>
                        <p className="text-xs text-muted">{site?.name}</p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">Welcome, {auth.user?.name}</h1>
                        <p className="mt-2 text-sm text-muted">{summary(counts)}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {homepage && (
                            <ButtonLink href={'/admin/editor/' + homepage.id} icon="home">
                                {can['page.edit'] ? 'Edit homepage' : 'View homepage'}
                            </ButtonLink>
                        )}
                        {can['page.create'] && (
                            <ButtonLink href="/admin/pages?new=1" variant="primary" icon="plus">
                                New page
                            </ButtonLink>
                        )}
                    </div>
                </header>
                <section aria-label="Website overview" className="flex flex-wrap gap-x-10 gap-y-3 border-y border-line py-5 text-sm">
                    <p>
                        <strong className="text-xl">{counts.all}</strong> {counts.all === 1 ? 'page' : 'pages'}
                    </p>
                    <p>
                        <strong>{counts.published + counts.changed}</strong> live
                    </p>
                    <p>
                        <strong>{counts.draft}</strong> unpublished
                    </p>
                    <p>
                        <strong>{counts.changed}</strong> with draft changes
                    </p>
                    {site?.url && (
                        <a href={site.url} className="text-accent hover:underline" target="_blank" rel="noreferrer">
                            View website ↗
                        </a>
                    )}
                </section>
                {(!homepage || counts.published + counts.changed === 0) && (
                    <section className="space-y-2" aria-labelledby="setup-title">
                        <h2 id="setup-title" className="text-base font-semibold">
                            Get your site ready
                        </h2>
                        <p className="text-sm text-muted">
                            {homepage ? 'Your homepage is saved. Open the builder to preview and publish it.' : 'Create a page at / to set up your homepage.'}
                        </p>
                        <div className="flex flex-wrap gap-4 text-sm">
                            <Link href="/admin/pages" className="text-accent">
                                Set up pages
                            </Link>
                            <Link href="/admin/navigation" className="text-accent">
                                Review navigation
                            </Link>
                            {can['page.publish'] && (
                                <Link href="/admin/settings" className="text-accent">
                                    Review site identity
                                </Link>
                            )}
                        </div>
                    </section>
                )}
                <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,0.7fr)]">
                    <section aria-labelledby="recent-content">
                        <div className="flex justify-between gap-3">
                            <h2 id="recent-content" className="text-base font-semibold">
                                Recent content
                            </h2>
                            <Link href="/admin/pages" className="text-sm text-accent">
                                View all pages →
                            </Link>
                        </div>
                        {pages.length ? (
                            <ul className="mt-3 divide-y divide-line">
                                {pages.map((page) => (
                                    <li key={page.id} className="flex items-center justify-between gap-3 py-3">
                                        <div className="min-w-0">
                                            <Link className="block truncate text-sm font-medium hover:text-accent" href={'/admin/editor/' + page.id}>
                                                {page.title}
                                            </Link>
                                            <p className="mt-1 text-xs text-muted">
                                                {STATUS_LABEL[page.status]} · {page.updatedAt ? relativeTime(page.updatedAt) : 'Recently created'}
                                            </p>
                                        </div>
                                        <ButtonLink href={'/admin/editor/' + page.id} size="sm">
                                            Open builder
                                        </ButtonLink>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <EmptyState icon="pages" title="Your website starts with a page">
                                Create a page manually or prepare editable drafts with AI.
                            </EmptyState>
                        )}
                    </section>
                    <section aria-labelledby="attention-title" data-testid="attention">
                        <h2 id="attention-title" className="text-base font-semibold">
                            Needs attention
                        </h2>
                        <ul className="mt-3 space-y-3 text-sm">
                            {overview.proposals.map((p) => (
                                <li key={p.id}>
                                    <Link href={'/admin/editor/' + p.pageId} className="text-accent">
                                        Review AI proposal for {p.page}
                                    </Link>
                                </li>
                            ))}
                            {counts.changed > 0 && (
                                <li>
                                    <Link href="/admin/pages?status=changed" className="text-accent">
                                        Review {counts.changed} pages with unpublished changes
                                    </Link>
                                </li>
                            )}
                            {(overview.refreshes.failed > 0 || overview.refreshes.pending > 0) && (
                                <li>
                                    <Link href="/admin/performance" className="text-danger">
                                        {overview.refreshes.failed} failed · {overview.refreshes.pending} pending live-page updates
                                    </Link>
                                </li>
                            )}
                            {overview.design.tokensChanged && (
                                <li>
                                    <Link href="/admin/design" className="text-accent">
                                        Review unpublished global styles
                                    </Link>
                                </li>
                            )}
                            {overview.design.componentsChanged > 0 && (
                                <li>
                                    <Link href="/admin/design/components" className="text-accent">
                                        Review {overview.design.componentsChanged} changed components
                                    </Link>
                                </li>
                            )}
                            {overview.proposals.length === 0 &&
                                counts.changed === 0 &&
                                !overview.design.tokensChanged &&
                                overview.design.componentsChanged === 0 &&
                                overview.refreshes.pending === 0 &&
                                overview.refreshes.failed === 0 && <li className="text-muted">No pending actions in recorded publishing activity.</li>}
                        </ul>
                        <div className="mt-5 border-t border-line pt-4 text-sm">
                            <Link href="/admin/seo" className="text-accent">
                                Review SEO metadata →
                            </Link>
                            <p className="mt-2 text-xs text-muted">Performance and indexing have not been measured for this site.</p>
                        </div>
                    </section>
                </div>
                <section aria-labelledby="activity-title" data-testid="activity" className="border-t border-line pt-6">
                    <h2 id="activity-title" className="text-base font-semibold">
                        Recent activity
                    </h2>
                    {overview.activity.length ? (
                        <ul className="mt-3 space-y-3 text-sm">
                            {overview.activity.slice(0, 5).map((item) => (
                                <li key={item.id} className="flex flex-wrap justify-between gap-2">
                                    <span>
                                        {item.actor ?? 'A team member'} {item.verb}{' '}
                                        {item.href ? (
                                            <Link className="text-accent" href={item.href}>
                                                {item.target}
                                            </Link>
                                        ) : (
                                            item.target
                                        )}
                                    </span>
                                    <time className="text-xs text-muted" dateTime={item.at} title={fullDate(item.at)}>
                                        {relativeTime(item.at)}
                                    </time>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-3 text-sm text-muted">Publishing and design changes will appear here.</p>
                    )}
                </section>
                {can['page.edit'] && (
                    <section className="flex flex-wrap items-center justify-between gap-4 border-t border-line pt-6">
                        <div>
                            <h2 className="text-base font-semibold">Build with Arkon AI</h2>
                            <p className="mt-1 text-sm text-muted">
                                Prepare a website proposal, or open a page builder to ask for a specific change. Review before applying.
                            </p>
                        </div>
                        <ButtonLink href="/admin/website" icon="sparkle">
                            Build a website
                        </ButtonLink>
                    </section>
                )}
            </div>
        </AdminLayout>
    );
}
