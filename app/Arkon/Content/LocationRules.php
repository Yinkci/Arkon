<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Permissions;
use App\Arkon\Support\Json;

/**
 * Where a field group applies.
 *
 * Rules inside a group are joined with AND; the groups themselves are alternatives, joined with
 * OR. So two groups holding one rule each read as "either of these", and one group holding two
 * rules reads as "both of these" — which covers (A AND B) OR C without a language to parse.
 *
 * Only attributes Arkon actually has are offered. There is no page template concept, so there is
 * no template rule; when one exists, it is added here and every reader gains it at once.
 */
final class LocationRules
{
    /**
     * What a rule may ask about, and how. `targets` says where the admin gets the values to
     * choose from, and `evaluate` is the only place each attribute's meaning is decided.
     */
    public const ATTRIBUTES = [
        'scope' => ['label' => 'Where', 'operators' => ['is', 'is_not'], 'targets' => 'scopes'],
        'content_type' => ['label' => 'Content type', 'operators' => ['is', 'is_not'], 'targets' => 'content_types'],
        'entry' => ['label' => 'Entry', 'operators' => ['is', 'is_not'], 'targets' => 'entries'],
        'taxonomy_term' => ['label' => 'Category or tag', 'operators' => ['contains', 'does_not_contain'], 'targets' => 'terms'],
        'user_role' => ['label' => 'The person editing', 'operators' => ['is', 'is_not'], 'targets' => 'roles'],
    ];

    public const OPERATORS = [
        'is' => 'is',
        'is_not' => 'is not',
        'contains' => 'has',
        'does_not_contain' => 'does not have',
    ];

    /** What a set of fields can belong to. Entries are the usual answer; the site itself is the other. */
    public const SCOPES = ['entry' => 'Entries', 'site' => 'Site data'];

    private const MAX_GROUPS = 10;

    private const MAX_RULES = 10;

    /**
     * Checks rules and returns them canonically. Values are kept as given even when what they
     * name has since been deleted: a rule pointing at something missing is shown and repaired in
     * the editor, never silently dropped (which would quietly change where fields appear).
     *
     * @return array{groups: list<array{rules: list<array{attribute: string, operator: string, value: string}>}>}
     */
    public static function validate(mixed $rules): array
    {
        $rules = Json::toArray($rules);
        $sets = is_array($rules) ? ($rules['groups'] ?? []) : [];
        if (! is_array($sets) || ! array_is_list($sets)) {
            throw new ValidationException('Location rules must be a list of rule groups.');
        }
        if (count($sets) > self::MAX_GROUPS) {
            throw new ValidationException('A field group may have at most '.self::MAX_GROUPS.' rule groups.');
        }
        $out = [];
        foreach ($sets as $set) {
            $inner = is_array($set) ? ($set['rules'] ?? []) : null;
            if (! is_array($inner) || ! array_is_list($inner)) {
                throw new ValidationException('Each rule group must hold a list of rules.');
            }
            if ($inner === []) {
                continue; // An empty group would mean "always", which is said by having no groups.
            }
            if (count($inner) > self::MAX_RULES) {
                throw new ValidationException('A rule group may hold at most '.self::MAX_RULES.' rules.');
            }
            $out[] = ['rules' => array_values(array_map(self::rule(...), $inner))];
        }

        return ['groups' => $out];
    }

    private static function rule(mixed $rule): array
    {
        $attribute = is_array($rule) ? ($rule['attribute'] ?? null) : null;
        if (! is_string($attribute) || ! isset(self::ATTRIBUTES[$attribute])) {
            throw new ValidationException('"'.(is_string($attribute) ? $attribute : 'That').'" is not something a rule can ask about.');
        }
        $operator = $rule['operator'] ?? null;
        if (! is_string($operator) || ! in_array($operator, self::ATTRIBUTES[$attribute]['operators'], true)) {
            throw new ValidationException(self::ATTRIBUTES[$attribute]['label'].' cannot be compared with "'.(is_string($operator) ? $operator : '?').'".');
        }
        $value = $rule['value'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new ValidationException(self::ATTRIBUTES[$attribute]['label'].' needs something to compare with.');
        }
        if ($attribute === 'user_role' && ! in_array($value, Permissions::ROLES, true)) {
            throw new ValidationException('"'.$value.'" is not a role on this site.');
        }
        if ($attribute === 'scope' && ! isset(self::SCOPES[$value])) {
            throw new ValidationException('"'.$value.'" is not somewhere fields can belong.');
        }

        return ['attribute' => $attribute, 'operator' => $operator, 'value' => trim($value)];
    }

    /**
     * Whether these rules apply to what is being edited.
     *
     * The context says what is known: its kind, which entry it is, the terms it carries and who
     * is editing. Anything absent makes a rule about it false rather than throwing, so asking
     * about a page while knowing only its kind is safe.
     *
     * @param  array{kind?: ?string, entryId?: ?string, terms?: list<string>, role?: ?string}  $context
     */
    public static function match(array $rules, array $context): bool
    {
        $sets = $rules['groups'] ?? [];
        if ($sets === []) {
            return false; // Placed nowhere: the fields apply nowhere.
        }
        foreach ($sets as $set) {
            $all = true;
            foreach ($set['rules'] ?? [] as $rule) {
                if (! self::matchOne($rule, $context)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true; // One satisfied group is enough: the groups are alternatives.
            }
        }

        return false;
    }

    private static function matchOne(array $rule, array $context): bool
    {
        $value = $rule['value'];
        $holds = match ($rule['attribute']) {
            'scope' => ($context['scope'] ?? 'entry') === $value,
            'content_type' => ($context['kind'] ?? null) === $value,
            'entry' => ($context['entryId'] ?? null) === $value,
            'taxonomy_term' => in_array($value, $context['terms'] ?? [], true),
            'user_role' => ($context['role'] ?? null) === $value,
            default => false,
        };

        return in_array($rule['operator'], ['is_not', 'does_not_contain'], true) ? ! $holds : $holds;
    }

    /**
     * The rules in words, for overview screens: "Services, or Pages that have News".
     *
     * `names` turns a stored value into what a person calls it; a value whose target has been
     * deleted is shown as missing rather than as a bare id.
     *
     * @param  callable(string, string): ?string  $names  (attribute, value) => label or null
     */
    public static function describe(array $rules, callable $names): string
    {
        $sets = $rules['groups'] ?? [];
        if ($sets === []) {
            return 'Nowhere yet';
        }
        $parts = [];
        foreach ($sets as $set) {
            $clauses = [];
            foreach ($set['rules'] ?? [] as $rule) {
                $label = $names($rule['attribute'], $rule['value']);
                $what = $label ?? $rule['value'].' (missing)';
                $clauses[] = match ($rule['attribute']) {
                    'scope' => $rule['operator'] === 'is' ? $what : 'anywhere but '.$what,
                    'content_type' => $rule['operator'] === 'is' ? $what : 'anything but '.$what,
                    'entry' => $rule['operator'] === 'is' ? $what : 'any entry but '.$what,
                    'taxonomy_term' => ($rule['operator'] === 'contains' ? 'has ' : 'does not have ').$what,
                    'user_role' => ($rule['operator'] === 'is' ? 'edited by ' : 'not edited by ').$what.'s',
                    default => $what,
                };
            }
            $parts[] = implode(' and ', $clauses);
        }

        return implode(', or ', $parts);
    }

    /**
     * Every value these rules name, by attribute, so a caller can look them all up at once
     * instead of once per rule.
     *
     * @return array<string, list<string>>
     */
    public static function referenced(array $rules): array
    {
        $out = [];
        foreach ($rules['groups'] ?? [] as $set) {
            foreach ($set['rules'] ?? [] as $rule) {
                $out[$rule['attribute']][] = $rule['value'];
            }
        }

        return array_map(fn ($values) => array_values(array_unique($values)), $out);
    }
}
