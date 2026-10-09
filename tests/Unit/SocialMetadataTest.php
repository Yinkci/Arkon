<?php

namespace Tests\Unit;

use App\Arkon\Components\Factories;
use App\Arkon\Renderer\PageRenderer;
use Tests\TestCase;

final class SocialMetadataTest extends TestCase
{
    public function test_new_metadata_uses_escaped_canonical_inputs_and_old_heads_are_preserved(): void
    {
        $doc = Factories::pageDocument([Factories::heroNode()]);
        $doc['seo'] = ['title' => 'Custom "title"', 'description' => '<Our company>'];
        $renderer = app(PageRenderer::class);
        $args = [$doc, 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'A & B', 'origin' => 'https://site.test'], []];
        $old = $renderer->render(...[...$args, false, false, 'arkon-php-4']);
        $new = $renderer->render(...$args);
        $this->assertStringNotContainsString('og:title', $old['html']);
        $this->assertStringContainsString('<meta property="og:title" content="Custom &quot;title&quot;">', $new['html']);
        $this->assertStringContainsString('<meta property="og:url" content="https://site.test/">', $new['html']);
        $this->assertStringContainsString('<meta property="og:description" content="&lt;Our company&gt;">', $new['html']);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $new['html']);
        $this->assertSame($old['body'], $new['body']);
        $this->assertSame($old['css'], $new['css']);
        $this->assertSame($old['html'], $renderer->reproduce($doc, $old['inputs'])['html']);
        $this->assertSame($new['html'], $renderer->reproduce($doc, $new['inputs'])['html']);
    }
}
