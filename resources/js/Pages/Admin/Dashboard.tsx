import { Head, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/Components/AdminLayout';
import { PagesTable } from '@/Components/PagesTable';
import type { PageRow, SharedProps } from '@/types';

export default function Dashboard({ pages }: { pages: PageRow[] }) {
    const { auth, site, can } = usePage<SharedProps>().props;
    const stats = [
        { label: 'Pages', value: pages.length },
        { label: 'Published', value: pages.filter((p) => p.status === 'published').length },
        { label: 'Not live yet / changed', value: pages.filter((p) => p.status !== 'published').length },
    ];
    return (
        <AdminLayout>
            <Head title="Dashboard" />
            <div className="max-w-4xl space-y-8">
                <header>
                    <h1 className="text-2xl font-semibold">Welcome, {auth.user?.name}</h1>
                    <p className="text-zinc-600">{site?.name}</p>
                </header>
                <section className="grid grid-cols-3 gap-4" aria-label="Summary">
                    {stats.map((stat) => (
                        <div key={stat.label} className="rounded-lg border border-zinc-200 bg-white p-4">
                            <p className="text-sm text-zinc-500">{stat.label}</p>
                            <p className="mt-1 text-2xl font-semibold">{stat.value}</p>
                        </div>
                    ))}
                </section>
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">Pages</h2>
                    <PagesTable pages={pages} canPublish={!!can['page.publish']} canDelete={!!can['page.delete']} />
                </section>
            </div>
        </AdminLayout>
    );
}
