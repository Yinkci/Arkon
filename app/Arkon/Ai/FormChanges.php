<?php

namespace App\Arkon\Ai;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormDefinition;
use App\Arkon\Forms\FormService;
use App\Arkon\Support\Json;

/**
 * Form edits AI may propose, with everything the form editor can do except notification recipients
 * (deliberately human-only: an address set by AI could send entries somewhere unintended).
 *
 * Edits are small operations on the current definition, never a regenerated form: field ids are
 * kept, so existing entries stay linked to their fields and nothing is recreated. The result is
 * validated by the form editor's own rules (FormDefinition::validate, schema version 2).
 *
 *   name | description | submit | success | redirect   text
 *   open | close   whether the form accepts submissions (it goes live only when a person publishes)
 *   add       field (new id), type, text (label) and any field setting, before (field id or null = end)
 *   update    field, any non-null setting; when {mode, rules: []} clears a condition
 *   move      field, before
 *   remove    field
 *
 * Field settings: text (label), required, width (3, 4, 6, 12 columns), row (fields sharing a row id
 * sit side by side), placeholder, help, default, choices, min, max, step, when (show conditions).
 */
final class FormChanges
{
    public const OPS = ['name', 'description', 'submit', 'success', 'redirect', 'open', 'close', 'add', 'update', 'move', 'remove'];

    /** For revision instructions (the website instructions carry WebsiteProposalService::FORM_RULES). */
    public const RULES_SHORT = 'Forms: change them only with formChanges (name, description, submit, success, redirect, open, close, add, update, move, remove); fields sharing a row id sit side by side with widths adding up to 12 (for example 6 and 6); keep field ids; notification recipients are set by people, never here.';

    /** Form editor settings AI deliberately cannot change, with the reason (checked by the parity test). */
    public const HUMAN_ONLY = ['notifications' => 'Who receives form entries by email is set by people: an address chosen by AI could send entries somewhere unintended.'];

    /** Form editor settings and the edit that changes each (checked by the parity test). */
    public const FORM_SETTINGS = ['name' => 'name', 'description' => 'description', 'active' => 'open/close', 'submitLabel' => 'submit', 'successMessage' => 'success', 'confirmation' => 'success/redirect'];

    /** Settings an update may change, with the definition key each writes. */
    public const SETTINGS = ['text' => 'label', 'required' => 'required', 'width' => 'width', 'row' => 'row', 'placeholder' => 'placeholder', 'help' => 'description', 'default' => 'defaultValue', 'min' => 'min', 'max' => 'max', 'step' => 'step'];

    /** The JSON schema of the formChanges list. Compact (it travels on the local CLI's command line); validate() checks lengths. */
    public static function schema(array $formIds): array
    {
        $types = array_keys(FormDefinition::registry()['types']);
        $n = fn (string $type) => ['type' => [$type, 'null']];

        return ['type' => 'array', 'maxItems' => 60, 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['form', 'op', 'field', 'text', 'type', 'required', 'width', 'row', 'placeholder', 'help', 'default', 'choices', 'min', 'max', 'step', 'when', 'before'],
            'properties' => [
                'form' => ['type' => 'string', 'enum' => $formIds], 'op' => ['type' => 'string', 'enum' => self::OPS], 'field' => $n('string'), 'text' => $n('string'),
                'type' => ['type' => ['string', 'null'], 'enum' => [...$types, null]], 'required' => $n('boolean'), 'width' => ['type' => ['integer', 'null'], 'enum' => [3, 4, 6, 12, null]],
                'row' => $n('string'), 'placeholder' => $n('string'), 'help' => $n('string'), 'default' => $n('string'), 'choices' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                'min' => $n('number'), 'max' => $n('number'), 'step' => $n('number'),
                'when' => ['type' => ['object', 'null'], 'additionalProperties' => false, 'required' => ['mode', 'rules'], 'properties' => ['mode' => ['type' => 'string', 'enum' => ['all', 'any']],
                    'rules' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['field', 'operator', 'value'], 'properties' => [
                        'field' => ['type' => 'string'], 'operator' => ['type' => 'string', 'enum' => ['is', 'is_not', 'contains', 'not_contains', 'greater', 'less', 'empty', 'not_empty']], 'value' => ['type' => 'string']]]]]],
                'before' => $n('string'),
            ]]];
    }

    /** How a form is described to the model: its settings and fields in order, without notification settings. */
    public static function context(string $id, array $definition): array
    {
        $d = self::normalize($definition);

        return ['id' => $id, 'name' => $d['name'], 'submit' => $d['submitLabel'], 'confirmation' => $d['confirmation'],
            'fields' => array_map(fn ($f) => array_filter(['id' => $f['id'], 'type' => $f['type'], 'label' => $f['label'], 'required' => $f['required'], 'row' => $f['row'], 'width' => $f['width'],
                'placeholder' => $f['placeholder'], 'help' => $f['description'], 'default' => $f['defaultValue'], 'choices' => array_column($f['choices'], 'label'), 'min' => $f['min'], 'max' => $f['max'], 'step' => $f['step'], 'when' => $f['condition']],
                fn ($v) => $v !== null && $v !== '' && $v !== []), $d['fields'])];
    }

    /** Any stored definition (legacy or current) in the form editor's current shape. */
    public static function normalize(?array $definition): array
    {
        if ($definition === null) {
            return FormDefinition::modern(['name' => 'Contact form', 'submitLabel' => 'Send', 'successMessage' => 'Thank you. Your message was received.', 'fields' => []]);
        }

        return ($definition['schemaVersion'] ?? null) === 2 ? $definition : FormDefinition::modern(FormService::validateDefinition($definition));
    }

    /**
     * Applies one form's edits to its definition.
     *
     * @return array{definition: array, changes: list<string>}
     */
    public static function apply(?array $definition, array $ops, string $label = 'Form'): array
    {
        $d = self::normalize($definition);
        $notifications = $d['notifications'] ?? [];
        $changes = [];
        $index = fn (string $id) => array_search($id, array_column($d['fields'], 'id'), true);
        foreach (array_values($ops) as $i => $op) {
            $at = "{$label}, change ".($i + 1);
            $kind = $op['op'] ?? null;
            $text = is_string($op['text'] ?? null) ? trim($op['text']) : null;
            $field = is_string($op['field'] ?? null) ? $op['field'] : null;
            switch ($kind) {
                case 'open':
                case 'close':
                    $changes[] = $kind === 'open' ? 'Form accepts submissions' : 'Form stops accepting submissions';
                    $d['active'] = $kind === 'open';
                    break;
                case 'description':
                    $changes[] = 'Form description: '.($text ?? '');
                    $d['description'] = $text ?? '';
                    break;
                case 'name':
                case 'submit':
                case 'success':
                case 'redirect':
                    if ($text === null || $text === '') {
                        throw new ValidationException("{$at}: give the new text.");
                    }
                    if ($kind === 'name') {
                        $changes[] = 'Form name: '.$d['name'].' → '.$text;
                        $d['name'] = $text;
                    } elseif ($kind === 'submit') {
                        $changes[] = 'Submit button: '.$d['submitLabel'].' → '.$text;
                        $d['submitLabel'] = $text;
                    } elseif ($kind === 'success') {
                        $changes[] = 'Confirmation message: '.$text;
                        $d['confirmation'] = ['type' => 'message', 'message' => $text, 'url' => ''];
                        $d['successMessage'] = $text;
                    } else {
                        $changes[] = 'After sending, go to '.$text;
                        $d['confirmation'] = ['type' => 'redirect', 'message' => $d['confirmation']['message'] ?? '', 'url' => $text];
                    }
                    break;
                case 'add':
                    if ($field === null || $index($field) !== false) {
                        throw new ValidationException("{$at}: a new field needs a new id.");
                    }
                    if (! is_string($op['type'] ?? null) || $text === null) {
                        throw new ValidationException("{$at}: a new field needs a type and a label.");
                    }
                    $new = ['id' => $field, 'label' => $text, 'type' => $op['type'], 'required' => false, 'description' => '', 'placeholder' => '', 'defaultValue' => '', 'width' => 12, 'row' => $field, 'choices' => [], 'condition' => null, 'min' => null, 'max' => null, 'step' => null];
                    $new = self::set($new, $op);
                    array_splice($d['fields'], self::position($d['fields'], $op['before'] ?? null, $at), 0, [$new]);
                    $changes[] = 'Add field '.$new['label'];
                    break;
                case 'update':
                    $at2 = $field === null ? false : $index($field);
                    if ($at2 === false) {
                        throw new ValidationException("{$at}: there is no field {$field}.");
                    }
                    $before = $d['fields'][$at2];
                    $d['fields'][$at2] = self::set($before, $op);
                    $changes[] = 'Update field '.$before['label'].self::summary($before, $d['fields'][$at2]);
                    break;
                case 'move':
                    $from = $field === null ? false : $index($field);
                    if ($from === false) {
                        throw new ValidationException("{$at}: there is no field {$field}.");
                    }
                    [$moved] = array_splice($d['fields'], $from, 1);
                    array_splice($d['fields'], self::position($d['fields'], $op['before'] ?? null, $at), 0, [$moved]);
                    $changes[] = 'Move '.$moved['label'].(($op['before'] ?? null) === null ? ' to the end' : ' before '.$d['fields'][$index($op['before'])]['label']);
                    break;
                case 'remove':
                    $from = $field === null ? false : $index($field);
                    if ($from === false) {
                        throw new ValidationException("{$at}: there is no field {$field}.");
                    }
                    [$removed] = array_splice($d['fields'], $from, 1);
                    $changes[] = 'Remove field '.$removed['label'];
                    break;
                default:
                    throw new ValidationException("{$at}: unknown form change.");
            }
        }
        // Notification settings are never changed here; they are restored by the writer from the stored form.
        $d['notifications'] = [];
        try {
            $valid = FormDefinition::validate($d);
        } catch (ValidationException $e) {
            throw new ValidationException("{$label}: ".$e->getMessage(), $e->issues);
        }
        $valid['notifications'] = $notifications;

        return ['definition' => ($definition['schemaVersion'] ?? null) === 2 ? $valid : (self::legacy($valid) ?? $valid), 'changes' => $changes];
    }

    /**
     * The same form in the original format when it uses nothing newer (no rows, widths, placeholders,
     * help, defaults, conditions, number limits or redirect). Such forms render without the form
     * script, as before; a form edited with newer settings uses the current format, like the editor.
     */
    private static function legacy(array $d): ?array
    {
        if ($d['confirmation']['type'] !== 'message' || ($d['description'] ?? '') !== '' || ! ($d['active'] ?? true) || $d['notifications'] !== []) {
            return null;
        }
        $fields = [];
        foreach ($d['fields'] as $f) {
            $choices = array_column($f['choices'], 'label');
            if (! in_array($f['type'], ['text', 'email', 'tel', 'textarea', 'select', 'checkbox'], true) || $f['width'] !== 12 || $f['row'] !== $f['id']
                || $f['placeholder'] !== '' || $f['description'] !== '' || $f['defaultValue'] !== '' || $f['condition'] !== null
                || $f['min'] !== null || $f['max'] !== null || $f['step'] !== null || $choices !== array_column($f['choices'], 'value')) {
                return null;
            }
            $fields[] = ['id' => $f['id'], 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'], ...($choices !== [] ? ['options' => $choices] : [])];
        }

        return ['name' => $d['name'], 'submitLabel' => $d['submitLabel'], 'successMessage' => $d['confirmation']['message'], 'fields' => $fields];
    }

    /** Groups formChanges by form id, keeping their order. @return array<string, list<array>> */
    public static function byForm(mixed $changes): array
    {
        $out = [];
        foreach (is_array($changes) ? $changes : [] as $op) {
            if (is_array($op) && is_string($op['form'] ?? null)) {
                $out[$op['form']][] = $op;
            }
        }

        return $out;
    }

    private static function set(array $f, array $op): array
    {
        foreach (self::SETTINGS as $from => $to) {
            if (array_key_exists($from, $op) && $op[$from] !== null) {
                $f[$to] = is_string($op[$from]) ? trim($op[$from]) : $op[$from];
            }
        }
        if (is_array($op['choices'] ?? null)) {
            $f['choices'] = array_map(fn ($c) => ['label' => (string) $c, 'value' => (string) $c], array_values(array_unique(array_filter($op['choices'], 'is_string'))));
        }
        if (is_string($op['type'] ?? null) && ($op['op'] ?? '') === 'update') {
            $f['type'] = $op['type'];
        }
        if (is_array($op['when'] ?? null)) {
            $rules = array_map(fn ($r) => ['fieldId' => (string) ($r['field'] ?? ''), 'operator' => (string) ($r['operator'] ?? ''), 'value' => (string) ($r['value'] ?? '')], $op['when']['rules'] ?? []);
            $f['condition'] = $rules === [] ? null : ['mode' => $op['when']['mode'] ?? 'all', 'rules' => $rules];
        }

        return $f;
    }

    private static function position(array $fields, mixed $before, string $at): int
    {
        if ($before === null) {
            return count($fields);
        }
        $i = array_search($before, array_column($fields, 'id'), true);
        if ($i === false) {
            throw new ValidationException("{$at}: there is no field {$before} to place it before.");
        }

        return $i;
    }

    private static function summary(array $a, array $b): string
    {
        $parts = [];
        foreach (['label' => 'label', 'required' => 'required', 'width' => 'width', 'row' => 'row', 'placeholder' => 'placeholder', 'description' => 'help', 'type' => 'type'] as $key => $name) {
            if (Json::encode($a[$key] ?? null) !== Json::encode($b[$key] ?? null)) {
                $parts[] = $name.' '.(is_bool($b[$key]) ? ($b[$key] ? 'yes' : 'no') : (string) $b[$key]);
            }
        }

        return $parts === [] ? '' : ': '.implode(', ', $parts);
    }
}
