import { Head, router } from '@inertiajs/react';

export default function NoSite() {
    return (
        <main className="flex min-h-full items-center justify-center p-6">
            <Head title="No site" />
            <div className="w-full max-w-sm space-y-4 rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <h1 className="text-xl font-semibold">No site access</h1>
                <p className="text-sm text-zinc-600">Your account is not a member of any site. Ask a site owner to add you.</p>
                <button type="button" onClick={() => router.post('/logout')} className="text-sm font-medium text-indigo-600 hover:underline">
                    Sign out
                </button>
            </div>
        </main>
    );
}
