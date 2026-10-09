import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { Icon, type IconName } from '@/Components/Icon';
import { STATUS_LABEL, StatusMark } from '@/Components/PagesTable';
import { Avatar, ButtonLink, EmptyState, PageShell } from '@/Components/ui';
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

function greeting(now = new Date()): string {
    const hour = now.getHours();
    if (hour >= 5 && hour < 12) return 'Good morning';
    if (hour >= 12 && hour < 18) return 'Good afternoon';
    return 'Good evening';
}

const host = (url: string) => url.replace(/^https?:\/\//, '').replace(/\/$/, '');

interface AttentionItem {
    key: string;
    href: string;
    icon: IconName;
    tone: string;
    label: string;
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
    const live = counts.published + counts.changed;
    const failing = overview.refreshes.failed > 0;
    const healthTone = failing ? 'bg-danger' : counts.changed > 0 ? 'bg-changed' : counts.all > 0 && counts.draft === 0 ? 'bg-live' : 'bg-draft';
    const [latest, ...rest] = pages;

    const attention: AttentionItem[] = [
        ...overview.proposals.map((p) => ({
            key: 'proposal-' + p.id,
            href: '/admin/editor/' + p.pageId,
            icon: 'sparkle' as const,
            tone: 'text-ai',
            label: `Review AI proposal for ${p.page}`,
        })),
        ...(counts.changed > 0
            ? [
                  {
                      key: 'changed',
                      href: '/admin/pages?status=changed',
                      icon: 'dots' as const,
                      tone: 'text-changed',
                      label: `Review ${counts.changed} pages with unpublished changes`,
                  },
              ]
            : []),
        ...(failing || overview.refreshes.pending > 0
            ? [
                  {
                      key: 'refreshes',
                      href: '/admin/performance',
                      icon: 'alert' as const,
                      tone: 'text-danger',
                      label: `${overview.refreshes.failed} failed · ${overview.refreshes.pending} pending live-page updates`,
                  },
              ]
            : []),
        ...(overview.design.tokensChanged
            ? [{ key: 'tokens', href: '/admin/design', icon: 'palette' as const, tone: 'text-changed', label: 'Review unpublished global styles' }]
            : []),
        ...(overview.design.componentsChanged > 0
            ? [
                  {
                      key: 'components',
                      href: '/admin/design/components',
                      icon: 'component' as const,
                      tone: 'text-changed',
                      label: `Review ${overview.design.componentsChanged} changed components`,
                  },
              ]
            : []),
    ];

    const setup = !homepage || live === 0;
    const setupSteps = [
        { done: !!homepage, href: '/admin/pages', label: homepage ? 'Homepage created' : 'Create a page at /' },
        {
            done: !!homepage && homepage.status !== 'draft',
            href: homepage ? '/admin/editor/' + homepage.id : '/admin/pages',
            label: 'Preview and publish the homepage',
        },
        { done: false, href: '/admin/navigation', label: 'Review navigation' },
        ...(can['page.publish'] ? [{ done: false, href: '/admin/settings', label: 'Review site identity' }] : []),
    ];

    return (
        <AdminLayout>
            <Head title="Dashboard" />
            <PageShell>
                <header className="flex flex-wrap items-center justify-between gap-x-8 gap-y-5">
                    <div className="min-w-0">
                        <p className="flex items-center gap-2 t-meta">
                            <span className="font-medium text-fg">{site?.name}</span>
                            {site?.url && (
                                <>
                                    <span aria-hidden="true" className="text-faint">
                                        /
                                    </span>
                                    <span className="truncate font-mono text-xs">{host(site.url)}</span>
                                </>
                            )}
                        </p>
                        <h1 className="mt-2 t-page">
                            {greeting()}, {auth.user?.name}
                        </h1>
                        <p className="mt-1.5 text-base text-muted">Here’s what is happening with your website today.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2.5">
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

                {/* 1 · Where the site stands, and what needs a decision. */}
                <div className="mt-9 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <section aria-labelledby="overview-title" className="overflow-hidden rounded-lg border border-line bg-surface shadow-hairline">
                        <PanelHeading
                            id="overview-title"
                            title="Site overview"
                            aside={
                                <span className="inline-flex items-center gap-2 text-ui text-muted" data-testid="site-health">
                                    <span aria-hidden="true" className={`size-2 shrink-0 rounded-full ${healthTone}`} />
                                    {summary(counts)}
                                </span>
                            }
                        />
                        <div className="grid gap-x-10 gap-y-6 px-6 pt-6 pb-5 sm:grid-cols-[auto_minmax(0,1fr)]">
                            <div>
                                <p className="t-label">Pages</p>
                                <p className="mt-1 text-[2.5rem] leading-none font-semibold tracking-tight t-num">{counts.all}</p>
                                <p className="mt-2 t-meta t-num">
                                    {live} of {counts.all} live
                                </p>
                            </div>
                            <div className="min-w-0 self-end">
                                <ul className="grid grid-cols-3 gap-4">
                                    <Metric status="published" count={counts.published} label="Live" />
                                    <Metric status="changed" count={counts.changed} label="Draft changes" />
                                    <Metric status="draft" count={counts.draft} label="Unpublished" />
                                </ul>
                                <StateBar counts={counts} />
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-line bg-raised px-6 py-3.5 text-ui">
                            <span className="t-label">Homepage</span>
                            {homepage ? (
                                <span className="inline-flex items-center gap-2 font-medium">
                                    <StatusMark status={homepage.status} />
                                    {STATUS_LABEL[homepage.status]}
                                    <span className="font-normal text-muted">
                                        {homepage.publishedAt ? `· published ${relativeTime(homepage.publishedAt)}` : '· not live yet'}
                                    </span>
                                </span>
                            ) : (
                                <span className="text-muted">No page at / yet</span>
                            )}
                            {site?.url && (
                                <a href={site.url} className="ui-link ml-auto inline-flex items-center gap-1.5" target="_blank" rel="noreferrer">
                                    View website
                                    <Icon name="external" className="size-3.5" />
                                </a>
                            )}
                        </div>
                    </section>

                    <section
                        aria-labelledby="attention-title"
                        data-testid="attention"
                        className="flex flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-hairline"
                    >
                        <PanelHeading
                            id="attention-title"
                            title="Needs attention"
                            aside={
                                attention.length > 0 ? (
                                    <span className="rounded-sm bg-changed-soft px-2 py-0.5 text-xs font-semibold text-changed t-num">{attention.length}</span>
                                ) : undefined
                            }
                        />
                        {attention.length ? (
                            <ul className="flex-1 divide-y divide-line">
                                {attention.map((item) => (
                                    <li key={item.key}>
                                        <Link href={item.href} className="group flex items-center gap-3 px-6 py-3.5 text-sm hover:bg-hover">
                                            <span className="grid size-8 shrink-0 place-items-center rounded-md bg-sunken">
                                                <Icon name={item.icon} className={`size-4 ${item.tone}`} />
                                            </span>
                                            <span className="min-w-0 flex-1">{item.label}</span>
                                            <Icon name="chevronRight" className="size-4 text-faint group-hover:text-muted" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="flex flex-1 items-start gap-3 px-6 py-5">
                                <span className="grid size-8 shrink-0 place-items-center rounded-md bg-live-soft">
                                    <Icon name="check" className="size-4 text-live" />
                                </span>
                                <div>
                                    <p className="t-title">All clear</p>
                                    <p className="mt-0.5 t-meta">No pending actions in recorded publishing activity.</p>
                                </div>
                            </div>
                        )}
                        <p className="border-t border-line px-6 py-3 t-meta">Performance and indexing have not been measured for this site.</p>
                    </section>
                </div>

                {/* 2 · The work itself, and the shortest ways to more of it. */}
                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <section aria-labelledby="recent-content" className="min-w-0 overflow-hidden rounded-lg border border-line bg-surface shadow-hairline">
                        <PanelHeading
                            id="recent-content"
                            title="Recent content"
                            aside={
                                <Link href="/admin/pages" className="ui-link inline-flex items-center gap-1 text-ui font-medium">
                                    View all pages
                                    <Icon name="chevronRight" className="size-3.5" />
                                </Link>
                            }
                        />
                        {latest ? (
                            <>
                                <div className="flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-line bg-raised px-6 py-5">
                                    <span className="grid size-11 shrink-0 place-items-center rounded-lg border border-line bg-surface text-muted shadow-hairline max-sm:hidden">
                                        <Icon name="pages" className="size-5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="t-eyebrow">Continue editing</p>
                                        <Link className="mt-1 block truncate text-base font-semibold hover:text-accent" href={'/admin/editor/' + latest.id}>
                                            {latest.title}
                                        </Link>
                                        <p className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-0.5 t-meta">
                                            <span className="font-mono text-xs">{latest.path}</span>
                                            <span className="inline-flex items-center gap-1.5">
                                                <StatusMark status={latest.status} />
                                                {STATUS_LABEL[latest.status]}
                                            </span>
                                            <span>{latest.updatedAt ? `Edited ${relativeTime(latest.updatedAt)}` : 'Recently created'}</span>
                                        </p>
                                    </div>
                                    <ButtonLink href={'/admin/editor/' + latest.id} variant="primary" icon="layers">
                                        Open builder
                                    </ButtonLink>
                                </div>
                                {rest.length > 0 && (
                                    <>
                                        <div
                                            aria-hidden="true"
                                            className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-6 px-6 pt-4 pb-1 t-eyebrow sm:grid-cols-[minmax(0,1fr)_11rem_7rem_1rem]"
                                        >
                                            <span>Page</span>
                                            <span className="max-sm:hidden">Status</span>
                                            <span className="text-right">Edited</span>
                                            <span className="max-sm:hidden" />
                                        </div>
                                        <ul className="px-3 pb-3">
                                            {rest.map((page) => (
                                                <li key={page.id}>
                                                    <Link
                                                        href={'/admin/editor/' + page.id}
                                                        className="group grid min-h-14 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-6 rounded-md px-3 py-2 hover:bg-hover sm:grid-cols-[minmax(0,1fr)_11rem_7rem_1rem]"
                                                    >
                                                        <span className="min-w-0">
                                                            <span className="block truncate text-sm font-medium group-hover:text-accent">{page.title}</span>
                                                            <span className="block truncate font-mono text-xs text-muted">{page.path}</span>
                                                        </span>
                                                        <span className="inline-flex items-center gap-2 text-ui text-muted max-sm:hidden">
                                                            <StatusMark status={page.status} />
                                                            {STATUS_LABEL[page.status]}
                                                        </span>
                                                        <span
                                                            className="text-right text-ui text-muted t-num"
                                                            title={page.updatedAt ? fullDate(page.updatedAt) : undefined}
                                                        >
                                                            {page.updatedAt ? relativeTime(page.updatedAt) : 'New'}
                                                        </span>
                                                        <Icon name="chevronRight" className="size-4 text-faint group-hover:text-muted max-sm:hidden" />
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </>
                        ) : (
                            <EmptyState
                                icon="pages"
                                title="Your website starts with a page"
                                action={
                                    can['page.create'] && (
                                        <ButtonLink href="/admin/pages?new=1" variant="primary" icon="plus">
                                            New page
                                        </ButtonLink>
                                    )
                                }
                            >
                                Create a page manually or prepare editable drafts with AI.
                            </EmptyState>
                        )}
                    </section>

                    <div className="space-y-6">
                        {setup && (
                            <section aria-labelledby="setup-title" className="overflow-hidden rounded-lg border border-line bg-surface shadow-hairline">
                                <PanelHeading
                                    id="setup-title"
                                    title="Get your site ready"
                                    aside={
                                        <span className="t-meta t-num">
                                            {setupSteps.filter((step) => step.done).length} of {setupSteps.length}
                                        </span>
                                    }
                                />
                                <ol className="space-y-0.5 p-3">
                                    {setupSteps.map((step) => (
                                        <li key={step.label}>
                                            <Link href={step.href} className="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm hover:bg-hover">
                                                {step.done ? (
                                                    <span className="grid size-5 shrink-0 place-items-center rounded-full bg-live text-surface">
                                                        <Icon name="check" className="size-3" />
                                                    </span>
                                                ) : (
                                                    <span
                                                        aria-hidden="true"
                                                        className="size-5 shrink-0 rounded-full border-[1.5px] border-dashed border-line-strong"
                                                    />
                                                )}
                                                <span className={step.done ? 'text-muted line-through decoration-line-strong' : ''}>{step.label}</span>
                                                {step.done && <span className="sr-only">(done)</span>}
                                            </Link>
                                        </li>
                                    ))}
                                </ol>
                            </section>
                        )}

                        <section aria-labelledby="shortcuts-title" className="overflow-hidden rounded-lg border border-line bg-surface shadow-hairline">
                            <PanelHeading id="shortcuts-title" title="Quick actions" />
                            <ul className="space-y-0.5 p-3">
                                {can['page.create'] && (
                                    <QuickAction href="/admin/pages?new=1" icon="plus" label="Create a page" detail="A new unpublished draft" />
                                )}
                                {can['media.view'] && (
                                    <QuickAction href="/admin/media" icon="image" label="Media library" detail="Upload and describe images" />
                                )}
                                <QuickAction href="/admin/navigation" icon="menu" label="Navigation" detail="Menus, header and footer" />
                                <QuickAction href="/admin/design" icon="palette" label="Global styles" detail="Colours, fonts and spacing" />
                                <QuickAction href="/admin/seo" icon="globe" label="Review SEO metadata" detail="Titles and descriptions" />
                            </ul>
                            {can['page.edit'] && (
                                <div className="border-t border-line bg-ai-soft/60 px-6 py-4">
                                    <p className="flex items-center gap-2 t-title">
                                        <Icon name="sparkle" className="size-4 text-ai" />
                                        Build with Arkon AI
                                    </p>
                                    <p className="mt-1 t-meta">
                                        Prepare a website proposal, or open a page builder to ask for a change. Review before applying.
                                    </p>
                                    <ButtonLink href="/admin/website" size="sm" className="mt-3">
                                        Build a website
                                    </ButtonLink>
                                </div>
                            )}
                        </section>
                    </div>
                </div>

                {/* 3 · What happened. */}
                <section
                    aria-labelledby="activity-title"
                    data-testid="activity"
                    className="mt-6 overflow-hidden rounded-lg border border-line bg-surface shadow-hairline"
                >
                    <PanelHeading id="activity-title" title="Recent activity" aside={<span className="t-meta">Publishing, restores and design changes</span>} />
                    {overview.activity.length ? (
                        <ol className="relative space-y-5 px-6 py-5 before:absolute before:top-8 before:bottom-8 before:left-[2.375rem] before:w-px before:bg-line">
                            {overview.activity.slice(0, 6).map((item) => (
                                <li key={item.id} className="relative flex items-start gap-4">
                                    <Avatar name={item.actor ?? 'Team member'} className="relative size-7 text-2xs ring-4 ring-surface" />
                                    <p className="min-w-0 flex-1 pt-1 text-sm">
                                        <span className="font-medium">{item.actor ?? 'A team member'}</span> <span className="text-muted">{item.verb}</span>{' '}
                                        {item.href ? (
                                            <Link className="ui-link" href={item.href}>
                                                {item.target}
                                            </Link>
                                        ) : (
                                            item.target
                                        )}
                                    </p>
                                    <time className="shrink-0 pt-1 t-meta t-num" dateTime={item.at} title={fullDate(item.at)}>
                                        {relativeTime(item.at)}
                                    </time>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p className="px-6 py-5 text-sm text-muted">Publishing and design changes will appear here.</p>
                    )}
                </section>
            </PageShell>
        </AdminLayout>
    );
}

/** A panel's title row: the same height, padding and type in every dashboard panel. */
function PanelHeading({ id, title, aside }: { id: string; title: string; aside?: ReactNode }) {
    return (
        <div className="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-line px-6 py-3">
            <h2 id={id} className="t-section">
                {title}
            </h2>
            {aside}
        </div>
    );
}

/** Live, changed and unpublished as one proportional bar (the metrics above it carry the words). */
function StateBar({ counts }: { counts: Record<Filter, number> }) {
    const total = Math.max(1, counts.all);
    const segments: [number, string][] = [
        [counts.published, 'bg-live'],
        [counts.changed, 'bg-changed'],
        [counts.draft, 'bg-line-strong'],
    ];
    return (
        <div aria-hidden="true" className="mt-4 flex h-2 gap-0.5 overflow-hidden rounded-full bg-sunken">
            {counts.all > 0 &&
                segments.map(([count, color], i) =>
                    count ? <span key={i} className={`${color} h-full`} style={{ width: `${(count / total) * 100}%` }} /> : null,
                )}
        </div>
    );
}

function Metric({ status, count, label }: { status: PageStatus; count: number; label: string }) {
    return (
        <li>
            <Link href={`/admin/pages?status=${status}`} aria-label={`${count} ${label.toLowerCase()}`} className="group block rounded-md outline-offset-4">
                <span className="flex items-center gap-1.5 t-label group-hover:text-fg">
                    <StatusMark status={status} />
                    {label}
                </span>
                <span className="mt-1 block text-2xl font-semibold t-num">{count}</span>
            </Link>
        </li>
    );
}

function QuickAction({ href, icon, label, detail }: { href: string; icon: IconName; label: string; detail: string }) {
    return (
        <li>
            <Link href={href} className="group flex items-center gap-3 rounded-md px-3 py-2.5 hover:bg-hover">
                <span className="grid size-9 shrink-0 place-items-center rounded-md border border-line bg-raised text-muted group-hover:text-accent">
                    <Icon name={icon} className="size-[18px]" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium">{label}</span>
                    <span className="block truncate t-meta">{detail}</span>
                </span>
                <Icon name="chevronRight" className="size-4 text-faint opacity-0 transition-opacity group-hover:opacity-100" />
            </Link>
        </li>
    );
}
