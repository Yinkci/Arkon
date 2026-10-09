import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { AdminLayout } from '@/Components/AdminLayout';
import { api, newRequestKey } from '@/lib/api';
type ProposedPage = { id: string; title: string; path: string; existing: boolean; changes: string[] };
type Proposal = {
    id: string;
    prompt: string;
    status: string;
    summary: string | null;
    error: string | null;
    issues: { path?: string; message: string }[];
    activity: string | null;
    createdAt: string;
    startedAt: string | null;
    heartbeatAt: string | null;
    resolvedAt: string | null;
    candidateSaved: boolean;
    result: {
        includeLayout?: boolean;
        pages: ProposedPage[];
        notes: string[];
        tokenChanges: { token: string; value: string }[];
        menu?: { id: string; definition: { name: string; items: { label: string; type: string; href: string }[] } };
        shared: Record<string, { id: string; changed: boolean }>;
        form: { id: string; definition: { name: string; fields: unknown[] } } | null;
    } | null;
    applied: { pages: { id: string; title: string; path: string; version: number }[] } | null;
};
type Connection = { ready: boolean; message: string };
type Readiness = {
    ready: boolean;
    issues: string[];
    pages: { id: string; title: string; path: string; htmlBytes: number; cssBytes: number; scripts: number; warnings: string[] }[];
    note: string;
};
export default function Website(props: { requests: Proposal[]; connection: Connection; permissions: { edit: boolean; publish: boolean } }) {
    const [requests, setRequests] = useState(props.requests),
        [connection, setConnection] = useState(props.connection),
        [prompt, setPrompt] = useState(''),
        [selected, setSelected] = useState<string | null>(props.requests[0]?.id ?? null),
        [index, setIndex] = useState(0),
        [screen, setScreen] = useState(1100),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [allowRepair, setAllowRepair] = useState(false),
        [includeLayout, setIncludeLayout] = useState(true),
        [pollError, setPollError] = useState(false),
        [now, setNow] = useState(Date.now()),
        [check, setCheck] = useState<Readiness | null>(null);
    const requestAttempt = useRef<{ prompt: string; allowRepair: boolean; includeLayout: boolean; key: string } | null>(null),
        publishAttempt = useRef<{ id: string; key: string } | null>(null);
    const proposal = requests.find((r) => r.id === selected);
    const pending = requests.some((r) => r.status === 'queued' || r.status === 'running');
    const refresh = async () => {
        const r = await api<{ requests: Proposal[]; connection: Connection }>('/website/requests');
        if (r.ok) {
            setPollError(false);
            setRequests(r.data.requests);
            setConnection(r.data.connection);
        } else {
            setPollError(true);
        }
    };
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, []);
    useEffect(() => {
        let active = true;
        const tick = setInterval(
            () => {
                if (active) void refresh().catch(() => setPollError(true));
            },
            pending ? 2500 : 10000,
        );
        return () => {
            active = false;
            clearInterval(tick);
        };
    }, [pending]);
    const request = async () => {
        if (busy || pending || !prompt.trim()) return;
        setBusy(true);
        const a =
            requestAttempt.current?.prompt === prompt &&
            requestAttempt.current.allowRepair === allowRepair &&
            requestAttempt.current.includeLayout === includeLayout
                ? requestAttempt.current
                : { prompt, allowRepair, includeLayout, key: newRequestKey() };
        requestAttempt.current = a;
        try {
            const r = await api<Proposal>('/website/requests', {
                body: { prompt: a.prompt, allowRepair: a.allowRepair, includeLayout: a.includeLayout, requestKey: a.key },
            });
            if (!r.ok) {
                setNotice(r.message);
                return;
            }
            requestAttempt.current = null;
            setSelected(r.data.id);
            setIndex(0);
            setCheck(null);
            setRequests((rs) => [r.data, ...rs.filter((p) => p.id !== r.data.id)]);
            setNotice('Your helper will prepare the whole website. Review the proposal before applying it.');
        } catch {
            setNotice('Request could not be confirmed. Send again to check the same request.');
        } finally {
            setBusy(false);
        }
    };
    const apply = async () => {
        if (!proposal || busy) return;
        setBusy(true);
        try {
            const r = await api(`/website/${proposal.id}/apply`, { body: {} });
            setNotice(r.ok ? 'Website drafts applied. Review the pages, then check publishing readiness.' : r.message);
            if (r.ok) await refresh();
        } catch {
            setNotice('Apply could not be confirmed. Retry Apply; Arkon applies each proposal once.');
        } finally {
            setBusy(false);
        }
    };
    const readiness = async () => {
        if (!proposal) return;
        setBusy(true);
        try {
            const r = await api<Readiness>(`/website/${proposal.id}/readiness`);
            if (r.ok) setCheck(r.data);
            else setNotice(r.message);
        } catch {
            setNotice('Could not check publishing readiness.');
        } finally {
            setBusy(false);
        }
    };
    const publish = async () => {
        if (!proposal || busy || !check?.ready) return;
        if (!confirm('Publish all pages, shared header/footer, branding and form in this website?')) return;
        setBusy(true);
        const a = publishAttempt.current?.id === proposal.id ? publishAttempt.current : { id: proposal.id, key: newRequestKey() };
        publishAttempt.current = a;
        try {
            const r = await api(`/website/${a.id}/publish`, { body: { requestKey: a.key } });
            if (!r.ok) {
                setNotice(r.message);
                return;
            }
            publishAttempt.current = null;
            setNotice('Website published. All pages and shared resources went live together.');
        } catch {
            setNotice('Publishing could not be confirmed. Retry with the same button to check the original result.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <AdminLayout>
            <Head title="Build a website" />
            <div className="mx-auto max-w-[1400px] space-y-6 p-6">
                <AdminPageHeader
                    title="Build a website"
                    description="Describe the site once. Review every page, apply editable drafts, then publish when ready."
                />
                <p className={`text-xs ${connection.ready ? 'text-live' : 'text-muted'}`}>{connection.message}</p>
                {notice && (
                    <p role="status" className="rounded border border-line bg-raised p-3 text-sm">
                        {notice}
                    </p>
                )}
                {props.permissions.edit && (
                    <section className="space-y-3 rounded-xl border border-line bg-raised p-5">
                        <label className="block text-sm font-medium" htmlFor="website-brief">
                            Website brief
                        </label>
                        <textarea
                            id="website-brief"
                            className="w-full rounded-lg border border-line bg-surface p-3 text-sm"
                            rows={4}
                            maxLength={6000}
                            value={prompt}
                            onChange={(e) => setPrompt(e.target.value)}
                            placeholder="Create a professional landscaping website with Home, About, Services and Contact pages. Use a shared header and footer, green branding, and a contact enquiry form. Include only the business facts I provide."
                        />
                        <div className="flex items-center gap-4">
                            <button
                                disabled={busy || pending || !connection.ready || !prompt.trim()}
                                className="rounded-lg bg-accent px-4 py-2 text-sm text-white disabled:opacity-50"
                                onClick={() => void request()}
                            >
                                Prepare website proposal
                            </button>
                            <span className="text-xs text-muted">Uses your Claude Code allowance. Creates no live changes.</span>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={includeLayout} disabled={busy || pending} onChange={(e) => setIncludeLayout(e.target.checked)} />
                            Include shared header, navigation and footer
                        </label>
                        <label className="flex items-center gap-2 text-xs text-muted">
                            <input type="checkbox" checked={allowRepair} disabled={busy || pending} onChange={(e) => setAllowRepair(e.target.checked)} />
                            Allow one automatic repair if validation fails (uses additional Claude allowance).
                        </label>
                    </section>
                )}
                <div className="grid gap-5 lg:grid-cols-[240px_1fr]">
                    <aside className="space-y-2">
                        {requests.map((r) => (
                            <button
                                key={r.id}
                                disabled={busy}
                                className={`w-full rounded-lg border border-line p-3 text-left text-sm ${selected === r.id ? 'bg-sunken' : 'bg-raised'}`}
                                onClick={() => {
                                    setSelected(r.id);
                                    setIndex(0);
                                    setCheck(null);
                                }}
                            >
                                <span className="block line-clamp-2">{r.summary || r.prompt}</span>
                                <span className="mt-2 block text-xs text-muted">{r.status}</span>
                            </button>
                        ))}
                    </aside>
                    <section className="min-w-0 space-y-4">
                        {!proposal ? (
                            <p className="p-6 text-muted">Your website proposals will appear here.</p>
                        ) : (
                            <>
                                <h2 className="text-lg font-semibold">{proposal.summary || 'Website request'}</h2>
                                {pollError && (
                                    <p role="alert" className="text-sm text-amber-700">
                                        Cannot refresh status. The request may still be running. Reconnecting…
                                    </p>
                                )}
                                {proposal.error && (
                                    <div role="alert" className="space-y-2 rounded-lg border border-line p-4 text-sm">
                                        <p className="text-red-600">{proposal.error}</p>
                                        {proposal.issues?.length > 0 && (
                                            <ul className="list-disc pl-5">
                                                {proposal.issues.map((issue, i) => (
                                                    <li key={i}>{issue.message}</li>
                                                ))}
                                            </ul>
                                        )}
                                        {proposal.candidateSaved && (
                                            <p className="text-muted">
                                                The rejected proposal is retained privately for diagnosis. Nothing was applied. No additional model request is
                                                started automatically.
                                            </p>
                                        )}
                                    </div>
                                )}
                                {['queued', 'running'].includes(proposal.status) && (
                                    <div role="status" className="space-y-3 rounded-lg border border-line p-4 text-sm">
                                        <p className="font-medium">
                                            {proposal.status === 'queued'
                                                ? 'Waiting for the local helper…'
                                                : proposal.activity === 'checking'
                                                  ? 'Checking the generated pages against builder rules…'
                                                  : proposal.activity === 'repairing'
                                                    ? 'Claude Code is correcting the validation issues…'
                                                    : 'Claude Code is generating the website…'}
                                        </p>
                                        <progress aria-label="Website preparation progress" className="h-2 w-full" />
                                        <p className="text-muted">
                                            Elapsed: {Math.floor(Math.max(0, now - Date.parse(proposal.startedAt || proposal.createdAt)) / 60000)}m{' '}
                                            {Math.floor(Math.max(0, now - Date.parse(proposal.startedAt || proposal.createdAt)) / 1000) % 60}s ·{' '}
                                            {proposal.status === 'queued'
                                                ? connection.ready
                                                    ? 'Helper connected; waiting to start'
                                                    : 'Helper unavailable'
                                                : proposal.heartbeatAt
                                                  ? now - Date.parse(proposal.heartbeatAt) < 15000
                                                      ? 'Helper responding'
                                                      : 'No recent helper heartbeat; checking connection'
                                                  : 'Waiting for the first helper heartbeat'}
                                        </p>
                                        <p className="text-xs text-muted">
                                            Generation → validation → review. This activity bar is not a completion percentage. A responding helper confirms the
                                            process is running, not that Claude has finished.
                                        </p>
                                    </div>
                                )}
                                {proposal.result?.menu && proposal.result.includeLayout !== false && (
                                    <section className="rounded-lg border border-line bg-raised p-4 text-sm">
                                        <h3 className="font-semibold">Website structure included</h3>
                                        <p>Shared header · {proposal.result.menu.definition.name} · Shared footer</p>
                                        <p className="mt-2 text-muted">Menu: {proposal.result.menu.definition.items.map((i) => i.label).join(' · ')}</p>
                                    </section>
                                )}
                                {proposal.result && (
                                    <>
                                        <div className="flex flex-wrap gap-2">
                                            {proposal.result.pages.map((p, i) => (
                                                <button
                                                    className={`rounded border border-line px-3 py-2 text-sm ${index === i ? 'bg-accent text-white' : 'bg-raised'}`}
                                                    key={p.id}
                                                    onClick={() => setIndex(i)}
                                                >
                                                    {p.title} · {p.path}
                                                </button>
                                            ))}
                                        </div>
                                        <div className="flex gap-2 text-xs">
                                            {[
                                                [1100, 'Desktop'],
                                                [768, 'Tablet'],
                                                [390, 'Mobile'],
                                            ].map(([w, label]) => (
                                                <button
                                                    key={w}
                                                    className={`rounded border border-line px-3 py-1 ${screen === w ? 'bg-sunken' : ''}`}
                                                    onClick={() => setScreen(Number(w))}
                                                >
                                                    {label}
                                                </button>
                                            ))}
                                        </div>
                                        <div className="overflow-auto rounded-xl border border-line bg-sunken p-3">
                                            <iframe
                                                key={`${proposal.id}-${index}`}
                                                title={`Proposed ${proposal.result.pages[index]?.title ?? 'page'}`}
                                                sandbox=""
                                                src={`/admin/website/${proposal.id}/preview/${index}`}
                                                className="mx-auto h-[650px] border-0 bg-white"
                                                style={{ width: screen, maxWidth: 'none' }}
                                            />
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {proposal.result.pages.map((p) => (
                                                <article key={p.id} className="rounded border border-line bg-raised p-4">
                                                    <h3 className="text-sm font-semibold">
                                                        {p.existing ? 'Update' : 'Create'} {p.title}
                                                    </h3>
                                                    <p className="text-xs text-muted">{p.path}</p>
                                                    <ul className="mt-2 list-inside list-disc text-xs text-muted">
                                                        {p.changes.map((c, i) => (
                                                            <li key={i}>{c}</li>
                                                        ))}
                                                    </ul>
                                                </article>
                                            ))}
                                        </div>
                                        <p className="text-sm">
                                            Shared header and footer remain editable in{' '}
                                            <Link className="text-accent" href="/admin/design/components">
                                                Reusable components
                                            </Link>
                                            .{' '}
                                            {proposal.result.form && (
                                                <>
                                                    Contact form: {proposal.result.form.definition.name} ({proposal.result.form.definition.fields.length}{' '}
                                                    fields).
                                                </>
                                            )}
                                        </p>
                                        {proposal.result.tokenChanges.length > 0 && (
                                            <ul className="text-xs text-muted">
                                                {proposal.result.tokenChanges.map((c) => (
                                                    <li key={c.token}>
                                                        {c.token}: {c.value}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                        {proposal.result.notes.length > 0 && (
                                            <ul className="list-inside list-disc text-sm text-muted">
                                                {proposal.result.notes.map((n, i) => (
                                                    <li key={i}>{n}</li>
                                                ))}
                                            </ul>
                                        )}
                                        {proposal.status === 'proposed' && props.permissions.edit && (
                                            <button disabled={busy} className="rounded bg-accent px-4 py-2 text-sm text-white" onClick={() => void apply()}>
                                                Apply all website drafts
                                            </button>
                                        )}
                                        {proposal.applied && (
                                            <>
                                                <div className="flex flex-wrap gap-3">
                                                    {proposal.applied.pages.map((p) => (
                                                        <Link className="text-sm text-accent" href={`/admin/editor/${p.id}`} key={p.id}>
                                                            Edit {p.title}
                                                        </Link>
                                                    ))}
                                                </div>
                                                <button
                                                    disabled={busy}
                                                    className="rounded border border-line px-4 py-2 text-sm"
                                                    onClick={() => void readiness()}
                                                >
                                                    Check publishing readiness
                                                </button>
                                            </>
                                        )}
                                        {check && (
                                            <div className="space-y-3 rounded border border-line bg-raised p-4">
                                                <h3 className="font-semibold">{check.ready ? 'Ready to publish' : 'Needs attention'}</h3>
                                                {check.issues.map((s, i) => (
                                                    <p key={i} className="text-sm text-red-600">
                                                        {s}
                                                    </p>
                                                ))}
                                                {check.pages.map((p) => (
                                                    <div key={p.id} className="text-sm">
                                                        <strong>{p.title}</strong> · {(p.htmlBytes / 1024).toFixed(1)} KB HTML ·{' '}
                                                        {(p.cssBytes / 1024).toFixed(1)} KB CSS · {p.scripts} scripts
                                                        {p.warnings.map((w, i) => (
                                                            <p key={i} className="text-xs text-muted">
                                                                {w}
                                                            </p>
                                                        ))}
                                                    </div>
                                                ))}
                                                <p className="text-xs text-muted">{check.note}</p>
                                                {check.ready && props.permissions.publish && (
                                                    <button
                                                        disabled={busy}
                                                        className="rounded bg-accent px-4 py-2 text-sm text-white"
                                                        onClick={() => void publish()}
                                                    >
                                                        Publish website
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </>
                                )}
                                {props.permissions.edit && ['queued', 'running', 'proposed', 'empty'].includes(proposal.status) && (
                                    <button
                                        disabled={busy}
                                        className="text-sm text-muted"
                                        onClick={async () => {
                                            setBusy(true);
                                            try {
                                                const r = await api(`/website/${proposal.id}/discard`, { body: {} });
                                                if (r.ok) await refresh();
                                                else setNotice(r.message);
                                            } catch {
                                                setNotice('Could not confirm cancellation.');
                                            } finally {
                                                setBusy(false);
                                            }
                                        }}
                                    >
                                        {['queued', 'running'].includes(proposal.status) ? 'Cancel request' : 'Discard proposal'}
                                    </button>
                                )}
                            </>
                        )}
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}
