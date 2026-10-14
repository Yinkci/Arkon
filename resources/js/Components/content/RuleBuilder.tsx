import { Icon } from '@/Components/Icon';
import { Button } from '@/Components/ui';

export interface RuleAttribute {
    key: string;
    label: string;
    operators: { key: string; label: string }[];
    values: { value: string; label: string }[];
}

export interface Rule {
    attribute: string;
    operator: string;
    value: string;
}

export interface RuleSet {
    rules: Rule[];
}

export interface LocationRules {
    groups: RuleSet[];
}

/**
 * Where a field group's fields apply.
 *
 * Rules stacked inside one card must all hold; a second card is an alternative. The words "and"
 * and "or" are on the page rather than left to be inferred, because a rule builder that reads
 * wrongly is worse than no rule builder.
 */
export function RuleBuilder({
    rules,
    attributes,
    missing = [],
    onChange,
}: {
    rules: LocationRules;
    attributes: RuleAttribute[];
    /** Values whose target no longer exists, so the row can say so instead of looking empty. */
    missing?: { attribute: string; value: string }[];
    onChange(rules: LocationRules): void;
}) {
    const attributeOf = (key: string) => attributes.find((a) => a.key === key) ?? attributes[0]!;
    const isMissing = (rule: Rule) => missing.some((m) => m.attribute === rule.attribute && m.value === rule.value);

    const edit = (setIndex: number, ruleIndex: number, patch: Partial<Rule>) => {
        const groups = rules.groups.map((set, i) =>
            i !== setIndex ? set : { rules: set.rules.map((rule, j) => (j === ruleIndex ? { ...rule, ...patch } : rule)) },
        );
        onChange({ groups });
    };

    const blank = (attribute: RuleAttribute): Rule => ({
        attribute: attribute.key,
        operator: attribute.operators[0]!.key,
        value: attribute.values[0]?.value ?? '',
    });

    if (attributes.length === 0) return null;

    return (
        <div className="space-y-3">
            {rules.groups.length === 0 && (
                <p className="t-meta">These fields apply nowhere yet. Add a rule to say where they belong — for example “Content type is Services”.</p>
            )}

            {rules.groups.map((set, setIndex) => (
                <div key={setIndex}>
                    {setIndex > 0 && <p className="mb-2 t-eyebrow">or</p>}
                    <div className="rounded-lg border border-line bg-sunken p-3">
                        {set.rules.map((rule, ruleIndex) => {
                            const attribute = attributeOf(rule.attribute);
                            return (
                                <div key={ruleIndex} className="mb-2 last:mb-0">
                                    {ruleIndex > 0 && <p className="mb-2 t-eyebrow">and</p>}
                                    <div className="flex flex-wrap items-center gap-2">
                                        <select
                                            aria-label="What to check"
                                            className="ui-input w-auto"
                                            value={rule.attribute}
                                            onChange={(e) => edit(setIndex, ruleIndex, blank(attributeOf(e.target.value)))}
                                        >
                                            {attributes.map((a) => (
                                                <option key={a.key} value={a.key}>
                                                    {a.label}
                                                </option>
                                            ))}
                                        </select>
                                        <select
                                            aria-label="How to compare"
                                            className="ui-input w-auto"
                                            value={rule.operator}
                                            onChange={(e) => edit(setIndex, ruleIndex, { operator: e.target.value })}
                                        >
                                            {attribute.operators.map((op) => (
                                                <option key={op.key} value={op.key}>
                                                    {op.label}
                                                </option>
                                            ))}
                                        </select>
                                        <select
                                            aria-label="Which one"
                                            className="ui-input w-auto min-w-40 flex-1"
                                            value={rule.value}
                                            onChange={(e) => edit(setIndex, ruleIndex, { value: e.target.value })}
                                        >
                                            {isMissing(rule) && <option value={rule.value}>{rule.value} — no longer exists</option>}
                                            {attribute.values.map((v) => (
                                                <option key={v.value} value={v.value}>
                                                    {v.label}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            variant="ghost"
                                            icon="close"
                                            aria-label="Remove this rule"
                                            onClick={() => {
                                                const groups = rules.groups
                                                    .map((s, i) => (i !== setIndex ? s : { rules: s.rules.filter((_, j) => j !== ruleIndex) }))
                                                    .filter((s) => s.rules.length > 0);
                                                onChange({ groups });
                                            }}
                                        >
                                            <span className="sr-only">Remove</span>
                                        </Button>
                                    </div>
                                    {isMissing(rule) && (
                                        <p className="mt-1 text-xs text-danger">
                                            What this rule names has been deleted. Choose something else, or remove the rule.
                                        </p>
                                    )}
                                </div>
                            );
                        })}
                        <Button
                            size="sm"
                            icon="plus"
                            className="mt-2"
                            onClick={() =>
                                onChange({
                                    groups: rules.groups.map((s, i) => (i !== setIndex ? s : { rules: [...s.rules, blank(attributes[0]!)] })),
                                })
                            }
                        >
                            And
                        </Button>
                    </div>
                </div>
            ))}

            <Button icon="plus" size="sm" onClick={() => onChange({ groups: [...rules.groups, { rules: [blank(attributes[0]!)] }] })}>
                {rules.groups.length === 0 ? 'Add a rule' : 'Or'}
            </Button>
        </div>
    );
}

/** The rules in words, matching what the server says on list screens. */
export function RuleSummary({ summary }: { summary: string }) {
    return (
        <p className="flex items-center gap-1.5 t-meta">
            <Icon name="target" className="size-3.5 shrink-0 text-faint" aria-hidden />
            These fields apply to: {summary}
        </p>
    );
}
