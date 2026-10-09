import { useEffect, useRef, useState } from 'react';
import { Button, EmptyState, Notice } from '@/Components/ui';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { api } from '@/lib/api';
import { fullDate } from '@/lib/time';
import type { Definition } from './schema';
type Entry = {
    id: string;
    createdAt: string;
    read: boolean;
    starred: boolean;
    status: string;
    notificationStatus: string;
    notificationResults: { id: string; name: string; status: string }[];
    fields: { id: string; label: string; value: string; type: string }[];
    formVersion: number;
};
type Results = { items: Entry[]; page: number; pages: number; total: number };
export function FormEntries({ formId, definition, canExport }: { formId: string; definition: Definition; canExport: boolean }) {
    const [q, setQ] = useState(''),
        [filter, setFilter] = useState('inbox'),
        [from, setFrom] = useState(''),
        [to, setTo] = useState(''),
        [sort, setSort] = useState('newest'),
        [page, setPage] = useState(1),
        [result, setResult] = useState<Results | null>(null),
        [error, setError] = useState(''),
        [busy, setBusy] = useState(false),
        [selected, setSelected] = useState<string[]>([]),
        [detail, setDetail] = useState<Entry | null>(null),
        [refresh, setRefresh] = useState(0),
        [trash, setTrash] = useState(false),
        [exportOpen, setExportOpen] = useState(false),
        [exportFields, setExportFields] = useState<string[]>([]);
    const dialog = useRef<HTMLDialogElement>(null),
        sequence = useRef(0);
    const params = new URLSearchParams({ q, filter, from, to, sort, page: String(page) });
    useEffect(() => {
        let active = true;
        const seq = ++sequence.current;
        const timer = setTimeout(async () => {
            setBusy(true);
            try {
                const r = await api<Results>(`/forms/${formId}/entries?${params}`, { method: 'GET' });
                if (active && seq === sequence.current) {
                    if (r.ok) {
                        setResult(r.data);
                        setSelected([]);
                        setError('');
                    } else setError(r.message);
                }
            } catch {
                if (active) setError('Entries could not be loaded. Try Refresh.');
            } finally {
                if (active) setBusy(false);
            }
        }, 250);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [formId, q, filter, from, to, sort, page, refresh]);
    useEffect(() => {
        if (detail) dialog.current?.showModal();
        else dialog.current?.close();
    }, [detail]);
    const change = async (action: string, ids = selected): Promise<string | null> => {
        setBusy(true);
        try {
            const r = await api(`/forms/${formId}/entries`, { body: { action, ids } });
            if (!r.ok) {
                setError(r.message);
                return r.message;
            }
            setRefresh((n) => n + 1);
            if (detail && ids.includes(detail.id))
                setDetail({
                    ...detail,
                    ...(action === 'read'
                        ? { read: true }
                        : action === 'unread'
                          ? { read: false }
                          : action === 'star'
                            ? { starred: true }
                            : action === 'unstar'
                              ? { starred: false }
                              : { status: action === 'restore' ? 'inbox' : action }),
                });
            return null;
        } catch {
            const m = 'The update could not be confirmed. Retry the same action.';
            setError(m);
            return m;
        } finally {
            setBusy(false);
        }
    };
    const open = async (entry: Entry) => {
        setDetail(entry);
        if (!entry.read) {
            const failure = await change('read', [entry.id]);
            if (!failure) setDetail((current) => (current?.id === entry.id ? { ...current, read: true } : current));
        }
    };
    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="t-section">Entries</h2>
                    <p className="t-meta">{result?.total ?? '…'} matching entries · Values are shown with the labels submitted at the time.</p>
                </div>
                <div className="flex gap-2">
                    <Button disabled={busy} onClick={() => setRefresh((n) => n + 1)}>
                        Refresh
                    </Button>
                    {canExport && <Button onClick={() => setExportOpen(!exportOpen)}>Export CSV</Button>}
                </div>
            </div>
            {error && <Notice tone="error">{error}</Notice>}
            <div className="flex flex-wrap items-end gap-3">
                <label className="ui-field">
                    Search entries
                    <input
                        className="ui-input"
                        placeholder="Whole word, email, or entry ID"
                        value={q}
                        onChange={(e) => {
                            setQ(e.target.value);
                            setPage(1);
                        }}
                    />
                </label>
                <label className="ui-field">
                    Status
                    <select
                        className="ui-input"
                        value={filter}
                        onChange={(e) => {
                            setFilter(e.target.value);
                            setPage(1);
                        }}
                    >
                        {['inbox', 'unread', 'starred', 'spam', 'trash'].map((s) => (
                            <option key={s} value={s}>
                                {s[0]!.toUpperCase() + s.slice(1)}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="ui-field">
                    From
                    <input
                        className="ui-input"
                        type="date"
                        value={from}
                        onChange={(e) => {
                            setFrom(e.target.value);
                            setPage(1);
                        }}
                    />
                </label>
                <label className="ui-field">
                    To
                    <input
                        className="ui-input"
                        type="date"
                        value={to}
                        onChange={(e) => {
                            setTo(e.target.value);
                            setPage(1);
                        }}
                    />
                </label>
                <label className="ui-field">
                    Order
                    <select className="ui-input" value={sort} onChange={(e) => setSort(e.target.value)}>
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                    </select>
                </label>
            </div>
            {exportOpen && (
                <div className="space-y-3 rounded-lg border border-line bg-surface p-4">
                    <h3 className="t-title">Export matching entries</h3>
                    <p className="t-meta">
                        Current search, status and dates apply. Leave every field unchecked to export all historical fields. Entry ID and submitted date are
                        always included.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        {definition.fields
                            .filter((f) => !['section', 'divider'].includes(f.type))
                            .map((f) => (
                                <label key={f.id} className="ui-check">
                                    <input
                                        type="checkbox"
                                        checked={exportFields.includes(f.id)}
                                        onChange={(e) => setExportFields(e.target.checked ? [...exportFields, f.id] : exportFields.filter((id) => id !== f.id))}
                                    />
                                    {f.label}
                                </label>
                            ))}
                    </div>
                    <a
                        className="ui-link"
                        href={`/admin/forms/${formId}/export?${params}&${exportFields.map((id) => 'fields[]=' + encodeURIComponent(id)).join('&')}`}
                    >
                        Download CSV
                    </a>
                </div>
            )}
            <div className="flex flex-wrap gap-2">
                <span className="t-meta self-center">{selected.length} selected</span>
                {['read', 'unread', 'star', 'unstar', ...(filter === 'trash' || filter === 'spam' ? ['restore'] : ['spam'])].map((action) => (
                    <Button key={action} size="sm" disabled={busy || !selected.length} onClick={() => void change(action)}>
                        {action[0]!.toUpperCase() + action.slice(1)}
                    </Button>
                ))}
                <Button size="sm" variant="quiet-danger" disabled={busy || !selected.length || filter === 'trash'} onClick={() => setTrash(true)}>
                    Trash
                </Button>
            </div>
            {busy && (
                <p role="status" className="t-meta">
                    Loading entries…
                </p>
            )}
            {result?.items.length ? (
                <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                    <table className="w-full text-left text-ui">
                        <thead>
                            <tr className="border-b border-line">
                                <th className="p-3">
                                    <input
                                        type="checkbox"
                                        aria-label="Select all entries on this page"
                                        checked={selected.length === result.items.length}
                                        onChange={(e) => setSelected(e.target.checked ? result.items.map((x) => x.id) : [])}
                                    />
                                </th>
                                {['Entry', 'Submitted', 'Status', 'Notification', ''].map((s) => (
                                    <th className="p-3" key={s}>
                                        {s}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {result.items.map((e) => (
                                <tr key={e.id} className="border-b border-line last:border-0">
                                    <td className="p-3">
                                        <input
                                            type="checkbox"
                                            aria-label={'Select entry ' + e.id}
                                            checked={selected.includes(e.id)}
                                            onChange={(event) => setSelected(event.target.checked ? [...selected, e.id] : selected.filter((id) => id !== e.id))}
                                        />
                                    </td>
                                    <td className="p-3">
                                        <button className={'ui-link text-left ' + (!e.read ? 'font-semibold' : '')} onClick={() => void open(e)}>
                                            {e.fields.find((f) => f.type === 'email')?.value || e.fields[0]?.value || 'Entry ' + e.id.slice(-8)}
                                        </button>
                                    </td>
                                    <td className="p-3 t-meta">{fullDate(e.createdAt)}</td>
                                    <td className="p-3">
                                        {e.read ? 'Read' : 'Unread'} · {e.status}
                                    </td>
                                    <td className="p-3 t-meta">{e.notificationStatus}</td>
                                    <td className="p-3">
                                        <Button
                                            size="sm"
                                            disabled={busy}
                                            aria-label={e.starred ? 'Unstar entry' : 'Star entry'}
                                            onClick={() => void change(e.starred ? 'unstar' : 'star', [e.id])}
                                        >
                                            {e.starred ? '★' : '☆'}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                !busy && (
                    <EmptyState icon="form" title="No matching entries">
                        Entries appear after a visitor submits a published form.
                    </EmptyState>
                )
            )}
            {result && (
                <div className="flex justify-between">
                    <Button disabled={busy || page <= 1} onClick={() => setPage(page - 1)}>
                        Previous page
                    </Button>
                    <span className="t-meta">
                        Page {result.page} of {result.pages}
                    </span>
                    <Button disabled={busy || page >= result.pages} onClick={() => setPage(page + 1)}>
                        Next page
                    </Button>
                </div>
            )}
            <dialog
                ref={dialog}
                aria-labelledby="entry-title"
                onCancel={() => setDetail(null)}
                className="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-2xl overflow-y-auto rounded-xl border border-line bg-surface p-6 text-fg shadow-pop backdrop:bg-scrim"
            >
                {detail && (
                    <div className="space-y-5">
                        <div className="flex justify-between gap-3">
                            <h2 id="entry-title" className="t-section">
                                Entry details
                            </h2>
                            <Button onClick={() => setDetail(null)}>Close</Button>
                        </div>
                        <p className="t-meta">
                            {fullDate(detail.createdAt)} · Form version {detail.formVersion}
                        </p>
                        <dl className="space-y-4">
                            {detail.fields.map((f) => (
                                <div key={f.id}>
                                    <dt className="t-label">{f.label}</dt>
                                    <dd className="mt-1 whitespace-pre-wrap break-words text-ui">{f.value || '—'}</dd>
                                </div>
                            ))}
                        </dl>
                        <section className="space-y-2 border-t border-line pt-4">
                            <h3 className="t-title">Notifications</h3>
                            {detail.notificationResults.length ? (
                                detail.notificationResults.map((n) => (
                                    <p key={n.id} className="t-meta">
                                        {n.name}: {n.status}
                                    </p>
                                ))
                            ) : (
                                <p className="t-meta">{detail.notificationStatus}</p>
                            )}
                            <p className="t-meta">“Sent” means the mail transport accepted the message; it does not guarantee inbox delivery.</p>
                        </section>
                        <div className="flex gap-2">
                            <Button disabled={busy} onClick={() => void change(detail.read ? 'unread' : 'read', [detail.id])}>
                                Mark {detail.read ? 'unread' : 'read'}
                            </Button>
                            <Button disabled={busy} onClick={() => void change(detail.starred ? 'unstar' : 'star', [detail.id])}>
                                {detail.starred ? 'Unstar' : 'Star'}
                            </Button>
                            <Button disabled={busy} onClick={() => void change(detail.status === 'inbox' ? 'spam' : 'restore', [detail.id])}>
                                {detail.status === 'inbox' ? 'Mark spam' : 'Restore'}
                            </Button>
                        </div>
                        <p className="break-all t-meta">Entry ID: {detail.id}</p>
                    </div>
                )}
            </dialog>
            <ConfirmDialog
                open={trash}
                title="Move selected entries to trash?"
                confirmLabel="Move to trash"
                tone="danger"
                onClose={() => setTrash(false)}
                onConfirm={async () => {
                    const error = await change('trash');
                    if (!error) setTrash(false);
                    return error;
                }}
            >
                Entries are retained and can be restored from Trash.
            </ConfirmDialog>
        </section>
    );
}
