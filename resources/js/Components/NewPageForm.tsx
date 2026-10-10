import { router } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import { slugify } from '@/arkon/schema/paths';
import { api, newRequestKey } from '@/lib/api';
import type { ContentTypeInfo } from '@/types';
import { Icon } from './Icon';
import { Button } from './ui';

const PAGE: ContentTypeInfo = { kind: 'page', label: 'Page', plural: 'Pages', pathPrefix: '', taxonomies: [] };

/** A new page, post or other item: title and URL (the type's prefix plus a slug of the title). */
export function NewPageForm({ type = PAGE }: { type?: ContentTypeInfo }) {
    const noun = type.label.toLowerCase();
    const fromTitle = (value: string) => (type.pathPrefix ? type.pathPrefix + slugify(value) : slugify(value));
    const [title, setTitle] = useState('');
    const [path, setPath] = useState(type.pathPrefix ? type.pathPrefix + '/' : '');
    const [pathEdited, setPathEdited] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    // One key per create intent; kept after an uncertain response so a retry cannot create twice.
    const attempt = useRef<{ key: string; title: string; path: string } | null>(null);
    const ids = { title: useId(), path: useId(), error: useId() };

    async function onSubmit(event: React.FormEvent) {
        event.preventDefault();
        const intent = attempt.current?.title === title && attempt.current.path === path ? attempt.current : { key: newRequestKey(), title, path };
        attempt.current = intent;
        setPending(true);
        setError(null);
        try {
            const result = await api<{ pageId: string; replayed: boolean }>('/pages', { body: { title, path, kind: type.kind, requestKey: intent.key } });
            if (!result.ok) {
                // INTERNAL may have been applied: keep the key so a retry returns the same page.
                if (result.code !== 'INTERNAL') attempt.current = null;
                setError(result.issues?.length ? result.issues.map((i) => i.message).join(' ') : result.message);
                return;
            }
            router.visit(`/admin/editor/${result.data.pageId}`);
        } catch {
            setError(`Couldn't confirm the ${noun} was created (network problem). Click Create ${noun} again: it will not create a duplicate.`);
        } finally {
            setPending(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="rounded-lg border border-line bg-surface shadow-hairline" aria-label={`New ${noun}`}>
            <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-2.5">
                <h2 className="t-title">New {noun}</h2>
                <p className="text-xs text-muted">Starts as an unpublished draft and opens in the builder.</p>
            </div>
            <div className="grid gap-3 px-4 py-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={ids.title} className="ui-label">
                        Title
                    </label>
                    <input
                        id={ids.title}
                        required
                        maxLength={120}
                        value={title}
                        className="ui-input h-9 text-sm"
                        placeholder={type.kind === 'page' ? 'About us' : 'What we learned this year'}
                        aria-describedby={error ? ids.error : undefined}
                        onChange={(e) => {
                            setTitle(e.target.value);
                            if (!pathEdited) setPath(fromTitle(e.target.value));
                        }}
                    />
                </div>
                <div>
                    <label htmlFor={ids.path} className="ui-label">
                        URL path
                    </label>
                    <input
                        id={ids.path}
                        required
                        maxLength={200}
                        value={path}
                        placeholder={type.kind === 'page' ? '/about' : `${type.pathPrefix}/what-we-learned`}
                        className="ui-input h-9 font-mono text-sm"
                        aria-describedby={error ? ids.error : undefined}
                        onChange={(e) => {
                            setPath(e.target.value);
                            setPathEdited(true);
                        }}
                    />
                </div>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-raised px-4 py-2.5">
                {error ? (
                    <p id={ids.error} role="alert" className="flex items-start gap-1.5 text-ui text-danger">
                        <Icon name="alert" className="mt-0.5 size-4" />
                        {error}
                    </p>
                ) : (
                    <p className="text-xs text-muted">The path is filled in from the title; you can change it.</p>
                )}
                <Button type="submit" variant="primary" icon="plus" busy={pending} disabled={pending}>
                    {pending ? 'Creating…' : `Create ${noun}`}
                </Button>
            </div>
        </form>
    );
}
