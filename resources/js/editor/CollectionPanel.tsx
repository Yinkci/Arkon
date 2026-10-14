import { Button, Field } from '@/Components/ui';
import type { CollectionFilterField, CollectionSources, CollectionState } from '@/types';

/** The query a content list holds: what it shows, in what order, how many, and narrowed by what. */
export interface CollectionQuery {
    source: string;
    sort: string;
    limit: number;
    filters: { field: string; operator: string; value: string; value2: string }[];
}

const OPERATOR_LABELS: Record<string, string> = {
    is: 'is',
    is_not: 'is not',
    contains: 'contains',
    gt: 'is more than',
    lt: 'is less than',
    between: 'is between',
    before: 'is before',
    after: 'is after',
    has_term: 'is in',
    is_true: 'is on',
    is_false: 'is off',
};

const SORTS: { value: string; label: string }[] = [
    { value: '-published_at', label: 'Newest first' },
    { value: 'published_at', label: 'Oldest first' },
    { value: 'title', label: 'Title A–Z' },
    { value: '-title', label: 'Title Z–A' },
    { value: '-modified_at', label: 'Recently changed' },
    { value: 'path', label: 'URL' },
];

export const emptyQuery = (): CollectionQuery => ({ source: '', sort: '-published_at', limit: 6, filters: [] });

/** The query as it is stored, with anything missing filled in, so the editor never reads undefined. */
export function queryOf(props: Record<string, unknown>): CollectionQuery {
    const raw = (props.query ?? {}) as Partial<CollectionQuery>;
    return {
        source: typeof raw.source === 'string' ? raw.source : '',
        sort: typeof raw.sort === 'string' ? raw.sort : '-published_at',
        limit: typeof raw.limit === 'number' ? raw.limit : 6,
        filters: Array.isArray(raw.filters)
            ? raw.filters.map((f) => ({
                  field: String(f?.field ?? ''),
                  operator: String(f?.operator ?? 'is'),
                  value: String(f?.value ?? ''),
                  value2: String(f?.value2 ?? ''),
              }))
            : [],
    };
}

/**
 * What a content list shows: a source, an order, a limit and any number of filters.
 *
 * Every choice here comes from the schema the server checks the query against — the kinds of
 * content this site has, their fields, and the operators each kind of field can answer — so the
 * editor cannot offer a query that would be refused, and a field that has since been removed is
 * reported rather than silently ignored.
 */
export function CollectionPanel({
    query,
    sources,
    state,
    canEdit,
    onChange,
}: {
    query: CollectionQuery;
    sources: CollectionSources;
    state?: CollectionState;
    canEdit: boolean;
    onChange(query: CollectionQuery): void;
}) {
    const related = query.source.startsWith('related:');
    const relatedField = related ? sources.related.find((r) => r.key === query.source.slice('related:'.length)) : undefined;
    const type = related ? undefined : sources.types.find((t) => t.key === query.source);
    const fields = type?.fields ?? [];
    const filterable = fields.filter((f) => f.operators.length > 0);
    const taxonomies = type?.taxonomies ?? [];
    const set = (values: Partial<CollectionQuery>) => onChange({ ...query, ...values });

    const fieldOf = (key: string): CollectionFilterField | undefined => fields.find((f) => f.key === key);
    const operatorsFor = (key: string): string[] => (taxonomies.some((t) => t.key === key) ? ['has_term'] : (fieldOf(key)?.operators ?? []));

    return (
        <div className="space-y-3" data-testid="collection-panel">
            {(state?.issues.length ?? 0) > 0 && (
                <p role="alert" className="text-xs text-danger">
                    {state!.issues.join(' ')}
                </p>
            )}
            <Field label="Show" hint="Which content this list draws from.">
                {({ id }) => (
                    <select
                        id={id}
                        className="ui-input"
                        disabled={!canEdit}
                        value={query.source}
                        onChange={(e) => set({ source: e.target.value, filters: [] })}
                    >
                        <option value="">Choose what to show…</option>
                        <optgroup label="Content">
                            {sources.types.map((t) => (
                                <option key={t.key} value={t.key}>
                                    {t.plural}
                                </option>
                            ))}
                        </optgroup>
                        {sources.related.length > 0 && (
                            <optgroup label="Related to this entry">
                                {sources.related.map((r) => (
                                    <option key={r.key} value={`related:${r.key}`}>
                                        {r.label} ({r.targetLabel})
                                    </option>
                                ))}
                            </optgroup>
                        )}
                    </select>
                )}
            </Field>

            <div className="flex gap-2">
                <Field label="Order">
                    {({ id }) => (
                        <select id={id} className="ui-input" disabled={!canEdit} value={query.sort} onChange={(e) => set({ sort: e.target.value })}>
                            {related && <option value="chosen">The order chosen here</option>}
                            {SORTS.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                            {fields
                                .filter((f) => f.sortable)
                                .flatMap((f) => [
                                    { value: `field:${f.key}`, label: `${f.label} (first to last)` },
                                    { value: `-field:${f.key}`, label: `${f.label} (last to first)` },
                                ])
                                .map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                        </select>
                    )}
                </Field>
                <Field label="How many">
                    {({ id }) => (
                        <input
                            id={id}
                            className="ui-input w-20"
                            type="number"
                            min={1}
                            max={48}
                            disabled={!canEdit}
                            value={query.limit}
                            onChange={(e) => set({ limit: Math.max(1, Math.min(48, Number(e.target.value) || 1)) })}
                        />
                    )}
                </Field>
            </div>

            {query.source !== '' && !related && (
                <fieldset className="space-y-2">
                    <legend className="ui-label">Only show items where…</legend>
                    {query.filters.map((filter, index) => {
                        const operators = operatorsFor(filter.field);
                        const field = fieldOf(filter.field);
                        const needsValue = !['is_true', 'is_false'].includes(filter.operator);
                        const change = (values: Partial<CollectionQuery['filters'][number]>) =>
                            set({ filters: query.filters.map((f, i) => (i === index ? { ...f, ...values } : f)) });
                        return (
                            <div key={index} className="space-y-1 rounded border border-line p-2">
                                <div className="flex gap-1.5">
                                    <select
                                        aria-label={`Filter ${index + 1} field`}
                                        className="ui-input min-w-0 flex-1"
                                        disabled={!canEdit}
                                        value={filter.field}
                                        onChange={(e) => {
                                            const next = e.target.value;
                                            change({ field: next, operator: operatorsFor(next)[0] ?? 'is', value: '', value2: '' });
                                        }}
                                    >
                                        <option value="">Choose a field…</option>
                                        {filterable.map((f) => (
                                            <option key={f.key} value={f.key}>
                                                {f.label}
                                            </option>
                                        ))}
                                        {taxonomies.map((t) => (
                                            <option key={t.key} value={t.key}>
                                                {t.label}
                                            </option>
                                        ))}
                                    </select>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        icon="trash"
                                        aria-label={`Remove filter ${index + 1}`}
                                        disabled={!canEdit}
                                        onClick={() => set({ filters: query.filters.filter((_, i) => i !== index) })}
                                    >
                                        <span className="sr-only">Remove</span>
                                    </Button>
                                </div>
                                {filter.field !== '' && (
                                    <div className="flex gap-1.5">
                                        <select
                                            aria-label={`Filter ${index + 1} test`}
                                            className="ui-input w-32 shrink-0"
                                            disabled={!canEdit}
                                            value={filter.operator}
                                            onChange={(e) => change({ operator: e.target.value })}
                                        >
                                            {operators.map((operator) => (
                                                <option key={operator} value={operator}>
                                                    {OPERATOR_LABELS[operator] ?? operator}
                                                </option>
                                            ))}
                                        </select>
                                        {needsValue &&
                                            (field?.choices.length ? (
                                                <select
                                                    aria-label={`Filter ${index + 1} value`}
                                                    className="ui-input min-w-0 flex-1"
                                                    disabled={!canEdit}
                                                    value={filter.value}
                                                    onChange={(e) => change({ value: e.target.value })}
                                                >
                                                    <option value="">Choose…</option>
                                                    {field.choices.map((choice) => (
                                                        <option key={choice.value} value={choice.value}>
                                                            {choice.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : (
                                                <input
                                                    aria-label={`Filter ${index + 1} value`}
                                                    className="ui-input min-w-0 flex-1"
                                                    type={
                                                        field && ['number', 'decimal'].includes(field.type)
                                                            ? 'number'
                                                            : field?.type === 'date'
                                                              ? 'date'
                                                              : 'text'
                                                    }
                                                    disabled={!canEdit}
                                                    value={filter.value}
                                                    onChange={(e) => change({ value: e.target.value })}
                                                />
                                            ))}
                                        {filter.operator === 'between' && (
                                            <input
                                                aria-label={`Filter ${index + 1} second value`}
                                                className="ui-input min-w-0 flex-1"
                                                type={field && ['number', 'decimal'].includes(field.type) ? 'number' : 'date'}
                                                disabled={!canEdit}
                                                value={filter.value2}
                                                onChange={(e) => change({ value2: e.target.value })}
                                            />
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                    {canEdit && query.filters.length < 5 && (
                        <Button
                            size="sm"
                            icon="plus"
                            onClick={() => set({ filters: [...query.filters, { field: '', operator: 'is', value: '', value2: '' }] })}
                        >
                            Add a filter
                        </Button>
                    )}
                </fieldset>
            )}

            {relatedField && <p className="t-meta">Shows what this entry’s “{relatedField.label}” points at, in the order chosen there.</p>}
            {state && state.issues.length === 0 && (
                <p className="t-meta" data-testid="collection-count">
                    {state.sample
                        ? 'Nothing published yet: the canvas shows one entry as a sample so the item below can be designed.'
                        : `Showing ${state.shown} of ${state.total} published ${state.total === 1 ? 'item' : 'items'}.`}
                </p>
            )}
        </div>
    );
}
