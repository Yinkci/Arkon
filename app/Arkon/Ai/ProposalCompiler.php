<?php

namespace App\Arkon\Ai;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Schema\DocumentStructure;
use App\Arkon\Schema\OperationException;
use App\Arkon\Schema\Operations;
use App\Arkon\Support\Json;
use App\Arkon\Support\Text;
use stdClass;

/**
 * Turns a model reply into the editor's own operations (insertNode, updateProps,
 * moveNode, removeNode) against the draft it was based on, and checks the result
 * exactly as a save would: component types and versions, props, nesting, link
 * policy, media. Nothing in the reply is trusted: ids are generated here, every
 * referenced block must exist, and only images already on the page may be used.
 */
final class ProposalCompiler
{
    private const TAG = '/<\s*\/?\s*[a-z!][^>]*>/i';

    public function __construct(private readonly ComponentRegistry $registry, private readonly DocumentValidator $validator) {}

    /**
     * @param  array  $doc  the base draft (raw form, current component versions)
     * @param  mixed  $reply  the decoded JSON reply
     * @param  list<string>  $allowedAssets
     * @return array{operations: list<array>, document: array, changes: list<string>, warnings: list<string>, summary: string, notes: list<string>}
     *
     * @throws AiException INVALID_OUTPUT with the problems as issues
     */
    public function compile(array $doc, mixed $reply, array $allowedAssets, int $maxChanges): array
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

        $working = $doc;
        $operations = [];
        $descriptions = [];
        foreach ($changes as $i => $change) {
            $at = 'Change '.($i + 1);
            try {
                [$op, $description] = $this->operationFor($working, is_array($change) ? $change : []);
                $working = Operations::apply($working, [$op])['doc'];
            } catch (OperationException|ProposalProblem $error) {
                $issues[] = ['path' => "changes.{$i}", 'message' => "{$at}: {$error->getMessage()}"];

                continue;
            }
            $previous = $operations === [] ? null : $operations[array_key_last($operations)];
            if ($op['op'] === 'updateProps' && $previous && $previous['op'] === 'updateProps' && $previous['nodeId'] === $op['nodeId']) {
                // One update per block in a row, as the editor would batch them.
                $operations[array_key_last($operations)]['set'] = [...Json::entries($previous['set']), ...Json::entries($op['set'])];
                $descriptions[array_key_last($descriptions)] = $description;
            } else {
                $operations[] = $op;
                $descriptions[] = $description;
            }
        }

        if ($issues === []) {
            foreach ($this->validator->validate($working) as $issue) {
                $issues[] = ['message' => $this->describeIssue($working, $issue)];
            }
            $allowed = array_flip($allowedAssets);
            foreach ($issues === [] ? $this->validator->mediaRefs($working) : [] as $assetId) {
                if (! isset($allowed[$assetId])) {
                    $issues[] = ['message' => "Image {$assetId} is not one of the images on this page"];
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

        $warnings = array_values(array_unique(array_column($this->validator->publishIssues($working), 'message')));

        return [
            'operations' => $operations,
            'document' => $working,
            'changes' => $descriptions,
            'warnings' => $warnings,
            'summary' => Text::trim($reply['summary']),
            'notes' => $notes,
        ];
    }

    /** @return array{0: array, 1: string} */
    private function operationFor(array $doc, array $change): array
    {
        $nodes = Json::entries($doc['nodes']);
        switch ($change['action'] ?? null) {
            case 'add':
                $parentId = $this->parent($doc, $change['parent'] ?? null);
                $children = $nodes[$parentId]['children'];
                $index = $this->index($change['index'] ?? null, count($children));
                $block = $this->flatten($change['block'] ?? null);

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
                if ($set === []) {
                    throw new ProposalProblem("the update of block {$node['id']} changes nothing");
                }

                return [
                    ['op' => 'updateProps', 'nodeId' => $node['id'], 'set' => $set, 'unset' => []],
                    'Change '.$this->label($node).': '.implode(', ', array_keys($set)),
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
        if (! is_array($block) || ! is_string($block['type'] ?? null) || $depth > 4) {
            throw new ProposalProblem('a block needs a type');
        }
        $definition = $this->registry->current($block['type']);
        if ($definition === null || $block['type'] === 'page') {
            throw new ProposalProblem('there is no '.json_encode($block['type']).' block');
        }
        $props = [...$definition->defaultProps, ...(is_array($block['props'] ?? null) ? $block['props'] : [])];
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
