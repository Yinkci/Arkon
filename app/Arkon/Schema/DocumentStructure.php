<?php

namespace App\Arkon\Schema;

use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;

/**
 * Structural invariants that hold for every document regardless of components.
 * Port of packages/schema/src/document.ts; the TypeScript twin is
 * resources/js/arkon/schema/document.ts. Documents are in raw JSON form (see Support/Json).
 */
final class DocumentStructure
{
    /**
     * The root exists, every node is reachable from the root exactly once (no
     * cycles, no shared children, no orphans), map keys match node ids, and depth
     * is bounded.
     *
     * @return list<array{nodeId?: string, message: string}>
     */
    public static function validate(array $doc): array
    {
        $issues = [];
        $nodes = Json::entries($doc['nodes']);
        $maxNodes = Rules::get('maxNodes');
        $maxDepth = Rules::get('maxDepth');
        if (count($nodes) > $maxNodes) {
            $issues[] = ['message' => Rules::message('tooManyNodes', ['max' => $maxNodes])];
        }
        foreach ($nodes as $id => $node) {
            if (($node['id'] ?? null) !== (string) $id) {
                $issues[] = ['nodeId' => (string) $id, 'message' => Rules::message('keyMismatch')];
            }
        }
        if (! isset($nodes[$doc['root']])) {
            return [...$issues, ['message' => Rules::message('rootMissing')]];
        }

        $seen = [];
        $visit = function (string $id, int $depth) use (&$visit, &$seen, &$issues, $nodes, $maxDepth): void {
            if (isset($seen[$id])) {
                $issues[] = ['nodeId' => $id, 'message' => Rules::message('duplicateInTree')];

                return;
            }
            $seen[$id] = true;
            $node = $nodes[$id] ?? null;
            if ($node === null) {
                $issues[] = ['nodeId' => $id, 'message' => Rules::message('childMissing')];

                return;
            }
            if ($depth > $maxDepth) {
                $issues[] = ['nodeId' => $id, 'message' => Rules::message('tooDeep', ['max' => $maxDepth])];
            }
            foreach ($node['children'] ?? [] as $child) {
                $visit((string) $child, $depth + 1);
            }
        };
        $visit((string) $doc['root'], 0);

        foreach (array_keys($nodes) as $id) {
            if (! isset($seen[(string) $id])) {
                $issues[] = ['nodeId' => (string) $id, 'message' => Rules::message('detached')];
            }
        }

        return $issues;
    }

    /** @return array{parentId: string, index: int}|null */
    public static function findParent(array $doc, string $nodeId): ?array
    {
        foreach ($doc['nodes'] as $node) {
            $index = array_search($nodeId, $node['children'] ?? [], true);
            if ($index !== false) {
                return ['parentId' => $node['id'], 'index' => $index];
            }
        }

        return null;
    }

    /**
     * A node and all its descendants in depth-first pre-order.
     *
     * @return list<array>
     */
    public static function collectSubtree(array $doc, string $nodeId): array
    {
        $out = [];
        $walk = function (string $id) use (&$walk, &$out, $doc): void {
            $node = $doc['nodes'][$id] ?? null;
            if ($node === null) {
                return;
            }
            $out[] = $node;
            foreach ($node['children'] ?? [] as $child) {
                $walk((string) $child);
            }
        };
        $walk($nodeId);

        return $out;
    }
}
