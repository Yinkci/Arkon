import { Head, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { Button, Notice, StatusPill } from '@/Components/ui';
import { api, newRequestKey } from '@/lib/api';
import type { SharedProps } from '@/types';

interface Selection {
    id: string;
    name: string;
    description: string;
    version: number;
    types: string[];
}
interface Candidate extends Omit<Selection, 'version'> {
    version: number | null;
    error: string | null;
}
interface ThemeState {
    version: number;
    draft: Selection | null;
    published: Selection | null;
    publishedVersion: number | null;
    changed: boolean;
    themes: Candidate[];
    retainedTypes: string[];
}
type Intent =
    | { path: '/themes/activate'; body: { themeId: string | null; baseVersion: number; requestKey: string } }
    | { path: '/themes/publish'; body: { expectedVersion: number; requestKey: string } };

export default function Themes({ init, manage }: { init: ThemeState; manage: boolean }) {
    const { site } = usePage<SharedProps>().props;
    const storageKey = `arkon-theme-intent:${site?.id}`;
    const [state, setState] = useState(init);
    const [busy, setBusy] = useState(false);
    const running = useRef(false);
    const [pending, setPending] = useState<Intent | null>(() => {
        try {
            const raw = sessionStorage.getItem(storageKey);
            return raw ? (JSON.parse(raw) as Intent) : null;
        } catch {
            return null;
        }
    });
    const [message, setMessage] = useState('');
    const [error, setError] = useState(false);
    const [preview, setPreview] = useState<string | null>(null);

    async function run(intent: Intent) {
        if (running.current) return;
        running.current = true;
        setBusy(true);
        setPending(intent);
        try {
            sessionStorage.setItem(storageKey, JSON.stringify(intent));
        } catch {
            /* Retained in this open tab. */
        }
        setMessage('');
        try {
            const result = await api(intent.path, { body: intent.body });
            if (!result.ok && result.code === 'INTERNAL') throw new Error('Unconfirmed outcome');
            setPending(null);
            try {
                sessionStorage.removeItem(storageKey);
            } catch {
                /* Storage may be disabled. */
            }
            setError(!result.ok);
            setMessage(
                result.ok
                    ? intent.path === '/themes/activate'
                        ? 'Theme activated for editing. Your live pages have not changed.'
                        : 'Theme selection published. Publish individual pages to release their edits.'
                    : result.message,
            );
            const fresh = await api<ThemeState>('/themes').catch(() => null);
            if (fresh?.ok) setState(fresh.data);
            else
                setMessage(
                    result.ok
                        ? 'Theme request completed. Reload this screen to refresh its status.'
                        : `${result.message} Reload this screen to refresh its status.`,
                );
        } catch {
            setError(true);
            setMessage('The outcome could not be confirmed. Retry this same request safely before making another theme change.');
        } finally {
            running.current = false;
            setBusy(false);
        }
    }

    const blocked = busy || pending !== null;
    const builtin: Candidate = {
        id: '',
        name: 'Arkon core',
        description: 'The standard builder blocks. Previously used custom components remain editable.',
        version: null,
        types: [],
        error: null,
    };
    return (
        <AdminLayout>
            <Head title="Themes" />
            <header className="border-b border-line bg-raised px-6 py-5">
                <p className="text-xs text-muted">Appearance</p>
                <div className="mt-1 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Themes</h1>
                    {manage && (
                        <Button
                            variant="primary"
                            disabled={blocked || !state.changed}
                            onClick={() => void run({ path: '/themes/publish', body: { expectedVersion: state.version, requestKey: newRequestKey() } })}
                        >
                            Publish theme selection
                        </Button>
                    )}
                </div>
                <p className="mt-2 max-w-3xl text-sm text-muted">
                    Choose the custom components available for this site. Themes currently provide builder components; they do not replace your page layouts,
                    colours or content.
                </p>
            </header>
            <div className="space-y-5 p-6">
                <section aria-label="Current theme" className="rounded-xl border border-line bg-surface p-4 text-sm">
                    <p>
                        <strong>Active for editing:</strong> {state.draft?.name ?? 'Arkon core'}
                    </p>
                    <p className="mt-1 text-muted">
                        <strong>Published selection:</strong> {state.published?.name ?? 'Arkon core'}
                        {state.publishedVersion !== null && ` · revision ${state.publishedVersion}`}
                    </p>
                    {state.changed && <p className="mt-2 text-changed">Theme selection has unpublished changes.</p>}
                </section>
                {message && <Notice tone={error ? 'error' : 'success'}>{message}</Notice>}
                {pending && (
                    <Notice
                        tone="warning"
                        action={
                            <Button disabled={busy} busy={busy} onClick={() => void run(pending)}>
                                Retry theme request
                            </Button>
                        }
                    >
                        A theme request is awaiting confirmation. Its original request key is retained in this tab.
                    </Notice>
                )}
                {!manage && <Notice tone="info">Only owners and admins can activate or publish a theme.</Notice>}
                {state.retainedTypes.length > 0 && (
                    <Notice tone="info">
                        Existing pages still use components from another theme. Those components and their published versions are retained, so you can keep
                        editing and publishing those pages.
                    </Notice>
                )}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {[builtin, ...state.themes].map((theme) => {
                        const active = (state.draft?.id ?? '') === theme.id;
                        return (
                            <article key={theme.id} aria-label={theme.name} className="flex flex-col rounded-xl border border-line bg-surface p-5">
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="font-semibold">{theme.name}</h2>
                                    {active && <StatusPill tone="live">Active for editing</StatusPill>}
                                </div>
                                <p className="mt-2 min-h-12 text-sm text-muted">
                                    {theme.description || `${theme.types.length} custom builder component${theme.types.length === 1 ? '' : 's'}.`}
                                </p>
                                {theme.version !== null && (
                                    <p className="mt-2 text-xs text-muted">
                                        Version {theme.version} · themes/{theme.id}
                                    </p>
                                )}
                                {theme.error && (
                                    <p role="alert" className="mt-3 text-sm text-danger">
                                        {theme.error}
                                    </p>
                                )}
                                <div className="mt-5 flex flex-wrap gap-2">
                                    {theme.id && (
                                        <Button disabled={!!theme.error} onClick={() => setPreview(theme.id)}>
                                            Preview components
                                        </Button>
                                    )}
                                    {manage && (
                                        <Button
                                            variant={active ? 'secondary' : 'primary'}
                                            disabled={blocked || !!theme.error || (active && !theme.id)}
                                            onClick={() =>
                                                void run({
                                                    path: '/themes/activate',
                                                    body: { themeId: theme.id || null, baseVersion: state.version, requestKey: newRequestKey() },
                                                })
                                            }
                                        >
                                            {active ? (theme.id ? 'Refresh from files' : 'Active') : 'Activate for editing'}
                                        </Button>
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </div>
                {preview && (
                    <section aria-label="Theme component preview" className="rounded-xl border border-line bg-surface p-4">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="font-semibold">Component preview</h2>
                            <Button onClick={() => setPreview(null)}>Close preview</Button>
                        </div>
                        <p className="mb-3 text-sm text-muted">Sample components only. Previewing does not activate a theme or change your pages.</p>
                        <iframe
                            title="Theme component preview"
                            sandbox=""
                            src={`/admin/themes/${encodeURIComponent(preview)}/preview`}
                            className="h-[32rem] w-full rounded-lg border border-line bg-white"
                        />
                    </section>
                )}
                <p className="text-xs text-muted">
                    Develop your site in themes/mysite. Add or update component files there, then refresh and activate the validated theme here. Existing
                    component versions are immutable.
                </p>
            </div>
        </AdminLayout>
    );
}
