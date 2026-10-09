<?php

namespace Tests\Unit;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\PatternLibrary;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\Widgets;
use App\Arkon\Support\Json;
use Tests\TestCase;

class SliderOptionsTest extends TestCase
{
    public function test_controls_and_editor_are_versioned_and_use_existing_runtime(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $slider = collect($doc['nodes'])->firstWhere('type', 'slider')['id'];
        $this->assertSame(8, $doc['nodes'][$slider]['version']);
        foreach (['numbers', 'dots', 'bars', 'none'] as $pagination) {
            $doc['nodes'][$slider]['props'] = [...Json::entries($doc['nodes'][$slider]['props']), 'pagination' => $pagination, 'arrows' => false, 'autoplay' => true, 'controlsAlign' => 'center', 'controlsTone' => 'dark'];
            $this->assertSame([], app(DocumentValidator::class)->validate($doc));
            $render = app(PageRenderer::class)->render($doc, 'production', ['title' => 'Test', 'path' => '/'], ['name' => 'Test'], []);
            $this->assertStringContainsString('ak-slider--'.$pagination, $render['body']);
            $this->assertStringContainsString('data-autoplay="true"', $render['body']);
            $this->assertSame(3, substr_count($render['body'], 'data-slide-step='));
            $this->assertSame(2, substr_count($render['body'], 'data-slide-index='));
            $this->assertStringContainsString(Widgets::scriptTag(), $render['html']);
            $editor = app(PageRenderer::class)->render($doc, 'editor', ['title' => 'Test', 'path' => '/'], ['name' => 'Test'], []);
            $this->assertStringContainsString('data-editor-slider="true"', $editor['body']);
            $this->assertSame(2, substr_count($editor['body'], 'data-slide-index='));
            $this->assertStringContainsString('inert', $editor['body']);
            $this->assertStringNotContainsString(Widgets::scriptTag(), $editor['html']);
        }
    }

    public function test_old_slider_upgrades_keep_numbered_pagination_without_changing_source(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $id = collect($doc['nodes'])->firstWhere('type', 'slider')['id'];
        $doc['nodes'][$id]['version'] = 1;
        $doc['nodes'][$id]['props'] = ['label' => 'Old slider', 'autoplay' => false, 'interval' => '7000', 'style' => new \stdClass];
        $before = Json::encode($doc);
        $upgraded = app(ComponentRegistry::class)->migrateDocument($doc);
        $this->assertSame('numbers', Json::entries($upgraded['nodes'][$id]['props'])['pagination']);
        $this->assertSame(8, $upgraded['nodes'][$id]['version']);
        $this->assertSame($before, Json::encode($doc));
        $this->assertSame([], app(DocumentValidator::class)->validate($upgraded));
    }

    public function test_whole_second_intervals_and_legacy_hover_behavior(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $id = collect($doc['nodes'])->firstWhere('type', 'slider')['id'];
        foreach (['1000', '2000', '30000', '60000'] as $interval) {
            $doc['nodes'][$id]['props']['interval'] = $interval;
            $this->assertSame([], app(DocumentValidator::class)->validate($doc));
        }
        foreach (['0', '500', '61000', '1500'] as $interval) {
            $doc['nodes'][$id]['props']['interval'] = $interval;
            $this->assertNotSame([], app(DocumentValidator::class)->validate($doc));
        }
        $doc['nodes'][$id]['version'] = 2;
        unset($doc['nodes'][$id]['props']['responsive']);
        $doc['nodes'][$id]['props']['interval'] = '5000';
        unset($doc['nodes'][$id]['props']['pauseOnHover']);
        $upgraded = app(ComponentRegistry::class)->migrateDocument($doc);
        $this->assertTrue(Json::entries($upgraded['nodes'][$id]['props'])['pauseOnHover']);
        $this->assertSame('5000', Json::entries($upgraded['nodes'][$id]['props'])['interval']);
    }

    public function test_transition_options_and_legacy_presentation_are_preserved(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $id = collect($doc['nodes'])->firstWhere('type', 'slider')['id'];
        foreach (['slide', 'fade', 'none'] as $transition) {
            $doc['nodes'][$id]['props']['transition'] = $transition;
            $doc['nodes'][$id]['props']['transitionDuration'] = '700';
            $doc['nodes'][$id]['props']['showPauseControl'] = false;
            $this->assertSame([], app(DocumentValidator::class)->validate($doc));
            $render = app(PageRenderer::class)->render($doc, 'production', ['title' => 'Test', 'path' => '/'], ['name' => 'Test'], []);
            $this->assertStringContainsString('data-transition="'.$transition.'"', $render['body']);
            $this->assertStringContainsString('data-transition-duration="700"', $render['body']);
            $this->assertStringContainsString('ak-slider--quiet-playback', $render['body']);
        }
        $doc['nodes'][$id]['props']['transition'] = 'spin';
        $this->assertNotSame([], app(DocumentValidator::class)->validate($doc));
        $doc['nodes'][$id]['version'] = 3;
        unset($doc['nodes'][$id]['props']['responsive']);
        unset($doc['nodes'][$id]['props']['transition'], $doc['nodes'][$id]['props']['transitionDuration'], $doc['nodes'][$id]['props']['showPauseControl']);
        $upgraded = app(ComponentRegistry::class)->migrateDocument($doc);
        $props = Json::entries($upgraded['nodes'][$id]['props']);
        $this->assertSame('none', $props['transition']);
        $this->assertTrue($props['showPauseControl']);
        $this->assertSame([], app(DocumentValidator::class)->validate($upgraded));
    }

    public function test_edge_arrows_are_independent_of_pagination_and_legacy_defaults(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $id = collect($doc['nodes'])->firstWhere('type', 'slider')['id'];
        $doc['nodes'][$id]['props'] = [...Json::entries($doc['nodes'][$id]['props']), 'arrowPlacement' => 'edges', 'pagination' => 'none', 'arrows' => true];
        $this->assertSame([], app(DocumentValidator::class)->validate($doc));
        foreach (['production', 'editor'] as $mode) {
            $render = app(PageRenderer::class)->render($doc, $mode, ['title' => 'Test', 'path' => '/'], ['name' => 'Test'], []);
            $this->assertStringContainsString('ak-slider--arrows-edges', $render['body']);
            $this->assertSame(3, substr_count($render['body'], 'data-slide-step='));
            $this->assertSame(2, substr_count($render['body'], 'data-slide-index='));
        }
        $doc['nodes'][$id]['props']['arrowPlacement'] = 'unsupported';
        $this->assertNotSame([], app(DocumentValidator::class)->validate($doc));
        $doc['nodes'][$id]['version'] = 4;
        unset($doc['nodes'][$id]['props']['responsive']);
        unset($doc['nodes'][$id]['props']['arrowPlacement']);
        $upgraded = app(ComponentRegistry::class)->migrateDocument($doc);
        $this->assertSame('grouped', Json::entries($upgraded['nodes'][$id]['props'])['arrowPlacement']);
        $this->assertSame([], app(DocumentValidator::class)->validate($upgraded));
    }

    public function test_arrow_style_slot_is_validated_and_rendered_on_both_buttons(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $id = collect($doc['nodes'])->firstWhere('type', 'slider')['id'];
        $doc['nodes'][$id]['props']['style'] = ['arrows' => ['base' => ['color' => '#ff0000', 'backgroundColor' => '#00000000', 'borderRadius' => '8px', 'hoverColor' => '#00ff00']]];
        $this->assertSame([], app(DocumentValidator::class)->validate($doc));
        foreach (['editor', 'production'] as $mode) {
            $render = app(PageRenderer::class)->render($doc, $mode, ['title' => 'Test', 'path' => '/'], ['name' => 'Test'], []);
            $this->assertMatchesRegularExpression('/ak-slider__arrow ak-s[0-9a-f]+/', $render['body']);
            $this->assertStringContainsString('color:#ff0000', $render['html']);
            $this->assertStringContainsString('background-color:#00000000', $render['html']);
        }
        $doc['nodes'][$id]['props']['style']['arrows']['base']['color'] = 'red;display:none';
        $this->assertNotSame([], app(DocumentValidator::class)->validate($doc));
        $doc['nodes'][$id]['props']['style'] = [];
        $doc['nodes'][$id]['props']['arrowButtonSize'] = '5';
        $this->assertNotSame([], app(DocumentValidator::class)->validate($doc));
    }
}
