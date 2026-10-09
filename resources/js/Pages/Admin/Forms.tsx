import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { Button, ButtonLink, EmptyState, Notice } from '@/Components/ui';
import { api, newRequestKey } from '@/lib/api';
import { template, type FormRow, type Permissions } from '@/forms/schema';
import { fullDate } from '@/lib/time';
export default function Forms({
    library,
    permissions,
}: {
    library: { items: FormRow[]; total: number; page: number; pages: number; q: string };
    permissions: Permissions;
}) {
    const [q, setQ] = useState(library.q),
        [creating, setCreating] = useState(false),
        [name, setName] = useState('Contact form'),
        [kind, setKind] = useState('contact'),
        [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const dialog = useRef<HTMLDialogElement>(null),
        intent = useRef<{ body: unknown; key: string } | null>(null),
        actionKeys = useRef<Record<string, string>>({});
    useEffect(() => {
        const timer = setTimeout(() => {
            if (q !== library.q) router.get('/admin/forms', { q }, { preserveState: true, preserveScroll: true });
        }, 300);
        return () => clearTimeout(timer);
    }, [q, library.q]);
    useEffect(() => {
        if (creating) dialog.current?.showModal();
        else dialog.current?.close();
    }, [creating]);
    const create = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        const a = intent.current ?? { key: newRequestKey(), body: { baseVersion: 0, definition: template(name, kind) } };
        intent.current = a;
        try {
            const result = await api<{ id: string }>('/forms/save', { body: { ...(a.body as object), requestKey: a.key } });
            if (!result.ok) {
                intent.current = null;
                setError(result.message);
                return;
            }
            intent.current = null;
            router.visit(`/admin/forms/${result.data.id}`);
        } catch {
            setError('Creation could not be confirmed. Retry Create form with the same request.');
        } finally {
            setBusy(false);
        }
    };
    const duplicate = async (f: FormRow) => {
        if (busy) return;
        setBusy(true);
        const key = actionKeys.current[f.id] ?? newRequestKey();
        actionKeys.current[f.id] = key;
        try {
            const r = await api<{ id: string }>(`/forms/${f.id}/duplicate`, { body: { requestKey: key } });
            if (r.ok) {
                delete actionKeys.current[f.id];
                router.visit(`/admin/forms/${r.data.id}`);
            } else {
                delete actionKeys.current[f.id];
                setError(r.message);
            }
        } catch {
            setError('Duplication could not be confirmed. Retry to check the same copy.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <AdminLayout>
            <Head title="Forms" />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    title="Forms"
                    description="Build reusable forms, configure their behavior, and manage entries."
                    actions={
                        permissions.edit ? (
                            <Button variant="primary" icon="plus" disabled={busy || !!intent.current} onClick={() => setCreating(true)}>
                                New form
                            </Button>
                        ) : undefined
                    }
                />
                {error && !creating && <Notice tone="error">{error}</Notice>}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <label className="ui-field">
                        Search forms
                        <input className="ui-input" value={q} placeholder="Form name" onChange={(e) => setQ(e.target.value)} />
                    </label>
                    <p className="t-meta">{library.total} forms</p>
                </div>
                {library.items.length ? (
                    <div className="ui-management-list rounded-lg border border-line bg-surface">
                        <table className="ui-management-table w-full text-left text-ui">
                            <caption className="sr-only">Forms and management actions</caption>
                            <colgroup>
                                <col />
                                <col className="ui-col-status" />
                                {permissions.entries && <col className="ui-col-count" />}
                                <col className="ui-col-date" />
                                <col className="ui-col-actions" />
                            </colgroup>
                            <thead className="border-b border-line text-muted">
                                <tr>
                                    <th scope="col">Form</th>
                                    <th scope="col">Status</th>
                                    {permissions.entries && (
                                        <th scope="col" className="ui-cell-count">
                                            Entries
                                        </th>
                                    )}
                                    <th scope="col" className="ui-cell-date">
                                        Updated
                                    </th>
                                    <th scope="col" className="ui-cell-actions">
                                        Manage
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {library.items.map((f) => (
                                    <tr key={f.id} className="border-b border-line last:border-0 hover:bg-hover">
                                        <th scope="row" className="ui-cell-name font-medium">
                                            <Link href={`/admin/forms/${f.id}`} className="ui-link block truncate" title={f.definition.name}>
                                                {f.definition.name}
                                            </Link>
                                        </th>
                                        <td className="ui-cell-status">
                                            <span
                                                className={
                                                    'inline-flex rounded-sm px-2 py-1 text-xs ' +
                                                    (f.status === 'Active'
                                                        ? 'bg-live-soft text-live'
                                                        : f.status === 'Draft'
                                                          ? 'bg-sunken text-muted'
                                                          : 'bg-sunken text-muted')
                                                }
                                            >
                                                {f.status}
                                            </span>
                                            {f.hasDraftChanges && !!f.publishedVersion && (
                                                <span className="mt-1 block text-xs text-changed">Unpublished edits</span>
                                            )}
                                        </td>
                                        {permissions.entries && (
                                            <td className="ui-cell-count t-num">
                                                <Link
                                                    className="ui-link"
                                                    href={`/admin/forms/${f.id}?section=Entries`}
                                                    aria-label={`${f.entries ?? 0} entries for ${f.definition.name}`}
                                                >
                                                    <span>{f.entries ?? 0}</span>
                                                    <span className="ui-mobile-label"> entries</span>
                                                </Link>
                                            </td>
                                        )}
                                        <td className="ui-cell-date t-meta">
                                            <span className="ui-mobile-label">Updated </span>
                                            <time dateTime={f.updatedAt}>{fullDate(f.updatedAt)}</time>
                                        </td>
                                        <td className="ui-cell-actions">
                                            <div className="flex items-center gap-1">
                                                {permissions.entries && (
                                                    <ButtonLink size="sm" variant="ghost" href={`/admin/forms/${f.id}?section=Entries`}>
                                                        Entries
                                                    </ButtonLink>
                                                )}
                                                <ButtonLink
                                                    size="sm"
                                                    variant="ghost"
                                                    className="ui-direct-settings"
                                                    href={`/admin/forms/${f.id}?section=Settings`}
                                                >
                                                    Settings
                                                </ButtonLink>
                                                <a
                                                    className="ui-direct-preview ui-link rounded-md px-2 py-1"
                                                    href={`/admin/forms/${f.id}/preview`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    Preview
                                                </a>
                                                <details
                                                    className={'relative ml-auto ' + (!permissions.edit && !permissions.manage ? 'ui-responsive-overflow' : '')}
                                                >
                                                    <summary
                                                        className="ui-row-overflow cursor-pointer rounded-md px-2 py-1 text-muted hover:bg-hover"
                                                        aria-label={'More actions for ' + f.definition.name}
                                                        title="More actions"
                                                    >
                                                        ⋯
                                                    </summary>
                                                    <div className="absolute right-0 z-10 grid min-w-40 gap-1 rounded-lg border border-line bg-surface p-2 shadow-pop">
                                                        <ButtonLink
                                                            size="sm"
                                                            variant="ghost"
                                                            className="ui-overflow-settings"
                                                            href={`/admin/forms/${f.id}?section=Settings`}
                                                        >
                                                            Settings
                                                        </ButtonLink>
                                                        <a
                                                            className="ui-overflow-preview ui-link px-3 py-2"
                                                            href={`/admin/forms/${f.id}/preview`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            Preview
                                                        </a>
                                                        {permissions.edit && (
                                                            <Button size="sm" variant="ghost" disabled={busy} onClick={() => void duplicate(f)}>
                                                                Duplicate
                                                            </Button>
                                                        )}
                                                        {permissions.manage && (
                                                            <ButtonLink size="sm" variant="quiet-danger" href={`/admin/forms/${f.id}?section=Settings`}>
                                                                Archive settings
                                                            </ButtonLink>
                                                        )}
                                                    </div>
                                                </details>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState icon="form" title={q ? 'No matching forms' : 'Create your first form'}>
                        Start with a blank form or a simple template.
                    </EmptyState>
                )}
                <div className="flex justify-between">
                    <Button disabled={library.page <= 1} onClick={() => router.get('/admin/forms', { q, page: library.page - 1 })}>
                        Previous page
                    </Button>
                    <span className="t-meta">
                        Page {library.page} of {library.pages}
                    </span>
                    <Button disabled={library.page >= library.pages} onClick={() => router.get('/admin/forms', { q, page: library.page + 1 })}>
                        Next page
                    </Button>
                </div>
            </div>
            <dialog
                ref={dialog}
                aria-labelledby="new-form-title"
                onCancel={(e) => {
                    if (busy || intent.current) e.preventDefault();
                    else setCreating(false);
                }}
                className="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-line bg-surface p-6 text-fg shadow-pop backdrop:bg-scrim"
            >
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void create();
                    }}
                >
                    <h2 id="new-form-title" className="t-section">
                        New form
                    </h2>
                    {error && <Notice tone="error">{error}</Notice>}
                    <fieldset disabled={busy || !!intent.current} className="space-y-4">
                        <label className="ui-field">
                            Form name
                            <input className="ui-input" required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} />
                        </label>
                        <label className="ui-field">
                            Start with
                            <select className="ui-input" value={kind} onChange={(e) => setKind(e.target.value)}>
                                <option value="blank">Blank form</option>
                                <option value="contact">Contact form</option>
                                <option value="newsletter">Newsletter signup</option>
                            </select>
                        </label>
                    </fieldset>
                    <div className="flex justify-end gap-2">
                        <Button disabled={busy || !!intent.current} onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" busy={busy} disabled={busy}>
                            {intent.current && !busy ? 'Retry Create form' : 'Create form'}
                        </Button>
                    </div>
                </form>
            </dialog>
        </AdminLayout>
    );
}
