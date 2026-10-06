<?php

namespace App\Arkon\Ai;

use App\Arkon\Components\ComponentDefinition;
use App\Arkon\Components\ComponentRegistry;

/**
 * What the model may answer, derived from the component registry: the current version
 * of every registered component, its props (types, enums, limits, defaults) and its
 * nesting rules. Both the JSON schema (enforced by the provider's structured output)
 * and the catalogue text (the instructions) come from the same manifests, so a new
 * component version changes what the AI can propose without touching this code.
 *
 * Shape of a reply:
 *   { summary, notes[], changes[] } where a change is one of
 *   add    { parent: "page" | id, index | null, block }   (block nests its children)
 *   update { id, type, props }                              (null props are left unchanged)
 *   move   { id, parent, index | null }
 *   remove { id }
 */
final class ProposalSchema
{
    public const PAGE_PARENT = 'page';

    public function __construct(private readonly ComponentRegistry $registry) {}

    /**
     * @param  list<string>  $assetIds  images the proposal may reference (already on the page)
     */
    public function schema(array $assetIds): array
    {
        $definitions = $this->insertable();
        $defs = [];
        foreach ($definitions as $type => $definition) {
            $defs["block_{$type}"] = $this->blockSchema($definition, $assetIds, []);
            $defs["update_{$type}"] = $this->updateSchema($definition, $assetIds);
        }
        $addable = array_values(array_filter(array_keys($definitions), fn ($type) => $assetIds !== [] || ! $this->needsImage($definitions[$type])));

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'notes', 'changes'],
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'One or two sentences telling the user what this proposal changes.'],
                'notes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What could not be done with the available blocks, and what the user must still provide (for example a link destination). Empty if nothing.'],
                'changes' => [
                    'type' => 'array',
                    'description' => 'Changes in the order they apply. Empty when nothing can be done.',
                    'items' => ['anyOf' => [
                        [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'parent', 'index', 'block'],
                            'properties' => [
                                'action' => ['type' => 'string', 'const' => 'add'],
                                'parent' => ['type' => 'string', 'description' => '"page" for the top level, or the id of an existing container block.'],
                                'index' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']], 'description' => 'Position among the parent\'s children (0 = first); null = at the end.'],
                                'block' => ['anyOf' => array_map(fn ($type) => ['$ref' => "#/\$defs/block_{$type}"], $addable)],
                            ],
                        ],
                        [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'change'],
                            'properties' => [
                                'action' => ['type' => 'string', 'const' => 'update'],
                                'change' => ['anyOf' => array_map(fn ($type) => ['$ref' => "#/\$defs/update_{$type}"], array_keys($definitions))],
                            ],
                        ],
                        [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'id', 'parent', 'index'],
                            'properties' => [
                                'action' => ['type' => 'string', 'const' => 'move'],
                                'id' => ['type' => 'string'],
                                'parent' => ['type' => 'string'],
                                'index' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                            ],
                        ],
                        [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'id'],
                            'properties' => [
                                'action' => ['type' => 'string', 'const' => 'remove'],
                                'id' => ['type' => 'string'],
                            ],
                        ],
                    ]],
                ],
            ],
            '$defs' => $defs,
        ];
    }

    /** The catalogue part of the instructions: blocks, props and nesting, from the manifests. */
    public function catalogue(): string
    {
        $lines = [];
        foreach ($this->insertable() as $type => $definition) {
            $props = [];
            foreach ($definition->props->fields() as $name => $field) {
                $props[] = "{$name}: ".$this->describeField($field);
            }
            $lines[] = "- {$type} ({$definition->label}, version {$definition->version})".($props === [] ? ': no props' : ': '.implode('; ', $props));
        }
        $lines[] = '';
        $lines[] = 'Nesting (anything else is refused):';
        foreach ($this->registry->currentDefinitions() as $type => $definition) {
            if ($definition->children === false) {
                continue;
            }
            $limits = array_filter([
                isset($definition->children['min']) ? "at least {$definition->children['min']}" : null,
                isset($definition->children['max']) ? "at most {$definition->children['max']}" : null,
            ]);
            $holder = $type === 'page' ? 'The page' : "{$type}";
            $lines[] = "- {$holder} holds: ".implode(', ', $definition->children['allow']).($limits ? ' ('.implode(', ', $limits).')' : '');
        }

        return implode("\n", $lines);
    }

    /** @return array<string, ComponentDefinition> every component except the page itself */
    private function insertable(): array
    {
        return array_filter($this->registry->currentDefinitions(), fn (ComponentDefinition $definition) => $definition->type !== 'page');
    }

    private function needsImage(ComponentDefinition $definition): bool
    {
        // A block whose only purpose is an image (its publish checks require one).
        foreach ($definition->publishChecks as $check) {
            if ($check['rule'] === 'present' && in_array($check['prop'], array_map(fn ($ref) => explode('.', $ref)[0], $definition->mediaRefs), true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $path types already above this block (guards against cycles) */
    private function blockSchema(ComponentDefinition $definition, array $assetIds, array $path): array
    {
        $properties = [
            'type' => ['type' => 'string', 'const' => $definition->type],
            'props' => $this->propsSchema($definition, $assetIds, nullable: false),
        ];
        $required = ['type', 'props'];
        if ($definition->children !== false && ! in_array($definition->type, $path, true)) {
            $children = [];
            foreach ($definition->children['allow'] as $childType) {
                $child = $this->registry->current($childType);
                if ($child !== null && ($assetIds !== [] || ! $this->needsImage($child))) {
                    $children[] = $this->blockSchema($child, $assetIds, [...$path, $definition->type]);
                }
            }
            $properties['children'] = ['type' => 'array', 'items' => ['anyOf' => $children]];
            $required[] = 'children';
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties];
    }

    private function updateSchema(ComponentDefinition $definition, array $assetIds): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'type', 'props'],
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Id of an existing block.'],
                'type' => ['type' => 'string', 'const' => $definition->type],
                'props' => $this->propsSchema($definition, $assetIds, nullable: true),
            ],
        ];
    }

    private function propsSchema(ComponentDefinition $definition, array $assetIds, bool $nullable): array
    {
        $properties = [];
        foreach ($definition->props->fields() as $name => $field) {
            $schema = $this->fieldSchema($field, $assetIds);
            // In an update, null means "leave unchanged".
            $properties[$name] = $nullable ? ['anyOf' => [$schema, ['type' => 'null']]] : $schema;
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties === [] ? new \stdClass : $properties];
    }

    private function fieldSchema(array $field, array $assetIds): array
    {
        $description = $this->describeField($field);
        $schema = match ($field['type']) {
            'string', 'link' => ['type' => 'string'],
            'enum' => ['type' => 'string', 'enum' => $field['values']],
            'boolean' => ['type' => 'boolean'],
            'uuid' => $assetIds === [] ? ['type' => 'null'] : ['type' => 'string', 'enum' => $assetIds],
            'object' => $this->objectSchema($field, $assetIds),
            default => throw new \LogicException("Unknown prop type {$field['type']}"),
        };

        return isset($schema['anyOf']) ? $schema : [...$schema, 'description' => $description];
    }

    private function objectSchema(array $field, array $assetIds): array
    {
        $properties = [];
        foreach ($field['properties'] ?? [] as $name => $inner) {
            $properties[$name] = $this->fieldSchema($inner, $assetIds);
        }
        $object = ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
        $holdsMedia = in_array('uuid', array_column($field['properties'] ?? [], 'type'), true);
        if (($field['nullable'] ?? false) && $holdsMedia && $assetIds === []) {
            // No images may be referenced: the only allowed value is null.
            return ['type' => 'null', 'description' => 'Always null: no images are available.'];
        }

        return ($field['nullable'] ?? false) ? ['anyOf' => [$object, ['type' => 'null']], 'description' => $this->describeField($field)] : $object;
    }

    private function describeField(array $field): string
    {
        $parts = match ($field['type']) {
            'string' => ['plain text'.(isset($field['maxLength']) ? " up to {$field['maxLength']} characters" : '')],
            'link' => ['a link: /path, #section, ?query, https://..., http://..., mailto: or tel: (no spaces or backslashes); "" when the destination is unknown'],
            'enum' => ['one of '.implode(', ', $field['values'])],
            'boolean' => ['true or false'],
            'uuid' => ['an image asset id from the list of images on this page'],
            'object' => [($field['nullable'] ?? false) ? 'an image on this page ({assetId, alt}) or null' : 'an object'],
            default => [$field['type']],
        };
        if (array_key_exists('default', $field)) {
            $parts[] = 'default '.json_encode($field['default'], JSON_UNESCAPED_SLASHES);
        }

        return implode(', ', $parts);
    }
}
