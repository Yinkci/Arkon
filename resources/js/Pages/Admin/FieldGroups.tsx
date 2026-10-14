import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { MenuItem, RowMenu } from '@/Components/ListManagement';
import { toast } from '@/Components/Toast';
import { Button, ButtonLink } from '@/Components/ui';
import { api } from '@/lib/api';

export interface FieldGroupRow {
    id: string;
    key: string;
    name: string;
    description: string;
    fields: number;
    schemaVersion: number;
    appliesTo: string;
    placed: boolean;
}

const head = 'h-11 pr-4 t-eyebrow';
const cell = 'py-3 pr-4 align-middle';

/**
 * Field groups: sets of fields that content types share. The list leads with what each group
 * holds and who uses it, because changing a shared group changes every one of them.
 */
export default function FieldGroups({ groups, canManage }: { groups: FieldGroupRow[]; canManage: boolean }) {
    const [deleting, setDeleting] = useState<FieldGroupRow | null>(null);

    async function remove(confirmed: boolean): Promise<string | null> {
        if (!deleting) return null;
        const result = await api(`/field-groups/${deleting.key}/delete`, { body: { confirmed } }).catch(() => null);
        if (!result?.ok) return result?.message ?? 'The field group could not be deleted.';
        toast(`${deleting.name} deleted`);
        setDeleting(null);
        router.reload();
        return null;
    }

    return (
        <AdminLayout>
            <Head title="Fields" />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    title="Fields"
                    description="A field group is a set of fields that content types share. Define it once — an address, a set of service details — and attach it wherever it belongs."
                    actions={
                        canManage ? (
                            <ButtonLink href="/admin/fields/new" variant="primary" icon="plus">
                                Create field group
                            </ButtonLink>
                        ) : undefined
                    }
                />

                {groups.length === 0 ? (
                    <p className="t-meta">
                        No field groups yet.{' '}
                        {canManage && (
                            <Link href="/admin/fields/new" className="ui-link">
                                Create one
                            </Link>
                        )}{' '}
                        to share a set of fields across content types.
                    </p>
                ) : (
                    <table className="w-full text-left">
                        <thead>
                            <tr className="border-b border-line">
                                <th className={head}>Name</th>
                                <th className={head}>Fields</th>
                                <th className={head}>Applies to</th>
                                <th className={head}>
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) => (
                                <tr key={group.key} className="border-b border-line last:border-0">
                                    <td className={cell}>
                                        {canManage ? (
                                            <Link href={`/admin/fields/${group.key}`} className="ui-link font-medium">
                                                {group.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">{group.name}</span>
                                        )}
                                        <p className="t-meta">{group.description || <code className="font-mono">{group.key}</code>}</p>
                                    </td>
                                    <td className={`${cell} tabular-nums text-muted`}>{group.fields}</td>
                                    <td className={`${cell} text-muted`}>{group.appliesTo}</td>
                                    <td className={`${cell} text-right`}>
                                        {canManage && (
                                            <RowMenu label={`${group.name} actions`}>
                                                {(close) => (
                                                    <>
                                                        <MenuItem href={`/admin/fields/${group.key}`}>Edit group</MenuItem>
                                                        <MenuItem
                                                            tone="danger"
                                                            onSelect={() => {
                                                                close();
                                                                setDeleting(group);
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
                )}
            </div>

            <ConfirmDialog
                open={!!deleting}
                title={`Delete ${deleting?.name ?? ''}?`}
                confirmLabel="Delete field group"
                busyLabel="Deleting…"
                tone="danger"
                onClose={() => setDeleting(null)}
                onConfirm={() => remove(true)}
            >
                <p>
                    This removes the group and takes its {deleting?.fields ?? 0} field{deleting?.fields === 1 ? '' : 's'} off everything using it
                    {deleting?.placed ? ` (${deleting.appliesTo})` : ''}.
                </p>
                <p className="mt-2">What entries already stored in those fields is kept, so attaching the group again brings the values back.</p>
            </ConfirmDialog>
        </AdminLayout>
    );
}
