import registry from '../../arkon/forms.json';
import { newRequestKey } from '@/lib/api';
export const formLimits = registry;
export const fieldTypes = registry.types;
export type FieldType = keyof typeof fieldTypes;
export type Condition = { mode: 'all' | 'any'; rules: { fieldId: string; operator: string; value: string }[] } | null;
export type Field = {
    id: string;
    label: string;
    type: FieldType;
    required: boolean;
    description: string;
    placeholder: string;
    defaultValue: string;
    row: string;
    width: 3 | 4 | 6 | 12;
    choices: { label: string; value: string }[];
    condition: Condition;
    min: number | null;
    max: number | null;
    step: number | null;
};
export type Notification = {
    id: string;
    name: string;
    enabled: boolean;
    recipient: string;
    replyTo: string;
    fromName: string;
    subject: string;
    message: string;
    condition: Condition;
};
export type Definition = {
    schemaVersion: 2;
    name: string;
    submitLabel: string;
    successMessage: string;
    description: string;
    active: boolean;
    fields: Field[];
    confirmation: { type: 'message' | 'redirect'; message: string; url: string };
    notifications: Notification[];
};
export type FormRow = {
    id: string;
    version: number;
    publishedVersion: number | null;
    definition: Definition;
    status: string;
    hasDraftChanges: boolean;
    createdAt: string;
    updatedAt: string;
    notificationEmail: string | null;
    entries: number | null;
    usage?: string[];
};
export type Permissions = { edit: boolean; publish: boolean; manage: boolean; notifications: boolean; entries: boolean; export: boolean };
export const id = () =>
    'f_' +
    newRequestKey()
        .replace(/[^a-z0-9]/gi, '')
        .slice(-28)
        .toLowerCase();
export function field(type: FieldType): Field {
    const key = id();
    return {
        id: key,
        label: fieldTypes[type].label,
        type,
        required: false,
        description: '',
        placeholder: '',
        defaultValue: '',
        row: key,
        width: 12,
        choices: ['select', 'radio', 'checkboxes'].includes(type)
            ? [
                  { label: 'First choice', value: 'first' },
                  { label: 'Second choice', value: 'second' },
              ]
            : [],
        condition: null,
        min: null,
        max: null,
        step: null,
    };
}
export function template(name: string, type: string): Definition {
    const fields: Field[] = type === 'blank' ? [] : type === 'newsletter' ? [field('email')] : [field('text'), field('email'), field('textarea')];
    if (type === 'contact') {
        fields[0]!.label = 'Your name';
        fields[2]!.label = 'Message';
    }
    fields.forEach((f) => (f.required = true));
    return {
        schemaVersion: 2,
        name,
        submitLabel: type === 'newsletter' ? 'Subscribe' : 'Send message',
        successMessage: 'Thank you. Your submission has been received.',
        description: '',
        active: true,
        confirmation: { type: 'message', message: 'Thank you. Your submission has been received.', url: '' },
        notifications: [],
        fields,
    };
}
export function rows(fields: Field[]): { id: string; fields: Field[] }[] {
    const output: { id: string; fields: Field[] }[] = [];
    for (const f of fields) {
        let row = output.find((r) => r.id === f.row);
        if (!row) {
            row = { id: f.row, fields: [] };
            output.push(row);
        }
        row.fields.push(f);
    }
    return output;
}
export function place(fields: Field[], source: Field, rowId: string, index: number): Field[] {
    const groups = rows(fields),
        origin = groups.find((r) => r.fields.some((f) => f.id === source.id)),
        target = groups.find((r) => r.id === rowId);
    if (target && target.fields.filter((f) => f.id !== source.id).length >= 4) return fields;
    const originalIndex = target?.fields.findIndex((f) => f.id === source.id) ?? -1;
    if (originalIndex >= 0 && originalIndex < index) index--;
    for (const group of groups) group.fields = group.fields.filter((f) => f.id !== source.id);
    const nextTarget = groups.find((r) => r.id === rowId) ?? { id: rowId, fields: [] };
    if (!target) groups.push(nextTarget);
    nextTarget.fields.splice(Math.max(0, Math.min(index, nextTarget.fields.length)), 0, { ...source, row: rowId });
    return groups
        .filter((r) => r.fields.length)
        .flatMap((r) => r.fields.map((f) => (r.id === rowId || r.id === origin?.id ? { ...f, width: (12 / r.fields.length) as Field['width'] } : f)));
}
export function newRow(fields: Field[], source: Field, index: number): Field[] {
    const groups = rows(fields);
    const originalRow = groups.findIndex((r) => r.fields.some((f) => f.id === source.id));
    if (originalRow >= 0 && groups[originalRow]!.fields.length === 1 && originalRow < index) index--;
    const rest = groups.map((r) => ({ ...r, fields: r.fields.filter((f) => f.id !== source.id) })).filter((r) => r.fields.length);
    rest.splice(Math.max(0, Math.min(index, rest.length)), 0, { id: source.id, fields: [{ ...source, row: source.id, width: 12 }] });
    return rest.flatMap((r) => r.fields.map((f) => (groups[originalRow]?.id === r.id ? { ...f, width: (12 / r.fields.length) as Field['width'] } : f)));
}
export function copyField(fields: Field[], source: Field): { fields: Field[]; copy: Field } {
    const key = id();
    const copy = { ...structuredClone(source), id: key, row: key, label: source.label + ' (copy)' };
    const groups = rows(fields),
        position = groups.findIndex((r) => r.fields.some((f) => f.id === source.id));
    groups.splice(position + 1, 0, { id: key, fields: [{ ...copy, width: 12 }] });
    return { fields: groups.flatMap((r) => r.fields), copy };
}
export function remove(fields: Field[], key: string): Field[] {
    return fields
        .filter((f) => f.id !== key)
        .map((f) => ({ ...f, condition: f.condition ? { ...f.condition, rules: f.condition.rules.filter((r) => r.fieldId !== key) } : null }))
        .map((f) => ({ ...f, condition: f.condition?.rules.length ? f.condition : null }));
}
