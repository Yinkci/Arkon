import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { Button, ButtonLink, EmptyState, Notice } from '@/Components/ui';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { api, newRequestKey } from '@/lib/api';
import { FormBuilder } from '@/forms/FormBuilder';
import { FormEntries } from '@/forms/FormEntries';
import { ConditionEditor } from '@/forms/ConditionEditor';
import { id, formLimits, type Definition, type FormRow, type Notification, type Permissions } from '@/forms/schema';

export default function FormEditor({
    form,
    permissions,
    initialSection,
    sender,
    mailConfigured,
}: {
    form: FormRow;
    permissions: Permissions;
    initialSection: string;
    sender: string | null;
    mailConfigured: boolean;
}) {
    const [draft, setDraft] = useState<Definition>(structuredClone(form.definition)),
        [version, setVersion] = useState(form.version),
        [published, setPublished] = useState(form.publishedVersion),
        [section, setSection] = useState(initialSection),
        [busy, setBusy] = useState(false),
        [uncertain, setUncertain] = useState(false),
        [message, setMessage] = useState(''),
        [error, setError] = useState(''),
        [publishOpen, setPublishOpen] = useState(false),
        [archiveOpen, setArchiveOpen] = useState(false),
        [tick, setTick] = useState(0);
    const saved = useRef(JSON.stringify(form.definition)),
        intent = useRef<{ kind: 'save' | 'publish'; key: string; body: unknown } | null>(null),
        flight = useRef(false),
        history = useRef<Definition[]>([]),
        future = useRef<Definition[]>([]);
    const dirty = JSON.stringify(draft) !== saved.current,
        locked = busy || uncertain || !permissions.edit;
    const change = (next: Definition) => {
        if (locked) return;
        history.current.push(structuredClone(draft));
        if (history.current.length > 100) history.current.shift();
        future.current = [];
        setDraft(next);
        setMessage('');
    };
    const undo = (redo = false) => {
        if (locked) return;
        const from = redo ? future.current : history.current,
            to = redo ? history.current : future.current;
        const next = from.pop();
        if (next) {
            to.push(structuredClone(draft));
            setDraft(next);
            setTick(tick + 1);
        }
    };
    useEffect(() => {
        const warn = (e: BeforeUnloadEvent) => {
            if (dirty || uncertain) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        const remove = router.on('before', (event) => {
            if ((dirty || uncertain) && !window.confirm('Leave this form? Unsaved changes will be lost.')) event.preventDefault();
        });
        return () => {
            window.removeEventListener('beforeunload', warn);
            remove();
        };
    }, [dirty, uncertain]);
    const run = async (kind: 'save' | 'publish'): Promise<string | null> => {
        if (flight.current) return 'Wait for the current request to finish.';
        if (intent.current && intent.current.kind !== kind) return 'Retry the unconfirmed request first.';
        flight.current = true;
        setBusy(true);
        setError('');
        setMessage('');
        const action = intent.current ?? {
            kind,
            key: newRequestKey(),
            body: kind === 'save' ? { id: form.id, baseVersion: version, definition: structuredClone(draft) } : { expectedVersion: version },
        };
        intent.current = action;
        try {
            const result = await api<{ version?: number; publishedVersion?: number }>(kind === 'save' ? '/forms/save' : `/forms/${form.id}/publish`, {
                body: { ...(action.body as object), requestKey: action.key },
            });
            if (!result.ok) {
                if (result.code === 'INTERNAL') {
                    setUncertain(true);
                    setError('The server could not confirm this request. Retry before editing.');
                    return 'The server could not confirm this request. Retry before editing.';
                }
                intent.current = null;
                setUncertain(false);
                setError(result.message);
                return result.message;
            }
            if (kind === 'save') {
                setVersion(result.data.version!);
                saved.current = JSON.stringify((action.body as { definition: Definition }).definition);
            } else setPublished(result.data.publishedVersion!);
            intent.current = null;
            setUncertain(false);
            setMessage(kind === 'save' ? 'Draft saved. Publish when ready.' : 'Form published. Live pages now use this version.');
            return null;
        } catch {
            const text = `${kind === 'save' ? 'Save' : 'Publish'} could not be confirmed. Retry the same request before editing.`;
            setUncertain(true);
            setError(text);
            return text;
        } finally {
            flight.current = false;
            setBusy(false);
        }
    };
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                if (permissions.edit && !flight.current && (!intent.current || intent.current.kind === 'save')) void run('save');
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    });
    const sections = [
        'Build',
        'Settings',
        'Confirmations',
        ...(permissions.notifications ? ['Notifications'] : []),
        ...(permissions.entries ? ['Entries'] : []),
    ];
    const active = sections.includes(section) ? section : 'Build';
    return (
        <AdminLayout>
            <Head title={draft.name + ' · Forms'} />
            <div className="ak-page space-y-5">
                <AdminPageHeader
                    eyebrow={
                        <ButtonLink href="/admin/forms" size="sm" variant="ghost" icon="arrowLeft">
                            All forms
                        </ButtonLink>
                    }
                    title={draft.name}
                    description={`${published ? 'Published version ' + published : 'Unpublished form'} · ${busy ? 'Request in progress…' : uncertain ? 'Outcome unconfirmed' : dirty ? 'Unsaved changes' : 'Draft version ' + version}`}
                    actions={
                        <>
                            <Button disabled={locked || !history.current.length} size="sm" icon="undo" onClick={() => undo()}>
                                Undo
                            </Button>
                            <Button disabled={locked || !future.current.length} size="sm" icon="redo" onClick={() => undo(true)}>
                                Redo
                            </Button>
                            <a
                                href={`/admin/forms/${form.id}/preview`}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-disabled={dirty || uncertain || busy}
                                onClick={(e) => {
                                    if (dirty || uncertain || busy) {
                                        e.preventDefault();
                                        setError('Save the draft before opening its preview.');
                                    }
                                }}
                                className="ui-link px-3 py-2"
                            >
                                Preview
                            </a>
                            {permissions.edit && (
                                <Button
                                    busy={busy && intent.current?.kind === 'save'}
                                    disabled={busy || (uncertain && intent.current?.kind !== 'save')}
                                    onClick={() => void run('save')}
                                >
                                    {uncertain && intent.current?.kind === 'save' ? 'Retry Save' : 'Save draft'}
                                </Button>
                            )}
                            {permissions.publish && (
                                <Button
                                    variant="primary"
                                    disabled={busy || dirty || (uncertain && intent.current?.kind !== 'publish')}
                                    onClick={() => setPublishOpen(true)}
                                >
                                    {uncertain && intent.current?.kind === 'publish' ? 'Retry Publish' : 'Publish form'}
                                </Button>
                            )}
                        </>
                    }
                />
                {error && <Notice tone="error">{error}</Notice>}
                {message && <Notice tone="success">{message}</Notice>}
                {uncertain && <Notice tone="warning">Editing is locked until the request is confirmed. Keep this tab open and retry.</Notice>}
                <nav aria-label="Form sections" className="flex gap-1 overflow-x-auto border-b border-line pb-3">
                    {sections.map((s) => (
                        <Button
                            key={s}
                            variant={active === s ? 'primary' : 'ghost'}
                            onClick={() => {
                                setSection(s);
                                const url = new URL(location.href);
                                url.searchParams.set('section', s);
                                window.history.replaceState(null, '', url);
                            }}
                        >
                            {s}
                        </Button>
                    ))}
                </nav>
                {active === 'Build' && <FormBuilder fields={draft.fields} disabled={locked} onChange={(fields) => change({ ...draft, fields })} />}
                {active === 'Settings' && (
                    <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <fieldset disabled={locked} className="space-y-5 rounded-lg border border-line bg-surface p-5">
                            <h2 className="t-section">Form settings</h2>
                            <label className="ui-field">
                                Form name
                                <input className="ui-input" value={draft.name} maxLength={100} onChange={(e) => change({ ...draft, name: e.target.value })} />
                            </label>
                            <label className="ui-field">
                                Description
                                <textarea
                                    className="ui-input"
                                    value={draft.description}
                                    maxLength={600}
                                    onChange={(e) => change({ ...draft, description: e.target.value })}
                                />
                                <span className="t-meta">Shown above the fields.</span>
                            </label>
                            <label className="ui-field">
                                Submit button label
                                <input
                                    className="ui-input"
                                    value={draft.submitLabel}
                                    maxLength={60}
                                    onChange={(e) => change({ ...draft, submitLabel: e.target.value })}
                                />
                            </label>
                            <label className="ui-check">
                                <input type="checkbox" checked={draft.active} onChange={(e) => change({ ...draft, active: e.target.checked })} />
                                Accept submissions
                            </label>
                            <p className="t-meta">Disabling takes effect when this form is published. Existing entries remain available.</p>
                        </fieldset>
                        <aside className="space-y-5">
                            <section className="space-y-3 rounded-lg border border-line bg-surface p-5">
                                <h2 className="t-title">Spam and privacy</h2>
                                <p className="t-meta">
                                    Honeypot protection, cross-site request checks and submission rate limits are always enabled. Entries are encrypted. Visitor
                                    IPs are used temporarily for rate limiting and are not stored with entries.
                                </p>
                            </section>
                            <section className="space-y-3 rounded-lg border border-line bg-surface p-5">
                                <h2 className="t-title">Where it is used</h2>
                                {form.usage?.length ? (
                                    <ul className="space-y-1">
                                        {form.usage.map((path) => (
                                            <li key={path}>
                                                <a className="ui-link" href={path} target="_blank" rel="noreferrer">
                                                    {path}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="t-meta">Not used on a live page. Choose this form in a Form block in the page builder.</p>
                                )}
                                <p className="t-meta">
                                    Form reference: <code className="break-all">{form.id}</code>
                                </p>
                            </section>
                            {permissions.manage && (
                                <section className="space-y-3 rounded-lg border border-line bg-surface p-5">
                                    <h2 className="t-title">Archive form</h2>
                                    <p className="t-meta">Remove it from live pages first. Archiving hides the form and retains its entries and history.</p>
                                    <Button variant="quiet-danger" disabled={busy || uncertain || dirty} onClick={() => setArchiveOpen(true)}>
                                        Archive form
                                    </Button>
                                </section>
                            )}
                        </aside>
                    </div>
                )}
                {active === 'Confirmations' && (
                    <fieldset disabled={locked} className="max-w-2xl space-y-5 rounded-lg border border-line bg-surface p-5">
                        <h2 className="t-section">After submission</h2>
                        <p className="t-meta">Choose what visitors see after their entry is safely stored.</p>
                        <label className="ui-field">
                            Confirmation type
                            <select
                                className="ui-input"
                                value={draft.confirmation.type}
                                onChange={(e) => change({ ...draft, confirmation: { ...draft.confirmation, type: e.target.value as 'message' | 'redirect' } })}
                            >
                                <option value="message">Show a message</option>
                                <option value="redirect">Redirect to a page or URL</option>
                            </select>
                        </label>
                        {draft.confirmation.type === 'message' ? (
                            <label className="ui-field">
                                Confirmation message
                                <textarea
                                    className="ui-input"
                                    maxLength={400}
                                    value={draft.confirmation.message}
                                    onChange={(e) =>
                                        change({ ...draft, successMessage: e.target.value, confirmation: { ...draft.confirmation, message: e.target.value } })
                                    }
                                />
                            </label>
                        ) : (
                            <label className="ui-field">
                                Redirect URL
                                <input
                                    className="ui-input"
                                    placeholder="/thank-you or https://example.com/thanks"
                                    value={draft.confirmation.url}
                                    onChange={(e) => change({ ...draft, confirmation: { ...draft.confirmation, url: e.target.value } })}
                                />
                            </label>
                        )}
                        <p className="t-meta">One confirmation per form. Conditional confirmations are not supported yet.</p>
                    </fieldset>
                )}
                {active === 'Notifications' && (
                    <Notifications
                        definition={draft}
                        onChange={change}
                        disabled={locked}
                        sender={sender}
                        configured={mailConfigured}
                        legacyEmail={form.notificationEmail}
                        formId={form.id}
                    />
                )}
                {active === 'Entries' && permissions.entries && <FormEntries formId={form.id} definition={draft} canExport={permissions.export} />}
            </div>
            <ConfirmDialog
                open={publishOpen}
                title="Publish this form?"
                confirmLabel={uncertain ? 'Retry Publish' : 'Publish form'}
                onClose={() => setPublishOpen(false)}
                onConfirm={async () => {
                    const error = await run('publish');
                    if (!error) setPublishOpen(false);
                    return error;
                }}
            >
                Live pages that use this form will be refreshed. Existing entries keep the form version originally submitted.
            </ConfirmDialog>
            <ConfirmDialog
                open={archiveOpen}
                title="Archive this form?"
                confirmLabel="Archive form"
                requireText={draft.name}
                tone="danger"
                onClose={() => setArchiveOpen(false)}
                onConfirm={async () => {
                    try {
                        const r = await api(`/forms/${form.id}/archive`, { body: { version } });
                        if (!r.ok) return r.message;
                        saved.current = JSON.stringify(draft);
                        router.visit('/admin/forms');
                        return null;
                    } catch {
                        return 'The result could not be confirmed. Retry archiving.';
                    }
                }}
            >
                The form will be hidden. Entries and history will be retained. Live references must be removed first.
            </ConfirmDialog>
        </AdminLayout>
    );
}

function Notifications({
    definition: d,
    onChange,
    disabled,
    sender,
    configured,
    legacyEmail,
    formId,
}: {
    definition: Definition;
    onChange: (d: Definition) => void;
    disabled: boolean;
    sender: string | null;
    configured: boolean;
    legacyEmail: string | null;
    formId: string;
}) {
    const [selected, setSelected] = useState(d.notifications[0]?.id ?? ''),
        [legacy, setLegacy] = useState(legacyEmail ?? ''),
        [legacyError, setLegacyError] = useState('');
    const n = d.notifications.find((x) => x.id === selected);
    const update = (patch: Partial<Notification>) => {
        if (n) onChange({ ...d, notifications: d.notifications.map((x) => (x.id === n.id ? { ...x, ...patch } : x)) });
    };
    const mergeFields = [
        { id: 'all_fields', label: 'All submitted fields' },
        { id: 'entry_id', label: 'Entry ID' },
        { id: 'submission_date', label: 'Submission date' },
        ...d.fields.filter((f) => !['section', 'divider'].includes(f.type)),
    ];
    return (
        <div className="space-y-4">
            {!configured && (
                <Notice tone="warning">
                    Email delivery is not configured. Entries are still saved. Ask the administrator to configure a production mail service before relying on
                    notifications.
                </Notice>
            )}
            {legacyEmail && (
                <section className="space-y-3 rounded-lg border border-line bg-surface p-4">
                    <h2 className="t-title">Existing notification recipient</h2>
                    <p className="t-meta">This legacy recipient is used only when the form has no notification definitions. Its setting applies immediately.</p>
                    <label className="ui-field">
                        Legacy recipient
                        <input className="ui-input" type="email" disabled={disabled} value={legacy} onChange={(e) => setLegacy(e.target.value)} />
                    </label>
                    <Button
                        disabled={disabled}
                        onClick={async () => {
                            try {
                                const r = await api(`/forms/${formId}/notifications`, { body: { email: legacy || null } });
                                setLegacyError(r.ok ? 'Recipient updated.' : r.message);
                            } catch {
                                setLegacyError('Could not confirm the update. Retry.');
                            }
                        }}
                    >
                        Update legacy recipient
                    </Button>
                    {legacyError && <p role="status">{legacyError}</p>}
                </section>
            )}
            <div className="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <aside className="space-y-3 rounded-lg border border-line bg-surface p-4">
                    <h2 className="t-title">Email notifications</h2>
                    {d.notifications.map((x) => (
                        <Button key={x.id} className="w-full justify-start" variant={selected === x.id ? 'primary' : 'ghost'} onClick={() => setSelected(x.id)}>
                            {x.name}
                            {x.enabled ? '' : ' · disabled'}
                        </Button>
                    ))}
                    <Button
                        icon="plus"
                        disabled={disabled || d.notifications.length >= formLimits.maxNotifications}
                        onClick={() => {
                            const key = id();
                            onChange({
                                ...d,
                                notifications: [
                                    ...d.notifications,
                                    {
                                        id: key,
                                        name: 'New notification',
                                        enabled: true,
                                        recipient: '',
                                        replyTo: '',
                                        fromName: '',
                                        subject: 'New form entry',
                                        message: '{all_fields}',
                                        condition: null,
                                    },
                                ],
                            });
                            setSelected(key);
                        }}
                    >
                        Add notification
                    </Button>
                </aside>
                {n ? (
                    <fieldset disabled={disabled} className="space-y-4 rounded-lg border border-line bg-surface p-5">
                        <h2 className="t-section">{n.name}</h2>
                        <label className="ui-field">
                            Notification name
                            <input className="ui-input" value={n.name} maxLength={100} onChange={(e) => update({ name: e.target.value })} />
                        </label>
                        <label className="ui-check">
                            <input type="checkbox" checked={n.enabled} onChange={(e) => update({ enabled: e.target.checked })} />
                            Enabled
                        </label>
                        <label className="ui-field">
                            Recipient
                            <input
                                className="ui-input"
                                value={n.recipient}
                                placeholder="team@example.com or an email field tag"
                                onChange={(e) => update({ recipient: e.target.value })}
                            />
                        </label>
                        <label className="ui-field">
                            Use a submitted email
                            <select
                                className="ui-input"
                                value=""
                                onChange={(e) => {
                                    if (e.target.value) update({ recipient: '{' + e.target.value + '}' });
                                }}
                            >
                                <option value="">Choose email field…</option>
                                {d.fields
                                    .filter((f) => f.type === 'email')
                                    .map((f) => (
                                        <option key={f.id} value={f.id}>
                                            {f.label}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label className="ui-field">
                            Sender name
                            <input className="ui-input" value={n.fromName} onChange={(e) => update({ fromName: e.target.value })} />
                            <span className="t-meta">Sender email: {sender || 'Not configured'}. It is controlled by the mail service.</span>
                        </label>
                        <label className="ui-field">
                            Reply-to
                            <input
                                className="ui-input"
                                value={n.replyTo}
                                placeholder="Email address or email field tag"
                                onChange={(e) => update({ replyTo: e.target.value })}
                            />
                        </label>
                        <label className="ui-field">
                            Subject
                            <input className="ui-input" value={n.subject} maxLength={150} onChange={(e) => update({ subject: e.target.value })} />
                        </label>
                        <label className="ui-field">
                            Message
                            <textarea rows={8} className="ui-input" value={n.message} maxLength={4000} onChange={(e) => update({ message: e.target.value })} />
                            <span className="t-meta">Plain text. Submitted values are inserted safely.</span>
                        </label>
                        <label className="ui-field">
                            Insert field in message
                            <select
                                className="ui-input"
                                value=""
                                onChange={(e) => {
                                    if (e.target.value) update({ message: n.message + '{' + e.target.value + '}' });
                                }}
                            >
                                <option value="">Choose a merge field…</option>
                                {mergeFields.map((f) => (
                                    <option key={f.id} value={f.id}>
                                        {f.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <h3 className="t-title">Send only when</h3>
                        <ConditionEditor value={n.condition} fields={d.fields} onChange={(condition) => update({ condition })} />
                        <Button
                            variant="quiet-danger"
                            onClick={() => {
                                onChange({ ...d, notifications: d.notifications.filter((x) => x.id !== n.id) });
                                setSelected('');
                            }}
                        >
                            Remove notification
                        </Button>
                    </fieldset>
                ) : (
                    <EmptyState icon="form" title="Choose or add a notification">
                        Configure separate messages for your team and the person submitting.
                    </EmptyState>
                )}
            </div>
        </div>
    );
}
