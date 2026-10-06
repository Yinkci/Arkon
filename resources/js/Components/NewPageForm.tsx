import { router } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import { slugify } from '@/arkon/schema/paths';
import { api, newRequestKey } from '@/lib/api';

const field =
    'mt-1 w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-sm text-fg outline-none placeholder:text-faint focus:border-accent focus:ring-2 focus:ring-accent-soft';

export function NewPageForm() {
    const [title, setTitle] = useState('');
    const [path, setPath] = useState('');
    const [pathEdited, setPathEdited] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    // One key per create intent; kept after an uncertain response so a retry cannot create twice.
    const attempt = useRef<{ key: string; title: string; path: string } | null>(null);
    const ids = { title: useId(), path: useId() };

    async function onSubmit(event: React.FormEvent) {
        event.preventDefault();
        const intent = attempt.current?.title === title && attempt.current.path === path ? attempt.current : { key: newRequestKey(), title, path };
        attempt.current = intent;
        setPending(true);
        setError(null);
        try {
            const result = await api<{ pageId: string; replayed: boolean }>('/pages', { body: { title, path, requestKey: intent.key } });
            if (!result.ok) {
                // INTERNAL may have been applied: keep the key so a retry returns the same page.
                if (result.code !== 'INTERNAL') attempt.current = null;
                setError(result.issues?.length ? result.issues.map((i) => i.message).join(' ') : result.message);
                return;
            }
            router.visit(`/admin/editor/${result.data.pageId}`);
        } catch {
            setError("Couldn't confirm the page was created (network problem). Click Create page again: it will not create a duplicate.");
        } finally {
            setPending(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="space-y-3 rounded-lg border border-line bg-surface p-4 shadow-hairline" aria-label="New page">
            <h2 className="text-sm font-semibold">New page</h2>
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <label htmlFor={ids.title} className="block text-xs font-medium text-muted">
                        Title
                    </label>
                    <input
                        id={ids.title}
                        required
                        maxLength={120}
                        value={title}
                        className={field}
                        onChange={(e) => {
                            setTitle(e.target.value);
                            if (!pathEdited) setPath(slugify(e.target.value));
                        }}
                    />
                </div>
                <div>
                    <label htmlFor={ids.path} className="block text-xs font-medium text-muted">
                        URL path
                    </label>
                    <input
                        id={ids.path}
                        required
                        maxLength={200}
                        value={path}
                        placeholder="/about"
                        className={field}
                        onChange={(e) => {
                            setPath(e.target.value);
                            setPathEdited(true);
                        }}
                    />
                </div>
            </div>
            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}
            <div className="flex items-center justify-between">
                <p className="text-xs text-muted">New pages start as unpublished drafts.</p>
                <button
                    type="submit"
                    disabled={pending}
                    className="rounded-md bg-accent px-3 py-1.5 text-sm font-medium text-accent-fg hover:bg-accent-hover disabled:opacity-60"
                >
                    {pending ? 'Creating…' : 'Create page'}
                </button>
            </div>
        </form>
    );
}
