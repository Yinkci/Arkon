<?php

namespace App\Arkon\Schema;

use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Text;

/**
 * Shape of a PageDocument in raw JSON form (objects may be stdClass): the
 * equivalent of the reference project's Zod PageDocumentSchema. Issues carry a
 * dotted path, exactly like the TypeScript twin (resources/js/arkon/schema/shape.ts).
 */
final class DocumentShape
{
    /** @return list<array{path: string, message: string}> */
    public static function documentIssues(mixed $doc): array
    {
        if (! Json::isObject($doc)) {
            return [['path' => '', 'message' => Rules::message('expectedObject')]];
        }
        $doc = Json::entries($doc);
        $issues = self::unknownKeys($doc, ['schemaVersion', 'root', 'nodes', 'seo'], '');
        $version = $doc['schemaVersion'] ?? null;
        if (! self::isPositiveInteger($version) || (int) $version !== Rules::get('schemaVersion')) {
            $issues[] = ['path' => 'schemaVersion', 'message' => Rules::message('unsupportedSchemaVersion')];
        }
        if (! Rules::matches('nodeId', $doc['root'] ?? null)) {
            $issues[] = ['path' => 'root', 'message' => Rules::message('invalidNodeId')];
        }
        $nodes = $doc['nodes'] ?? null;
        if (! Json::isObject($nodes)) {
            $issues[] = ['path' => 'nodes', 'message' => Rules::message('expectedObject')];
        } else {
            foreach (Json::entries($nodes) as $key => $node) {
                if (! Rules::matches('nodeId', (string) $key)) {
                    $issues[] = ['path' => "nodes.{$key}", 'message' => Rules::message('invalidNodeId')];
                }
                array_push($issues, ...self::nodeIssues($node, "nodes.{$key}"));
            }
        }
        array_push($issues, ...self::seoIssues($doc['seo'] ?? null, 'seo'));

        return $issues;
    }

    /** @return list<array{path: string, message: string}> */
    public static function nodeIssues(mixed $node, string $at): array
    {
        if (! Json::isObject($node)) {
            return [['path' => $at, 'message' => Rules::message('expectedObject')]];
        }
        $node = Json::entries($node);
        $issues = self::unknownKeys($node, ['id', 'type', 'version', 'variant', 'props', 'children', 'editor'], $at);
        if (! Rules::matches('nodeId', $node['id'] ?? null)) {
            $issues[] = ['path' => "{$at}.id", 'message' => Rules::message('invalidNodeId')];
        }
        if (! Rules::matches('componentType', $node['type'] ?? null)) {
            $issues[] = ['path' => "{$at}.type", 'message' => Rules::message('invalidComponentType')];
        }
        if (! self::isPositiveInteger($node['version'] ?? null)) {
            $issues[] = ['path' => "{$at}.version", 'message' => Rules::message('expectedPositiveInteger')];
        }
        if (array_key_exists('variant', $node)) {
            array_push($issues, ...self::stringIssues($node['variant'], Rules::get('limits.variant'), "{$at}.variant"));
        }
        if (! Json::isObject($node['props'] ?? null)) {
            $issues[] = ['path' => "{$at}.props", 'message' => Rules::message('expectedObject')];
        }
        if (array_key_exists('children', $node)) {
            if (! Json::isList($node['children'])) {
                $issues[] = ['path' => "{$at}.children", 'message' => Rules::message('expectedList')];
            } else {
                foreach ($node['children'] as $i => $child) {
                    if (! Rules::matches('nodeId', $child)) {
                        $issues[] = ['path' => "{$at}.children.{$i}", 'message' => Rules::message('invalidNodeId')];
                    }
                }
            }
        }
        if (array_key_exists('editor', $node)) {
            if (! Json::isObject($node['editor'])) {
                $issues[] = ['path' => "{$at}.editor", 'message' => Rules::message('expectedObject')];
            } else {
                $editor = Json::entries($node['editor']);
                array_push($issues, ...self::unknownKeys($editor, ['name'], "{$at}.editor"));
                if (array_key_exists('name', $editor)) {
                    array_push($issues, ...self::stringIssues($editor['name'], Rules::get('limits.editorName'), "{$at}.editor.name"));
                }
            }
        }

        return $issues;
    }

    /** @return list<array{path: string, message: string}> */
    public static function seoIssues(mixed $seo, string $at): array
    {
        if (! Json::isObject($seo)) {
            return [['path' => $at, 'message' => Rules::message('expectedObject')]];
        }
        $seo = Json::entries($seo);
        $fields = Rules::get('seo');
        $issues = self::unknownKeys($seo, array_keys($fields), $at);
        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $seo)) {
                continue;
            }
            if ($field['type'] === 'boolean') {
                if (! is_bool($seo[$key])) {
                    $issues[] = ['path' => "{$at}.{$key}", 'message' => Rules::message('expectedBoolean')];
                }
            } else {
                array_push($issues, ...self::stringIssues($seo[$key], $field['maxLength'], "{$at}.{$key}"));
                if (is_string($seo[$key]) && isset($field['values']) && ! in_array($seo[$key], $field['values'], true)) {
                    $issues[] = ['path' => "{$at}.{$key}", 'message' => Rules::message('oneOf', ['values' => implode(', ', $field['values'])])];
                }
                if (is_string($seo[$key]) && isset($field['pattern']) && preg_match('~'.$field['pattern'].'~D', $seo[$key]) !== 1) {
                    $issues[] = ['path' => "{$at}.{$key}", 'message' => 'Use a valid SEO value'];
                }
            }
        }

        return $issues;
    }

    /** @return list<array{path: string, message: string}> */
    private static function stringIssues(mixed $value, int $max, string $at): array
    {
        if (! is_string($value)) {
            return [['path' => $at, 'message' => Rules::message('expectedString')]];
        }
        if (Text::utf16Length($value) > $max) {
            return [['path' => $at, 'message' => Rules::message('tooLong', ['max' => $max])]];
        }

        return [];
    }

    /** @return list<array{path: string, message: string}> */
    private static function unknownKeys(array $object, array $allowed, string $at): array
    {
        $issues = [];
        foreach (array_keys($object) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $issues[] = ['path' => ltrim("{$at}.{$key}", '.'), 'message' => Rules::message('unrecognizedKey', ['key' => $key])];
            }
        }

        return $issues;
    }

    public static function isPositiveInteger(mixed $value): bool
    {
        return (is_int($value) || (is_float($value) && floor($value) === $value)) && $value > 0;
    }
}
