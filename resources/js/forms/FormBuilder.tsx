import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Button, EmptyState } from '@/Components/ui';
import { DragController } from '@/editor/drag/controller';
import { FormDragFeedback } from './dragFeedback';
import { ConditionEditor } from './ConditionEditor';
import { field, fieldTypes, formLimits, place, rows, newRow, copyField, remove, type Field, type FieldType } from './schema';
import { plural } from '@/lib/mutate';

export function FormBuilder({ fields, onChange, disabled }: { fields: Field[]; onChange: (fields: Field[]) => void; disabled: boolean }) {
    const [selected, setSelected] = useState<string | null>(null),
        [viewport, setViewport] = useState('desktop'),
        [deleteId, setDeleteId] = useState<string | null>(null);
    const canvas = useRef<HTMLDivElement>(null),
        version = useRef(0),
        current = useRef(fields);
    current.current = fields;
    const edit = (next: Field[]) => {
        version.current++;
        onChange(next);
    };
    const viewportRef = useRef(viewport);
    viewportRef.current = viewport;
    const feedbackRef = useRef<FormDragFeedback | null>(null);
    if (!feedbackRef.current)
        feedbackRef.current = new FormDragFeedback(
            () => canvas.current,
            () => viewportRef.current === 'mobile',
        );
    const feedback = feedbackRef.current;
    const controller = useRef<DragController | null>(null);
    if (!controller.current) controller.current = new DragController({ documentVersion: () => version.current, commit: () => false });
    const drag = controller.current;
    drag.setOptions({
        documentVersion: () => version.current,
        onEnd: ({ committed, session }) => feedback.finish(committed, session.source),
        commit: (session, result) => {
            const source = current.current.find((f) => f.id === session.source.nodeId) ?? field(session.source.type as FieldType);
            const next = result.parentId.startsWith('newrow:')
                ? newRow(current.current, source, Number(result.parentId.split(':')[1]))
                : place(current.current, source, result.parentId, result.index);
            if (JSON.stringify(next) === JSON.stringify(current.current)) return false;
            edit(next);
            setSelected(source.id);
            return true;
        },
    });
    const state = useSyncExternalStore(drag.subscribe, drag.getState, drag.getState);
    useEffect(() => {
        version.current++;
        drag.invalidate();
    }, [fields, drag]);
    useEffect(() => {
        drag.setEnabled(!disabled);
    }, [disabled, drag]);
    useEffect(() => {
        const unregister = drag.registerZone(feedback.zone);
        const resize = () => drag.cancel('stale');
        window.addEventListener('resize', resize);
        return () => {
            window.removeEventListener('resize', resize);
            unregister();
            drag.dispose();
            feedback.dispose();
        };
    }, [drag, feedback]);
    const groups = rows(fields),
        f = fields.find((f) => f.id === selected);
    const update = (change: Partial<Field>) => {
        if (f) edit(fields.map((x) => (x.id === f.id ? { ...x, ...change } : x)));
    };
    const add = (type: FieldType) => {
        const n = field(type);
        edit([...fields, n]);
        setSelected(n.id);
    };
    const duplicate = (f: Field) => {
        const result = copyField(fields, f);
        edit(result.fields);
        setSelected(result.copy.id);
    };
    const move = (f: Field, delta: number) => {
        const i = fields.findIndex((x) => x.id === f.id),
            target = i + delta;
        if (target < 0 || target >= fields.length) return;
        const next = [...fields];
        next.splice(i, 1);
        next.splice(target, 0, { ...f, row: f.id, width: 12 });
        edit(next);
    };
    const erase = () => {
        if (!deleteId) return;
        edit(remove(fields, deleteId));
        setSelected(null);
        setDeleteId(null);
    };
    const pending = fields.find((x) => x.id === deleteId);
    return (
        <div className="grid items-start gap-4 xl:grid-cols-[12rem_minmax(0,1fr)_19rem]">
            <aside className="space-y-4 rounded-lg border border-line bg-surface p-4">
                <h2 className="t-title">Add fields</h2>
                <p className="t-meta">Click to add. Drag into a row for columns.</p>
                {['Basic', 'Choices', 'Layout'].map((group) => (
                    <section key={group}>
                        <h3 className="mb-2 t-eyebrow">{group}</h3>
                        <div className="grid gap-1">
                            {Object.entries(fieldTypes)
                                .filter(([, v]) => v.group === group)
                                .map(([type, v]) => (
                                    <Button
                                        key={type}
                                        disabled={disabled || fields.length >= formLimits.maxFields}
                                        className="justify-start touch-none"
                                        onPointerDown={(e) => {
                                            feedback.prepare(e, e.currentTarget);
                                            drag.press({ type, label: v.label, origin: 'palette' }, e, e.currentTarget);
                                        }}
                                        onClick={() => {
                                            if (!drag.consumeClick()) add(type as FieldType);
                                        }}
                                    >
                                        {v.label}
                                    </Button>
                                ))}
                        </div>
                    </section>
                ))}
                <p className="t-meta">File uploads and multi-step forms are not supported yet.</p>
            </aside>
            <section className="min-w-0 rounded-lg border border-line bg-sunken p-4">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 className="t-title">Form layout</h2>
                    <div className="flex gap-1">
                        {['desktop', 'tablet', 'mobile'].map((v) => (
                            <Button key={v} size="sm" variant={viewport === v ? 'primary' : 'ghost'} onClick={() => setViewport(v)}>
                                {v === 'desktop' ? 'Desktop' : v === 'tablet' ? 'Tablet' : 'Mobile'}
                            </Button>
                        ))}
                    </div>
                </div>
                <div
                    ref={canvas}
                    className="max-h-[70vh] min-h-80 overflow-y-auto rounded-lg border border-line bg-surface p-4"
                    style={{ maxWidth: viewport === 'mobile' ? 375 : viewport === 'tablet' ? 768 : undefined, marginInline: 'auto' }}
                >
                    <div className="space-y-4">
                        {!fields.length && (
                            <EmptyState icon="form" title="Add your first field">
                                Choose a field on the left or drag it here.
                            </EmptyState>
                        )}
                        {groups.map((r) => (
                            <div
                                key={r.id}
                                data-form-row={r.id}
                                className={
                                    'ak-form-row grid gap-3 rounded-lg ' +
                                    (state.session?.result &&
                                    typeof state.session.result === 'object' &&
                                    'parentId' in state.session.result &&
                                    state.session.result.parentId === r.id
                                        ? 'ring-2 ring-accent'
                                        : '')
                                }
                                style={{ gridTemplateColumns: viewport === 'mobile' ? '1fr' : 'repeat(12,minmax(0,1fr))' }}
                            >
                                {r.fields.map((item) => (
                                    <div
                                        key={item.id}
                                        data-form-field={item.id}
                                        style={{ gridColumn: viewport === 'mobile' ? undefined : `span ${item.width}` }}
                                        className={
                                            'ak-form-field min-w-0 rounded-lg border p-3 ' +
                                            (selected === item.id ? 'border-accent bg-accent-soft' : 'border-line bg-surface')
                                        }
                                    >
                                        <div className="mb-2 flex items-center justify-between gap-1">
                                            <button
                                                type="button"
                                                disabled={disabled}
                                                aria-label={'Select ' + item.label}
                                                onClick={() => setSelected(item.id)}
                                                className="truncate text-left t-label"
                                            >
                                                {item.label}
                                                {item.required ? ' *' : ''}
                                            </button>
                                            <Button
                                                size="sm"
                                                icon="grip"
                                                className="touch-none cursor-grab"
                                                disabled={disabled}
                                                aria-label={'Move ' + item.label}
                                                aria-describedby="form-drag-instructions"
                                                onClick={() => {
                                                    if (!drag.consumeClick()) setSelected(item.id);
                                                }}
                                                onPointerDown={(e) => {
                                                    feedback.prepare(e, e.currentTarget);
                                                    drag.press(
                                                        { type: item.type, nodeId: item.id, label: item.label, origin: 'canvas' },
                                                        e,
                                                        e.currentTarget,
                                                        () => setSelected(item.id),
                                                    );
                                                }}
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            disabled={disabled}
                                            onClick={() => setSelected(item.id)}
                                            aria-label={'Configure ' + item.label}
                                            className="w-full text-left text-muted"
                                        >
                                            <FieldPreview field={item} />
                                        </button>
                                        {selected === item.id && (
                                            <div className="mt-3 flex flex-wrap gap-1">
                                                <Button size="sm" disabled={disabled || fields.length >= formLimits.maxFields} onClick={() => duplicate(item)}>
                                                    Duplicate
                                                </Button>
                                                <Button size="sm" variant="quiet-danger" disabled={disabled} onClick={() => setDeleteId(item.id)}>
                                                    Delete
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    disabled={disabled || fields[0]?.id === item.id}
                                                    onClick={() => move(item, -1)}
                                                    aria-label={'Move ' + item.label + ' up'}
                                                >
                                                    ↑
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    disabled={disabled || fields.at(-1)?.id === item.id}
                                                    onClick={() => move(item, 1)}
                                                    aria-label={'Move ' + item.label + ' down'}
                                                >
                                                    ↓
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ))}
                        <div id="form-drag-instructions" className="min-h-10 border-t border-dashed border-line pt-3 t-meta">
                            {state.session?.result && typeof state.session.result === 'object' && 'label' in state.session.result
                                ? state.session.result.label
                                : 'Drag between rows to reorder, or into a row for columns.'}
                        </div>
                    </div>
                </div>
                <p role="status" className="mt-2 t-meta">
                    {state.phase === 'dragging'
                        ? 'Dragging ' + state.session?.source.label + '. Escape cancels.'
                        : fields.length + ' of ' + formLimits.maxFields + ' fields'}
                </p>
            </section>
            <aside className="rounded-lg border border-line bg-surface p-4">
                {f ? (
                    <fieldset disabled={disabled} className="space-y-4">
                        <h2 className="t-title">Field settings</h2>
                        <p className="t-meta">{fieldTypes[f.type].label}</p>
                        {['email', 'url', 'date', 'time'].includes(f.type) && (
                            <p className="t-meta">The {fieldTypes[f.type].label.toLowerCase()} format is validated automatically.</p>
                        )}
                        <label className="ui-field">
                            Label
                            <input className="ui-input" value={f.label} maxLength={120} onChange={(e) => update({ label: e.target.value })} />
                        </label>
                        {!['section', 'divider'].includes(f.type) && (
                            <label className="ui-check">
                                <input type="checkbox" checked={f.required} onChange={(e) => update({ required: e.target.checked })} />
                                Required
                            </label>
                        )}
                        <label className="ui-field">
                            Description
                            <textarea className="ui-input" value={f.description} maxLength={500} onChange={(e) => update({ description: e.target.value })} />
                        </label>
                        {!['divider', 'section', 'checkbox', 'checkboxes', 'radio'].includes(f.type) && (
                            <label className="ui-field">
                                Placeholder
                                <input className="ui-input" value={f.placeholder} maxLength={120} onChange={(e) => update({ placeholder: e.target.value })} />
                            </label>
                        )}
                        {['select', 'radio', 'checkboxes'].includes(f.type) && (
                            <section className="space-y-3 border-t border-line pt-4">
                                <h3 className="t-title">Choices</h3>
                                <p className="t-meta">Labels are displayed. Values are stored in entries.</p>
                                {f.choices.map((c, i) => (
                                    <div key={i} className="space-y-2 rounded-md border border-line p-2">
                                        <label className="ui-field">
                                            Choice {i + 1} label
                                            <input
                                                className="ui-input"
                                                value={c.label}
                                                maxLength={100}
                                                onChange={(e) => update({ choices: f.choices.map((x, n) => (n === i ? { ...x, label: e.target.value } : x)) })}
                                            />
                                        </label>
                                        <label className="ui-field">
                                            Stored value
                                            <input
                                                className="ui-input"
                                                value={c.value}
                                                maxLength={100}
                                                onChange={(e) => update({ choices: f.choices.map((x, n) => (n === i ? { ...x, value: e.target.value } : x)) })}
                                            />
                                        </label>
                                        <div className="flex gap-1">
                                            <Button
                                                size="sm"
                                                disabled={i === 0}
                                                onClick={() => {
                                                    const choices = [...f.choices];
                                                    [choices[i - 1], choices[i]] = [choices[i]!, choices[i - 1]!];
                                                    update({ choices });
                                                }}
                                            >
                                                ↑
                                            </Button>
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    update({
                                                        choices: [
                                                            ...f.choices.slice(0, i + 1),
                                                            { label: c.label + ' copy', value: c.value + '_copy' },
                                                            ...f.choices.slice(i + 1),
                                                        ],
                                                    })
                                                }
                                            >
                                                Copy
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="quiet-danger"
                                                disabled={f.choices.length === 1}
                                                onClick={() => update({ choices: f.choices.filter((_, n) => n !== i) })}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                                <Button
                                    size="sm"
                                    disabled={f.choices.length >= formLimits.maxChoices}
                                    onClick={() => update({ choices: [...f.choices, { label: 'New choice', value: 'choice_' + (f.choices.length + 1) }] })}
                                >
                                    Add choice
                                </Button>
                                <BulkChoices key={f.id} choices={f.choices} onApply={(choices) => update({ choices })} />
                            </section>
                        )}
                        {!['section', 'divider', 'checkboxes'].includes(f.type) && (
                            <label className="ui-field">
                                Default value
                                {['select', 'radio'].includes(f.type) ? (
                                    <select className="ui-input" value={f.defaultValue} onChange={(e) => update({ defaultValue: e.target.value })}>
                                        <option value="">None</option>
                                        {f.choices.map((c, i) => (
                                            <option key={i} value={c.value}>
                                                {c.label}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <input className="ui-input" value={f.defaultValue} onChange={(e) => update({ defaultValue: e.target.value })} />
                                )}
                            </label>
                        )}
                        {f.type === 'number' &&
                            ['min', 'max', 'step'].map((k) => (
                                <label key={k} className="ui-field">
                                    {k === 'min' ? 'Minimum' : k === 'max' ? 'Maximum' : 'Step'}
                                    <input
                                        type="number"
                                        className="ui-input"
                                        value={f[k as 'min'] ?? ''}
                                        onChange={(e) => update({ [k]: e.target.value === '' ? null : Number(e.target.value) })}
                                    />
                                </label>
                            ))}
                        <details className="space-y-3 border-t border-line pt-4">
                            <summary className="cursor-pointer t-title">Layout</summary>
                            <label className="ui-field">
                                Row
                                <select
                                    className="ui-input"
                                    value={f.row}
                                    onChange={(e) => edit(place(fields, f, e.target.value, fields.filter((x) => x.row === e.target.value).length))}
                                >
                                    {groups.map((r, i) => (
                                        <option key={r.id} value={r.id}>
                                            Row {i + 1} · {plural(r.fields.length, 'field')}
                                        </option>
                                    ))}
                                    <option value={'new_' + f.id}>New row</option>
                                </select>
                            </label>
                            <label className="ui-field">
                                Width
                                <select className="ui-input" value={f.width} onChange={(e) => update({ width: Number(e.target.value) as Field['width'] })}>
                                    {[
                                        [12, 'Full'],
                                        [6, 'Half'],
                                        [4, 'Third'],
                                        [3, 'Quarter'],
                                    ].map(([w, label]) => (
                                        <option key={w} value={w}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <p className="t-meta">Fields stack on mobile.</p>
                        </details>
                        <details className="space-y-3 border-t border-line pt-4">
                            <summary className="cursor-pointer t-title">Conditional logic</summary>
                            <ConditionEditor value={f.condition} fields={fields.filter((x) => x.id !== f.id)} onChange={(condition) => update({ condition })} />
                        </details>
                        <details>
                            <summary className="cursor-pointer t-label">Advanced</summary>
                            <p className="mt-2 t-meta">Stable field reference (cannot be renamed)</p>
                            <code className="break-all text-xs">{f.id}</code>
                        </details>
                    </fieldset>
                ) : (
                    <EmptyState compact icon="sliders" title="Select a field">
                        Select a field in the layout to configure it.
                    </EmptyState>
                )}
            </aside>
            <ConfirmDialog
                open={!!pending}
                title={'Delete ' + (pending?.label ?? 'field') + '?'}
                confirmLabel="Delete field"
                tone="danger"
                onClose={() => setDeleteId(null)}
                onConfirm={async () => {
                    erase();
                    return null;
                }}
            >
                Existing entries keep their values. Conditions referring to this field will be removed. Notifications containing its merge tag must be updated
                before saving.
            </ConfirmDialog>
        </div>
    );
}
function FieldPreview({ field: f }: { field: Field }) {
    if (f.type === 'divider') return <span className="block border-t border-line" />;
    if (f.type === 'section') return <span className="t-section">{f.label}</span>;
    if (['radio', 'checkboxes'].includes(f.type))
        return (
            <span className="grid gap-2">
                {f.choices.map((c, i) => (
                    <span key={i}>
                        {f.type === 'radio' ? '○' : '□'} {c.label}
                    </span>
                ))}
            </span>
        );
    if (f.type === 'checkbox') return <span>□ Consent checkbox</span>;
    return (
        <span className={'block rounded-md border border-line bg-sunken p-2 text-xs ' + (f.type === 'textarea' ? 'min-h-20' : '')}>
            {f.type === 'select' ? 'Choose…' : f.placeholder || f.defaultValue || fieldTypes[f.type].label}
        </span>
    );
}

function BulkChoices({ choices, onApply }: { choices: Field['choices']; onApply: (choices: Field['choices']) => void }) {
    const [text, setText] = useState(choices.map((c) => c.label).join('\n'));
    return (
        <details>
            <summary className="cursor-pointer t-label">Bulk choices</summary>
            <p className="mt-2 t-meta">One label per line. Existing labels keep their stored values. New labels use the label as their value.</p>
            <textarea className="ui-input mt-2" aria-label="Bulk choices" value={text} onChange={(e) => setText(e.target.value)} />
            <Button
                size="sm"
                disabled={!text.trim()}
                onClick={() => {
                    const labels = text
                        .split('\n')
                        .map((x) => x.trim())
                        .filter(Boolean)
                        .slice(0, formLimits.maxChoices);
                    onApply(labels.map((label) => choices.find((c) => c.label === label) ?? { label, value: label }));
                }}
            >
                Apply choices
            </Button>
        </details>
    );
}
