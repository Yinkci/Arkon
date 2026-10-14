import { useState } from 'react';
import { Icon } from '@/Components/Icon';
import { Button, Field } from '@/Components/ui';
import { FieldList } from './FieldList';
import type { FieldDefinition, FieldTypeInfo } from './FieldDrawer';

/** One kind of section an author may add: a stable key, a name, its fields, and how many of it. */
export interface LayoutDefinition {
    key: string;
    label: string;
    fields: FieldDefinition[];
    min?: number | null;
    max?: number | null;
}

const keyFrom = (label: string) =>
    label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .replace(/^([0-9])/, 'l_$1')
        .slice(0, 40);

/**
 * The layouts of a content-sections field: the kinds of section an author may put in order.
 *
 * Each layout holds ordinary fields, designed with the same drawer as any other field, because
 * they are the same fields. What a layout does not have is spacing, colour or breakpoints —
 * how a section looks is the template's business, not the content's.
 */
export function LayoutsEditor({
    layouts,
    fieldTypes,
    canEdit = true,
    onChange,
    onEditField,
}: {
    layouts: LayoutDefinition[];
    fieldTypes: FieldTypeInfo[];
    canEdit?: boolean;
    onChange(layouts: LayoutDefinition[]): void;
    /** Opens the field drawer for a field of one layout (-1 adds a new one). */
    onEditField(layoutIndex: number, field: FieldDefinition, fieldIndex: number): void;
}) {
    const [open, setOpen] = useState<number | null>(layouts.length === 1 ? 0 : null);
    const [touched, setTouched] = useState<Record<number, boolean>>({});

    const put = (index: number, values: Partial<LayoutDefinition>) => onChange(layouts.map((layout, i) => (i === index ? { ...layout, ...values } : layout)));
    const move = (index: number, by: number) => {
        const next = [...layouts];
        const to = index + by;
        if (to < 0 || to >= next.length) return;
        [next[index], next[to]] = [next[to]!, next[index]!];
        onChange(next);
    };
    const add = () => {
        onChange([...layouts, { key: '', label: '', fields: [] }]);
        setOpen(layouts.length);
    };

    return (
        <fieldset data-testid="layouts-editor">
            <legend className="ui-label">Kinds of section</legend>
            <p className="t-meta mb-2">
                Each one is a shape an author can add, as often as you allow. The fields inside are ordinary fields; how a section looks is decided by the
                template that renders it.
            </p>
            <ul className="space-y-2">
                {layouts.map((layout, index) => (
                    <li key={index} className="rounded border border-line">
                        <div className="flex items-center gap-2 px-2 py-1.5">
                            <button
                                type="button"
                                className="flex min-w-0 flex-1 items-center gap-1.5 text-left text-ui"
                                aria-expanded={open === index}
                                onClick={() => setOpen(open === index ? null : index)}
                            >
                                <Icon name="chevronRight" className={`size-3.5 transition-transform ${open === index ? 'rotate-90' : ''}`} aria-hidden />
                                <span className="truncate">{layout.label || 'Unnamed section'}</span>
                                <span className="t-meta">{layout.fields.length === 1 ? '1 field' : `${layout.fields.length} fields`}</span>
                            </button>
                            {canEdit && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        icon="chevronUp"
                                        aria-label={`Move ${layout.label || 'section'} up`}
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                    >
                                        <span className="sr-only">Move up</span>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        icon="chevronDown"
                                        aria-label={`Move ${layout.label || 'section'} down`}
                                        disabled={index === layouts.length - 1}
                                        onClick={() => move(index, 1)}
                                    >
                                        <span className="sr-only">Move down</span>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        aria-label={`Remove ${layout.label || 'section'}`}
                                        onClick={() => onChange(layouts.filter((_, i) => i !== index))}
                                    >
                                        <span className="sr-only">Remove</span>
                                    </Button>
                                </>
                            )}
                        </div>
                        {open === index && (
                            <div className="space-y-3 border-t border-line px-2 py-2">
                                <Field label="Name" hint="What an author picks from the list.">
                                    {({ id }) => (
                                        <input
                                            id={id}
                                            className="ui-input"
                                            disabled={!canEdit}
                                            value={layout.label}
                                            onChange={(e) => put(index, { label: e.target.value, key: touched[index] ? layout.key : keyFrom(e.target.value) })}
                                        />
                                    )}
                                </Field>
                                <Field label="Key" hint="Stored with every section of this kind. Keep it stable.">
                                    {({ id }) => (
                                        <input
                                            id={id}
                                            className="ui-input font-mono"
                                            disabled={!canEdit}
                                            value={layout.key}
                                            onChange={(e) => {
                                                setTouched((t) => ({ ...t, [index]: true }));
                                                put(index, { key: e.target.value });
                                            }}
                                        />
                                    )}
                                </Field>
                                <div className="flex gap-2">
                                    <Field label="Fewest">
                                        {({ id }) => (
                                            <input
                                                id={id}
                                                className="ui-input w-20"
                                                type="number"
                                                min={0}
                                                max={50}
                                                disabled={!canEdit}
                                                value={layout.min ?? ''}
                                                onChange={(e) => put(index, { min: e.target.value === '' ? null : Number(e.target.value) })}
                                            />
                                        )}
                                    </Field>
                                    <Field label="Most">
                                        {({ id }) => (
                                            <input
                                                id={id}
                                                className="ui-input w-20"
                                                type="number"
                                                min={1}
                                                max={50}
                                                disabled={!canEdit}
                                                value={layout.max ?? ''}
                                                onChange={(e) => put(index, { max: e.target.value === '' ? null : Number(e.target.value) })}
                                            />
                                        )}
                                    </Field>
                                </div>
                                <div>
                                    <span className="ui-label">Fields in this section</span>
                                    <FieldList
                                        fields={layout.fields}
                                        fieldTypes={fieldTypes}
                                        empty="No fields yet."
                                        onEdit={(field, fieldIndex) => onEditField(index, field, fieldIndex)}
                                        onChange={(fields) => put(index, { fields })}
                                    />
                                    {canEdit && (
                                        <Button
                                            size="sm"
                                            icon="plus"
                                            className="mt-2"
                                            onClick={() => onEditField(index, { key: '', label: '', type: 'text', settings: {}, choices: [] }, -1)}
                                        >
                                            Add field
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            {canEdit && (
                <Button size="sm" icon="plus" className="mt-2" onClick={add}>
                    Add a kind of section
                </Button>
            )}
        </fieldset>
    );
}
