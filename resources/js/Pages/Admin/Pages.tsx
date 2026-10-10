import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { BulkBar, ListEmpty, StatusTabs, useSelection } from '@/Components/ListManagement';
import { NewPageForm } from '@/Components/NewPageForm';
import { PagesTable, TrashTable, type TrashedPage } from '@/Components/PagesTable';
import { toast } from '@/Components/Toast';
import { Button, ButtonLink } from '@/Components/ui';
import { api } from '@/lib/api';
import { bulk, plural, reloadProps, type BulkAction, type BulkResult } from '@/lib/mutate';
import type { PageRow, SharedProps } from '@/types';

type Tab = 'all' | 'published' | 'draft' | 'changed' | 'trash';
type Confirm =
    | { kind: 'trash' | 'purge'; pages: { id: string; title: string; version?: number; livePath?: string | null }[] }
    | { kind: 'unpublish'; page: PageRow }
    | null;

const NOUN: [string, string] = ['page', 'pages'];

export default function Pages({ pages, trash }: { pages: PageRow[]; trash: TrashedPage[] }) {
    const page = usePage<SharedProps>();
    const { can } = page.props;
    const canDelete = !!can['page.delete'];
    const params = new URLSearchParams(page.url.split('?')[1] ?? '');
    const [creating, setCreating] = useState(params.get('new') === '1');
    const [tab, setTab] = useState<Tab>(() => {
        const status = params.get('status') as Tab | null;
        return status && ['published', 'draft', 'changed', 'trash'].includes(status) && (status !== 'trash' || canDelete) ? status : 'all';
    });
    const [query, setQuery] = useState('');
    const [confirm, setConfirm] = useState<Confirm>(null);
    const matches = (p: { title: string; path: string }) => (p.title + ' ' + p.path).toLowerCase().includes(query.toLowerCase());
    const inTrash = tab === 'trash';
    const shown = pages.filter((p) => (tab === 'all' || p.status === tab) && matches(p));
    const shownTrash = trash.filter(matches);
    const selection = useSelection((inTrash ? shownTrash : shown).map((p) => p.id));

    // Every list action ends the same way: the server confirms, the props reload, the selection
    // drops what left the list, and a toast says what happened (failures stay specific).
    async function act(action: BulkAction, items: { id: string; version?: number }[]): Promise<string | null> {
        const result: BulkResult = await bulk('pages', action, items, ['pages', 'trash']);
        const done = result.done.length;
        if (done === 0) return result.failed[0]?.message ?? 'Nothing changed.';
        const verb = { trash: 'moved to Trash', restore: 'restored', purge: 'deleted permanently' }[action];
        toast(done === 1 ? `Page ${verb}.` : `${plural(done, ...NOUN)} ${verb}.`);
        if (result.failed.length) toast(`${plural(result.failed.length, ...NOUN)} not changed: ${result.failed[0]!.message}`, 'error');
        selection.clear();
        return null;
    }
    async function restore(items: TrashedPage[]) {
        const error = await act('restore', items).catch(() => 'The outcome could not be confirmed (network problem). Check the Trash, then try again.');
        if (error) toast(error, 'error');
    }

    const counts: Record<Tab, number> = {
        all: pages.length,
        published: pages.filter((p) => p.status === 'published').length,
        draft: pages.filter((p) => p.status === 'draft').length,
        changed: pages.filter((p) => p.status === 'changed').length,
        trash: trash.length,
    };
    const selectedPages = shown.filter((p) => selection.has(p.id));
    const selectedTrash = shownTrash.filter((p) => selection.has(p.id));

    return (
        <AdminLayout>
            <Head title="Pages" />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    title="Pages"
                    description="Manage page drafts and live versions. Open any page in the builder to edit content, layout and SEO."
                    actions={
                        <>
                            {can['page.edit'] && (
                                <ButtonLink href="/admin/website" icon="sparkle">
                                    Generate with AI
                                </ButtonLink>
                            )}
                            {can['page.create'] && (
                                <Button
                                    variant="primary"
                                    icon="plus"
                                    aria-expanded={creating}
                                    aria-controls="new-page-panel"
                                    onClick={() => setCreating((value) => !value)}
                                >
                                    {creating ? 'Close new page' : 'New page'}
                                </Button>
                            )}
                        </>
                    }
                />
                {can['page.create'] && creating && (
                    <div id="new-page-panel">
                        <NewPageForm />
                    </div>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <StatusTabs<Tab>
                        label="Filter pages"
                        value={tab}
                        onChange={(value) => {
                            setTab(value);
                            selection.clear();
                        }}
                        tabs={[
                            { value: 'all', label: 'All', count: counts.all },
                            { value: 'published', label: 'Published', count: counts.published },
                            { value: 'draft', label: 'Not published', count: counts.draft },
                            { value: 'changed', label: 'Unpublished changes', count: counts.changed },
                            ...(canDelete ? [{ value: 'trash' as const, label: 'Trash', count: counts.trash }] : []),
                        ]}
                    />
                    <label className="sr-only" htmlFor="pages-search">
                        Search pages
                    </label>
                    <input
                        id="pages-search"
                        type="search"
                        className="ui-input w-full sm:w-64"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search title or URL"
                    />
                </div>

                <BulkBar selection={selection} noun={NOUN}>
                    {inTrash ? (
                        <>
                            <Button size="sm" icon="undo" onClick={() => void restore(selectedTrash)}>
                                Restore
                            </Button>
                            <Button size="sm" variant="quiet-danger" onClick={() => setConfirm({ kind: 'purge', pages: selectedTrash })}>
                                Delete permanently
                            </Button>
                        </>
                    ) : (
                        <Button size="sm" variant="quiet-danger" icon="trash" onClick={() => setConfirm({ kind: 'trash', pages: selectedPages })}>
                            Move to Trash
                        </Button>
                    )}
                </BulkBar>

                <section aria-label={inTrash ? 'Pages in the Trash' : 'All pages'}>
                    <h2 className="sr-only">{inTrash ? 'Pages in the Trash' : 'All pages'}</h2>
                    {inTrash ? (
                        shownTrash.length === 0 ? (
                            <ListEmpty icon="trash" title={trash.length ? 'No matching pages in the Trash' : 'Trash is empty'}>
                                {trash.length
                                    ? 'Change the search to find a page.'
                                    : 'Pages you move to the Trash appear here until you restore or delete them.'}
                            </ListEmpty>
                        ) : (
                            <div className="rounded-lg border border-line bg-surface px-5 shadow-hairline">
                                <TrashTable
                                    pages={shownTrash}
                                    selection={selection}
                                    onRestore={(items) => void restore(items)}
                                    onPurge={(items) => setConfirm({ kind: 'purge', pages: items })}
                                />
                            </div>
                        )
                    ) : shown.length === 0 ? (
                        <ListEmpty icon="pages" title={pages.length ? 'No matching pages' : 'Create your first page'}>
                            {pages.length
                                ? 'Change the search or filter to find a page.'
                                : can['page.create']
                                  ? 'Choose New page or generate editable drafts with AI.'
                                  : 'Pages created by your team appear here.'}
                        </ListEmpty>
                    ) : (
                        <div className="rounded-lg border border-line bg-surface px-5 shadow-hairline">
                            <PagesTable
                                pages={shown}
                                selection={canDelete ? selection : null}
                                actions={{
                                    canPublish: !!can['page.publish'],
                                    canDelete,
                                    onUnpublish: (p) => setConfirm({ kind: 'unpublish', page: p }),
                                    onTrash: (items) => setConfirm({ kind: 'trash', pages: items }),
                                }}
                            />
                        </div>
                    )}
                </section>
            </div>

            <ConfirmDialog
                open={confirm?.kind === 'trash'}
                title={
                    confirm?.kind === 'trash'
                        ? confirm.pages.length === 1
                            ? `Move “${confirm.pages[0]!.title}” to Trash?`
                            : `Move ${plural(confirm.pages.length, ...NOUN)} to Trash?`
                        : ''
                }
                confirmLabel="Move to Trash"
                busyLabel="Moving to Trash…"
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    confirm?.kind === 'trash'
                        ? act(
                              'trash',
                              confirm.pages.map((p) => ({ id: p.id, version: p.version })),
                          )
                        : Promise.resolve(null)
                }
            >
                {confirm?.kind === 'trash' && <TrashNote pages={confirm.pages} />}
            </ConfirmDialog>

            <ConfirmDialog
                open={confirm?.kind === 'purge'}
                tone="danger"
                title={
                    confirm?.kind === 'purge'
                        ? confirm.pages.length === 1
                            ? `Delete “${confirm.pages[0]!.title}” permanently?`
                            : `Permanently delete ${plural(confirm.pages.length, ...NOUN)}?`
                        : ''
                }
                confirmLabel="Delete permanently"
                busyLabel="Deleting…"
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    confirm?.kind === 'purge'
                        ? act(
                              'purge',
                              confirm.pages.map((p) => ({ id: p.id })),
                          )
                        : Promise.resolve(null)
                }
            >
                <p>
                    This cannot be undone: {confirm?.kind === 'purge' && confirm.pages.length > 1 ? 'they' : 'it'} can’t be restored. Published history stays in
                    Arkon’s audit record.
                </p>
            </ConfirmDialog>

            <ConfirmDialog
                open={confirm?.kind === 'unpublish'}
                title={confirm?.kind === 'unpublish' ? `Unpublish “${confirm.page.title}”?` : ''}
                confirmLabel="Unpublish"
                busyLabel="Unpublishing…"
                onClose={() => setConfirm(null)}
                onConfirm={async () => {
                    if (confirm?.kind !== 'unpublish') return null;
                    const result = await api(`/pages/${confirm.page.id}/unpublish`, { body: { expectedPublicationId: confirm.page.livePublicationId } });
                    if (!result.ok) return result.message;
                    await reloadProps(['pages', 'trash']);
                    toast('Page unpublished.');
                    return null;
                }}
            >
                {confirm?.kind === 'unpublish' && (
                    <>
                        <p>
                            Visitors to <strong>{confirm.page.livePath}</strong> will get “page not found”, and old URLs that redirect to this page stop
                            working.
                        </p>
                        <p>The draft and the full history are kept. Publishing again brings the page back.</p>
                    </>
                )}
            </ConfirmDialog>
        </AdminLayout>
    );
}

function TrashNote({ pages }: { pages: { livePath?: string | null }[] }) {
    const live = pages.filter((p) => p.livePath);
    return (
        <>
            {live.length > 0 && (
                <p>
                    {pages.length === 1 ? (
                        <>
                            It is <strong>live at {live[0]!.livePath}</strong> and goes offline now.
                        </>
                    ) : (
                        <>
                            <strong>{plural(live.length, ...NOUN)}</strong> {live.length === 1 ? 'is' : 'are'} live and go offline now.
                        </>
                    )}
                </p>
            )}
            <p>
                {pages.length === 1 ? 'Its URL becomes' : 'Their URLs become'} free for other pages. You can restore {pages.length === 1 ? 'it' : 'them'} from
                the Trash.
            </p>
        </>
    );
}
