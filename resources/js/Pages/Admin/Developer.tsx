import { Head } from '@inertiajs/react';
import { useId, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Icon } from '@/Components/Icon';
import { ListEmpty } from '@/Components/ListManagement';
import { toast } from '@/Components/Toast';
import { Button, Notice } from '@/Components/ui';
import { api } from '@/lib/api';
import { reloadProps } from '@/lib/mutate';
import { fullDate, relativeTime } from '@/lib/time';

interface Token {
    id: string;
    name: string;
    hint: string;
    scopes: string[];
    createdAt: string;
    lastUsedAt: string | null;
    expiresAt: string | null;
    revokedAt: string | null;
    state: 'active' | 'expired' | 'revoked';
}

const EXPIRY: { label: string; days: number | null }[] = [
    { label: '30 days', days: 30 },
    { label: '90 days', days: 90 },
    { label: '1 year', days: 365 },
    { label: 'No expiry', days: null },
];

/**
 * The public API for developers: where it is, how to authenticate, and the member's own personal
 * access tokens. A token acts as you on this site, limited to its scopes, and never does more
 * than your role allows. It is shown once.
 */
export default function Developer({ tokens, scopes, baseUrl }: { tokens: Token[]; scopes: Record<string, string>; baseUrl: string }) {
    const [created, setCreated] = useState<{ token: string; name: string } | null>(null);
    const [revoking, setRevoking] = useState<Token | null>(null);
    const active = tokens.filter((t) => t.state === 'active');

    return (
        <AdminLayout>
            <Head title="Developer API" />
            <div className="ak-page space-y-8">
                <AdminPageHeader
                    title="Developer API"
                    description="Build a headless site, an app or an integration on this site's content. Anonymous requests read what is published; a token adds what you allow it."
                />

                <section className="space-y-3 border-t border-line pt-5" aria-labelledby="api-base">
                    <h2 id="api-base" className="t-section">
                        Endpoint
                    </h2>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-muted">Base URL</dt>
                            <dd className="mt-1 font-mono text-sm break-all" data-testid="api-base-url">
                                {baseUrl}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted">Reference</dt>
                            <dd className="mt-1 text-sm">
                                <a className="text-accent hover:underline" href={`${baseUrl}/openapi.json`} target="_blank" rel="noreferrer">
                                    OpenAPI document
                                </a>
                                <span className="text-muted"> · guide in docs/api.md</span>
                            </dd>
                        </div>
                    </dl>
                    <pre className="overflow-x-auto rounded-md bg-sunken p-3 font-mono text-xs leading-relaxed">
                        {`curl ${baseUrl}/posts\ncurl -H "Authorization: Bearer <token>" "${baseUrl}/posts?status=draft"`}
                    </pre>
                </section>

                <section className="space-y-4 border-t border-line pt-5" aria-labelledby="api-tokens">
                    <h2 id="api-tokens" className="t-section">
                        Your access tokens
                    </h2>
                    {created && (
                        <Notice tone="success" title={`“${created.name}” created. Copy the token now: it is not shown again.`}>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <code className="rounded bg-surface px-2 py-1 font-mono text-xs break-all" data-testid="new-token">
                                    {created.token}
                                </code>
                                <Button
                                    size="sm"
                                    icon="copy"
                                    onClick={() =>
                                        void navigator.clipboard?.writeText(created.token).then(
                                            () => toast('Token copied.'),
                                            () => toast('Copy it by selecting the text.', 'error'),
                                        )
                                    }
                                >
                                    Copy
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setCreated(null)}>
                                    Done
                                </Button>
                            </div>
                        </Notice>
                    )}
                    <NewTokenForm scopes={scopes} onCreated={setCreated} />
                    {tokens.length === 0 ? (
                        <ListEmpty icon="key" title="No tokens yet">
                            Create one for each place that uses the API, so you can revoke it on its own.
                        </ListEmpty>
                    ) : (
                        <ul className="divide-y divide-line rounded-lg border border-line bg-surface shadow-hairline" aria-label="Access tokens">
                            {tokens.map((token) => (
                                <li key={token.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3" data-testid="token-row">
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {token.name} <span className="font-mono text-xs text-muted">{token.hint}</span>
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted">{token.scopes.join(' · ')}</p>
                                        <p className="mt-0.5 text-xs text-muted">
                                            Created <time title={fullDate(token.createdAt)}>{relativeTime(token.createdAt)}</time> ·{' '}
                                            {token.lastUsedAt ? <>last used {relativeTime(token.lastUsedAt)}</> : 'never used'}
                                            {token.expiresAt && (
                                                <>
                                                    {' '}
                                                    · {token.state === 'expired' ? 'expired' : 'expires'} {fullDate(token.expiresAt)}
                                                </>
                                            )}
                                        </p>
                                    </div>
                                    {token.state === 'active' ? (
                                        <Button size="sm" variant="quiet-danger" onClick={() => setRevoking(token)}>
                                            Revoke
                                        </Button>
                                    ) : (
                                        <span className="text-xs text-muted">{token.state === 'revoked' ? 'Revoked' : 'Expired'}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {active.length > 0 && (
                        <p className="text-xs text-muted">Revoke a token you no longer use, or one that may have leaked: it stops working at once.</p>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={revoking !== null}
                tone="danger"
                title={revoking ? `Revoke “${revoking.name}”?` : ''}
                confirmLabel="Revoke"
                busyLabel="Revoking…"
                onClose={() => setRevoking(null)}
                onConfirm={async () => {
                    if (!revoking) return null;
                    const result = await api(`/tokens/${revoking.id}/revoke`, { body: {} });
                    if (!result.ok) return result.message;
                    await reloadProps(['tokens']);
                    toast('Token revoked.');
                    return null;
                }}
            >
                <p>Anything using it gets “invalid token” from now on. This cannot be undone; create a new token if you need one.</p>
            </ConfirmDialog>
        </AdminLayout>
    );
}

function NewTokenForm({ scopes, onCreated }: { scopes: Record<string, string>; onCreated(created: { token: string; name: string }): void }) {
    const [name, setName] = useState('');
    const [chosen, setChosen] = useState<string[]>([]);
    const [days, setDays] = useState<number | null>(90);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const ids = { name: useId(), expiry: useId() };

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setPending(true);
        setError(null);
        const expiresAt = days === null ? null : new Date(Date.now() + days * 86_400_000).toISOString();
        const result = await api<{ token: string; item: Token }>('/tokens', { body: { name, scopes: chosen, expiresAt } }).catch(() => null);
        setPending(false);
        if (!result) return setError('The outcome could not be confirmed (network problem). Reload the page to see whether the token exists.');
        if (!result.ok) return setError(result.issues?.length ? result.issues.map((i) => i.message).join(' ') : result.message);
        onCreated({ token: result.data.token, name: result.data.item.name });
        setName('');
        setChosen([]);
        await reloadProps(['tokens']);
    }

    return (
        <form onSubmit={submit} className="rounded-lg border border-line bg-surface p-4 shadow-hairline" aria-label="New access token">
            <h3 className="mb-3 t-title">New access token</h3>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor={ids.name} className="ui-label">
                        Name
                    </label>
                    <input
                        id={ids.name}
                        required
                        maxLength={100}
                        className="ui-input h-9 text-sm"
                        placeholder="Blog frontend"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                    />
                </div>
                <div>
                    <label htmlFor={ids.expiry} className="ui-label">
                        Expires after
                    </label>
                    <select
                        id={ids.expiry}
                        className="ui-input h-9 text-sm"
                        value={days === null ? 'never' : String(days)}
                        onChange={(e) => setDays(e.target.value === 'never' ? null : Number(e.target.value))}
                    >
                        {EXPIRY.map((option) => (
                            <option key={option.label} value={option.days === null ? 'never' : String(option.days)}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <fieldset className="mt-4">
                <legend className="ui-label">Scopes</legend>
                <p className="mb-2 text-xs text-muted">Choose only what it needs. Reading published content needs no token at all.</p>
                <div className="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
                    {Object.entries(scopes).map(([scope, description]) => (
                        <label key={scope} className="flex items-start gap-2 text-ui">
                            <input
                                type="checkbox"
                                className="mt-0.5 size-4 accent-[var(--ak-accent)]"
                                checked={chosen.includes(scope)}
                                onChange={() => setChosen((list) => (list.includes(scope) ? list.filter((s) => s !== scope) : [...list, scope]))}
                            />
                            <span>
                                <span className="font-mono text-xs">{scope}</span>
                                <span className="block text-xs text-muted">{description}</span>
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>
            <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
                {error && (
                    <p role="alert" className="mr-auto flex items-start gap-1.5 text-ui text-danger">
                        <Icon name="alert" className="mt-0.5 size-4" />
                        {error}
                    </p>
                )}
                <Button type="submit" variant="primary" icon="key" busy={pending} disabled={pending || !name.trim() || chosen.length === 0}>
                    {pending ? 'Creating…' : 'Create token'}
                </Button>
            </div>
        </form>
    );
}
