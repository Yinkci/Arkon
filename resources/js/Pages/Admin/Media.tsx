import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Icon } from '@/Components/Icon';
import { BulkBar, Checkbox, ListEmpty, SelectAllCheckbox, StatusTabs, useSelection } from '@/Components/ListManagement';
import { toast } from '@/Components/Toast';
import { Button, Notice, Segmented, Spinner } from '@/Components/ui';
import { bulk, plural, reloadProps, type BulkAction } from '@/lib/mutate';
import { fullDate, relativeTime } from '@/lib/time';
import { api, newRequestKey } from '@/lib/api';
import { uploadMedia } from '@/lib/mediaUpload';
import { formatBytes as bytes, uploadHelp } from '@/lib/mediaPolicy';
import type { SharedProps } from '@/types';

type Asset = {
    id: string;
    title: string;
    alt: string;
    caption: string;
    description: string;
    version: number;
    originalName: string;
    url: string;
    previewUrl: string;
    /** Small WebP sizes for the grid (never the original when a size exists). */
    thumbUrl: string;
    thumbSrcset: string;
    width: number;
    height: number;
    mime: string;
    bytes: number;
    createdAt: string;
    archived: boolean;
    webpVariants: { url: string; width: number; height: number; bytes: number }[];
    optimization: { count: number; previewBytes: number | null; status: string };
    usage?: { draftPages: string[]; livePages: string[]; components: string[]; note: string };
};
type Library = {
    items: Asset[];
    total: number;
    page: number;
    pages: number;
    q: string;
    sort: string;
    status: 'library' | 'trash';
    counts: { library: number; trash: number };
};
type View = 'grid' | 'list';
type Usage = { livePages: number; drafts: number; components: number };
const VIEW_KEY = 'arkon.media.view';
const storedView = (): View => {
    try {
        return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid';
    } catch {
        return 'grid';
    }
};
type Fields = Pick<Asset, 'title' | 'alt' | 'caption' | 'description'>;

export default function Media({ library }: { library: Library }) {
    const { can, site } = usePage<SharedProps>().props;
    const [query, setQuery] = useState(library.q),
        [sort, setSort] = useState(library.sort),
        [busy, setBusy] = useState(false),
        [progress, setProgress] = useState(0),
        [error, setError] = useState(''),
        [selected, setSelected] = useState<Asset | null>(null),
        [fields, setFields] = useState<Fields | null>(null),
        [saving, setSaving] = useState(false),
        [message, setMessage] = useState(''),
        [confirmRemove, setConfirmRemove] = useState(false),
        [loadingDetails, setLoadingDetails] = useState(false),
        [view, setView] = useState<View>(storedView),
        [trashing, setTrashing] = useState<{ assets: Asset[]; usage: Usage | null } | null>(null);
    const inTrash = library.status === 'trash';
    // Selecting is for moving to the Trash and restoring: the same permission as those actions.
    const selectable = !!can['page.delete'];
    const selection = useSelection(library.items.map((a) => a.id));
    const selectedAssets = library.items.filter((a) => selection.has(a.id));
    function changeView(next: View) {
        setView(next);
        try {
            localStorage.setItem(VIEW_KEY, next);
        } catch {
            // Private mode: the choice lasts for this visit only.
        }
    }
    async function act(action: BulkAction, items: { id: string; version?: number }[]): Promise<string | null> {
        const result = await bulk('media', action, items, ['library']);
        const done = result.done.length;
        if (done === 0) return result.failed[0]?.message ?? 'Nothing changed.';
        toast(`${done === 1 ? 'Image' : plural(done, 'image')} ${action === 'trash' ? 'moved to Trash' : 'restored'}.`);
        if (result.failed.length) toast(`${plural(result.failed.length, 'image')} not changed: ${result.failed[0]!.message}`, 'error');
        selection.clear();
        return null;
    }
    async function restoreAssets(assets: Asset[]) {
        const error = await act(
            'restore',
            assets.map((a) => ({ id: a.id })),
        ).catch(() => 'The outcome could not be confirmed (network problem). Check the Trash, then try again.');
        if (error) toast(error, 'error');
    }
    // One summary of where the chosen images are used, before they move (never one dialog per image).
    async function askTrash(assets: Asset[]) {
        const usage = await api<Usage>('/media/usage', { body: { ids: assets.map((a) => a.id) } }).catch(() => null);
        setTrashing({ assets, usage: usage?.ok ? usage.data : null });
    }
    const file = useRef<HTMLInputElement>(null),
        dialog = useRef<HTMLDialogElement>(null),
        trigger = useRef<HTMLElement | null>(null),
        flight = useRef(false),
        load = useRef(0),
        intent = useRef<{ id: string; body: Fields & { version: number; requestKey: string } } | null>(null);
    const dirty = !!selected && !!fields && (['title', 'alt', 'caption', 'description'] as const).some((k) => selected[k] !== fields[k]);
    const unsaved = dirty || !!intent.current;
    useEffect(() => {
        if (query === library.q && sort === library.sort) return;
        const t = setTimeout(() => router.get('/admin/media', { q: query, sort }, { preserveState: true, preserveScroll: true, replace: true }), 300);
        return () => clearTimeout(t);
    }, [query, sort, library.q, library.sort]);
    useEffect(() => {
        if (!unsaved) return;
        const warn = (e: BeforeUnloadEvent) => {
            e.preventDefault();
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [unsaved]);
    async function open(asset: Asset) {
        if (flight.current || unsaved) return;
        if (!dialog.current?.open) trigger.current = document.activeElement as HTMLElement;
        setError('');
        setMessage('');
        setConfirmRemove(false);
        setLoadingDetails(true);
        setSelected(asset);
        setFields({ title: asset.title, alt: asset.alt, caption: asset.caption, description: asset.description });
        if (!dialog.current?.open) dialog.current?.showModal();
        const generation = ++load.current;
        try {
            const result = await api<Asset>(`/media/${asset.id}`);
            if (generation !== load.current) return;
            if (result.ok) {
                setSelected(result.data);
                setFields({ title: result.data.title, alt: result.data.alt, caption: result.data.caption, description: result.data.description });
                setLoadingDetails(false);
            } else setError(result.message);
        } catch {
            if (generation === load.current) setError('Image details could not be loaded. Close and try again.');
        }
    }
    function close() {
        if (flight.current || unsaved) return;
        ++load.current;
        dialog.current?.close();
        setSelected(null);
        setFields(null);
        setError('');
        trigger.current?.focus();
    }
    async function save() {
        if (!selected || !fields || flight.current || loadingDetails) return;
        flight.current = true;
        setSaving(true);
        setError('');
        setMessage('');
        const request = intent.current ?? { id: selected.id, body: { ...fields, version: selected.version, requestKey: newRequestKey() } };
        intent.current = request;
        try {
            const result = await api<{ version: number }>(`/media/${request.id}/metadata`, { body: request.body });
            if (result.ok) {
                setSelected({ ...selected, ...request.body, version: result.data.version });
                intent.current = null;
                setMessage('Details saved.');
                router.reload({ only: ['library'] });
            } else {
                intent.current = null;
                setError(result.message);
            }
        } catch {
            setError('Save outcome is unconfirmed. Retry Save to confirm the same change.');
        } finally {
            flight.current = false;
            setSaving(false);
        }
    }
    async function remove() {
        if (!selected || flight.current || unsaved) return;
        flight.current = true;
        setSaving(true);
        setError('');
        try {
            const result = await api(`/media/${selected.id}/archive`, { body: { version: selected.version } });
            if (result.ok) {
                flight.current = false;
                setConfirmRemove(false);
                close();
                await reloadProps(['library']);
                toast('Image moved to Trash.');
            } else setError(result.message);
        } catch {
            setError('The outcome could not be confirmed. Try again.');
        } finally {
            flight.current = false;
            setSaving(false);
        }
    }
    async function copyImageUrl(path: string, optimized = false) {
        try {
            const url = new URL(path, site?.url || window.location.origin).href;
            if (navigator.clipboard) await navigator.clipboard.writeText(url);
            else {
                // HTTP development origins may not expose the Clipboard API.
                const previousFocus = document.activeElement as HTMLElement | null;
                const input = document.createElement('textarea');
                input.value = url;
                input.style.position = 'fixed';
                input.style.opacity = '0';
                dialog.current?.append(input);
                input.select();
                const copied = document.execCommand('copy');
                input.remove();
                previousFocus?.focus();
                if (!copied) throw new Error('Clipboard unavailable');
            }
            setMessage(optimized ? 'WebP link copied.' : 'Link copied.');
        } catch {
            setError('Could not copy the link. Select the URL and copy it manually.');
        }
    }
    async function upload(image: File) {
        if (flight.current) return;
        flight.current = true;
        setBusy(true);
        setProgress(0);
        setError('');
        try {
            const asset = await uploadMedia(image, setProgress);
            if (asset.optimizationWarning) setError(asset.optimizationWarning);
            router.get('/admin/media', {}, { preserveState: true });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Upload failed.');
        } finally {
            flight.current = false;
            setBusy(false);
            if (file.current) file.current.value = '';
        }
    }
    const index = selected ? library.items.findIndex((a) => a.id === selected.id) : -1;
    const readonly = !can['media.upload'] || saving || loadingDetails || !!selected?.archived || !!intent.current;
    return (
        <AdminLayout>
            <Head title="Media library" />
            <div className="ak-page space-y-8">
                <AdminPageHeader
                    title="Media library"
                    description="Upload once, add useful descriptions, and reuse optimized images across your site."
                    actions={
                        can['media.upload'] && (
                            <Button variant="primary" icon="upload" busy={busy} disabled={busy} onClick={() => file.current?.click()}>
                                Upload image
                            </Button>
                        )
                    }
                />
                <input
                    ref={file}
                    type="file"
                    accept="image/jpeg,image/png,image/gif,image/webp,image/avif"
                    className="sr-only"
                    aria-label="Upload image file"
                    disabled={busy}
                    onChange={(e) => {
                        const image = e.target.files?.[0];
                        if (image) void upload(image);
                    }}
                />
                {busy && (
                    <div role="status">
                        <progress aria-label="Upload progress" value={progress} max={100} />
                        <p>{progress < 100 ? `Uploading ${progress}%…` : 'Preparing image…'}</p>
                    </div>
                )}
                {error && !selected && <Notice tone="error">{error}</Notice>}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {can['page.delete'] ? (
                        <StatusTabs<'library' | 'trash'>
                            label="Library or Trash"
                            value={library.status}
                            onChange={(status) =>
                                router.get(
                                    '/admin/media',
                                    { q: query, sort, ...(status === 'trash' ? { status } : {}) },
                                    { preserveState: true, replace: true },
                                )
                            }
                            tabs={[
                                { value: 'library', label: 'Library', count: library.counts.library },
                                { value: 'trash', label: 'Trash', count: library.counts.trash },
                            ]}
                        />
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="sr-only" htmlFor="media-search">
                            Search images
                        </label>
                        <input
                            id="media-search"
                            type="search"
                            className="ui-input w-full sm:w-72"
                            value={query}
                            maxLength={120}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search title, filename, alt text or caption"
                        />
                        <label className="sr-only" htmlFor="media-sort">
                            Sort
                        </label>
                        <select id="media-sort" className="ui-input w-36" value={sort} onChange={(e) => setSort(e.target.value)}>
                            {[
                                ['newest', 'Newest first'],
                                ['oldest', 'Oldest first'],
                                ['name', 'Name'],
                                ['largest', 'Largest'],
                                ['smallest', 'Smallest'],
                            ].map(([v, n]) => (
                                <option key={v} value={v}>
                                    {n}
                                </option>
                            ))}
                        </select>
                        <Segmented<View>
                            label="View"
                            value={view}
                            onChange={changeView}
                            options={[
                                { value: 'grid', label: 'Grid', icon: 'grid', hideLabel: true },
                                { value: 'list', label: 'List', icon: 'list', hideLabel: true },
                            ]}
                        />
                    </div>
                </div>
                <BulkBar selection={selection} noun={['image', 'images']}>
                    {inTrash ? (
                        <Button size="sm" icon="undo" onClick={() => void restoreAssets(selectedAssets)}>
                            Restore
                        </Button>
                    ) : (
                        <Button size="sm" variant="quiet-danger" icon="trash" onClick={() => void askTrash(selectedAssets)}>
                            Move to Trash
                        </Button>
                    )}
                </BulkBar>
                {library.items.length === 0 ? (
                    <ListEmpty
                        icon={inTrash ? 'trash' : 'image'}
                        title={query ? 'No matching images' : inTrash ? 'Trash is empty' : 'Your image library starts here'}
                    >
                        {query
                            ? 'Try another title, filename or description.'
                            : inTrash
                              ? 'Images you move to the Trash appear here. Pages that use them keep working.'
                              : 'Upload your first image, then choose it in the builder.'}
                    </ListEmpty>
                ) : view === 'grid' ? (
                    <div className="space-y-2">
                        {selectable && (
                            <label className="inline-flex items-center gap-2 text-sm text-muted">
                                <SelectAllCheckbox selection={selection} label="Select all images on this page" />
                                Select all on this page
                            </label>
                        )}
                        <ul
                            className="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-3 sm:grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))]"
                            data-testid="media-grid"
                        >
                            {library.items.map((a) => (
                                <li key={a.id} className="group relative" data-testid="media-item">
                                    <button
                                        type="button"
                                        aria-label={`Open ${a.title}`}
                                        onClick={() => void open(a)}
                                        className={`block w-full overflow-hidden rounded-lg border bg-surface text-left transition-[border-color,box-shadow] duration-100 hover:border-line-strong hover:shadow-raise focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ${selection.has(a.id) ? 'border-accent ring-1 ring-accent' : 'border-line'}`}
                                    >
                                        <span className="flex aspect-square items-center justify-center bg-raised p-2">
                                            <Thumb asset={a} sizes="(min-width: 640px) 10rem, 45vw" />
                                        </span>
                                        <span className="block border-t border-line px-2.5 py-2">
                                            <span className="block truncate text-ui font-medium" title={a.title}>
                                                {a.title}
                                            </span>
                                            <span className="block truncate text-2xs text-muted t-num">
                                                {a.width} × {a.height} · {a.mime.replace('image/', '').toUpperCase()}
                                            </span>
                                        </span>
                                    </button>
                                    {selectable && (
                                        <span
                                            className={`absolute top-1.5 left-1.5 grid size-7 place-items-center rounded-md bg-surface/90 shadow-hairline transition-opacity ${selection.count || selection.has(a.id) ? 'opacity-100' : 'opacity-0 group-focus-within:opacity-100 group-hover:opacity-100'}`}
                                        >
                                            <Checkbox checked={selection.has(a.id)} onChange={() => selection.toggle(a.id)} label={`Select ${a.title}`} />
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-line bg-surface shadow-hairline">
                        <table className="w-full min-w-[44rem] text-left text-sm" data-testid="media-list">
                            <caption className="sr-only">{inTrash ? 'Images in the Trash' : 'Images'}</caption>
                            <thead className="border-b border-line">
                                <tr className="t-eyebrow">
                                    {selectable && (
                                        <th scope="col" className="w-10 py-2.5 pl-4">
                                            <SelectAllCheckbox selection={selection} label="Select all images on this page" />
                                        </th>
                                    )}
                                    <th scope="col" className="w-16 py-2.5 pl-4">
                                        <span className="sr-only">Preview</span>
                                    </th>
                                    <th scope="col" className="py-2.5 pr-4">
                                        Name
                                    </th>
                                    <th scope="col" className="py-2.5 pr-4">
                                        Type
                                    </th>
                                    <th scope="col" className="py-2.5 pr-4">
                                        Dimensions
                                    </th>
                                    <th scope="col" className="py-2.5 pr-4 text-right">
                                        Size
                                    </th>
                                    <th scope="col" className="py-2.5 pr-4">
                                        Optimized
                                    </th>
                                    <th scope="col" className="py-2.5 pr-4">
                                        Uploaded
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {library.items.map((a) => (
                                    <tr
                                        key={a.id}
                                        className={`border-b border-line last:border-0 ${selection.has(a.id) ? 'bg-accent-soft/50' : 'hover:bg-hover'}`}
                                        data-testid="media-item"
                                    >
                                        {selectable && (
                                            <td className="py-2 pl-4">
                                                <Checkbox checked={selection.has(a.id)} onChange={() => selection.toggle(a.id)} label={`Select ${a.title}`} />
                                            </td>
                                        )}
                                        <td className="py-2 pl-4">
                                            <span className="grid size-10 place-items-center overflow-hidden rounded-md bg-raised">
                                                <Thumb asset={a} sizes="40px" />
                                            </span>
                                        </td>
                                        <th scope="row" className="max-w-0 py-2 pr-4 text-left font-normal">
                                            <button
                                                type="button"
                                                onClick={() => void open(a)}
                                                className="block max-w-full truncate font-medium text-fg hover:text-accent"
                                                title={a.title}
                                            >
                                                {a.title}
                                            </button>
                                            <span className="block truncate text-xs text-muted" title={a.originalName}>
                                                {a.originalName}
                                            </span>
                                        </th>
                                        <td className="py-2 pr-4 text-muted">{a.mime.replace('image/', '').toUpperCase()}</td>
                                        <td className="py-2 pr-4 whitespace-nowrap text-muted t-num">
                                            {a.width} × {a.height}
                                        </td>
                                        <td className="py-2 pr-4 text-right whitespace-nowrap text-muted t-num">{bytes(a.bytes)}</td>
                                        <td className="py-2 pr-4 whitespace-nowrap">
                                            {a.optimization.count > 0 ? (
                                                <span className="inline-flex items-center gap-1 text-live">
                                                    <Icon name="check" className="size-3.5" />
                                                    WebP
                                                </span>
                                            ) : (
                                                <span className="text-muted">Original only</span>
                                            )}
                                        </td>
                                        <td className="py-2 pr-4 whitespace-nowrap text-muted">
                                            <time dateTime={a.createdAt} title={fullDate(a.createdAt)}>
                                                {relativeTime(a.createdAt)}
                                            </time>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="t-meta t-num">
                        {plural(library.total, 'image')}
                        {!inTrash && ' · Images remain private until used on a live page.'}
                    </p>
                    {library.pages > 1 && (
                        <div className="flex items-center gap-3">
                            <Button
                                size="sm"
                                disabled={library.page <= 1}
                                onClick={() =>
                                    router.get('/admin/media', { q: query, sort, status: library.status, page: library.page - 1 }, { preserveState: true })
                                }
                            >
                                Previous
                            </Button>
                            <span className="t-meta t-num">
                                Page {library.page} of {library.pages}
                            </span>
                            <Button
                                size="sm"
                                disabled={library.page >= library.pages}
                                onClick={() =>
                                    router.get('/admin/media', { q: query, sort, status: library.status, page: library.page + 1 }, { preserveState: true })
                                }
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </div>
                <p className="text-xs text-muted">{uploadHelp()}</p>
            </div>
            <ConfirmDialog
                open={!!trashing}
                title={
                    trashing
                        ? trashing.assets.length === 1
                            ? `Move “${trashing.assets[0]!.title}” to Trash?`
                            : `Move ${plural(trashing.assets.length, 'image')} to Trash?`
                        : ''
                }
                confirmLabel="Move to Trash"
                busyLabel="Moving to Trash…"
                onClose={() => setTrashing(null)}
                onConfirm={() =>
                    trashing
                        ? act(
                              'trash',
                              trashing.assets.map((a) => ({ id: a.id, version: a.version })),
                          )
                        : Promise.resolve(null)
                }
            >
                {trashing && <UsageNote usage={trashing.usage} count={trashing.assets.length} />}
                <p>They leave the library and image choices. Pages and history that use them keep working, and you can restore them from the Trash.</p>
            </ConfirmDialog>
            <dialog
                ref={dialog}
                aria-labelledby="media-detail-title"
                className="ak-scroll m-auto max-h-[92dvh] w-[min(70rem,96vw)] rounded-xl border border-line bg-surface p-0 text-fg shadow-pop backdrop:bg-scrim"
                onCancel={(e) => {
                    e.preventDefault();
                    close();
                }}
            >
                {selected && fields && (
                    <>
                        <div className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 border-b border-line bg-surface p-4">
                            <h2 id="media-detail-title" className="t-section">
                                Image details
                            </h2>
                            <div className="flex gap-2">
                                <Button disabled={unsaved || saving || index <= 0} onClick={() => void open(library.items[index - 1]!)}>
                                    Previous image
                                </Button>
                                <Button
                                    disabled={unsaved || saving || index < 0 || index >= library.items.length - 1}
                                    onClick={() => void open(library.items[index + 1]!)}
                                >
                                    Next image
                                </Button>
                                <Button disabled={unsaved || saving} onClick={close}>
                                    Close
                                </Button>
                            </div>
                        </div>
                        <div className="grid gap-6 p-4 sm:p-6 lg:grid-cols-2">
                            <div className="min-w-0 space-y-4">
                                <div className="flex h-64 items-center justify-center rounded-lg bg-sunken p-4 sm:h-96">
                                    <img src={selected.previewUrl} alt={fields.alt} className="max-h-full max-w-full object-contain" />
                                </div>
                                <a href={selected.url} target="_blank" rel="noopener" className="ui-link text-ui">
                                    Open full-size image
                                </a>
                                <details className="rounded-lg border border-line p-4" open>
                                    <summary className="cursor-pointer font-medium">File and optimization details</summary>
                                    <dl className="mt-3 space-y-2 text-sm">
                                        <div>
                                            <dt className="text-muted">Original filename</dt>
                                            <dd className="break-all">{selected.originalName}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted">Original file</dt>
                                            <dd>
                                                {selected.mime} · {bytes(selected.bytes)} · {selected.width} × {selected.height}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted">Uploaded</dt>
                                            <dd>{new Date(selected.createdAt).toLocaleString()}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted">Delivery</dt>
                                            <dd>
                                                {selected.optimization.status}
                                                {selected.optimization.previewBytes !== null && ` · Preview ${bytes(selected.optimization.previewBytes)}`}
                                            </dd>
                                            <dd>{selected.optimization.count} responsive sizes recorded</dd>
                                        </div>
                                    </dl>
                                </details>
                            </div>
                            <div className="min-w-0 space-y-4">
                                {loadingDetails && (
                                    <p role="status" className="flex items-center gap-2 text-ui text-muted">
                                        <Spinner />
                                        Loading image details…
                                    </p>
                                )}
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        void save();
                                    }}
                                    className="space-y-4"
                                >
                                    {(['title', 'alt', 'caption', 'description'] as const).map((k) => (
                                        <label key={k} className="block text-sm font-medium">
                                            {{ title: 'Title', alt: 'Alt text', caption: 'Default caption', description: 'Description' }[k]}
                                            <textarea
                                                className="ui-input w-full"
                                                rows={k === 'description' ? 3 : 2}
                                                aria-label={{ title: 'Title', alt: 'Alt text', caption: 'Default caption', description: 'Description' }[k]}
                                                value={fields[k]}
                                                maxLength={{ title: 200, alt: 300, caption: 300, description: 2000 }[k]}
                                                required={k === 'title'}
                                                disabled={readonly}
                                                onChange={(e) => setFields({ ...fields, [k]: e.target.value })}
                                            />
                                            {k === 'alt' && (
                                                <span className="mt-1 block text-xs font-normal text-muted">
                                                    Describe the image for visitors who cannot see it. Leave empty for a decorative image. This is a default for
                                                    new placements; existing page descriptions stay as saved.
                                                </span>
                                            )}
                                            {k === 'caption' && (
                                                <span className="mt-1 block text-xs font-normal text-muted">
                                                    A suggested caption for new Image blocks; each placement can use its own caption.
                                                </span>
                                            )}
                                        </label>
                                    ))}
                                    {can['media.upload'] && (
                                        <div className="flex flex-wrap items-center gap-3">
                                            <Button
                                                type="submit"
                                                variant="primary"
                                                busy={saving}
                                                disabled={saving || loadingDetails || selected.archived || (!dirty && !intent.current)}
                                            >
                                                Save details
                                            </Button>
                                            {dirty && !intent.current && !saving && (
                                                <Button
                                                    onClick={() =>
                                                        setFields({
                                                            title: selected.title,
                                                            alt: selected.alt,
                                                            caption: selected.caption,
                                                            description: selected.description,
                                                        })
                                                    }
                                                >
                                                    Discard changes
                                                </Button>
                                            )}
                                            <span role="status" className="text-xs text-muted">
                                                {saving ? 'Saving…' : message || (unsaved ? 'Unsaved details' : '')}
                                            </span>
                                        </div>
                                    )}
                                    {error && <Notice tone="error">{error}</Notice>}
                                </form>
                                <div className="space-y-2 border-t border-line pt-4">
                                    <label className="block text-sm font-medium">
                                        File URL
                                        <input
                                            readOnly
                                            className="ui-input mt-1 w-full text-xs"
                                            value={new URL(selected.url, site?.url || window.location.origin).href}
                                        />
                                    </label>
                                    <Button onClick={() => void copyImageUrl(selected.url)}>Copy URL</Button>
                                    <p className="text-xs text-muted">Permanent original asset URL. Access stays private until a live page uses this image.</p>
                                </div>
                                <section className="space-y-3 border-t border-line pt-4" aria-labelledby="webp-links-title">
                                    <h3 id="webp-links-title" className="text-sm font-medium">
                                        Optimized WebP links
                                    </h3>
                                    <p className="text-xs text-muted">
                                        Choose a size to copy. These links follow the original image’s privacy rules. The builder selects responsive sizes
                                        automatically.
                                    </p>
                                    {selected.webpVariants.length ? (
                                        selected.webpVariants.map((variant) => (
                                            <div key={variant.url} className="space-y-2 rounded-lg border border-line p-3">
                                                <p className="text-xs font-medium">
                                                    {variant.width} × {variant.height} px · WebP · {bytes(variant.bytes)}
                                                </p>
                                                <input
                                                    aria-label={`WebP URL (${variant.width}px)`}
                                                    readOnly
                                                    className="ui-input w-full text-xs"
                                                    value={new URL(variant.url, site?.url || window.location.origin).href}
                                                />
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <Button onClick={() => void copyImageUrl(variant.url, true)}>Copy {variant.width}px WebP URL</Button>
                                                    <a
                                                        href={new URL(variant.url, site?.url || window.location.origin).href}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="ui-link text-xs"
                                                        aria-label={`Open ${variant.width}px WebP image`}
                                                    >
                                                        Open image
                                                    </a>
                                                </div>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-xs text-muted">No optimized WebP copies are available for this image.</p>
                                    )}
                                </section>
                                {selected.usage && (
                                    <div className="space-y-2 border-t border-line pt-4 text-sm">
                                        <h3 className="t-title">Where it is used</h3>
                                        {(
                                            [
                                                ['Live pages', selected.usage.livePages],
                                                ['Page drafts', selected.usage.draftPages],
                                                ['Reusable component drafts', selected.usage.components],
                                            ] as const
                                        ).map(([name, items]) => (
                                            <p key={name}>
                                                {name}: {items.length ? items.slice(0, 20).join(', ') + (items.length > 20 ? '…' : '') : 'None found'}
                                            </p>
                                        ))}
                                        <p className="text-xs text-muted">
                                            Usage lists are limited to 20 per category. History and other saved references retain their files.
                                        </p>
                                    </div>
                                )}
                                {can['page.delete'] && !selected.archived && (
                                    <details className="border-t border-line pt-4">
                                        <summary className="cursor-pointer text-sm text-danger">Move to Trash</summary>
                                        <p className="my-3 text-sm text-muted">
                                            Remove it from the library and future image choices. Pages and history that use it keep working, and you can restore
                                            it from the Trash.
                                        </p>
                                        {confirmRemove ? (
                                            <div className="flex flex-wrap gap-2">
                                                <Button variant="danger" disabled={saving || unsaved} onClick={() => void remove()}>
                                                    Move to Trash
                                                </Button>
                                                <Button disabled={saving} onClick={() => setConfirmRemove(false)}>
                                                    Cancel
                                                </Button>
                                            </div>
                                        ) : (
                                            <Button variant="quiet-danger" disabled={saving || unsaved} onClick={() => setConfirmRemove(true)}>
                                                Move to Trash…
                                            </Button>
                                        )}
                                    </details>
                                )}
                            </div>
                        </div>
                    </>
                )}
            </dialog>
        </AdminLayout>
    );
}

/** A grid or list thumbnail from the small WebP sizes; the browser picks one for the tile and screen density. */
function Thumb({ asset, sizes }: { asset: Asset; sizes: string }) {
    return (
        <img
            src={asset.thumbUrl}
            srcSet={asset.thumbSrcset || undefined}
            sizes={asset.thumbSrcset ? sizes : undefined}
            alt=""
            loading="lazy"
            decoding="async"
            width={asset.width}
            height={asset.height}
            className="max-h-full max-w-full object-contain"
        />
    );
}

function UsageNote({ usage, count }: { usage: Usage | null; count: number }) {
    if (!usage) return <p>Where these images are used could not be checked.</p>;
    const parts = [
        usage.livePages && `${plural(usage.livePages, 'image')} on live pages`,
        usage.drafts && `${plural(usage.drafts, 'image')} in page drafts`,
        usage.components && `${plural(usage.components, 'image')} in reusable components`,
    ].filter(Boolean);
    if (!parts.length) return <p>{count === 1 ? 'This image is' : 'These images are'} not used on any page or component.</p>;
    return (
        <p className="flex gap-2 text-fg">
            <Icon name="alert" className="mt-0.5 size-4 shrink-0 text-changed" />
            <span>In use: {parts.join(', ')}.</span>
        </p>
    );
}
