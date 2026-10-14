<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;

/**
 * The field types a content type can use, from resources/arkon/content.json (the editor reads
 * the same file). A type says how its value is stored in the entry, how it is projected into
 * content_field_index for queries, and which settings its editor offers — so storage,
 * validation and querying are decided in one place instead of by conditionals spread around.
 */
final class FieldTypes
{
    private static ?array $registry = null;

    public static function registry(): array
    {
        return self::$registry ??= json_decode(file_get_contents(resource_path('arkon/content.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Only for tests that change the file on disk. */
    public static function forget(): void
    {
        self::$registry = null;
    }

    public static function exists(mixed $type): bool
    {
        return is_string($type) && isset(self::registry()['types'][$type]);
    }

    /** @return array{label: string, group: string, store: string, index: string, choices?: bool, settings: list<string>} */
    public static function get(mixed $type): array
    {
        return self::exists($type) ? self::registry()['types'][$type] : throw new ValidationException('Unknown field type.');
    }

    /** How a value of this type is kept in the entry's JSON. */
    public static function store(string $type): string
    {
        return self::get($type)['store'];
    }

    /** Which column of content_field_index a value lands in, or 'none' when it is not queryable. */
    public static function index(string $type): string
    {
        return self::get($type)['index'];
    }

    public static function takesChoices(string $type): bool
    {
        return (bool) (self::get($type)['choices'] ?? false);
    }

    /** Whether this type holds fields of its own: a group or a repeater. */
    public static function takesChildren(string $type): bool
    {
        return (bool) (self::get($type)['children'] ?? false);
    }

    /**
     * Whether this type holds named layouts, each with fields of its own: content sections.
     * A layout is a shape an author may choose between, not a container they may nest freely.
     */
    public static function takesLayouts(string $type): bool
    {
        return (bool) (self::get($type)['layouts'] ?? false);
    }

    public static function allows(string $type, string $setting): bool
    {
        return in_array($setting, self::get($type)['settings'], true);
    }

    public static function limit(string $name): int
    {
        return self::registry()['limits'][$name];
    }

    public static function pattern(string $name): string
    {
        return self::registry()['patterns'][$name];
    }

    /** @return list<string> */
    public static function reservedTypeKeys(): array
    {
        return self::registry()['reservedTypeKeys'];
    }

    /** @return list<string> */
    public static function reservedFieldKeys(): array
    {
        return self::registry()['reservedFieldKeys'];
    }
}
