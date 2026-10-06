<?php

namespace Tests\Unit;

use App\Arkon\Components\Factories;
use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Renderer\Serializer;
use App\Arkon\Support\Json;
use InvalidArgumentException;
use Tests\TestCase;

/** Port of packages/renderer/test/render.test.ts. */
class RendererTest extends TestCase
{
    private const ASSET = '01890a5d-ac96-774b-bcce-b302099a8057';

    private function render(?array $document = null, string $mode = 'production', array $page = ['title' => 'Home', 'path' => '/'], bool $strict = false): array
    {
        $document ??= Factories::pageDocument([Factories::heroNode(['heading' => 'Turn conversations into sales', 'text' => 'Reply faster.', 'image' => ['assetId' => self::ASSET, 'alt' => 'A phone']])]);
        $media = [self::ASSET => ['id' => self::ASSET, 'url' => '/media/'.self::ASSET.'.png', 'width' => 1200, 'height' => 800, 'mime' => 'image/png']];

        // Through JSON, as documents always arrive.
        return app(PageRenderer::class)->render(Json::decode(Json::encode($document)), $mode, $page, ['name' => 'Demo'], $media, $strict);
    }

    public function test_production_output_is_semantic_and_minimal(): void
    {
        $out = $this->render();
        $this->assertSame(
            '<main><section class="ak-hero ak-hero--media">'
            .'<h1 class="ak-hero__heading">Turn conversations into sales</h1>'
            .'<p class="ak-hero__text">Reply faster.</p>'
            .'<img class="ak-hero__image" src="/media/01890a5d-ac96-774b-bcce-b302099a8057.png" alt="A phone" width="1200" height="800" decoding="async" fetchpriority="high">'
            .'</section></main>',
            $out['body'],
        );
        $this->assertSame(5, $out['report']['elements']);
    }

    public function test_production_output_contains_no_editor_attributes_scripts_or_assets(): void
    {
        $html = $this->render()['html'];
        $this->assertStringNotContainsString('data-ak-', $html);
        $this->assertDoesNotMatchRegularExpression('/<script/i', $html);
        $this->assertStringNotContainsString('contenteditable', $html);
        $this->assertStringNotContainsString('bridge', $html);
        $this->assertSame(0, $this->render()['report']['scripts']);
    }

    public function test_is_a_complete_document_with_head_metadata(): void
    {
        $out = $this->render();
        $this->assertStringStartsWith('<!doctype html><html lang="en"><head>', $out['html']);
        $this->assertStringContainsString('<title>Home · Demo</title>', $out['html']);
        $this->assertStringContainsString('.ak-hero{', $out['css']);
    }

    public function test_seo_fields_reach_the_head(): void
    {
        $doc = Factories::pageDocument([Factories::heroNode()]);
        $doc['seo'] = ['title' => '  Custom  ', 'description' => 'About "us"', 'noindex' => true];
        $html = $this->render($doc)['html'];
        $this->assertStringContainsString('<title>Custom</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="About &quot;us&quot;">', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
    }

    public function test_omits_an_empty_text_paragraph_and_lazy_loads_images_below_the_first_section(): void
    {
        $this->assertStringNotContainsString('<p', $this->render(Factories::pageDocument([Factories::heroNode(['heading' => 'Only heading', 'text' => ''])]))['body']);
        $body = $this->render(Factories::pageDocument([
            Factories::heroNode(['heading' => 'First']),
            Factories::heroNode(['heading' => 'Second', 'image' => ['assetId' => self::ASSET, 'alt' => 'x']]),
        ]))['body'];
        $this->assertStringContainsString('loading="lazy"', $body);
        $this->assertStringNotContainsString('fetchpriority', $body);
    }

    public function test_adds_node_annotations_and_inline_edit_markers_only_in_editor_mode(): void
    {
        $body = $this->render(mode: 'editor')['body'];
        $this->assertMatchesRegularExpression('/<main data-ak-id="[^"]+" data-ak-type="page">/', $body);
        $this->assertStringContainsString('data-ak-type="hero"', $body);
        $this->assertStringContainsString('data-ak-prop="heading"', $body);
        // The editor shows an empty text field so it can be typed into.
        $this->assertStringContainsString('data-ak-prop="text"', $this->render(Factories::pageDocument([Factories::heroNode(['text' => ''])]), 'editor')['body']);
    }

    public function test_escapes_user_content(): void
    {
        $doc = Factories::pageDocument([Factories::heroNode(['heading' => '<script>alert("x")</script>', 'text' => 'a & b'])]);
        $out = $this->render($doc, page: ['title' => '<b>', 'path' => '/']);
        $this->assertStringNotContainsString('<script>', $out['html']);
        $this->assertStringContainsString('&lt;script&gt;alert("x")&lt;/script&gt;', $out['body']);
        $this->assertStringContainsString('a &amp; b', $out['body']);
        $this->assertStringContainsString('<title>&lt;b&gt; · Demo</title>', $out['html']);
    }

    public function test_escapes_attribute_values(): void
    {
        $this->assertSame('<img alt="&quot;&gt;&lt;x" src="/a.png">', Serializer::serialize(Element::h('img', ['alt' => '"><x', 'src' => '/a.png'])));
    }

    public function test_refuses_dangerous_elements_event_handlers_and_urls(): void
    {
        $this->assertThrows(fn () => Element::h('script'), InvalidArgumentException::class);
        $this->assertThrows(fn () => Serializer::serialize(Element::h('a', ['onclick' => 'x'])), InvalidArgumentException::class);
        $this->assertThrows(fn () => Serializer::serialize(Element::h('a', ['href' => 'javascript:alert(1)'])), InvalidArgumentException::class);
        $this->assertThrows(fn () => Serializer::serialize(Element::h('a', ['href' => '//evil.example'])), InvalidArgumentException::class);
        $this->assertSame('<a href="/ok">x</a>', Serializer::serialize(Element::h('a', ['href' => '/ok'], 'x')));
    }

    public function test_refuses_invalid_documents_and_in_strict_mode_unpublishable_ones(): void
    {
        $this->assertThrows(fn () => app(PageRenderer::class)->render(['nope' => true], 'production', ['title' => 'x', 'path' => '/'], ['name' => 'x'], []), RenderException::class);
        $blank = Factories::pageDocument([Factories::heroNode(['heading' => ''])]);
        $this->assertThrows(fn () => $this->render($blank, strict: true), RenderException::class, 'not ready to publish');
        $this->assertNotEmpty($this->render($blank)['html']);
    }
}
