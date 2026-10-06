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
            <div className="max-w-4xl space-y-4">
                <h1 className="text-2xl font-semibold">Pages</h1>
                {can['page.create'] && <NewPageForm />}
                <PagesTable pages={pages} canPublish={!!can['page.publish']} canDelete={!!can['page.delete']} />
                <p className="text-sm text-zinc-500">
                    Change a page title or URL in the editor (Properties → Page settings). Changes stay in the draft until you publish.
                </p>
            </div>
        </AdminLayout>
    );
}
