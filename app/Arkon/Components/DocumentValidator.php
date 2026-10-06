<?php

namespace App\Arkon\Components;

use App\Arkon\Schema\DocumentShape;
use App\Arkon\Schema\DocumentStructure;
use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;

/**
 * Full validation of a page document: shape, tree structure, known component
 * types and versions, props and parent/child rules. This is what makes a
 * document saveable. The TypeScript twin (resources/js/arkon/components/validate.ts)
 * runs in the editor; tests/conformance holds both to the same results.
 */
final class DocumentValidator
{
    public function __construct(private readonly ComponentRegistry $registry) {}

    /**
     * Editing policy: every node must be at its component's current version. This
     * is what makes a document saveable and publishable as new work.
     *
     * @return list<array{nodeId?: string, path?: string, message: string}>
     */
    public function validate(mixed $doc): array
    {
        return $this->check($doc, pinned: false);
    }

    /**
     * Historical policy: every node is checked against its own (immutable)
     * component version, whatever the current version is. Used to reproduce and
     * re-render what was published; never to accept new edits.
     *
     * @return list<array{nodeId?: string, path?: string, message: string}>
     */
    public function validatePinned(mixed $doc): array
    {
        return $this->check($doc, pinned: true);
    }

    private function check(mixed $doc, bool $pinned): array
    {
        $shape = DocumentShape::documentIssues($doc);
        if ($shape !== []) {
            return $shape;
        }
        $issues = DocumentStructure::validate($doc);
        $nodes = Json::entries($doc['nodes']);
        if (($nodes[$doc['root']]['type'] ?? null) !== 'page') {
            $issues[] = ['message' => Rules::message('rootNotPage')];
        }

        foreach ($nodes as $node) {
            $current = $this->registry->current($node['type']);
            if ($current === null) {
                $issues[] = ['nodeId' => $node['id'], 'message' => Rules::message('unknownComponent', ['type' => $node['type']])];

                continue;
            }
            // Props and children are always checked against the node's own version, if it exists.
            $definition = $this->registry->get($node['type'], (int) $node['version']);
            if ($definition === null || (! $pinned && $definition->version !== $current->version)) {
                $issues[] = ['nodeId' => $node['id'], 'message' => Rules::message('unsupportedVersion', ['type' => $node['type'], 'version' => $node['version']])];
            }
            if ($definition === null) {
                continue;
            }
            [, $propIssues] = $definition->props->parse($node['props']);
            foreach ($propIssues as $issue) {
                $issues[] = ['nodeId' => $node['id'], 'path' => $issue['path'], 'message' => $issue['message']];
            }
            if ($definition->children === false) {
                if (array_key_exists('children', $node)) {
                    $issues[] = ['nodeId' => $node['id'], 'message' => Rules::message('cannotHaveChildren', ['label' => $definition->label])];
                }
            } else {
                $children = $node['children'] ?? [];
                if (! array_key_exists('children', $node)) {
                    $issues[] = ['nodeId' => $node['id'], 'message' => Rules::message('missingChildren')];
                }
                $max = $definition->children['max'] ?? null;
                if ($max !== null && count($children) > $max) {
                    $issues[] = ['nodeId' => $node['id'], 'message' => Rules::message('tooManyChildren', ['max' => $max])];
                }
                $min = $definition->children['min'] ?? null;
                if ($min !== null && count($children) < $min) {
                    $issues[] = ['nodeId' => $node['id'], 'message' => Rules::message('tooFewChildren', ['min' => $min])];
                }
                foreach ($children as $childId) {
                    $child = $nodes[$childId] ?? null;
                    if ($child !== null && ! in_array($child['type'], $definition->children['allow'], true)) {
                        $issues[] = ['nodeId' => $childId, 'message' => Rules::message('childNotAllowed', ['child' => $child['type'], 'parent' => $node['type']])];
                    }
                }
            }
        }

        return $issues;
    }

    /** The definition of a node's own component version (assumes a validated document). */
    public function definitionOf(array $node): ComponentDefinition
    {
        return $this->registry->get($node['type'], (int) $node['version'])
            ?? throw new \InvalidArgumentException("Unknown component {$node['type']} v{$node['version']}");
    }

    /**
     * Problems that block publishing but not saving a draft. Assumes a valid document.
     *
     * @return list<array{nodeId: string, message: string}>
     */
    public function publishIssues(array $doc): array
    {
        $issues = [];
        foreach (Json::entries($doc['nodes']) as $node) {
            $definition = $this->definitionOf($node);
            foreach ($definition->publishProblems($definition->props->parseValid($node['props'])) as $message) {
                $issues[] = ['nodeId' => $node['id'], 'message' => $message];
            }
        }

        return $issues;
    }

    /**
     * Media asset ids referenced anywhere in a valid document.
     *
     * @return list<string>
     */
    public function mediaRefs(array $doc): array
    {
        $ids = [];
        foreach (Json::entries($doc['nodes']) as $node) {
            $definition = $this->definitionOf($node);
            array_push($ids, ...$definition->mediaRefsOf($definition->props->parseValid($node['props'])));
        }

        return array_values(array_unique($ids));
    }

    /** Media of a document that may be invalid (under the given policy): none, rather than guessing. */
    public function safeMediaRefs(mixed $doc, bool $pinned = false): array
    {
        return $this->check($doc, $pinned) === [] ? $this->mediaRefs($doc) : [];
    }

    /**
     * Media asset ids referenced by a stored document, tolerating documents that
     * no longer validate as a whole (older revisions). Each node is read with the
     * definition of its own version; nodes whose type or version is unknown, or
     * whose props no longer parse, are counted rather than guessed at.
     *
     * @return array{ids: list<string>, skippedNodes: int}
     */
    public function mediaRefsLenient(mixed $doc): array
    {
        $ids = [];
        $skipped = 0;
        $nodes = is_array($doc) ? ($doc['nodes'] ?? null) : null;
        if (! Json::isObject($nodes)) {
            return ['ids' => [], 'skippedNodes' => 0];
        }
        foreach (Json::entries($nodes) as $node) {
            $type = is_array($node) ? ($node['type'] ?? null) : null;
            $version = is_array($node) ? ($node['version'] ?? null) : null;
            $definition = is_string($type) && is_int($version) ? $this->registry->get($type, $version) : null;
            if ($definition === null) {
                $skipped++;

                continue;
            }
            if ($definition->mediaRefs === []) {
                continue;
            }
            [$props] = $definition->props->parse($node['props'] ?? null);
            if ($props === null) {
                $skipped++;

                continue;
            }
            array_push($ids, ...$definition->mediaRefsOf($props));
        }

        return ['ids' => array_values(array_unique($ids)), 'skippedNodes' => $skipped];
    }
}
