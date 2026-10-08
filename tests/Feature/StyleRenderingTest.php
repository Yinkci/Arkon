<?php

namespace Tests\Feature;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Media\MediaStorage;
use App\Arkon\Media\MediaVariants;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * The shared styling model as published: validated values become deduplicated
 * classes with desktop-first media queries, generated only from the allowlist;
 * the canvas, preview and live page render the same CSS; background images and
 * responsive image variants follow the media privacy rules; the likely LCP image is
 * fetched early and everything else lazily, with intrinsic sizes (no layout shift).
 */
class StyleRenderingTest extends DatabaseTestCase
{
    private const HOST = 'styles.test';

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], self::HOST);
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    private function version(): int
    {
        return (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version');
    }

    private function draft(): array
    {
        return Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'));
    }

    private function save(array $ops): array
    {
        return $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops))]);
    }

    private function publish(): array
    {
        return $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => $this->version(), 'idempotencyKey' => self::key()]);
    }

    private function live(): string
    {
        return (string) $this->pages()->livePage($this->f['siteId'], '/')?->html;
    }

    private function heroStyle(array $style): array
    {
        return $this->save([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['style' => $style]]]);
    }

    /** A photo-like PNG (gradient and grain), so WebP variants are much smaller than the original. */
    private static function photo(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(7);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $n = mt_rand(-24, 24);
                $c = fn (int $v) => max(0, min(255, $v + $n));
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $c(intdiv($x * 255, $width)), $c(intdiv($y * 255, $height)), $c(128)));
            }
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_responsive_styles_render_as_shared_classes_with_desktop_first_media_queries(): void
    {
        $this->heroStyle([
            'root' => ['base' => ['direction' => 'row', 'gap' => '@space.xl', 'backgroundColor' => '#F5F5F4'], 'tablet' => ['gap' => '@space.md'], 'mobile' => ['direction' => 'column']],
            'heading' => ['base' => ['fontSize' => '@fontSize.display', 'color' => '@color.primary'], 'mobile' => ['fontSize' => '2rem']],
        ]);
        $this->publish();
        $html = $this->live();

        preg_match('#<section class="ak-hero3 (ak-s[0-9a-f]{10})">#', $html, $root);
        preg_match('#<h1 class="ak-hero3__heading (ak-s[0-9a-f]{10})">#', $html, $heading);
        $this->assertNotEmpty($root, $html);
        $this->assertNotEmpty($heading);
        // Base rules first, then each breakpoint once; values come from the registry (tokens as variables, colours lowercased).
        $this->assertStringContainsString(".{$root[1]}{flex-direction:row;gap:var(--ak-t-space-xl);background-color:#f5f5f4;--ak-basis:0%}", $html);
        $this->assertStringContainsString(".{$heading[1]}{font-size:var(--ak-t-fontSize-display);color:var(--ak-t-color-primary)}", $html);
        $this->assertMatchesRegularExpression('#@media \(max-width:899px\)\{\.'.$root[1].'\{gap:var\(--ak-t-space-md\)\}\}@media \(max-width:599px\)\{\.'.$root[1].'\{flex-direction:column;--ak-basis:auto\}\.'.$heading[1].'\{font-size:2rem\}\}#', $html);
        // Only the tokens used are defined, with their published (here default) values.
        $this->assertStringContainsString('--ak-t-space-xl:4rem', $html);
        $this->assertStringContainsString('--ak-t-fontSize-display:3.5rem', $html);
        $this->assertStringNotContainsString('--ak-t-shadow', $html);
        // Clean markup: no inline styles, no editor attributes, no scripts, no unused component CSS.
        $this->assertStringNotContainsString(' style=', $html);
        $this->assertStringNotContainsString('data-ak-', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('.ak-section', $html);
        $this->assertStringNotContainsString('.ak-btn2', $html);

        // The canvas renders the same CSS (plus editor annotations in the markup only).
        $canvas = $this->pages()->renderCanvas($this->f['ctx'], $this->f['pageId'], $this->draft(), new MediaSigner);
        $this->assertStringContainsString("<style>{$canvas['css']}</style>", $html);
        $this->assertStringContainsString('data-ak-id=', $canvas['body']);
        $this->assertSame($canvas['css'], $this->stylesOf($this->pages()->renderPreview($this->f['ctx'], $this->f['pageId'])));
    }

    public function test_hero_v3_parts_take_a_set_width_instead_of_their_equal_share(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'garden.png');
        $this->save([['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => [
            'image' => ['assetId' => $asset['id'], 'alt' => 'A garden'],
            'style' => [
                'root' => ['base' => ['direction' => 'row', 'width' => '960px']],
                'media' => ['base' => ['width' => '320px', 'height' => '500px', 'objectFit' => 'cover'], 'mobile' => ['width' => 'auto']],
                'content' => ['base' => ['width' => '40%']],
            ],
        ]]]);
        $this->publish();
        $html = $this->live();

        preg_match('#<img class="ak-hero3__media (ak-s[0-9a-f]{10})"#', $html, $media);
        preg_match('#<div class="ak-hero3__content (ak-s[0-9a-f]{10})">#', $html, $content);
        preg_match('#<section class="ak-hero3 (ak-s[0-9a-f]{10})">#', $html, $root);
        // The image's own width is its size side by side; "auto" on phones gives the equal share back.
        $this->assertStringContainsString(".{$media[1]}{width:320px;height:500px;object-fit:cover;flex:0 1 auto}", $html);
        $this->assertMatchesRegularExpression('#@media \(max-width:599px\)\{[^@]*\.'.$media[1].'\{width:auto;flex:1 1 var\(--ak-basis\)\}#', $html);
        $this->assertStringContainsString(".{$content[1]}{width:40%;flex:0 1 auto}", $html);
        // The section's width is the section's own: no flex rule (it is not a shared item).
        $this->assertStringContainsString(".{$root[1]}{flex-direction:row;width:960px;--ak-basis:0%}", $html);
        $this->assertStringNotContainsString('data-ak-part', $html);

        // In the editor each part is marked, so it can be selected and highlighted.
        $canvas = $this->pages()->renderCanvas($this->f['ctx'], $this->f['pageId'], $this->draft(), new MediaSigner);
        foreach (['root', 'content', 'heading', 'text', 'media'] as $part) {
            $this->assertStringContainsString("data-ak-part=\"{$part}\"", $canvas['body']);
        }
    }

    public function test_hero_v2_keeps_its_equal_shares_whatever_width_is_set(): void
    {
        $doc = Json::decode('{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":3,"props":{},"children":["hero0001"]},"hero0001":{"id":"hero0001","type":"hero","version":2,"props":{"heading":"Kept","headingLevel":"h1","text":"","image":null,"style":{"content":{"base":{"width":"40%"}}}},"children":[]}},"seo":{}}');
        $out = app(PageRenderer::class)->render($doc, 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test Site'], [], pinned: true);
        preg_match('#<div class="ak-hero2__content (ak-s[0-9a-f]{10})">#', $out['html'], $content);
        $this->assertStringContainsString(".{$content[1]}{width:40%}", $out['html']);
        $this->assertStringNotContainsString('flex:0 1 auto', $out['html']);
    }

    private function stylesOf(string $html): string
    {
        preg_match('#<style>(.*)</style>#s', $html, $m);

        return $m[1] ?? '';
    }

    public function test_identical_styles_share_one_rule(): void
    {
        $root = $this->draft()['root'];
        $style = ['root' => ['base' => ['textAlign' => 'center', 'color' => '@color.muted']]];
        $this->save([
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'One', 'style' => $style]]]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 2, 'nodes' => [['id' => 'text0002', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Two', 'style' => $style]]]],
        ]);
        $this->publish();
        $html = $this->live();
        preg_match_all('#<p class="ak-text2 ak-flow (ak-s[0-9a-f]{10})">#', $html, $m);
        $this->assertCount(2, $m[1]);
        $this->assertSame($m[1][0], $m[1][1]);
        $this->assertSame(1, substr_count($html, ".{$m[1][0]}{"));
    }

    public function test_unsafe_or_out_of_range_values_are_refused_by_the_server(): void
    {
        $before = $this->version();
        foreach ([
            ['root' => ['base' => ['backgroundColor' => 'red;background:url(//evil.example/x)']]],
            ['root' => ['base' => ['minHeight' => 'expression(alert(1))']]],
            ['media' => ['base' => ['height' => '9999px']]],
            ['heading' => ['base' => ['height' => '10px']]],
            ['root' => ['mobile' => ['backgroundImage' => ['assetId' => '01890a5d-ac96-774b-bcce-b302099a8057']]]],
            ['root' => ['base' => ['position' => 'fixed']]],
        ] as $style) {
            try {
                $this->heroStyle($style);
                $this->fail('Expected '.json_encode($style).' to be refused');
            } catch (ValidationException) {
            }
        }
        $this->assertSame($before, $this->version(), 'nothing was saved');
    }

    public function test_background_images_follow_the_media_rules(): void
    {
        $other = $this->siteFixture();
        $foreign = app(MediaService::class)->upload($other['ctx'], self::png(), 'theirs.png');
        $this->assertThrows(fn () => $this->heroStyle(['root' => ['base' => ['backgroundImage' => ['assetId' => $foreign['id']]]]]), ValidationException::class, 'does not exist');

        $own = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'texture.png');
        $key = substr($own['url'], 7);
        $this->heroStyle(['root' => ['base' => ['backgroundImage' => ['assetId' => $own['id']], 'backgroundOverlay' => '#00000080', 'color' => '#ffffff']]]);
        $this->assertNull(app(MediaService::class)->resolveAccess($key, self::HOST, null, null, new MediaSigner), 'private in a draft');
        // The canvas gets a signed URL.
        $canvas = $this->pages()->renderCanvas($this->f['ctx'], $this->f['pageId'], $this->draft(), new MediaSigner);
        $this->assertMatchesRegularExpression('#background-image:linear-gradient\(\#00000080,\#00000080\),url\("/media/'.preg_quote($key, '#').'\?t=[^"]+"\)#', $canvas['css']);

        $this->publish();
        $this->assertStringContainsString('background-image:linear-gradient(#00000080,#00000080),url("/media/'.$key.'");background-size:cover;background-position:center', $this->live());
        $this->assertSame('public', app(MediaService::class)->resolveAccess($key, self::HOST, null, null, new MediaSigner)['access'] ?? null);
    }

    public function test_images_get_private_webp_variants_srcset_sizes_and_reserved_space(): void
    {
        $this->assertTrue(MediaVariants::supported(), 'GD with WebP is required');
        $original = self::photo(1300, 650);
        $asset = app(MediaService::class)->upload($this->f['ctx'], $original, 'garden.png');
        $variants = DB::table('media_variants')->where('asset_id', $asset['id'])->orderBy('width')->get();
        $this->assertSame([320, 640, 960, 1280, 1300], $variants->pluck('width')->map(fn ($w) => (int) $w)->all());
        $this->assertSame([160, 320, 480, 640, 650], $variants->pluck('height')->map(fn ($h) => (int) $h)->all());
        foreach ($variants as $variant) {
            $this->assertLessThan(strlen($original), (int) $variant->bytes);
        }
        // The original is kept byte for byte.
        $this->assertSame($original, app(MediaStorage::class)->read(substr($asset['url'], 7)));

        $root = $this->draft()['root'];
        $this->save([
            ['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['image' => ['assetId' => $asset['id'], 'alt' => 'A garden']]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [['id' => 'imag0001', 'type' => 'image', 'version' => 4, 'props' => ['image' => ['assetId' => $asset['id'], 'alt' => 'Below the fold'], 'style' => ['media' => ['base' => ['height' => '500px', 'objectFit' => 'cover', 'objectPosition' => 'top']]]]]]],
        ]);
        $variantKey = MediaVariants::key($asset['id'], 640);
        $this->assertNull(app(MediaService::class)->resolveAccess($variantKey, self::HOST, null, null, new MediaSigner), 'variants are private while the original is');
        $signer = new MediaSigner;
        $canvas = $this->pages()->renderCanvas($this->f['ctx'], $this->f['pageId'], $this->draft(), $signer);
        preg_match('#/media/'.preg_quote($variantKey, '#').'\?t=([^ "]+)#', $canvas['body'], $m);
        $this->assertNotEmpty($m, 'the canvas gets signed variant URLs');
        $this->assertSame('private', app(MediaService::class)->resolveAccess($variantKey, null, null, $m[1], $signer)['access'] ?? null);

        $this->publish();
        $html = $this->live();
        $srcset = "/media/{$asset['id']}-w320.webp 320w, /media/{$asset['id']}-w640.webp 640w, /media/{$asset['id']}-w960.webp 960w, /media/{$asset['id']}-w1280.webp 1280w, /media/{$asset['id']}-w1300.webp 1300w";
        // The hero image is the likely LCP element: eager, high priority, half the content width on wide screens.
        $this->assertStringContainsString('<img class="ak-hero3__media" src="'.$asset['url'].'" srcset="'.$srcset.'" sizes="(max-width: 899px) 100vw, 576px" alt="A garden" width="1300" height="650" decoding="async" fetchpriority="high">', $html);
        // The image block further down: lazy, full content width, with its fit and crop as a class.
        $this->assertMatchesRegularExpression('#<img class="ak-img2__media (ak-s[0-9a-f]{10})" src="'.preg_quote($asset['url'], '#').'" srcset="'.preg_quote($srcset, '#').'" sizes="\(max-width: 899px\) 100vw, 1152px" alt="Below the fold" width="1300" height="650" decoding="async" loading="lazy">#', $html);
        preg_match('#ak-img2__media (ak-s[0-9a-f]{10})#', $html, $c);
        $this->assertStringContainsString(".{$c[1]}{height:500px;object-fit:cover;object-position:top}", $html);
        $this->assertSame('public', app(MediaService::class)->resolveAccess($variantKey, self::HOST, null, null, new MediaSigner)['access'] ?? null);
        $this->assertSame('image/webp', app(MediaService::class)->resolveAccess($variantKey, self::HOST, null, null, new MediaSigner)['mime']);
        // Not public on another host.
        $this->assertNull(app(MediaService::class)->resolveAccess($variantKey, 'elsewhere.test', null, null, new MediaSigner));
    }

    public function test_only_the_first_image_of_the_first_block_is_fetched_early(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'a.png');
        $root = $this->draft()['root'];
        $image = fn (string $id, string $loading = 'auto') => ['id' => $id, 'type' => 'image', 'version' => 4, 'props' => ['image' => ['assetId' => $asset['id'], 'alt' => $id], 'loading' => $loading]];
        // The hero has no image; the first section holds columns with two images: only the first is eager.
        $this->save([
            ['op' => 'removeNode', 'nodeId' => $this->f['heroId']],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 0, 'nodes' => [
                ['id' => 'sect0001', 'type' => 'section', 'version' => 2, 'props' => new \stdClass, 'children' => ['cols0001']],
                ['id' => 'cols0001', 'type' => 'columns', 'version' => 3, 'props' => new \stdClass, 'children' => ['colu0001', 'colu0002']],
                ['id' => 'colu0001', 'type' => 'column', 'version' => 3, 'props' => new \stdClass, 'children' => ['img00001']],
                ['id' => 'colu0002', 'type' => 'column', 'version' => 3, 'props' => new \stdClass, 'children' => ['img00002']],
                $image('img00001'), $image('img00002'),
            ]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [$image('img00003')]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 2, 'nodes' => [$image('img00004', 'eager')]],
        ]);
        $this->publish();
        $html = $this->live();
        $tag = function (string $alt) use ($html): string {
            preg_match('#<img [^>]*alt="'.$alt.'"[^>]*>#', $html, $m);

            return $m[0] ?? '';
        };
        $this->assertStringContainsString('fetchpriority="high"', $tag('img00001'));
        $this->assertStringNotContainsString('loading=', $tag('img00001'));
        $this->assertStringContainsString('loading="lazy"', $tag('img00002'));
        $this->assertStringContainsString('loading="lazy"', $tag('img00003'));
        $this->assertStringContainsString('fetchpriority="high"', $tag('img00004'), 'an explicit "load early" is respected');
        foreach (['img00001', 'img00002', 'img00003', 'img00004'] as $alt) {
            $this->assertStringContainsString('width="1" height="1"', $tag($alt), 'intrinsic size reserves the space');
        }
    }

    public function test_an_image_right_after_a_leading_heading_is_still_fetched_early(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'a.png');
        $root = $this->draft()['root'];
        $image = fn (string $id) => ['id' => $id, 'type' => 'image', 'version' => 4, 'props' => ['image' => ['assetId' => $asset['id'], 'alt' => $id]]];
        $this->save([
            ['op' => 'removeNode', 'nodeId' => $this->f['heroId']],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Our work', 'element' => 'h1']]]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [$image('img00001')]],
            ['op' => 'insertNode', 'parentId' => $root, 'index' => 2, 'nodes' => [$image('img00002')]],
        ]);
        $this->publish();
        $html = $this->live();
        $this->assertMatchesRegularExpression('#<img [^>]*alt="img00001"[^>]*fetchpriority="high">#', $html);
        $this->assertMatchesRegularExpression('#<img [^>]*alt="img00002"[^>]*loading="lazy">#', $html);
    }

    public function test_sections_groups_and_column_proportions_render_without_extra_wrappers(): void
    {
        $root = $this->draft()['root'];
        $this->save([['op' => 'insertNode', 'parentId' => $root, 'index' => 1, 'nodes' => [
            ['id' => 'sect0001', 'type' => 'section', 'version' => 2, 'props' => ['contentWidth' => 'narrow', 'element' => 'footer', 'style' => ['root' => ['base' => ['backgroundColor' => '@color.surface']]]], 'children' => ['grup0001', 'cols0001']],
            ['id' => 'grup0001', 'type' => 'group', 'version' => 2, 'props' => ['style' => ['root' => ['base' => ['direction' => 'row', 'justify' => 'space-between'], 'mobile' => ['direction' => 'column']]]], 'children' => ['text0001', 'text0002']],
            ['id' => 'text0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Left']],
            ['id' => 'text0002', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Right']],
            ['id' => 'cols0001', 'type' => 'columns', 'version' => 3, 'props' => ['style' => ['root' => ['base' => ['columns' => '1fr 2fr'], 'mobile' => ['columns' => '1']]]], 'children' => ['colu0001', 'colu0002']],
            ['id' => 'colu0001', 'type' => 'column', 'version' => 3, 'props' => new \stdClass, 'children' => []],
            ['id' => 'colu0002', 'type' => 'column', 'version' => 3, 'props' => new \stdClass, 'children' => []],
        ]]]);
        $this->publish();
        $html = $this->live();
        $this->assertMatchesRegularExpression('#<footer class="ak-section ak-section--narrow ak-s[0-9a-f]{10}"><div class="ak-group ak-flow ak-s[0-9a-f]{10}"><p class="ak-text2 ak-flow">Left</p><p class="ak-text2 ak-flow">Right</p></div><div class="ak-cols ak-flow ak-cols--n2 (ak-s[0-9a-f]{10})"><div class="ak-col"></div><div class="ak-col"></div></div></footer>#', $html);
        preg_match('#ak-cols--n2 (ak-s[0-9a-f]{10})#', $html, $m);
        $this->assertStringContainsString(".{$m[1]}{grid-template-columns:minmax(0,1fr) minmax(0,2fr)}", $html);
        $this->assertMatchesRegularExpression('#@media \(max-width:599px\)\{[^@]*\.'.$m[1].'\{grid-template-columns:repeat\(1,minmax\(0,1fr\)\)\}#', $html);
    }

    public function test_publications_made_with_the_previous_renderer_reproduce_unchanged(): void
    {
        // Stored exactly as the previous renderer produced it (arkon-php-1: no tokens, no style classes).
        $doc = Json::decode('{"schemaVersion":1,"root":"root0001","nodes":{"root0001":{"id":"root0001","type":"page","version":2,"props":{},"children":["hero0001"]},"hero0001":{"id":"hero0001","type":"hero","version":1,"props":{"heading":"Old page","headingLevel":"h1","text":"","image":null}}},"seo":{}}');
        $inputs = ['renderer' => 'arkon-php-1', 'components' => ['hero@1', 'page@2'], 'page' => ['title' => 'Old', 'path' => '/old'], 'site' => ['name' => 'Test Site', 'lang' => 'en'], 'media' => new \stdClass];
        $out = app(PageRenderer::class)->reproduce($doc, Json::toArray(Json::decode(Json::encode($inputs))));
        $this->assertStringContainsString('<main><section class="ak-hero"><h1 class="ak-hero__heading">Old page</h1></section></main>', $out['html']);
        $this->assertStringStartsWith(':root{--ak-color-text:#111827;', $out['css']);
        $this->assertStringNotContainsString('--ak-t-', $out['html']);
        $this->assertArrayNotHasKey('tokens', $out['inputs']);
    }
}
