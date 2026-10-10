import { Head } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Icon } from '@/Components/Icon';
import { Button, IconButton, Notice, PageShell, Panel, Spinner, StatusPill, buttonClass } from '@/Components/ui';
import { api } from '@/lib/api';
import { fullDate, relativeTime } from '@/lib/time';

type Provider = {
    id: string;
    name: string;
    description: string;
    install: string;
    login: string;
    ready: boolean;
    state: string;
    message: string;
    version: string | null;
    code?: string | null;
    lastSeenAt?: string | null;
    connectionId: string | null;
    checking: boolean;
    testPending: boolean;
    testResult: { ok: boolean; message: string } | null;
};
type State = { providers: Provider[]; email: string };
type Action = 'check' | 'test' | 'disconnect';
type Feedback = { tone: 'success' | 'error' | 'info'; message: string };

function statusLabel(p: Provider) {
    if (p.checking && p.state !== 'disconnected') return 'Checking connection…';
    if (p.ready) return 'Connected';
    if (p.state === 'not_installed') return 'CLI not detected';
    if (p.state === 'not_authenticated') return 'Needs sign-in';
    if (p.state === 'connection_error') return 'Needs attention';
    return p.lastSeenAt || p.connectionId ? 'Helper offline' : 'Not connected';
}
function ProviderIcon({ id }: { id: string }) {
    // Neutral coding marks, not imitations of provider trademarks.
    return (
        <span className="grid size-12 shrink-0 place-items-center rounded-lg border border-line bg-raised text-fg" aria-hidden="true">
            <svg viewBox="0 0 24 24" className="size-6" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
                {id === 'claude-code' ? <path d="m6 7 5 5-5 5M13 17h5" /> : <path d="m8 6-5 6 5 6m8-12 5 6-5 6m-3-13-2 14" />}
            </svg>
        </span>
    );
}
function Status({ provider: p, uncertain = false }: { provider: Provider; uncertain?: boolean }) {
    return (
        <span
            className={`inline-flex items-center gap-2 text-sm font-medium ${uncertain ? 'text-muted' : p.ready ? 'text-live' : p.state === 'disconnected' ? 'text-muted' : 'text-changed'}`}
        >
            {p.checking && p.state !== 'disconnected' ? (
                <Spinner className="size-3" />
            ) : (
                <span aria-hidden="true" className="size-1.5 shrink-0 rounded-full bg-current" />
            )}
            {uncertain ? 'Last known: ' : ''}
            {statusLabel(p)}
        </span>
    );
}
function Command({ label, command }: { label: string; command: string }) {
    const [result, setResult] = useState('');
    const input = useRef<HTMLTextAreaElement>(null);
    useEffect(() => {
        if (!result) return;
        const timer = setTimeout(() => setResult(''), 2500);
        return () => clearTimeout(timer);
    }, [result]);
    const copy = async () => {
        try {
            try {
                if (!navigator.clipboard) throw new Error();
                await navigator.clipboard.writeText(command);
            } catch {
                const previous = document.activeElement as HTMLElement | null;
                input.current?.focus();
                input.current?.select();
                const copied = document.execCommand('copy');
                previous?.focus();
                if (!copied) throw new Error();
            }
            setResult('Copied');
        } catch {
            setResult('Select the command to copy it.');
        }
    };
    return (
        <div className="min-w-0">
            <div className="flex items-center justify-between gap-3 pb-2">
                <p className="t-label">{label}</p>
                <Button
                    size="sm"
                    variant="ghost"
                    icon={result === 'Copied' ? 'check' : 'copy'}
                    aria-label={`Copy ${label.toLowerCase()}`}
                    onClick={() => void copy()}
                >
                    {result === 'Copied' ? 'Copied' : 'Copy'}
                </Button>
            </div>
            <pre tabIndex={0} aria-label={label} className="ak-scroll overflow-x-auto rounded-md border border-line bg-raised p-3 text-xs leading-relaxed">
                <code>{command}</code>
            </pre>
            <textarea
                ref={input}
                value={command}
                readOnly
                tabIndex={-1}
                aria-hidden="true"
                className="pointer-events-none fixed top-0 left-0 size-px opacity-0"
            />
            <span role="status" className="sr-only">
                {result}
            </span>
        </div>
    );
}

export default function AiConnections(props: State) {
    const [state, setState] = useState(props);
    const [stale, setStale] = useState(false);
    const current = useRef(props),
        sequence = useRef(0),
        pending = useRef(false),
        refreshing = useRef(false);
    const [busy, setBusy] = useState<string | null>(null),
        [loading, setLoading] = useState(false);
    const [feedback, setFeedback] = useState<Feedback | null>(null);
    const [opened, setOpened] = useState<{ id: string; mode: 'setup' | 'manage' } | null>(null);
    const [step, setStep] = useState(0),
        [disconnecting, setDisconnecting] = useState<Provider | null>(null);
    const dialog = useRef<HTMLDialogElement>(null),
        titleId = useId();
    const selected = state.providers.find((p) => p.id === opened?.id);
    const accept = (next: State) => {
        const newlyReady = next.providers.find((p) => p.ready && !current.current.providers.find((old) => old.id === p.id)?.ready);
        setStale(false);
        current.current = next;
        setState(next);
        if (newlyReady) setFeedback({ tone: 'success', message: `${newlyReady.name} connected successfully.` });
    };
    const refresh = async (manual = false) => {
        if (refreshing.current || pending.current) return;
        refreshing.current = true;
        if (manual) setLoading(true);
        const ticket = ++sequence.current;
        try {
            const r = await api<State>('/ai-connections');
            if (r.ok && ticket === sequence.current) accept(r.data);
            else if (!r.ok && ticket === sequence.current) {
                setStale(true);
                if (manual) setFeedback({ tone: 'error', message: r.message });
            }
        } catch {
            if (ticket === sequence.current) setStale(true);
            if (manual) setFeedback({ tone: 'error', message: 'Status could not be refreshed. Try again.' });
        } finally {
            refreshing.current = false;
            if (manual) setLoading(false);
        }
    };
    useEffect(() => {
        const timer = setInterval(() => {
            if (!document.hidden) void refresh();
        }, 3000);
        return () => clearInterval(timer);
    }, []);
    useEffect(() => {
        const d = dialog.current;
        if (!d) return;
        if (opened && !d.open) d.showModal();
        if (!opened && d.open) d.close();
    }, [opened]);
    const open = (p: Provider, mode: 'setup' | 'manage') => {
        setStep(p.connectionId ? (p.state === 'not_authenticated' ? 1 : 3) : 0);
        setOpened({ id: p.id, mode });
    };
    const action = async (p: Provider, name: Action): Promise<string | null> => {
        if (pending.current) return 'Wait for the current action to finish.';
        pending.current = true;
        setBusy(p.id);
        const ticket = ++sequence.current;
        try {
            const r = await api<State>(`/ai-connections/${p.connectionId}/${name}`, {
                body: {},
            });
            if (!r.ok) {
                setFeedback({ tone: 'error', message: r.message });
                return r.message;
            }
            if (ticket === sequence.current) accept(r.data);
            setFeedback({
                tone: name === 'disconnect' ? 'success' : 'info',
                message:
                    name === 'disconnect'
                        ? `${p.name} disconnected from Arkon. Your provider login is unchanged.`
                        : name === 'test'
                          ? 'Connection test queued. This uses a small amount of your provider allowance.'
                          : 'Status check queued. Keep your helper running.',
            });
            if (name === 'disconnect') {
                setOpened({ id: p.id, mode: 'setup' });
                setStep(0);
            }
            return null;
        } catch {
            const message = 'The outcome could not be confirmed. Refresh status before trying again.';
            setFeedback({ tone: 'error', message });
            return message;
        } finally {
            pending.current = false;
            setBusy(null);
        }
    };
    const check = (p: Provider) => (p.connectionId ? void action(p, 'check') : void refresh(true));
    const connected = state.providers.filter((p) => p.ready).length;
    const priority = state.providers.find((p) => !p.ready)?.id;
    const pairing = selected ? `php artisan arkon:ai-pair ${state.email} --helper --provider=${selected.id}` : '';
    const helper = selected ? `php artisan arkon:ai-helper --provider=${selected.id}` : '';
    const completed = selected
        ? [
              !!selected.version && selected.state !== 'not_installed' && selected.state !== 'disconnected',
              selected.ready,
              !!selected.connectionId,
              selected.ready,
          ]
        : [];
    const failedStep = selected?.state === 'not_installed' ? 0 : selected?.state === 'not_authenticated' ? 1 : selected?.state === 'connection_error' ? 3 : -1;
    const steps = ['Install', 'Sign in', 'Pair', 'Verify'];
    return (
        <AdminLayout>
            <Head title="AI Connections" />
            <PageShell className="space-y-8">
                <AdminPageHeader
                    title="AI Connections"
                    description="Connect the AI tools you already use and choose which provider powers Arkon."
                    actions={
                        <Button icon="reset" busy={loading} disabled={loading || busy !== null} onClick={() => void refresh(true)}>
                            {loading ? 'Refreshing…' : 'Refresh status'}
                        </Button>
                    }
                />
                {feedback && (
                    <Notice tone={feedback.tone} onDismiss={() => setFeedback(null)}>
                        {feedback.message}
                    </Notice>
                )}
                <div className="space-y-3">
                    <div className="flex items-center justify-between gap-4">
                        <h2 className="t-eyebrow text-muted">AI providers</h2>
                        <p className="t-meta">
                            {stale ? 'Last known · ' : ''}
                            {connected} of {state.providers.length} connected
                        </p>
                    </div>
                    <Panel padded={false} className="divide-y divide-line">
                        {state.providers.map((p) => (
                            <section key={p.id} data-testid={`provider-${p.id}`} aria-labelledby={`provider-title-${p.id}`} className="p-5 sm:p-6">
                                <div className="flex flex-wrap items-center justify-between gap-5">
                                    <div className="flex min-w-0 items-start gap-4">
                                        <ProviderIcon id={p.id} />
                                        <div className="min-w-0 space-y-2">
                                            <div className="flex flex-wrap items-center gap-3">
                                                <h3 id={`provider-title-${p.id}`} className="t-section">
                                                    {p.name}
                                                </h3>
                                            </div>
                                            <p className="t-meta">
                                                {p.id === 'codex' ? 'OpenAI coding agent · ChatGPT account' : 'Local coding assistant · Claude subscription'}
                                            </p>
                                            <Status provider={p} uncertain={stale} />
                                        </div>
                                    </div>
                                    <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                                        <Button
                                            variant={priority === p.id ? 'primary' : 'secondary'}
                                            icon={p.ready ? 'sliders' : 'plus'}
                                            onClick={() => open(p, p.ready ? 'manage' : 'setup')}
                                        >
                                            {p.ready ? 'Manage' : p.connectionId ? 'Continue setup' : 'Connect'}
                                        </Button>
                                    </div>
                                </div>
                            </section>
                        ))}
                    </Panel>
                    <p className="flex items-center gap-2 t-meta">
                        <Icon name="lock" className="size-4 text-faint" />
                        Credentials stay with your local AI provider.
                    </p>
                </div>
                <details className="group border-t border-line pt-5">
                    <summary className="flex cursor-pointer list-none items-center gap-2 t-label text-muted [&::-webkit-details-marker]:hidden">
                        <Icon name="chevronRight" className="size-4 transition-transform group-open:rotate-90" />
                        How AI connections work
                    </summary>
                    <ul className="mt-4 space-y-2 pl-6 t-meta">
                        <li>Helpers run on your computer and report installation, sign-in and connection health.</li>
                        <li>One ready AI is used automatically. When several are ready, choose one for each new task.</li>
                        <li>Arkon reviews and validates results. It never switches providers or publishes automatically.</li>
                    </ul>
                </details>
            </PageShell>
            <dialog
                ref={dialog}
                onKeyDown={(event) => {
                    if (event.key !== 'Tab') return;
                    const controls = Array.from(
                        event.currentTarget.querySelectorAll<HTMLElement>('button, a[href], input, select, textarea, summary, [tabindex]'),
                    ).filter((element) => element.tabIndex >= 0 && !element.matches(':disabled, [aria-hidden="true"]') && element.getClientRects().length > 0);
                    const first = controls[0],
                        last = controls.at(-1);
                    if (!first || !last) {
                        event.preventDefault();
                        return;
                    }
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    } else if (!event.currentTarget.contains(document.activeElement)) {
                        event.preventDefault();
                        first.focus();
                    }
                }}
                aria-labelledby={titleId}
                onCancel={(e) => {
                    e.preventDefault();
                    setOpened(null);
                }}
                className="fixed inset-y-0 right-0 left-auto m-0 h-dvh max-h-none w-full max-w-xl border-0 border-l border-line bg-surface p-0 text-fg shadow-pop backdrop:bg-scrim"
            >
                {selected && (
                    <div className="flex h-full min-h-0 flex-col">
                        <header className="flex shrink-0 items-center justify-between gap-4 border-b border-line p-5 sm:p-6">
                            <div className="flex items-center gap-3">
                                <ProviderIcon id={selected.id} />
                                <div>
                                    <h2 id={titleId} className="t-section">
                                        {opened?.mode === 'setup' ? 'Connect' : 'Manage'} {selected.name}
                                    </h2>
                                    <div className="mt-2">
                                        <Status provider={selected} uncertain={stale} />
                                    </div>
                                </div>
                            </div>
                            <IconButton icon="close" label="Close provider details" onClick={() => setOpened(null)} />
                        </header>
                        <div className="ak-scroll min-h-0 flex-1 space-y-6 overflow-y-auto p-5 sm:p-6">
                            {feedback && (
                                <Notice tone={feedback.tone} onDismiss={() => setFeedback(null)}>
                                    {feedback.message}
                                </Notice>
                            )}
                            {opened?.mode === 'setup' ? (
                                <>
                                    <nav aria-label="Connection setup steps" className="grid grid-cols-4 gap-2">
                                        {steps.map((name, index) => (
                                            <button
                                                key={name}
                                                onClick={() => setStep(index)}
                                                aria-current={step === index ? 'step' : undefined}
                                                className={`flex flex-col items-center gap-2 rounded-md px-1 py-3 text-xs font-medium transition-colors ${step === index ? 'bg-accent-soft text-accent' : 'text-muted hover:bg-hover'}`}
                                            >
                                                <span
                                                    className={`grid size-7 place-items-center rounded-full border ${index === failedStep ? 'border-changed/30 bg-changed-soft text-changed' : completed[index] ? 'border-live/30 bg-live-soft text-live' : step === index ? 'border-accent-line' : 'border-line'}`}
                                                >
                                                    {index === failedStep ? (
                                                        <Icon name="alert" className="size-4" />
                                                    ) : completed[index] ? (
                                                        <Icon name="check" className="size-4" />
                                                    ) : (
                                                        index + 1
                                                    )}
                                                </span>
                                                {name}
                                                <span className="sr-only">
                                                    {index === failedStep ? 'Needs attention' : completed[index] ? 'Confirmed' : 'Not yet confirmed'}
                                                </span>
                                            </button>
                                        ))}
                                    </nav>
                                    <section className="space-y-5" aria-labelledby="setup-step-title">
                                        <div>
                                            <p className="t-eyebrow text-muted">Step {step + 1} of 4</p>
                                            <h3 id="setup-step-title" className="mt-2 t-section">
                                                {
                                                    ['Install ' + selected.name, 'Sign in to your account', 'Pair with Arkon', 'Start your helper and verify'][
                                                        step
                                                    ]
                                                }
                                            </h3>
                                        </div>
                                        {step === 0 && (
                                            <>
                                                <p className="t-body text-muted">Install the CLI on the computer where you’ll run your Arkon helper.</p>
                                                <a href={selected.install} target="_blank" rel="noreferrer" className={buttonClass('secondary')}>
                                                    <Icon name="external" className="size-4" />
                                                    Open installation guide
                                                </a>
                                                <p className="ui-hint">Arkon confirms installation after your paired helper reports back.</p>
                                            </>
                                        )}
                                        {step === 1 && (
                                            <>
                                                <p className="t-body text-muted">Run this in your terminal and complete the provider’s sign-in flow.</p>
                                                <Command label="Sign-in command" command={selected.login} />
                                                <p className="ui-hint">
                                                    Use {selected.id === 'codex' ? 'your ChatGPT account' : 'your Claude subscription'}. No API key is needed.
                                                </p>
                                            </>
                                        )}
                                        {step === 2 && (
                                            <>
                                                <p className="t-body text-muted">Run this from your Arkon project folder to link your account.</p>
                                                <Command label="Pairing command" command={pairing} />
                                                <p className="ui-hint">Pairing creates an Arkon connection; it does not copy provider credentials.</p>
                                            </>
                                        )}
                                        {step === 3 && (
                                            <>
                                                <p className="t-body text-muted">Start the helper in your project folder and leave that terminal running.</p>
                                                <Command label="Helper command" command={helper} />
                                                {selected.ready ? (
                                                    <Notice tone="success" title="Connection successful">
                                                        {selected.name} is ready to prepare proposals.
                                                    </Notice>
                                                ) : (
                                                    <Notice tone={selected.state === 'connection_error' ? 'error' : 'warning'} title={statusLabel(selected)}>
                                                        {selected.state === 'not_authenticated'
                                                            ? 'Complete sign-in, then check again.'
                                                            : selected.state === 'not_installed'
                                                              ? 'Install the CLI, then restart your helper.'
                                                              : 'Start the paired helper to confirm installation and sign-in.'}
                                                    </Notice>
                                                )}
                                                <Button
                                                    icon="reset"
                                                    busy={(selected.checking && selected.state !== 'disconnected') || loading}
                                                    disabled={busy !== null || loading || (selected.checking && selected.state !== 'disconnected')}
                                                    onClick={() => check(selected)}
                                                >
                                                    Check connection
                                                </Button>
                                                <p className="ui-hint">Checking status uses no model allowance.</p>
                                            </>
                                        )}
                                        <div className="flex justify-between gap-3 border-t border-line pt-5">
                                            <Button variant="ghost" disabled={step === 0} icon="chevronLeft" onClick={() => setStep(step - 1)}>
                                                Back
                                            </Button>
                                            {step < 3 ? (
                                                <Button variant="primary" onClick={() => setStep(step + 1)}>
                                                    Continue
                                                    <Icon name="chevronRight" className="size-4" />
                                                </Button>
                                            ) : selected.ready ? (
                                                <Button variant="primary" onClick={() => setOpened({ id: selected.id, mode: 'manage' })}>
                                                    Manage connection
                                                </Button>
                                            ) : (
                                                <Button onClick={() => setOpened(null)}>Done for now</Button>
                                            )}
                                        </div>
                                    </section>
                                </>
                            ) : (
                                <>
                                    <section className="space-y-4">
                                        <div className="flex items-center justify-between gap-3">
                                            <h3 className="t-title">Connection health</h3>
                                            <Button
                                                size="sm"
                                                icon="reset"
                                                busy={selected.checking}
                                                disabled={busy !== null || selected.checking || !selected.connectionId}
                                                onClick={() => check(selected)}
                                            >
                                                {selected.checking ? 'Checking…' : 'Check again'}
                                            </Button>
                                        </div>
                                        <dl className="divide-y divide-line text-sm">
                                            {[
                                                [
                                                    'Installation',
                                                    selected.version
                                                        ? `${selected.state === 'disconnected' ? 'Last reported' : 'Detected'} · v${selected.version}`
                                                        : selected.state === 'not_installed'
                                                          ? 'Not detected'
                                                          : 'Not yet verified',
                                                ],
                                                [
                                                    'Authentication',
                                                    selected.ready
                                                        ? 'Ready'
                                                        : selected.state === 'not_authenticated'
                                                          ? 'Sign-in needed'
                                                          : 'Not currently verified',
                                                ],
                                                ['Helper', selected.ready ? 'Online' : selected.lastSeenAt ? 'Offline' : 'Waiting for connection'],
                                                ['Last seen', selected.lastSeenAt ? relativeTime(selected.lastSeenAt) : 'No report yet'],
                                            ].map(([label, value]) => (
                                                <div key={label} className="flex justify-between gap-4 py-3">
                                                    <dt className="text-muted">{label}</dt>
                                                    <dd
                                                        className="text-right font-medium"
                                                        title={label === 'Last seen' && selected.lastSeenAt ? fullDate(selected.lastSeenAt) : undefined}
                                                    >
                                                        {value}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    </section>
                                    {!selected.ready && (
                                        <Notice
                                            tone="warning"
                                            title={statusLabel(selected)}
                                            action={
                                                <Button size="sm" onClick={() => open(selected, 'setup')}>
                                                    Restart instructions
                                                </Button>
                                            }
                                        >
                                            Your helper needs attention before it can run new tasks.
                                        </Notice>
                                    )}
                                    <section className="space-y-3 border-t border-line pt-5">
                                        <h3 className="t-title">Test connection</h3>
                                        <p className="t-meta">Send one small request to confirm the provider responds.</p>
                                        <Button
                                            icon="bolt"
                                            disabled={busy !== null || !selected.ready || !selected.connectionId || selected.testPending}
                                            busy={selected.testPending}
                                            onClick={() => void action(selected, 'test')}
                                        >
                                            {selected.testPending ? 'Testing connection…' : 'Test connection'}
                                        </Button>
                                        <p className="ui-hint">Uses a small amount of your provider allowance.</p>
                                        {selected.testResult && (
                                            <Notice
                                                tone={selected.testResult.ok ? 'success' : 'error'}
                                                title={selected.testResult.ok ? 'Connection successful' : 'Connection test failed'}
                                            >
                                                {selected.testResult.message}
                                            </Notice>
                                        )}
                                    </section>
                                    {!selected.connectionId && (
                                        <p className="ui-hint">
                                            This site uses another member’s helper. Pair your own helper to manage or test your connection.
                                        </p>
                                    )}
                                    <details className="group border-t border-line pt-5">
                                        <summary className="flex cursor-pointer list-none items-center gap-2 t-label [&::-webkit-details-marker]:hidden">
                                            <Icon name="chevronRight" className="size-4 text-muted group-open:rotate-90" />
                                            Advanced & diagnostics
                                        </summary>
                                        <div className="mt-4 space-y-4">
                                            <dl className="grid grid-cols-2 gap-3 text-xs">
                                                <dt className="text-muted">Provider ID</dt>
                                                <dd>{selected.id}</dd>
                                                <dt className="text-muted">Status code</dt>
                                                <dd className="break-words">{selected.code ?? 'None'}</dd>
                                            </dl>
                                            <p className="t-meta break-words">{selected.message}</p>
                                            <Button size="sm" onClick={() => open(selected, 'setup')}>
                                                Setup & reconnect
                                            </Button>
                                            <Command label="Restart helper command" command={helper} />
                                            <p className="ui-hint">
                                                Executable paths are configured locally. Arkon does not accept terminal commands or provider credentials in this
                                                screen.
                                            </p>
                                        </div>
                                    </details>
                                    {selected.connectionId && (
                                        <section className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-5">
                                            <div>
                                                <h3 className="t-title">Disconnect from Arkon</h3>
                                                <p className="mt-1 t-meta">Stops this helper’s work. Your provider stays signed in.</p>
                                            </div>
                                            <Button variant="quiet-danger" icon="logout" disabled={busy !== null} onClick={() => setDisconnecting(selected)}>
                                                Disconnect
                                            </Button>
                                        </section>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                )}
            </dialog>
            <ConfirmDialog
                open={disconnecting !== null}
                title={`Disconnect ${disconnecting?.name ?? 'provider'}?`}
                confirmLabel="Disconnect"
                busyLabel="Disconnecting…"
                tone="danger"
                onClose={() => setDisconnecting(null)}
                onConfirm={() => (disconnecting ? action(disconnecting, 'disconnect') : Promise.resolve(null))}
            >
                Running requests from this helper will stop. Your provider login stays signed in.
            </ConfirmDialog>
        </AdminLayout>
    );
}
