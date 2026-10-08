<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Design\TokenService;
use App\Arkon\Errors\ArkonException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;
use Tests\Support\Interleaves;

/**
 * "Apply to the token draft" for an AI proposal's site-wide token changes: the token draft
 * write and the proposal's applied marker commit together, so an interruption leaves
 * neither, duplicates apply once, an exact retry returns the original result, and a
 * change is never replayed over token edits made after the proposal.
 */
class AiTokenApplyTest extends DatabaseTestCase
{
    use Interleaves;

    private array $f;

    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $id = app(AiConnections::class)->create($this->f['siteId'], $this->f['ctx']->userId, 'helper', 'test helper')['id'];
        app(AiConnections::class)->heartbeat($id, FakeClaudeRunner::ready());
        $this->helper = DB::table('ai_connections')->where('id', $id)->first();
    }

    private function ai(): ProposalService
    {
        return app(ProposalService::class);
    }

    private function tokens(): TokenService
    {
        return app(TokenService::class);
    }

    /** A reviewed proposal that changes the primary colour site-wide. */
    private function proposeTeal(): string
    {
        $version = (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version');
        $request = $this->ai()->request($this->f['ctx'], $this->f['pageId'], ['prompt' => 'Make the brand colour teal', 'baseVersion' => $version, 'requestKey' => self::key()]);
        $reply = ['summary' => 'Teal brand colour.', 'notes' => [], 'tokenChanges' => [['token' => '@color.primary', 'value' => '#0f766e']], 'changes' => []];
        (new AiHelper($this->ai(), app(AiConnections::class), new FakeClaudeRunner([$reply])))->tick($this->helper);
        $this->assertSame('proposed', $this->ai()->status($this->f['ctx'], $this->f['pageId'], $request['id'], new MediaSigner)['status']);

        return $request['id'];
    }

    private function apply(string $proposalId, ?SiteContext $ctx = null): array
    {
        return $this->ai()->applyTokenChanges($ctx ?? $this->f['ctx'], $this->f['pageId'], $proposalId, $this->tokens());
    }

    private function draft(): array
    {
        return Json::toArray($this->tokens()->state($this->f['ctx'])['draft']);
    }

    private function tokenVersion(): int
    {
        return $this->tokens()->state($this->f['ctx'])['version'];
    }

    /** The user edits the token draft (the Design page's Save). */
    private function editTokens(array $tokens): int
    {
        return $this->tokens()->save($this->f['ctx'], ['baseVersion' => $this->tokenVersion(), 'tokens' => Json::decode(Json::encode($tokens)), 'saveKey' => self::key()])['version'];
    }

    /** Fails the statement that records the proposal as applied: the process is interrupted there. */
    private function interruptAtMarker(callable $run): void
    {
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$armed) {
            if ($armed && str_starts_with($query, 'update "ai_proposals"')) {
                $armed = false;
                throw new RuntimeException('interrupted before the proposal was marked applied');
            }
        });
        try {
            $run();
            $this->fail('Expected the interruption');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('interrupted', $error->getMessage());
        } finally {
            $armed = false;
        }
    }

    private function livePageHtml(): string
    {
        return (string) app(PageService::class)->livePage($this->f['siteId'], '/')->html;
    }

    public function test_an_interruption_after_the_token_write_leaves_neither_and_the_retry_applies_once(): void
    {
        $proposal = $this->proposeTeal();
        $before = $this->tokenVersion();

        $this->interruptAtMarker(fn () => $this->apply($proposal));
        $this->assertSame($before, $this->tokenVersion(), 'the token draft write rolled back with the marker');
        $this->assertEquals([], $this->draft());

        $applied = $this->apply($proposal);
        $this->assertSame($before + 1, $applied['tokenDraftVersion']);
        $this->assertEquals(['color' => ['primary' => '#0f766e']], $this->draft());
        $this->assertSame($applied, $this->apply($proposal), 'an exact retry returns the original result');
        $this->assertSame($before + 1, $this->tokenVersion());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tokens.ai.apply')->count());
    }

    public function test_an_exact_retry_after_later_token_edits_returns_the_original_result_and_keeps_the_edits(): void
    {
        $proposal = $this->proposeTeal();
        $applied = $this->apply($proposal);
        $edited = $this->editTokens(['color' => ['primary' => '#7c3aed']]);

        $this->assertSame($applied, $this->apply($proposal));
        $this->assertSame($edited, $this->tokenVersion(), 'not applied again');
        $this->assertEquals(['color' => ['primary' => '#7c3aed']], $this->draft(), 'the newer edit is kept');
    }

    public function test_a_change_is_never_replayed_over_token_edits_made_after_the_proposal(): void
    {
        $proposal = $this->proposeTeal();
        // The first attempt is interrupted, then the user edits the tokens on the Design page.
        $this->interruptAtMarker(fn () => $this->apply($proposal));
        $edited = $this->editTokens(['color' => ['primary' => '#7c3aed']]);

        try {
            $this->apply($proposal);
            $this->fail('Expected a stale proposal');
        } catch (AiException $error) {
            $this->assertSame(AiException::STALE_PROPOSAL, $error->code());
        }
        $this->assertSame($edited, $this->tokenVersion());
        $this->assertEquals(['color' => ['primary' => '#7c3aed']], $this->draft());
        $this->assertNull(Json::toArray(Json::decode((string) DB::table('ai_proposals')->where('id', $proposal)->value('details')))['tokenChangesApplied'] ?? null);
    }

    public function test_concurrent_duplicate_applications_apply_once(): void
    {
        $proposal = $this->proposeTeal();
        $before = $this->tokenVersion();

        [$first, $second] = $this->interleaveWith(fn () => $this->apply($proposal), 'applyTokens', $this->f['ctx'], ['pageId' => $this->f['pageId'], 'proposalId' => $proposal]);

        $this->assertTrue($second['ok'], 'the duplicate succeeds with the same result: '.json_encode($second));
        $this->assertSame($first, $second['result']);
        $this->assertSame($before + 1, $this->tokenVersion());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tokens.ai.apply')->count());
    }

    public function test_current_permissions_are_enforced_and_live_pages_wait_for_token_publication(): void
    {
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $live = $this->livePageHtml();
        $proposal = $this->proposeTeal();

        // The requester is demoted to viewer after asking: applying (and retrying) is refused.
        DB::table('site_members')->where('site_id', $this->f['siteId'])->where('user_id', $this->f['ctx']->userId)->update(['role' => 'viewer']);
        foreach ([1, 2] as $attempt) {
            try {
                $this->apply($proposal);
                $this->fail('Expected a refusal');
            } catch (ForbiddenException) {
                $this->assertEquals([], $this->draft(), "attempt {$attempt}");
            }
        }
        // Another editor of the site cannot apply someone else's proposal either.
        $editor = $this->addMember($this->f['siteId'], 'editor');
        try {
            $this->apply($proposal, $editor);
            $this->fail('Expected not found');
        } catch (ArkonException $error) {
            $this->assertSame('NOT_FOUND', $error->code());
        }

        DB::table('site_members')->where('site_id', $this->f['siteId'])->where('user_id', $this->f['ctx']->userId)->update(['role' => 'editor']);
        $this->apply($proposal);
        $this->assertEquals(['color' => ['primary' => '#0f766e']], $this->draft());
        $this->assertSame($live, $this->livePageHtml(), 'live pages change only when tokens are published');
    }
}
