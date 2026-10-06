import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { api } from '@/lib/api';
import type { PageRow, PageStatus } from '@/types';
import { ConfirmDialog } from './ConfirmDialog';

const STATUS: Record<PageStatus, { label: string; className: string }> = {
    draft: { label: 'Not published', className: 'bg-zinc-100 text-zinc-700' },
    published: { label: 'Published', className: 'bg-emerald-50 text-emerald-700' },
    changed: { label: 'Unpublished changes', className: 'bg-amber-50 text-amber-800' },
};

export function PagesTable({ pages, canPublish, canDelete }: { pages: PageRow[]; canPublish: boolean; canDelete: boolean }) {
    return (
        <table className="w-full overflow-hidden rounded-lg border border-zinc-200 bg-white text-sm">
            <thead className="bg-zinc-50 text-left text-xs text-zinc-500">
                <tr>
                    <th className="px-4 py-2 font-medium">Page</th>
                    <th className="px-4 py-2 font-medium">Status</th>
                    <th className="px-4 py-2 font-medium">Last published</th>
                    <th className="px-4 py-2">
                        <span className="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                {pages.map((page) => (
                    <tr key={page.id} className="border-t border-zinc-100" data-testid="page-row" data-path={page.path}>
                        <td className="px-4 py-3">
                            <p className="font-medium">{page.title}</p>
                            <p className="text-xs text-zinc-500">
                                {page.path}
                                {page.livePath && page.livePath !== page.path && <span className="ml-1 text-amber-700">(live at {page.livePath})</span>}
                            </p>
                        </td>
                        <td className="px-4 py-3">
                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS[page.status].className}`}>{STATUS[page.status].label}</span>
                        </td>
                        <td className="px-4 py-3 text-zinc-600">{page.publishedAt ? new Date(page.publishedAt).toLocaleString('en-GB') : '—'}</td>
                        <td className="space-x-3 px-4 py-3 text-right whitespace-nowrap">
                            {page.livePath && (
                                <a href={page.livePath} target="_blank" rel="noreferrer" className="text-zinc-600 hover:underline">
                                    View live
                                </a>
                            )}
                            <PageRowActions page={page} canPublish={canPublish} canDelete={canDelete} />
                            <Link href={`/admin/editor/${page.id}`} className="font-medium text-indigo-600 hover:underline">
                                Edit
                            </Link>
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

const NETWORK = 'The request could not be confirmed (network problem). Reload the page to see the current state, then try again.';

function PageRowActions({ page, canPublish, canDelete }: { page: PageRow; canPublish: boolean; canDelete: boolean }) {
    const [dialog, setDialog] = useState<'unpublish' | 'delete' | null>(null);

    async function run(path: string, body: unknown): Promise<string | null> {
        try {
            const result = await api(path, { body });
            if (!result.ok) return result.message;
            router.reload();
            return null;
        } catch {
            return NETWORK;
        }
    }

    return (
        <>
            {canPublish && page.livePublicationId && (
                <button type="button" onClick={() => setDialog('unpublish')} className="text-zinc-600 hover:underline">
                    Unpublish
                </button>
            )}
            {canDelete && (
                <button type="button" onClick={() => setDialog('delete')} className="text-red-700 hover:underline">
                    Delete
                </button>
            )}

            <ConfirmDialog
                open={dialog === 'unpublish'}
                title={`Unpublish “${page.title}”?`}
                confirmLabel="Unpublish"
                onClose={() => setDialog(null)}
                onConfirm={() => run(`/pages/${page.id}/unpublish`, { expectedPublicationId: page.livePublicationId })}
            >
                <p>
                    Visitors to <strong>{page.livePath}</strong> will get “page not found”, and old URLs that redirect to this page stop working.
                </p>
                <p>The draft and the full history are kept. Publishing again brings the page back.</p>
            </ConfirmDialog>

            <ConfirmDialog
                open={dialog === 'delete'}
                title={`Delete “${page.title}”?`}
                confirmLabel="Delete page"
                tone="danger"
                requireText={page.path}
                onClose={() => setDialog(null)}
                onConfirm={() => run(`/pages/${page.id}/delete`, { expectedVersion: page.version })}
            >
                {page.livePath ? (
                    <p>
                        This page is <strong>live at {page.livePath}</strong>. Deleting it takes it offline immediately.
                    </p>
                ) : (
                    <p>This page is not published.</p>
                )}
                <p>It disappears from the admin and its URL becomes available for another page. Revisions and publication history are kept in the database.</p>
            </ConfirmDialog>
        </>
    );
}
