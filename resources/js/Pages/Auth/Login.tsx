import { Head, useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Icon } from '@/Components/Icon';
import { ArkonMark, Button } from '@/Components/ui';
import { useTheme } from '@/lib/theme';

/** A centred card framed by crop marks, the same marks the builder draws around what you edit. */
export function AuthFrame({ children }: { children: ReactNode }) {
    const { theme } = useTheme();
    return (
        <main data-theme={theme} className="flex min-h-dvh items-center justify-center bg-canvas p-6 text-fg">
            <div className="relative w-full max-w-sm">
                {(
                    [
                        '-top-3 -left-3 border-t-2 border-l-2',
                        '-top-3 -right-3 border-t-2 border-r-2',
                        '-bottom-3 -left-3 border-b-2 border-l-2',
                        '-right-3 -bottom-3 border-r-2 border-b-2',
                    ] as const
                ).map((corner) => (
                    <span key={corner} aria-hidden className={`absolute size-5 border-accent ${corner}`} />
                ))}
                <div className="rounded-xl border border-line bg-surface p-8 shadow-hairline">{children}</div>
            </div>
        </main>
    );
}

export default function Login({ next }: { next: string }) {
    const form = useForm({ email: '', password: '', next });
    const error = form.errors.email ?? form.errors.password;

    return (
        <AuthFrame>
            <Head title="Sign in" />
            <div className="flex items-center gap-2.5">
                <ArkonMark className="size-8" />
                <span className="text-sm font-semibold tracking-tight">Arkon</span>
            </div>
            <h1 className="mt-6 text-xl font-semibold tracking-tight">Sign in</h1>
            <p className="mt-1 text-[0.8125rem] text-muted">Design, edit and publish your site.</p>
            <form
                className="mt-6 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/login', { onFinish: () => form.reset('password') });
                }}
            >
                <label className="block">
                    <span className="ui-label">Email</span>
                    <input
                        name="email"
                        type="email"
                        autoComplete="username"
                        required
                        className="ui-input h-9 text-sm"
                        aria-invalid={!!error}
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </label>
                <label className="block">
                    <span className="ui-label">Password</span>
                    <input
                        name="password"
                        type="password"
                        autoComplete="current-password"
                        required
                        className="ui-input h-9 text-sm"
                        aria-invalid={!!error}
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                </label>
                {error && (
                    <p role="alert" className="flex items-start gap-1.5 text-[0.8125rem] text-danger">
                        <Icon name="alert" className="mt-0.5 size-4" />
                        {error}
                    </p>
                )}
                <Button type="submit" variant="primary" className="h-9 w-full" busy={form.processing} disabled={form.processing}>
                    {form.processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>
            <p className="mt-6 text-xs text-muted">Accounts are created by the site owner from the server command line.</p>
        </AuthFrame>
    );
}
