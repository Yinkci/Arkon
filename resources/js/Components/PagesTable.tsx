import { Link } from '@inertiajs/react';
import type { PageRow, PageStatus } from '@/types';
import { fullDate, relativeTime } from '@/lib/time';
import { Icon } from './Icon';
import { Checkbox, MenuItem, RowMenu, SelectAllCheckbox, type Selection } from './ListManagement';
import { buttonClass } from './ui';

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

const action = 'inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-muted hover:bg-hover hover:text-fg';
const head = 'h-11 pr-4 t-eyebrow';
const cell = 'py-3.5 pr-4 align-middle';

export interface PageActions {
    canPublish: boolean;
    canDelete: boolean;
    onUnpublish(page: PageRow): void;
    onTrash(pages: PageRow[]): void;
}

/** The page list: a checkbox per row when bulk actions are allowed, status, last published, and View live / More / Edit. */
export function PagesTable({ pages, selection, actions }: { pages: PageRow[]; selection: Selection | null; actions: PageActions }) {
    return (
        <table className="w-full text-sm">
            <thead className="text-left max-sm:sr-only">
                <tr className="border-b border-line">
                    {selection && (
                        <th scope="col" className="h-11 w-10 pr-2">
                            <SelectAllCheckbox selection={selection} label="Select all pages" />
                        </th>
                    )}
                    <th scope="col" className={`${head} w-full`}>
                        Page
                    </th>
                    <th scope="col" className={`${head} min-w-44`}>
                        Status
                    </th>
                    <th scope="col" className={`${head} hidden min-w-32 md:table-cell`}>
                        Last published
                    </th>
                    <th scope="col" className="h-11">
                        <span className="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                {pages.map((page) => (
                    <tr
                        key={page.id}
                        className={`border-b border-line transition-colors last:border-b-0 max-sm:flex max-sm:flex-wrap max-sm:items-center max-sm:gap-x-3 max-sm:gap-y-1 max-sm:py-3 ${selection?.has(page.id) ? 'bg-accent-soft/50' : 'hover:bg-hover'}`}
                        data-testid="page-row"
                        data-path={page.path}
                    >
                        {selection && (
                            <td className="w-10 py-3.5 pr-2 align-middle max-sm:py-0">
                                <Checkbox checked={selection.has(page.id)} onChange={() => selection.toggle(page.id)} label={`Select ${page.title}`} />
                            </td>
                        )}
                        <td className={`${cell} max-sm:basis-[calc(100%-4rem)] max-sm:py-0`}>
                            <Link href={`/admin/editor/${page.id}`} className="font-medium text-fg hover:text-accent">
                                {page.title}
                            </Link>
                            <p className="mt-0.5 font-mono break-all text-xs text-muted">
                                {page.path}
                                {page.livePath && page.livePath !== page.path && <span className="ml-1.5 text-changed">live at {page.livePath}</span>}
                            </p>
                        </td>
                        <td className={`${cell} whitespace-nowrap max-sm:py-0`}>
                            <span className="inline-flex items-center gap-1.5 text-ui text-muted">
                                <StatusMark status={page.status} />
                                {STATUS_LABEL[page.status]}
                            </span>
                        </td>
                        <td className={`${cell} hidden text-ui whitespace-nowrap text-muted tabular-nums md:table-cell`}>
                            {page.updatedAt && (
                                <p className="text-xs text-faint" title={fullDate(page.updatedAt)}>
                                    Edited {relativeTime(page.updatedAt)}
                                </p>
                            )}
                            {page.publishedAt ? (
                                <time dateTime={page.publishedAt} title={fullDate(page.publishedAt)}>
                                    {relativeTime(page.publishedAt)}
                                </time>
                            ) : (
                                <span className="text-faint">Never</span>
                            )}
                        </td>
                        <td className="py-3.5 align-middle max-sm:ml-auto max-sm:py-0">
                            <div className="flex items-center justify-end gap-1 text-xs whitespace-nowrap">
                                {page.livePath && (
                                    <a href={page.livePath} target="_blank" rel="noreferrer" className={action}>
                                        <Icon name="external" className="size-3.5" />
                                        View live
                                    </a>
                                )}
                                <RowMenu label={`More actions for ${page.title}`}>
                                    {() => (
                                        <>
                                            <MenuItem href={`/preview/${page.id}`} external>
                                                Preview draft
                                            </MenuItem>
                                            {actions.canPublish && page.livePublicationId && (
                                                <MenuItem onSelect={() => actions.onUnpublish(page)}>Unpublish</MenuItem>
                                            )}
                                            {actions.canDelete && (
                                                <MenuItem tone="danger" onSelect={() => actions.onTrash([page])}>
                                                    Move to Trash
                                                </MenuItem>
                                            )}
                                        </>
                                    )}
                                </RowMenu>
                                <Link href={`/admin/editor/${page.id}`} className={buttonClass('secondary', 'sm', 'ml-1')}>
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

export interface TrashedPage {
    id: string;
    title: string;
    path: string;
    version: number;
    deletedAt: string | null;
}

/** Pages in the Trash: when they were moved there, Restore and Delete permanently. */
export function TrashTable({
    pages,
    selection,
    onRestore,
    onPurge,
}: {
    pages: TrashedPage[];
    selection: Selection;
    onRestore(pages: TrashedPage[]): void;
    onPurge(pages: TrashedPage[]): void;
}) {
    return (
        <table className="w-full text-sm">
            <thead className="text-left max-sm:sr-only">
                <tr className="border-b border-line">
                    <th scope="col" className="h-11 w-10 pr-2">
                        <SelectAllCheckbox selection={selection} label="Select all pages in the Trash" />
                    </th>
                    <th scope="col" className={`${head} w-full`}>
                        Page
                    </th>
                    <th scope="col" className={`${head} hidden min-w-40 md:table-cell`}>
                        Moved to Trash
                    </th>
                    <th scope="col" className="h-11">
                        <span className="sr-only">Actions</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                {pages.map((page) => (
                    <tr
                        key={page.id}
                        className={`border-b border-line last:border-b-0 ${selection.has(page.id) ? 'bg-accent-soft/50' : ''}`}
                        data-testid="trash-row"
                    >
                        <td className="w-10 py-3.5 pr-2 align-middle">
                            <Checkbox checked={selection.has(page.id)} onChange={() => selection.toggle(page.id)} label={`Select ${page.title}`} />
                        </td>
                        <td className={cell}>
                            <p className="font-medium text-fg">{page.title}</p>
                            <p className="mt-0.5 font-mono break-all text-xs text-muted">{page.path}</p>
                        </td>
                        <td className={`${cell} hidden text-ui whitespace-nowrap text-muted md:table-cell`}>
                            {page.deletedAt && (
                                <time dateTime={page.deletedAt} title={fullDate(page.deletedAt)}>
                                    {relativeTime(page.deletedAt)}
                                </time>
                            )}
                        </td>
                        <td className="py-3.5 align-middle">
                            <div className="flex items-center justify-end gap-1 text-xs whitespace-nowrap">
                                <button type="button" className={action} onClick={() => onRestore([page])}>
                                    <Icon name="undo" className="size-3.5" />
                                    Restore
                                </button>
                                <button
                                    type="button"
                                    className="inline-flex h-7 items-center rounded-md px-2 text-danger hover:bg-danger-soft"
                                    onClick={() => onPurge([page])}
                                >
                                    Delete permanently
                                </button>
                            </div>
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
