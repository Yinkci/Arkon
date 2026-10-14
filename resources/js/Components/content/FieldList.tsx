import { Icon } from '@/Components/Icon';
import { MenuItem, RowMenu } from '@/Components/ListManagement';
import type { FieldDefinition, FieldTypeInfo } from './FieldDrawer';

/**
 * The list of fields being designed, used by both the content type editor and the field group
 * editor — they are editing the same kind of thing, so they show it the same way.
 *
 * Fields are identified by their key, never by their position, so reordering moves a row without
 * moving anybody's settings.
 */
export function FieldList({
    fields,
    fieldTypes,
    badField,
    error,
    empty,
    onEdit,
    onChange,
}: {
    fields: FieldDefinition[];
    fieldTypes: FieldTypeInfo[];
    /** The key of a field the server objected to, so its message appears on that row. */
    badField?: string | null;
    error?: string | null;
    empty: string;
    onEdit(field: FieldDefinition, index: number): void;
    onChange(fields: FieldDefinition[]): void;
}) {
    const move = (index: number, by: number) => {
        const next = [...fields];
        const target = index + by;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target]!, next[index]!];
        onChange(next);
    };

    const duplicate = (index: number) => {
        const field = fields[index]!;
        const taken = new Set(fields.map((f) => f.key));
        let key = `${field.key}_copy`;
        let n = 2;
        while (taken.has(key)) key = `${field.key}_copy_${n++}`;
        // A deep copy: two fields must never share one settings object.
        const copy: FieldDefinition = {
            ...field,
            key,
            label: `${field.label} copy`,
            settings: { ...(field.settings ?? {}) },
            ...(field.choices ? { choices: field.choices.map((choice) => ({ ...choice })) } : {}),
        };
        onChange([...fields.slice(0, index + 1), copy, ...fields.slice(index + 1)]);
    };

    if (fields.length === 0) return <p className="t-meta">{empty}</p>;

    return (
        <ul className="divide-y divide-line">
            {fields.map((field, index) => (
                <li key={field.key || index} className="flex items-center gap-3 py-2">
                    <Icon name="grip" className="size-3.5 shrink-0 text-faint" aria-hidden />
                    <button type="button" className="min-w-0 flex-1 text-left" onClick={() => onEdit(field, index)}>
                        <span className="font-medium">{field.label || 'Untitled field'}</span>
                        <span className="t-meta ml-2 font-mono">{field.key}</span>
                        {badField === field.key && error && (
                            <span role="alert" className="block text-xs text-danger">
                                {error}
                            </span>
                        )}
                    </button>
                    <span className="t-meta shrink-0">{fieldTypes.find((t) => t.key === field.type)?.label ?? field.type}</span>
                    <RowMenu label={`${field.label || 'Field'} actions`}>
                        {(close) => (
                            <>
                                <MenuItem
                                    disabled={index === 0}
                                    onSelect={() => {
                                        close();
                                        move(index, -1);
                                    }}
                                >
                                    Move up
                                </MenuItem>
                                <MenuItem
                                    disabled={index === fields.length - 1}
                                    onSelect={() => {
                                        close();
                                        move(index, 1);
                                    }}
                                >
                                    Move down
                                </MenuItem>
                                <MenuItem
                                    onSelect={() => {
                                        close();
                                        onEdit(field, index);
                                    }}
                                >
                                    Edit field
                                </MenuItem>
                                <MenuItem
                                    onSelect={() => {
                                        close();
                                        duplicate(index);
                                    }}
                                >
                                    Duplicate
                                </MenuItem>
                                <MenuItem
                                    tone="danger"
                                    onSelect={() => {
                                        close();
                                        onChange(fields.filter((_, i) => i !== index));
                                    }}
                                >
                                    Remove
                                </MenuItem>
                            </>
                        )}
                    </RowMenu>
                </li>
            ))}
        </ul>
    );
}
