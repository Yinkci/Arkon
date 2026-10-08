import { useId, useState } from 'react';
import { Icon } from '@/Components/Icon';
import { Button, PanelSection } from '@/Components/ui';

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
        <PanelSection title="Title and URL">
            <form
                className="space-y-3"
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
                <div>
                    <label htmlFor={ids.title} className="ui-label">
                        Page title
                    </label>
                    <input
                        id={ids.title}
                        className="ui-input"
                        disabled={!canEdit}
                        value={draftTitle}
                        maxLength={120}
                        onChange={(e) => setDraftTitle(e.target.value)}
                    />
                </div>
                <div>
                    <label htmlFor={ids.path} className="ui-label">
                        URL path
                    </label>
                    <input
                        id={ids.path}
                        className="ui-input font-mono"
                        disabled={!canEdit}
                        value={draftPath}
                        maxLength={200}
                        onChange={(e) => setDraftPath(e.target.value)}
                    />
                </div>
                {live && (live.path !== path || live.title !== title) && (
                    <p className="flex items-start gap-1.5 text-[11px] text-changed" data-testid="live-meta">
                        <Icon name="clock" className="mt-px size-3.5" />
                        Live now as “{live.title}” at {live.path} until you publish.
                    </p>
                )}
                {live && changed && draftPath !== live.path && (
                    <p className="text-[11px] text-muted">When published, {live.path} will redirect (301) to the new URL.</p>
                )}
                {blockedReason && changed && <p className="text-[11px] text-changed">{blockedReason}</p>}
                <Button type="submit" className="w-full" busy={pending} disabled={!canEdit || !changed || pending || blockedReason !== null}>
                    {pending ? 'Updating…' : 'Update title & URL'}
                </Button>
            </form>
        </PanelSection>
    );
}
