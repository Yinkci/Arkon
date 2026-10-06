<?php

namespace App\Arkon\Components;

use App\Arkon\Schema\Operations;
use App\Arkon\Support\Rules;
use stdClass;

/** Builders for new documents (raw JSON form). */
final class Factories
{
    public static function heroNode(array $props = []): array
    {
        $hero = app(ComponentRegistry::class)->current('hero');

        return [
            'id' => Operations::newNodeId(),
            'type' => $hero->type,
            'version' => $hero->version,
            'props' => [...$hero->defaultProps, ...$props],
        ];
    }

    /** @param list<array> $sections */
    public static function pageDocument(array $sections = []): array
    {
        $page = app(ComponentRegistry::class)->current('page');
        $rootId = Operations::newNodeId();
        $nodes = [$rootId => [
            'id' => $rootId,
            'type' => $page->type,
            'version' => $page->version,
            'props' => new stdClass,
            'children' => array_column($sections, 'id'),
        ]];
        foreach ($sections as $section) {
            $nodes[$section['id']] = $section;
        }

        return ['schemaVersion' => Rules::get('schemaVersion'), 'root' => $rootId, 'nodes' => $nodes, 'seo' => new stdClass];
    }
}
