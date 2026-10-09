<?php

namespace App\Arkon\Components;

use App\Arkon\Schema\Operations;
use App\Arkon\Support\Json;
use InvalidArgumentException;

/** Shared layout recipes, expanded into ordinary versioned blocks with fresh ids. */
final class PatternLibrary
{
    public function __construct(private readonly ComponentRegistry $registry) {}

    public function all(): array
    {
        return Json::decode(file_get_contents(resource_path('arkon/patterns.json')));
    }

    public function nodes(string $id): array
    {
        $pattern = collect($this->all())->firstWhere('id', $id) ?? throw new InvalidArgumentException('Unknown layout pattern');
        $nodes = [];
        $visit = function (array $template) use (&$visit, &$nodes): string {
            $definition = $this->registry->current($template['type']);
            $id = Operations::newNodeId();
            $node = ['id' => $id, 'type' => $definition->type, 'version' => $definition->version, 'props' => [...$definition->defaultProps, ...Json::entries($template['props'] ?? [])]];
            $nodes[$id] = $node;
            if ($definition->children !== false) {
                $nodes[$id]['children'] = array_map($visit, $template['children'] ?? []);
            }

            return $id;
        };
        $visit($pattern['template']);

        return array_values($nodes);
    }

    public function document(array $ids): array
    {
        $root = Factories::pageDocument([]);
        foreach ($ids as $id) {
            $nodes = $this->nodes($id);
            $root['nodes'][$root['root']]['children'][] = $nodes[0]['id'];
            foreach ($nodes as $node) {
                $root['nodes'][$node['id']] = $node;
            }
        }

        return Json::decode(Json::encode($root));
    }
}
