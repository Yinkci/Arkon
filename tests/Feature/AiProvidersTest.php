<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiCompletion;
use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\AiRunner;
use App\Arkon\Ai\ProposalLedger;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Ai\RunnerStatus;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Support\Uuid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;

class AiProvidersTest extends DatabaseTestCase
{
    private function pair(array $f, string $provider): object
    {
        $c = app(AiConnections::class);
        $id = $c->create($f['siteId'], $f['ctx']->userId, 'helper', $provider, $provider)['id'];
        $c->heartbeat($id, new RunnerStatus(true, null, 'Ready'));

        return DB::table('ai_connections')->where('id', $id)->first();
    }

    private function request(array $f, array $selection = [], string $scope = 'page', ?string $key = null): array
    {
        $input = ['prompt' => 'Change heading', 'baseVersion' => 1, 'requestKey' => $key ?? Uuid::v7(), ...$selection];

        return $scope === 'website' ? app(WebsiteProposalService::class)->request($f['ctx'], $input) : app(ProposalService::class)->request($f['ctx'], $f['pageId'], $input);
    }

    private function rejects(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Request was accepted');
        } catch (AiException $e) {
            $this->assertSame($code, $e->code());
        }
    }

    public function test_one_provider_is_automatic_and_saved_preferences_are_ignored(): void
    {
        foreach (['claude-code', 'codex'] as $provider) {
            $f = $this->siteFixture();
            $this->pair($f, $provider);
            DB::table('ai_preferences')->insert(['site_id' => $f['siteId'], 'user_id' => $f['ctx']->userId, 'provider' => $provider === 'codex' ? 'claude-code' : 'codex']);
            $status = app(AiConnections::class)->forContext($f['ctx']);
            $this->assertSame('automatic', $status['selectionState']);
            $this->assertSame($provider, $status['provider']);
            $this->assertSame($provider, $this->request($f)['provider']);
            $this->assertSame($provider, $this->request($f, [], 'website')['provider']);
        }
    }

    public function test_multiple_providers_require_choice_for_pages_and_websites(): void
    {
        $f = $this->siteFixture();
        $claude = $this->pair($f, 'claude-code');
        $codex = $this->pair($f, 'codex');
        $this->assertSame('choice_required', app(AiConnections::class)->forContext($f['ctx'])['selectionState']);
        foreach (['page', 'website'] as $scope) {
            $this->rejects(fn () => $this->request($f, [], $scope), 'PROVIDER_CHOICE_REQUIRED');
            $r = $this->request($f, ['provider' => 'codex', 'selectionMode' => 'explicit'], $scope);
            $this->assertSame('codex', $r['provider']);
            $this->assertNull(app(ProposalService::class)->claimNext($claude));
            $this->assertSame($r['id'], app(ProposalService::class)->claimNext($codex)->id);
        }
    }

    public function test_no_provider_stale_authentication_revocation_and_role_loss_are_unavailable(): void
    {
        $f = $this->siteFixture();
        $c = app(AiConnections::class);
        $this->rejects(fn () => $this->request($f), 'PROVIDER_UNAVAILABLE');
        $b = $this->pair($f, 'codex');
        DB::table('ai_connections')->where('id', $b->id)->update(['last_seen_at' => DB::raw("now()-interval '21 seconds'")]);
        $this->assertSame('unavailable', $c->forContext($f['ctx'])['selectionState']);
        $c->heartbeat($b->id, new RunnerStatus(false, 'PROVIDER_NOT_AUTHENTICATED', 'Sign in'));
        $this->assertFalse($c->forContext($f['ctx'])['ready']);
        $c->heartbeat($b->id, new RunnerStatus(true, null, 'Ready'));
        $c->revoke($b->id);
        $this->assertFalse($c->forContext($f['ctx'])['ready']);
        $b = $this->pair($f, 'codex');
        DB::table('site_members')->where('site_id', $f['siteId'])->where('user_id', $f['ctx']->userId)->update(['role' => 'viewer']);
        $this->actingAs(User::find($f['ctx']->userId));
        $this->getJson('/admin/api/ai-connections')->assertForbidden();
    }

    public function test_other_members_helpers_are_not_used_or_shown_as_mine(): void
    {
        $f = $this->siteFixture();
        $ownerHelper = $this->pair($f, 'codex');
        $member = $this->addMember($f['siteId'], 'editor');
        $g = [...$f, 'ctx' => $member];
        $c = app(AiConnections::class);
        $this->assertSame('unavailable', $c->forContext($member)['selectionState']);
        $this->actingAs(User::find($member->userId));
        $this->getJson('/admin/api/ai-connections')->assertOk()->assertJsonPath('data.providers.1.ready', false)->assertJsonPath('data.providers.1.connectionId', null);
        $this->rejects(fn () => $this->request($g), 'PROVIDER_UNAVAILABLE');
        $this->pair($g, 'codex');
        $r = $this->request($g);
        $this->assertNull(app(ProposalService::class)->claimNext($ownerHelper));
        $other = $this->siteFixture();
        $this->assertFalse($c->forContext($other['ctx'])['ready']);
    }

    public function test_automatic_hint_rejects_connections_changed_before_submission(): void
    {
        $f = $this->siteFixture();
        $b = $this->pair($f, 'codex');
        $this->pair($f, 'claude-code');
        foreach (['page', 'website'] as $scope) {
            $this->rejects(fn () => $this->request($f, ['provider' => 'codex', 'selectionMode' => 'automatic'], $scope), 'PROVIDER_CHOICE_REQUIRED');
        }
        app(AiConnections::class)->revoke($b->id);
        foreach (['page', 'website'] as $scope) {
            $this->rejects(fn () => $this->request($f, ['provider' => 'codex', 'selectionMode' => 'automatic'], $scope), 'PROVIDER_SELECTION_CHANGED');
        }
        $this->assertSame(0, DB::table('ai_proposals')->where('site_id', $f['siteId'])->count());
    }

    public function test_exact_retries_keep_provider_after_disconnection_draft_and_availability_changes(): void
    {
        foreach (['page', 'website'] as $scope) {
            $f = $this->siteFixture();
            $b = $this->pair($f, 'codex');
            $key = Uuid::v7();
            $selection = ['provider' => 'codex', 'selectionMode' => 'automatic'];
            $a = $this->request($f, $selection, $scope, $key);
            app(AiConnections::class)->revoke($b->id);
            $this->pair($f, 'claude-code');
            DB::table('page_drafts')->where('page_id', $f['pageId'])->increment('version');
            $retry = $this->request($f, $selection, $scope, $key);
            $this->assertSame([$a['id'], 'codex'], [$retry['id'], $retry['provider']]);
            $this->assertSame(1, DB::table('ai_proposals')->where('site_id', $f['siteId'])->count());
            try {
                $this->request($f, ['provider' => 'claude-code', 'selectionMode' => 'explicit'], $scope, $key);
                $this->fail('Changed retry accepted');
            } catch (ConflictException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_legacy_retry_without_selection_fields_survives_new_ambiguity(): void
    {
        foreach (['page', 'website'] as $scope) {
            $f = $this->siteFixture();
            $this->pair($f, 'codex');
            $key = Uuid::v7();
            $a = $this->request($f, [], $scope, $key);
            $this->pair($f, 'claude-code');
            $b = $this->request($f, [], $scope, $key);
            $this->assertSame($a['id'], $b['id']);
        }
    }

    public function test_websites_check_protocol_for_each_provider(): void
    {
        $f = $this->siteFixture();
        $b = $this->pair($f, 'codex');
        $c = $this->pair($f, 'claude-code');
        DB::table('ai_connections')->where('id', $b->id)->update(['status' => json_encode(['ready' => true, 'websiteProtocol' => 2])]);
        $this->assertSame('choice_required', app(AiConnections::class)->forContext($f['ctx'])['selectionState']);
        $website = app(AiConnections::class)->forContext($f['ctx'], 'website_proposal');
        $this->assertSame('claude-code', $website['provider']);
        $this->rejects(fn () => $this->request($f, ['provider' => 'codex'], 'website'), 'PROVIDER_UNAVAILABLE');
    }

    public function test_interruption_fails_without_second_model_attempt(): void
    {
        foreach (['page', 'website'] as $scope) {
            $f = $this->siteFixture();
            $b = $this->pair($f, 'codex');
            $r = $this->request($f, [], $scope);
            $claim = app(ProposalService::class)->claimNext($b);
            DB::table('ai_proposals')->where('id', $r['id'])->update(['lease_expires_at' => DB::raw("now()-interval '1 second'")]);
            $ledger = app(ProposalLedger::class);
            $ledger->recover($f['siteId']);
            $this->assertFalse($ledger->renew($claim->id, $claim->lease_token));
            $this->assertNull(app(ProposalService::class)->claimNext($b));
            $this->assertSame('failed', DB::table('ai_proposals')->where('id', $r['id'])->value('status'));
            $this->assertSame(1, DB::table('ai_proposals')->where('id', $r['id'])->value('attempts'));
        }
    }

    public function test_connection_management_has_no_default_and_is_scoped(): void
    {
        $f = $this->siteFixture();
        $b = $this->pair($f, 'codex');
        $this->actingAs(User::find($f['ctx']->userId));
        $this->getJson('/admin/api/ai-connections')->assertOk()->assertJsonMissingPath('data.defaultProvider');
        $this->postJson('/admin/api/ai-connections/default', ['provider' => 'codex'])->assertNotFound();
        $this->postJson('/admin/api/ai-connections/'.$b->id.'/disconnect')->assertOk();
        $other = $this->pair($this->siteFixture(), 'codex');
        $this->postJson('/admin/api/ai-connections/'.$other->id.'/disconnect')->assertNotFound();
    }

    public function test_connection_response_test_runs_once_and_revoked_helper_cannot_start(): void
    {
        $f = $this->siteFixture();
        $b = $this->pair($f, 'codex');
        DB::table('ai_connections')->where('id', $b->id)->update(['test_requested_at' => DB::raw('now()')]);
        $runner = new FakeClaudeRunner([['ok' => true]]);
        $helper = new AiHelper(app(ProposalService::class), app(AiConnections::class), $runner);
        $this->assertSame('connection_test', $helper->tick($b));
        $this->assertTrue(json_decode(DB::table('ai_connections')->where('id', $b->id)->value('test_result'), true)['ok']);
        $this->assertNull($helper->tick($b));
        app(AiConnections::class)->revoke($b->id);
        $this->assertNull($helper->tick($b));
    }

    public function test_post_seo_and_image_alt_requests_use_the_same_policy(): void
    {
        foreach (['page', 'post'] as $kind) {
            $f = $this->siteFixture();
            DB::table('pages')->where('id', $f['pageId'])->update(['kind' => $kind]);
            $this->pair($f, 'codex');
            foreach (['ARKON_SEO_METADATA_ONLY:all', 'ARKON_SEO_ALT_ONLY:'.$f['heroId']] as $prompt) {
                $r = app(ProposalService::class)->request($f['ctx'], $f['pageId'], ['prompt' => $prompt, 'baseVersion' => 1, 'requestKey' => Uuid::v7()]);
                $this->assertSame('codex', $r['provider']);
            }
        }
    }

    public function test_revoking_one_provider_never_dispatches_its_work_to_the_other(): void
    {
        $f = $this->siteFixture();
        $codex = $this->pair($f, 'codex');
        $claude = $this->pair($f, 'claude-code');
        $r = $this->request($f, ['provider' => 'codex', 'selectionMode' => 'explicit']);
        $claim = app(ProposalService::class)->claimNext($codex);
        app(AiConnections::class)->revoke($codex->id);
        $this->assertFalse(app(ProposalLedger::class)->renew($claim->id, $claim->lease_token));
        $this->assertNull(app(ProposalService::class)->claimNext($claude));
        $this->assertSame('failed', DB::table('ai_proposals')->where('id', $r['id'])->value('status'));
        $this->assertSame('codex', DB::table('ai_proposals')->where('id', $r['id'])->value('provider'));
    }

    public function test_login_is_rechecked_before_spending_allowance(): void
    {
        $f = $this->siteFixture();
        $connection = $this->pair($f, 'codex');
        $r = $this->request($f);
        $runner = new class implements AiRunner
        {
            public int $checks = 0;

            public int $runs = 0;

            public function check(): RunnerStatus
            {
                return ++$this->checks === 1 ? new RunnerStatus(true, null, 'Ready') : new RunnerStatus(false, 'PROVIDER_NOT_AUTHENTICATED', 'Sign in again');
            }

            public function run(AiRequest $request, callable $keepGoing): AiCompletion
            {
                $this->runs++;
                throw new \RuntimeException('Must not run');
            }
        };
        $helper = new AiHelper(app(ProposalService::class),app(AiConnections::class),$runner);
        $this->assertSame('failed',$helper->tick($connection));
        $this->assertSame(0,$runner->runs);
        $this->assertSame('PROVIDER_UNAVAILABLE',DB::table('ai_proposals')->where('id',$r['id'])->value('error_code'));
    }
}
