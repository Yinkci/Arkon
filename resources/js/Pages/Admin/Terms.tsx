import { Head, Link, usePage } from '@inertiajs/react';
import { useId, useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Icon } from '@/Components/Icon';
import { ListEmpty, MenuItem, RowMenu } from '@/Components/ListManagement';
import { toast } from '@/Components/Toast';
import { Button } from '@/Components/ui';
import { api } from '@/lib/api';
import { plural, reloadProps } from '@/lib/mutate';
import type { SharedProps, Term } from '@/types';

interface TaxonomyInfo {
    name: string;
    label: string;
    plural: string;
    hierarchical: boolean;
}

const HREF: Record<string, string> = { category: '/admin/posts/categories', tag: '/admin/posts/tags' };
const head = 'h-11 pr-4 t-eyebrow';
const cell = 'py-3 pr-4 align-middle';

/**
 * Categories and tags of posts. Editors add terms; owners and admins rename and delete them,
 * because a term's name shows on published posts at once (terms are not versioned).
 */
export default function Terms({ taxonomy, taxonomies, terms }: { taxonomy: string; taxonomies: TaxonomyInfo[]; terms: Term[] }) {
    const { can } = usePage<SharedProps>().props;
    const info = taxonomies.find((t) => t.name === taxonomy)!;
    const noun: [string, string] = [info.label.toLowerCase(), info.plural.toLowerCase()];
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<Term | null>(null);
    const [deleting, setDeleting] = useState<Term | null>(null);
    const byId = new Map(terms.map((t) => [t.id, t]));
    const shown = terms.filter((t) => (t.name + ' ' + t.slug).toLowerCase().includes(query.toLowerCase()));

    return (
        <AdminLayout>
            <Head title="Categories & tags" />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    title="Categories & tags"
                    description="Group posts so readers (and your site's API) can browse them by topic. Assign them to a post in the builder, under Properties."
                />
                <nav aria-label="Taxonomies" className="flex gap-1">
                    {taxonomies.map((t) => (
                        <Link
                            key={t.name}
                            href={HREF[t.name] ?? '#'}
                            aria-current={t.name === taxonomy ? 'page' : undefined}
                            className={`rounded-md px-3 py-1.5 text-ui ${t.name === taxonomy ? 'bg-accent-soft font-medium text-fg' : 'text-muted hover:bg-hover hover:text-fg'}`}
                        >
                            {t.plural}
                        </Link>
                    ))}
                </nav>

                {can['term.create'] && <TermForm key={taxonomy} taxonomy={info} terms={terms} />}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-ui text-muted">{plural(terms.length, ...noun)}</p>
                    <label className="sr-only" htmlFor="terms-search">
                        Search {noun[1]}
                    </label>
                    <input
                        id="terms-search"
                        type="search"
                        className="ui-input w-full sm:w-64"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search name or slug"
                    />
                </div>

                {shown.length === 0 ? (
                    <ListEmpty icon="tag" title={terms.length ? `No matching ${noun[1]}` : `No ${noun[1]} yet`}>
                        {terms.length ? 'Change the search.' : `Add a ${noun[0]} above, or create one while editing a post.`}
                    </ListEmpty>
                ) : (
                    <div className="rounded-lg border border-line bg-surface px-5 shadow-hairline">
                        <table className="w-full text-sm">
                            <thead className="text-left max-sm:sr-only">
                                <tr className="border-b border-line">
                                    <th scope="col" className={`${head} w-full`}>
                                        Name
                                    </th>
                                    {info.hierarchical && (
                                        <th scope="col" className={`${head} hidden min-w-32 md:table-cell`}>
                                            Parent
                                        </th>
                                    )}
                                    <th scope="col" className={`${head} min-w-24 text-right`}>
                                        Published
                                    </th>
                                    <th scope="col" className={`${head} hidden min-w-20 text-right sm:table-cell`}>
                                        Drafts
                                    </th>
                                    <th scope="col" className="h-11">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((term) =>
                                    editing?.id === term.id ? (
                                        <tr key={term.id} className="border-b border-line last:border-b-0">
                                            <td colSpan={info.hierarchical ? 5 : 4} className="py-3">
                                                <TermForm taxonomy={info} terms={terms} term={term} onDone={() => setEditing(null)} />
                                            </td>
                                        </tr>
                                    ) : (
                                        <tr key={term.id} className="border-b border-line last:border-b-0 hover:bg-hover" data-testid="term-row">
                                            <td className={cell}>
                                                <span className="font-medium">{term.name}</span>
                                                <p className="mt-0.5 font-mono text-xs text-muted">{term.slug}</p>
                                                {term.description && <p className="mt-0.5 text-xs text-muted">{term.description}</p>}
                                            </td>
                                            {info.hierarchical && (
                                                <td className={`${cell} hidden text-muted md:table-cell`}>
                                                    {term.parentId ? (byId.get(term.parentId)?.name ?? '—') : '—'}
                                                </td>
                                            )}
                                            <td className={`${cell} text-right t-num`}>{term.count}</td>
                                            <td className={`${cell} hidden text-right text-muted t-num sm:table-cell`}>{term.draftCount ?? 0}</td>
                                            <td className="py-3 text-right align-middle">
                                                {can['term.manage'] && (
                                                    <RowMenu label={`Actions for ${term.name}`}>
                                                        {(close) => (
                                                            <>
                                                                <MenuItem
                                                                    onSelect={() => {
                                                                        close();
                                                                        setEditing(term);
                                                                    }}
                                                                >
                                                                    Edit
                                                                </MenuItem>
                                                                <MenuItem
                                                                    tone="danger"
                                                                    onSelect={() => {
                                                                        close();
                                                                        setDeleting(term);
                                                                    }}
                                                                >
                                                                    Delete
                                                                </MenuItem>
                                                            </>
                                                        )}
                                                    </RowMenu>
                                                )}
                                            </td>
                                        </tr>
                                    ),
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={deleting !== null}
                tone="danger"
                title={deleting ? `Delete “${deleting.name}”?` : ''}
                confirmLabel="Delete"
                busyLabel="Deleting…"
                onClose={() => setDeleting(null)}
                onConfirm={async () => {
                    if (!deleting) return null;
                    const result = await api(`/terms/${taxonomy}/${deleting.id}/delete`, { body: {} });
                    if (!result.ok) return result.message;
                    await reloadProps(['terms']);
                    toast(`${info.label} deleted.`);
                    return null;
                }}
            >
                <p>
                    It is removed from {deleting?.draftCount ? plural(deleting.draftCount, 'draft', 'drafts') : 'drafts'} at once. Published posts stop listing
                    it
                    {info.hierarchical ? '; its subcategories move up one level' : ''}. Posts themselves are not changed.
                </p>
            </ConfirmDialog>
        </AdminLayout>
    );
}

/** Add a term, or edit one (name, slug, description and, for categories, the parent). */
function TermForm({ taxonomy, terms, term, onDone }: { taxonomy: TaxonomyInfo; terms: Term[]; term?: Term; onDone?: () => void }) {
    const [name, setName] = useState(term?.name ?? '');
    const [slug, setSlug] = useState(term?.slug ?? '');
    const [description, setDescription] = useState(term?.description ?? '');
    const [parentId, setParentId] = useState(term?.parentId ?? '');
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const ids = { name: useId(), slug: useId(), description: useId(), parent: useId(), error: useId() };
    const noun = taxonomy.label.toLowerCase();

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setPending(true);
        setError(null);
        const body = { name, ...(slug ? { slug } : {}), description, ...(taxonomy.hierarchical ? { parentId: parentId || null } : {}) };
        const result = await api(term ? `/terms/${taxonomy.name}/${term.id}` : `/terms/${taxonomy.name}`, { body }).catch(() => null);
        setPending(false);
        if (!result) return setError('The outcome could not be confirmed (network problem). Reload the page before trying again.');
        if (!result.ok) return setError(result.issues?.length ? result.issues.map((i) => i.message).join(' ') : result.message);
        await reloadProps(['terms']);
        toast(term ? `${taxonomy.label} updated.` : `${taxonomy.label} added.`);
        if (term) onDone?.();
        else {
            setName('');
            setSlug('');
            setDescription('');
            setParentId('');
        }
    }

    return (
        <form
            onSubmit={submit}
            className={term ? 'space-y-3' : 'rounded-lg border border-line bg-surface p-4 shadow-hairline'}
            aria-label={term ? `Edit ${term.name}` : `Add a ${noun}`}
        >
            {!term && <h2 className="mb-3 t-title">Add a {noun}</h2>}
            <div className={`grid gap-3 ${taxonomy.hierarchical ? 'sm:grid-cols-4' : 'sm:grid-cols-3'}`}>
                <div>
                    <label htmlFor={ids.name} className="ui-label">
                        Name
                    </label>
                    <input id={ids.name} required maxLength={100} className="ui-input h-9 text-sm" value={name} onChange={(e) => setName(e.target.value)} />
                </div>
                <div>
                    <label htmlFor={ids.slug} className="ui-label">
                        Slug <span className="text-faint">(optional)</span>
                    </label>
                    <input
                        id={ids.slug}
                        maxLength={100}
                        className="ui-input h-9 font-mono text-sm"
                        value={slug}
                        placeholder="from the name"
                        onChange={(e) => setSlug(e.target.value)}
                    />
                </div>
                {taxonomy.hierarchical && (
                    <div>
                        <label htmlFor={ids.parent} className="ui-label">
                            Parent
                        </label>
                        <select id={ids.parent} className="ui-input h-9 text-sm" value={parentId} onChange={(e) => setParentId(e.target.value)}>
                            <option value="">None (top level)</option>
                            {terms
                                .filter((t) => t.id !== term?.id)
                                .map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                        </select>
                    </div>
                )}
                <div>
                    <label htmlFor={ids.description} className="ui-label">
                        Description <span className="text-faint">(optional)</span>
                    </label>
                    <input
                        id={ids.description}
                        maxLength={1000}
                        className="ui-input h-9 text-sm"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                    />
                </div>
            </div>
            <div className="mt-3 flex flex-wrap items-center justify-end gap-2">
                {error && (
                    <p id={ids.error} role="alert" className="mr-auto flex items-start gap-1.5 text-ui text-danger">
                        <Icon name="alert" className="mt-0.5 size-4" />
                        {error}
                    </p>
                )}
                {term && (
                    <Button type="button" onClick={onDone}>
                        Cancel
                    </Button>
                )}
                <Button type="submit" variant="primary" icon={term ? 'check' : 'plus'} busy={pending} disabled={pending || !name.trim()}>
                    {term ? 'Save' : `Add ${noun}`}
                </Button>
            </div>
        </form>
    );
}
