import { Link, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedProps } from '@/types';

const NAV = [
    { href: '/admin', label: 'Dashboard' },
    { href: '/admin/pages', label: 'Pages' },
];
const PLANNED = ['Content', 'Media', 'Design', 'SEO', 'AI', 'Site Health', 'Settings'];

export function AdminLayout({ children }: { children: ReactNode }) {
    const { auth, site } = usePage<SharedProps>().props;
    return (
        <div className="flex min-h-full">
            <aside className="flex w-56 shrink-0 flex-col border-r border-zinc-200 bg-white">
                <div className="border-b border-zinc-200 px-5 py-4">
                    <p className="text-xs font-semibold tracking-wide text-indigo-600">ARKON</p>
                    <p className="truncate text-sm font-medium">{site?.name}</p>
                </div>
                <nav aria-label="Main" className="flex-1 space-y-0.5 p-3 text-sm">
                    {NAV.map((item) => (
                        <Link key={item.href} href={item.href} className="block rounded-md px-3 py-2 hover:bg-zinc-100">
                            {item.label}
                        </Link>
                    ))}
                    <p className="px-3 pt-4 pb-1 text-xs font-medium text-zinc-400">Planned</p>
                    {PLANNED.map((label) => (
                        <span key={label} className="block cursor-not-allowed px-3 py-1.5 text-zinc-400">
                            {label}
                        </span>
                    ))}
                </nav>
                <div className="border-t border-zinc-200 p-3 text-sm">
                    <p className="truncate px-3 text-zinc-600">{auth.user?.email}</p>
                    <button
                        type="button"
                        onClick={() => router.post('/logout')}
                        className="mt-1 w-full rounded-md px-3 py-2 text-left text-zinc-600 hover:bg-zinc-100"
                    >
                        Sign out
                    </button>
                </div>
            </aside>
            <main className="flex-1 overflow-auto p-8">{children}</main>
        </div>
    );
}
