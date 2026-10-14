import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { toast } from '@/Components/Toast';
import { Button, Notice, Panel, SectionHeader } from '@/Components/ui';
import { EntryFieldControl } from '@/editor/EntryFields';
import { api } from '@/lib/api';
import { fullDate } from '@/lib/time';
import type { EntryFieldSection } from '@/types';

interface SiteDataPayload {
    version: number;
    values: Record<string, unknown>;
    sections: EntryFieldSection[];
    updatedAt: string | null;
}

interface HistoryRow {
    id: string;
    version: number;
    at: string;
    author: string | null;
}

/**
 * The values that belong to the website itself. Which fields appear here is decided by field
 * groups placed on site data, so this screen never names a field: it draws whatever the schema
 * says, with the same controls an entry uses.
 */
export default function SiteData({ data, history, canEdit }: { data: SiteDataPayload; history: HistoryRow[]; canEdit: boolean }) {
    const [saved, setSaved] = useState(data);
    const [values, setValues] = useState<Record<string, unknown>>(data.values ?? {});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const fields = saved.sections.flatMap((section) => section.fields);
    const changed = JSON.stringify(values) !== JSON.stringify(saved.values ?? {});

    async function save() {
        if (busy) return;
        setBusy(true);
        setError(null);
        // A field the author emptied is sent as null, which is how a value is cleared.
        const body: Record<string, unknown> = {};
        for (const field of fields) body[field.key] = values[field.key] ?? null;
        const result = await api<SiteDataPayload>('/site-data', { body: { values: body, expectedVersion: saved.version } }).catch(() => null);
        setBusy(false);
        if (!result?.ok) return setError(result?.message ?? 'Site data could not be saved.');
        setSaved(result.data);
        setValues(result.data.values ?? {});
        toast('Site data saved');
        router.reload({ only: ['history'] });
    }

    async function restore(row: HistoryRow) {
        setBusy(true);
        const result = await api<SiteDataPayload>('/site-data/restore', { body: { id: row.id } }).catch(() => null);
        setBusy(false);
        if (!result?.ok) return setError(result?.message ?? 'That version could not be put back.');
        setSaved(result.data);
        setValues(result.data.values ?? {});
        toast(`Put back what site data said at version ${row.version}`);
        router.reload({ only: ['history'] });
    }

    return (
        <AdminLayout>
            <Head title="Site data" />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    title="Site data"
                    description="Values that belong to the website rather than to one page: your phone number, address and opening hours. Change one here and every place that uses it follows."
                    actions={
                        canEdit ? (
                            <Button variant="primary" busy={busy} disabled={busy || !changed} onClick={() => void save()}>
                                {busy ? 'Saving…' : 'Save site data'}
                            </Button>
                        ) : undefined
                    }
                />

                {error && (
                    <Notice tone="error" title="That could not be saved">
                        {error}
                    </Notice>
                )}

                {saved.sections.length === 0 ? (
                    <Panel>
                        <p className="t-meta">
                            Nothing is here yet. Create a{' '}
                            <a href="/admin/fields" className="ui-link">
                                field group
                            </a>{' '}
                            and set its location to “Where is Site data” to decide what this site keeps.
                        </p>
                    </Panel>
                ) : (
                    saved.sections.map((section, index) => (
                        <Panel key={section.key ?? index}>
                            <SectionHeader className="mb-4" title={section.name ?? 'Site data'} description={section.description || undefined} />
                            <div className="space-y-4">
                                {section.fields.map((field) => (
                                    <EntryFieldControl
                                        key={field.key}
                                        field={field}
                                        value={values[field.key]}
                                        canEdit={canEdit}
                                        onChange={(next) => setValues((v) => ({ ...v, [field.key]: next }))}
                                    />
                                ))}
                            </div>
                        </Panel>
                    ))
                )}

                {history.length > 0 && (
                    <Panel>
                        <SectionHeader className="mb-3" title="Earlier versions" description="Site data shows on every page, so each change is kept." />
                        <ul className="divide-y divide-line">
                            {history.map((row) => (
                                <li key={row.id} className="flex items-center gap-3 py-2 text-ui">
                                    <span className="min-w-0 flex-1 truncate">
                                        Version {row.version}
                                        <span className="t-meta ml-2">
                                            {fullDate(row.at)}
                                            {row.author ? ` · ${row.author}` : ''}
                                        </span>
                                    </span>
                                    {canEdit && (
                                        <Button size="sm" disabled={busy} onClick={() => void restore(row)}>
                                            Put this back
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}
            </div>
        </AdminLayout>
    );
}
