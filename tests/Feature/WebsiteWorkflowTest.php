<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\ProposalLedger;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Sites\WebsitePublishing;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;

final class WebsiteWorkflowTest extends DatabaseTestCase
{
    private function helper(array $f): object
    {
        $c = app(AiConnections::class);
        $p = $c->create($f['siteId'], $f['ctx']->userId, 'helper', 'test');
        $c->heartbeat($p['id'], FakeClaudeRunner::ready());

        return $c->resolve($p['token'], 'helper');
    }

    private function proposal(array $f, array $snapshot): array
    {
        $proposal = fn ($changes) => ['summary' => 'Editable blocks', 'notes' => [], 'tokenChanges' => [], 'changes' => $changes];
        $add = fn ($type, $props, $parent = 'page', $ref = null) => ['action' => 'add', 'parent' => $parent, 'index' => null, 'ref' => $ref, 'block' => ['type' => $type, 'props' => $props]];
        $pages = [];
        foreach (['Home' => '/', 'About' => '/about', 'Services' => '/services', 'Contact' => '/contact'] as $title => $path) {
            $changes = $path === '/' ? [['action' => 'remove', 'id' => $f['heroId']]] : [];
            $changes[] = $add('hero', ['heading' => $title === 'Home' ? 'Gardens made for everyday life' : $title, 'text' => 'Thoughtful landscaping for your outdoor space.']);
            if ($path === '/contact') {
                $changes[] = $add('form', ['form' => ['id' => $snapshot['form']['id']]]);
            }
            $pages[] = ['pageId' => $path === '/' ? $f['pageId'] : null, 'title' => $title, 'path' => $path, 'seo' => ['title' => $title.' | Garden Studio', 'description' => 'Explore Garden Studio landscaping services and make an enquiry.'], 'proposal' => $proposal($changes)];
        }
        $header = [$add('group', ['element' => 'header'], 'page', 'header'), $add('text', ['text' => 'Garden Studio', 'element' => 'p'], 'new:header'), $add('group', ['element' => 'nav'], 'new:header', 'nav')];
        foreach ($pages as $p) {
            $header[] = $add('button', ['label' => $p['title'], 'href' => $p['path'], 'variant' => 'text'], 'new:nav');
        }

        return ['summary' => 'Four-page landscaping website', 'pages' => $pages, 'header' => $proposal($header), 'footer' => $proposal([$add('group', ['element' => 'footer'], 'page', 'footer'), $add('text', ['text' => 'Garden Studio — plan your next outdoor project.', 'element' => 'p'], 'new:footer')]), 'form' => ['name' => 'Contact enquiry', 'submitLabel' => 'Send enquiry', 'successMessage' => 'Thank you. Your enquiry was received.', 'fields' => [['id' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'options' => []], ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'options' => []], ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'options' => []]]], 'tokenChanges' => []];
    }

    private function runProposal(array $f): array
    {
        $helper = $this->helper($f);
        $s = app(WebsiteProposalService::class);
        $snapshot = $s->snapshot($f['ctx']);
        $request = $s->request($f['ctx'], ['prompt' => 'Create a landscaping website', 'requestKey' => self::key()]);
        $snapshot = Json::decode(DB::table('ai_proposals')->where('id', $request['id'])->value('website_snapshot'));
        $claim = app(ProposalService::class)->claimNext($helper);
        $runner = new FakeClaudeRunner([$this->proposal($f, $snapshot)]);
        $this->assertSame('proposed', app(ProposalService::class)->execute($claim, $runner), Json::encode($s->status($f['ctx'], $request['id'])));
        $this->assertArrayHasKey('$defs', $runner->requests[0]->schema);

        return [$s, $request['id']];
    }

    public function test_whole_website_is_reviewed_applied_once_and_published_atomically(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'website.test');
        [$s,$id] = $this->runProposal($f);
        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame(0, DB::table('site_forms')->count());
        $this->assertSame(0, DB::table('live_pages')->count());
        $preview = $s->preview($f['ctx'], $id, 3, app(MediaSigner::class));
        $this->assertStringContainsString('Garden Studio', $preview);
        $this->assertStringContainsString('<form', $preview);
        $applied = $s->apply($f['ctx'], $id);
        $this->assertCount(4, $applied['pages']);
        $this->assertTrue($s->apply($f['ctx'], $id)['replayed']);
        $this->assertSame(4, DB::table('pages')->count());
        $this->assertSame(0, DB::table('live_pages')->count());
        $pub = app(WebsitePublishing::class);
        $check = $pub->readiness($f['ctx'], $id);
        $this->assertTrue($check['ready'], Json::encode($check));
        $this->assertCount(4, $check['pages']);
        $key = self::key();
        $published = $pub->publish($f['ctx'], $id, ['requestKey' => $key]);
        $this->assertCount(4, $published['pages']);
        $this->assertTrue($pub->publish($f['ctx'], $id, ['requestKey' => $key])['replayed']);
        $this->assertSame(4, DB::table('live_pages')->count());
        $this->assertSame(4, DB::table('publications')->count());
        $html = DB::table('publications')->where('page_id', $applied['pages'][3]['id'])->value('html');
        $this->assertStringContainsString('href="/about"', $html);
        $this->assertStringContainsString('href="/services"', $html);
        $this->assertStringContainsString('href="/contact"', $html);
        $this->assertStringContainsString('<header', $html);
        $this->assertStringContainsString('<footer', $html);
        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('<form', $html);
        $this->assertStringNotContainsString('data-ak-', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->post('http://website.test/_arkon/forms/'.$applied['form']['id'].'/1', ['fields' => ['name' => 'Visitor', 'email' => 'visitor@example.com', 'message' => 'A garden project']])->assertOk();
        $this->assertSame(1, DB::table('form_submissions')->count());
    }

    public function test_stale_page_rejects_every_draft_resource_change(): void
    {
        $f = $this->siteFixture();
        [$s,$id] = $this->runProposal($f);
        DB::table('page_drafts')->where('page_id', $f['pageId'])->update(['version' => 2]);
        try {
            $s->apply($f['ctx'], $id);
            $this->fail('Expected stale');
        } catch (StaleVersionException) {
        }
        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame(0, DB::table('reusable_components')->count());
        $this->assertSame(0, DB::table('site_forms')->count());
        $this->assertSame('proposed', DB::table('ai_proposals')->where('id', $id)->value('status'));
    }

    public function test_publish_rolls_back_all_resources_if_a_page_is_stale(): void
    {
        $f = $this->siteFixture();
        [$s,$id] = $this->runProposal($f);
        $applied = $s->apply($f['ctx'], $id);
        DB::table('page_drafts')->where('page_id', $applied['pages'][3]['id'])->update(['version' => 2]);
        try {
            app(WebsitePublishing::class)->publish($f['ctx'], $id, ['requestKey' => self::key()]);
            $this->fail('Expected invalid');
        } catch (ValidationException) {
        }
        $this->assertSame(0, DB::table('site_token_versions')->count());
        $this->assertSame(0, DB::table('reusable_component_versions')->count());
        $this->assertSame(0, DB::table('site_form_versions')->count());
        $this->assertSame(0, DB::table('live_pages')->count());
    }

    public function test_revoked_website_helper_cannot_record_a_result(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $s = app(WebsiteProposalService::class);
        $r = $s->request($f['ctx'], ['prompt' => 'Create a site', 'requestKey' => self::key()]);
        $claim = app(ProposalService::class)->claimNext($helper);
        app(AiConnections::class)->revoke($helper->id);
        $this->assertFalse(app(ProposalLedger::class)->finishWebsite($r['id'], $claim->lease_token, ['summary' => 'Late']));
        $this->assertNull(DB::table('ai_proposals')->where('id', $r['id'])->value('website_result'));
    }

    public function test_invalid_output_is_retained_with_exact_issues_without_an_automatic_paid_retry(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $service = app(WebsiteProposalService::class);
        $request = $service->request($f['ctx'], ['prompt' => 'Digital marketing homepage', 'requestKey' => self::key()]);
        $claim = app(ProposalService::class)->claimNext($helper);
        $snapshot = Json::decode($claim->website_snapshot);
        $bad = $this->proposal($f, $snapshot);
        $bad['pages'][0]['proposal']['changes'][] = ['action' => 'update', 'id' => $f['heroId'], 'type' => 'hero', 'props' => ['heading' => 'Wrong envelope']];
        $runner = new FakeClaudeRunner([$bad]);
        $this->assertSame('failed', $service->execute($claim, $runner));
        $this->assertCount(1, $runner->requests);
        $view = $service->status($f['ctx'], $request['id']);
        $this->assertTrue($view['candidateSaved']);
        $this->assertNotEmpty($view['issues']);
        $this->assertStringContainsString('Page /:', $view['issues'][0]['message']);
        $this->assertStringNotContainsString('rephrase', $view['error']);
        $this->assertSame('failed', $view['activity']);
        $this->assertNotNull($view['heartbeatAt']);
        $this->assertArrayNotHasKey('website_candidate', $view);
        $this->assertEquals($bad, Json::decode(DB::table('ai_proposals')->where('id', $request['id'])->value('website_candidate')));
        $this->assertSame(1, DB::table('pages')->count());
        $this->assertSame(0, DB::table('live_pages')->count());
    }

    public function test_opted_in_repair_receives_exact_errors_and_is_bounded_to_one_retry(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $service = app(WebsiteProposalService::class);
        $request = $service->request($f['ctx'], ['prompt' => 'Homepage', 'requestKey' => self::key(), 'allowRepair' => true]);
        $claim = app(ProposalService::class)->claimNext($helper);
        $good = $this->proposal($f, Json::decode($claim->website_snapshot));
        $bad = $good;
        $bad['pages'][0]['proposal']['changes'][] = ['action' => 'remove', 'id' => 'missing-block'];
        $runner = new FakeClaudeRunner([$bad, function ($request, $keepGoing) use ($good) {
            $this->assertStringContainsString('missing-block', $request->prompt);
            $this->assertStringContainsString('Exact validation issues:', $request->prompt);
            $this->assertSame('repairing', DB::table('ai_proposals')->where('status', 'running')->value('activity'));
            $this->assertTrue($keepGoing());

            return $good;
        }]);
        $this->assertSame('proposed', $service->execute($claim, $runner));
        $this->assertCount(2, $runner->requests);
        $this->assertFalse($service->status($f['ctx'], $request['id'])['candidateSaved']);
    }

    public function test_expired_worker_cannot_write_progress_or_retained_output(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        $service = app(WebsiteProposalService::class);
        $request = $service->request($f['ctx'], ['prompt' => 'Homepage', 'requestKey' => self::key()]);
        $ledger = app(ProposalLedger::class);
        $claim = app(ProposalService::class)->claimNext($helper);
        DB::table('ai_proposals')->where('id', $claim->id)->update(['lease_expires_at' => DB::raw("now() - interval '1 second'")]);
        $this->assertFalse($ledger->websiteActivity($claim->id, $claim->lease_token, 'checking', [], ['invalid' => true]));
        $this->assertFalse($service->status($f['ctx'], $request['id'])['candidateSaved']);
    }

    public function test_second_active_website_request_is_refused_but_exact_retry_replays(): void
    {
        $f = $this->siteFixture();
        $this->helper($f);
        $s = app(WebsiteProposalService::class);
        $input = ['prompt' => 'Homepage', 'requestKey' => self::key()];
        $first = $s->request($f['ctx'], $input);
        $this->assertSame($first['id'], $s->request($f['ctx'], $input)['id']);
        $this->expectException(ConflictException::class);
        $s->request($f['ctx'], ['prompt' => 'Homepage', 'requestKey' => self::key()]);
    }

    public function test_old_helper_is_blocked_before_spending_allowance(): void
    {
        $f = $this->siteFixture();
        $helper = $this->helper($f);
        DB::table('ai_connections')->where('id', $helper->id)->update(['status' => Json::encode(FakeClaudeRunner::ready()->toArray())]);
        $service = app(WebsiteProposalService::class);
        $view = $service->list($f['ctx']);
        $this->assertFalse($view['connection']['ready']);
        $this->assertStringContainsString('old website code', $view['connection']['message']);
        $this->assertTrue(app(AiConnections::class)->helperStatus($f['siteId'])['ready']);
        try {
            $service->request($f['ctx'], ['prompt' => 'Homepage', 'requestKey' => self::key()]);
            $this->fail('Old helper must not receive a website job');
        } catch (AiException $e) {
            $this->assertSame(AiException::HELPER_OFFLINE, $e->code());
        }
        $this->assertSame(0, DB::table('ai_proposals')->count());
        app(AiConnections::class)->heartbeat($helper->id, FakeClaudeRunner::ready());
        $this->assertTrue($service->list($f['ctx'])['connection']['ready']);
    }
}
