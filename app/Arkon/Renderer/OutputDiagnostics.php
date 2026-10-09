<?php

namespace App\Arkon\Renderer;

/** Advisory measurements of actual rendered body IR, including expanded reusable components. */
final class OutputDiagnostics
{
    // Review thresholds, not publication blockers. Baseline fixtures are well below them.
    // Uncompressed byte budgets make regressions independent of server compression.
    public const BUDGETS = ['bodyElements' => 800, 'bodyDepth' => 12, 'maxChildren' => 60, 'htmlBytes' => 150000, 'cssBytes' => 30000];

    public static function inspect(Element $tree, string $html, string $css, string $description): array
    {
        $metrics = ['bodyElements' => 0, 'bodyDepth' => 0, 'maxChildren' => 0, 'htmlBytes' => strlen($html), 'cssBytes' => strlen($css), 'h1Count' => 0];
        $warnings = [];
        $lastHeading = 0;
        $walk = function (Element|TextNode $node, int $depth) use (&$walk, &$metrics, &$warnings, &$lastHeading): void {
            if ($node instanceof TextNode) {
                return;
            }
            $metrics['bodyElements']++;
            $metrics['bodyDepth'] = max($metrics['bodyDepth'], $depth);
            $metrics['maxChildren'] = max($metrics['maxChildren'], count($node->children));
            if (preg_match('/^h([1-6])$/D', $node->tag, $match)) {
                $level = (int) $match[1];
                $metrics['h1Count'] += $level === 1 ? 1 : 0;
                if ($lastHeading && $level > $lastHeading + 1) {
                    $warnings['heading-gap'] = ['code' => 'heading-gap', 'message' => 'Heading levels skip a step. Choose heading semantics separately from their visual size.'];
                }
                $lastHeading = $level;
            }
            if ($node->tag === 'a' && ($node->attrs['href'] ?? '') === '#') {
                $warnings['placeholder-link'] = ['code' => 'placeholder-link', 'message' => 'A link still points to #. Set its destination before launch.'];
            }
            foreach ($node->children as $child) {
                $walk($child, $depth + 1);
            }
        };
        $walk($tree, 1);
        foreach (self::BUDGETS as $name => $limit) {
            if ($metrics[$name] > $limit) {
                $warnings[$name] = ['code' => $name, 'message' => "Rendered {$name} is {$metrics[$name]}, above the review budget of {$limit}. Review repeated styles and layout complexity."];
            }
        }
        if ($metrics['h1Count'] !== 1) {
            $warnings['h1-count'] = ['code' => 'h1-count', 'message' => "This page has {$metrics['h1Count']} main headings. Review whether one clear h1 describes its main content."];
        }
        if (trim($description) === '') {
            $warnings['description'] = ['code' => 'description', 'message' => 'Add a search description in Page settings.'];
        }

        return ['metrics' => $metrics, 'budgets' => self::BUDGETS, 'warnings' => array_values($warnings)];
    }
}
