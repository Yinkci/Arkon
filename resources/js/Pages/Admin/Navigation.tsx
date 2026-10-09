import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { AdminLayout } from '@/Components/AdminLayout';
import { api, newRequestKey } from '@/lib/api';
type Item = { id: string; label: string; type: 'page' | 'url' | 'section'; pageId: string | null; href: string; anchor: string; parentId: string | null };
type Definition = { name: string; items: Item[] };
type Menu = { id: string; version: number; publishedVersion: number | null; warnings?: string[]; definition: Definition };
type Props = {
    menus: Menu[];
    pages: { id: string; title: string; path: string; anchors?: string[] }[];
    layout: { header_id: string | null; footer_id: string | null } | null;
    permissions: { edit: boolean; publish: boolean };
};
const input = 'w-full rounded-lg border border-line bg-surface p-2 text-sm';
export default function Navigation(props: Props) {
    const [selected, setSelected] = useState<Menu | null>(props.menus[0] ?? null),
        [draft, setDraft] = useState<Definition>(structuredClone(props.menus[0]?.definition ?? { name: 'Main navigation', items: [] })),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [uncertain, setUncertain] = useState(false);
    const attempt = useRef<{ path: string; body: unknown } | null>(null);
    const layoutKey = useRef<string | null>(null);
    const request = async (path: string, body: Record<string, unknown>) => {
        if (busy) return;
        const a = attempt.current ?? { path, body: { ...body, requestKey: newRequestKey() } };
        attempt.current = a;
        setBusy(true);
        try {
            const r = await api<{ id: string; version?: number; publishedVersion?: number }>(a.path, { body: a.body });
            if (!r.ok) {
                setNotice(r.message);
                attempt.current = null;
                setUncertain(false);
                return;
            }
            attempt.current = null;
            setUncertain(false);
            setNotice(a.path.endsWith('/save') ? 'Menu draft saved. Publish when ready.' : 'Menu published. Dependent pages are refreshed.');
            setSelected({
                ...selected,
                id: r.data.id,
                version: r.data.version ?? selected?.version ?? 0,
                publishedVersion: r.data.publishedVersion ?? selected?.publishedVersion ?? null,
                definition: structuredClone(draft),
            });
            router.reload({ only: ['menus'] });
        } catch {
            setUncertain(true);
            setNotice('Outcome could not be confirmed. Retry to check the same request; edits are locked until confirmed.');
        } finally {
            setBusy(false);
        }
    };
    const locked = busy || uncertain || !props.permissions.edit;
    const update = (i: number, patch: Partial<Item>) => setDraft((d) => ({ ...d, items: d.items.map((v, n) => (n === i ? { ...v, ...patch } : v)) }));
    const move = (i: number, to: number) =>
        setDraft((d) => {
            const items = [...d.items];
            const [item] = items.splice(i, 1);
            items.splice(to, 0, item!);
            return { ...d, items };
        });
    const save = () => request('/navigation/save', { id: selected?.id ?? null, baseVersion: selected?.version ?? 0, definition: structuredClone(draft) });
    const dirty = JSON.stringify(draft) !== JSON.stringify(selected?.definition);
    useEffect(() => {
        const warn = (e: BeforeUnloadEvent) => {
            if (dirty || uncertain) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty, uncertain]);
    const layout = async () => {
        if (dirty && !confirm('Discard unsaved menu changes and prepare a layout proposal?')) return;
        setBusy(true);
        try {
            const r = await api('/website/layout', { body: { requestKey: (layoutKey.current ??= newRequestKey()) } });
            if (!r.ok) setNotice(r.message);
            else router.visit('/admin/website');
        } catch {
            setNotice('Layout preparation could not be confirmed. Check Build a website before retrying.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <AdminLayout>
            <Head title="Navigation" />
            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <AdminPageHeader title="Navigation" description="Manage links once. Your header, footer and themes use these shared menus." />
                <section className="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-raised p-4">
                    <strong>Shared layout</strong>
                    {props.layout?.header_id && (
                        <Link className="text-accent" href={'/admin/components/' + props.layout.header_id}>
                            Edit header
                        </Link>
                    )}
                    {props.layout?.footer_id && (
                        <Link className="text-accent" href={'/admin/components/' + props.layout.footer_id}>
                            Edit footer
                        </Link>
                    )}
                    {props.permissions.edit && (
                        <button className="rounded border border-line px-3 py-2 text-sm" disabled={busy || uncertain} onClick={() => void layout()}>
                            Prepare missing header and footer
                        </button>
                    )}
                    <span className="text-xs text-muted">Creates a reviewable proposal locally. Uses no Claude allowance.</span>
                </section>
                {notice && (
                    <p role="status" className="rounded-lg border border-line bg-raised p-3 text-sm">
                        {notice}
                    </p>
                )}
                <div className="grid gap-6 md:grid-cols-[230px_1fr]">
                    <aside className="space-y-2">
                        {props.menus.map((m) => (
                            <button
                                key={m.id}
                                className={'w-full rounded-lg border border-line p-3 text-left ' + (selected?.id === m.id ? 'bg-sunken' : 'bg-raised')}
                                disabled={busy || uncertain}
                                onClick={() => {
                                    if (dirty && !confirm('Discard unsaved menu changes?')) return;
                                    setSelected(m);
                                    setDraft(structuredClone(m.definition));
                                }}
                            >
                                {m.definition.name}
                                <span className="block text-xs text-muted">
                                    Draft {m.version} · {m.publishedVersion ? 'Published ' + m.publishedVersion : 'Unpublished'}
                                </span>
                            </button>
                        ))}
                        {props.permissions.edit && (
                            <button
                                className="w-full rounded-lg border border-line p-3"
                                disabled={busy || uncertain}
                                onClick={() => {
                                    if (dirty && !confirm('Discard unsaved menu changes?')) return;
                                    setSelected(null);
                                    setDraft({ name: 'New menu', items: [] });
                                }}
                            >
                                New menu
                            </button>
                        )}
                    </aside>
                    <section className="space-y-4 rounded-xl border border-line bg-raised p-5">
                        <label className="block text-sm">
                            Menu name
                            <input
                                className={input}
                                maxLength={100}
                                disabled={locked}
                                value={draft.name}
                                onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))}
                            />
                        </label>
                        {draft.items.map((item, i) => (
                            <div key={item.id} className="space-y-3 rounded-lg border border-line p-4">
                                <div className="flex items-center justify-between">
                                    <strong className="text-sm">
                                        {item.parentId ? 'Dropdown item' : 'Menu item'} {i + 1}
                                    </strong>
                                    <div className="flex gap-2">
                                        <button disabled={locked || i === 0} aria-label={'Move ' + item.label + ' up'} onClick={() => move(i, i - 1)}>
                                            ↑
                                        </button>
                                        <button
                                            disabled={locked || i === draft.items.length - 1}
                                            aria-label={'Move ' + item.label + ' down'}
                                            onClick={() => move(i, i + 1)}
                                        >
                                            ↓
                                        </button>
                                        <button
                                            disabled={locked}
                                            className="text-red-600 text-sm"
                                            onClick={() =>
                                                setDraft((d) => ({
                                                    ...d,
                                                    items: d.items
                                                        .filter((v) => v.id !== item.id)
                                                        .map((v) => (v.parentId === item.id ? { ...v, parentId: null } : v)),
                                                }))
                                            }
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>
                                <label className="block text-sm">
                                    Label
                                    <input className={input} disabled={locked} value={item.label} onChange={(e) => update(i, { label: e.target.value })} />
                                </label>
                                <label className="block text-sm">
                                    Destination type
                                    <select
                                        className={input}
                                        disabled={locked}
                                        value={item.type}
                                        onChange={(e) => update(i, { type: e.target.value as Item['type'] })}
                                    >
                                        <option value="page">Page</option>
                                        <option value="section">Page section</option>
                                        <option value="url">Custom link</option>
                                    </select>
                                </label>
                                {item.type === 'url' ? (
                                    <label className="block text-sm">
                                        Link
                                        <input
                                            aria-label="Link"
                                            className={input}
                                            disabled={locked}
                                            value={item.href}
                                            onChange={(e) => update(i, { href: e.target.value })}
                                        />
                                        {item.href === '#' && <p className="text-xs text-amber-700">Placeholder destination</p>}
                                    </label>
                                ) : (
                                    <label className="block text-sm">
                                        Page
                                        <select
                                            className={input}
                                            disabled={locked}
                                            value={item.pageId ?? ''}
                                            onChange={(e) => update(i, { pageId: e.target.value || null })}
                                        >
                                            <option value="">Choose a page</option>
                                            {props.pages.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.title} · {p.path}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                                {item.type === 'section' && (
                                    <label className="block text-sm">
                                        Section anchor
                                        <input
                                            className={input}
                                            disabled={locked}
                                            aria-label="Section anchor"
                                            list={'anchors-' + item.id}
                                            placeholder="services"
                                            value={item.anchor}
                                            onChange={(e) => update(i, { anchor: e.target.value })}
                                        />
                                        <datalist id={'anchors-' + item.id}>
                                            {props.pages
                                                .find((p) => p.id === item.pageId)
                                                ?.anchors?.map((a) => (
                                                    <option key={a} value={a} />
                                                ))}
                                        </datalist>
                                        <span className="text-xs text-muted">Set the matching section anchor in the page builder.</span>
                                    </label>
                                )}
                                <label className="block text-sm">
                                    Dropdown parent
                                    <select
                                        className={input}
                                        disabled={locked}
                                        value={item.parentId ?? ''}
                                        onChange={(e) => update(i, { parentId: e.target.value || null })}
                                    >
                                        <option value="">Top-level item</option>
                                        {draft.items
                                            .filter((v) => v.id !== item.id && v.parentId === null && !draft.items.some((c) => c.parentId === item.id))
                                            .map((v) => (
                                                <option key={v.id} value={v.id}>
                                                    {v.label}
                                                </option>
                                            ))}
                                    </select>
                                </label>
                            </div>
                        ))}
                        <button
                            className="rounded border border-line px-3 py-2 text-sm"
                            disabled={locked || draft.items.length >= 50}
                            onClick={() =>
                                setDraft((d) => ({
                                    ...d,
                                    items: [
                                        ...d.items,
                                        {
                                            id:
                                                'item-' +
                                                newRequestKey()
                                                    .replace(/[^a-z0-9]/gi, '')
                                                    .slice(-20),
                                            label: 'New link',
                                            type: 'url',
                                            pageId: null,
                                            href: '#',
                                            anchor: '',
                                            parentId: null,
                                        },
                                    ],
                                }))
                            }
                        >
                            Add menu item
                        </button>
                        {!dirty &&
                            props.menus
                                .find((m) => m.id === selected?.id)
                                ?.warnings?.map((warning, i) => (
                                    <p key={i} className="text-sm text-amber-700">
                                        {warning}
                                    </p>
                                ))}
                        <div className="flex gap-3">
                            <button
                                className="rounded-lg bg-accent px-4 py-2 text-white"
                                disabled={busy || !props.permissions.edit}
                                onClick={() => void save()}
                            >
                                {uncertain ? 'Confirm previous request' : 'Save menu draft'}
                            </button>
                            {selected && props.permissions.publish && (
                                <button
                                    className="rounded border border-line px-4 py-2"
                                    disabled={busy || uncertain || dirty}
                                    onClick={() => {
                                        if (confirm('Publish this menu and refresh its live pages?'))
                                            void request('/navigation/' + selected.id + '/publish', { expectedVersion: selected.version });
                                    }}
                                >
                                    Publish menu
                                </button>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}
