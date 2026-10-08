import { Head, Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { Icon, type IconName } from '@/Components/Icon';
import { PagesTable, STATUS_LABEL, StatusMark } from '@/Components/PagesTable';
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

const FILTERS: { value: Filter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'changed', label: STATUS_LABEL.changed },
    { value: 'draft', label: STATUS_LABEL.draft },
    { value: 'published', label: 'Live' },
];

const EMPTY: Record<Filter, string> = {
    all: 'No pages yet.',
    changed: 'Every live page matches its draft.',
    draft: 'Every page has been published.',
    published: 'No page is live yet.',
};

const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

/** One sentence on what needs attention, most urgent first. */
function summary(counts: Record<Filter, number>): string {
    if (counts.all === 0) return 'Create a page to start building your site.';
    if (counts.changed > 0) return `${plural(counts.changed, 'page has', 'pages have')} changes that are not live yet.`;
    if (counts.draft > 0) return `${plural(counts.draft, 'page is', 'pages are')} not published yet.`;
    return 'Every page is live and up to date.';
}

const ACTIVITY_ICON: Record<string, IconName> = { page: 'pages', component: 'component', tokens: 'palette' };

export default function Dashboard({ pages, overview }: { pages: PageRow[]; overview: Overview }) {
    const { auth, site, can } = usePage<SharedProps>().props;
    const [filter, setFilter] = useState<Filter>('all');
    const counts: Record<Filter, number> = {
        all: pages.length,
        changed: pages.filter((p) => p.status === 'changed').length,
        draft: pages.filter((p) => p.status === 'draft').length,
        published: pages.filter((p) => p.status === 'published').length,
    };
    const shown = filter === 'all' ? pages : pages.filter((p) => p.status === filter);
    const recent = [...pages].filter((p) => p.updatedAt).sort((a, b) => (b.updatedAt ?? '').localeCompare(a.updatedAt ?? ''))[0];

    const attention: { key: string; icon: IconName; tone: string; text: ReactNode; href: string | null; action?: () => void; actionLabel: string }[] = [];
    for (const proposal of overview.proposals) {
        attention.push({
            key: `ai-${proposal.id}`,
            icon: 'sparkle',
            tone: 'text-ai',
            text: (
                <>
                    AI proposal ready for <strong className="font-medium">{proposal.page}</strong>: “{proposal.prompt}”
                </>
            ),
            href: `/admin/editor/${proposal.pageId}`,
            actionLabel: 'Review',
        });
    }
    if (overview.refreshes.failed > 0 || overview.refreshes.pending > 0)
        attention.push({
            key: 'refreshes',
            icon: 'alert',
            tone: 'text-danger',
            text:
                overview.refreshes.failed > 0
                    ? `${plural(overview.refreshes.failed, 'live page', 'live pages')} could not be updated after a design change.`
                    : `${plural(overview.refreshes.pending, 'live page is', 'live pages are')} waiting to be updated after a design change.`,
            href: '/admin/design',
            actionLabel: 'Open Design',
        });
    if (counts.changed > 0)
        attention.push({
            key: 'changed',
            icon: 'clock',
            tone: 'text-changed',
            text: `${plural(counts.changed, 'page has', 'pages have')} saved changes that are not live yet.`,
            href: null,
            action: () => setFilter('changed'),
            actionLabel: 'Show',
        });
    if (overview.design.tokensChanged || overview.design.componentsChanged > 0)
        attention.push({
            key: 'design',
            icon: 'palette',
            tone: 'text-site',
            text: [
                overview.design.tokensChanged ? 'The design token draft is not published.' : null,
                overview.design.componentsChanged > 0
                    ? `${plural(overview.design.componentsChanged, 'reusable component has', 'reusable components have')} unpublished changes.`
                    : null,
            ]
                .filter(Boolean)
                .join(' '),
            href: '/admin/design',
            actionLabel: 'Open Design',
        });

    return (
        <AdminLayout>
            <Head title="Dashboard" />
            <div className="mx-auto max-w-6xl px-4 py-6 sm:px-8 sm:py-8">
                <header className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
                    <div className="min-w-0">
                        <p className="text-[0.8125rem] text-muted">{site?.name}</p>
                        <h1 className="mt-0.5 text-2xl font-semibold tracking-tight">Welcome, {auth.user?.name}</h1>
                        <p className="mt-1 text-[0.875rem] text-muted">{summary(counts)}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {recent && (
                            <ButtonLink href={`/admin/editor/${recent.id}`} icon="pages">
                                <span className="max-w-[14rem] truncate">Continue editing {recent.title}</span>
                            </ButtonLink>
                        )}
                        {can['page.create'] && (
                            <ButtonLink href="/admin/pages" variant="primary" icon="plus">
                                New page
                            </ButtonLink>
                        )}
                    </div>
                </header>

                <div className="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <section aria-labelledby="dashboard-pages" className="overflow-hidden rounded-lg border border-line bg-surface shadow-hairline">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-2.5">
                            <h2 id="dashboard-pages" className="text-sm font-semibold">
                                Pages <span className="sr-only">on {site?.name}</span>
                            </h2>
                            <div role="group" aria-label="Show pages" className="-mx-1 flex flex-wrap gap-0.5">
                                {FILTERS.map((option) => {
                                    const active = filter === option.value;
                                    return (
                                        <button
                                            key={option.value}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => setFilter(option.value)}
                                            className={`inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-xs ${active ? 'bg-sunken font-medium text-fg' : 'text-muted hover:bg-sunken hover:text-fg'}`}
                                        >
                                            {option.value !== 'all' && <StatusMark status={option.value} />}
                                            {option.label}
                                            <span className="text-faint tabular-nums">{counts[option.value]}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                        {shown.length > 0 ? (
                            <div className="px-4">
                                <PagesTable pages={shown} canPublish={!!can['page.publish']} canDelete={!!can['page.delete']} />
                            </div>
                        ) : filter !== 'all' ? (
                            <EmptyState
                                icon="check"
                                title={EMPTY[filter]}
                                action={
                                    <button type="button" onClick={() => setFilter('all')} className="text-[0.8125rem] font-medium text-accent hover:underline">
                                        Show all pages
                                    </button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon="pages"
                                title="No pages yet"
                                action={
                                    can['page.create'] && (
                                        <ButtonLink href="/admin/pages" variant="primary" icon="plus">
                                            Create a page
                                        </ButtonLink>
                                    )
                                }
                            >
                                Create your first page, then design it in the builder or describe it to the AI.
                            </EmptyState>
                        )}
                    </section>

                    <div className="space-y-6">
                        <section
                            aria-labelledby="dashboard-attention"
                            className="rounded-lg border border-line bg-surface shadow-hairline"
                            data-testid="attention"
                        >
                            <h2 id="dashboard-attention" className="border-b border-line px-4 py-2.5 text-sm font-semibold">
                                Needs attention
                            </h2>
                            {attention.length === 0 ? (
                                <p className="flex items-center gap-2 px-4 py-4 text-[0.8125rem] text-muted">
                                    <Icon name="check" className="size-4 text-live" />
                                    Nothing is waiting for you.
                                </p>
                            ) : (
                                <ul className="divide-y divide-line">
                                    {attention.map((item) => (
                                        <li key={item.key} className="flex items-start gap-2.5 px-4 py-3 text-[0.8125rem]">
                                            <Icon name={item.icon} className={`mt-0.5 size-4 ${item.tone}`} />
                                            <span className="min-w-0 flex-1 leading-snug">{item.text}</span>
                                            {item.href ? (
                                                <Link href={item.href} className="shrink-0 text-xs font-medium text-accent hover:underline">
                                                    {item.actionLabel}
                                                </Link>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={item.action}
                                                    className="shrink-0 text-xs font-medium text-accent hover:underline"
                                                >
                                                    {item.actionLabel}
                                                </button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section
                            aria-labelledby="dashboard-activity"
                            className="rounded-lg border border-line bg-surface shadow-hairline"
                            data-testid="activity"
                        >
                            <h2 id="dashboard-activity" className="border-b border-line px-4 py-2.5 text-sm font-semibold">
                                Recent activity
                            </h2>
                            {overview.activity.length === 0 ? (
                                <p className="px-4 py-4 text-[0.8125rem] text-muted">Publishing, restores and design changes will show up here.</p>
                            ) : (
                                <ol className="px-4 py-2">
                                    {overview.activity.map((entry) => (
                                        <li key={entry.id} className="flex gap-2.5 py-2 text-[0.8125rem]">
                                            <span className="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-sunken text-muted">
                                                <Icon name={ACTIVITY_ICON[entry.kind] ?? 'pages'} className="size-3.5" />
                                            </span>
                                            <div className="min-w-0 leading-snug">
                                                <p>
                                                    <span className="font-medium">{entry.actor ?? 'Someone'}</span> {entry.verb}{' '}
                                                    {entry.target &&
                                                        (entry.href ? (
                                                            <Link
                                                                href={entry.href}
                                                                className="font-medium text-fg underline decoration-line-strong underline-offset-2 hover:decoration-fg"
                                                            >
                                                                {entry.target}
                                                            </Link>
                                                        ) : (
                                                            <span className="font-medium">{entry.target}</span>
                                                        ))}
                                                    {entry.kind === 'tokens' && entry.version ? ` (version ${entry.version})` : ''}
                                                </p>
                                                <time dateTime={entry.at} title={fullDate(entry.at)} className="text-[11px] text-muted">
                                                    {relativeTime(entry.at)}
                                                </time>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
