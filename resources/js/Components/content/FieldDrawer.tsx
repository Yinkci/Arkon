import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/Components/Icon';
import { Button, Field } from '@/Components/ui';
import { FieldList } from './FieldList';
import { LayoutsEditor, type LayoutDefinition } from './LayoutsEditor';

export interface FieldTypeInfo {
    key: string;
    label: string;
    group: string;
    choices: boolean;
    /** A group or a repeater: it holds fields of its own. */
    children: boolean;
    /** A content-sections field: it holds named layouts, each with fields of its own. */
    layouts?: boolean;
    settings: string[];
}

export interface ContentTypeChoice {
    key: string;
    label: string;
    plural: string;
}

export interface FieldDefinition {
    key: string;
    label: string;
    type: string;
    settings: Record<string, unknown>;
    choices?: { label: string; value: string }[];
    /** The fields inside a group or a repeater. */
    fields?: FieldDefinition[];
    /** The kinds of section a content-sections field offers. */
    layouts?: LayoutDefinition[];
    default?: unknown;
}

/** What each setting is called and how it is entered, so the drawer needs no per-type code. */
const SETTINGS: Record<string, { label: string; hint?: string; input: 'text' | 'number' | 'toggle' }> = {
    required: { label: 'Required', hint: 'Needed before an entry can be published; a draft may still be empty.', input: 'toggle' },
    unique: { label: 'Unique', hint: 'No two entries may hold the same value.', input: 'toggle' },
    private: { label: 'Private', hint: 'Kept out of the public API and off published pages.', input: 'toggle' },
    multiple: { label: 'Allow several', hint: 'Off: one item. On: a list, in the order chosen.', input: 'toggle' },
    maxItems: { label: 'Most items', input: 'number' },
    placeholder: { label: 'Placeholder', input: 'text' },
    help: { label: 'Help text', hint: 'Shown under the field in the entry editor.', input: 'text' },
    minRows: { label: 'Fewest rows', input: 'number' },
    maxRows: { label: 'Most rows', input: 'number' },
    rowLabel: { label: 'Label rows by', hint: 'The key of a field inside, used to title each row when it is collapsed.', input: 'text' },
    addLabel: { label: 'Add button', hint: 'For example “Add FAQ”.', input: 'text' },
    minLength: { label: 'Shortest length', input: 'number' },
    maxLength: { label: 'Longest length', input: 'number' },
    pattern: { label: 'Must match', hint: 'A regular expression, for formats such as a reference code.', input: 'text' },
    min: { label: 'Smallest value', input: 'number' },
    max: { label: 'Largest value', input: 'number' },
    step: { label: 'Step', input: 'number' },
    unit: { label: 'Unit', hint: 'Shown beside the value, such as PHP or kg.', input: 'text' },
};

const keyFrom = (label: string) =>
    label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^([0-9])/, 'f_$1')
        .slice(0, 40);

/**
 * One field, edited on its own. Everything a field can be is here, but only the settings its
 * type understands are shown, and the less common ones stay folded — so adding a plain text
 * field is three decisions, not fifteen.
 */
export function FieldDrawer({
    field,
    fieldTypes,
    contentTypes = [],
    existingKeys,
    onClose,
    onSave,
}: {
    field: FieldDefinition;
    fieldTypes: FieldTypeInfo[];
    contentTypes?: ContentTypeChoice[];
    existingKeys: string[];
    onClose(): void;
    onSave(field: FieldDefinition): void;
}) {
    const ref = useRef<HTMLDialogElement>(null);
    const [draft, setDraft] = useState<FieldDefinition>({ ...field, choices: field.choices ?? [] });
    const [keyTouched, setKeyTouched] = useState(field.key !== '');
    const [error, setError] = useState<string | null>(null);
    const [child, setChild] = useState<{ field: FieldDefinition; index: number } | null>(null);
    const [layoutField, setLayoutField] = useState<{ layoutIndex: number; field: FieldDefinition; index: number } | null>(null);
    const info = fieldTypes.find((t) => t.key === draft.type);

    useEffect(() => {
        const dialog = ref.current;
        if (dialog && !dialog.open) dialog.showModal();
    }, []);

    const groups = [...new Set(fieldTypes.map((t) => t.group))];
    const setting = (name: string) => draft.settings[name];
    const setSetting = (name: string, value: unknown) => setDraft((d) => ({ ...d, settings: { ...d.settings, [name]: value } }));

    function submit() {
        const key = draft.key.trim();
        if (draft.label.trim() === '') return setError('Give the field a label.');
        if (!/^[a-z][a-z0-9_]{0,39}$/.test(key)) return setError('The key must start with a letter and use only lowercase letters, numbers and underscores.');
        if (existingKeys.includes(key)) return setError('Another field already uses this key.');
        if (info?.choices && (draft.choices ?? []).length === 0) return setError('Add at least one choice.');
        if (info?.children && (draft.fields ?? []).length === 0) return setError('Add at least one field inside this ' + info.label.toLowerCase() + '.');
        if (draft.type === 'relationship' && !draft.settings.targetType) return setError('Choose the kind of content this field relates to.');
        if (info?.layouts) {
            const layouts = draft.layouts ?? [];
            if (layouts.length === 0) return setError('Add at least one kind of section.');
            for (const layout of layouts) {
                if (layout.label.trim() === '') return setError('Give every kind of section a name.');
                if (!/^[a-z][a-z0-9_]{0,39}$/.test(layout.key))
                    return setError(`“${layout.label}”: the key must start with a letter and use only lowercase letters, numbers and underscores.`);
                if (layout.fields.length === 0) return setError(`“${layout.label}” needs at least one field.`);
            }
            if (new Set(layouts.map((l) => l.key)).size !== layouts.length) return setError('Two kinds of section cannot share a key.');
        }
        onSave({ ...draft, key, label: draft.label.trim() });
    }

    // Settings split in two: what most fields need, and what only some do.
    const shown = info?.settings ?? [];
    const common = shown.filter((name) => ['required', 'placeholder', 'help'].includes(name));
    // The target is asked for beside the type rather than among the settings: a relationship
    // without one means nothing, so it is not something to find under "and more".
    const advanced = shown.filter((name) => !common.includes(name) && name !== 'default' && name !== 'targetType');

    return (
        <dialog
            ref={ref}
            onClose={onClose}
            onCancel={onClose}
            aria-label="Edit field"
            className="m-0 ml-auto h-full max-h-full w-full max-w-[28rem] bg-surface p-0 text-fg backdrop:bg-black/30"
        >
            <form method="dialog" className="flex h-full flex-col" onSubmit={(e) => e.preventDefault()}>
                <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
                    <h2 className="t-section">{field.key === '' ? 'Add field' : 'Edit field'}</h2>
                    <Button variant="ghost" icon="close" aria-label="Close" onClick={onClose}>
                        <span className="sr-only">Close</span>
                    </Button>
                </header>

                <div className="ak-scroll min-h-0 flex-1 space-y-4 px-5 py-4">
                    {error && (
                        <p role="alert" className="text-xs text-danger">
                            {error}
                        </p>
                    )}
                    <Field label="Label" hint="What the person filling it in reads.">
                        {({ id, describedBy }) => (
                            <input
                                id={id}
                                aria-describedby={describedBy}
                                className="ui-input"
                                value={draft.label}
                                onChange={(e) => setDraft((d) => ({ ...d, label: e.target.value, key: keyTouched ? d.key : keyFrom(e.target.value) }))}
                            />
                        )}
                    </Field>
                    <Field label="Key" hint="Used by the API and templates. Keep it stable.">
                        {({ id, describedBy }) => (
                            <input
                                id={id}
                                aria-describedby={describedBy}
                                className="ui-input font-mono"
                                value={draft.key}
                                onChange={(e) => {
                                    setKeyTouched(true);
                                    setDraft((d) => ({ ...d, key: e.target.value }));
                                }}
                            />
                        )}
                    </Field>
                    <Field label="Type">
                        {({ id }) => (
                            <select
                                id={id}
                                className="ui-input"
                                value={draft.type}
                                onChange={(e) =>
                                    setDraft((d) => ({ ...d, type: e.target.value, settings: {}, choices: [], fields: [], layouts: [], default: null }))
                                }
                            >
                                {groups.map((group) => (
                                    <optgroup key={group} label={group}>
                                        {fieldTypes
                                            .filter((t) => t.group === group)
                                            .map((t) => (
                                                <option key={t.key} value={t.key}>
                                                    {t.label}
                                                </option>
                                            ))}
                                    </optgroup>
                                ))}
                            </select>
                        )}
                    </Field>

                    {draft.type === 'relationship' && (
                        <Field label="Relates to" hint="Entries of this kind are what the field can point at.">
                            {({ id, describedBy }) => (
                                <select
                                    id={id}
                                    aria-describedby={describedBy}
                                    className="ui-input"
                                    value={String(draft.settings.targetType ?? '')}
                                    onChange={(e) => setSetting('targetType', e.target.value || null)}
                                >
                                    <option value="">Choose a kind of content…</option>
                                    {contentTypes.map((type) => (
                                        <option key={type.key} value={type.key}>
                                            {type.plural}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>
                    )}

                    {info?.choices && (
                        <fieldset>
                            <legend className="ui-label">Choices</legend>
                            <p className="t-meta mb-2">What is stored stays separate from what is shown, so a label can be reworded later.</p>
                            <ul className="space-y-2">
                                {(draft.choices ?? []).map((choice, index) => (
                                    <li key={index} className="flex items-center gap-2">
                                        <input
                                            aria-label={`Choice ${index + 1} label`}
                                            className="ui-input flex-1"
                                            placeholder="Premium"
                                            value={choice.label}
                                            onChange={(e) =>
                                                setDraft((d) => {
                                                    const choices = [...(d.choices ?? [])];
                                                    choices[index] = { label: e.target.value, value: choices[index]!.value || keyFrom(e.target.value) };
                                                    return { ...d, choices };
                                                })
                                            }
                                        />
                                        <input
                                            aria-label={`Choice ${index + 1} stored value`}
                                            className="ui-input w-32 font-mono"
                                            placeholder="premium"
                                            value={choice.value}
                                            onChange={(e) =>
                                                setDraft((d) => {
                                                    const choices = [...(d.choices ?? [])];
                                                    choices[index] = { ...choices[index]!, value: e.target.value };
                                                    return { ...d, choices };
                                                })
                                            }
                                        />
                                        <Button
                                            variant="ghost"
                                            icon="trash"
                                            aria-label={`Remove choice ${index + 1}`}
                                            onClick={() => setDraft((d) => ({ ...d, choices: (d.choices ?? []).filter((_, i) => i !== index) }))}
                                        >
                                            <span className="sr-only">Remove</span>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            <Button
                                size="sm"
                                icon="plus"
                                className="mt-2"
                                onClick={() => setDraft((d) => ({ ...d, choices: [...(d.choices ?? []), { label: '', value: '' }] }))}
                            >
                                Add choice
                            </Button>
                        </fieldset>
                    )}

                    {info?.layouts && (
                        <LayoutsEditor
                            layouts={draft.layouts ?? []}
                            fieldTypes={fieldTypes.filter((t) => !t.children && !t.layouts)}
                            onChange={(layouts) => setDraft((d) => ({ ...d, layouts }))}
                            onEditField={(layoutIndex, field, index) => setLayoutField({ layoutIndex, field, index })}
                        />
                    )}

                    {info?.children && (
                        <fieldset>
                            <legend className="ui-label">Fields inside</legend>
                            <p className="t-meta mb-2">
                                {draft.type === 'repeater'
                                    ? 'Every row holds these fields. They cannot themselves be a group or a repeater.'
                                    : 'The fields this group holds. They cannot themselves be a group or a repeater.'}
                            </p>
                            <FieldList
                                fields={draft.fields ?? []}
                                fieldTypes={fieldTypes.filter((t) => !t.children)}
                                empty="No fields yet."
                                onEdit={(field, index) => setChild({ field, index })}
                                onChange={(fields) => setDraft((d) => ({ ...d, fields }))}
                            />
                            <Button
                                size="sm"
                                icon="plus"
                                className="mt-2"
                                onClick={() => setChild({ index: -1, field: { key: '', label: '', type: 'text', settings: {}, choices: [] } })}
                            >
                                Add field inside
                            </Button>
                        </fieldset>
                    )}

                    {common.map((name) => (
                        <SettingInput key={name} name={name} fieldType={draft.type} value={setting(name)} onChange={(value) => setSetting(name, value)} />
                    ))}

                    {shown.includes('default') && <DefaultInput field={draft} onChange={(value) => setDraft((d) => ({ ...d, default: value }))} />}

                    {advanced.length > 0 && (
                        <details className="group">
                            <summary className="flex cursor-pointer items-center gap-1.5 text-ui font-medium">
                                <Icon name="chevronRight" className="size-3.5 transition-transform group-open:rotate-90" aria-hidden />
                                Validation and more
                            </summary>
                            <div className="mt-3 space-y-3">
                                {advanced.map((name) => (
                                    <SettingInput
                                        key={name}
                                        name={name}
                                        fieldType={draft.type}
                                        value={setting(name)}
                                        onChange={(value) => setSetting(name, value)}
                                    />
                                ))}
                            </div>
                        </details>
                    )}
                </div>

                <footer className="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <Button onClick={onClose}>Cancel</Button>
                    <Button variant="primary" onClick={submit}>
                        {field.key === '' ? 'Add field' : 'Save field'}
                    </Button>
                </footer>
            </form>
            {layoutField && (
                <FieldDrawer
                    field={layoutField.field}
                    fieldTypes={fieldTypes.filter((t) => !t.children && !t.layouts)}
                    contentTypes={contentTypes}
                    existingKeys={((draft.layouts ?? [])[layoutField.layoutIndex]?.fields ?? []).filter((_, i) => i !== layoutField.index).map((f) => f.key)}
                    onClose={() => setLayoutField(null)}
                    onSave={(saved) =>
                        setDraft((d) => {
                            const layouts = [...(d.layouts ?? [])];
                            const layout = layouts[layoutField.layoutIndex];
                            if (layout) {
                                const fields = [...layout.fields];
                                if (layoutField.index < 0) fields.push(saved);
                                else fields[layoutField.index] = saved;
                                layouts[layoutField.layoutIndex] = { ...layout, fields };
                            }
                            setLayoutField(null);

                            return { ...d, layouts };
                        })
                    }
                />
            )}
            {child && (
                <FieldDrawer
                    field={child.field}
                    fieldTypes={fieldTypes.filter((t) => !t.children)}
                    contentTypes={contentTypes}
                    existingKeys={(draft.fields ?? []).filter((_, i) => i !== child.index).map((f) => f.key)}
                    onClose={() => setChild(null)}
                    onSave={(saved) =>
                        setDraft((d) => {
                            const fields = [...(d.fields ?? [])];
                            if (child.index < 0) fields.push(saved);
                            else fields[child.index] = saved;
                            setChild(null);

                            return { ...d, fields };
                        })
                    }
                />
            )}
        </dialog>
    );
}

/**
 * What a new entry starts with in this field. Entered as the field itself would be, so the
 * server can check it with the same rules (a default can never be a value the field refuses).
 */
function DefaultInput({ field, onChange }: { field: FieldDefinition; onChange(value: unknown): void }) {
    const value = field.default;
    const hint = 'What a new entry starts with. Leave empty for none.';
    if (field.type === 'boolean') {
        return (
            <label className="flex items-start gap-2 text-ui">
                <input type="checkbox" className="ui-checkbox mt-0.5" checked={value === true} onChange={(e) => onChange(e.target.checked ? true : null)} />
                <span>
                    On by default
                    <span className="t-meta block">New entries start with this switched on.</span>
                </span>
            </label>
        );
    }
    return (
        <Field label="Default value" hint={hint}>
            {({ id, describedBy }) =>
                field.type === 'select' ? (
                    <select
                        id={id}
                        aria-describedby={describedBy}
                        className="ui-input"
                        value={typeof value === 'string' ? value : ''}
                        onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
                    >
                        <option value="">No default</option>
                        {(field.choices ?? [])
                            .filter((choice) => choice.value !== '')
                            .map((choice) => (
                                <option key={choice.value} value={choice.value}>
                                    {choice.label || choice.value}
                                </option>
                            ))}
                    </select>
                ) : (
                    <input
                        id={id}
                        aria-describedby={describedBy}
                        type={field.type === 'number' || field.type === 'decimal' ? 'number' : 'text'}
                        step={field.type === 'decimal' ? 'any' : undefined}
                        className="ui-input"
                        placeholder={field.type === 'color' ? '#336699' : undefined}
                        value={value === null || value === undefined ? '' : String(value)}
                        onChange={(e) =>
                            onChange(
                                e.target.value === '' ? null : field.type === 'number' || field.type === 'decimal' ? Number(e.target.value) : e.target.value,
                            )
                        }
                    />
                )
            }
        </Field>
    );
}

function SettingInput({ name, value, fieldType, onChange }: { name: string; value: unknown; fieldType: string; onChange(value: unknown): void }) {
    const spec = SETTINGS[name];
    if (!spec) return null;
    // A date's bounds are dates, stored as the field stores its values.
    if ((name === 'min' || name === 'max') && (fieldType === 'date' || fieldType === 'datetime')) {
        const withTime = fieldType === 'datetime';
        const shown = typeof value === 'string' ? (withTime ? value.slice(0, 16) : value) : '';
        return (
            <Field label={name === 'min' ? 'Earliest' : 'Latest'}>
                {({ id, describedBy }) => (
                    <input
                        id={id}
                        aria-describedby={describedBy}
                        type={withTime ? 'datetime-local' : 'date'}
                        className="ui-input"
                        value={shown}
                        onChange={(e) => onChange(e.target.value === '' ? null : withTime ? `${e.target.value}Z` : e.target.value)}
                    />
                )}
            </Field>
        );
    }
    if (spec.input === 'toggle') {
        return (
            <label className="flex items-start gap-2 text-ui">
                <input type="checkbox" className="ui-checkbox mt-0.5" checked={value === true} onChange={(e) => onChange(e.target.checked)} />
                <span>
                    {spec.label}
                    {spec.hint && <span className="t-meta block">{spec.hint}</span>}
                </span>
            </label>
        );
    }
    return (
        <Field label={spec.label} hint={spec.hint}>
            {({ id, describedBy }) => (
                <input
                    id={id}
                    aria-describedby={describedBy}
                    type={spec.input === 'number' ? 'number' : 'text'}
                    className="ui-input"
                    value={value === null || value === undefined ? '' : String(value)}
                    onChange={(e) => onChange(spec.input === 'number' ? (e.target.value === '' ? null : Number(e.target.value)) : e.target.value)}
                />
            )}
        </Field>
    );
}
