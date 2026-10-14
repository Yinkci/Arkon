/**
 * What the content type editor sends, and what it can tell the author before sending it.
 *
 * The contract, in one place: a field's `settings` is an object of named options — `{}` when
 * nothing is configured, never a list, never a JSON string — and `choices` is a list of
 * `{label, value}`. The server canonicalises and has the final word; this keeps the request
 * honest and lets the editor explain the obvious problems without a round trip.
 */
export interface FieldDraft {
    key: string;
    label: string;
    type: string;
    settings: Record<string, unknown>;
    choices?: { label: string; value: string }[];
    default?: unknown;
}

/** Whatever else a definition carries travels along untouched; only these are reasoned about. */
export interface TypeDraft {
    key: string;
    name: string;
    singular: string;
    fields: FieldDraft[];
}

export const TYPE_KEY = /^[a-z][a-z0-9_]{0,31}$/;
export const FIELD_KEY = /^[a-z][a-z0-9_]{0,39}$/;

/** The first thing wrong that the author can fix without asking the server, or null. */
export function firstProblem(draft: TypeDraft): string | null {
    if (draft.singular.trim() === '') return 'Give the type a singular name.';
    if (draft.name.trim() === '') return 'Give the type a plural name.';
    if (!TYPE_KEY.test(draft.key)) return 'The key must start with a letter and use only lowercase letters, numbers and underscores.';
    const keys = draft.fields.map((field) => field.key);
    const repeated = keys.find((key, index) => keys.indexOf(key) !== index);
    if (repeated) return `Two fields cannot share the key "${repeated}".`;
    const nameless = draft.fields.find((field) => field.label.trim() === '');
    if (nameless) return `The field "${nameless.key}" needs a label.`;
    const badKey = draft.fields.find((field) => !FIELD_KEY.test(field.key));
    if (badKey) return `${badKey.label}: the key must start with a letter and use only lowercase letters, numbers and underscores.`;
    return null;
}

/**
 * The request body. Settings are copied into a fresh object per field, so two fields can never
 * end up sharing one settings object through the editor's state.
 */
export function requestBody<T extends TypeDraft>(draft: T, options: { confirmRemovals?: boolean; expectedVersion?: number } = {}) {
    return {
        ...draft,
        fields: draft.fields.map((field) => ({
            ...field,
            settings: { ...(field.settings ?? {}) },
            ...(field.choices ? { choices: field.choices.map((choice) => ({ ...choice })) } : {}),
        })),
        confirmRemovals: options.confirmRemovals ?? false,
        expectedVersion: options.expectedVersion,
    };
}
