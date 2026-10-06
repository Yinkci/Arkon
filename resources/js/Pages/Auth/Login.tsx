import { Head, useForm } from '@inertiajs/react';

const inputClass = 'mt-1 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200';

export default function Login({ next }: { next: string }) {
    const form = useForm({ email: '', password: '', next });

    return (
        <main className="flex min-h-full items-center justify-center p-6">
            <Head title="Sign in" />
            <div className="w-full max-w-sm rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                <p className="text-sm font-semibold tracking-wide text-indigo-600">ARKON</p>
                <h1 className="mt-1 text-xl font-semibold">Sign in</h1>
                <form
                    className="mt-6 space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/login', { onFinish: () => form.reset('password') });
                    }}
                >
                    <label className="block text-sm font-medium">
                        Email
                        <input
                            name="email"
                            type="email"
                            autoComplete="username"
                            required
                            className={inputClass}
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                        />
                    </label>
                    <label className="block text-sm font-medium">
                        Password
                        <input
                            name="password"
                            type="password"
                            autoComplete="current-password"
                            required
                            className={inputClass}
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    </label>
                    {(form.errors.email || form.errors.password) && (
                        <p role="alert" className="text-sm text-red-600">
                            {form.errors.email ?? form.errors.password}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-60"
                    >
                        {form.processing ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
                <p className="mt-6 text-xs text-zinc-500">Accounts are created by the site owner from the server command line.</p>
            </div>
        </main>
    );
}
