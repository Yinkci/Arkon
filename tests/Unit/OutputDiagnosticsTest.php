<?php

namespace Tests\Unit;

use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\OutputDiagnostics;
use PHPUnit\Framework\TestCase;

final class OutputDiagnosticsTest extends TestCase
{
    public function test_measures_rendered_elements_depth_children_and_utf8_bytes(): void
    {
        $tree = Element::h('main', [], Element::h('section', [], Element::h('h1', [], 'Title'), Element::h('p', [], 'Text')));
        $r = OutputDiagnostics::inspect($tree, 'é', '.a{}', 'Description');
        $this->assertSame(['bodyElements' => 4, 'bodyDepth' => 3, 'maxChildren' => 2, 'htmlBytes' => 2, 'cssBytes' => 4, 'h1Count' => 1], $r['metrics']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_warns_about_output_not_schema_nodes_and_deduplicates_warnings(): void
    {
        $tree = Element::h('main', [], Element::h('h1', [], 'First'), Element::h('h3', [], 'Skipped'), Element::h('h1', [], 'Second'), Element::h('a', ['href' => '#'], 'One'), Element::h('a', ['href' => '#'], 'Two'));
        $r = OutputDiagnostics::inspect($tree, str_repeat('a', 150001), str_repeat('b', 30001), '');
        $this->assertSame(2, $r['metrics']['h1Count']);
        $this->assertEqualsCanonicalizing(['heading-gap', 'placeholder-link', 'htmlBytes', 'cssBytes', 'h1-count', 'description'], array_column($r['warnings'], 'code'));
    }

    public function test_large_rendered_trees_trigger_advisories_without_mutation(): void
    {
        $tree = Element::h('main', [], array_fill(0, 801, Element::h('p', [], 'Content')));
        $r = OutputDiagnostics::inspect($tree, '', '', 'Description');
        $this->assertSame(802, $r['metrics']['bodyElements']);
        $this->assertContains('bodyElements', array_column($r['warnings'], 'code'));
        $this->assertContains('maxChildren', array_column($r['warnings'], 'code'));
        $this->assertCount(801, $tree->children);
    }
}
