<?php

namespace Tests\Feature;

use App\Arkon\Components\ColumnLayout;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Database\MigrationConfig;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\Motion;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Schema\Operations;
use App\Arkon\Support\Json;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Entrance animations on published pages: stored as validated design settings, rendered as CSS
 * (opacity and transform only) with a class per block; "when scrolled into view" pages load the
 * one trusted, versioned runtime under a script policy that allows exactly that file; ordinary
 * pages stay script-free; content shows without JavaScript and for reduced motion; the likely
 * LCP (priority image, first h1) is never animated; publications record the runtime and
 * reproduce byte for byte.
 */
class MotionRenderingTest extends DatabaseTestCase
{
    private const HOST = 'motion.test';

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

    private function root(): string
    {
        return Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'))['root'];
    }

    private function node(string $id, string $type, array $props, ?array $children = null): array
    {
        $definition = app(ComponentRegistry::class)->current($type);

        return ['id' => $id, 'type' => $type, 'version' => $definition->version, 'props' => [...$definition->defaultProps, ...$props], ...($children === null ? [] : ['children' => $children])];
    }

    private function save(array $ops): void
    {
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => $this->version(), 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops))]);
    }

    private function publish(): object
    {
        $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => $this->version(), 'idempotencyKey' => self::key()]);

        return $this->pages()->livePage($this->f['siteId'], '/');
    }

    private function publicGet(string $path)
    {
        return $this->get('http://'.self::HOST.$path);
    }

    /** A section below the hero, with the given root style. */
    private function addSection(string $id, array $root, string $text = 'Further down'): void
    {
        $this->save([['op' => 'insertNode', 'parentId' => $this->root(), 'index' => 1, 'nodes' => [
            $this->node($id, 'section', ['style' => ['root' => $root]], ["{$id}t"]),
            $this->node("{$id}t", 'text', ['text' => $text]),
        ]]]);
    }

    public function test_every_runtime_file_is_the_one_pinned_by_its_integrity(): void
    {
        // motion-1 and motion-2 stay exactly as published pages recorded them; new pages use motion-3.
        $this->assertSame('motion-3', Motion::RUNTIME);
        foreach (Motion::RUNTIMES as $name => $runtime) {
            $file = public_path(ltrim($runtime['src'], '/'));
            $this->assertFileExists($file);
            $this->assertSame($runtime['integrity'], 'sha384-'.base64_encode(hash_file('sha384', $file, true)), "{$name} is immutable: ship changes as a new version");
            $source = (string) file_get_contents($file);
            // One shared observer, disconnected when done; no polling, timers or animation loops.
            $this->assertSame(1, substr_count($source, 'new IntersectionObserver'), $name);
            $this->assertStringContainsString('io.disconnect()', $source);
            foreach (['setInterval', 'setTimeout', 'requestAnimationFrame', 'addEventListener(\'scroll', 'eval', 'innerHTML', 'fetch', 'XMLHttpRequest'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $name);
            }
            $this->assertLessThan(2560, strlen($source));
        }
        $this->assertSame('sha384-DSgoXTH8FQ277yBLQN9hUc69Tkp2cu0Or/RroCHoz+12tAEmk9GPpQ87nFtYH4tX', Motion::RUNTIMES['motion-1']['integrity'], 'motion-1 is never changed');
        $this->assertSame('sha384-R5QzvHCVFLp/KC8mNpj+VUbBqbghmvigBfJCqGT8odvH8zvHvdMu5TFRkRZA4RcK', Motion::RUNTIMES['motion-2']['integrity'], 'motion-2 is never changed');
        // motion-3 keeps focus listening for the whole visit (entrances on screen can be focused at any time).
        $this->assertStringContainsString("addEventListener('focusin', onFocus, true)", (string) file_get_contents(public_path('_arkon/motion-3.js')));
    }

    public function test_animated_pages_allow_only_the_runtime_and_pages_without_entrances_stay_script_free(): void
    {
        // No entrance: no script, script-src 'none'.
        $live = $this->publish();
        $this->assertDoesNotMatchRegularExpression('/<script(?! type="application\/ld\+json")/i', $live->html);
        $this->assertStringContainsString("script-src 'none'", $this->publicGet('/')->headers->get('Content-Security-Policy'));

        // On page load (motion-3): the entrance is CSS; the runtime only keeps focused content shown.
        $this->addSection('sect0001', ['base' => ['animation' => 'fade', 'animationTrigger' => 'load']]);
        $live = $this->publish();
        $this->assertMatchesRegularExpression('#<section class="ak-section ak-anim (ak-m[0-9a-f]{10})">#', $live->html);
        $this->assertSame(1, preg_match_all('/<script(?! type="application\/ld\+json")/i', $live->html));
        $this->assertStringContainsString(Motion::scriptTag('motion-3'), $live->html);
        $this->assertStringContainsString('script-src http://motion.test/_arkon/motion-3.js;', $this->publicGet('/')->headers->get('Content-Security-Policy'));
        $this->assertSame('motion-3', json_decode(DB::table('publications')->where('id', $live->publication_id)->value('render_inputs'), true)['motion']);

        // When scrolled into view: exactly the runtime, deferred, pinned by integrity, allowed by URL.
        $this->save([['op' => 'updateProps', 'nodeId' => 'sect0001', 'set' => ['style' => ['root' => ['base' => ['animation' => 'fade-up', 'animationTrigger' => 'view']]]]]]);
        $live = $this->publish();
        $this->assertSame(1, preg_match_all('/<script(?! type="application\/ld\+json")/i', $live->html));
        $this->assertStringContainsString(Motion::scriptTag('motion-3'), $live->html);
        $response = $this->publicGet('/')->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('script-src http://motion.test/_arkon/motion-3.js;', $csp);
        foreach (["'unsafe-inline' 'unsafe-eval'", "'unsafe-eval'", "script-src 'self'", '*'] as $never) {
            $this->assertStringNotContainsString($never, explode(';', explode('script-src', $csp)[1])[0]);
        }
        foreach (['data-ak-', '/build/', 'inertia', 'data-page', 'contenteditable', 'ak-replay'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $live->html);
        }

        // The member preview has the same semantics and the same narrow policy.
        $preview = $this->actingAs(User::findOrFail($this->f['ctx']->userId))->get('http://'.self::HOST.'/preview/'.$this->f['pageId'])->assertOk();
        $this->assertStringContainsString(Motion::scriptTag('motion-3'), $preview->getContent());
        $this->assertStringContainsString('script-src http://motion.test/_arkon/motion-3.js;', $preview->headers->get('Content-Security-Policy'));
    }

    public function test_content_shows_without_javascript_and_for_reduced_motion(): void
    {
        $this->addSection('sect0001', ['base' => ['animation' => 'fade-up', 'animationTrigger' => 'view'], 'mobile' => ['animation' => 'none']]);
        $html = $this->publish()->html;
        // Every entrance starts with the page in CSS; only the runtime holds a block back (.ak-wait), so
        // without it nothing waits for the viewport. Focus shows every animated block around it (CSS while
        // focused, at any point of any entrance; the runtime's .ak-shown for good); reduced motion and print
        // show the final state.
        $this->assertStringContainsString('.ak-anim.ak-wait{animation-name:none;opacity:0}.ak-anim.ak-shown{animation-name:none;opacity:1}.ak-anim:focus-within{opacity:1!important}', $html);
        $this->assertStringNotContainsString('ak-reveal-on', $html);
        $this->assertStringContainsString('@media (prefers-reduced-motion:reduce){html .ak-anim{animation:none;opacity:1}}', $html);
        $this->assertStringContainsString('@media print{html .ak-anim{animation:none;opacity:1}}', $html);
        // Only opacity and transform are animated.
        preg_match_all('#@keyframes [a-z-]+\{from\{([^}]*)\}\}#', $html, $frames);
        $this->assertCount(6, $frames[1]);
        foreach ($frames[1] as $declarations) {
            foreach (explode(';', $declarations) as $declaration) {
                $this->assertContains(explode(':', $declaration)[0], ['opacity', 'transform']);
            }
        }
        // Phones: no animation (a mobile override), the desktop columns etc. untouched.
        preg_match('#ak-anim ak-reveal (ak-m[0-9a-f]{10})#', $html, $m);
        $this->assertStringContainsString("@media (max-width:599px){.{$m[1]}{animation-name:none}}", $html);
    }

    public function test_the_likely_lcp_is_never_animated_and_the_editor_says_why(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'photo.png');
        $fade = ['base' => ['animation' => 'fade-up']];
        $this->save([
            // The hero (h1) and its section's image block: both would be the LCP.
            ['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['style' => ['root' => $fade]]],
            ['op' => 'insertNode', 'parentId' => $this->root(), 'index' => 1, 'nodes' => [
                $this->node('sect0001', 'section', ['style' => ['root' => $fade]], ['imag0001', 'text0001']),
                $this->node('imag0001', 'image', ['image' => ['assetId' => $asset['id'], 'alt' => 'Photo'], 'style' => ['root' => $fade]]),
                $this->node('text0001', 'text', ['text' => 'Caption-like text', 'style' => ['root' => $fade]]),
            ]],
            ['op' => 'insertNode', 'parentId' => $this->root(), 'index' => 2, 'nodes' => [$this->node('sect0002', 'section', ['style' => ['root' => ['base' => ['animation' => 'zoom', 'animationTrigger' => 'view']]]], [])]],
        ]);
        $canvas = $this->pages()->renderCanvas($this->f['ctx'], $this->f['pageId'], $this->pages()->editorState($this->f['ctx'], $this->f['pageId'])['draft']['document'], new MediaSigner);
        // Every protected block, with the content that makes it so (named in the editor before any effect is chosen).
        $this->assertEquals(['protected' => (object) [
            $this->f['heroId'] => ['reason' => 'heading', 'cause' => $this->f['heroId']],
            'imag0001' => ['reason' => 'image', 'cause' => 'imag0001'],
            'sect0001' => ['reason' => 'image', 'cause' => 'imag0001'],
            $this->root() => ['reason' => 'image', 'cause' => 'imag0001'],
        ]], $canvas['motion']);
        // The editor canvas shows the final state (replay only) and never loads the runtime.
        $this->assertStringContainsString('.ak-anim:not(.ak-replay){animation-name:none}', $canvas['css']);
        $this->assertStringNotContainsString('ak-reveal-on', $canvas['css']);

        $html = $this->publish()->html;
        $this->assertStringContainsString('<section class="ak-hero3">', $html, 'the hero with the h1 is not animated');
        $this->assertMatchesRegularExpression('#<figure class="ak-img2 ak-flow"><img class="ak-img2__media" src="[^"]+" (srcset="[^"]+" sizes="[^"]+" )?alt="Photo" width="1" height="1" decoding="async" fetchpriority="high">#', $html);
        $this->assertMatchesRegularExpression('#<p class="ak-text2 ak-flow ak-anim ak-m[0-9a-f]{10}">Caption-like text</p>#', $html, 'a neighbour of the LCP still animates');
        $this->assertMatchesRegularExpression('#<section class="ak-section ak-anim ak-reveal ak-m[0-9a-f]{10}"></section>#', $html);
    }

    public function test_publications_record_the_runtime_and_reproduce_and_older_output_is_unchanged(): void
    {
        // A page without animations renders exactly as before this milestone: no motion CSS, no input.
        $plain = $this->publish();
        $this->assertStringNotContainsString('ak-a-', $plain->html);
        $this->assertArrayNotHasKey('motion', json_decode(DB::table('publications')->where('id', $plain->publication_id)->value('render_inputs'), true));

        $this->addSection('sect0001', ['base' => ['animation' => 'fade-left', 'animationTrigger' => 'view', 'animationDelay' => '200ms', 'animationEasing' => 'ease-in-out']]);
        $animated = $this->publish();
        foreach ([$plain, $animated] as $live) {
            $result = $this->pages()->reproducePublication($this->f['siteId'], $live->publication_id);
            $this->assertSame('reproduced', $result['status'], (string) $result['reason']);
            $this->assertTrue($result['matches']);
        }

        // A recorded runtime this code no longer has is reported, never guessed.
        // (Owner role: publications are append-only for the app.)
        $owner = MigrationConfig::connect('test');
        try {
            $inputs = json_decode(DB::table('publications')->where('id', $animated->publication_id)->value('render_inputs'), true);
            DB::connection($owner)->table('publications')->where('id', $animated->publication_id)->update(['render_inputs' => Json::encode([...$inputs, 'motion' => 'motion-0'])]);
            $this->assertSame('unavailable', $this->pages()->reproducePublication($this->f['siteId'], $animated->publication_id)['status']);
            DB::connection($owner)->table('publications')->where('id', $animated->publication_id)->update(['render_inputs' => Json::encode(array_diff_key($inputs, ['motion' => 1]))]);
            $this->assertSame('unavailable', $this->pages()->reproducePublication($this->f['siteId'], $animated->publication_id)['status']);
        } finally {
            MigrationConfig::disconnect();
        }
    }

    public function test_publications_made_with_motion_1_still_reproduce_byte_for_byte(): void
    {
        $this->addSection('sect0001', ['base' => ['animation' => 'fade-up', 'animationTrigger' => 'view']]);
        $live = $this->publish();
        $publication = DB::table('publications')->where('id', $live->publication_id)->first();
        $inputs = json_decode($publication->render_inputs, true);
        $this->assertSame('motion-3', $inputs['motion']);
        // A publication made before motion-2 recorded motion-1: its HTML (CSS and script) is that runtime's.
        $old = app(PageRenderer::class)->reproduce(Json::decode($this->revisionDocument($publication->revision_id)), [...$inputs, 'motion' => 'motion-1']);
        $this->assertStringContainsString('<script src="/_arkon/motion-1.js" integrity="sha384-DSgoXTH8FQ277yBLQN9hUc69Tkp2cu0Or/RroCHoz+12tAEmk9GPpQ87nFtYH4tX" defer></script>', $old['html']);
        $this->assertStringContainsString('html:not(.ak-reveal-on) .ak-reveal,.ak-reveal.ak-static,.ak-reveal-on .ak-reveal:focus-within{animation-name:none}', $old['html']);
        $this->assertNotSame($publication->html, $old['html']);
        $owner = MigrationConfig::connect('test');
        try {
            DB::connection($owner)->table('publications')->where('id', $live->publication_id)->update(['html' => $old['html'], 'render_inputs' => Json::encode([...$inputs, 'motion' => 'motion-1'])]);
            $result = $this->pages()->reproducePublication($this->f['siteId'], $live->publication_id);
            $this->assertSame('reproduced', $result['status'], (string) $result['reason']);
            $this->assertTrue($result['matches'], 'a motion-1 publication reproduces with motion-1');
            // Served with motion-1's own script policy.
            $this->assertStringContainsString('script-src http://motion.test/_arkon/motion-1.js;', $this->publicGet('/')->headers->get('Content-Security-Policy'));
        } finally {
            MigrationConfig::disconnect();
        }
    }

    public function test_publications_made_with_motion_2_still_reproduce_byte_for_byte(): void
    {
        // motion-2 loaded its script only for "when scrolled into view": a load-only page had none.
        $this->addSection('sect0001', ['base' => ['animation' => 'fade', 'animationTrigger' => 'load', 'animationDelay' => '2000ms']]);
        $live = $this->publish();
        $publication = DB::table('publications')->where('id', $live->publication_id)->first();
        $inputs = json_decode($publication->render_inputs, true);
        $old = app(PageRenderer::class)->reproduce(Json::decode($this->revisionDocument($publication->revision_id)), [...$inputs, 'motion' => 'motion-2']);
        $this->assertDoesNotMatchRegularExpression('/<script(?! type="application\/ld\+json")/i', $old['html']);
        $this->assertStringContainsString('.ak-anim.ak-wait:focus-within,.ak-anim.ak-shown{animation-name:none;opacity:1}', $old['html']);
        $this->assertStringNotContainsString('.ak-anim:focus-within{opacity:1!important}', $old['html']);
        $owner = MigrationConfig::connect('test');
        try {
            DB::connection($owner)->table('publications')->where('id', $live->publication_id)->update(['html' => $old['html'], 'render_inputs' => Json::encode([...$inputs, 'motion' => 'motion-2'])]);
            $result = $this->pages()->reproducePublication($this->f['siteId'], $live->publication_id);
            $this->assertSame('reproduced', $result['status'], (string) $result['reason']);
            $this->assertTrue($result['matches'], 'a motion-2 publication reproduces with motion-2');
            // Served as it was: no script allowed.
            $this->assertStringContainsString("script-src 'none'", $this->publicGet('/')->headers->get('Content-Security-Policy'));
        } finally {
            MigrationConfig::disconnect();
        }
    }

    private function revisionDocument(string $revisionId): string
    {
        return (string) DB::table('page_revisions')->where('id', $revisionId)->value('document');
    }

    public function test_invalid_animation_settings_are_refused_on_save(): void
    {
        foreach ([
            ['animation' => 'spin'],
            ['animation' => 'fade', 'animationDuration' => '5s'],
            ['animation' => 'fade', 'animationDelay' => '3000ms'],
            ['animation' => 'fade', 'animationEasing' => 'cubic-bezier(0,0,1,1)'],
        ] as $base) {
            $this->assertThrows(fn () => $this->addSection(Operations::newNodeId(), ['base' => $base]), ValidationException::class);
        }
        $this->assertThrows(fn () => $this->addSection(Operations::newNodeId(), ['mobile' => ['animationTrigger' => 'view']]), ValidationException::class);
        $this->assertThrows(fn () => $this->save([['op' => 'insertNode', 'parentId' => $this->root(), 'index' => 0, 'nodes' => [
            ['id' => 'text0009', 'type' => 'text', 'version' => 2, 'props' => ['text' => 'x', 'style' => ['root' => ['base' => ['animation' => 'fade']]]]],
        ]]]), ValidationException::class); // released versions cannot animate
    }

    public function test_a_duplicated_column_copies_its_width_like_the_editor(): void
    {
        $style = ['root' => ['base' => ['columns' => '1fr 2fr 1fr', 'gap' => '@space.lg'], 'tablet' => ['columns' => '3'], 'mobile' => ['columns' => '1']]];
        $this->assertSame(
            ['style' => ['root' => ['base' => ['columns' => '1fr 2fr 2fr 1fr', 'gap' => '@space.lg'], 'tablet' => ['columns' => '4'], 'mobile' => ['columns' => '1']]], 'reset' => []],
            ColumnLayout::reconcile($style, 3, [0, 1, 1, 2]),
        );
        $this->assertSame(['style' => [], 'reset' => []], ColumnLayout::reconcile(['root' => ['base' => ['columns' => '3']]], 3, [0, 0, 1, 2]), 'an all-equal count follows the number of columns (the default)');
        // New empty columns: proportions can't be known, so that screen goes back to equal (reported); stacking stays.
        $this->assertSame(['style' => ['root' => ['mobile' => ['columns' => '1']]], 'reset' => ['base']], ColumnLayout::reconcile(['root' => ['base' => ['columns' => '1fr 2fr'], 'mobile' => ['columns' => '1']]], 2, [0, 1, null]));
        $this->assertSame(['style' => ['root' => ['base' => ['columns' => '2fr 1fr']]], 'reset' => []], ColumnLayout::reconcile(['root' => ['base' => ['columns' => '1fr 2fr']]], 2, [1, 0]), 'reordered columns keep their widths');
    }
}
