import { useState, type ReactNode } from 'react';
import { Icon } from '@/Components/Icon';
import { Button } from '@/Components/ui';
import type { ContentFieldDefinition } from '@/types';

/** A repeater row as the editor holds it: the author's values plus the id Arkon gave the row. */
type Row = Record<string, unknown> & { _id?: string };

/**
 * A group or a repeater inside the entry editor.
 *
 * A group is its fields under a heading. A repeater is a list of rows, each collapsible and
 * titled by one of its own values, that can be added, removed and reordered — by id, never by
 * position, so moving a row moves what it holds with it.
 */
export function NestedField({
    field,
    value,
    canEdit,
    onChange,
    renderChild,
}: {
    field: ContentFieldDefinition;
    value: unknown;
    canEdit: boolean;
    onChange(value: unknown): void;
    /** One ordinary field control, so this component needs no knowledge of field types. */
    renderChild(child: ContentFieldDefinition, value: unknown, onChange: (value: unknown) => void): ReactNode;
}) {
    const children = field.fields ?? [];

    if (field.type === 'group') {
        const current = (value ?? {}) as Record<string, unknown>;
        return (
            <fieldset className="rounded-md border border-line p-3">
                <legend className="ui-label px-1">{field.label}</legend>
                {(field.settings.help as string) && <p className="t-meta mb-2">{field.settings.help as string}</p>}
                <div className="space-y-3">
                    {children.map((child) => renderChild(child, current[child.key], (next) => onChange({ ...current, [child.key]: next })))}
                </div>
            </fieldset>
        );
    }

    return <Repeater field={field} rows={(Array.isArray(value) ? value : []) as Row[]} canEdit={canEdit} onChange={onChange} renderChild={renderChild} />;
}

function Repeater({
    field,
    rows,
    canEdit,
    onChange,
    renderChild,
}: {
    field: ContentFieldDefinition;
    rows: Row[];
    canEdit: boolean;
    onChange(value: unknown): void;
    renderChild(child: ContentFieldDefinition, value: unknown, onChange: (value: unknown) => void): ReactNode;
}) {
    const children = field.fields ?? [];
    const [closed, setClosed] = useState<string[]>([]);
    const max = field.settings.maxRows as number | null;
    const addLabel = (field.settings.addLabel as string) || `Add ${field.label.toLowerCase()}`;
    // A row is titled by one of its own values when the field says which; "FAQ: What does it
    // cost?" beats "Row 3" for anyone scanning a long list.
    const labelKey = (field.settings.rowLabel as string) || children[0]?.key;

    const keyOf = (row: Row, index: number) => row._id ?? `new-${index}`;
    const title = (row: Row, index: number) => {
        const value = labelKey ? row[labelKey] : null;
        return typeof value === 'string' && value.trim() !== '' ? value : `${field.label} ${index + 1}`;
    };

    const replace = (index: number, row: Row) => onChange(rows.map((r, i) => (i === index ? row : r)));
    const move = (index: number, by: number) => {
        const target = index + by;
        if (target < 0 || target >= rows.length) return;
        const next = [...rows];
        [next[index], next[target]] = [next[target]!, next[index]!];
        onChange(next);
    };

    return (
        <div>
            <p className="ui-label">{field.label}</p>
            {(field.settings.help as string) && <p className="t-meta">{field.settings.help as string}</p>}
            <ul className="mt-1 space-y-2">
                {rows.map((row, index) => {
                    const id = keyOf(row, index);
                    const open = !closed.includes(id);
                    return (
                        <li key={id} className="rounded-md border border-line">
                            <div className="flex items-center gap-1 px-2 py-1.5">
                                <button
                                    type="button"
                                    className="flex min-w-0 flex-1 items-center gap-1.5 text-left text-ui"
                                    aria-expanded={open}
                                    onClick={() => setClosed((c) => (open ? [...c, id] : c.filter((x) => x !== id)))}
                                >
                                    <Icon name="chevronRight" className={`size-3.5 shrink-0 text-faint ${open ? 'rotate-90' : ''}`} aria-hidden />
                                    <span className="truncate">{title(row, index)}</span>
                                </button>
                                {canEdit && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon="arrowUp"
                                            aria-label="Move up"
                                            disabled={index === 0}
                                            onClick={() => move(index, -1)}
                                        >
                                            <span className="sr-only">Move up</span>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon="arrowDown"
                                            aria-label="Move down"
                                            disabled={index === rows.length - 1}
                                            onClick={() => move(index, 1)}
                                        >
                                            <span className="sr-only">Move down</span>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            aria-label={`Remove ${title(row, index)}`}
                                            onClick={() => onChange(rows.filter((_, i) => i !== index))}
                                        >
                                            <span className="sr-only">Remove</span>
                                        </Button>
                                    </>
                                )}
                            </div>
                            {open && (
                                <div className="space-y-3 border-t border-line p-3">
                                    {children.map((child) => renderChild(child, row[child.key], (next) => replace(index, { ...row, [child.key]: next })))}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
            {canEdit && (max === null || max === undefined || rows.length < max) && (
                <Button size="sm" icon="plus" className="mt-2" onClick={() => onChange([...rows, {}])}>
                    {addLabel}
                </Button>
            )}
            {rows.length === 0 && <p className="t-meta mt-1">No rows yet.</p>}
        </div>
    );
}
