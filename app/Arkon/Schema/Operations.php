<?php

namespace App\Arkon\Schema;

use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use stdClass;

/**
 * The shared operation language: parsing (server input), pure application and
 * inverses. Port of packages/schema/src/operations.ts; the TypeScript twin is
 * resources/js/arkon/schema/operations.ts and both run tests/conformance.
 */
final class Operations
{
    public const SEO_KEYS = ['title', 'description', 'noindex'];

    /**
     * Validates the shape of operations sent by a client and returns them in
     * internal (array) form with unknown fields dropped.
     *
     * @return array{0: list<array>, 1: list<array{path: string, message: string}>} [operations, issues]
     */
    public static function parse(mixed $raw): array
    {
        $issues = [];
        if (! Json::isList($raw)) {
            return [[], [['path' => 'operations', 'message' => Rules::message('expectedList')]]];
        }
        $ops = [];
        foreach ($raw as $i => $op) {
            $before = count($issues);
            $parsed = self::parseOne($op, "operations.{$i}", $issues);
            if (count($issues) === $before && $parsed !== null) {
                $ops[] = $parsed;
            }
        }

        return [$ops, $issues];
    }

    private static function parseOne(mixed $op, string $at, array &$issues): ?array
    {
        if (! Json::isObject($op)) {
            $issues[] = ['path' => $at, 'message' => Rules::message('expectedObject')];

            return null;
        }
        $op = Json::entries($op);
        $nodeId = function (string $key) use ($op, $at, &$issues): ?string {
            if (! Rules::matches('nodeId', $op[$key] ?? null)) {
                $issues[] = ['path' => "{$at}.{$key}", 'message' => Rules::message('invalidNodeId')];

                return null;
            }

            return $op[$key];
        };
        $index = function () use ($op, $at, &$issues): ?int {
            $value = $op['index'] ?? null;
            if (! is_int($value) || $value < 0) {
                $issues[] = ['path' => "{$at}.index", 'message' => 'Expected a non-negative integer'];

                return null;
            }

            return $value;
        };

        switch ($op['op'] ?? null) {
            case 'insertNode':
                $parentId = $nodeId('parentId');
                $idx = $index();
                $nodes = $op['nodes'] ?? null;
                $max = Rules::get('limits.insertNodes');
                if (! Json::isList($nodes) || count($nodes) < 1 || count($nodes) > $max) {
                    $issues[] = ['path' => "{$at}.nodes", 'message' => "Expected 1 to {$max} nodes"];

                    return null;
                }
                $nodeIssues = [];
                foreach ($nodes as $n => $node) {
                    array_push($nodeIssues, ...DocumentShape::nodeIssues($node, "{$at}.nodes.{$n}"));
                }
                array_push($issues, ...$nodeIssues);
                if ($parentId === null || $idx === null || $nodeIssues !== []) {
                    return null;
                }

                return ['op' => 'insertNode', 'parentId' => $parentId, 'index' => $idx, 'nodes' => $nodes];

            case 'removeNode':
                $id = $nodeId('nodeId');

                return $id === null ? null : ['op' => 'removeNode', 'nodeId' => $id];

            case 'moveNode':
                $id = $nodeId('nodeId');
                $parentId = $nodeId('parentId');
                $idx = $index();

                return $id === null || $parentId === null || $idx === null
                    ? null
                    : ['op' => 'moveNode', 'nodeId' => $id, 'parentId' => $parentId, 'index' => $idx];

            case 'updateProps':
                $id = $nodeId('nodeId');
                $set = $op['set'] ?? null;
                if (! Json::isObject($set)) {
                    $issues[] = ['path' => "{$at}.set", 'message' => Rules::message('expectedObject')];

                    return null;
                }
                $ok = $id !== null;
                foreach (array_keys(Json::entries($set)) as $key) {
                    if (! Rules::matches('propKey', (string) $key)) {
                        $issues[] = ['path' => "{$at}.set.{$key}", 'message' => 'Invalid property name'];
                        $ok = false;
                    }
                }
                $unset = self::parseKeyList($op, $at, fn ($key) => Rules::matches('propKey', $key), $issues, $ok);

                return $ok ? ['op' => 'updateProps', 'nodeId' => $id, 'set' => $set, 'unset' => $unset] : null;

            case 'updateSeo':
                $set = $op['set'] ?? null;
                $seoIssues = DocumentShape::seoIssues($set, "{$at}.set");
                array_push($issues, ...$seoIssues);
                $ok = $seoIssues === [];
                $unset = self::parseKeyList($op, $at, fn ($key) => in_array($key, self::SEO_KEYS, true), $issues, $ok);

                return $ok ? ['op' => 'updateSeo', 'set' => $set, 'unset' => $unset] : null;

            default:
                $issues[] = ['path' => "{$at}.op", 'message' => 'Unknown operation'];

                return null;
        }
    }

    /** @return list<string> */
    private static function parseKeyList(array $op, string $at, callable $valid, array &$issues, bool &$ok): array
    {
        if (! array_key_exists('unset', $op)) {
            return [];
        }
        if (! Json::isList($op['unset'])) {
            $issues[] = ['path' => "{$at}.unset", 'message' => Rules::message('expectedList')];
            $ok = false;

            return [];
        }
        foreach ($op['unset'] as $i => $key) {
            if (! is_string($key) || ! $valid($key)) {
                $issues[] = ['path' => "{$at}.unset.{$i}", 'message' => 'Invalid property name'];
                $ok = false;
            }
        }

        return $op['unset'];
    }

    /**
     * Applies operations to a copy of `$doc` (PHP arrays are values, so the input
     * is never mutated). Throws OperationException when an operation targets
     * missing nodes or breaks the tree. Component-level validation is the caller's.
     *
     * @return array{doc: array, inverse: list<array>}
     */
    public static function apply(array $doc, array $ops): array
    {
        $inverses = [];
        foreach ($ops as $i => $op) {
            $inverses[] = self::applyOne($doc, $op, $i);
        }
        $issues = DocumentStructure::validate($doc);
        if ($issues !== []) {
            throw new OperationException(implode('; ', array_column($issues, 'message')), count($ops) - 1);
        }

        return ['doc' => $doc, 'inverse' => array_reverse($inverses)];
    }

    private static function &requireNode(array &$doc, string $id, int $i): array
    {
        if (! isset($doc['nodes'][$id])) {
            throw new OperationException("Node {$id} does not exist", $i);
        }

        return $doc['nodes'][$id];
    }

    private static function requireContainer(array &$doc, string $id, int $i): void
    {
        $node = self::requireNode($doc, $id, $i);
        if (! array_key_exists('children', $node) || ! is_array($node['children'])) {
            throw new OperationException("Node {$id} cannot have children", $i);
        }
    }

    private static function applyOne(array &$doc, array $op, int $i): array
    {
        switch ($op['op']) {
            case 'insertNode':
                self::requireContainer($doc, $op['parentId'], $i);
                if ($op['index'] > count($doc['nodes'][$op['parentId']]['children'])) {
                    throw new OperationException('Insert index out of range', $i);
                }
                foreach ($op['nodes'] as $node) {
                    if (isset($doc['nodes'][$node['id']])) {
                        throw new OperationException("Node {$node['id']} already exists", $i);
                    }
                    $doc['nodes'][$node['id']] = $node;
                }
                $rootId = $op['nodes'][0]['id'];
                array_splice($doc['nodes'][$op['parentId']]['children'], $op['index'], 0, [$rootId]);

                return ['op' => 'removeNode', 'nodeId' => $rootId];

            case 'removeNode':
                if ($op['nodeId'] === $doc['root']) {
                    throw new OperationException('The page root cannot be removed', $i);
                }
                self::requireNode($doc, $op['nodeId'], $i);
                $location = DocumentStructure::findParent($doc, $op['nodeId']);
                if ($location === null) {
                    throw new OperationException("Node {$op['nodeId']} is not attached", $i);
                }
                $subtree = DocumentStructure::collectSubtree($doc, $op['nodeId']);
                array_splice($doc['nodes'][$location['parentId']]['children'], $location['index'], 1);
                foreach ($subtree as $node) {
                    unset($doc['nodes'][$node['id']]);
                }

                return ['op' => 'insertNode', 'parentId' => $location['parentId'], 'index' => $location['index'], 'nodes' => $subtree];

            case 'moveNode':
                if ($op['nodeId'] === $doc['root']) {
                    throw new OperationException('The page root cannot be moved', $i);
                }
                self::requireNode($doc, $op['nodeId'], $i);
                $from = DocumentStructure::findParent($doc, $op['nodeId']);
                if ($from === null) {
                    throw new OperationException("Node {$op['nodeId']} is not attached", $i);
                }
                foreach (DocumentStructure::collectSubtree($doc, $op['nodeId']) as $node) {
                    if ($node['id'] === $op['parentId']) {
                        throw new OperationException('A node cannot be moved into itself', $i);
                    }
                }
                self::requireContainer($doc, $op['parentId'], $i);
                array_splice($doc['nodes'][$from['parentId']]['children'], $from['index'], 1);
                // `index` refers to the position after the node has been removed from its old parent.
                if ($op['index'] > count($doc['nodes'][$op['parentId']]['children'])) {
                    throw new OperationException('Move index out of range', $i);
                }
                array_splice($doc['nodes'][$op['parentId']]['children'], $op['index'], 0, [$op['nodeId']]);

                return ['op' => 'moveNode', 'nodeId' => $op['nodeId'], 'parentId' => $from['parentId'], 'index' => $from['index']];

            case 'updateProps':
                $node = &self::requireNode($doc, $op['nodeId'], $i);
                $props = Json::entries($node['props'] ?? []);
                $inverse = self::invertPatch($props, $op['set'], $op['unset'] ?? []);
                foreach (Json::entries($op['set']) as $key => $value) {
                    $props[$key] = $value;
                }
                foreach ($op['unset'] ?? [] as $key) {
                    unset($props[$key]);
                }
                $node['props'] = self::object($props);
                unset($node);

                return ['op' => 'updateProps', 'nodeId' => $op['nodeId'], ...$inverse];

            case 'updateSeo':
                $seo = Json::entries($doc['seo'] ?? []);
                $inverse = self::invertPatch($seo, $op['set'], $op['unset'] ?? []);
                foreach (Json::entries($op['set']) as $key => $value) {
                    $seo[$key] = $value;
                }
                foreach ($op['unset'] ?? [] as $key) {
                    unset($seo[$key]);
                }
                $doc['seo'] = self::object($seo);

                return ['op' => 'updateSeo', ...$inverse];
        }

        throw new OperationException('Unknown operation', $i);
    }

    /** @return array{set: array|stdClass, unset: list<string>} */
    private static function invertPatch(array $current, mixed $set, array $unset): array
    {
        $restore = [];
        $remove = [];
        foreach (array_unique([...array_map('strval', array_keys(Json::entries($set))), ...$unset]) as $key) {
            if (array_key_exists($key, $current)) {
                $restore[$key] = $current[$key];
            } else {
                $remove[] = $key;
            }
        }

        return ['set' => self::object($restore), 'unset' => $remove];
    }

    /** Keeps empty JSON objects as objects (raw form). */
    private static function object(array $members): array|stdClass
    {
        return $members === [] ? new stdClass : $members;
    }

    /** Short random id for nodes inside documents. 10 base62 chars ≈ 59 bits. */
    public static function newNodeId(): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $id = '';
        while (strlen($id) < 10) {
            $byte = ord(random_bytes(1));
            // 248 is the largest multiple of 62 below 256; rejecting above it avoids modulo bias.
            if ($byte < 248) {
                $id .= $alphabet[$byte % 62];
            }
        }

        return $id;
    }
}
