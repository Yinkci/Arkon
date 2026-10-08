import { Head, router } from '@inertiajs/react';
import { useId, useMemo, useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import type { Issue } from '@/arkon/rules';
import { styleRules, styleValueProblem } from '@/arkon/style/schema';
import { tokenDefinition, type TokenSet } from '@/arkon/style/tokens';
import { api, newRequestKey } from '@/lib/api';
import { Icon } from '@/Components/Icon';
import { Button, EmptyState, IconButton, Notice, StatusPill, buttonClass } from '@/Components/ui';

interface Refreshes {
    pending: number;
    failed: number;
    items: { id: string; page: string; path: string | null; cause: string; status: string; attempts: number; error: string | null; updatedAt: string }[];
}

interface TokenState {
    draft: TokenSet;
    version: number;
    published: { version: number | null; tokens: TokenSet };
    defaults: TokenSet;
    groups: Record<string, { label: string; kind: string; lengths: string | null }>;
    changed: boolean;
    refreshes: Refreshes;
}

interface ComponentRow {
    id: string;
    name: string;
    version: number;
    published: number | null;
    changed: boolean;
    livePages: number;
    updatedAt: string;
}

/**
 * The site's design: design tokens (colours, fonts, type scale, spacing, widths, radii,
 * shadows) and reusable components. Drafts never reach live pages; publishing creates
 * a new version and re-renders the live pages that use it, with their status here.
 */
export default function Design(props: { tokens: TokenState; components: ComponentRow[]; permissions: { edit: boolean; publish: boolean } }) {
    const [state, setState] = useState(props.tokens);
    const [draft, setDraft] = useState<TokenSet>(() => structuredClone(props.tokens.draft ?? {}));
    const [notice, setNotice] = useState<{ tone: 'error' | 'info'; message: string; issues?: Issue[] } | null>(null);
    const [busy, setBusy] = useState(false);
    const [refreshes, setRefreshes] = useState(props.tokens.refreshes);
    const saveAttempt = useRef<{ key: string; body: string } | null>(null);
    const publishAttempt = useRef<{ key: string; version: number } | null>(null);
    const dirty = JSON.stringify(draft) !== JSON.stringify(state.draft ?? {});
    const { edit: canEdit, publish: canPublish } = props.permissions;

    const reload = async () => {
        const result = await api<TokenState>('/design/tokens');
        if (result.ok) {
            setState(result.data);
            setDraft(structuredClone(result.data.draft ?? {}));
            setRefreshes(result.data.refreshes);
        }
    };

    const save = async (): Promise<number | null> => {
        const body = JSON.stringify(draft);
        // Same change after an uncertain response → same key (a retry, never a second save).
        const attempt = saveAttempt.current?.body === body ? saveAttempt.current : { key: newRequestKey(), body };
        saveAttempt.current = attempt;
        setBusy(true);
        try {
            const result = await api<{ version: number }>('/design/tokens/save', { body: { baseVersion: state.version, tokens: draft, saveKey: attempt.key } });
            if (!result.ok) {
                if (result.code !== 'INTERNAL') saveAttempt.current = null;
                setNotice({
                    tone: 'error',
                    message: result.code === 'STALE_VERSION' ? 'The tokens were changed elsewhere. Reload the page to continue.' : result.message,
                    issues: result.issues,
                });
                return null;
            }
            saveAttempt.current = null;
            setState((s) => ({ ...s, draft: structuredClone(draft), version: result.data.version, changed: true }));
            setNotice({ tone: 'info', message: 'Token draft saved. Nothing on the live site changes until you publish.' });
            return result.data.version;
        } catch {
            setNotice({ tone: 'error', message: "Couldn't confirm the save (network problem). Save again to retry safely." });
            return null;
        } finally {
            setBusy(false);
        }
    };

    const publish = async () => {
        const version = dirty ? await save() : state.version;
        if (version === null) return;
        const attempt = publishAttempt.current?.version === version ? publishAttempt.current : { key: newRequestKey(), version };
        publishAttempt.current = attempt;
        setBusy(true);
        try {
            const result = await api<{ version: number; refreshes: { queued: number; done: number; failed: number; skipped: number } }>(
                '/design/tokens/publish',
                {
                    body: { expectedVersion: version, idempotencyKey: attempt.key },
                },
            );
            if (!result.ok) {
                if (result.code !== 'INTERNAL') publishAttempt.current = null;
                setNotice({ tone: 'error', message: result.message, issues: result.issues });
                return;
            }
            publishAttempt.current = null;
            const r = result.data.refreshes;
            setNotice({
                tone: r.failed > 0 ? 'error' : 'info',
                message: `Published design tokens version ${result.data.version}. ${r.queued} live page${r.queued === 1 ? '' : 's'} to update: ${r.done} updated${r.failed ? `, ${r.failed} failed (see below)` : ''}${r.queued - r.done - r.failed - r.skipped > 0 ? ', the rest are queued' : ''}.`,
            });
            await reload();
        } catch {
            setNotice({ tone: 'error', message: "Couldn't confirm the publish (network problem). Click Publish again: it never publishes twice." });
        } finally {
            setBusy(false);
        }
    };

    const retry = async () => {
        setBusy(true);
        try {
            const result = await api<Refreshes>('/design/refreshes/retry', { body: {} });
            if (result.ok) setRefreshes(result.data);
            else setNotice({ tone: 'error', message: result.message });
        } finally {
            setBusy(false);
        }
    };

    const publishedTokens = state.published.tokens ?? {};
    const changedTokens = (group: string, name: string) => (draft[group]?.[name] ?? null) !== (publishedTokens[group]?.[name] ?? null);

    return (
        <AdminLayout>
            <Head title="Design" />
            <div className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-8 sm:py-8">
                <header className="max-w-3xl">
                    <h1 className="text-2xl font-semibold tracking-tight">Design</h1>
                    <p className="mt-1 text-[0.875rem] text-muted">
                        Shared values and components used across the site. Changes are drafts until you publish them; publishing updates every live page that
                        uses them.
                    </p>
                </header>

                {notice && (
                    <Notice tone={notice.tone === 'error' ? 'error' : 'success'} onDismiss={() => setNotice(null)}>
                        <p>{notice.message}</p>
                        {notice.issues && (
                            <ul className="list-disc pl-4 text-xs text-muted">
                                {notice.issues.slice(0, 6).map((i, n) => (
                                    <li key={n}>
                                        {i.path ? `${i.path}: ` : ''}
                                        {i.message}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Notice>
                )}

                <RefreshStatus refreshes={refreshes} canRetry={canPublish} busy={busy} onRetry={() => void retry()} />

                <section aria-labelledby="tokens-heading" className="rounded-lg border border-line bg-surface shadow-hairline">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                        <div className="min-w-0">
                            <h2 id="tokens-heading" className="text-sm font-semibold">
                                Design tokens
                            </h2>
                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                                <StatusPill tone="live" icon="globe">
                                    {state.published.version ? `Live: version ${state.published.version}` : 'Live: the defaults'}
                                </StatusPill>
                                <StatusPill
                                    tone={dirty ? 'changed' : state.changed ? 'site' : 'neutral'}
                                    icon={dirty ? 'dots' : state.changed ? 'clock' : 'check'}
                                >
                                    {dirty ? 'Unsaved changes.' : state.changed ? 'The draft differs from what is live.' : 'The draft matches what is live.'}
                                </StatusPill>
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <Button icon="save" disabled={!canEdit || !dirty || busy} onClick={() => void save()} data-testid="tokens-save">
                                Save draft
                            </Button>
                            <Button
                                variant="primary"
                                icon="globe"
                                busy={busy}
                                disabled={!canPublish || busy || (!dirty && !state.changed)}
                                onClick={() => void publish()}
                                data-testid="tokens-publish"
                                title={canPublish ? 'Publish the tokens: every live page that uses them is updated' : "You don't have permission to publish"}
                            >
                                Publish tokens
                            </Button>
                        </div>
                    </div>
                    <p className="flex items-center gap-1.5 border-b border-line bg-site-soft px-4 py-2 text-xs text-fg">
                        <Icon name="globe" className="size-3.5 text-site" />
                        Site-wide: tokens are used by every page. Publishing re-renders the live pages that use them.
                    </p>
                    <div className="grid gap-px bg-line md:grid-cols-2">
                        {Object.entries(state.groups).map(([group, definition]) => (
                            <fieldset key={group} className="min-w-0 bg-surface px-4 py-3.5">
                                <legend className="sr-only">{definition.label}</legend>
                                <h3 aria-hidden className="mb-2.5 text-xs font-semibold">
                                    {definition.label}
                                </h3>
                                <div className="space-y-2">
                                    {Object.entries(state.defaults[group] ?? {}).map(([name, fallback]) => (
                                        <TokenField
                                            key={`${name}:${state.version}`}
                                            group={group}
                                            name={name}
                                            kind={definition.kind}
                                            value={draft[group]?.[name]}
                                            fallback={fallback}
                                            changed={changedTokens(group, name)}
                                            disabled={!canEdit || busy}
                                            onChange={(value) =>
                                                setDraft((current) => {
                                                    const next = structuredClone(current);
                                                    if (value === null) {
                                                        delete next[group]?.[name];
                                                        if (next[group] && Object.keys(next[group]!).length === 0) delete next[group];
                                                    } else (next[group] ??= {})[name] = value;
                                                    return next;
                                                })
                                            }
                                        />
                                    ))}
                                </div>
                            </fieldset>
                        ))}
                    </div>
                </section>

                <ComponentsSection components={props.components} canEdit={canEdit} />
            </div>
        </AdminLayout>
    );
}

function TokenField(props: {
    group: string;
    name: string;
    kind: string;
    value: string | undefined;
    fallback: string;
    changed: boolean;
    disabled: boolean;
    onChange(value: string | null): void;
}) {
    const id = useId();
    const definition = tokenDefinition(props.group, props.name);
    const [text, setText] = useState(props.value ?? '');
    const problem = text.trim() === '' ? null : styleValueProblem(definition, text.trim());
    const shown = props.value ?? props.fallback;
    const set = (value: string) => {
        setText(value);
        const trimmed = value.trim();
        if (trimmed === '') props.onChange(null);
        else if (styleValueProblem(definition, trimmed) === null) props.onChange(trimmed);
    };
    const options = props.kind === 'font' ? Object.keys(styleRules.fonts) : props.kind === 'shadowPreset' ? Object.keys(styleRules.shadows) : null;

    return (
        <div className="grid grid-cols-[minmax(0,7.5rem)_minmax(0,1fr)] items-center gap-x-2 gap-y-1" data-testid={`token-${props.group}-${props.name}`}>
            <label htmlFor={id} className="flex min-w-0 items-center gap-1.5">
                <code className="truncate font-mono text-[11px] text-muted">{props.name}</code>
                {props.changed && (
                    <span className="size-1.5 shrink-0 rounded-full bg-site" title="Draft differs from live">
                        <span className="sr-only">(draft differs from live)</span>
                    </span>
                )}
            </label>
            <div className="flex min-w-0 items-center gap-1.5">
                {props.kind === 'color' && (
                    <input
                        type="color"
                        aria-label={`${props.name} colour picker`}
                        disabled={props.disabled}
                        value={/^#[0-9a-f]{6}$/i.test(shown) ? shown : '#000000'}
                        onChange={(e) => set(e.target.value)}
                        className="h-8 w-9 shrink-0 cursor-pointer rounded-md border border-line-strong bg-surface p-0.5"
                    />
                )}
                {options ? (
                    <select id={id} className="ui-input" disabled={props.disabled} value={props.value ?? ''} onChange={(e) => set(e.target.value)}>
                        <option value="">Default ({props.fallback})</option>
                        {options.map((o) => (
                            <option key={o} value={o}>
                                {o}
                            </option>
                        ))}
                    </select>
                ) : (
                    <input
                        id={id}
                        className="ui-input font-mono"
                        disabled={props.disabled}
                        value={text}
                        placeholder={props.fallback}
                        aria-invalid={problem !== null}
                        aria-label={`@${props.group}.${props.name}`}
                        onChange={(e) => set(e.target.value)}
                        onBlur={() => problem && setText(props.value ?? '')}
                    />
                )}
                {props.value !== undefined && (
                    <IconButton icon="reset" size="sm" disabled={props.disabled} label={`Reset ${props.name} to the default`} onClick={() => set('')} />
                )}
            </div>
            {problem && (
                <p role="alert" className="col-span-2 text-[11px] text-danger">
                    {problem}
                </p>
            )}
        </div>
    );
}

function RefreshStatus({ refreshes, canRetry, busy, onRetry }: { refreshes: Refreshes; canRetry: boolean; busy: boolean; onRetry(): void }) {
    if (refreshes.pending === 0 && refreshes.failed === 0) return null;
    return (
        <Notice
            tone={refreshes.failed > 0 ? 'error' : 'warning'}
            data-testid="refresh-status"
            title={[
                refreshes.failed > 0 ? `${refreshes.failed} live page${refreshes.failed === 1 ? '' : 's'} could not be updated.` : '',
                refreshes.pending > 0 ? `${refreshes.pending} waiting to be updated.` : '',
            ]
                .filter(Boolean)
                .join(' ')}
            action={
                canRetry ? (
                    <Button size="sm" icon="reset" busy={busy} disabled={busy} onClick={onRetry}>
                        Retry now
                    </Button>
                ) : undefined
            }
        >
            <p className="text-xs text-muted">
                These pages still show their previous version (nothing is half-updated). Retrying re-renders them from what is published.
            </p>
            <ul className="space-y-0.5 text-xs">
                {refreshes.items.slice(0, 10).map((item) => (
                    <li key={item.id}>
                        <strong className="font-medium">{item.page}</strong> <span className="font-mono text-muted">{item.path}</span> · {item.cause} ·{' '}
                        {item.status}
                        {item.error ? `: ${item.error}` : ''}
                    </li>
                ))}
            </ul>
        </Notice>
    );
}

function ComponentsSection({ components, canEdit }: { components: ComponentRow[]; canEdit: boolean }) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const id = useId();
    const sorted = useMemo(() => [...components].sort((a, b) => a.name.localeCompare(b.name)), [components]);
    const create = async () => {
        setError(null);
        const result = await api<{ id: string }>('/components', { body: { name } });
        if (!result.ok) {
            setError(result.message);
            return;
        }
        router.visit(`/admin/components/${result.data.id}`);
    };
    return (
        <section aria-labelledby="components-heading" className="rounded-lg border border-line bg-surface shadow-hairline">
            <div className="border-b border-line px-4 py-3">
                <h2 id="components-heading" className="text-sm font-semibold">
                    Reusable components
                </h2>
                <p className="mt-0.5 text-xs text-muted">
                    Blocks you use on several pages (a banner, a call to action, a footer). Pages show the published version; publishing a change updates them
                    all.
                </p>
            </div>
            {sorted.length === 0 ? (
                <EmptyState icon="component" title="No components yet">
                    Create one below, or select a block in the builder and choose “Make reusable”.
                </EmptyState>
            ) : (
                <table className="w-full text-[0.8125rem]">
                    <thead className="text-left text-xs text-muted">
                        <tr className="border-b border-line">
                            <th className="px-4 py-2 font-medium">Name</th>
                            <th className="px-4 py-2 font-medium">Status</th>
                            <th className="hidden px-4 py-2 font-medium sm:table-cell">Used on live pages</th>
                            <th className="px-4 py-2">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {sorted.map((c) => (
                            <tr key={c.id} className="border-b border-line last:border-b-0">
                                <td className="px-4 py-2.5">
                                    <a className="inline-flex items-center gap-2 font-medium hover:underline" href={`/admin/components/${c.id}`}>
                                        <Icon name="component" className="size-4 text-site" />
                                        {c.name}
                                    </a>
                                </td>
                                <td className="px-4 py-2.5">
                                    {c.published === null ? (
                                        <StatusPill tone="neutral" icon="pages">
                                            Draft, never published
                                        </StatusPill>
                                    ) : c.changed ? (
                                        <StatusPill tone="changed" icon="clock">
                                            Live v{c.published}, draft has changes
                                        </StatusPill>
                                    ) : (
                                        <StatusPill tone="live" icon="globe">
                                            Live v{c.published}
                                        </StatusPill>
                                    )}
                                </td>
                                <td className="hidden px-4 py-2.5 text-muted tabular-nums sm:table-cell">{c.livePages}</td>
                                <td className="px-4 py-2.5 text-right">
                                    <a href={`/admin/components/${c.id}`} className={buttonClass('secondary', 'sm')}>
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {canEdit && (
                <form
                    className="flex flex-wrap items-end gap-2 border-t border-line bg-raised px-4 py-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void create();
                    }}
                >
                    <label htmlFor={id} className="min-w-0">
                        <span className="ui-label">New component</span>
                        <input
                            id={id}
                            className="ui-input w-64 max-w-full"
                            value={name}
                            maxLength={80}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="e.g. Delivery banner"
                        />
                    </label>
                    <Button type="submit" icon="plus" disabled={name.trim() === ''}>
                        Create
                    </Button>
                    {error && (
                        <p role="alert" className="w-full text-[0.8125rem] text-danger">
                            {error}
                        </p>
                    )}
                </form>
            )}
        </section>
    );
}
