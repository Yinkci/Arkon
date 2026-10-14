<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Uuid;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * An entry's own values, checked against its type's field definitions. Every caller — the
 * admin, the REST API, AI and imports — goes through here, so a value that one of them accepts
 * is a value all of them accept, and nothing that reaches storage is of the wrong shape.
 *
 * Values keep their type: a number is a number and a toggle is a boolean, both in the entry's
 * JSON and in the API. index() then projects them into content_field_index so lists can filter
 * and sort in the database.
 */
final class FieldValues
{
    /**
     * Checks a whole entry's values. Missing fields take their default when creating; on an
     * update an absent field keeps no value rather than silently taking the default again.
     *
     * `$publishing` applies the rules that only matter once an entry goes live: a draft may be
     * incomplete, a published entry may not.
     *
     * @param  array<string, mixed>  $definition  the type definition
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> canonical values, keyed by field, in definition order
     */
    public static function validate(array $definition, array $values, bool $creating = false, bool $publishing = false): array
    {
        $out = [];
        $issues = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['key'];
            $given = array_key_exists($key, $values) ? $values[$key] : null;
            if ($given === null && $creating && array_key_exists('default', $field) && $field['default'] !== null) {
                $given = $field['default'];
            }
            try {
                $value = self::one($field, $given, publishing: $publishing);
                if ($publishing && self::isEmpty($value) && ($field['settings']['required'] ?? false)) {
                    throw new ValidationException($field['label'].' is needed before this can be published.');
                }
                if ($value !== null) {
                    $out[$key] = $value;
                }
            } catch (ValidationException $e) {
                $issues[] = ['path' => "fields.{$key}", 'message' => $e->getMessage()];
            }
        }
        $unknown = array_diff(array_keys($values), array_column($definition['fields'], 'key'));
        foreach ($unknown as $key) {
            $issues[] = ['path' => "fields.{$key}", 'message' => "This type has no field \"{$key}\"."];
        }
        if ($issues !== []) {
            throw new ValidationException(implode(' ', array_column($issues, 'message')), $issues);
        }

        return $out;
    }

    /**
     * One value, in the shape its field's type stores. Returns null when nothing was given,
     * which is how "no value" is stored: an absent key rather than an empty string.
     */
    public static function one(array $field, mixed $value, bool $defaulting = true, bool $publishing = false): mixed
    {
        unset($defaulting);
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        $settings = $field['settings'] ?? [];
        $label = $field['label'] ?? $field['key'];

        return match (FieldTypes::store($field['type'])) {
            'string' => self::string($field, $value, $settings, $label),
            'html' => self::html($value, $label),
            'integer' => self::integer($value, $settings, $label),
            'decimal' => self::decimal($value, $settings, $label),
            'boolean' => self::boolean($value, $label),
            'stringList' => self::stringList($field, $value, $label),
            'date' => self::date($value, $settings, $label, false),
            'datetime' => self::date($value, $settings, $label, true),
            'time' => self::time($value, $label),
            'color' => self::color($value, $label),
            'media' => self::media($value, $label),
            'mediaList' => self::mediaList($value, $label),
            'refs' => self::refs($field, $value, $settings, $label),
            'object' => self::object($field, $value, $label, $publishing),
            'rows' => self::rows($field, $value, $settings, $label, $publishing),
            'blocks' => self::blocks($field, $value, $settings, $label, $publishing),
            default => throw new ValidationException("{$label}: this field type cannot be stored."),
        };
    }

    private static function string(array $field, mixed $value, array $settings, string $label): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new ValidationException("{$label} must be text.");
        }
        $value = trim((string) $value);
        if (isset($field['choices'])) {
            $allowed = array_column($field['choices'], 'value');
            if (! in_array($value, $allowed, true)) {
                throw new ValidationException("{$label}: \"{$value}\" is not one of the choices.");
            }

            return $value;
        }
        $max = $settings['maxLength'] ?? FieldTypes::limit($field['type'] === 'textarea' ? 'textarea' : 'text');
        if (mb_strlen($value) > $max) {
            throw new ValidationException("{$label} must be at most {$max} characters.");
        }
        if (($settings['minLength'] ?? null) !== null && mb_strlen($value) < $settings['minLength']) {
            throw new ValidationException("{$label} must be at least {$settings['minLength']} characters.");
        }
        if (($settings['pattern'] ?? '') !== '' && @preg_match('/'.str_replace('/', '\/', $settings['pattern']).'/Du', $value) !== 1) {
            throw new ValidationException("{$label} is not in the expected format.");
        }
        self::checkShape($field['type'], $value, $label);

        return $value;
    }

    private static function checkShape(string $type, string $value, string $label): void
    {
        $ok = match ($type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $value) === 1,
            'phone' => preg_match('/^[0-9+().\- ]{4,40}$/D', $value) === 1,
            default => true,
        };
        if (! $ok) {
            throw new ValidationException(match ($type) {
                'email' => "{$label} must be an email address.",
                'url' => "{$label} must be a link starting with http:// or https://.",
                default => "{$label} must be a phone number.",
            });
        }
    }

    /** Rich text is sanitised on the way in: what is stored is already safe to render. */
    private static function html(mixed $value, string $label): string
    {
        if (! is_string($value)) {
            throw new ValidationException("{$label} must be text.");
        }
        $max = FieldTypes::limit('richText');
        if (mb_strlen($value) > $max) {
            throw new ValidationException("{$label} must be at most {$max} characters.");
        }

        return RichText::sanitize($value);
    }

    private static function integer(mixed $value, array $settings, string $label): int
    {
        if (is_string($value) && preg_match('/^-?[0-9]+$/D', trim($value))) {
            $value = (int) trim($value);
        }
        if (! is_int($value) && ! (is_float($value) && floor($value) === $value)) {
            throw new ValidationException("{$label} must be a whole number.");
        }
        $value = (int) $value;
        self::checkRange($value, $settings, $label);

        return $value;
    }

    private static function decimal(mixed $value, array $settings, string $label): float
    {
        if (is_string($value) && is_numeric(trim($value))) {
            $value = (float) trim($value);
        }
        if (! is_int($value) && ! is_float($value)) {
            throw new ValidationException("{$label} must be a number.");
        }
        $value = (float) $value;
        if (! is_finite($value)) {
            throw new ValidationException("{$label} must be a number.");
        }
        self::checkRange($value, $settings, $label);

        return $value;
    }

    private static function checkRange(int|float $value, array $settings, string $label): void
    {
        if (($settings['min'] ?? null) !== null && $value < $settings['min']) {
            throw new ValidationException("{$label} must be at least {$settings['min']}.");
        }
        if (($settings['max'] ?? null) !== null && $value > $settings['max']) {
            throw new ValidationException("{$label} must be at most {$settings['max']}.");
        }
    }

    private static function boolean(mixed $value, string $label): bool
    {
        // Only real booleans are stored; "yes"/"no" strings are not quietly accepted.
        if (! is_bool($value)) {
            throw new ValidationException("{$label} must be on or off.");
        }

        return $value;
    }

    private static function stringList(array $field, mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value) === false) {
            throw new ValidationException("{$label} must be a list of choices.");
        }
        $limit = FieldTypes::limit('multiSelect');
        if (count($value) > $limit) {
            throw new ValidationException("{$label} may hold at most {$limit} choices.");
        }
        $allowed = array_column($field['choices'] ?? [], 'value');
        $out = [];
        foreach ($value as $one) {
            if (! is_string($one) || ! in_array($one, $allowed, true)) {
                throw new ValidationException("{$label}: \"".(is_string($one) ? $one : gettype($one)).'" is not one of the choices.');
            }
            $out[] = $one;
        }
        if (count(array_unique($out)) !== count($out)) {
            throw new ValidationException("{$label} must not repeat a choice.");
        }

        return $out;
    }

    /**
     * Dates are stored normalised: a date as YYYY-MM-DD, a date and time as an ISO 8601 instant
     * in UTC, so two entries saved from different time zones compare and sort correctly.
     */
    private static function date(mixed $value, array $settings, string $label, bool $withTime): string
    {
        if (! is_string($value)) {
            throw new ValidationException("{$label} must be a date.");
        }
        try {
            $date = new DateTimeImmutable(trim($value), new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new ValidationException("{$label} must be a date.");
        }
        $stored = $withTime
            ? $date->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM)
            : $date->format('Y-m-d');
        foreach (['min' => 'before', 'max' => 'after'] as $bound => $word) {
            if (($settings[$bound] ?? null) === null) {
                continue;
            }
            $limit = new DateTimeImmutable((string) $settings[$bound], new DateTimeZone('UTC'));
            if (($word === 'before' && $date < $limit) || ($word === 'after' && $date > $limit)) {
                throw new ValidationException("{$label} must not be {$word} ".$limit->format($withTime ? DateTimeInterface::ATOM : 'Y-m-d').'.');
            }
        }

        return $stored;
    }

    private static function time(mixed $value, string $label): string
    {
        if (! is_string($value) || ! preg_match('/'.FieldTypes::pattern('time').'/D', trim($value))) {
            throw new ValidationException("{$label} must be a time like 14:30.");
        }

        return trim($value);
    }

    private static function color(mixed $value, string $label): string
    {
        if (! is_string($value) || ! preg_match('/'.FieldTypes::pattern('color').'/D', trim($value))) {
            throw new ValidationException("{$label} must be a colour like #336699.");
        }

        return strtolower(trim($value));
    }

    /** Media is stored as a reference to the library, never as a copied path. */
    private static function media(mixed $value, string $label): array
    {
        $id = is_array($value) ? ($value['id'] ?? $value['assetId'] ?? null) : $value;
        if (! Rules::matches('uuid', $id)) {
            throw new ValidationException("{$label} must be an item from the media library.");
        }

        return ['id' => (string) $id];
    }

    private static function mediaList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ValidationException("{$label} must be a list of media.");
        }
        $limit = FieldTypes::limit('gallery');
        if (count($value) > $limit) {
            throw new ValidationException("{$label} may hold at most {$limit} items.");
        }

        return array_map(fn ($one) => self::media($one, $label), $value);
    }

    /**
     * A relationship: references to other entries of one content type. A single relationship
     * stores one reference, a multiple one stores a list in the order somebody chose, because
     * that order is editorial — "Related projects" means these projects, like this.
     *
     * Only the shape is settled here. Whether each target exists, is of the right type and
     * belongs to this site needs the database, and is Relationships::assertValid()'s work.
     */
    private static function refs(array $field, mixed $value, array $settings, string $label): array
    {
        $multiple = (bool) ($settings['multiple'] ?? false);
        if (! $multiple) {
            // A single relationship accepts a list of one, so a field switched from multiple to
            // single still reads what it already holds.
            $one = is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;
            if (is_array($value) && array_is_list($value) && count($value) > 1) {
                throw new ValidationException("{$label} takes one item.");
            }

            return self::ref($one, $label);
        }
        if (! is_array($value) || ! array_is_list($value)) {
            $value = [$value];
        }
        $max = min($settings['maxItems'] ?? PHP_INT_MAX, FieldTypes::limit('related'));
        if (count($value) > $max) {
            throw new ValidationException("{$label} may hold at most {$max} items.");
        }
        $out = array_map(fn ($one) => self::ref($one, $label), $value);
        if (count(array_unique(array_column($out, 'id'))) !== count($out)) {
            throw new ValidationException("{$label} must not hold the same item twice.");
        }

        return $out;
    }

    /** One reference, as an object with an id — the same shape media uses, for the same reason. */
    private static function ref(mixed $value, string $label): array
    {
        $id = is_array($value) ? ($value['id'] ?? null) : $value;
        if (! Rules::matches('uuid', $id)) {
            throw new ValidationException("{$label} must be an item of the related content type.");
        }

        return ['id' => (string) $id];
    }

    /**
     * A group: one object whose members are the group's own fields, each keeping its own type.
     * A member left empty is simply absent, exactly as a top-level field would be.
     */
    private static function object(array $field, mixed $value, string $label, bool $publishing = false): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new ValidationException("{$label} must be a set of named values.");
        }
        $out = self::nested($field['fields'] ?? [], $value, $label, $publishing);

        return $out === [] ? null : $out;
    }

    /**
     * A repeater: a list of rows, each shaped like a group. Every row keeps a stable id, so
     * reordering rows moves them without changing which row is which.
     */
    private static function rows(array $field, mixed $value, array $settings, string $label, bool $publishing = false): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ValidationException("{$label} must be a list of rows.");
        }
        $limit = FieldTypes::limit('rows');
        if (count($value) > $limit) {
            throw new ValidationException("{$label} may hold at most {$limit} rows.");
        }
        $rows = [];
        foreach ($value as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new ValidationException("{$label} row ".($index + 1).' must be a set of named values.');
            }
            $values = self::nested($field['fields'] ?? [], $row, "{$label} row ".($index + 1), $publishing);
            // An entirely empty row is one the author added and did not fill in; it is dropped
            // rather than stored, so a repeater does not collect blanks.
            if ($values === []) {
                continue;
            }
            $rows[] = ['_id' => self::rowId($row), ...$values];
        }
        if (($settings['maxRows'] ?? null) !== null && count($rows) > $settings['maxRows']) {
            throw new ValidationException("{$label} may hold at most {$settings['maxRows']} rows.");
        }
        if ($rows !== [] && ($settings['minRows'] ?? null) !== null && count($rows) < $settings['minRows']) {
            throw new ValidationException("{$label} needs at least {$settings['minRows']} rows.");
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * Content sections: an ordered list of blocks, each one of the layouts the field offers and
     * holding that layout's fields. Every block keeps a stable id and the key of its layout, so
     * reordering, revisions and a later edit all talk about the same block rather than "the
     * fourth one".
     *
     * A block whose layout the field no longer offers is kept, not dropped: somebody removed a
     * layout from the definition and the content it described is still the author's. It is
     * checked for shape and size only (its own layout is gone, so there is nothing to check it
     * against), reported by Flexible::deprecated(), and rendered by nothing until it is dealt
     * with.
     */
    private static function blocks(array $field, mixed $value, array $settings, string $label, bool $publishing = false): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ValidationException("{$label} must be a list of sections.");
        }
        $limit = FieldTypes::limit('blocks');
        if (count($value) > $limit) {
            throw new ValidationException("{$label} may hold at most {$limit} sections.");
        }
        $layouts = array_column($field['layouts'] ?? [], null, 'key');
        $blocks = [];
        $counts = [];
        foreach ($value as $index => $block) {
            $where = "{$label} section ".($index + 1);
            if (! is_array($block) || array_is_list($block)) {
                throw new ValidationException("{$where} must be a set of named values.");
            }
            $layout = $block['_layout'] ?? null;
            if (! is_string($layout) || ! preg_match('/'.FieldTypes::pattern('fieldKey').'/D', $layout)) {
                throw new ValidationException("{$where} does not say which kind of section it is.");
            }
            $counts[$layout] = ($counts[$layout] ?? 0) + 1;
            $blocks[] = isset($layouts[$layout])
                ? ['_id' => self::rowId($block), '_layout' => $layout, ...self::nested($layouts[$layout]['fields'], array_diff_key($block, ['_layout' => true]), $layouts[$layout]['label'].' in '.$where, $publishing)]
                : ['_id' => self::rowId($block), '_layout' => $layout, ...self::deprecated($block, $where)];
        }
        foreach ($layouts as $key => $layout) {
            $count = $counts[$key] ?? 0;
            if (($layout['max'] ?? null) !== null && $count > $layout['max']) {
                throw new ValidationException("{$label} may hold at most {$layout['max']} “{$layout['label']}” ".($layout['max'] === 1 ? 'section' : 'sections').'.');
            }
            if ($blocks !== [] && ($layout['min'] ?? null) !== null && $count < $layout['min']) {
                throw new ValidationException("{$label} needs at least {$layout['min']} “{$layout['label']}” ".($layout['min'] === 1 ? 'section' : 'sections').'.');
            }
        }
        if (($settings['maxRows'] ?? null) !== null && count($blocks) > $settings['maxRows']) {
            throw new ValidationException("{$label} may hold at most {$settings['maxRows']} sections.");
        }
        if ($blocks !== [] && ($settings['minRows'] ?? null) !== null && count($blocks) < $settings['minRows']) {
            throw new ValidationException("{$label} needs at least {$settings['minRows']} sections.");
        }

        return $blocks === [] ? null : $blocks;
    }

    /**
     * What is kept of a block whose layout is gone: its own values, as inert data. Checked for
     * shape (field keys, scalars or lists and maps of them, one level deep) and for size, so
     * nothing unbounded or structural can be smuggled in under a layout nobody defined.
     *
     * @return array<string, mixed>
     */
    private static function deprecated(array $block, string $where): array
    {
        $kept = array_diff_key($block, ['_id' => true, '_layout' => true]);
        if (strlen((string) json_encode($kept)) > FieldTypes::limit('deprecatedBlockBytes')) {
            throw new ValidationException("{$where} holds more than Arkon keeps for a section whose kind was removed.");
        }
        $plain = function (mixed $item) use (&$plain, $where): mixed {
            if ($item === null || is_scalar($item)) {
                return $item;
            }
            if (! is_array($item)) {
                throw new ValidationException("{$where} holds something Arkon cannot keep.");
            }
            foreach (array_keys($item) as $key) {
                if (is_string($key) && ! preg_match('/'.FieldTypes::pattern('fieldKey').'/D', $key)) {
                    throw new ValidationException("{$where} holds a value named in a way Arkon cannot keep.");
                }
            }

            return array_map($plain, $item);
        };
        $out = [];
        foreach ($kept as $key => $item) {
            if (! is_string($key) || ! preg_match('/'.FieldTypes::pattern('fieldKey').'/D', $key)) {
                throw new ValidationException("{$where} holds a value named in a way Arkon cannot keep.");
            }
            $out[$key] = $plain($item);
        }

        return $out;
    }

    /** The row's own id if it came with one, else a new one. Ids are never taken from the author. */
    private static function rowId(array $row): string
    {
        $given = $row['_id'] ?? null;

        return is_string($given) && preg_match('/^[A-Za-z0-9_-]{4,64}$/D', $given) ? $given : Uuid::v7();
    }

    /**
     * The members of a group or of one repeater row, against the fields that structure holds.
     * Errors name the structure and, for a repeater, the row — "FAQs row 2: Question …" — rather
     * than a path only a developer could read.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private static function nested(array $fields, array $given, string $where, bool $publishing = false): array
    {
        $out = [];
        $issues = [];
        foreach ($fields as $child) {
            try {
                $value = self::one($child, $given[$child['key']] ?? null, publishing: $publishing);
                if ($publishing && self::isEmpty($value) && ($child['settings']['required'] ?? false)) {
                    throw new ValidationException($child['label'].' is needed before this can be published.');
                }
                if ($value !== null) {
                    $out[$child['key']] = $value;
                }
            } catch (ValidationException $e) {
                $issues[] = ['path' => $where.'.'.$child['key'], 'fieldKey' => $child['key'], 'message' => $where.': '.$e->getMessage()];
            }
        }
        $unknown = array_diff(array_keys($given), [...array_column($fields, 'key'), '_id', '_layout']);
        foreach ($unknown as $key) {
            $issues[] = ['path' => $where.'.'.$key, 'fieldKey' => $key, 'message' => "{$where} has no field \"{$key}\"."];
        }
        if ($issues !== []) {
            throw new ValidationException(implode(' ', array_column($issues, 'message')), $issues);
        }

        return $out;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * The same values with Arkon's own bookkeeping taken out: a repeater row's id exists so the
     * editor can move rows about without losing track of them, and means nothing to a reader of
     * the public API or to AI. Everything a person entered is kept.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function withoutInternals(array $values): array
    {
        $clean = function (mixed $value) use (&$clean): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($clean, $value);

            // A block's layout stays: it is what the value means, not bookkeeping. The id goes,
            // like a repeater row's, because it exists for the editor.
            return array_is_list($value) ? $value : array_diff_key($value, ['_id' => true]);
        };

        return array_map($clean, $values);
    }

    /**
     * The rows that represent these values in content_field_index. One row per value, with the
     * value in the column its type belongs to, so filters and sorts are ordinary SQL.
     *
     * @return list<array<string, mixed>>
     */
    public static function index(array $definition, array $values): array
    {
        $rows = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $values) || FieldTypes::index($field['type']) === 'none') {
                continue;
            }
            foreach (self::cells($field, $values[$key]) as $position => $cell) {
                $rows[] = [
                    'field_key' => $key,
                    'position' => $position,
                    'text_value' => $cell['text'] ?? null,
                    'num_value' => $cell['num'] ?? null,
                    'bool_value' => $cell['bool'] ?? null,
                    'date_value' => $cell['date'] ?? null,
                    'ref_id' => $cell['ref'] ?? null,
                    'unique_value' => (bool) ($field['settings']['unique'] ?? false),
                ];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function cells(array $field, mixed $value): array
    {
        return match (FieldTypes::index($field['type'])) {
            'text' => array_map(fn ($one) => ['text' => (string) $one], is_array($value) ? $value : [$value]),
            'number' => [['num' => $value, 'text' => (string) $value]],
            'boolean' => [['bool' => $value]],
            'date' => [['date' => $value, 'text' => (string) $value]],
            // A relationship with several targets keeps one row each, in its stored order, so a
            // query can ask for entries that reference this one without reading any JSON.
            'ref' => array_map(
                fn ($one) => ['ref' => is_array($one) ? ($one['id'] ?? null) : null],
                is_array($value) && array_is_list($value) ? $value : [$value],
            ),
            default => [],
        };
    }
}
