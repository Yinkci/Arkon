import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { BulkBar, Checkbox, ListEmpty, MenuItem, RowMenu, SelectAllCheckbox, StatusTabs, useSelection } from '@/Components/ListManagement';
import { toast } from '@/Components/Toast';
import { Button, ButtonLink, Notice } from '@/Components/ui';
import { api, newRequestKey } from '@/lib/api';
import { bulk, plural, type BulkAction } from '@/lib/mutate';
import { template, type FormRow, type Permissions } from '@/forms/schema';
import { fullDate } from '@/lib/time';

type FormStatus = 'all' | 'active' | 'draft' | 'inactive' | 'trash';
type Confirm = { kind: 'trash'; forms: FormRow[] } | { kind: 'purge'; forms: FormRow[]; entries: number } | null;
const NOUN: [string, string] = ['form', 'forms'];

export default function Forms({
    library,
    permissions,
}: {
    library: { items: FormRow[]; total: number; page: number; pages: number; q: string; status: FormStatus; counts: Record<FormStatus, number> };
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
            if (q !== library.q)
                router.get(
                    '/admin/forms',
                    { q, ...(library.status === 'all' ? {} : { status: library.status }) },
                    { preserveState: true, preserveScroll: true },
                );
        }, 300);
        return () => clearTimeout(timer);
    }, [q, library.q, library.status]);
    const [confirm, setConfirm] = useState<Confirm>(null);
    const inTrash = library.status === 'trash';
    const selection = useSelection(library.items.map((f) => f.id));
    const selected = library.items.filter((f) => selection.has(f.id));
    async function act(action: BulkAction, items: { id: string; version?: number }[]): Promise<string | null> {
        const result = await bulk('forms', action, items, ['library']);
        const done = result.done.length;
        if (done === 0) return result.failed[0]?.message ?? 'Nothing changed.';
        const verb = { trash: 'moved to Trash', restore: 'restored', purge: 'deleted permanently' }[action];
        toast(done === 1 ? `Form ${verb}.` : `${plural(done, ...NOUN)} ${verb}.`);
        if (result.failed.length) toast(`${plural(result.failed.length, ...NOUN)} not changed: ${result.failed[0]!.message}`, 'error');
        selection.clear();
        return null;
    }
    async function restore(forms: FormRow[]) {
        const error = await act(
            'restore',
            forms.map((f) => ({ id: f.id })),
        ).catch(() => 'The outcome could not be confirmed (network problem). Check the Trash, then try again.');
        if (error) toast(error, 'error');
    }
    // Permanent deletion takes the entries with it, so the confirmation says how many.
    async function askPurge(forms: FormRow[]) {
        const counts = await api<Record<string, number>>('/forms/entry-counts', { body: { ids: forms.map((f) => f.id) } }).catch(() => null);
        if (!counts?.ok) return toast(counts?.message ?? 'Entry counts could not be loaded. Try again.', 'error');
        setConfirm({ kind: 'purge', forms, entries: Object.values(counts.data).reduce((sum, n) => sum + n, 0) });
    }
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
                    <StatusTabs<FormStatus>
                        label="Filter forms"
                        value={library.status}
                        onChange={(status) =>
                            router.get(
                                '/admin/forms',
                                { q, ...(status === 'all' ? {} : { status }) },
                                { preserveState: true, preserveScroll: true, replace: true },
                            )
                        }
                        tabs={[
                            { value: 'all', label: 'All', count: library.counts.all },
                            { value: 'active', label: 'Active', count: library.counts.active },
                            { value: 'draft', label: 'Draft', count: library.counts.draft },
                            ...(library.counts.inactive ? [{ value: 'inactive' as const, label: 'Inactive', count: library.counts.inactive }] : []),
                            ...(permissions.manage ? [{ value: 'trash' as const, label: 'Trash', count: library.counts.trash }] : []),
                        ]}
                    />
                    <label className="sr-only" htmlFor="forms-search">
                        Search forms
                    </label>
                    <input
                        id="forms-search"
                        type="search"
                        className="ui-input w-full sm:w-64"
                        value={q}
                        placeholder="Search forms"
                        onChange={(e) => setQ(e.target.value)}
                    />
                </div>
                <BulkBar selection={selection} noun={NOUN}>
                    {inTrash ? (
                        <>
                            <Button size="sm" icon="undo" onClick={() => void restore(selected)}>
                                Restore
                            </Button>
                            <Button size="sm" variant="quiet-danger" onClick={() => void askPurge(selected)}>
                                Delete permanently
                            </Button>
                        </>
                    ) : (
                        <Button size="sm" variant="quiet-danger" icon="trash" onClick={() => setConfirm({ kind: 'trash', forms: selected })}>
                            Move to Trash
                        </Button>
                    )}
                </BulkBar>
                {library.items.length ? (
                    <div className="ui-management-list rounded-lg border border-line bg-surface">
                        <table className="ui-management-table w-full text-left text-ui">
                            <caption className="sr-only">{inTrash ? 'Forms in the Trash' : 'Forms and management actions'}</caption>
                            <colgroup>
                                {permissions.manage && <col className="w-10" />}
                                <col />
                                <col className="ui-col-status" />
                                {permissions.entries && <col className="ui-col-count" />}
                                <col className="ui-col-date" />
                                <col className="ui-col-actions" />
                            </colgroup>
                            <thead className="border-b border-line text-muted">
                                <tr>
                                    {permissions.manage && (
                                        <th scope="col" className="w-10">
                                            <SelectAllCheckbox selection={selection} label={inTrash ? 'Select all forms in the Trash' : 'Select all forms'} />
                                        </th>
                                    )}
                                    <th scope="col">Form</th>
                                    <th scope="col">Status</th>
                                    {permissions.entries && (
                                        <th scope="col" className="ui-cell-count">
                                            Entries
                                        </th>
                                    )}
                                    <th scope="col" className="ui-cell-date">
                                        {inTrash ? 'Moved to Trash' : 'Updated'}
                                    </th>
                                    <th scope="col" className="ui-cell-actions">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {library.items.map((f) => (
                                    <tr
                                        key={f.id}
                                        className={`border-b border-line last:border-0 ${selection.has(f.id) ? 'bg-accent-soft/50' : 'hover:bg-hover'}`}
                                        data-testid="form-row"
                                    >
                                        {permissions.manage && (
                                            <td className="w-10">
                                                <Checkbox
                                                    checked={selection.has(f.id)}
                                                    onChange={() => selection.toggle(f.id)}
                                                    label={`Select ${f.definition.name}`}
                                                />
                                            </td>
                                        )}
                                        <th scope="row" className="ui-cell-name font-medium">
                                            {inTrash ? (
                                                <span className="block truncate" title={f.definition.name}>
                                                    {f.definition.name}
                                                </span>
                                            ) : (
                                                <Link href={`/admin/forms/${f.id}`} className="ui-link block truncate" title={f.definition.name}>
                                                    {f.definition.name}
                                                </Link>
                                            )}
                                        </th>
                                        <td className="ui-cell-status">
                                            <span
                                                className={
                                                    'inline-flex rounded-sm px-2 py-1 text-xs ' +
                                                    (f.status === 'Active' ? 'bg-live-soft text-live' : 'bg-sunken text-muted')
                                                }
                                            >
                                                {f.status}
                                            </span>
                                            {!inTrash && f.hasDraftChanges && !!f.publishedVersion && (
                                                <span className="mt-1 block text-xs text-changed">Unpublished edits</span>
                                            )}
                                        </td>
                                        {permissions.entries && (
                                            <td className="ui-cell-count t-num">
                                                {inTrash ? (
                                                    <span>{f.entries ?? 0}</span>
                                                ) : (
                                                    <Link
                                                        className="ui-link"
                                                        href={`/admin/forms/${f.id}?section=Entries`}
                                                        aria-label={`${plural(f.entries ?? 0, 'entry', 'entries')} for ${f.definition.name}`}
                                                    >
                                                        <span>{f.entries ?? 0}</span>
                                                        <span className="ui-mobile-label"> entries</span>
                                                    </Link>
                                                )}
                                            </td>
                                        )}
                                        <td className="ui-cell-date t-meta">
                                            <span className="ui-mobile-label">{inTrash ? 'Moved to Trash ' : 'Updated '}</span>
                                            <time dateTime={f.updatedAt}>{fullDate(f.updatedAt)}</time>
                                        </td>
                                        <td className="ui-cell-actions">
                                            <div className="flex flex-wrap items-center justify-end gap-1">
                                                {inTrash ? (
                                                    <>
                                                        <Button size="sm" variant="ghost" icon="undo" onClick={() => void restore([f])}>
                                                            Restore
                                                        </Button>
                                                        <Button size="sm" variant="quiet-danger" onClick={() => void askPurge([f])}>
                                                            Delete permanently
                                                        </Button>
                                                    </>
                                                ) : (
                                                    <>
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
                                                        <RowMenu label={`More actions for ${f.definition.name}`}>
                                                            {() => (
                                                                <>
                                                                    <MenuItem href={`/admin/forms/${f.id}?section=Settings`}>Settings</MenuItem>
                                                                    <MenuItem href={`/admin/forms/${f.id}/preview`} external>
                                                                        Preview
                                                                    </MenuItem>
                                                                    {permissions.edit && <MenuItem onSelect={() => void duplicate(f)}>Duplicate</MenuItem>}
                                                                    {permissions.manage && (
                                                                        <MenuItem tone="danger" onSelect={() => setConfirm({ kind: 'trash', forms: [f] })}>
                                                                            Move to Trash
                                                                        </MenuItem>
                                                                    )}
                                                                </>
                                                            )}
                                                        </RowMenu>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : inTrash ? (
                    <ListEmpty icon="trash" title={q ? 'No matching forms in the Trash' : 'Trash is empty'}>
                        {q
                            ? 'Change the search to find a form.'
                            : 'Forms you move to the Trash appear here, with their entries, until you restore or delete them.'}
                    </ListEmpty>
                ) : (
                    <ListEmpty icon="form" title={q || library.status !== 'all' ? 'No matching forms' : 'Create your first form'}>
                        {q || library.status !== 'all' ? 'Change the search or filter to find a form.' : 'Start with a blank form or a simple template.'}
                    </ListEmpty>
                )}
                <div className="flex items-center justify-between gap-3">
                    <p className="t-meta t-num">{plural(library.total, 'form')}</p>
                    {library.pages > 1 && (
                        <div className="flex items-center gap-3">
                            <Button
                                size="sm"
                                disabled={library.page <= 1}
                                onClick={() => router.get('/admin/forms', { q, status: library.status, page: library.page - 1 }, { preserveState: true })}
                            >
                                Previous
                            </Button>
                            <span className="t-meta t-num">
                                Page {library.page} of {library.pages}
                            </span>
                            <Button
                                size="sm"
                                disabled={library.page >= library.pages}
                                onClick={() => router.get('/admin/forms', { q, status: library.status, page: library.page + 1 }, { preserveState: true })}
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </div>
            </div>
            <ConfirmDialog
                open={confirm?.kind === 'trash'}
                title={
                    confirm?.kind === 'trash'
                        ? confirm.forms.length === 1
                            ? `Move “${confirm.forms[0]!.definition.name}” to Trash?`
                            : `Move ${plural(confirm.forms.length, ...NOUN)} to Trash?`
                        : ''
                }
                confirmLabel="Move to Trash"
                busyLabel="Moving to Trash…"
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    confirm?.kind === 'trash'
                        ? act(
                              'trash',
                              confirm.forms.map((f) => ({ id: f.id, version: f.version })),
                          )
                        : Promise.resolve(null)
                }
            >
                <p>
                    Entries and history are kept, and you can restore {confirm?.kind === 'trash' && confirm.forms.length > 1 ? 'them' : 'it'} from the Trash. A
                    form on a live page has to be removed from that page first.
                </p>
            </ConfirmDialog>
            <ConfirmDialog
                open={confirm?.kind === 'purge'}
                tone="danger"
                title={
                    confirm?.kind === 'purge'
                        ? confirm.forms.length === 1
                            ? `Delete “${confirm.forms[0]!.definition.name}” permanently?`
                            : `Permanently delete ${plural(confirm.forms.length, ...NOUN)}?`
                        : ''
                }
                confirmLabel="Delete permanently"
                busyLabel="Deleting…"
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    confirm?.kind === 'purge'
                        ? act(
                              'purge',
                              confirm.forms.map((f) => ({ id: f.id })),
                          )
                        : Promise.resolve(null)
                }
            >
                {confirm?.kind === 'purge' && (
                    <>
                        <p>This cannot be undone.</p>
                        {confirm.entries > 0 ? (
                            <p>
                                <strong className="text-fg">{plural(confirm.entries, 'entry', 'entries')}</strong> (visitors’ submissions) will be deleted with{' '}
                                {confirm.forms.length === 1 ? 'it' : 'them'}. Export them first if you need them.
                            </p>
                        ) : (
                            <p>{confirm.forms.length === 1 ? 'It has' : 'They have'} no entries.</p>
                        )}
                    </>
                )}
            </ConfirmDialog>
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
