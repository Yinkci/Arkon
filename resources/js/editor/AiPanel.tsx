import { useEffect, useId, useState } from 'react';
import type { Issue } from '@/arkon/rules';
import { isActive, isReviewable, type AiConnection, type AiProposal, type AiRequestView } from '@/arkon/editor/proposals';

export interface AiPanelProps {
    /** Whether this user may use AI on this page (edit permission). */
    available: boolean;
    unavailableReason: string | null;
    promptMax: number;
    connection: AiConnection;
    /** The user's requests on this page that are running or waiting for review. */
    requests: AiRequestView[];
    /** The request this panel asked for most recently, while it runs and after it ends. */
    tracked: AiRequestView | null;
    sending: boolean;
    proposal: AiProposal | null;
    applyBlocker: string | null;
    error: { message: string; issues?: Issue[] } | null;
    history: { prompt: string; outcome: 'applied' | 'discarded' }[];
    onAsk(prompt: string): void;
    onCancel(id: string): void;
    onReview(id: string): void;
    onApply(): void;
    onDiscard(): void;
}

const SOURCE: Record<string, string> = { mcp: 'From Claude Code in VS Code', panel: 'From this panel', api: 'Earlier request' };

/**
 * Prompt → queued → Claude Code (local helper) → proposal → Apply or Discard. Proposals sent
 * from Claude Code in VS Code wait here too. Applying adds the proposal to the draft as one
 * undoable edit; publishing stays the Publish button.
 */
export function AiPanel(props: AiPanelProps) {
    const [prompt, setPrompt] = useState('');
    const ids = { prompt: useId(), hint: useId(), proposal: useId() };
    const { proposal, connection, tracked } = props;
    const tooLong = prompt.length > props.promptMax;
    const running = tracked !== null && isActive(tracked);
    const waiting = props.requests.filter((r) => isReviewable(r) && r.id !== proposal?.id);

    if (!props.available) {
        return (
            <section className="space-y-2 p-4" aria-label="AI">
                <h2 className="text-sm font-semibold">Ask AI</h2>
                <p className="text-xs text-zinc-600" data-testid="ai-unavailable">
                    {props.unavailableReason}
                </p>
            </section>
        );
    }

    return (
        <section className="space-y-4 p-4" aria-label="AI">
            <p
                data-testid="ai-connection"
                data-ready={connection.ready}
                className={`flex items-start gap-1.5 text-xs ${connection.ready ? 'text-emerald-800' : 'text-amber-900'}`}
            >
                <span aria-hidden="true" className={`mt-1 size-2 shrink-0 rounded-full ${connection.ready ? 'bg-emerald-500' : 'bg-amber-500'}`} />
                <span>{connection.ready ? `Connected: ${connection.message}` : connection.message}</span>
            </p>

            {!proposal && (
                <form
                    className="space-y-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (prompt.trim() && !tooLong && !props.sending && !running) props.onAsk(prompt.trim());
                    }}
                >
                    <label htmlFor={ids.prompt} className="block text-sm font-semibold">
                        Ask AI to change this page
                    </label>
                    <textarea
                        id={ids.prompt}
                        rows={5}
                        value={prompt}
                        disabled={props.sending || running}
                        aria-describedby={ids.hint}
                        placeholder="Build a homepage for a landscaping business, with a hero, services, about section and contact button."
                        onChange={(e) => setPrompt(e.target.value)}
                        className="w-full rounded-md border border-zinc-300 px-2.5 py-1.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 disabled:bg-zinc-50"
                    />
                    <p id={ids.hint} className={`text-xs ${tooLong ? 'text-red-700' : 'text-zinc-500'}`}>
                        {tooLong
                            ? `Too long: ${prompt.length} of ${props.promptMax} characters.`
                            : 'Claude Code (your subscription) prepares a proposal on this computer. You preview it first; nothing changes until you apply it, and nothing goes live until you publish.'}
                    </p>
                    <button
                        type="submit"
                        disabled={props.sending || running || !prompt.trim() || tooLong || !connection.ready}
                        className="w-full rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {props.sending ? 'Sending…' : 'Generate proposal'}
                    </button>
                </form>
            )}

            {tracked && !proposal && <RequestState request={tracked} onCancel={props.onCancel} />}

            {props.error && (
                <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-800" data-testid="ai-error">
                    <p>{props.error.message}</p>
                    {props.error.issues && props.error.issues.length > 0 && (
                        <ul className="mt-1 list-disc pl-4">
                            {props.error.issues.slice(0, 6).map((issue, i) => (
                                <li key={i}>{issue.message}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {!proposal && waiting.length > 0 && (
                <div className="space-y-1.5" data-testid="ai-waiting">
                    <h3 className="text-xs font-medium text-zinc-600">Waiting for your review</h3>
                    <ul className="space-y-1.5">
                        {waiting.map((request) => (
                            <li key={request.id} className="flex items-start justify-between gap-2 rounded-md border border-zinc-200 p-2 text-xs">
                                <span className="min-w-0">
                                    <span className="block text-zinc-500">{SOURCE[request.source] ?? request.source}</span>
                                    <span className="block truncate">“{request.prompt}”</span>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => props.onReview(request.id)}
                                    className="shrink-0 rounded-md border border-zinc-300 px-2 py-1 hover:bg-zinc-50"
                                >
                                    Review
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {proposal && (
                <article aria-labelledby={ids.proposal} className="space-y-3" data-testid="ai-proposal">
                    <div>
                        <h2 id={ids.proposal} className="text-sm font-semibold">
                            {proposal.status === 'empty' ? 'No changes proposed' : 'Proposal (preview only)'}
                        </h2>
                        <p className="mt-0.5 text-xs text-zinc-500">
                            “{proposal.prompt}” · based on draft version {proposal.baseVersion}
                        </p>
                    </div>
                    <p className="text-sm">{proposal.summary}</p>
                    {proposal.changes.length > 0 && (
                        <div>
                            <h3 className="text-xs font-medium text-zinc-600">What will change</h3>
                            <ul className="mt-1 list-disc space-y-0.5 pl-4 text-xs" data-testid="ai-changes">
                                {proposal.changes.map((change, i) => (
                                    <li key={i}>{change}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {proposal.warnings.length > 0 && (
                        <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900">
                            <p className="font-medium">Before you can publish</p>
                            <ul className="list-disc pl-4">
                                {proposal.warnings.map((warning, i) => (
                                    <li key={i}>{warning}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {proposal.notes.length > 0 && (
                        <div className="text-xs text-zinc-600">
                            <p className="font-medium">Notes from the AI</p>
                            <ul className="list-disc pl-4" data-testid="ai-notes">
                                {proposal.notes.map((note, i) => (
                                    <li key={i}>{note}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {proposal.status !== 'empty' && !proposal.canvas && (
                        <p role="alert" className="text-xs text-red-700">
                            The draft changed after this proposal was made, so it can no longer be previewed or applied. Discard it and ask again.
                        </p>
                    )}
                    {props.applyBlocker && proposal.status !== 'empty' && proposal.canvas && (
                        <p role="alert" className="text-xs text-red-700">
                            {props.applyBlocker}
                        </p>
                    )}
                    <div className="flex gap-2">
                        {proposal.status !== 'empty' && (
                            <button
                                type="button"
                                disabled={props.applyBlocker !== null || !proposal.canvas}
                                onClick={props.onApply}
                                className="flex-1 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                            >
                                Apply to draft
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={props.onDiscard}
                            className="flex-1 rounded-md border border-zinc-300 px-3 py-1.5 text-sm hover:bg-zinc-50"
                        >
                            Discard
                        </button>
                    </div>
                </article>
            )}

            {props.history.length > 0 && (
                <div className="border-t border-zinc-200 pt-3 text-xs text-zinc-500">
                    <h3 className="font-medium">Earlier requests</h3>
                    <ul className="mt-1 space-y-0.5">
                        {props.history.map((entry, i) => (
                            <li key={i}>
                                {entry.outcome === 'applied' ? 'Applied' : 'Discarded'}: “{entry.prompt}”
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

const STATE_TEXT: Record<string, string> = {
    queued: 'Queued: waiting for the local helper to pick it up…',
    running: 'Claude Code is working on it…',
    cancelled: 'Cancelled.',
};

function RequestState({ request, onCancel }: { request: AiRequestView; onCancel(id: string): void }) {
    const [now, setNow] = useState(() => Date.now());
    const active = isActive(request);
    useEffect(() => {
        if (!active) return;
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, [active]);
    if (request.status === 'failed') {
        return (
            <div role="alert" className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-800" data-testid="ai-request-failed">
                <p className="font-medium">The request failed</p>
                <p>{request.error?.message}</p>
            </div>
        );
    }
    if (!STATE_TEXT[request.status]) return null;
    const since = request.startedAt ?? request.createdAt;
    const seconds = Math.max(0, Math.round((now - new Date(since).getTime()) / 1000));
    return (
        <div
            className="flex items-center justify-between gap-2 rounded-md border border-zinc-200 bg-zinc-50 p-2 text-xs"
            data-testid="ai-request"
            data-status={request.status}
        >
            <span>
                {request.status === 'cancelled' && request.error?.code === 'AI_SUPERSEDED' ? 'Replaced by a newer request.' : STATE_TEXT[request.status]}
                {active && <span className="ml-1 text-zinc-500 tabular-nums">{seconds}s</span>}
            </span>
            {active && (
                <button
                    type="button"
                    onClick={() => onCancel(request.id)}
                    className="shrink-0 rounded-md border border-zinc-300 bg-white px-2 py-1 hover:bg-zinc-100"
                >
                    Cancel
                </button>
            )}
        </div>
    );
}
