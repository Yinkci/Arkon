import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { api } from '@/lib/api';
import type { PageRow, PageStatus } from '@/types';
import { ConfirmDialog } from './ConfirmDialog';
import { fullDate, relativeTime } from '@/lib/time';

export const STATUS_LABEL: Record<PageStatus, string> = {
    draft: 'Not published',
    published: 'Published',
    changed: 'Unpublished changes',
};

/** Draft and live in one glyph: a ring (not live), half filled (live, draft ahead), filled (live, in sync). */
export function StatusMark({ status, className = '' }: { status: PageStatus; className?: string }) {
    const color = status === 'published' ? 'text-live' : status === 'changed' ? 'text-changed' : 'text-draft';
    return (
        <svg aria-hidden="true" viewBox="0 0 10 10" className={`size-2.5 shrink-0 ${color} ${className}`}>
            <circle cx="5" cy="5" r="4" fill={status === 'published' ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="1.5" />
            {status === 'changed' && <path d="M5 1a4 4 0 0 1 0 8z" fill="currentColor" />}
        </svg>
    );
}

const action = 'rounded px-1.5 py-1 text-muted hover:bg-line/60 hover:text-fg';

export function PagesTable({ pages, canPublish, canDelete }: { pages: PageRow[]; canPublish: boolean; canDelete: boolean }) {
    return (
        <table className="w-full text-sm">
            <thead className="text-left text-xs text-muted max-sm:sr-only">
                <tr className="border-b border-line">
                    <th scope="col" className="w-full py-2 pr-4 font-normal">
                        Page
                    </th>
                    <th scope="col" className="min-w-44 py-2 pr-4 font-normal">
                        Status
                    </th>
                    <th scope="col" className="hidden min-w-32 py-2 pr-4 font-normal md:table-cell">
                        Last published
                    </th>
                    <th scope="col" className="py-2">
                        <span className="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                {pages.map((page) => (
                    <tr
                        key={page.id}
                        className="border-b border-line last:border-b-0 max-sm:flex max-sm:flex-wrap max-sm:items-center max-sm:gap-x-3 max-sm:gap-y-1 max-sm:py-3"
                        data-testid="page-row"
                        data-path={page.path}
                    >
                        <td className="py-3 pr-4 align-middle max-sm:basis-full max-sm:py-0">
                            <p className="font-medium">{page.title}</p>
                            <p className="mt-0.5 text-xs text-muted">
                                {page.path}
                                {page.livePath && page.livePath !== page.path && <span className="ml-1.5 text-changed">live at {page.livePath}</span>}
                            </p>
                        </td>
                        <td className="py-3 pr-4 align-middle whitespace-nowrap max-sm:py-0">
                            <span className="inline-flex items-center gap-1.5 text-xs text-muted">
                                <StatusMark status={page.status} />
                                {STATUS_LABEL[page.status]}
                            </span>
                        </td>
                        <td className="hidden py-3 pr-4 align-middle text-xs whitespace-nowrap text-muted tabular-nums md:table-cell">
                            {page.publishedAt ? (
                                <time dateTime={page.publishedAt} title={fullDate(page.publishedAt)}>
                                    {relativeTime(page.publishedAt)}
                                </time>
                            ) : (
                                <span className="text-faint">Never</span>
                            )}
                        </td>
                        <td className="py-3 align-middle max-sm:ml-auto max-sm:py-0">
                            <div className="flex items-center justify-end gap-1 text-xs whitespace-nowrap">
                                {page.livePath && (
                                    <a href={page.livePath} target="_blank" rel="noreferrer" className={action}>
                                        View live
                                    </a>
                                )}
                                <PageRowActions page={page} canPublish={canPublish} canDelete={canDelete} />
                                <Link
                                    href={`/admin/editor/${page.id}`}
                                    className="ml-1 rounded-md border border-line-strong bg-surface px-2.5 py-1 font-medium shadow-hairline hover:border-faint"
                                >
                                    Edit
                                </Link>
                            </div>
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
                <button type="button" onClick={() => setDialog('unpublish')} className={action}>
                    Unpublish
                </button>
            )}
            {canDelete && (
                <button type="button" onClick={() => setDialog('delete')} className="rounded px-1.5 py-1 text-danger hover:bg-danger-soft">
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
