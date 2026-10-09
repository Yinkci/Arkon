import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { AdminLayout } from '@/Components/AdminLayout';
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
const control = 'w-full rounded-md border border-line bg-surface p-2 text-sm';
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
            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <AdminPageHeader
                    title="Forms & enquiries"
                    description="Build a form once, publish it, and select it in any page. Enquiries are saved even if email delivery fails."
                />
                {notice && (
                    <p role="status" className="rounded border border-line bg-raised p-3 text-sm">
                        {notice}
                    </p>
                )}
                <div className="grid gap-6 md:grid-cols-[15rem_1fr]">
                    <aside className="space-y-2">
                        {forms.map((f) => (
                            <button
                                className={`block w-full rounded p-3 text-left text-sm ${selected?.id === f.id ? 'bg-accent text-white' : 'border border-line bg-raised'}`}
                                key={f.id}
                                disabled={busy || saveUnconfirmed}
                                onClick={() => select(f)}
                            >
                                {f.definition.name}
                                <span className="block text-xs">{f.publishedVersion ? 'Published' : 'Draft'}</span>
                            </button>
                        ))}
                        {permissions.edit && (
                            <button disabled={busy || saveUnconfirmed} className={control} onClick={() => select(null)}>
                                + New form
                            </button>
                        )}
                        <button className={control} disabled={!permissions.edit || busy || saveUnconfirmed} onClick={newNewsletter}>
                            + Newsletter form
                        </button>
                    </aside>
                    <section className="space-y-4 rounded-lg border border-line bg-raised p-5">
                        <fieldset disabled={!permissions.edit || busy || saveUnconfirmed} className="space-y-4">
                            <label className="block text-sm">
                                Form name
                                <input className={control} value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />
                            </label>
                            {draft.fields.map((f, i) => (
                                <div key={i} className="space-y-3 rounded border border-line p-3">
                                    <div className="flex items-center justify-between">
                                        <strong className="text-sm">Field {i + 1}</strong>
                                        <div className="flex gap-3 text-xs">
                                            <button
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
                                                disabled={draft.fields.length <= 1}
                                                onClick={() => setDraft({ ...draft, fields: draft.fields.filter((_, n) => n !== i) })}
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label className="text-sm">
                                            Label
                                            <input className={control} value={f.label} onChange={(e) => update(i, { label: e.target.value })} />
                                        </label>
                                        <label className="text-sm">
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
                                    <label className="block text-xs text-muted">
                                        Field key (letters, numbers and underscores)
                                        <input className={control} value={f.id} onChange={(e) => update(i, { id: e.target.value })} />
                                    </label>
                                    {f.type === 'select' && (
                                        <label className="block text-sm">
                                            Options, one per line
                                            <textarea
                                                className={control}
                                                value={f.options?.join('\n') ?? ''}
                                                onChange={(e) => update(i, { options: e.target.value.split('\n') })}
                                            />
                                        </label>
                                    )}
                                    <label className="flex gap-2 text-sm">
                                        <input type="checkbox" checked={f.required} onChange={(e) => update(i, { required: e.target.checked })} />
                                        Required
                                    </label>
                                </div>
                            ))}
                            <button
                                className={control}
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
                                + Add field
                            </button>
                            <label className="block text-sm">
                                Submit button
                                <input className={control} value={draft.submitLabel} onChange={(e) => setDraft({ ...draft, submitLabel: e.target.value })} />
                            </label>
                            <label className="block text-sm">
                                Confirmation message
                                <textarea
                                    className={control}
                                    value={draft.successMessage}
                                    onChange={(e) => setDraft({ ...draft, successMessage: e.target.value })}
                                />
                            </label>
                        </fieldset>
                        {permissions.edit && (
                            <button disabled={busy} className="rounded bg-accent px-4 py-2 text-sm text-white disabled:opacity-50" onClick={() => void save()}>
                                Save draft
                            </button>
                        )}
                        {permissions.publish && (
                            <>
                                <button
                                    disabled={busy || saveUnconfirmed || dirty || !selected}
                                    className="rounded border border-line px-4 py-2 text-sm disabled:opacity-50"
                                    onClick={() => void publish()}
                                >
                                    Publish form
                                </button>
                                <div className="border-t border-line pt-4">
                                    <label className="block text-sm">
                                        Notification email (optional)
                                        <input
                                            type="email"
                                            disabled={busy || saveUnconfirmed || !selected}
                                            className={control}
                                            value={email}
                                            onChange={(e) => setEmail(e.target.value)}
                                        />
                                    </label>
                                    <p className="my-2 text-xs text-muted">Uses the server’s configured mail service. Blank means store enquiries only.</p>
                                    <button
                                        disabled={busy || saveUnconfirmed || !selected}
                                        className="text-sm text-accent"
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
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold">Recent enquiries</h2>
                        <p className="text-xs text-muted">Latest 100 submissions. Only owners and admins can read enquiries.</p>
                        {submissions.length === 0 ? (
                            <p className="text-sm text-muted">No enquiries yet.</p>
                        ) : (
                            submissions.map((s) => (
                                <article key={s.id} className="rounded border border-line bg-raised p-4">
                                    <p className="mb-2 text-xs text-muted">
                                        {s.createdAt} · Email: {s.notificationStatus}
                                    </p>
                                    <dl className="grid gap-2">
                                        {Object.entries(s.values).map(([k, v]) => (
                                            <div key={k}>
                                                <dt className="text-xs font-semibold">{k}</dt>
                                                <dd className="whitespace-pre-wrap text-sm">{v}</dd>
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
