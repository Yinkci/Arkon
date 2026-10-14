import { Icon } from '@/Components/Icon';
import { Button, PanelSection } from '@/Components/ui';

export interface BindingSource {
    source: string;
    label: string;
    fields: { field: string; path: string[]; label: string; type: string; fits: string[] }[];
}

export interface Binding {
    source: string;
    field: string;
    path?: string[];
}

/** A property of the selected block that something could be bound to. */
export interface BindableProp {
    key: string;
    label: string;
    /** What the component wants here: a string, a number, a toggle or a picture. */
    shape: string;
}

const same = (a: Binding, field: BindingSource['fields'][number], source: string) =>
    a.source === source && a.field === field.field && (a.path ?? []).join('.') === field.path.join('.');

/**
 * Where the selected block's properties take their values from.
 *
 * Properties hold what somebody typed until they are told otherwise, so this stays out of the
 * way: it appears only when there is content to bind to, and a property is static until it is
 * deliberately pointed at a field. What was typed stays put as the fallback.
 */
export function BindingPanel({
    props: bindable,
    sources,
    bindings,
    canEdit,
    onChange,
}: {
    props: BindableProp[];
    sources: BindingSource[];
    bindings: Record<string, Binding>;
    canEdit: boolean;
    onChange(prop: string, binding: Binding | null): void;
}) {
    // Nothing to bind to, or nothing on this block that could take it: say nothing at all.
    const usable = bindable.filter((prop) => sources.some((source) => source.fields.some((field) => field.fits.includes(prop.shape))));
    if (usable.length === 0 || sources.length === 0) return null;

    const bound = Object.keys(bindings).length;

    return (
        <PanelSection
            title="Content"
            collapsible
            defaultOpen={bound > 0}
            data-testid="binding-panel"
            aside={bound > 0 ? <span className="t-meta">{bound} bound</span> : undefined}
        >
            <p className="t-meta">A bound property shows the content’s value. What is typed in stays as the fallback.</p>
            {usable.map((prop) => {
                const current = bindings[prop.key];
                const id = `bind-${prop.key}`;
                return (
                    <div key={prop.key}>
                        <label htmlFor={id} className="ui-label">
                            {prop.label}
                        </label>
                        <div className="flex items-center gap-1.5">
                            <select
                                id={id}
                                className="ui-input"
                                disabled={!canEdit}
                                value={current ? `${current.source}:${current.field}:${(current.path ?? []).join('.')}` : ''}
                                onChange={(e) => {
                                    if (e.target.value === '') return onChange(prop.key, null);
                                    const [source, field, path] = e.target.value.split(':');
                                    onChange(prop.key, {
                                        source: source!,
                                        field: field!,
                                        ...(path ? { path: path.split('.') } : {}),
                                    });
                                }}
                            >
                                <option value="">Static — what is typed in</option>
                                {sources.map((source) => {
                                    const fields = source.fields.filter((field) => field.fits.includes(prop.shape));
                                    if (fields.length === 0) return null;
                                    return (
                                        <optgroup key={source.source} label={source.label}>
                                            {fields.map((field) => (
                                                <option
                                                    key={`${field.field}.${field.path.join('.')}`}
                                                    value={`${source.source}:${field.field}:${field.path.join('.')}`}
                                                >
                                                    {field.label}
                                                </option>
                                            ))}
                                        </optgroup>
                                    );
                                })}
                            </select>
                            {current && canEdit && (
                                <Button variant="ghost" icon="unlink" aria-label={`Make ${prop.label} static again`} onClick={() => onChange(prop.key, null)}>
                                    <span className="sr-only">Make static</span>
                                </Button>
                            )}
                        </div>
                        {current && !sources.some((source) => source.fields.some((field) => same(current, field, source.source))) && (
                            <p role="alert" className="mt-1 flex items-center gap-1 text-xs text-danger">
                                <Icon name="alert" className="size-3.5 shrink-0" aria-hidden />
                                This field no longer exists. The typed value is shown instead.
                            </p>
                        )}
                    </div>
                );
            })}
        </PanelSection>
    );
}
