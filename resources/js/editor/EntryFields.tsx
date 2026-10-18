import { useState } from 'react';
import { Button, Field, PanelSection } from '@/Components/ui';
import { api } from '@/lib/api';
import type { ContentFieldDefinition, EntryFieldSection } from '@/types';
import { NestedField } from './NestedField';
import { FlexibleField } from './FlexibleField';
import { RelationshipField } from './RelationshipField';

/**
 * The values of an entry whose type defines fields. They belong to the draft like the title
 * does: saved here, live once the entry is published. Each field is drawn from its definition,
 * so a type gains a field and this panel shows it without any change here.
 *
 * Fields arrive in sections: the type's own first, then one per field group attached to it.
 * That grouping is only how they are shown — the values are one flat set, saved together.
 */
export function EntryFields({
    pageId,
    sections,
    initial,
    canEdit,
    deprecated = [],
}: {
    pageId: string;
    sections: EntryFieldSection[];
    initial: Record<string, unknown>;
    canEdit: boolean;
    /** Sections this entry holds whose kind was removed from its content type. */
    deprecated?: string[];
}) {
    const fields = sections.flatMap((section) => section.fields);
    const [saved, setSaved] = useState<Record<string, unknown>>(initial);
    const [values, setValues] = useState<Record<string, unknown>>(initial);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [note, setNote] = useState(false);
    const changed = JSON.stringify(values) !== JSON.stringify(saved);

    const set = (key: string, value: unknown) => {
        setValues((v) => ({ ...v, [key]: value }));
        setNote(false);
    };

    async function save() {
        if (pending) return;
        setPending(true);
        setError(null);
        // Only what this person changed is sent, with what they saw before changing it, so a
        // save never overwrites a field somebody else changed meanwhile (the server refuses it).
        // Fields the author emptied are sent as null, which is how a value is cleared.
        const body: Record<string, unknown> = {};
        const expected: Record<string, unknown> = {};
        for (const field of fields) {
            if (JSON.stringify(values[field.key] ?? null) === JSON.stringify(saved[field.key] ?? null)) continue;
            body[field.key] = values[field.key] ?? null;
            expected[field.key] = saved[field.key] ?? null;
        }
        const result = await api<{ fields: Record<string, unknown> }>(`/pages/${pageId}/fields`, { body: { fields: body, expected } }).catch(() => null);
        setPending(false);
        if (!result?.ok) return setError(result?.message ?? 'The fields could not be saved.');
        setSaved(result.data.fields);
        setValues(result.data.fields);
        setNote(true);
    }

    if (fields.length === 0) return null;

    const inputs = (section: EntryFieldSection) =>
        section.fields.map((field) => (
            <EntryFieldControl key={field.key} field={field} value={values[field.key]} canEdit={canEdit} onChange={(value) => set(field.key, value)} />
        ));

    return (
        <>
            {sections.map((section, index) => {
                // A group is named, so it gets its own collapsible section; the type's own
                // fields have no name and sit directly under "Fields".
                const title = section.name ?? 'Fields';
                return section.key ? (
                    <PanelSection key={section.key} title={title} collapsible defaultOpen={index < 2} data-testid={`entry-fields-${section.key}`}>
                        {section.description && <p className="t-meta">{section.description}</p>}
                        {inputs(section)}
                    </PanelSection>
                ) : (
                    <PanelSection key="own" title={title} data-testid="entry-fields">
                        {inputs(section)}
                    </PanelSection>
                );
            })}
            <PanelSection title="" data-testid="entry-fields-save">
                {deprecated.map((message) => (
                    <p key={message} role="status" className="text-xs text-muted" data-testid="deprecated-sections">
                        {message}
                    </p>
                ))}
                {error && (
                    <p role="alert" className="text-xs text-danger">
                        {error}
                    </p>
                )}
                {canEdit && (
                    <div className="flex items-center gap-2">
                        <Button size="sm" variant="primary" disabled={!changed || pending} busy={pending} onClick={() => void save()}>
                            Save fields
                        </Button>
                        {note && !changed && <span className="text-2xs text-muted">Saved to the draft</span>}
                    </div>
                )}
            </PanelSection>
        </>
    );
}

/**
 * One field control, used by the entry editor and by site data: the same field means the same
 * thing wherever its values are kept.
 */
export function EntryFieldControl({
    field,
    value,
    canEdit,
    onChange,
}: {
    field: ContentFieldDefinition;
    value: unknown;
    canEdit: boolean;
    onChange(value: unknown): void;
}) {
    if (field.type === 'group' || field.type === 'repeater') {
        return (
            <NestedField
                field={field}
                value={value}
                canEdit={canEdit}
                onChange={onChange}
                renderChild={(child, childValue, childChange) => (
                    <EntryFieldControl key={child.key} field={child} value={childValue} canEdit={canEdit} onChange={childChange} />
                )}
            />
        );
    }
    if (field.type === 'flexible') {
        return (
            <FlexibleField
                field={field}
                value={value}
                canEdit={canEdit}
                onChange={onChange}
                renderChild={(child, childValue, childChange) => (
                    <EntryFieldControl key={child.key} field={child} value={childValue} canEdit={canEdit} onChange={childChange} />
                )}
            />
        );
    }
    if (field.type === 'relationship') {
        return <RelationshipField field={field} value={value} canEdit={canEdit} onChange={onChange} />;
    }
    const hint = (field.settings.help as string) || undefined;
    const placeholder = (field.settings.placeholder as string) || undefined;
    const text = value === null || value === undefined ? '' : String(value);

    if (field.type === 'boolean') {
        return (
            <label className="flex items-start gap-2 text-ui">
                <input
                    type="checkbox"
                    className="ui-checkbox mt-0.5"
                    disabled={!canEdit}
                    checked={value === true}
                    onChange={(e) => onChange(e.target.checked)}
                />
                <span>
                    {field.label}
                    {hint && <span className="t-meta block">{hint}</span>}
                </span>
            </label>
        );
    }

    return (
        <Field label={field.label} hint={hint}>
            {({ id, describedBy }) => {
                const common = { id, 'aria-describedby': describedBy, className: 'ui-input', disabled: !canEdit };
                if (field.type === 'select') {
                    return (
                        <select {...common} value={text} onChange={(e) => onChange(e.target.value || null)}>
                            <option value="">—</option>
                            {(field.choices ?? []).map((choice) => (
                                <option key={choice.value} value={choice.value}>
                                    {choice.label}
                                </option>
                            ))}
                        </select>
                    );
                }
                if (field.type === 'multi_select') {
                    const chosen = Array.isArray(value) ? (value as string[]) : [];
                    return (
                        <div className="space-y-1">
                            {(field.choices ?? []).map((choice) => (
                                <label key={choice.value} className="flex items-center gap-2 text-ui">
                                    <input
                                        type="checkbox"
                                        className="ui-checkbox"
                                        disabled={!canEdit}
                                        checked={chosen.includes(choice.value)}
                                        onChange={(e) => onChange(e.target.checked ? [...chosen, choice.value] : chosen.filter((v) => v !== choice.value))}
                                    />
                                    {choice.label}
                                </label>
                            ))}
                        </div>
                    );
                }
                if (field.type === 'textarea' || field.type === 'rich_text') {
                    return <textarea {...common} rows={4} placeholder={placeholder} value={text} onChange={(e) => onChange(e.target.value)} />;
                }
                const type =
                    field.type === 'number' || field.type === 'decimal'
                        ? 'number'
                        : field.type === 'date'
                          ? 'date'
                          : field.type === 'datetime'
                            ? 'datetime-local'
                            : field.type === 'time'
                              ? 'time'
                              : field.type === 'color'
                                ? 'color'
                                : field.type === 'email'
                                  ? 'email'
                                  : field.type === 'url'
                                    ? 'url'
                                    : 'text';
                return (
                    <input
                        {...common}
                        type={type}
                        placeholder={placeholder}
                        step={field.type === 'decimal' ? 'any' : (field.settings.step as number | undefined)}
                        value={text}
                        onChange={(e) => onChange(e.target.value === '' ? null : type === 'number' ? Number(e.target.value) : e.target.value)}
                    />
                );
            }}
        </Field>
    );
}
