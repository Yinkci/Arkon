<?php

namespace App\Arkon\Ai;

use App\Arkon\Components\ColumnLayout;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Design\DesignResources;
use App\Arkon\Schema\DocumentStructure;
use App\Arkon\Schema\OperationException;
use App\Arkon\Schema\Operations;
use App\Arkon\Style\StyleSchema;
use App\Arkon\Style\Tokens;
use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Text;
use stdClass;

/**
 * Turns a model reply into the editor's own operations (insertNode, updateProps,
 * moveNode, removeNode) against the draft it was based on, and checks the result
 * exactly as a save would: component types and versions, props, nesting, link
 * policy, media. Nothing in the reply is trusted: ids are generated here, every
 * referenced block must exist, and only images already on the page may be used.
 *
 * Style settings arrive as a flat list ({slot, screen, property, value}) and are
 * merged into the block's defaults (new blocks) or its current style (updates),
 * so a follow-up changes one setting without dropping the others. Token changes
 * are validated here but never become page operations.
 */
final class ProposalCompiler
{
    private const TAG = '/<\s*\/?\s*[a-z!][^>]*>/i';

    /** @var array<string, string> names given to new blocks in this reply ("ref") → their generated ids */
    private array $refs = [];

    /** @var array<string, list<string>> Columns blocks → screens whose widths the reply sets itself (taken as they are, then validated) */
    private array $explicitWidths = [];

    /** @var array<string, string> columns copied in this reply → the column they copy (a copy takes its width) */
    private array $copies = [];

    public function __construct(private readonly ComponentRegistry $registry, private readonly DocumentValidator $validator) {}

    /**
     * @param  array  $doc  the base draft (raw form, current component versions)
     * @param  mixed  $reply  the decoded JSON reply
     * @param  list<string>  $allowedAssets
     * @param  list<string>  $allowedComponents  published reusable components (instances may only use these)
     * @return array{operations: list<array>, document: array, changes: list<string>, warnings: list<string>, summary: string, notes: list<string>, tokenChanges: list<array{token: string, value: string}>}
     *
     * @throws AiException INVALID_OUTPUT with the problems as issues
     */
    public function compile(array $doc, mixed $reply, array $allowedAssets, int $maxChanges, array $allowedComponents = [], ?array $allowedThemeTypes = null): array
    {
        $issues = [];
        if (! is_array($reply) || ! is_string($reply['summary'] ?? null) || ! Json::isList($reply['notes'] ?? null) || ! Json::isList($reply['changes'] ?? null)) {
            throw self::invalid([['message' => 'The reply is not a proposal (summary, notes and changes are required).']]);
        }
        $changes = $reply['changes'];
        if (count($changes) > $maxChanges) {
            throw self::invalid([['message' => 'Too many changes at once ('.count($changes)." > {$maxChanges}). Ask for less in one request."]]);
        }
        $notes = array_values(array_filter(array_map(fn ($note) => is_string($note) ? Text::trim($note) : '', array_slice($reply['notes'], 0, 8)), fn ($note) => $note !== ''));
        $tokenChanges = $this->tokenChanges($reply['tokenChanges'] ?? [], $issues);

        $this->refs = [];
        $this->explicitWidths = [];
        $this->copies = [];
        $working = $doc;
        $operations = [];
        $descriptions = [];
        foreach ($changes as $i => $change) {
            $at = 'Change '.($i + 1);
            try {
                [$op, $description] = $this->operationFor($working, is_array($change) ? $change : []);
                // A duplicated column also updates its Columns block's widths: two operations, one change.
                $ops = isset($op['op']) ? [$op] : $op;
                $working = Operations::apply($working, $ops)['doc'];
            } catch (OperationException|ProposalProblem $error) {
                $issues[] = ['path' => "changes.{$i}", 'message' => "{$at}: {$error->getMessage()}"];

                continue;
            }
            if (count($ops) > 1) {
                array_push($operations, ...$ops);
                $descriptions[] = $description;

                continue;
            }
            $previous = $operations === [] ? null : $operations[array_key_last($operations)];
            if ($op['op'] === 'updateSeo' && $previous && $previous['op'] === 'updateSeo') {
                // The editor merges consecutive metadata edits into one save operation. Review and save must match exactly.
                $operations[array_key_last($operations)]['set'] = [...Json::entries($previous['set']), ...Json::entries($op['set'])];
                $descriptions[array_key_last($descriptions)] .= '; '.$description;
            } elseif ($op['op'] === 'updateProps' && $previous && $previous['op'] === 'updateProps' && $previous['nodeId'] === $op['nodeId']) {
                // One update per block in a row, as the editor would batch them.
                $operations[array_key_last($operations)]['set'] = [...Json::entries($previous['set']), ...Json::entries($op['set'])];
                $descriptions[array_key_last($descriptions)] = $description;
            } else {
                $operations[] = $op;
                $descriptions[] = $description;
            }
        }

        if ($issues === []) {
            // The whole reply, not each step: a Columns block whose columns changed gets its widths
            // reconciled at the end (unless the reply sets them), as one more reviewed operation.
            foreach (ColumnLayout::changes($doc, $working, $this->explicitWidths, $this->copies) as $change) {
                $working = Operations::apply($working, [$change['op']])['doc'];
                $operations[] = $change['op'];
                $descriptions[] = $change['description'];
            }
            foreach ($this->validator->validate($working) as $issue) {
                $issues[] = ['message' => $this->describeIssue($working, $issue)];
            }
            foreach (Json::entries($working['nodes']) as $node) {
                if (str_starts_with($node['type'], 'theme-') && $allowedThemeTypes !== null && ! in_array($node['type'], $allowedThemeTypes, true)) {
                    $issues[] = ['message' => 'This component is not available in the site’s active theme.'];
                }
            }
            $allowed = array_flip($allowedAssets);
            foreach ($issues === [] ? $this->validator->mediaRefs($working) : [] as $assetId) {
                if (! isset($allowed[$assetId])) {
                    $issues[] = ['message' => "Image {$assetId} is not one of the images on this page"];
                }
            }
            $known = array_flip($allowedComponents);
            foreach (DesignResources::componentIds($working) as $componentId) {
                if (! isset($known[$componentId])) {
                    $issues[] = ['message' => "Reusable component {$componentId} is not one of the published components"];
                }
            }
            foreach ($this->strings($working, $doc) as [$where, $value]) {
                if (preg_match(self::TAG, $value) === 1) {
                    $issues[] = ['message' => "{$where}: use plain text, not HTML"];
                }
            }
        }
        if ($issues !== []) {
            throw self::invalid($issues);
        }

        $placeholder = [];
        foreach (Json::entries($working['nodes']) as $node) {
            if ($node['type'] === 'button' && (Json::entries($node['props'])['href'] ?? '') === '#') {
                $placeholder[] = 'Button uses a placeholder destination (#)';
            }
        }
        $warnings = array_values(array_unique([...array_column($this->validator->publishIssues($working), 'message'), ...$placeholder]));

        return [
            'operations' => $operations,
            'document' => $working,
            'changes' => $descriptions,
            'warnings' => $warnings,
            'summary' => Text::trim($reply['summary']),
            'notes' => $notes,
            'tokenChanges' => $tokenChanges,
        ];
    }

    /**
     * Site-wide token changes: each a known token and a valid value. The last change
     * of a token wins.
     *
     * @return list<array{token: string, value: string}>
     */
    private function tokenChanges(mixed $changes, array &$issues): array
    {
        if (! Json::isList($changes) && $changes !== []) {
            $issues[] = ['path' => 'tokenChanges', 'message' => 'tokenChanges must be a list'];

            return [];
        }
        $out = [];
        foreach ($changes as $i => $change) {
            $token = is_array($change) ? ($change['token'] ?? null) : null;
            $value = is_array($change) ? ($change['value'] ?? null) : null;
            if (! is_string($token) || ! in_array($token, ProposalSchema::tokenNames(), true)) {
                $issues[] = ['path' => "tokenChanges.{$i}", 'message' => 'Token change '.($i + 1).': unknown token '.json_encode($token)];

                continue;
            }
            [$group, $name] = explode('.', substr($token, 1), 2);
            $problem = StyleSchema::valueProblem(Tokens::definition($group, $name), $value);
            if ($problem !== null) {
                $issues[] = ['path' => "tokenChanges.{$i}", 'message' => 'Token change '.($i + 1).": {$problem}"];

                continue;
            }
            $out[$token] = ['token' => $token, 'value' => $value];
        }

        return array_values($out);
    }

    /**
     * Props from a reply, with style setting lists turned into stored styles merged
     * into `$current` (the block's defaults or its current props).
     */
    private function convertProps(array $fields, array $props, array $current): array
    {
        foreach ($props as $key => $value) {
            if ($key === 'responsive' && isset($fields[$key]['properties']['tablet']) && Json::isList($value)) {
                $overrides = Json::toArray($current[$key] ?? []);
                foreach ($value as $setting) {
                    $setting = Json::entries($setting);
                    $screen = $setting['screen'] ?? '';
                    $property = $setting['property'] ?? '';
                    $v = $setting['value'] ?? null;
                    $field = $fields[$key]['properties'][$screen]['properties'][$property] ?? null;
                    if (array_diff(array_keys($setting), ['screen', 'property', 'value']) !== [] || ! in_array($screen, ['tablet', 'mobile'], true) || ! $field || ! in_array($v, $field['values'], true)) {
                        throw new ProposalProblem('Invalid responsive component setting');
                    }
                    $overrides[$screen][$property] = $v;
                }
                $props[$key] = $overrides;
            }
            if (($fields[$key]['type'] ?? null) === 'style' && Json::isList($value)) {
                $props[$key] = $this->applyStyle(Json::toArray($current[$key] ?? []), $value);
            }
        }

        return $props;
    }

    /** @param list<mixed> $settings {slot, screen, property, value}; value null removes */
    private function applyStyle(array $style, array $settings): array|stdClass
    {
        foreach ($settings as $i => $setting) {
            $slot = is_array($setting) ? ($setting['slot'] ?? null) : null;
            $screen = is_array($setting) ? ($setting['screen'] ?? null) : null;
            $property = is_array($setting) ? ($setting['property'] ?? null) : null;
            $value = is_array($setting) ? ($setting['value'] ?? null) : null;
            if (! is_string($slot) || ! is_string($screen) || ! is_string($property) || ! (is_string($value) || $value === null)) {
                throw new ProposalProblem('design setting '.($i + 1).' needs a slot, screen, property and a text value (or null)');
            }
            if ($value === null) {
                unset($style[$slot][$screen][$property]);
                if (($style[$slot][$screen] ?? null) === []) {
                    unset($style[$slot][$screen]);
                }
                if (($style[$slot] ?? null) === []) {
                    unset($style[$slot]);
                }

                continue;
            }
            $style[$slot][$screen][$property] = $property === 'backgroundImage' ? ['assetId' => $value] : $value;
        }

        return $style === [] ? new stdClass : $style;
    }

    /** @return array{0: array, 1: string} */
    private function operationFor(array $doc, array $change): array
    {
        $nodes = Json::entries($doc['nodes']);
        switch ($change['action'] ?? null) {
            case 'alt':
                $id = $change['nodeId'] ?? '';
                $node = Json::entries($doc['nodes'])[$id] ?? null;
                $image = $node ? Json::entries(Json::entries($node['props'])['image'] ?? []) : [];
                if (! $node || ! isset($image['assetId']) || ! is_string($change['value'] ?? null)) {
                    throw new ProposalProblem('Choose an existing image block for alt text.');
                }

                return [['op' => 'updateProps', 'nodeId' => $id, 'set' => ['image' => [...$image, 'alt' => $change['value']]]], 'Image alt text: '.($image['alt'] ?? '(empty)').' → '.$change['value']];
            case 'seo':
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? null;
                if (! in_array($field, ['title', 'description', 'focusTopic', 'socialTitle', 'socialDescription'], true) || ! is_string($value)) {
                    throw new ProposalProblem('Use an editable SEO text field');
                }

                return [['op' => 'updateSeo', 'set' => [$field => $value]], 'SEO '.$field.': '.(Json::entries($doc['seo'])[$field] ?? '(default)').' → '.$value];
            case 'add':
                $parentId = $this->parent($doc, $change['parent'] ?? null);
                $children = $nodes[$parentId]['children'];
                $index = $this->index($change['index'] ?? null, count($children));
                $block = $this->flatten($change['block'] ?? null);
                $ref = $change['ref'] ?? null;
                if (is_string($ref) && $ref !== '') {
                    if (isset($this->refs[$ref])) {
                        throw new ProposalProblem('the name '.json_encode($ref).' is already used by another new block');
                    }
                    $this->refs[$ref] = $block[0]['id'];
                }

                return [
                    ['op' => 'insertNode', 'parentId' => $parentId, 'index' => $index, 'nodes' => $block],
                    'Add '.$this->label($block[0]).$this->inside($block, $doc).$this->where($doc, $parentId),
                ];

            case 'update':
                $update = is_array($change['change'] ?? null) ? $change['change'] : [];
                $node = $this->existing($doc, $update['id'] ?? null);
                if (($update['type'] ?? null) !== $node['type']) {
                    throw new ProposalProblem("block {$node['id']} is a {$node['type']}, not a ".json_encode($update['type'] ?? null));
                }
                $set = array_filter(is_array($update['props'] ?? null) ? $update['props'] : [], fn ($value) => $value !== null);
                $set = $this->convertProps($this->registry->current($node['type'])?->props->fields() ?? [], $set, Json::entries($node['props'] ?? []));
                // Only actual width settings count, per screen; gap, padding, background, animation … do not.
                if ($node['type'] === 'columns' && Json::isList($update['props']['style'] ?? null)) {
                    foreach ($update['props']['style'] as $setting) {
                        if (is_array($setting) && ($setting['property'] ?? null) === 'columns' && is_string($setting['screen'] ?? null)) {
                            $this->explicitWidths[$node['id']] = array_values(array_unique([...($this->explicitWidths[$node['id']] ?? []), $setting['screen']]));
                        }
                    }
                }
                if ($set === []) {
                    throw new ProposalProblem("the update of block {$node['id']} changes nothing");
                }

                return [
                    ['op' => 'updateProps', 'nodeId' => $node['id'], 'set' => $set, 'unset' => []],
                    // A block added or copied earlier in this proposal is "new" (a copy still has the original's text).
                    'Change '.(in_array($node['id'], $this->refs, true) ? 'new ' : '').$this->label($node).': '.implode(', ', array_keys($set)),
                ];

            case 'move':
                $node = $this->existing($doc, $change['id'] ?? null);
                $parentId = $this->parent($doc, $change['parent'] ?? null);
                $from = DocumentStructure::findParent($doc, $node['id']);
                $count = count($nodes[$parentId]['children']) - ($from && $from['parentId'] === $parentId ? 1 : 0);

                return [
                    ['op' => 'moveNode', 'nodeId' => $node['id'], 'parentId' => $parentId, 'index' => $this->index($change['index'] ?? null, $count)],
                    'Move '.$this->label($node).$this->where($doc, $parentId),
                ];

            case 'remove':
                $node = $this->existing($doc, $change['id'] ?? null);

                return [['op' => 'removeNode', 'nodeId' => $node['id']], 'Remove '.$this->label($node)];

            case 'duplicate':
                // A deep copy right after the original (the editor's Duplicate). Its ref names the copy.
                $node = $this->existing($doc, $change['id'] ?? null);
                $copy = Duplicates::of($doc, $node['id']);
                $ref = $change['ref'] ?? null;
                if (is_string($ref) && $ref !== '') {
                    if (isset($this->refs[$ref])) {
                        throw new ProposalProblem('the name '.json_encode($ref).' is already used by another new block');
                    }
                    $this->refs[$ref] = $copy['nodes'][0]['id'];
                }
                $ops = ['op' => 'insertNode', 'parentId' => $copy['parentId'], 'index' => $copy['index'], 'nodes' => $copy['nodes']];
                $this->copies[$copy['nodes'][0]['id']] = $this->copies[$node['id']] ?? $node['id'];

                return [$ops, 'Duplicate '.$this->label($node).(count($copy['nodes']) > 1 ? ' (with '.(count($copy['nodes']) - 1).' blocks inside)' : '').$this->where($doc, $copy['parentId'])];
        }

        throw new ProposalProblem('unknown action '.json_encode($change['action'] ?? null));
    }

    private function parent(array $doc, mixed $parent): string
    {
        if ($parent === ProposalSchema::PAGE_PARENT || $parent === $doc['root']) {
            return $doc['root'];
        }
        $node = $this->existing($doc, $parent);
        if (! array_key_exists('children', $node)) {
            throw new ProposalProblem("block {$node['id']} cannot contain other blocks");
        }

        return $node['id'];
    }

    private function existing(array $doc, mixed $id): array
    {
        if (is_string($id) && str_starts_with($id, ProposalSchema::NEW_PREFIX)) {
            $name = substr($id, strlen(ProposalSchema::NEW_PREFIX));
            $id = $this->refs[$name] ?? throw new ProposalProblem('no earlier change adds a block named '.json_encode($name));
        }
        $node = is_string($id) ? (Json::entries($doc['nodes'])[$id] ?? null) : null;
        if ($node === null || $id === $doc['root']) {
            throw new ProposalProblem('there is no block '.json_encode($id).' on this page');
        }

        return $node;
    }

    private function index(mixed $index, int $count): int
    {
        if ($index === null) {
            return $count;
        }
        if (! is_int($index) || $index < 0 || $index > $count) {
            throw new ProposalProblem("position {$index} is out of range (0 to {$count})");
        }

        return $index;
    }

    /**
     * A nested block becomes the node list insertNode expects: the root first, every node
     * with a new id, the current component version, and defaults for props not given.
     *
     * @return list<array>
     */
    private function flatten(mixed $block, int $depth = 0): array
    {
        if (! is_array($block) || ! is_string($block['type'] ?? null) || $depth > (int) Rules::get('maxDepth')) {
            throw new ProposalProblem('a block needs a type');
        }
        $definition = $this->registry->current($block['type']);
        if ($definition === null || $block['type'] === 'page') {
            throw new ProposalProblem('there is no '.json_encode($block['type']).' block');
        }
        $given = is_array($block['props'] ?? null) ? $block['props'] : [];
        $props = [...$definition->defaultProps, ...$this->convertProps($definition->props->fields(), $given, $definition->defaultProps)];
        if ($definition->type === 'button' && ($props['href'] ?? '') === '') {
            $props['href'] = '#';
        }
        $node = ['id' => Operations::newNodeId(), 'type' => $definition->type, 'version' => $definition->version, 'props' => $props === [] ? new stdClass : $props];
        $descendants = [];
        if ($definition->children !== false) {
            $node['children'] = [];
            foreach (Json::isList($block['children'] ?? null) ? $block['children'] : [] as $child) {
                $flat = $this->flatten($child, $depth + 1);
                $node['children'][] = $flat[0]['id'];
                array_push($descendants, ...$flat);
            }
        } elseif (! empty($block['children'])) {
            throw new ProposalProblem("a {$definition->type} block cannot contain other blocks");
        }

        return [$node, ...$descendants];
    }

    private function label(array $node): string
    {
        $definition = $this->registry->current($node['type']);
        $name = $definition?->label ?? $node['type'];
        $props = Json::entries($node['props'] ?? []);
        foreach (['heading', 'text', 'label', 'caption'] as $key) {
            if (is_string($props[$key] ?? null) && Text::trim($props[$key]) !== '') {
                $text = preg_replace('/\s+/u', ' ', Text::trim($props[$key]));

                return "{$name} “".(mb_strlen($text) > 40 ? mb_substr($text, 0, 40).'…' : $text).'”';
            }
        }

        return $name;
    }

    /** @param list<array> $flat */
    private function inside(array $flat, array $doc): string
    {
        $inner = count($flat) - 1;
        if ($inner === 0) {
            return '';
        }
        $direct = count($flat[0]['children'] ?? []);
        $childType = $flat[1]['type'] ?? null;
        $childLabel = strtolower($this->registry->current((string) $childType)?->label ?? 'block');

        return " with {$direct} {$childLabel}".($direct === 1 ? '' : 's').($inner > $direct ? ' ('.($inner - $direct).' blocks inside)' : '');
    }

    private function where(array $doc, string $parentId): string
    {
        return $parentId === $doc['root'] ? '' : ' inside '.$this->label(Json::entries($doc['nodes'])[$parentId]);
    }

    private function describeIssue(array $doc, array $issue): string
    {
        $node = isset($issue['nodeId']) ? (Json::entries($doc['nodes'])[$issue['nodeId']] ?? null) : null;
        $where = $node ? $this->label($node).(isset($issue['path']) ? " {$issue['path']}" : '') : 'Page';

        return "{$where}: {$issue['message']}";
    }

    /**
     * String props that are new or changed compared with the base draft.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function strings(array $working, array $base): array
    {
        $before = Json::entries($base['nodes']);
        $out = [];
        foreach (Json::entries($working['nodes']) as $id => $node) {
            $old = Json::entries($before[$id]['props'] ?? []);
            foreach (Json::entries($node['props'] ?? []) as $key => $value) {
                $values = is_array($value) ? array_filter($value, 'is_string') : [$value];
                foreach ($values as $string) {
                    if (is_string($string) && ($old[$key] ?? null) !== $value) {
                        $out[] = [$this->label($node)." {$key}", $string];
                    }
                }
            }
        }

        return $out;
    }

    /** @param list<array{message: string, path?: string}> $issues */
    private static function invalid(array $issues): AiException
    {
        return new AiException(AiException::INVALID_OUTPUT, "The AI's proposal doesn't fit this page's rules, so nothing was changed. Try rephrasing the request.", array_slice($issues, 0, 10));
    }
}
