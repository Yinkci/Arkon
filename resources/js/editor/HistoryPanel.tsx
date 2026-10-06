import type { Revision } from '@/types';

export function HistoryPanel({ revisions, canRestore, onRestore }: { revisions: Revision[]; canRestore: boolean; onRestore(revision: Revision): void }) {
    if (revisions.length === 0) return <p className="p-4 text-sm text-zinc-500">No revisions yet.</p>;
    return (
        <ol className="divide-y divide-zinc-100" aria-label="Revision history">
            {revisions.map((r) => (
                <li key={r.id} className="flex items-start justify-between gap-2 px-4 py-3 text-sm" data-testid="revision">
                    <div className="min-w-0">
                        <p className="font-medium">
                            #{r.number} {r.message}
                            {r.isLive && <span className="ml-2 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">LIVE</span>}
                        </p>
                        <p className="truncate text-xs text-zinc-500">
                            {r.authorName ?? (r.source === 'system' ? 'System' : 'Unknown')} · {new Date(r.createdAt).toLocaleString('en-GB')}
                            {r.source === 'ai' && ' · AI'}
                        </p>
                    </div>
                    {canRestore && (
                        <button type="button" onClick={() => onRestore(r)} className="shrink-0 text-xs font-medium text-indigo-600 hover:underline">
                            Restore
                        </button>
                    )}
                </li>
            ))}
        </ol>
    );
}
