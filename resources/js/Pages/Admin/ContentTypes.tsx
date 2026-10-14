import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Icon } from '@/Components/Icon';
import { MenuItem, RowMenu } from '@/Components/ListManagement';
import { toast } from '@/Components/Toast';
import { Button, ButtonLink, StatusPill } from '@/Components/ui';
import { api } from '@/lib/api';
import type { IconName } from '@/Components/Icon';

export interface ContentTypeRow {
    key: string;
    label: string;
    plural: string;
    description: string;
    icon: string;
    defined: boolean;
    active: boolean;
    fields: number;
    entries: number;
    schemaVersion: number | null;
}

const head = 'h-11 pr-4 t-eyebrow';
const cell = 'py-3 pr-4 align-middle';

/**
 * The kinds of content this site holds: the ones Arkon provides and the ones the site has
 * defined for itself. Built-in kinds are listed too, so this reads as the whole content model
 * rather than an "extras" screen.
 */
export default function ContentTypes({ types, canManage }: { types: ContentTypeRow[]; canManage: boolean }) {
    const [deleting, setDeleting] = useState<ContentTypeRow | null>(null);
    const [busy, setBusy] = useState(false);
    const defined = types.filter((t) => t.defined);
    const builtIn = types.filter((t) => !t.defined);

    async function setActive(type: ContentTypeRow, active: boolean) {
        setBusy(true);
        const result = await api(`/content-types/${type.key}/active`, { body: { active } }).catch(() => null);
        setBusy(false);
        if (!result?.ok) return toast(result?.message ?? 'That could not be saved.');
        toast(active ? `${type.plural} turned on` : `${type.plural} turned off; nothing was deleted`);
        router.reload();
    }

    /** The dialog shows what the server says, so a refused delete explains itself in place. */
    async function remove(): Promise<string | null> {
        if (!deleting) return null;
        const result = await api(`/content-types/${deleting.key}/delete`, { body: {} }).catch(() => null);
        if (!result?.ok) return result?.message ?? 'The content type could not be deleted.';
        toast(`${deleting.plural} deleted`);
        setDeleting(null);
        router.reload();
        return null;
    }

    return (
        <AdminLayout>
            <Head title="Content types" />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    title="Content types"
                    description="The kinds of content this site holds. Define your own — services, team members, projects — each with its own fields, and manage their entries like any other content."
                    actions={
                        canManage ? (
                            <ButtonLink href="/admin/content-types/new" variant="primary" icon="plus">
                                Create content type
                            </ButtonLink>
                        ) : undefined
                    }
                />

                <table className="w-full text-left">
                    <thead>
                        <tr className="border-b border-line">
                            <th className={head}>Name</th>
                            <th className={head}>Fields</th>
                            <th className={head}>Entries</th>
                            <th className={head}>
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {[...defined, ...builtIn].map((type) => (
                            <tr key={type.key} className="border-b border-line last:border-0">
                                <td className={cell}>
                                    <div className="flex items-start gap-2.5">
                                        <Icon name={(type.icon || 'pages') as IconName} className="mt-0.5 size-4 shrink-0 text-muted" />
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                {type.defined && canManage ? (
                                                    <Link href={`/admin/content-types/${type.key}`} className="ui-link font-medium">
                                                        {type.plural}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium">{type.plural}</span>
                                                )}
                                                {!type.defined && <StatusPill tone="neutral">Built in</StatusPill>}
                                                {type.defined && !type.active && <StatusPill tone="changed">Off</StatusPill>}
                                            </div>
                                            <p className="t-meta">{type.description || <code className="font-mono">{type.key}</code>}</p>
                                        </div>
                                    </div>
                                </td>
                                <td className={`${cell} tabular-nums text-muted`}>{type.defined ? type.fields : '—'}</td>
                                <td className={`${cell} tabular-nums`}>
                                    <Link
                                        href={type.defined ? `/admin/content/${type.key}` : type.key === 'post' ? '/admin/posts' : '/admin/pages'}
                                        className="ui-link"
                                    >
                                        {type.entries}
                                    </Link>
                                </td>
                                <td className={`${cell} text-right`}>
                                    {type.defined && canManage && (
                                        <RowMenu label={`${type.plural} actions`}>
                                            {(close) => (
                                                <>
                                                    <MenuItem href={`/admin/content-types/${type.key}`}>Edit definition</MenuItem>
                                                    <MenuItem
                                                        disabled={busy}
                                                        onSelect={() => {
                                                            close();
                                                            void setActive(type, !type.active);
                                                        }}
                                                    >
                                                        {type.active ? 'Turn off' : 'Turn on'}
                                                    </MenuItem>
                                                    <MenuItem
                                                        tone="danger"
                                                        disabled={busy}
                                                        onSelect={() => {
                                                            close();
                                                            setDeleting(type);
                                                        }}
                                                    >
                                                        Delete…
                                                    </MenuItem>
                                                </>
                                            )}
                                        </RowMenu>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {defined.length === 0 && canManage && (
                    <p className="t-meta">
                        You have not defined a content type yet.{' '}
                        <Link href="/admin/content-types/new" className="ui-link">
                            Create one
                        </Link>{' '}
                        to add structured content such as services or team members.
                    </p>
                )}
            </div>

            <ConfirmDialog
                open={!!deleting}
                title={`Delete ${deleting?.plural ?? ''}?`}
                confirmLabel="Delete content type"
                busyLabel="Deleting…"
                tone="danger"
                onClose={() => setDeleting(null)}
                onConfirm={remove}
            >
                <p>
                    This removes the definition and its fields. Its API and any template that names <code className="font-mono">{deleting?.key}</code> stop
                    working.
                </p>
                {!!deleting?.entries && (
                    <p className="mt-2">
                        It still holds {deleting.entries} {deleting.entries === 1 ? 'entry' : 'entries'}, so deleting is refused. Turn the type off instead to
                        keep them.
                    </p>
                )}
            </ConfirmDialog>
        </AdminLayout>
    );
}
