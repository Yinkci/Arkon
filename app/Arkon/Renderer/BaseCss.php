<?php

namespace App\Arkon\Renderer;

/**
 * Site-wide base styles. `CSS` is the renderer arkon-php-1 stylesheet with fixed
 * values (kept byte for byte for reproduction). `CSS_V2` (arkon-php-2) reads the
 * site's design tokens: the older variables become aliases of token variables,
 * whose values PageRenderer emits from the site's published token set.
 */
final class BaseCss
{
    public const CSS_V2 = <<<'CSS'

:root{--ak-color-text:var(--ak-t-color-text);--ak-color-muted:var(--ak-t-color-muted);--ak-color-bg:var(--ak-t-color-background);--ak-color-primary:var(--ak-t-color-primary);
--ak-font-body:var(--ak-t-font-body);
--ak-text-lg:clamp(1.0625rem,1rem + .3vw,1.25rem);--ak-text-display:clamp(2.25rem,1.6rem + 3vw,4rem);
--ak-space-4:var(--ak-t-space-md);--ak-space-8:var(--ak-t-space-lg);--ak-space-section:clamp(3rem,2rem + 5vw,7rem);--ak-gutter:clamp(1rem,.5rem + 2.5vw,2.5rem);
--ak-container:var(--ak-t-container-default);--ak-radius-lg:var(--ak-t-radius-lg)}
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:var(--ak-font-body);color:var(--ak-color-text);background:var(--ak-color-bg);line-height:1.6}
h1,h2,h3,h4{font-family:var(--ak-t-font-heading)}
img{max-width:100%}
CSS;

    public const CSS = <<<'CSS'

:root{--ak-color-text:#111827;--ak-color-muted:#4b5563;--ak-color-bg:#ffffff;--ak-color-primary:#4f46e5;
--ak-font-body:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
--ak-text-lg:clamp(1.0625rem,1rem + .3vw,1.25rem);--ak-text-display:clamp(2.25rem,1.6rem + 3vw,4rem);
--ak-space-4:1rem;--ak-space-8:2rem;--ak-space-section:clamp(3rem,2rem + 5vw,7rem);--ak-gutter:clamp(1rem,.5rem + 2.5vw,2.5rem);
--ak-container:72rem;--ak-radius-lg:1rem}
*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:var(--ak-font-body);color:var(--ak-color-text);background:var(--ak-color-bg);line-height:1.6}
img{max-width:100%}
CSS;

    public static function minify(string $css): string
    {
        $css = (string) preg_replace('#/\*[\s\S]*?\*/#', '', $css);
        $css = (string) preg_replace('/\s*\n\s*/', '', $css);
        $css = (string) preg_replace('/\s*([{};:,])\s*/', '$1', $css);
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }
}
