import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { AdminLayout } from '@/Components/AdminLayout';
import { Icon } from '@/Components/Icon';
import { buttonClass, EmptyState, Notice, SectionHeader } from '@/Components/ui';
import { api, newRequestKey } from '@/lib/api';
type Field = { id: string; label: string; type: 'text' | 'email' | 'tel' | 'textarea' | 'select' | 'checkbox'; required: boolean; options?: string[] };
type Definition = { name: string; submitLabel: string; successMessage: string; fields: Field[] };
type FormRow = { id: string; version: number; publishedVersion: number | null; definition: Definition; notificationEmail: string | null };
const defaults: Definition = {
    name: 'Contact enquiry',
    submitLabel: 'Send enquiry',
    successMessage: 'Thank you. Your enquiry has been received.',
    fields: [
        { id: 'name', label: 'Your name', type: 'text', required: true },
        { id: 'email', label: 'Email address', type: 'email', required: true },
        { id: 'message', label: 'How can we help?', type: 'textarea', required: true },
    ],
};
const newsletter: Definition = {
    name: 'Newsletter signup',
    submitLabel: 'Subscribe',
    successMessage: 'Thank you. Your signup has been recorded.',
    fields: [{ id: 'email', label: 'Email address', type: 'email', required: true }],
};
const control = 'ui-input';
export default function Forms({
    forms,
    submissions,
    permissions,
}: {
    forms: FormRow[];
    submissions: { id: string; formId: string; createdAt: string; notificationStatus: string; values: Record<string, string> }[];
    permissions: { edit: boolean; publish: boolean };
}) {
    const [selected, setSelected] = useState<FormRow | null>(forms[0] ?? null),
        [draft, setDraft] = useState<Definition>(() => structuredClone(forms[0]?.definition ?? defaults)),
        [email, setEmail] = useState(forms[0]?.notificationEmail ?? ''),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [saveUnconfirmed, setSaveUnconfirmed] = useState(false);
    const saveAttempt = useRef<{ body: string; key: string } | null>(null),
        publishAttempt = useRef<{ id: string; version: number; key: string } | null>(null);
    const dirty = JSON.stringify(draft) !== JSON.stringify(selected?.definition ?? null);
    const select = (f: FormRow | null) => {
        if (busy || saveUnconfirmed) return;
        if (dirty && !confirm('Discard unsaved form edits?')) return;
        setSelected(f);
        setDraft(structuredClone(f?.definition ?? defaults));
        setEmail(f?.notificationEmail ?? '');
        setNotice('');
    };
    const newNewsletter = () => {
        if (busy || saveUnconfirmed) return;
        if (dirty && !confirm('Discard unsaved form edits?')) return;
        setSelected(null);
        setDraft(structuredClone(newsletter));
        setEmail('');
        setNotice('Newsletter signups are saved here. Connect a mailing-list service separately before promising email delivery.');
    };
    const update = (i: number, values: Partial<Field>) => setDraft((d) => ({ ...d, fields: d.fields.map((f, n) => (n === i ? { ...f, ...values } : f)) }));
    const save = async () => {
        if (busy) return;
        setBusy(true);
        const body = { id: selected?.id ?? null, baseVersion: selected?.version ?? 0, definition: structuredClone(draft) };
        const json = JSON.stringify(body);
        const a = saveAttempt.current?.body === json ? saveAttempt.current : { body: json, key: newRequestKey() };
        saveAttempt.current = a;
        try {
            const r = await api<{ id: string; version: number }>('/forms/save', { body: { ...body, requestKey: a.key } });
            if (!r.ok) {
                setNotice(r.message);
                return;
            }
            saveAttempt.current = null;
            setSaveUnconfirmed(false);
            setSelected({
                id: r.data.id,
                version: r.data.version,
                definition: body.definition,
                publishedVersion: selected?.publishedVersion ?? null,
                notificationEmail: email,
            });
            setNotice('Form draft saved. Publish it when ready.');
            router.reload({ only: ['forms'] });
        } catch {
            setSaveUnconfirmed(true);
            setNotice('Save could not be confirmed. Retry Save before editing or switching forms.');
        } finally {
            setBusy(false);
        }
    };
    const publish = async () => {
        if (!selected || dirty || busy || saveUnconfirmed) return;
        if (!confirm('Publish this form? Live pages that use it will be refreshed.')) return;
        setBusy(true);
        const a =
            publishAttempt.current?.id === selected.id && publishAttempt.current.version === selected.version
                ? publishAttempt.current
                : { id: selected.id, version: selected.version, key: newRequestKey() };
        publishAttempt.current = a;
        try {
            const r = await api<{ publishedVersion: number }>(`/forms/${selected.id}/publish`, { body: { expectedVersion: a.version, requestKey: a.key } });
            if (!r.ok) {
                setNotice(r.message);
                return;
            }
            publishAttempt.current = null;
            setSelected({ ...selected, publishedVersion: r.data.publishedVersion });
            setNotice('Form published. Add Contact form in the page builder.');
            router.reload({ only: ['forms'] });
        } catch {
            setNotice('Publish could not be confirmed. Retry to check the same publication.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <AdminLayout>
            <Head title="Forms" />
            <div className="ak-page space-y-8">
                <AdminPageHeader
                    title="Forms & enquiries"
                    description="Build a form once, publish it, and select it in any page. Enquiries are saved even if email delivery fails."
                />
                {notice && (
                    <Notice tone="info">
                        <p>{notice}</p>
                    </Notice>
                )}
                <div className="grid items-start gap-6 md:grid-cols-[14rem_minmax(0,1fr)]">
                    <aside className="space-y-1" aria-label="Forms">
                        <p className="mb-2 px-3 t-eyebrow">Forms</p>
                        {forms.map((f) => (
                            <button
                                aria-current={selected?.id === f.id ? 'true' : undefined}
                                className={`block w-full rounded-md px-3 py-2 text-left text-ui font-medium transition-colors ${selected?.id === f.id ? 'bg-accent-soft text-fg ring-1 ring-accent-line ring-inset' : 'hover:bg-hover'}`}
                                key={f.id}
                                disabled={busy || saveUnconfirmed}
                                onClick={() => select(f)}
                            >
                                {f.definition.name}
                                <span className="mt-0.5 flex items-center gap-1.5 text-xs font-normal text-muted">
                                    <span aria-hidden="true" className={`size-1.5 rounded-full ${f.publishedVersion ? 'bg-live' : 'bg-draft'}`} />
                                    {f.publishedVersion ? 'Published' : 'Draft'}
                                </span>
                            </button>
                        ))}
                        {permissions.edit && (
                            <div className="pt-2">
                                <button
                                    disabled={busy || saveUnconfirmed}
                                    className={buttonClass('ghost', 'sm', 'w-full justify-start')}
                                    onClick={() => select(null)}
                                >
                                    <Icon name="plus" className="size-3.5" />
                                    New form
                                </button>
                            </div>
                        )}
                        <button
                            className={buttonClass('ghost', 'sm', 'w-full justify-start')}
                            disabled={!permissions.edit || busy || saveUnconfirmed}
                            onClick={newNewsletter}
                        >
                            <Icon name="plus" className="size-3.5" />
                            Newsletter form
                        </button>
                    </aside>
                    <section className="space-y-5 rounded-lg border border-line p-5 shadow-hairline">
                        <fieldset disabled={!permissions.edit || busy || saveUnconfirmed} className="space-y-4">
                            <label className="ui-field">
                                Form name
                                <input className={control} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />
                            </label>
                            {draft.fields.map((f, i) => (
                                <div key={i} className="space-y-3 rounded-lg border border-line bg-raised p-4">
                                    <div className="flex items-center justify-between">
                                        <strong className="t-title">Field {i + 1}</strong>
                                        <div className="flex gap-1 text-xs">
                                            <button
                                                className={buttonClass('ghost', 'sm')}
                                                disabled={i === 0}
                                                onClick={() =>
                                                    setDraft({
                                                        ...draft,
                                                        fields: draft.fields.map((v, n) => (n === i - 1 ? f : n === i ? draft.fields[i - 1]! : v)),
                                                    })
                                                }
                                            >
                                                Move up
                                            </button>
                                            <button
                                                className={buttonClass('quiet-danger', 'sm')}
                                                disabled={draft.fields.length <= 1}
                                                onClick={() => setDraft({ ...draft, fields: draft.fields.filter((_, n) => n !== i) })}
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label className="ui-field">
                                            Label
                                            <input className={control} value={f.label} onChange={(e) => update(i, { label: e.target.value })} />
                                        </label>
                                        <label className="ui-field">
                                            Type
                                            <select
                                                className={control}
                                                value={f.type}
                                                onChange={(e) =>
                                                    update(i, {
                                                        type: e.target.value as Field['type'],
                                                        options: e.target.value === 'select' ? ['Option one', 'Option two'] : undefined,
                                                    })
                                                }
                                            >
                                                {['text', 'email', 'tel', 'textarea', 'select', 'checkbox'].map((t) => (
                                                    <option key={t}>{t}</option>
                                                ))}
                                            </select>
                                        </label>
                                    </div>
                                    <label className="ui-field">
                                        Field key (letters, numbers and underscores)
                                        <input className={control} value={f.id} onChange={(e) => update(i, { id: e.target.value })} />
                                    </label>
                                    {f.type === 'select' && (
                                        <label className="ui-field">
                                            Options, one per line
                                            <textarea
                                                className={control}
                                                value={f.options?.join('\n') ?? ''}
                                                onChange={(e) => update(i, { options: e.target.value.split('\n') })}
                                            />
                                        </label>
                                    )}
                                    <label className="ui-check">
                                        <input type="checkbox" checked={f.required} onChange={(e) => update(i, { required: e.target.checked })} />
                                        Required
                                    </label>
                                </div>
                            ))}
                            <button
                                className={buttonClass('secondary', 'sm')}
                                disabled={draft.fields.length >= 20}
                                onClick={() =>
                                    setDraft({
                                        ...draft,
                                        fields: [
                                            ...draft.fields,
                                            { id: `field_${Date.now().toString(36)}`, label: 'New field', type: 'text', required: false },
                                        ],
                                    })
                                }
                            >
                                <Icon name="plus" className="size-3.5" />
                                Add field
                            </button>
                            <label className="ui-field">
                                Submit button
                                <input className={control} value={draft.submitLabel} onChange={(e) => setDraft({ ...draft, submitLabel: e.target.value })} />
                            </label>
                            <label className="ui-field">
                                Confirmation message
                                <textarea
                                    className={control}
                                    value={draft.successMessage}
                                    onChange={(e) => setDraft({ ...draft, successMessage: e.target.value })}
                                />
                            </label>
                        </fieldset>
                        <div className="flex flex-wrap gap-2 border-t border-line pt-4">
                            {permissions.edit && (
                                <button disabled={busy} className={buttonClass('primary')} onClick={() => void save()}>
                                    Save draft
                                </button>
                            )}
                            {permissions.publish && (
                                <button
                                    disabled={busy || saveUnconfirmed || dirty || !selected}
                                    className={buttonClass('secondary')}
                                    onClick={() => void publish()}
                                >
                                    Publish form
                                </button>
                            )}
                        </div>
                        {permissions.publish && (
                            <>
                                <div className="space-y-2 border-t border-line pt-4">
                                    <label className="ui-field">
                                        Notification email (optional)
                                        <input
                                            type="email"
                                            disabled={busy || saveUnconfirmed || !selected}
                                            className={control}
                                            value={email}
                                            onChange={(e) => setEmail(e.target.value)}
                                        />
                                    </label>
                                    <p className="ui-hint">Uses the server’s configured mail service. Blank means store enquiries only.</p>
                                    <button
                                        disabled={busy || saveUnconfirmed || !selected}
                                        className={buttonClass('secondary', 'sm')}
                                        onClick={async () => {
                                            if (!selected) return;
                                            setBusy(true);
                                            try {
                                                const r = await api(`/forms/${selected.id}/notifications`, { body: { email: email || null } });
                                                setNotice(r.ok ? 'Notification settings saved.' : r.message);
                                            } catch {
                                                setNotice('Could not confirm notification settings.');
                                            } finally {
                                                setBusy(false);
                                            }
                                        }}
                                    >
                                        Save notification settings
                                    </button>
                                </div>
                            </>
                        )}
                    </section>
                </div>
                {permissions.publish && (
                    <section className="space-y-4" aria-labelledby="enquiries-title">
                        <SectionHeader
                            id="enquiries-title"
                            title="Recent enquiries"
                            description="Latest 100 submissions. Only owners and admins can read enquiries."
                        />
                        {submissions.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-line-strong">
                                <EmptyState icon="form" title="No enquiries yet" compact>
                                    Submissions from published forms appear here.
                                </EmptyState>
                            </div>
                        ) : (
                            submissions.map((s) => (
                                <article key={s.id} className="rounded-lg border border-line p-4">
                                    <p className="mb-3 t-meta t-num">
                                        {s.createdAt} · Email: {s.notificationStatus}
                                    </p>
                                    <dl className="grid gap-3 sm:grid-cols-2">
                                        {Object.entries(s.values).map(([k, v]) => (
                                            <div key={k}>
                                                <dt className="t-label">{k}</dt>
                                                <dd className="mt-0.5 text-ui whitespace-pre-wrap">{v}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </article>
                            ))
                        )}
                    </section>
                )}
            </div>
        </AdminLayout>
    );
}
