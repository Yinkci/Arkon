<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;
use Tests\Support\FakeStagedRunner;

/**
 * Staged website generation: a small plan, then the shared layout and each page as their own small
 * runs side by side, with targeted context, per-stage progress, failures that name their stage, Resume
 * that reuses finished stages, an overall ceiling and cancellation that stops every running stage.
 */
final class WebsiteStagesTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['arkon.ai.website_stages' => true, 'arkon.ai.per_user_per_minute' => 50]);
    }

    private function helper(array $f): object
    {
        $c = app(AiConnections::class);
        $p = $c->create($f['siteId'], $f['ctx']->userId, 'helper', 'test', 'claude-code');
        $c->heartbeat($p['id'], FakeClaudeRunner::ready());

        return $c->resolve($p['token'], 'helper');
    }

    private function ask(array $f, string $prompt, bool $layout = false): string
    {
        return app(WebsiteProposalService::class)->request($f['ctx'], ['prompt' => $prompt, 'requestKey' => self::key(), 'includeLayout' => $layout])['id'];
    }

    private function execute(object $helper, FakeStagedRunner $runner): string
    {
        $claim = app(ProposalService::class)->claimNext($helper);
        $this->assertNotNull($claim);

        return app(ProposalService::class)->execute($claim, $runner);
    }

    private static function plan(array $pages, bool $layout = false, bool $form = false): array
    {
        return ['summary' => 'Planned change', 'pages' => array_map(fn ($p) => ['pageId' => $p[2] ?? null, 'title' => $p[0], 'path' => $p[1], 'purpose' => $p[0].' page', 'sections' => ['Hero', 'Details'], 'form' => false, 'generatedImage' => false], $pages),
            'layout' => $layout, 'form' => $form, 'branding' => false, 'notes' => ['Add your team photos.']];
    }

    private static function page(string $heading): array
    {
        return ['summary' => $heading, 'seo' => ['title' => $heading.' | Test Site', 'description' => 'About '.$heading.' at Test Site.'],
            'proposal' => ['summary' => $heading, 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'add', 'parent' => 'page', 'index' => null, 'ref' => null, 'block' => ['type' => 'hero', 'props' => ['heading' => $heading, 'text' => 'Text for '.$heading]]]]]];
    }

    private static function layout(): array
    {
        $add = fn ($type, $props, $parent = 'page', $ref = null) => ['action' => 'add', 'parent' => $parent, 'index' => null, 'ref' => $ref, 'block' => ['type' => $type, 'props' => $props]];
        $p = fn ($changes) => ['summary' => 'Layout', 'notes' => [], 'tokenChanges' => [], 'changes' => $changes];

        return ['summary' => 'Shared layout', 'header' => $p([$add('group', ['element' => 'header'], 'page', 'h'), $add('text', ['text' => 'Test Site', 'element' => 'p'], 'new:h')]),
            'footer' => $p([$add('group', ['element' => 'footer'], 'page', 'f'), $add('text', ['text' => 'Test Site footer', 'element' => 'p'], 'new:f')]),
            'menu' => null, 'formChanges' => [], 'tokenChanges' => []];
    }

    public function test_a_small_about_page_request_is_two_small_runs_with_targeted_context(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $id = $this->ask($f, 'Create about us page. This will have basic about us page, hero should be image');
        $runner = new FakeStagedRunner(['Planning' => self::plan([['About us', '/about']]), 'Page About us' => self::page('About us')]);
        $this->assertSame('proposed', $this->execute($helper, $runner));
        $this->assertSame(['Planning the website', 'Page About us'], $runner->labels(), 'no layout run is needed when nothing shared changes');

        [$plan, $page] = $runner->requests;
        $this->assertStringNotContainsString('"blocks"', $plan->prompt, 'the plan sees page outlines, not block trees');
        $this->assertStringNotContainsString($f['heroId'], $page->prompt, 'the page run does not carry other pages\' blocks');
        $single = app(WebsiteProposalService::class)->modelRequest(Json::decode(DB::table('ai_proposals')->where('id', $id)->value('website_snapshot')), 'x');
        $this->assertLessThan(strlen($single['instructions']) / 4, strlen($plan->instructions), 'the plan has no block catalogue');
        $this->assertSame(config('arkon.ai.stage_timeouts.plan'), $plan->timeout);
        $this->assertSame(config('arkon.ai.stage_timeouts.section'), $page->timeout, 'a new page is built in section chunks with the section limit');

        $result = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('website_result'));
        $this->assertSame(['/about'], array_column($result['pages'], 'path'));
        $this->assertContains('Add your team photos.', $result['notes']);
        $progress = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('progress'));
        $this->assertSame(['done', 'done', 'done'], array_column($progress['stages'], 'status'));
        $diagnostics = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('diagnostics'));
        $this->assertSame(['staged', 'proposed'], [$diagnostics['mode'], $diagnostics['outcome']]);
    }

    public function test_a_longer_new_page_is_built_in_short_section_chunks_that_merge_in_order(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $id = $this->ask($f, 'Create an about us page with our story, values and a call to action.');
        $plan = self::plan([['About us', '/about']]);
        $plan['pages'][0]['sections'] = ['Hero', 'Our story', 'Our values', 'Call to action'];
        $chunk = fn (string $heading) => ['summary' => $heading, 'proposal' => ['summary' => $heading, 'notes' => [], 'tokenChanges' => [], 'changes' => [
            ['action' => 'add', 'parent' => 'page', 'index' => null, 'ref' => 'part', 'block' => ['type' => 'section', 'props' => []]],
            ['action' => 'add', 'parent' => 'new:part', 'index' => null, 'ref' => null, 'block' => ['type' => 'text', 'props' => ['text' => $heading, 'element' => 'h2']]],
        ]]];
        $runner = new FakeStagedRunner(['Planning' => $plan, 'Page About us (1/2)' => $chunk('Our story'), 'Page About us (2/2)' => $chunk('Call to action')]);
        $this->assertSame('proposed', $this->execute($helper, $runner));
        $this->assertSame(['Planning the website', 'Page About us (1/2)', 'Page About us (2/2)'], $runner->labels());
        $this->assertStringContainsString('Our values', $runner->requests[2]->prompt, 'a chunk knows the sections other steps build');
        $this->assertStringContainsString('use no h1 heading', $runner->requests[2]->instructions);
        $result = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('website_result'));
        $doc = $result['pages'][0]['document'];
        $texts = [];
        foreach ($doc['nodes'][$doc['root']]['children'] as $sectionId) {
            foreach ($doc['nodes'][$sectionId]['children'] ?? [] as $child) {
                $texts[] = $doc['nodes'][$child]['props']['text'];
            }
        }
        $this->assertSame(['Our story', 'Call to action'], $texts, 'both chunks, in order, their refs kept apart');
        $this->assertSame('About us', $result['pages'][0]['document']['seo']['title'], 'SEO comes from the plan');
    }

    public function test_a_new_page_joins_the_existing_navigation_without_a_layout_run(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $service = app(WebsiteProposalService::class);
        $first = $this->ask($f, 'Build a homepage with a header and footer.', layout: true);
        $runner = new FakeStagedRunner(['Planning' => self::plan([['Home', '/', $f['pageId']]], layout: true), 'Shared' => self::layout(), 'Page Home' => self::page('Welcome')]);
        $this->assertSame('proposed', $this->execute($helper, $runner));
        $this->assertContains('Shared header, footer and navigation', $runner->labels());
        $service->apply($f['ctx'], $first);

        $second = $this->ask($f, 'Create an about us page.', layout: true);
        $runner = new FakeStagedRunner(['Planning' => self::plan([['About us', '/about']]), 'Page About us' => self::page('About us')]);
        $this->assertSame('proposed', $this->execute($helper, $runner), Json::encode($service->status($f['ctx'], $second)));
        $this->assertSame(['Planning the website', 'Page About us'], $runner->labels());
        $applied = $service->apply($f['ctx'], $second);
        $menu = Json::decode(DB::table('site_menus')->value('draft'));
        $about = collect($applied['pages'])->firstWhere('path', '/about')['id'];
        $this->assertContains($about, array_column($menu['items'], 'pageId'), 'the new page is in the navigation');
        $this->assertSame(2, DB::table('pages')->count());
    }

    public function test_a_large_website_runs_layout_and_pages_side_by_side(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $id = $this->ask($f, 'Create a complete dental clinic website: Home, About, Services, Dentists, Blog and Contact.', layout: true);
        $pages = [['Home', '/', $f['pageId']], ['About', '/about'], ['Services', '/services'], ['Dentists', '/dentists'], ['Blog', '/blog-info'], ['Contact', '/contact']];
        $rules = ['Planning' => self::plan($pages, layout: true), 'Shared' => self::layout()];
        foreach ($pages as $p) {
            $rules['Page '.$p[0]] = self::page($p[0]);
        }
        $runner = new FakeStagedRunner($rules, polls: 5);
        $this->assertSame('proposed', $this->execute($helper, $runner));
        $this->assertCount(8, $runner->requests, 'plan, layout and six pages');
        $this->assertSame(3, $runner->maxRunning, 'three stages at a time');
        $result = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('website_result'));
        $this->assertCount(6, $result['pages']);
        $this->assertTrue($result['shared']['header']['changed']);
        foreach ($runner->requests as $r) {
            $this->assertLessThanOrEqual(240, $r->timeout);
        }
    }

    public function test_a_failed_page_names_its_stage_and_resume_generates_only_what_is_missing(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $service = app(WebsiteProposalService::class);
        $id = $this->ask($f, 'Create About, Services and Contact pages.');
        $pages = [['About', '/about'], ['Services', '/services'], ['Contact', '/contact']];
        $runner = new FakeStagedRunner(['Planning' => self::plan($pages), 'Page About' => self::page('About'), 'Page Services' => new AiException('EXECUTION_TIMEOUT', 'Codex did not finish Page Services within 240 seconds.'), 'Page Contact' => self::page('Contact')]);
        $this->assertSame('failed', $this->execute($helper, $runner));
        $view = $service->status($f['ctx'], $id);
        $this->assertStringContainsString('Page Services', $view['error']);
        $this->assertTrue($view['resumable']);
        $this->assertGreaterThanOrEqual(2, $view['cachedStages'], 'the plan and finished pages are kept');
        $this->assertContains('failed', array_column($view['progress']['stages'], 'status'));
        $this->assertSame('failed', $view['diagnostics']['outcome']);
        $this->assertSame(0, DB::table('pages')->where('path', '/about')->count(), 'nothing was applied');

        $this->assertSame('queued', $service->resume($f['ctx'], $id)['status']);
        $this->assertSame('queued', $service->resume($f['ctx'], $id)['status'], 'a repeated click changes nothing');
        $again = new FakeStagedRunner(['Page Services' => self::page('Services')]);
        $this->assertSame('proposed', $this->execute($helper, $again));
        $this->assertSame(['Page Services'], $again->labels(), 'only the failed page is generated again');
        $result = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('website_result'));
        $this->assertSame(['/about', '/services', '/contact'], array_column($result['pages'], 'path'));
        $this->assertSame(1, DB::table('ai_proposals')->count(), 'the same request, no duplicate');
    }

    public function test_a_step_that_gets_no_reply_is_retried_once_and_other_failures_never(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $id = $this->ask($f, 'Create an About page.');
        $calls = 0;
        $runner = new FakeStagedRunner(['Planning' => self::plan([['About', '/about']]), 'Page About' => function () use (&$calls) {
            return ++$calls === 1 ? new AiException('EXECUTION_TIMEOUT', 'Codex did not finish Page About within 90 seconds (the model gave no reply for 90 seconds).') : self::page('About');
        }]);
        $this->assertSame('proposed', $this->execute($helper, $runner));
        $this->assertSame(['Planning the website', 'Page About', 'Page About'], $runner->labels(), 'only the stalled step ran again');
        $progress = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('progress'));
        $this->assertSame(1, collect($progress['stages'])->firstWhere('key', 'page:0:0')['retries']);

        $this->ask($f, 'Create a Team page.');
        $invalid = new FakeStagedRunner(['Planning' => self::plan([['Team', '/team']]), 'Page Team' => new AiException('PROVIDER_LIMIT_REACHED', 'Your Codex usage limit has been reached.')]);
        $this->assertSame('failed', $this->execute($helper, $invalid));
        $this->assertSame(['Planning the website', 'Page Team'], $invalid->labels(), 'not a transient failure: no retry');
    }

    public function test_an_invalid_page_is_named_redone_alone_and_never_reused(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $service = app(WebsiteProposalService::class);
        $bad = ['summary' => 'Bad', 'seo' => ['title' => 'B', 'description' => 'B page.'], 'proposal' => ['summary' => 'Bad', 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'add', 'parent' => 'page', 'index' => null, 'ref' => null, 'block' => ['type' => 'no-such-block', 'props' => []]]]]];
        $id = $this->ask($f, 'Create About and Team pages.');
        $runner = new FakeStagedRunner(['Planning' => self::plan([['About', '/about'], ['Team', '/team']]), 'Page About' => self::page('About'), 'Page Team' => $bad]);
        $this->assertSame('failed', $this->execute($helper, $runner));
        $view = $service->status($f['ctx'], $id);
        $this->assertStringContainsString('Page Team did not pass the builder checks', $view['error']);
        $cache = Json::decode(DB::table('ai_proposals')->where('id', $id)->value('stage_cache'));
        $this->assertArrayHasKey('page:0:0', $cache);
        $this->assertArrayNotHasKey('page:1:0', $cache, 'an invalid page is not kept for Resume');
        $service->resume($f['ctx'], $id);
        $again = new FakeStagedRunner(['Page Team' => self::page('Team')]);
        $this->assertSame('proposed', $this->execute($helper, $again));
        $this->assertSame(['Page Team'], $again->labels());

        // With one repair allowed, only the invalid page runs again, told what to correct.
        $id = app(WebsiteProposalService::class)->request($f['ctx'], ['prompt' => 'Create Blog and Press pages.', 'requestKey' => self::key(), 'includeLayout' => false, 'allowRepair' => true])['id'];
        $calls = 0;
        $runner = new FakeStagedRunner(['Planning' => self::plan([['Blog', '/blog-home'], ['Press', '/press']]), 'Page Blog' => self::page('Blog'), 'Page Press' => function () use (&$calls, $bad) {
            return ++$calls === 1 ? $bad : self::page('Press');
        }]);
        $this->assertSame('proposed', $this->execute($helper, $runner));
        $this->assertSame(['Planning the website', 'Page Blog', 'Page Press', 'Page Press (repair)'], $runner->labels());
        $this->assertStringContainsString('no-such-block', end($runner->requests)->prompt, 'the repair is told the exact issue');
    }

    public function test_the_overall_ceiling_and_cancellation_stop_every_running_stage(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $service = app(WebsiteProposalService::class);
        $id = $this->ask($f, 'Create About, Services and Contact pages.');
        $pages = [['About', '/about'], ['Services', '/services'], ['Contact', '/contact']];
        // Cancelled by the user while the pages run.
        $runner = new FakeStagedRunner(['Planning' => function () use ($service, $f, $id, $pages) {
            $service->discard($f['ctx'], $id);

            return self::plan($pages);
        }, 'Page' => self::page('x')], polls: 50);
        $this->assertSame('lost', $this->execute($helper, $runner));
        $this->assertSame('discarded', DB::table('ai_proposals')->where('id', $id)->value('status'));
        $this->assertSame(0, $runner->running, 'no stage keeps running');

        config(['arkon.ai.website_ceiling_seconds' => 0]);
        $id = $this->ask($f, 'Create an About page.');
        $slow = new FakeStagedRunner(['Planning' => self::plan([['About', '/about']]), 'Page' => self::page('About')], polls: 1000);
        $this->assertSame('failed', $this->execute($helper, $slow));
        $this->assertSame('EXECUTION_TIMEOUT', DB::table('ai_proposals')->where('id', $id)->value('error_code'));
        $this->assertStringContainsString('overall', DB::table('ai_proposals')->where('id', $id)->value('error_message'));
        $this->assertSame(0, $slow->running);
    }
}
