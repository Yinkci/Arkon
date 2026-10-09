import { useEffect, useId, useState } from 'react';
import type { Issue } from '@/arkon/rules';
import { isActive, isReviewable, type AiConnection, type AiProposal, type AiRequestView } from '@/arkon/editor/proposals';
import { Icon } from '@/Components/Icon';
import { Button, Notice, Spinner, StatusPill } from '@/Components/ui';

export interface AiPanelProps {
    seoScores?: { before: number; after: number };
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
    /** Applies the proposal's site-wide token changes to the token draft (never publishes). */
    onApplyTokens?(): void;
    tokensBusy?: boolean;
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
            <section className="p-4" aria-label="AI">
                <Notice tone="info" title="AI is not available here">
                    <p data-testid="ai-unavailable">{props.unavailableReason}</p>
                </Notice>
            </section>
        );
    }

    const hasPageChanges = proposal !== null && proposal.status !== 'empty' && proposal.operations.length > 0;
    const tokenChanges = proposal?.tokenChanges ?? [];

    return (
        <section className="divide-y divide-line" aria-label="AI">
            <div className="px-4 py-3">
                <p
                    data-testid="ai-connection"
                    data-ready={connection.ready}
                    className={`flex items-start gap-2 rounded-md px-2.5 py-2 text-xs ${connection.ready ? 'bg-live-soft text-fg' : 'bg-changed-soft text-fg'}`}
                >
                    <Icon name={connection.ready ? 'check' : 'alert'} className={`mt-px size-3.5 ${connection.ready ? 'text-live' : 'text-changed'}`} />
                    <span>{connection.ready ? `Connected: ${connection.message}` : connection.message}</span>
                </p>
            </div>

            {!proposal && (
                <form
                    className="space-y-2 px-4 py-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (prompt.trim() && !tooLong && !props.sending && !running) props.onAsk(prompt.trim());
                    }}
                >
                    <label htmlFor={ids.prompt} className="block text-ui font-semibold">
                        Ask AI to change this page
                    </label>
                    <textarea
                        id={ids.prompt}
                        rows={5}
                        value={prompt}
                        disabled={props.sending || running}
                        aria-describedby={ids.hint}
                        aria-invalid={tooLong}
                        placeholder="Put the hero image on the right, 500px tall with cover cropping, and stack it below the text on mobile."
                        onChange={(e) => setPrompt(e.target.value)}
                        className="ui-input"
                    />
                    <p id={ids.hint} className={`text-2xs leading-snug ${tooLong ? 'text-danger' : 'text-muted'}`}>
                        {tooLong
                            ? `Too long: ${prompt.length} of ${props.promptMax} characters.`
                            : 'Claude Code (your subscription) prepares a proposal on this computer. You preview it first; nothing changes until you apply it, and nothing goes live until you publish.'}
                    </p>
                    <Button
                        type="submit"
                        variant="primary"
                        icon="sparkle"
                        busy={props.sending}
                        className="w-full"
                        disabled={props.sending || running || !prompt.trim() || tooLong || !connection.ready}
                    >
                        {props.sending ? 'Sending…' : 'Generate proposal'}
                    </Button>
                </form>
            )}

            {tracked && !proposal && (
                <div className="px-4 py-3">
                    <RequestState request={tracked} onCancel={props.onCancel} />
                </div>
            )}

            {props.error && (
                <div className="px-4 py-3">
                    <Notice tone="error" data-testid="ai-error">
                        <p>{props.error.message}</p>
                        {props.error.issues && props.error.issues.length > 0 && (
                            <ul className="list-disc pl-4 text-xs">
                                {props.error.issues.slice(0, 6).map((issue, i) => (
                                    <li key={i}>{issue.message}</li>
                                ))}
                            </ul>
                        )}
                    </Notice>
                </div>
            )}

            {!proposal && waiting.length > 0 && (
                <div className="space-y-2 px-4 py-3" data-testid="ai-waiting">
                    <h3 className="text-xs font-semibold">Waiting for your review</h3>
                    <ul className="space-y-1.5">
                        {waiting.map((request) => (
                            <li key={request.id} className="flex items-start justify-between gap-2 rounded-md border border-ai/25 bg-ai-soft p-2.5 text-xs">
                                <span className="min-w-0">
                                    <span className="block text-2xs text-muted">{SOURCE[request.source] ?? request.source}</span>
                                    <span className="block truncate font-medium">“{request.prompt}”</span>
                                </span>
                                <Button size="sm" onClick={() => props.onReview(request.id)}>
                                    Review
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {proposal && (
                <article aria-labelledby={ids.proposal} className="space-y-3 px-4 py-4" data-testid="ai-proposal">
                    <div>
                        <StatusPill tone="ai" icon="sparkle">
                            {proposal.status === 'empty' ? 'Nothing to apply' : 'Preview only: nothing changed yet'}
                        </StatusPill>
                        <h2 id={ids.proposal} className="mt-2 t-title">
                            {proposal.status === 'empty' ? 'No changes proposed' : 'Proposal (preview only)'}
                        </h2>
                        <p className="mt-0.5 text-2xs text-muted">
                            “{proposal.prompt.replace(/^ARKON_SEO_METADATA_ONLY:[A-Za-z]+\n/, '')}” · based on draft version {proposal.baseVersion}
                        </p>
                    </div>
                    <p className="text-ui leading-relaxed">{proposal.summary}</p>
                    {props.seoScores && (
                        <p data-testid="seo-projection" className="rounded-md border border-line p-3 text-sm">
                            SEO before: {props.seoScores.before} / 100 · Proposed: {props.seoScores.after} / 100
                            <br />
                            <span className="text-xs text-muted">Calculated from the proposed output. Nothing is applied or published yet.</span>
                        </p>
                    )}

                    {proposal.changes.length > 0 && (
                        <div className="rounded-md border border-line">
                            <h3 className="flex items-center gap-1.5 border-b border-line bg-raised px-2.5 py-1.5 text-2xs font-semibold">
                                <Icon name="pages" className="size-3.5 text-muted" />
                                This page only: what will change
                            </h3>
                            <ul className="list-disc space-y-0.5 py-2 pr-2.5 pl-6 text-xs" data-testid="ai-changes">
                                {proposal.changes.map((change, i) => (
                                    <li key={i}>{change}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {tokenChanges.length > 0 && (
                        <section
                            className="rounded-md border border-site/30 bg-site-soft p-2.5 text-xs"
                            data-testid="ai-token-changes"
                            aria-label="Site-wide design changes"
                        >
                            <p className="flex items-center gap-1.5 font-semibold">
                                <Icon name="globe" className="size-3.5 text-site" />
                                Site-wide design changes (every page)
                            </p>
                            <ul className="mt-1.5 space-y-1">
                                {tokenChanges.map((change) => (
                                    <li key={change.token} className="flex items-center gap-1.5">
                                        {/^#[0-9a-f]{3,8}$/i.test(change.value) && (
                                            <span
                                                aria-hidden
                                                className="inline-block size-3.5 rounded-sm border border-line-strong"
                                                style={{ background: change.value }}
                                            />
                                        )}
                                        <code className="font-mono">{change.token}</code> → <code className="font-mono">{change.value}</code>
                                    </li>
                                ))}
                            </ul>
                            {proposal.tokenChangesApplied ? (
                                <p className="mt-2 flex items-start gap-1.5" role="status">
                                    <Icon name="check" className="mt-px size-3.5 text-live" />
                                    <span>
                                        Applied to the token draft. Review and publish them on the{' '}
                                        <a href="/admin/design" className="font-medium underline">
                                            Design
                                        </a>{' '}
                                        page; until then no page changes.
                                    </span>
                                </p>
                            ) : (
                                <>
                                    <p className="mt-2 text-muted">
                                        Not part of “Apply to draft”. They only reach the site's token draft, and go live when you publish tokens on the Design
                                        page.
                                    </p>
                                    <Button
                                        size="sm"
                                        icon="globe"
                                        className="mt-2"
                                        busy={props.tokensBusy}
                                        disabled={props.tokensBusy || !props.onApplyTokens}
                                        onClick={props.onApplyTokens}
                                        data-testid="ai-apply-tokens"
                                    >
                                        Apply to the token draft
                                    </Button>
                                </>
                            )}
                        </section>
                    )}

                    {proposal.warnings.length > 0 && (
                        <Notice tone="warning" title="Before you can publish">
                            <ul className="list-disc pl-4 text-xs">
                                {proposal.warnings.map((warning, i) => (
                                    <li key={i}>{warning}</li>
                                ))}
                            </ul>
                        </Notice>
                    )}
                    {proposal.notes.length > 0 && (
                        <div className="rounded-md bg-raised p-2.5 text-xs">
                            <p className="font-semibold">Notes from the AI (including what it could not do)</p>
                            <ul className="mt-1 list-disc pl-4 text-muted" data-testid="ai-notes">
                                {proposal.notes.map((note, i) => (
                                    <li key={i}>{note}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {proposal.status !== 'empty' && proposal.operations.length > 0 && !proposal.canvas && (
                        <p role="alert" className="text-xs text-danger">
                            The draft changed after this proposal was made, so it can no longer be previewed or applied. Discard it and ask again.
                        </p>
                    )}
                    {props.applyBlocker && hasPageChanges && proposal.canvas && (
                        <p role="alert" className="flex items-start gap-1.5 text-xs text-danger">
                            <Icon name="alert" className="mt-px size-3.5" />
                            {props.applyBlocker}
                        </p>
                    )}
                    <div className="flex gap-2 pt-1">
                        {hasPageChanges && (
                            <Button
                                variant="primary"
                                icon="check"
                                className="flex-1"
                                disabled={props.applyBlocker !== null || !proposal.canvas}
                                onClick={props.onApply}
                            >
                                Apply to draft
                            </Button>
                        )}
                        <Button icon="close" className="flex-1" onClick={props.onDiscard}>
                            Discard
                        </Button>
                    </div>
                    {hasPageChanges && (
                        <p className="text-2xs text-muted">
                            Applying saves the changes to this page's draft as one step (Undo reverts it). Publishing stays separate.
                        </p>
                    )}
                </article>
            )}

            {props.history.length > 0 && (
                <div className="px-4 py-3 text-xs">
                    <h3 className="font-semibold">Earlier requests</h3>
                    <ul className="mt-1.5 space-y-1 text-muted">
                        {props.history.map((entry, i) => (
                            <li key={i} className="flex items-start gap-1.5">
                                <Icon
                                    name={entry.outcome === 'applied' ? 'check' : 'close'}
                                    className={`mt-px size-3.5 ${entry.outcome === 'applied' ? 'text-live' : 'text-faint'}`}
                                />
                                <span>
                                    {entry.outcome === 'applied' ? 'Applied' : 'Discarded'}: “{entry.prompt}”
                                </span>
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
            <Notice tone="error" title="The request failed" data-testid="ai-request-failed">
                <p>{request.error?.message}</p>
            </Notice>
        );
    }
    if (!STATE_TEXT[request.status]) return null;
    const since = request.startedAt ?? request.createdAt;
    const seconds = Math.max(0, Math.round((now - new Date(since).getTime()) / 1000));
    return (
        <div
            className={`flex items-center justify-between gap-2 rounded-md border p-2.5 text-xs ${active ? 'border-ai/25 bg-ai-soft' : 'border-line bg-raised'}`}
            data-testid="ai-request"
            data-status={request.status}
        >
            <span className="flex items-center gap-2">
                {active ? <Spinner className="size-3.5 text-ai" /> : <Icon name="close" className="size-3.5 text-muted" />}
                <span>
                    {request.status === 'cancelled' && request.error?.code === 'AI_SUPERSEDED' ? 'Replaced by a newer request.' : STATE_TEXT[request.status]}
                    {active && <span className="ml-1 text-muted tabular-nums">{seconds}s</span>}
                </span>
            </span>
            {active && (
                <Button size="sm" icon="stop" onClick={() => onCancel(request.id)}>
                    Cancel
                </Button>
            )}
        </div>
    );
}
