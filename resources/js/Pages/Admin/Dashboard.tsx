import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { PagesTable, STATUS_LABEL, StatusMark } from '@/Components/PagesTable';
import type { PageRow, PageStatus, SharedProps } from '@/types';

type Filter = 'all' | PageStatus;

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

export default function Dashboard({ pages }: { pages: PageRow[] }) {
    const { auth, site, can } = usePage<SharedProps>().props;
    const [filter, setFilter] = useState<Filter>('all');
    const counts: Record<Filter, number> = {
        all: pages.length,
        changed: pages.filter((p) => p.status === 'changed').length,
        draft: pages.filter((p) => p.status === 'draft').length,
        published: pages.filter((p) => p.status === 'published').length,
    };
    const shown = filter === 'all' ? pages : pages.filter((p) => p.status === filter);

    return (
        <AdminLayout>
            <Head title="Dashboard" />
            <div className="mx-auto max-w-5xl px-4 py-6 sm:px-8 sm:py-10">
                <header className="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold tracking-tight">Welcome, {auth.user?.name}</h1>
                        <p className="mt-1 text-sm text-muted">{summary(counts)}</p>
                    </div>
                    {can['page.create'] && (
                        <Link
                            href="/admin/pages"
                            className="inline-flex h-8 items-center rounded-md bg-accent px-3 text-sm font-medium text-accent-fg shadow-hairline hover:bg-accent-hover"
                        >
                            New page
                        </Link>
                    )}
                </header>

                <section aria-labelledby="dashboard-pages" className="mt-8 rounded-lg border border-line bg-surface shadow-hairline">
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
                                        className={`inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-xs ${
                                            active ? 'bg-line/70 font-medium text-fg' : 'text-muted hover:bg-line/50 hover:text-fg'
                                        }`}
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
                    ) : (
                        <div className="px-4 py-10 text-center text-sm">
                            <p className="text-muted">{EMPTY[filter]}</p>
                            {filter !== 'all' ? (
                                <button type="button" onClick={() => setFilter('all')} className="mt-2 font-medium text-accent hover:underline">
                                    Show all pages
                                </button>
                            ) : (
                                can['page.create'] && (
                                    <Link href="/admin/pages" className="mt-2 inline-block font-medium text-accent hover:underline">
                                        Create a page
                                    </Link>
                                )
                            )}
                        </div>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
