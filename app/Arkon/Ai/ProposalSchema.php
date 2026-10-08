<?php

namespace App\Arkon\Ai;

use App\Arkon\Components\ComponentDefinition;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Style\StyleSchema;
use App\Arkon\Style\Tokens;
use App\Arkon\Support\Rules;

/**
 * What the model may answer, derived from the component registry and the shared
 * style registry: the current version of every registered component, its props
 * (types, enums, limits, defaults, style slots) and its nesting rules. Both the JSON
 * schema (enforced by structured output) and the catalogue text (the instructions)
 * come from the same manifests, so a new component version or style property changes
 * what the AI can propose without touching this code.
 *
 * Shape of a reply:
 *   { summary, notes[], tokenChanges[], changes[] } where a change is one of
 *   add    { parent: "page" | id | "new:<ref>", index | null, ref | null, block }
 *   update { id, type, props }                              (null props are left unchanged)
 *   move   { id, parent, index | null }
 *   remove { id }
 *   duplicate { id, ref | null }                            (a copy right after the block, like the editor's Duplicate)
 *
 * Blocks are added one at a time: an add may name its new block (`ref`) so that
 * later adds put children inside it with parent "new:<ref>". This keeps the schema
 * flat, small enough for the Claude Code command line whatever the nesting depth.
 *
 * Style props are a flat list of settings { slot, screen, property, value }; the
 * compiler turns them into the stored {slot: {screen: {property: value}}} form and
 * merges them into the block's defaults or current style (value null removes one).
 * Site-wide token changes are a separate list, reviewed and applied apart from the
 * page changes.
 */
final class ProposalSchema
{
    public const PAGE_PARENT = 'page';

    public const NEW_PREFIX = 'new:';

    /** @var list<string> published reusable components a proposal may insert (set by schema()) */
    private array $componentIds = [];

    public function __construct(private readonly ComponentRegistry $registry) {}

    /**
     * @param  list<string>  $assetIds  images the proposal may reference (already on the page)
     * @param  list<string>  $componentIds  published reusable components the proposal may insert
     */
    public function schema(array $assetIds, array $componentIds = [], ?array $themeTypes = null): array
    {
        $this->componentIds = $componentIds;
        $definitions = $this->insertable($themeTypes);
        $defs = ['style' => $this->styleSettingSchema()];
        foreach ($definitions as $type => $definition) {
            $defs["block_{$type}"] = $this->blockSchema($definition, $assetIds);
            $defs["update_{$type}"] = $this->updateSchema($definition, $assetIds);
        }
        $addable = array_values(array_filter(array_keys($definitions), fn ($type) => $this->offerable($definitions[$type], $assetIds)));
        $nullableInt = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'notes', 'tokenChanges', 'changes'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tokenChanges' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['token', 'value'],
                        'properties' => ['token' => ['type' => 'string', 'enum' => self::tokenNames()], 'value' => ['type' => 'string']],
                    ],
                ],
                'changes' => [
                    'type' => 'array',
                    'items' => ['anyOf' => [
                        [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'parent', 'index', 'ref', 'block'],
                            'properties' => [
                                'action' => ['type' => 'string', 'const' => 'add'],
                                'parent' => ['type' => 'string'],
                                'index' => $nullableInt,
                                'ref' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
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
                            'properties' => ['action' => ['type' => 'string', 'const' => 'move'], 'id' => ['type' => 'string'], 'parent' => ['type' => 'string'], 'index' => $nullableInt],
                        ],
                        [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'id'],
                            'properties' => ['action' => ['type' => 'string', 'const' => 'remove'], 'id' => ['type' => 'string']],
                        ],
                        [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['action', 'id', 'ref'],
                            'properties' => [
                                'action' => ['type' => 'string', 'const' => 'duplicate'],
                                'id' => ['type' => 'string'],
                                'ref' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                            ],
                        ],
                    ]],
                ],
            ],
            '$defs' => $defs,
        ];
    }

    /** The catalogue part of the instructions: blocks, props, nesting, design settings and tokens, from the registries. */
    public function catalogue(?array $themeTypes = null): string
    {
        $lines = [];
        foreach ($this->insertable($themeTypes) as $type => $definition) {
            $props = [];
            foreach ($definition->props->fields() as $name => $field) {
                $props[] = "{$name}: ".$this->describeField($field);
            }
            $lines[] = "- {$type} ({$definition->label}, version {$definition->version})".($props === [] ? ': no props' : ': '.implode('; ', $props));
        }
        $lines[] = '';
        $lines[] = 'Nesting (anything else is refused; at most '.Rules::get('maxDepth').' levels deep, the page included):';
        foreach ($this->registry->currentDefinitions() as $type => $definition) {
            if ($definition->children === false || $type === 'fragment') {
                continue;
            }
            $limits = array_filter([
                isset($definition->children['min']) ? "at least {$definition->children['min']}" : null,
                isset($definition->children['max']) ? "at most {$definition->children['max']}" : null,
            ]);
            $holder = $type === 'page' ? 'The page' : "{$type}";
            $lines[] = "- {$holder} holds: ".implode(', ', array_values(array_filter($definition->children['allow'], fn ($child) => ! str_starts_with($child, 'theme-') || $themeTypes === null || in_array($child, $themeTypes, true)))).($limits ? ' ('.implode(', ', $limits).')' : '');
        }

        $lines[] = '';
        $lines[] = 'Design settings (the "style" prop) are a list of {slot, screen, property, value}. screen is base (all screens), tablet (899px wide and narrower) or mobile (599px and narrower); a smaller screen inherits the larger one unless it sets its own value. Each slot accepts only the properties listed for it above. Properties and their accepted values:';
        foreach (StyleSchema::properties() as $property => $definition) {
            $ranges = match ($definition['kind']) {
                'length' => ' (ranges: '.implode(', ', array_map(fn ($unit, $b) => "{$unit} {$b[0]} to {$b[1]}", array_keys(Rules::get("style.lengths.{$definition['lengths']}")), Rules::get("style.lengths.{$definition['lengths']}"))).')',
                'number' => " ({$definition['min']} to {$definition['max']})",
                'time' => " ({$definition['min']}ms to {$definition['max']}ms)",
                default => '',
            };
            $lines[] = "- {$property} ({$definition['label']}): ".StyleSchema::expected($definition).$ranges.(($definition['baseOnly'] ?? false) ? '; base screen only' : '');
        }
        $lines[] = '';
        $lines[] = 'Design tokens are the site\'s shared values; prefer them to fixed values so pages stay consistent (defaults shown, the site may have changed them):';
        foreach (Tokens::groups() as $group => $definition) {
            $lines[] = "- {$definition['label']}: ".implode(', ', array_map(fn ($name, $value) => "@{$group}.{$name} = {$value}", array_keys($definition['values']), $definition['values']));
        }

        return implode("\n", $lines);
    }

    /** @return list<string> every token as "@group.name" */
    public static function tokenNames(): array
    {
        $names = [];
        foreach (Tokens::groups() as $group => $definition) {
            foreach (array_keys($definition['values']) as $name) {
                $names[] = "@{$group}.{$name}";
            }
        }

        return $names;
    }

    public static function refersToComponents(ComponentDefinition $definition): bool
    {
        return in_array('component', array_column($definition->props->fields(), 'ref'), true);
    }

    /** @return array<string, ComponentDefinition> every component a page can contain (not the page, not a reusable component's root) */
    private function insertable(?array $themeTypes = null): array
    {
        return array_filter($this->registry->currentDefinitions(), fn (ComponentDefinition $definition) => ! in_array($definition->type, ['page', 'fragment'], true) && (! str_starts_with($definition->type, 'theme-') || $themeTypes === null || in_array($definition->type, $themeTypes, true)));
    }

    /** Whether a block can be proposed at all: image blocks need images, instances need published components. */
    private function offerable(ComponentDefinition $definition, array $assetIds): bool
    {
        if ($this->needsImage($definition) && $assetIds === []) {
            return false;
        }

        return ! self::refersToComponents($definition) || $this->componentIds !== [];
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

    private function blockSchema(ComponentDefinition $definition, array $assetIds): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'props'],
            'properties' => [
                'type' => ['type' => 'string', 'const' => $definition->type],
                'props' => $this->propsSchema($definition, $assetIds, nullable: false),
            ],
        ];
    }

    private function updateSchema(ComponentDefinition $definition, array $assetIds): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'type', 'props'],
            'properties' => [
                'id' => ['type' => 'string'],
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
        return match ($field['type']) {
            'string', 'link' => ['type' => 'string'],
            'enum' => ['type' => 'string', 'enum' => $field['values']],
            'boolean' => ['type' => 'boolean'],
            'uuid' => ($field['ref'] ?? null) === 'component'
                ? ($this->componentIds === [] ? ['type' => 'null'] : ['type' => 'string', 'enum' => $this->componentIds])
                : ($assetIds === [] ? ['type' => 'null'] : ['type' => 'string', 'enum' => $assetIds]),
            'style' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/style']],
            'object' => $this->objectSchema($field, $assetIds),
            default => throw new \LogicException("Unknown prop type {$field['type']}"),
        };
    }

    /** One design setting. Which slots and properties a block accepts is checked by the compiler and listed in the catalogue. */
    private function styleSettingSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['slot', 'screen', 'property', 'value'],
            'properties' => [
                'slot' => ['type' => 'string'],
                'screen' => ['type' => 'string', 'enum' => StyleSchema::BREAKPOINTS],
                'property' => ['type' => 'string', 'enum' => array_keys(StyleSchema::properties())],
                'value' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            ],
        ];
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
            return ['type' => 'null'];
        }

        return ($field['nullable'] ?? false) ? ['anyOf' => [$object, ['type' => 'null']]] : $object;
    }

    private function describeField(array $field): string
    {
        $parts = match ($field['type']) {
            'string' => ['plain text'.(isset($field['maxLength']) ? " up to {$field['maxLength']} characters" : '')],
            'link' => ['a link: /path, #section, ?query, https://..., http://..., mailto: or tel: (no spaces or backslashes); "" when the destination is unknown'],
            'enum' => ['one of '.implode(', ', $field['values'])],
            'boolean' => ['true or false'],
            'uuid' => [($field['ref'] ?? null) === 'component' ? 'the id of a reusable component from the list of reusable components' : 'an image asset id from the list of images on this page'],
            'style' => ['design settings; slots: '.implode('; ', array_map(
                fn ($slot, $definition) => "{$slot} ({$definition['label']}) accepts ".implode(', ', StyleSchema::allowed($definition)),
                array_keys($field['slots']),
                $field['slots'],
            ))],
            'object' => [($field['nullable'] ?? false) ? 'an image on this page ({assetId, alt}) or null' : 'an object'],
            default => [$field['type']],
        };
        if ($field['type'] === 'style') {
            $defaults = self::settings($field['default'] ?? []);
            $parts[] = $defaults === [] ? 'default: none' : 'default: '.implode(', ', $defaults);
        } elseif (array_key_exists('default', $field)) {
            $parts[] = 'default '.json_encode($field['default'], JSON_UNESCAPED_SLASHES);
        }

        return implode(', ', $parts);
    }

    /** A stored style as readable settings: "root tablet direction=column". */
    public static function settings(array $style): array
    {
        $out = [];
        foreach ($style as $slot => $screens) {
            foreach ((array) $screens as $screen => $values) {
                foreach ((array) $values as $property => $value) {
                    $out[] = "{$slot} {$screen} {$property}=".(is_array($value) ? ($value['assetId'] ?? '') : $value);
                }
            }
        }

        return $out;
    }
}
