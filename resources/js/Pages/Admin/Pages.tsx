import { Head, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/Components/AdminLayout';
import { NewPageForm } from '@/Components/NewPageForm';
import { PagesTable } from '@/Components/PagesTable';
import type { PageRow, SharedProps } from '@/types';

export default function Pages({ pages }: { pages: PageRow[] }) {
    const { can } = usePage<SharedProps>().props;
    return (
        <AdminLayout>
            <Head title="Pages" />
            <div className="mx-auto max-w-5xl space-y-4 px-4 py-6 sm:px-8 sm:py-10">
                <h1 className="text-xl font-semibold tracking-tight">Pages</h1>
                {can['page.create'] && <NewPageForm />}
                <div className="rounded-lg border border-line bg-surface px-4 shadow-hairline">
                    <PagesTable pages={pages} canPublish={!!can['page.publish']} canDelete={!!can['page.delete']} />
                </div>
                <p className="text-sm text-muted">
                    Change a page title or URL in the editor (Properties → Page settings). Changes stay in the draft until you publish.
                </p>
            </div>
        </AdminLayout>
    );
}
