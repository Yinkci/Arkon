import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { FieldDrawer, type ContentTypeChoice, type FieldDefinition, type FieldTypeInfo } from '@/Components/content/FieldDrawer';
import { FieldList } from '@/Components/content/FieldList';
import { Icon, type IconName } from '@/Components/Icon';
import { toast } from '@/Components/Toast';
import { Button, Field, Notice, Panel, SectionHeader } from '@/Components/ui';
import { api } from '@/lib/api';
import { firstProblem, requestBody } from '@/lib/contentTypes';

interface TaxonomyInfo {
    name: string;
    label: string;
    plural: string;
}

export interface ContentTypeDefinition {
    key: string;
    name: string;
    singular: string;
    description: string;
    icon: string;
    pathPrefix: string;
    inMenu: boolean;
    menuOrder: number;
    details: boolean;
    taxonomies: string[];
    fields: FieldDefinition[];
    schemaVersion: number;
    entries: number;
}

/** The icons a type may wear: Arkon's own set, so nothing arbitrary is ever rendered. */
const ICONS: IconName[] = ['pages', 'post', 'component', 'grid', 'list', 'image', 'text', 'form', 'tag', 'globe', 'home', 'layers', 'palette', 'clock', 'key'];

const BLANK: ContentTypeDefinition = {
    key: '',
    name: '',
    singular: '',
    description: '',
    icon: 'component',
    pathPrefix: '',
    inMenu: true,
    menuOrder: 10,
    details: true,
    taxonomies: [],
    fields: [],
    schemaVersion: 1,
    entries: 0,
};

/** A machine key suggested from the singular name, which the author may still change. */
const suggestKey = (singular: string) =>
    singular
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^([0-9])/, 'type_$1')
        .slice(0, 32);

/**
 * Defining one content type. The first section is all most people need; routing, taxonomies and
 * the admin menu stay folded away until they are wanted, and each field opens in its own drawer
 * rather than unfolding another dozen settings into this page.
 */
export default function ContentTypeEditor({
    type,
    groups,
    fieldTypes,
    contentTypes,
    taxonomies,
}: {
    type: ContentTypeDefinition | null;
    /** Field groups attached to this type: its entries carry these too. */
    groups: { key: string; name: string; fields: number }[];
    fieldTypes: FieldTypeInfo[];
    /** What a relationship field here may point at. */
    contentTypes: ContentTypeChoice[];
    taxonomies: TaxonomyInfo[];
}) {
    const creating = type === null;
    const [draft, setDraft] = useState<ContentTypeDefinition>(type ?? BLANK);
    const [keyTouched, setKeyTouched] = useState(!creating);
    const [editingField, setEditingField] = useState<{ field: FieldDefinition; index: number } | null>(null);
    const [error, setError] = useState<string | null>(null);
    // The field the server objected to, so the message appears where the problem is.
    const [badField, setBadField] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const set = <K extends keyof ContentTypeDefinition>(key: K, value: ContentTypeDefinition[K]) => setDraft((d) => ({ ...d, [key]: value }));

    function setSingular(singular: string) {
        setDraft((d) => ({ ...d, singular, key: keyTouched ? d.key : suggestKey(singular) }));
    }

    function putField(field: FieldDefinition, index: number) {
        setDraft((d) => {
            const fields = [...d.fields];
            if (index < 0) fields.push(field);
            else fields[index] = field;
            return { ...d, fields };
        });
        setEditingField(null);
    }

    async function save(confirmRemovals = false) {
        if (busy) return; // One save at a time: a second click must not create a second type.
        const problem = firstProblem(draft);
        if (problem) {
            setBadField(null);
            return setError(problem);
        }
        setBusy(true);
        setError(null);
        setBadField(null);
        const body = requestBody(draft, { confirmRemovals, expectedVersion: creating ? undefined : draft.schemaVersion });
        const result = await api<ContentTypeDefinition>(creating ? '/content-types' : `/content-types/${draft.key}`, { body }).catch(() => null);
        setBusy(false);
        if (!result?.ok) {
            // The server says which field it meant, so the message can be shown beside it.
            const issue = (result?.issues ?? []).find((i) => (i as { fieldKey?: string }).fieldKey);
            setBadField((issue as { fieldKey?: string } | undefined)?.fieldKey ?? null);
            return setError(result?.message ?? 'The content type could not be saved.');
        }
        toast(creating ? `${draft.name} created` : `${draft.name} saved`);
        if (creating) router.visit(`/admin/content-types/${result.data.key}`);
        else setDraft((d) => ({ ...d, schemaVersion: result.data.schemaVersion }));
    }

    // The server refuses a removal until it is confirmed; that refusal is what offers the choice.
    const removalRefused = error?.includes('would leave the values stored for it unreachable') ?? false;

    return (
        <AdminLayout>
            <Head title={creating ? 'Create content type' : `Edit ${draft.name}`} />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    eyebrow={
                        <a href="/admin/content-types" className="ui-link">
                            Content types
                        </a>
                    }
                    title={creating ? 'Create content type' : draft.name}
                    description={
                        creating
                            ? 'Name it, then add the fields its entries carry. You can change everything but the key afterwards.'
                            : `Key ${draft.key} · schema version ${draft.schemaVersion} · ${draft.entries} ${draft.entries === 1 ? 'entry' : 'entries'}`
                    }
                    actions={
                        <Button variant="primary" busy={busy} disabled={busy} onClick={() => void save()}>
                            {busy ? (creating ? 'Creating…' : 'Saving…') : creating ? 'Create content type' : 'Save changes'}
                        </Button>
                    }
                />

                {error && (
                    <Notice
                        tone="error"
                        title={removalRefused ? 'Removing a field loses what entries stored in it' : 'That could not be saved'}
                        action={
                            removalRefused ? (
                                <Button variant="danger" busy={busy} onClick={() => void save(true)}>
                                    Remove the field anyway
                                </Button>
                            ) : undefined
                        }
                    >
                        {error}
                    </Notice>
                )}

                <Panel>
                    <SectionHeader title="Basics" className="mb-4" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Singular name" hint="One of them, as you would say it: Service.">
                            {({ id, describedBy }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input"
                                    value={draft.singular}
                                    onChange={(e) => setSingular(e.target.value)}
                                />
                            )}
                        </Field>
                        <Field label="Plural name" hint="What the admin calls the list: Services.">
                            {({ id, describedBy }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input"
                                    value={draft.name}
                                    onChange={(e) => set('name', e.target.value)}
                                />
                            )}
                        </Field>
                        <Field
                            label="Key"
                            hint={
                                creating ? 'Used by the API, templates and links. It cannot be changed later.' : 'Fixed: the API, templates and links use it.'
                            }
                        >
                            {({ id, describedBy }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input font-mono"
                                    value={draft.key}
                                    disabled={!creating}
                                    onChange={(e) => {
                                        setKeyTouched(true);
                                        set('key', e.target.value);
                                    }}
                                />
                            )}
                        </Field>
                        <Field label="Description" hint="Shown to whoever manages this content.">
                            {({ id, describedBy }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input"
                                    value={draft.description}
                                    onChange={(e) => set('description', e.target.value)}
                                />
                            )}
                        </Field>
                    </div>
                    <fieldset className="mt-4">
                        <legend className="ui-label">Icon</legend>
                        <div className="mt-1 flex flex-wrap gap-1">
                            {ICONS.map((icon) => (
                                <button
                                    key={icon}
                                    type="button"
                                    aria-label={icon}
                                    aria-pressed={draft.icon === icon}
                                    onClick={() => set('icon', icon)}
                                    className={`grid size-9 place-items-center rounded-md border transition-colors ${
                                        draft.icon === icon ? 'border-accent bg-accent-soft text-accent' : 'border-line text-muted hover:text-fg'
                                    }`}
                                >
                                    <Icon name={icon} className="size-4" />
                                </button>
                            ))}
                        </div>
                    </fieldset>
                </Panel>

                <Panel>
                    <SectionHeader
                        className="mb-4"
                        title="Fields"
                        description="What every entry of this type carries, besides its title, URL and SEO."
                        action={
                            <Button
                                icon="plus"
                                size="sm"
                                onClick={() => setEditingField({ index: -1, field: { key: '', label: '', type: 'text', settings: {}, choices: [] } })}
                            >
                                Add field
                            </Button>
                        }
                    />
                    <FieldList
                        fields={draft.fields}
                        fieldTypes={fieldTypes}
                        badField={badField}
                        error={error}
                        empty="No fields yet. A type with no fields still has a title, a URL and SEO."
                        onEdit={(field, index) => setEditingField({ field, index })}
                        onChange={(fields) => setDraft((d) => ({ ...d, fields }))}
                    />
                    {!creating && (
                        <div className="mt-4 border-t border-line pt-4">
                            <p className="ui-label">Field groups</p>
                            {groups.length === 0 ? (
                                <p className="t-meta mt-1">
                                    None attached.{' '}
                                    <a href="/admin/fields" className="ui-link">
                                        Field groups
                                    </a>{' '}
                                    let several content types share one set of fields.
                                </p>
                            ) : (
                                <ul className="mt-1 space-y-1">
                                    {groups.map((group) => (
                                        <li key={group.key} className="flex items-baseline gap-2 text-ui">
                                            <a href={`/admin/fields/${group.key}`} className="ui-link">
                                                {group.name}
                                            </a>
                                            <span className="t-meta">
                                                {group.fields} field{group.fields === 1 ? '' : 's'}, shared
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </Panel>

                <details className="group">
                    <summary className="flex cursor-pointer items-center gap-1.5 text-ui font-medium">
                        <Icon name="chevronRight" className="size-3.5 transition-transform group-open:rotate-90" aria-hidden />
                        Where entries live, and who sees them
                    </summary>
                    <div className="mt-4 space-y-4">
                        <SectionHeader title="URLs" description="New entries get this prefix, then their own slug." />
                        <Field label="URL prefix" hint={`For example /services gives /services/web-development.`}>
                            {({ id, describedBy }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input font-mono"
                                    placeholder="/services"
                                    value={draft.pathPrefix}
                                    onChange={(e) => set('pathPrefix', e.target.value)}
                                />
                            )}
                        </Field>
                        <SectionHeader title="Admin" />
                        <label className="flex items-center gap-2 text-ui">
                            <input type="checkbox" className="ui-checkbox" checked={draft.inMenu} onChange={(e) => set('inMenu', e.target.checked)} />
                            Show in the admin menu
                        </label>
                        <label className="flex items-center gap-2 text-ui">
                            <input type="checkbox" className="ui-checkbox" checked={draft.details} onChange={(e) => set('details', e.target.checked)} />
                            Entries have an excerpt and a featured image
                        </label>
                        {taxonomies.length > 0 && (
                            <fieldset>
                                <legend className="ui-label">Grouped by</legend>
                                <div className="mt-1 flex flex-wrap gap-3">
                                    {taxonomies.map((taxonomy) => (
                                        <label key={taxonomy.name} className="flex items-center gap-2 text-ui">
                                            <input
                                                type="checkbox"
                                                className="ui-checkbox"
                                                checked={draft.taxonomies.includes(taxonomy.name)}
                                                onChange={(e) =>
                                                    set(
                                                        'taxonomies',
                                                        e.target.checked
                                                            ? [...draft.taxonomies, taxonomy.name]
                                                            : draft.taxonomies.filter((t) => t !== taxonomy.name),
                                                    )
                                                }
                                            />
                                            {taxonomy.plural}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        )}
                    </div>
                </details>
            </div>

            {editingField && (
                <FieldDrawer
                    field={editingField.field}
                    fieldTypes={fieldTypes}
                    contentTypes={contentTypes}
                    existingKeys={draft.fields.filter((_, i) => i !== editingField.index).map((f) => f.key)}
                    onClose={() => setEditingField(null)}
                    onSave={(field) => putField(field, editingField.index)}
                />
            )}
        </AdminLayout>
    );
}
