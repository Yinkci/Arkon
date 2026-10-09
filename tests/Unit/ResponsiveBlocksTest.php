<?php

namespace Tests\Unit;

use App\Arkon\Ai\AiException;
use App\Arkon\Ai\ProposalCompiler;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\PatternLibrary;
use App\Arkon\Components\Render\ImageSizes;
use App\Arkon\Components\Render\RenderContext;
use App\Arkon\Components\Render\ResponsiveOptions;
use App\Arkon\Support\Json;
use Tests\TestCase;

class ResponsiveBlocksTest extends TestCase
{
    public function test_unset_options_inherit_and_mobile_can_reset_to_tablet(): void
    {
        $props = ['size' => 'large', 'variant' => 'primary', 'responsive' => ['tablet' => ['size' => 'small', 'variant' => 'secondary'], 'mobile' => ['size' => 'inherit', 'variant' => 'text']]];
        $attrs = ResponsiveOptions::attributes($props, ['size', 'variant']);
        $this->assertSame('small', $attrs['data-mobile-size']);
        $this->assertSame('text', $attrs['data-mobile-variant']);
        $this->assertSame([], ResponsiveOptions::attributes(['size' => 'large'], ['size']));
    }

    public function test_new_presets_validate_and_unknown_screen_values_are_rejected(): void
    {
        foreach (['button', 'section', 'form'] as $type) {
            $definition = app(ComponentRegistry::class)->current($type);
            $fields = $definition->props->fields()['responsive']['properties']['tablet']['properties'];
            $key = array_key_first($fields);
            $props = $definition->defaultProps;
            $props['responsive']['tablet'][$key] = 'not-a-preset';
            [, $issues] = $definition->props->parse(json_decode(json_encode($props)));
            $this->assertNotEmpty($issues);
        }
    }

    public function test_column_image_estimates_follow_each_screen_and_explicit_widths(): void
    {
        $shares = ImageSizes::childShares(['style' => ['root' => ['base' => ['columns' => '1fr 2fr'], 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]]], ['base' => 1, 'tablet' => 1, 'mobile' => 1], 2, 0);
        $this->assertEquals(1 / 3, $shares['base']);
        $this->assertEquals(.5, $shares['tablet']);
        $this->assertEquals(1, $shares['mobile']);
        $sizes = ImageSizes::attribute($shares, []);
        $this->assertStringContainsString('(max-width: 899px) 50vw', $sizes);
        $this->assertStringContainsString('(max-width: 599px) 100vw', $sizes);
        $this->assertStringContainsString('300px', ImageSizes::attribute($shares, ['media' => ['mobile' => ['width' => '300px']]]));
        $nested = ImageSizes::childShares([], $shares, 2, 1);
        $this->assertEquals(.25, $nested['tablet']);
    }

    public function test_ai_button_presets_use_the_component_rules_and_keep_base_values(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $id = collect($doc['nodes'])->firstWhere('type', 'button')['id'];
        $reply = ['summary' => 'Responsive button', 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'update', 'change' => ['id' => $id, 'type' => 'button', 'props' => ['responsive' => [['screen' => 'tablet', 'property' => 'size', 'value' => 'small']]]]]]];
        $compiler = app(ProposalCompiler::class);
        $result = $compiler->compile($doc, $reply, [], 20);
        $props = Json::toArray($result['document']['nodes'][$id]['props']);
        $this->assertSame('small', $props['responsive']['tablet']['size']);
        $this->assertSame($doc['nodes'][$id]['props']['size'], $props['size']);
        $reply['changes'][0]['change']['props']['responsive'][0]['property'] = 'arrows';
        $this->expectException(AiException::class);
        $compiler->compile($doc, $reply, [], 20);
    }

    public function test_empty_form_still_renders_a_placeholder(): void
    {
        $registry = app(ComponentRegistry::class);
        $definition = $registry->current('form');
        $ctx = new RenderContext(['id' => 'testform01'], $definition->props->parseValid($definition->defaultProps), [], 'editor', 0, fn ($id) => null);
        $element = $registry->renderer('form', $definition->version)->render($ctx);
        $this->assertNotEmpty($element->children);
    }
}
