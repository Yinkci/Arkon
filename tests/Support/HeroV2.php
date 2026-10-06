<?php

namespace Tests\Support;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\Render\ComponentRenderer;
use App\Arkon\Components\Render\HeroV1;
use App\Arkon\Components\Render\PageV1;
use App\Arkon\Components\Render\RenderContext;
use App\Arkon\Renderer\Element;
use Illuminate\Support\Facades\File;

/**
 * A hypothetical hero v2 for tests: `text` is renamed `body`, and the markup and
 * stylesheet differ from v1, so any accidental use of v2 where v1 was published
 * shows up in the output.
 */
final class HeroV2 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h(
            'section',
            ['class' => 'ak-hero2'],
            Element::h($ctx->props['headingLevel'], ['class' => 'ak-hero2__title', ...$ctx->editable('heading')], $ctx->props['heading']),
            Element::h('div', ['class' => 'ak-hero2__body', ...$ctx->editable('body')], $ctx->props['body']),
        );
    }

    /** A registry with hero v1 and v2 (plus the v1 → v2 migration), built in `$directory`. */
    public static function registry(string $directory): ComponentRegistry
    {
        File::copyDirectory(resource_path('arkon/components'), $directory);
        $v2 = json_decode(File::get("{$directory}/hero/v1.json"), true);
        $v2['version'] = 2;
        $v2['props']['body'] = $v2['props']['text'];
        unset($v2['props']['text'], $v2['defaultProps']['text'], $v2['inlineFields']['text']);
        $v2['defaultProps']['body'] = '';
        $v2['inlineFields']['body'] = ['kind' => 'multiline'];
        File::put("{$directory}/hero/v2.json", json_encode($v2));
        File::put("{$directory}/hero/v2.css", '.ak-hero2{display:block;padding:3rem}.ak-hero2__title{font-size:3rem}');

        return new ComponentRegistry($directory, ['page@1' => PageV1::class, 'hero@1' => HeroV1::class, 'hero@2' => self::class], [
            'hero@1' => function (array $props) {
                $props['body'] = $props['text'] ?? '';
                unset($props['text']);

                return $props;
            },
        ]);
    }
}
