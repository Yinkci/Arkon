<?php

namespace Tests\Support;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\Render\ComponentRenderer;
use App\Arkon\Components\Render\RenderContext;
use App\Arkon\Renderer\Element;
use Illuminate\Support\Facades\File;

/**
 * A hypothetical next hero version (current + 1) for tests: `text` is renamed
 * `body`, and the markup and stylesheet differ from the current version, so any
 * accidental use of it where the current version was published shows up in the output.
 */
final class HeroNext implements ComponentRenderer
{
    public const VERSION = 5;

    public function render(RenderContext $ctx): Element
    {
        return Element::h(
            'section',
            ['class' => 'ak-hero4'],
            Element::h($ctx->props['headingLevel'], ['class' => 'ak-hero4__title', ...$ctx->editable('heading')], $ctx->props['heading']),
            Element::h('div', ['class' => 'ak-hero4__body', ...$ctx->editable('body')], $ctx->props['body']),
        );
    }

    /** A registry with the real components plus this hero version (and its migration), built in `$directory`. */
    public static function registry(string $directory): ComponentRegistry
    {
        $from = self::VERSION - 1;
        File::copyDirectory(resource_path('arkon/components'), $directory);
        $next = json_decode(File::get("{$directory}/hero/v{$from}.json"), true);
        $next['version'] = self::VERSION;
        $next['props']['body'] = $next['props']['text'];
        unset($next['props']['text'], $next['defaultProps']['text'], $next['inlineFields']['text']);
        $next['defaultProps']['body'] = '';
        $next['inlineFields']['body'] = ['kind' => 'multiline'];
        File::put("{$directory}/hero/v".self::VERSION.'.json', json_encode($next));
        File::put("{$directory}/hero/v".self::VERSION.'.css', '.ak-hero4{display:block;padding:3rem}.ak-hero4__title{font-size:3rem}');

        return new ComponentRegistry($directory, [...ComponentRegistry::RENDERERS, 'hero@'.self::VERSION => self::class], [
            ...ComponentRegistry::migrations(),
            "hero@{$from}" => function (array $props) {
                $props['body'] = $props['text'] ?? '';
                unset($props['text']);

                return $props;
            },
        ]);
    }
}
