<?php

namespace Tests\Unit;

use App\Arkon\Ai\ProposalSchema;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\Factories;
use App\Arkon\Components\PatternLibrary;
use App\Arkon\Renderer\BackgroundImages;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\Widgets;
use App\Arkon\Style\StyleSheet;
use App\Arkon\Support\Json;
use Tests\TestCase;

class ReferenceBuilderTest extends TestCase
{
    public function test_every_pattern_expands_into_valid_editable_blocks_with_unique_ids(): void
    {
        $library = app(PatternLibrary::class);
        foreach ($library->all() as $pattern) {
            $doc = $library->document([$pattern['id']]);
            $this->assertSame([], app(DocumentValidator::class)->validate($doc), $pattern['id']);
            $other = $library->document([$pattern['id']]);
            $this->assertSame([], array_intersect(array_keys($doc['nodes']), array_keys($other['nodes'])));
        }
    }

    public function test_widgets_are_conditional_and_editor_output_does_not_run_them(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $renderer = app(PageRenderer::class);
        $public = $renderer->render($doc, 'production', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], []);
        $this->assertStringContainsString(Widgets::scriptTag(), $public['html']);
        $this->assertStringContainsString('aria-label="Featured services"', $public['body']);
        $this->assertStringNotContainsString('data-ak-', $public['html']);
        $this->assertSame(1, $public['report']['scripts']);
        $editor = $renderer->render($doc, 'editor', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], []);
        $this->assertStringNotContainsString(Widgets::scriptTag(), $editor['html']);
        $plain = $renderer->render(Factories::pageDocument([Factories::heroNode()]), 'production', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], []);
        $this->assertStringNotContainsString(Widgets::scriptTag(), $plain['html']);
        $this->assertSame('sha384-'.base64_encode(hash('sha384', file_get_contents(public_path(Widgets::PATH)), true)), Widgets::INTEGRITY);
    }

    public function test_old_renderer_keeps_its_widget_runtime_and_reproduces_identically(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $renderer = app(PageRenderer::class);
        $old = $renderer->render($doc, 'production', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], [], rendererVersion: 'arkon-php-4');
        $this->assertStringContainsString(Widgets::scriptTag('components-4'), $old['html']);
        $this->assertStringNotContainsString(Widgets::scriptTag(), $old['html']);
        $this->assertSame($old['html'], $renderer->reproduce($doc, $old['inputs'])['html']);
        $this->assertSame('https://site.test/_arkon/components-4.js', Widgets::scriptSrc($old['html'], 'https://site.test'));
    }

    public function test_nested_sticky_headers_cannot_leak_internal_editor_attributes(): void
    {
        $outer = ['id' => 'outer001', 'type' => 'group', 'version' => 5, 'props' => new \stdClass, 'children' => ['header01']];
        $doc = Factories::pageDocument([$outer]);
        $doc['nodes']['header01'] = ['id' => 'header01', 'type' => 'group', 'version' => 5, 'props' => ['element' => 'header', 'style' => ['root' => ['base' => ['position' => 'sticky', 'top' => '0px']]]], 'children' => ['text0001']];
        $doc['nodes']['text0001'] = ['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Nested header', 'element' => 'h1']];
        $doc = Json::decode(Json::encode($doc));
        $renderer = app(PageRenderer::class);
        $old = $renderer->render($doc, 'production', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], [], rendererVersion: 'arkon-php-4');
        $new = $renderer->render($doc, 'production', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], []);
        $this->assertStringContainsString('data-ak-sticky-class', $old['body']);
        $this->assertStringNotContainsString('data-ak-', $new['body']);
        $this->assertSame($old['css'], $new['css']);
        $this->assertSame($old['html'], $renderer->reproduce($doc, $old['inputs'])['html']);
        $editor = $renderer->render($doc, 'editor', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], []);
        $this->assertStringContainsString('data-ak-id', $editor['body']);
    }

    public function test_slider_descendants_cannot_include_another_slider_or_an_instance(): void
    {
        $doc = app(PatternLibrary::class)->document(['agency-hero']);
        $slide = collect($doc['nodes'])->firstWhere('type', 'slide')['id'];
        $nested = app(PatternLibrary::class)->nodes('agency-hero');
        foreach ($nested as $node) {
            $doc['nodes'][$node['id']] = $node;
        }
        $doc['nodes'][$slide]['children'][] = $nested[0]['id'];
        $issues = app(DocumentValidator::class)->validate($doc);
        $this->assertContains('Slides cannot contain nested sliders or reusable component instances.', array_column($issues, 'message'));
    }

    public function test_schema_advertises_supported_features_and_stays_within_cli_budget(): void
    {
        $schema = app(ProposalSchema::class)->schema([]);
        foreach (['slider', 'slide', 'icon', 'logo', 'back-to-top'] as $type) {
            $this->assertArrayHasKey('update_'.$type, $schema['$defs']);
        }
        $this->assertLessThan(30000, strlen(Json::encode($schema)));
        $form = $schema['$defs']['block_form']['properties']['props']['properties']['form'];
        $this->assertSame('object', $form['anyOf'][0]['type']);
        $this->assertSame('string', $form['anyOf'][0]['properties']['id']['type']);
        $this->assertArrayNotHasKey('enum', $form['anyOf'][0]['properties']['id']);
    }

    public function test_gradient_reset_keeps_the_inherited_responsive_background_and_focus_style(): void
    {
        $sheet = new StyleSheet(fn ($id, $screen) => '/media/'.$screen.'.webp', responsiveBackgrounds: true);
        $sheet->classFor(['base' => ['backgroundImage' => ['assetId' => 'managed'], 'backgroundGradient' => '90deg #102030ff #10203000', 'hoverColor' => '#ffffff'], 'mobile' => ['backgroundGradient' => 'none']]);
        $css = $sheet->css();
        $this->assertStringContainsString('linear-gradient(90deg', $css);
        $this->assertStringContainsString('/media/base.webp', $css);
        $this->assertStringContainsString('/media/mobile.webp', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $mobile = substr($css, strpos($css, '/media/mobile.webp') - 50, 100);
        $this->assertStringNotContainsString('linear-gradient', $mobile);
    }

    public function test_background_candidates_and_local_fonts_are_conditional(): void
    {
        $media = ['width' => 1920, 'url' => '/original.png', 'variants' => [['width' => 960, 'url' => '/small.webp'], ['width' => 1600, 'url' => '/large.webp']]];
        $this->assertSame('/small.webp', BackgroundImages::url($media, 'mobile'));
        $this->assertSame('/large.webp', BackgroundImages::url($media, 'base'));
        $doc = app(PatternLibrary::class)->document(['agency-services']);
        $render = fn ($doc) => app(PageRenderer::class)->render($doc, 'production', ['title' => 'Demo', 'path' => '/'], ['name' => 'Demo'], []);
        $this->assertStringNotContainsString('@font-face', $render($doc)['html']);
        foreach ($doc['nodes'] as &$node) {
            if ($node['type'] === 'text') {
                $props = Json::toArray($node['props']);
                $props['style']['root']['base']['fontFamily'] = 'inter';
                $node['props'] = $props;
                break;
            }
        }
        unset($node);
        $html = $render($doc)['html'];
        $this->assertStringContainsString('InterVariable-latin.woff2', $html);
        $this->assertStringNotContainsString('fonts.googleapis', $html);
    }
}
