// Panel pieces of Build a website. Each live clock lives in the component that shows it, so a ticking
// timer never re-renders the workspace (or the preview).
import { useEffect, useId, useState, type KeyboardEvent, type ReactNode } from 'react';
import { Icon, type IconName } from '@/Components/Icon';
import { ago, elapsed, statusLabel, requestTitle, type Connection, type Proposal, type Stage } from './types';

export function useNow(interval = 1000, active = true) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        if (!active) return;
        setNow(Date.now());
        const timer = setInterval(() => setNow(Date.now()), interval);
        return () => clearInterval(timer);
    }, [interval, active]);
    return now;
}

const STAGE_STATUS: Record<Stage['status'], string> = {
    waiting: 'Waiting',
    running: 'Working',
    retrying: 'No reply; trying once more',
    done: 'Done',
    cached: 'Kept from last attempt',
    failed: 'Failed',
};

/**
 * The state of the run as one mark that settles rather than swaps: a ring spinning on its violet segment while
 * the provider works, then the same ring in green with its centre filled once the proposal is ready.
 */
export function StateDot({ working }: { working: boolean }) {
    return (
        <span
            aria-hidden="true"
            className={
                working
                    ? 'size-3 animate-spin rounded-full border-[1.6px] border-line-strong border-t-ai [animation-duration:0.9s]'
                    : 'size-3 rounded-full border-[1.6px] border-live bg-live shadow-[inset_0_0_0_2.5px_var(--ak-surface)]'
            }
        />
    );
}

/** A step's marker in the trace: an outlined dot until it runs, then filled. */
function TraceDot({ state }: { state: 'pending' | 'active' | 'done' | 'failed' }) {
    const fill =
        state === 'failed'
            ? 'border-danger bg-danger'
            : state === 'done'
              ? 'border-ai bg-ai'
              : state === 'active'
                ? 'animate-pulse border-ai bg-ai'
                : 'border-current';
    return <span aria-hidden="true" className={`size-2.5 shrink-0 rounded-full border-[1.4px] ${fill}`} />;
}

/**
 * The run's real steps as a trace: monospace, dim until a step is reached, each with its own dot and time.
 * No invented percentages — every line is a step the server reported.
 */
export function StageList({ stages, live = false }: { stages: Stage[]; live?: boolean }) {
    const now = useNow(1000, live);
    return (
        <ol className="grid gap-1.5 font-mono text-2xs" aria-label="Stages">
            {stages.map((s) => {
                const done = s.status === 'done' || s.status === 'cached';
                const active = s.status === 'running' || s.status === 'retrying';
                const failed = s.status === 'failed';
                return (
                    <li key={s.key} aria-current={s.status === 'running' ? 'step' : undefined}>
                        <span className={`flex items-center gap-2 ${failed ? 'text-danger' : done || active ? 'text-fg' : 'text-faint opacity-40'}`}>
                            <TraceDot state={failed ? 'failed' : done ? 'done' : active ? 'active' : 'pending'} />
                            <span className="min-w-0 flex-1 truncate">{s.label}</span>
                            <span className="shrink-0 tabular-nums">
                                {s.status === 'running' && s.startedAt ? elapsed(s.startedAt, now) : s.seconds !== null ? `${s.seconds}s` : ''}
                            </span>
                        </span>
                        {(s.status === 'retrying' || s.status === 'cached' || s.error) && (
                            <span className={`block pl-[18px] ${s.error ? 'text-danger' : 'text-faint'}`}>{s.error ?? STAGE_STATUS[s.status]}</span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

/** The same trace for a run that reports no stage list of its own (queued, or a provider without staging). */
export function SimpleSteps({ steps, current }: { steps: string[]; current: string }) {
    const at = steps.indexOf(current);
    return (
        <ol className="grid gap-1.5 font-mono text-2xs" aria-label="Stages">
            {steps.map((name, i) => (
                <li
                    key={name}
                    aria-current={i === at ? 'step' : undefined}
                    className={`flex items-center gap-2 ${i <= at ? 'text-fg' : 'text-faint opacity-40'}`}
                >
                    <TraceDot state={i < at ? 'done' : i === at ? 'active' : 'pending'} />
                    <span className="min-w-0 flex-1 truncate">{name}</span>
                </li>
            ))}
        </ol>
    );
}

/**
 * The blocks this proposal is built from, as the catalogue chips of the reference: everything Arkon placed,
 * with a theme component marked as one. Read from the proposal's own documents, so it is never a guess.
 */
export function ComponentBadges({ blocks }: { blocks: { type: string; label: string; theme: boolean }[] }) {
    if (blocks.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-wrap gap-1" aria-label="Blocks used">
            {blocks.map((b) => (
                <li
                    key={b.type}
                    className={`rounded-sm px-[7px] py-[3px] font-semibold text-2xs ring-1 ring-inset ${b.theme ? 'bg-site-soft text-site ring-site/40' : 'bg-ai-soft text-ai ring-ai/40'}`}
                >
                    {b.label}
                    {b.theme && ' · theme'}
                </li>
            ))}
        </ul>
    );
}

/** Elapsed time and whether the helper is still answering, refreshed every second. */
export function LiveTiming({ from, heartbeatAt, waiting }: { from: string; heartbeatAt: string | null; waiting?: string }) {
    const now = useNow();
    return (
        <p className="text-xs text-muted t-num">
            Elapsed: {elapsed(from, now)} ·{' '}
            {waiting ??
                (heartbeatAt
                    ? now - Date.parse(heartbeatAt) < 15000
                        ? 'Helper responding'
                        : 'No recent helper heartbeat; checking connection'
                    : 'Waiting for the first helper heartbeat')}
        </p>
    );
}

/** A quiet disclosure ("View changes", "Details") for content that is there when asked for. */
export function Disclosure({ summary, children, className = '' }: { summary: ReactNode; children: ReactNode; className?: string }) {
    return (
        <details className={`group ${className}`}>
            <summary className="inline-flex cursor-pointer list-none items-center gap-1 rounded text-xs font-medium text-muted hover:text-fg focus-visible:outline-2 focus-visible:outline-accent [&::-webkit-details-marker]:hidden">
                <Icon name="chevronRight" className="size-3 transition-transform group-open:rotate-90" />
                {summary}
            </summary>
            <div className="mt-2 space-y-2 pl-4 text-xs text-muted">{children}</div>
        </details>
    );
}

/** The provider choice, shown only when there is a real choice to make. */
export function ProviderSelect({
    connection,
    value,
    onChange,
    disabled,
    label = 'AI provider for this task',
}: {
    connection: Connection;
    value?: string;
    onChange(value: string | undefined): void;
    disabled?: boolean;
    label?: string;
}) {
    const id = useId();
    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="ui-label">
                {label}
            </label>
            <select id={id} className="ui-input" value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value || undefined)}>
                <option value="">{connection.ready && connection.providerName ? `Automatic (${connection.providerName})` : 'Choose a connected AI'}</option>
                {connection.providers?.map((p) => (
                    <option key={p.id} value={p.id} disabled={!p.ready}>
                        {p.name}
                        {!p.ready ? ' — unavailable' : ''}
                    </option>
                ))}
            </select>
        </div>
    );
}

const DOT: Record<string, string> = { live: 'bg-live', ai: 'bg-ai', changed: 'bg-changed', danger: 'bg-danger', neutral: 'bg-line-strong' };

/** "Today", "Yesterday" or the date: History groups requests by the day they were made. */
function dayLabel(iso: string, now: number) {
    const day = (t: number) => new Date(t).toDateString();
    const at = Date.parse(iso);
    if (day(at) === day(now)) return 'Today';
    if (day(at) === day(now - 86_400_000)) return 'Yesterday';
    return new Date(at).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: new Date(at).getFullYear() === new Date(now).getFullYear() ? undefined : 'numeric',
    });
}

/** Every request, newest first, grouped by day: a short title, its status and when. Choosing one shows it. */
export function HistoryList({
    requests,
    selected,
    onSelect,
    disabled,
}: {
    requests: Proposal[];
    selected: string | null;
    onSelect(id: string): void;
    disabled: boolean;
}) {
    const now = useNow(30000);
    if (!requests.length) {
        return (
            <div className="flex flex-col items-center px-4 py-10 text-center">
                <Icon name="history" className="size-5 text-faint" />
                <p className="mt-2 t-title">No requests yet</p>
                <p className="mt-1 t-meta">What you ask Arkon to build or change appears here.</p>
            </div>
        );
    }
    const groups: { label: string; items: Proposal[] }[] = [];
    for (const r of requests) {
        const label = dayLabel(r.createdAt, now);
        if (groups.at(-1)?.label !== label) groups.push({ label, items: [] });
        groups.at(-1)!.items.push(r);
    }
    return (
        <div className="space-y-4" aria-label="Website requests" role="list">
            {groups.map((g) => (
                <section key={g.label} role="listitem" aria-label={g.label}>
                    <h3 className="px-2 pb-1 t-eyebrow">{g.label}</h3>
                    <ul className="space-y-0.5">
                        {g.items.map((r) => {
                            const s = statusLabel(r);
                            return (
                                <li key={r.id}>
                                    <button
                                        type="button"
                                        disabled={disabled}
                                        aria-current={selected === r.id ? 'true' : undefined}
                                        onClick={() => onSelect(r.id)}
                                        className={`flex w-full items-start gap-2.5 rounded-md px-2 py-2 text-left transition-colors ${selected === r.id ? 'bg-accent-soft ring-1 ring-accent-line ring-inset' : 'hover:bg-hover'}`}
                                    >
                                        <span aria-hidden="true" className={`mt-1.5 size-2 shrink-0 rounded-full ${DOT[s.tone]}`} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-ui font-medium text-fg" title={r.prompt}>
                                                {requestTitle(r)}
                                            </span>
                                            <span className="block truncate text-xs text-muted">
                                                {s.text} · {ago(r.resolvedAt ?? r.createdAt, now)}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}
        </div>
    );
}

/** Accessible tabs: arrow keys move between them, each controls its panel. */
export function PanelTabs<T extends string>({
    id,
    tabs,
    value,
    onChange,
}: {
    id: string;
    tabs: { value: T; label: string; icon: IconName; count?: number }[];
    value: T;
    onChange(value: T): void;
}) {
    const onKey = (e: KeyboardEvent<HTMLButtonElement>) => {
        const at = tabs.findIndex((t) => t.value === value);
        const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
        if (!step) return;
        e.preventDefault();
        const next = tabs[(at + step + tabs.length) % tabs.length]!;
        onChange(next.value);
        document.getElementById(`${id}-tab-${next.value}`)?.focus();
    };
    return (
        <div role="tablist" aria-label="Assistant" className="ak-band-x flex h-9 shrink-0 items-stretch gap-4">
            {tabs.map((t) => {
                const active = t.value === value;
                return (
                    <button
                        key={t.value}
                        id={`${id}-tab-${t.value}`}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        aria-controls={`${id}-panel-${t.value}`}
                        tabIndex={active ? 0 : -1}
                        onKeyDown={onKey}
                        onClick={() => onChange(t.value)}
                        className={`relative flex items-center gap-1.5 text-xs font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-accent ${active ? 'text-fg' : 'text-muted hover:text-fg'}`}
                    >
                        <Icon name={t.icon} className={`size-3.5 transition-colors duration-150 ${active ? 'text-ai' : ''}`} />
                        {t.label}
                        {t.count ? <span className="rounded-sm bg-sunken px-1 text-2xs text-muted t-num">{t.count}</span> : null}
                        {active && <span aria-hidden className="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-ai" />}
                    </button>
                );
            })}
        </div>
    );
}
