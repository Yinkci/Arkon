import { Head, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/Components/AdminLayout';
import { NewPageForm } from '@/Components/NewPageForm';
import { PagesTable } from '@/Components/PagesTable';
import { EmptyState } from '@/Components/ui';
import type { PageRow, SharedProps } from '@/types';

export default function Pages({ pages }: { pages: PageRow[] }) {
    const { can } = usePage<SharedProps>().props;
    return (
        <AdminLayout>
            <Head title="Pages" />
            <div className="mx-auto max-w-5xl space-y-5 px-4 py-6 sm:px-8 sm:py-8">
                <header>
                    <h1 className="text-2xl font-semibold tracking-tight">Pages</h1>
                    <p className="mt-1 text-[0.875rem] text-muted">
                        Every page of the site with its draft and live status. Titles and URLs are changed in the builder (Page settings); changes stay in the
                        draft until you publish.
                    </p>
                </header>
                {can['page.create'] && <NewPageForm />}
                <section aria-label="All pages" className="rounded-lg border border-line bg-surface shadow-hairline">
                    <h2 className="border-b border-line px-4 py-2.5 text-sm font-semibold">
                        All pages <span className="font-normal text-muted tabular-nums">{pages.length}</span>
                    </h2>
                    {pages.length === 0 ? (
                        <EmptyState icon="pages" title="No pages yet">
                            {can['page.create'] ? 'Create one above to start building.' : 'Pages created by your team appear here.'}
                        </EmptyState>
                    ) : (
                        <div className="px-4">
                            <PagesTable pages={pages} canPublish={!!can['page.publish']} canDelete={!!can['page.delete']} />
                        </div>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
