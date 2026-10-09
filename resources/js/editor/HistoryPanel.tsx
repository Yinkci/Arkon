import { Icon } from '@/Components/Icon';
import { Button, EmptyState, StatusPill } from '@/Components/ui';
import { fullDate, relativeTime } from '@/lib/time';
import type { Revision } from '@/types';

/** Saved revisions of the page, newest first: which one is live, who made it, and Restore. */
export function HistoryPanel({ revisions, canRestore, onRestore }: { revisions: Revision[]; canRestore: boolean; onRestore(revision: Revision): void }) {
    if (revisions.length === 0)
        return (
            <EmptyState icon="history" title="No revisions yet">
                Each save adds a revision here. You can restore any of them into the draft.
            </EmptyState>
        );
    return (
        <div>
            <p className="border-b border-line px-4 py-2.5 text-2xs leading-snug text-muted">
                Restoring copies a revision into the draft. The live page changes only when you publish.
            </p>
            <ol aria-label="Revision history">
                {revisions.map((r) => (
                    <li key={r.id} className="flex items-start gap-3 border-b border-line px-4 py-3" data-testid="revision">
                        <span
                            className={`mt-0.5 grid size-6 shrink-0 place-items-center rounded-full text-3xs font-semibold tabular-nums ${r.isLive ? 'bg-live-soft text-live' : 'bg-sunken text-muted'}`}
                        >
                            {r.number}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-ui leading-snug font-medium">
                                #{r.number} {r.message}
                            </p>
                            <p className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-2xs text-muted">
                                <span>{r.authorName ?? (r.source === 'system' ? 'System' : 'Unknown')}</span>
                                <span aria-hidden>·</span>
                                <time dateTime={r.createdAt} title={fullDate(r.createdAt)}>
                                    {relativeTime(r.createdAt)}
                                </time>
                                {r.source === 'ai' && (
                                    <span className="inline-flex items-center gap-0.5 text-ai">
                                        <Icon name="sparkle" className="size-3" /> AI
                                    </span>
                                )}
                            </p>
                            {r.isLive && (
                                <StatusPill tone="live" icon="globe" className="mt-1.5">
                                    Live now
                                </StatusPill>
                            )}
                        </div>
                        {canRestore && (
                            <Button size="sm" variant="ghost" icon="reset" onClick={() => onRestore(r)}>
                                Restore
                            </Button>
                        )}
                    </li>
                ))}
            </ol>
        </div>
    );
}
