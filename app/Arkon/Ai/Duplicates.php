<?php

namespace App\Arkon\Ai;

use App\Arkon\Schema\DocumentStructure;
use App\Arkon\Schema\Operations;
use App\Arkon\Support\Json;

/**
 * Duplicating a block in a proposal, with the same semantics as the editor
 * (resources/js/arkon/editor/duplicate.ts and columns.ts): a deep copy with fresh ids right
 * after the original, everything kept (props, design settings, animations, image and
 * reusable component references). A copied column's width is copied too, by the compiler's
 * final width pass (ColumnLayout::changes), so the layout keeps one width per column.
 */
final class Duplicates
{
    /**
     * @return array{nodes: list<array>, parentId: string, index: int} the insertNode payload
     */
    public static function of(array $doc, string $nodeId): array
    {
        $nodes = Json::entries($doc['nodes']);
        $location = DocumentStructure::findParent($doc, $nodeId) ?? throw new ProposalProblem("block {$nodeId} is not on the page");
        $copy = [];
        $visit = function (string $id) use (&$visit, &$copy, $nodes): string {
            $node = $nodes[$id];
            $clone = ['id' => Operations::newNodeId(), 'type' => $node['type'], 'version' => $node['version'], 'props' => $node['props']];
            $index = count($copy);
            $copy[] = $clone;
            if (array_key_exists('children', $node)) {
                $copy[$index]['children'] = array_map(fn ($child) => $visit((string) $child), $node['children']);
            }

            return $clone['id'];
        };
        $visit($nodeId);

        return ['nodes' => $copy, 'parentId' => $location['parentId'], 'index' => $location['index'] + 1];
    }
}
