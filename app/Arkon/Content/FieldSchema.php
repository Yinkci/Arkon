<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;

/**
 * One field definition, wherever it is declared: on a content type directly, or in a field group
 * that several types share. There is one field engine in Arkon, so a Text field behaves the same
 * however it reached an entry — same keys, same settings, same validation, same storage.
 *
 * What a field is: a stable key (its identity in the API, in templates and in storage), a label
 * (free to reword), a type from the registry, and only the settings that type understands.
 */
final class FieldSchema
{
    /**
     * How deep fields may nest. One means a group or a repeater holds ordinary fields and
     * nothing structural, which is enough for an address or a list of questions and keeps a
     * definition from describing itself forever.
     */
    private const MAX_DEPTH = 1;

    /** The settings a field may carry beyond its identity, with the rule each must satisfy. */
    private const SETTING_RULES = [
        'targetType' => 'present|nullable|string|max:32',
        'multiple' => 'boolean',
        'maxItems' => 'nullable|integer|min:1|max:50',
        'minRows' => 'nullable|integer|min:0|max:1000',
        'maxRows' => 'nullable|integer|min:1|max:1000',
        'rowLabel' => 'present|nullable|string|max:60',
        'addLabel' => 'present|nullable|string|max:60',
        'placeholder' => 'present|nullable|string|max:120',
        'help' => 'present|nullable|string|max:500',
        'required' => 'boolean',
        'unique' => 'boolean',
        'private' => 'boolean',
        'minLength' => 'nullable|integer|min:0|max:100000',
        'maxLength' => 'nullable|integer|min:1|max:100000',
        'pattern' => 'present|nullable|string|max:200',
        'min' => 'nullable|numeric',
        'max' => 'nullable|numeric',
        'step' => 'nullable|numeric|gt:0',
        'unit' => 'present|nullable|string|max:20',
    ];

    /**
     * A whole list of fields in canonical form, with every key distinct.
     *
     * @param  mixed  $fields  the list as it arrived, in raw or internal form
     * @return list<array<string, mixed>>
     */
    public static function fields(mixed $fields, int $depth = 0): array
    {
        $fields = Json::toArray($fields);
        if (! is_array($fields) || ! array_is_list($fields)) {
            throw new ValidationException('Fields must be a list.');
        }
        $limit = FieldTypes::limit('fields');
        if (count($fields) > $limit) {
            throw new ValidationException("A field group or content type may hold at most {$limit} fields.");
        }
        $out = array_values(array_map(fn ($field) => self::one($field, $depth), $fields));
        self::assertDistinct(array_column($out, 'key'));

        return $out;
    }

    /** Two fields with one key would make the second unreachable through the API or a template. */
    public static function assertDistinct(array $keys, string $where = 'Two fields'): void
    {
        $repeated = array_values(array_unique(array_diff_assoc($keys, array_unique($keys))));
        if ($repeated !== []) {
            throw new ValidationException(
                "{$where} cannot share the key \"{$repeated[0]}\". Give each field its own key.",
                [['path' => 'fields', 'fieldKey' => $repeated[0], 'code' => 'duplicate_key', 'message' => 'Duplicate field key '.$repeated[0]]],
            );
        }
    }

    /** One field: a stable key, a known type, and only the settings that type understands. */
    public static function one(mixed $field, int $depth = 0): array
    {
        if (! is_array($field)) {
            throw new ValidationException('Each field must be an object with a key, a label and a type.');
        }
        // Named before anything else, so every message below can say which field it means.
        $name = is_string($field['label'] ?? null) && trim($field['label']) !== ''
            ? trim($field['label'])
            : (is_string($field['key'] ?? null) && $field['key'] !== '' ? $field['key'] : 'A field');
        $key = $field['key'] ?? '';
        if (! is_string($key) || ! preg_match('/'.FieldTypes::pattern('fieldKey').'/D', $key)) {
            throw self::problem($name, $key, 'key', 'invalid_key', is_string($key) && $key !== ''
                ? "{$name}: the key \"{$key}\" must start with a letter and use only lowercase letters, numbers and underscores."
                : "{$name} needs a key of lowercase letters, numbers and underscores.");
        }
        if (in_array($key, FieldTypes::reservedFieldKeys(), true)) {
            throw self::problem($name, $key, 'key', 'reserved_key', "{$name}: \"{$key}\" is something every entry already has. Choose another key.");
        }
        if (! is_string($field['label'] ?? null) || trim($field['label']) === '') {
            throw self::problem($name, $key, 'label', 'missing_label', "The field \"{$key}\" needs a label.");
        }
        if (mb_strlen($field['label']) > FieldTypes::limit('label')) {
            throw self::problem($name, $key, 'label', 'label_too_long', "{$name}: the label is too long.");
        }
        if (! FieldTypes::exists($field['type'] ?? null)) {
            throw self::problem($name, $key, 'type', 'unknown_type', "{$name}: \"".(is_string($field['type'] ?? null) ? $field['type'] : 'none').'" is not a kind of field Arkon offers.');
        }
        // The shape the admin, the API and AI all send: settings are a map of named options.
        // An absent or empty one is the ordinary case — a field with nothing configured.
        $given = $field['settings'] ?? [];
        if (! is_array($given) || array_is_list($given) && $given !== []) {
            throw self::problem($name, $key, 'settings', 'invalid_settings', "{$name}: its settings must be a set of named options.");
        }
        $unknown = array_diff(array_keys($given), array_keys(self::SETTING_RULES));
        if ($unknown !== []) {
            throw self::problem($name, $key, 'settings', 'unknown_setting', "{$name}: \"".reset($unknown).'" is not a setting Arkon knows.');
        }
        $wrongType = array_filter(array_keys($given), fn ($setting) => ! FieldTypes::allows($field['type'], $setting));
        if ($wrongType !== []) {
            throw self::problem($name, $key, 'settings', 'setting_not_for_type', "{$name}: a ".strtolower(FieldTypes::get($field['type'])['label']).' field has no "'.reset($wrongType).'" setting.');
        }
        $settings = [];
        foreach (self::SETTING_RULES as $setting => $rule) {
            if (! FieldTypes::allows($field['type'], $setting)) {
                continue;
            }
            $settings[$setting] = Input::validate(
                [$setting => $given[$setting] ?? self::blank($setting)],
                [$setting => $rule],
            )[$setting] ?? self::blank($setting);
        }
        $out = ['key' => $key, 'label' => trim($field['label']), 'type' => $field['type'], 'settings' => $settings];
        if (FieldTypes::takesChoices($field['type'])) {
            $out['choices'] = self::choices($field['choices'] ?? [], $name);
        }
        if (FieldTypes::takesChildren($field['type'])) {
            $out['fields'] = self::children($field, $name, $key, $depth);
        }
        if (FieldTypes::takesLayouts($field['type'])) {
            $out['layouts'] = self::layouts($field, $name, $key, $depth);
        }
        if (FieldTypes::allows($field['type'], 'default')) {
            // Checked against the field itself, so a default can never be a value the field rejects.
            $out['default'] = FieldValues::one($out, $field['default'] ?? null, defaulting: false);
        }
        if (($settings['minLength'] ?? null) !== null && ($settings['maxLength'] ?? null) !== null && $settings['minLength'] > $settings['maxLength']) {
            throw self::problem($name, $key, 'settings', 'bad_range', $name.': the shortest length must not exceed the longest.');
        }
        if (($settings['min'] ?? null) !== null && ($settings['max'] ?? null) !== null && $settings['min'] > $settings['max']) {
            throw self::problem($name, $key, 'settings', 'bad_range', $name.': the smallest value must not exceed the largest.');
        }
        if (($out['choices'] ?? null) === [] && FieldTypes::takesChoices($field['type'])) {
            throw self::problem($name, $key, 'choices', 'no_choices', $name.' needs at least one choice.');
        }

        return $out;
    }

    /**
     * The fields a group or a repeater holds. They are ordinary field definitions, checked by the
     * same engine — so a number inside a repeater is the same number it would be outside — but
     * they may not themselves hold fields: a definition that could nest forever is a definition
     * nobody can reason about, and an address or a list of questions does not need it.
     */
    private static function children(array $field, string $name, string $key, int $depth): array
    {
        $children = $field['fields'] ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw self::problem($name, $key, 'fields', 'invalid_children', "{$name}: its fields must be a list.");
        }
        if ($depth >= self::MAX_DEPTH) {
            throw self::problem($name, $key, 'type', 'too_deep', "{$name} cannot go inside another group or repeater.");
        }
        $limit = FieldTypes::limit('childFields');
        if (count($children) > $limit) {
            throw self::problem($name, $key, 'fields', 'too_many_children', "{$name}: a group or repeater may hold at most {$limit} fields.");
        }
        if ($children === []) {
            throw self::problem($name, $key, 'fields', 'no_children', "{$name} needs at least one field inside it.");
        }

        return self::fields($children, $depth + 1);
    }

    /**
     * The layouts a content-sections field offers: each a stable key, a name a person reads and
     * freely rewords, and its own fields.
     *
     * A layout is a shape, not a container: its fields are ordinary fields checked by the same
     * engine, and they may not themselves hold fields. That is what keeps this a way of
     * composing structured content rather than a second page builder.
     *
     * @return list<array{key: string, label: string, fields: list<array<string, mixed>>, min: ?int, max: ?int}>
     */
    private static function layouts(array $field, string $name, string $key, int $depth): array
    {
        $layouts = Json::toArray($field['layouts'] ?? []);
        if (! is_array($layouts) || ! array_is_list($layouts)) {
            throw self::problem($name, $key, 'layouts', 'invalid_layouts', "{$name}: its layouts must be a list.");
        }
        if ($depth >= self::MAX_DEPTH) {
            throw self::problem($name, $key, 'type', 'too_deep', "{$name} cannot go inside another group, repeater or content-sections field.");
        }
        if ($layouts === []) {
            throw self::problem($name, $key, 'layouts', 'no_layouts', "{$name} needs at least one layout: the kinds of section an author may add.");
        }
        $limit = FieldTypes::limit('layouts');
        if (count($layouts) > $limit) {
            throw self::problem($name, $key, 'layouts', 'too_many_layouts', "{$name}: at most {$limit} layouts.");
        }
        $out = [];
        foreach ($layouts as $layout) {
            $out[] = self::layout($layout, $name, $key, $depth);
        }
        $keys = array_column($out, 'key');
        $repeated = array_values(array_unique(array_diff_assoc($keys, array_unique($keys))));
        if ($repeated !== []) {
            throw self::problem($name, $key, 'layouts', 'duplicate_layout', "{$name}: two layouts cannot share the key \"{$repeated[0]}\".");
        }

        return $out;
    }

    /** One layout. Its key is its identity in stored content, so it is checked like a field key. */
    private static function layout(mixed $layout, string $name, string $key, int $depth): array
    {
        if (! is_array($layout)) {
            throw self::problem($name, $key, 'layouts', 'invalid_layout', "{$name}: each layout must be an object with a key, a name and fields.");
        }
        $layoutKey = $layout['key'] ?? '';
        if (! is_string($layoutKey) || ! preg_match('/'.FieldTypes::pattern('fieldKey').'/D', $layoutKey)) {
            throw self::problem($name, $key, 'layouts', 'invalid_layout_key', "{$name}: a layout key uses lowercase letters, numbers and underscores.");
        }
        if (! is_string($layout['label'] ?? null) || trim($layout['label']) === '') {
            throw self::problem($name, $key, 'layouts', 'missing_layout_label', "{$name}: the layout \"{$layoutKey}\" needs a name.");
        }
        if (mb_strlen($layout['label']) > FieldTypes::limit('label')) {
            throw self::problem($name, $key, 'layouts', 'layout_label_too_long', "{$name}: the name of \"{$layoutKey}\" is too long.");
        }
        $fields = Json::toArray($layout['fields'] ?? []);
        if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
            throw self::problem($name, $key, 'layouts', 'no_layout_fields', "{$name}: the layout \"{$layoutKey}\" needs at least one field.");
        }
        $limit = FieldTypes::limit('layoutFields');
        if (count($fields) > $limit) {
            throw self::problem($name, $key, 'layouts', 'too_many_layout_fields', "{$name}: a layout may hold at most {$limit} fields.");
        }
        $bounds = Input::validate($layout, ['min' => 'nullable|integer|min:0|max:50', 'max' => 'nullable|integer|min:1|max:50']);
        if (($bounds['min'] ?? null) !== null && ($bounds['max'] ?? null) !== null && $bounds['min'] > $bounds['max']) {
            throw self::problem($name, $key, 'layouts', 'bad_range', "{$name}: the fewest of \"{$layoutKey}\" must not exceed the most.");
        }

        return [
            'key' => $layoutKey,
            'label' => trim($layout['label']),
            'fields' => self::fields($fields, $depth + 1),
            'min' => $bounds['min'] ?? null,
            'max' => $bounds['max'] ?? null,
        ];
    }

    /**
     * A problem with one field, carrying both what a person reads and what an editor needs to put
     * the message beside the right field.
     */
    public static function problem(string $name, mixed $key, string $part, string $code, string $message): ValidationException
    {
        return new ValidationException($message, [[
            'path' => 'fields.'.(is_string($key) && $key !== '' ? $key : '?').'.'.$part,
            'fieldKey' => is_string($key) ? $key : null,
            'fieldLabel' => $name,
            'code' => $code,
            'message' => $message,
        ]]);
    }

    private static function blank(string $setting): mixed
    {
        return match ($setting) {
            'placeholder', 'help', 'pattern', 'unit' => '',
            'required', 'unique', 'private', 'multiple' => false,
            default => null,
        };
    }

    /** Choices keep the stored value apart from the label, so a label may be reworded freely. */
    private static function choices(mixed $choices, string $name): array
    {
        if (! is_array($choices) || ! array_is_list($choices)) {
            throw new ValidationException($name.': choices must be a list.');
        }
        $limit = FieldTypes::limit('choices');
        if (count($choices) > $limit) {
            throw new ValidationException("{$name}: a field may offer at most {$limit} choices.");
        }
        $out = [];
        foreach ($choices as $choice) {
            $c = Input::validate(is_array($choice) ? $choice : [], ['label' => 'required|string|max:100', 'value' => 'required|string|max:100']);
            if (! preg_match('/'.FieldTypes::pattern('choiceValue').'/D', $c['value'])) {
                throw new ValidationException("Choice value \"{$c['value']}\" may only use letters, numbers, spaces, dots, dashes and underscores.");
            }
            $out[] = ['label' => $c['label'], 'value' => $c['value']];
        }
        if (count(array_unique(array_column($out, 'value'))) !== count($out)) {
            throw new ValidationException($name.': choice values must be unique.');
        }

        return $out;
    }
}
