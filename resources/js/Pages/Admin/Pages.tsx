import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { AdminLayout } from '@/Components/AdminLayout';
import { NewPageForm } from '@/Components/NewPageForm';
import { PagesTable } from '@/Components/PagesTable';
import { Button, ButtonLink, EmptyState } from '@/Components/ui';
import type { PageRow, SharedProps } from '@/types';

export default function Pages({ pages }: { pages: PageRow[] }) {
    const page = usePage<SharedProps>();
    const { can } = page.props;
    const params = new URLSearchParams(page.url.split('?')[1] ?? '');
    const [creating, setCreating] = useState(params.get('new') === '1');
    const [filter, setFilter] = useState(params.get('status') ?? 'all');
    const [query, setQuery] = useState('');
    const shown = pages.filter((p) => (filter === 'all' || p.status === filter) && (p.title + ' ' + p.path).toLowerCase().includes(query.toLowerCase()));
    return (
        <AdminLayout>
            <Head title="Pages" />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    title="Pages"
                    description="Manage page drafts and live versions. Open any page in the builder to edit content, layout and SEO."
                    actions={
                        <>
                            {can['page.edit'] && (
                                <ButtonLink href="/admin/website" icon="sparkle">
                                    Generate with AI
                                </ButtonLink>
                            )}
                            {can['page.create'] && (
                                <Button
                                    variant="primary"
                                    icon="plus"
                                    aria-expanded={creating}
                                    aria-controls="new-page-panel"
                                    onClick={() => setCreating((value) => !value)}
                                >
                                    {creating ? 'Close new page' : 'New page'}
                                </Button>
                            )}
                        </>
                    }
                />
                {can['page.create'] && creating && (
                    <div id="new-page-panel">
                        <NewPageForm />
                    </div>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Filter pages" className="inline-flex flex-wrap rounded-md border border-line bg-sunken p-0.5">
                        {[
                            ['all', 'All'],
                            ['published', 'Published'],
                            ['draft', 'Not published'],
                            ['changed', 'Unpublished changes'],
                        ].map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={filter === value}
                                onClick={() => setFilter(value!)}
                                className={`inline-flex h-8 items-center gap-1.5 rounded px-3 text-ui font-medium transition-colors ${filter === value ? 'bg-surface text-fg shadow-raise' : 'text-muted hover:text-fg'}`}
                            >
                                {label}
                                <span aria-hidden="true" className="text-2xs text-faint t-num">
                                    {value === 'all' ? pages.length : pages.filter((p) => p.status === value).length}
                                </span>
                            </button>
                        ))}
                    </div>
                    <label className="sr-only" htmlFor="pages-search">
                        Search pages
                    </label>
                    <input
                        id="pages-search"
                        type="search"
                        className="ui-input w-full sm:w-64"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search title or URL"
                    />
                </div>
                <section aria-label="All pages">
                    <h2 className="sr-only">All pages</h2>
                    {shown.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-line-strong">
                            <EmptyState icon="pages" title={pages.length ? 'No matching pages' : 'Create your first page'}>
                                {pages.length
                                    ? 'Change the search or filter to find a page.'
                                    : can['page.create']
                                      ? 'Choose New page or generate editable drafts with AI.'
                                      : 'Pages created by your team appear here.'}
                            </EmptyState>
                        </div>
                    ) : (
                        <div className="rounded-lg border border-line bg-surface px-5 shadow-hairline">
                            <PagesTable pages={shown} canPublish={!!can['page.publish']} canDelete={!!can['page.delete']} />
                        </div>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
