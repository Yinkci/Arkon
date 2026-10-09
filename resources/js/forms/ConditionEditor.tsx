import { Button } from '@/Components/ui';
import { formLimits, type Condition, type Field } from './schema';
export function ConditionEditor({
    value,
    fields,
    onChange,
    disabled = false,
}: {
    value: Condition;
    fields: Field[];
    onChange: (c: Condition) => void;
    disabled?: boolean;
}) {
    const available = fields.filter((f) => !['section', 'divider'].includes(f.type));
    return (
        <fieldset disabled={disabled} className="space-y-3">
            <label className="ui-check">
                <input
                    type="checkbox"
                    checked={!!value}
                    disabled={!available.length}
                    onChange={(e) => onChange(e.target.checked ? { mode: 'all', rules: [{ fieldId: available[0]!.id, operator: 'is', value: '' }] } : null)}
                />
                Enable conditional logic
            </label>
            {value && (
                <>
                    <label className="ui-field">
                        Match
                        <select className="ui-input" value={value.mode} onChange={(e) => onChange({ ...value, mode: e.target.value as 'all' | 'any' })}>
                            <option value="all">All conditions</option>
                            <option value="any">Any condition</option>
                        </select>
                    </label>
                    {value.rules.map((rule, i) => {
                        const type = available.find((f) => f.id === rule.fieldId)?.type;
                        return (
                            <div key={i} className="space-y-2 border-t border-line pt-3">
                                <label className="ui-field">
                                    Field
                                    <select
                                        className="ui-input"
                                        value={rule.fieldId}
                                        onChange={(e) =>
                                            onChange({
                                                ...value,
                                                rules: value.rules.map((r, n) => (n === i ? { ...r, fieldId: e.target.value, operator: 'is' } : r)),
                                            })
                                        }
                                    >
                                        {available.map((f) => (
                                            <option key={f.id} value={f.id}>
                                                {f.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="ui-field">
                                    Comparison
                                    <select
                                        className="ui-input"
                                        value={rule.operator}
                                        onChange={(e) =>
                                            onChange({ ...value, rules: value.rules.map((r, n) => (n === i ? { ...r, operator: e.target.value } : r)) })
                                        }
                                    >
                                        {[
                                            ['is', 'is'],
                                            ['is_not', 'is not'],
                                            ['contains', 'contains'],
                                            ['not_contains', 'does not contain'],
                                            ...(type === 'number'
                                                ? [
                                                      ['greater', 'greater than'],
                                                      ['less', 'less than'],
                                                  ]
                                                : []),
                                            ['empty', 'is empty'],
                                            ['not_empty', 'is not empty'],
                                        ].map(([key, label]) => (
                                            <option key={key} value={key}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                {!['empty', 'not_empty'].includes(rule.operator) && (
                                    <label className="ui-field">
                                        Value
                                        <input
                                            className="ui-input"
                                            value={rule.value}
                                            onChange={(e) =>
                                                onChange({ ...value, rules: value.rules.map((r, n) => (n === i ? { ...r, value: e.target.value } : r)) })
                                            }
                                        />
                                    </label>
                                )}
                                <Button
                                    size="sm"
                                    variant="quiet-danger"
                                    onClick={() => onChange(value.rules.length === 1 ? null : { ...value, rules: value.rules.filter((_, n) => n !== i) })}
                                >
                                    Remove condition
                                </Button>
                            </div>
                        );
                    })}
                    <Button
                        size="sm"
                        disabled={value.rules.length >= formLimits.maxConditions}
                        onClick={() => onChange({ ...value, rules: [...value.rules, { fieldId: available[0]!.id, operator: 'is', value: '' }] })}
                    >
                        Add condition
                    </Button>
                </>
            )}
        </fieldset>
    );
}
