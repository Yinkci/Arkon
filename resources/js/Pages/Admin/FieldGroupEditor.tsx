import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/Components/AdminLayout';
import { AdminPageHeader } from '@/Components/AdminPageHeader';
import { FieldDrawer, type ContentTypeChoice, type FieldDefinition, type FieldTypeInfo } from '@/Components/content/FieldDrawer';
import { FieldList } from '@/Components/content/FieldList';
import { RuleBuilder, RuleSummary, type LocationRules, type RuleAttribute } from '@/Components/content/RuleBuilder';
import { toast } from '@/Components/Toast';
import { Button, Field, Notice, Panel, SectionHeader } from '@/Components/ui';
import { api } from '@/lib/api';
import { FIELD_KEY, TYPE_KEY } from '@/lib/contentTypes';

export interface FieldGroupDefinition {
    id: string;
    key: string;
    name: string;
    description: string;
    fields: FieldDefinition[];
    schemaVersion: number;
    rules: LocationRules;
    appliesTo: string;
    missingTargets: { attribute: string; value: string }[];
    entries: number;
}

const BLANK: FieldGroupDefinition = {
    id: '',
    key: '',
    name: '',
    description: '',
    fields: [],
    schemaVersion: 1,
    rules: { groups: [] },
    appliesTo: 'Nowhere yet',
    missingTargets: [],
    entries: 0,
};

const suggestKey = (name: string) =>
    name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^([0-9])/, 'group_$1')
        .slice(0, 32);

/**
 * One field group: what it is called, which fields it holds, and where those fields apply. The
 * field editor is the same drawer content types use, because they are the same fields.
 */
export default function FieldGroupEditor({
    group,
    fieldTypes,
    contentTypes,
    attributes,
}: {
    group: FieldGroupDefinition | null;
    fieldTypes: FieldTypeInfo[];
    /** What a relationship field in this group may point at. */
    contentTypes: ContentTypeChoice[];
    attributes: RuleAttribute[];
}) {
    const creating = group === null;
    const [draft, setDraft] = useState<FieldGroupDefinition>(group ?? BLANK);
    const [keyTouched, setKeyTouched] = useState(!creating);
    const [editingField, setEditingField] = useState<{ field: FieldDefinition; index: number } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [badField, setBadField] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    function problem(): string | null {
        if (draft.name.trim() === '') return 'Give the field group a name.';
        if (!TYPE_KEY.test(draft.key)) return 'The key must start with a letter and use only lowercase letters, numbers and underscores.';
        const keys = draft.fields.map((f) => f.key);
        const repeated = keys.find((key, index) => keys.indexOf(key) !== index);
        if (repeated) return `Two fields cannot share the key "${repeated}".`;
        const badKey = draft.fields.find((f) => !FIELD_KEY.test(f.key));
        if (badKey) return `${badKey.label}: the key must start with a letter and use only lowercase letters, numbers and underscores.`;
        return null;
    }

    async function save(confirmRemovals = false) {
        if (busy) return;
        const found = problem();
        if (found) {
            setBadField(null);
            return setError(found);
        }
        setBusy(true);
        setError(null);
        setBadField(null);
        const body = {
            ...draft,
            fields: draft.fields.map((field) => ({ ...field, settings: { ...(field.settings ?? {}) } })),
            confirmRemovals,
            expectedVersion: creating ? undefined : draft.schemaVersion,
        };
        const result = await api<FieldGroupDefinition>(creating ? '/field-groups' : `/field-groups/${draft.key}`, { body }).catch(() => null);
        setBusy(false);
        if (!result?.ok) {
            const issue = (result?.issues ?? []).find((i) => (i as { fieldKey?: string }).fieldKey);
            setBadField((issue as { fieldKey?: string } | undefined)?.fieldKey ?? null);
            return setError(result?.message ?? 'The field group could not be saved.');
        }
        toast(creating ? `${draft.name} created` : `${draft.name} saved`);
        if (creating) router.visit(`/admin/fields/${result.data.key}`);
        else setDraft(result.data);
    }

    const removalRefused = error?.includes('would leave the values stored for it unreachable') ?? false;

    return (
        <AdminLayout>
            <Head title={creating ? 'Create field group' : `Edit ${draft.name}`} />
            <div className="ak-page space-y-6">
                <AdminPageHeader
                    eyebrow={
                        <a href="/admin/fields" className="ui-link">
                            Fields
                        </a>
                    }
                    title={creating ? 'Create field group' : draft.name}
                    description={
                        creating
                            ? 'Name it, add its fields, then choose what carries them. A group can be attached to as many content types as you like.'
                            : `Key ${draft.key} · schema version ${draft.schemaVersion} · applies to ${draft.appliesTo.toLowerCase()}`
                    }
                    actions={
                        <Button variant="primary" busy={busy} disabled={busy} onClick={() => void save()}>
                            {busy ? (creating ? 'Creating…' : 'Saving…') : creating ? 'Create field group' : 'Save changes'}
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
                        <Field label="Name" hint="What the admin calls this set of fields.">
                            {({ id, describedBy }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input"
                                    value={draft.name}
                                    onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value, key: keyTouched ? d.key : suggestKey(e.target.value) }))}
                                />
                            )}
                        </Field>
                        <Field
                            label="Key"
                            hint={creating ? 'Used by the API and templates. It cannot be changed later.' : 'Fixed: the API and templates use it.'}
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
                                        setDraft((d) => ({ ...d, key: e.target.value }));
                                    }}
                                />
                            )}
                        </Field>
                        <Field label="Description" hint="Shown to whoever manages this content." className="sm:col-span-2">
                            {({ id, describedBy }) => (
                                <input
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input"
                                    value={draft.description}
                                    onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))}
                                />
                            )}
                        </Field>
                    </div>
                </Panel>

                <Panel>
                    <SectionHeader
                        className="mb-4"
                        title="Fields"
                        description="Everything attached to this group carries these fields."
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
                        empty="No fields yet. A group with no fields adds nothing to what it is attached to."
                        onEdit={(field, index) => setEditingField({ field, index })}
                        onChange={(fields) => setDraft((d) => ({ ...d, fields }))}
                    />
                </Panel>

                <Panel>
                    <SectionHeader
                        className="mb-4"
                        title="Where these fields apply"
                        description={
                            draft.entries > 0
                                ? `${draft.entries} ${draft.entries === 1 ? 'entry' : 'entries'} can carry these fields.`
                                : 'Rules in one box must all hold. A second box is an alternative.'
                        }
                    />
                    <RuleBuilder
                        rules={draft.rules}
                        attributes={attributes}
                        missing={draft.missingTargets}
                        onChange={(rules) => setDraft((d) => ({ ...d, rules }))}
                    />
                    {draft.rules.groups.length > 0 && (
                        <div className="mt-3">
                            <RuleSummary summary={draft.appliesTo} />
                        </div>
                    )}
                </Panel>
            </div>

            {editingField && (
                <FieldDrawer
                    field={editingField.field}
                    fieldTypes={fieldTypes}
                    contentTypes={contentTypes}
                    existingKeys={draft.fields.filter((_, i) => i !== editingField.index).map((f) => f.key)}
                    onClose={() => setEditingField(null)}
                    onSave={(field) =>
                        setDraft((d) => {
                            const fields = [...d.fields];
                            if (editingField.index < 0) fields.push(field);
                            else fields[editingField.index] = field;
                            setEditingField(null);
                            return { ...d, fields };
                        })
                    }
                />
            )}
        </AdminLayout>
    );
}
