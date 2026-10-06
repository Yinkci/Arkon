import { useId, useState } from 'react';

const field =
    'mt-1 w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 disabled:bg-zinc-50';

export interface PageSettingsProps {
    title: string;
    path: string;
    live: { title: string; path: string } | null;
    canEdit: boolean;
    /** Why applying is not possible right now (e.g. unsaved edits), or null. */
    blockedReason: string | null;
    onApply(title: string, path: string): Promise<void>;
}

/**
 * Draft title and URL. Applying records a draft revision; the live page changes only
 * when published. The parent keys this component by title and path, so the form
 * resets when they change.
 */
export function PageSettings({ title, path, live, canEdit, blockedReason, onApply }: PageSettingsProps) {
    const [draftTitle, setDraftTitle] = useState(title);
    const [draftPath, setDraftPath] = useState(path);
    const [pending, setPending] = useState(false);
    const ids = { title: useId(), path: useId() };

    const changed = draftTitle !== title || draftPath !== path;
    return (
        <form
            className="space-y-3 border-b border-zinc-200 p-4"
            aria-label="Title and URL"
            onSubmit={async (event) => {
                event.preventDefault();
                setPending(true);
                try {
                    await onApply(draftTitle.trim(), draftPath.trim());
                } finally {
                    setPending(false);
                }
            }}
        >
            <h2 className="text-sm font-semibold">Title &amp; URL</h2>
            <div>
                <label htmlFor={ids.title} className="block text-xs font-medium text-zinc-600">
                    Page title
                </label>
                <input
                    id={ids.title}
                    className={field}
                    disabled={!canEdit}
                    value={draftTitle}
                    maxLength={120}
                    onChange={(e) => setDraftTitle(e.target.value)}
                />
            </div>
            <div>
                <label htmlFor={ids.path} className="block text-xs font-medium text-zinc-600">
                    URL path
                </label>
                <input id={ids.path} className={field} disabled={!canEdit} value={draftPath} maxLength={200} onChange={(e) => setDraftPath(e.target.value)} />
            </div>
            {live && (live.path !== path || live.title !== title) && (
                <p className="text-xs text-amber-700" data-testid="live-meta">
                    Live now as “{live.title}” at {live.path} until you publish.
                </p>
            )}
            {live && changed && draftPath !== live.path && (
                <p className="text-xs text-zinc-500">When published, {live.path} will redirect (301) to the new URL.</p>
            )}
            {blockedReason && changed && <p className="text-xs text-amber-700">{blockedReason}</p>}
            <button
                type="submit"
                disabled={!canEdit || !changed || pending || blockedReason !== null}
                className="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-sm hover:bg-zinc-50 disabled:opacity-50"
            >
                {pending ? 'Updating…' : 'Update title & URL'}
            </button>
        </form>
    );
}
