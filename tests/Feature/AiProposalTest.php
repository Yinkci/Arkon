<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\ProposalLedger;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Ai\RunnerStatus;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;
use Tests\Support\Parallel;

/**
 * The AI panel's path, end to end in PHP: a request is queued (no long web request), the local
 * helper leases it and runs Claude Code (a scripted fake here: no process, no login), the result
 * is validated into a proposal, previewed, applied as one AI revision, undone, published only
 * explicitly. Covers request keys, concurrency, leases and recovery, cancellation, limits,
 * permissions and site isolation.
 */
class AiProposalTest extends DatabaseTestCase
{
    private array $f;

    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'ai.test');
        $this->helper = $this->pairHelper($this->f['siteId'], $this->f['ctx']->userId);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function pairHelper(string $siteId, string $userId, ?RunnerStatus $status = null): object
    {
        $connections = app(AiConnections::class);
        $id = $connections->create($siteId, $userId, 'helper', 'test helper')['id'];
        $connections->heartbeat($id, $status ?? FakeClaudeRunner::ready());

        return DB::table('ai_connections')->where('id', $id)->first();
    }

    /** One helper step with this runner (what `php artisan arkon:ai-helper` does in a loop). */
    private function tick(FakeClaudeRunner $runner, ?object $helper = null): ?string
    {
        return (new AiHelper(app(ProposalService::class), app(AiConnections::class), $runner))->tick($helper ?? $this->helper);
    }

    private static function text(string $text, string $element = 'p'): array
    {
        return ['type' => 'text', 'props' => ['text' => $text, 'element' => $element, 'style' => []]];
    }

    private function landscaping(): array
    {
        $service = fn (string $name, string $about) => ['type' => 'column', 'props' => [], 'children' => [self::text($name, 'h3'), self::text($about)]];

        return [
            'summary' => 'A homepage for a landscaping business with a hero, three services, an about section and a contact button.',
            'notes' => ['Set where the Contact us button links to before publishing.'],
            'changes' => [
                ['action' => 'update', 'change' => ['id' => $this->f['heroId'], 'type' => 'hero', 'props' => [
                    'heading' => 'Gardens that grow with you', 'headingLevel' => null, 'text' => 'Garden design, planting and lawn care for homes and businesses.', 'image' => null, 'style' => null,
                ]]],
                ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => self::text('Our services', 'h2')],
                ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => ['type' => 'columns', 'props' => ['style' => []], 'children' => [
                    $service('Garden design', 'Plans that fit your space and how you use it.'),
                    $service('Planting', 'Trees, shrubs and borders chosen for your soil.'),
                    $service('Lawn care', 'Mowing, feeding and repair through the seasons.'),
                ]]],
                ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => self::text('About us', 'h2')],
                ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => self::text('We are a local team that looks after gardens of every size.')],
                ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => ['type' => 'button', 'props' => ['label' => 'Contact us', 'href' => '', 'variant' => 'primary', 'size' => 'medium', 'newTab' => false, 'style' => []]]],
            ],
        ];
    }

    private function ai(): ProposalService
    {
        return app(ProposalService::class);
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

    private function ask(string $prompt = 'Build a homepage for a landscaping business, with a hero, services, about section and contact button.', ?SiteContext $ctx = null, ?int $baseVersion = null, ?string $key = null): array
    {
        return $this->ai()->request($ctx ?? $this->f['ctx'], $this->f['pageId'], ['prompt' => $prompt, 'baseVersion' => $baseVersion ?? $this->version(), 'requestKey' => $key ?? self::key()]);
    }

    private function requestView(string $id, ?SiteContext $ctx = null): array
    {
        return $this->ai()->status($ctx ?? $this->f['ctx'], $this->f['pageId'], $id, new MediaSigner);
    }

    /** Ask, let the helper run it with this reply, and return the reviewed proposal. */
    private function propose(mixed $reply = null, string $prompt = 'Build a homepage for a landscaping business.'): array
    {
        $request = $this->ask($prompt);
        $this->tick(new FakeClaudeRunner([$reply ?? $this->landscaping()]));

        return $this->requestView($request['id']);
    }

    /** What the editor does on "Apply to draft": save exactly the proposed operations, through JSON. */
    private function apply(array $view, ?array $operations = null, ?int $baseVersion = null): array
    {
        return $this->pages()->saveDraft($this->f['ctx'], [
            'pageId' => $this->f['pageId'], 'baseVersion' => $baseVersion ?? $view['baseVersion'], 'saveKey' => self::key(),
            'operations' => Json::decode(Json::encode($operations ?? $view['proposal']['operations'])), 'proposalId' => $view['id'],
        ]);
    }

    private function assertAiError(string $code, callable $call, ?string $message = null): AiException
    {
        try {
            $call();
        } catch (AiException $error) {
            $this->assertSame($code, $error->code(), $error->getMessage());
            if ($message !== null) {
                $this->assertStringContainsString($message, $error->getMessage().' '.implode(' ', array_column($error->issues, 'message')));
            }

            return $error;
        }
        $this->fail("Expected {$code}");
    }

    // ── Request → helper → proposal ────────────────────────────────────────

    public function test_a_request_is_queued_run_by_the_helper_and_becomes_a_validated_proposal_that_changes_nothing(): void
    {
        $before = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->first();
        $request = $this->ask();
        $this->assertSame(['queued', 'panel', 1, null], [$request['status'], $request['source'], $request['baseVersion'], $request['proposal']]);

        $runner = new FakeClaudeRunner([$this->landscaping()]);
        $this->assertSame('proposed', $this->tick($runner));
        $view = $this->requestView($request['id']);
        $proposal = $view['proposal'];

        $this->assertSame('proposed', $view['status']);
        $this->assertSame(['updateProps', 'insertNode', 'insertNode', 'insertNode', 'insertNode', 'insertNode'], array_column(Json::toArray($proposal['operations']), 'op'));
        $this->assertSame([
            'Change Hero “Original heading”: heading, text',
            'Add Text “Our services”',
            'Add Columns with 3 columns (6 blocks inside)',
            'Add Text “About us”',
            'Add Text “We are a local team that looks after gar…”',
            'Add Button “Contact us”',
        ], $proposal['changes']);
        $this->assertSame(['Button uses a placeholder destination (#)'], $proposal['warnings']);
        $this->assertSame(['Set where the Contact us button links to before publishing.'], $proposal['notes']);
        $this->assertMatchesRegularExpression('#<h2 class="ak-text2 ak-flow"[^>]*data-ak-type="text">Our services</h2>#', $proposal['canvas']['body']);

        // What Claude Code got: Arkon's instructions with the registry catalogue, the page and the request.
        $sent = $runner->requests[0];
        $this->assertStringContainsString('- columns (Columns, version 3)', $sent->instructions);
        $this->assertStringContainsString('"id":"'.$this->f['heroId'].'"', $sent->prompt);
        $this->assertStringContainsString('Build a homepage for a landscaping business', $sent->prompt);
        $this->assertSame('json_schema', 'json_schema'); // the schema travels separately:
        $this->assertSame(['summary', 'notes', 'tokenChanges', 'changes'], $sent->schema['required']);
        $this->assertStringNotContainsString('owner@', $sent->prompt.$sent->instructions, 'no account data is sent');

        // Nothing changed: no new version, revision or publication.
        $after = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->first();
        $this->assertSame([$before->document, $before->version], [$after->document, $after->version]);
        $this->assertSame(0, DB::table('page_revisions')->count());
        $this->assertSame(0, DB::table('publications')->count());
        $this->assertSame([1, null], [(int) DB::table('ai_proposals')->value('attempts'), DB::table('ai_proposals')->value('lease_token')]);
    }

    public function test_applying_is_one_ai_revision_undo_restores_the_page_and_publishing_stays_explicit(): void
    {
        $base = $this->draft();
        $view = $this->propose();

        $this->assertSame(2, $this->apply($view)['version']);
        $revision = DB::table('page_revisions')->where('page_id', $this->f['pageId'])->orderByDesc('number')->first();
        $this->assertSame('ai', $revision->source);
        $this->assertStringStartsWith('AI: A homepage for a landscaping business', $revision->message);
        $this->assertSame(['applied', $revision->id], [DB::table('ai_proposals')->value('status'), DB::table('ai_proposals')->value('applied_revision_id')]);
        $this->assertSame(0, DB::table('publications')->count(), 'applying never publishes');

        $inverse = Operations::apply($base, Json::decode(Json::encode($view['proposal']['operations'])))['inverse'];
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 2, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($inverse))]);
        $this->assertEquals(Json::toArray($base), Json::toArray($this->draft()));

        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 3, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($view['proposal']['operations']))]);
        $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 4, 'idempotencyKey' => self::key()]);
        $this->assertStringContainsString('href="#"', $this->pages()->livePage($this->f['siteId'], '/')->html);
        $button = collect($this->draft()['nodes'])->firstWhere('type', 'button');
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 4, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $button['id'], 'set' => ['href' => '/contact']]]]);
        $this->pages()->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 5, 'idempotencyKey' => self::key()]);
        $html = $this->pages()->livePage($this->f['siteId'], '/')->html;
        $this->assertStringContainsString('<h1 class="ak-hero3__heading">Gardens that grow with you</h1>', $html);
        $this->assertStringContainsString('<a class="ak-btn3 ak-btn3--responsive ak-btn3--primary" href="/contact">Contact us</a>', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?! type="application\/ld\+json")/i', $html);
        foreach (['data-ak-', 'contenteditable'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_a_follow_up_works_on_the_current_draft(): void
    {
        $this->apply($this->propose());
        $runner = new FakeClaudeRunner([[
            'summary' => 'Shortened the headline and added a services introduction.',
            'notes' => [],
            'changes' => [
                ['action' => 'update', 'change' => ['id' => $this->f['heroId'], 'type' => 'hero', 'props' => ['heading' => 'Gardens that grow', 'headingLevel' => null, 'text' => null, 'image' => null, 'style' => null]]],
                ['action' => 'add', 'parent' => 'page', 'index' => 2, 'block' => self::text('From first sketch to seasonal care, one team does it all.')],
            ],
        ]]);
        $request = $this->ask('Shorten the headline and add a services section.');
        $this->assertSame(2, $request['baseVersion']);
        $this->tick($runner);
        $this->assertStringContainsString('Gardens that grow with you', $runner->requests[0]->prompt);
        $view = $this->requestView($request['id']);
        $this->assertSame(['Change Hero “Gardens that grow with you”: heading', 'Add Text “From first sketch to seasonal care, one …”'], $view['proposal']['changes']);
        $this->apply($view);
        $this->assertSame('Gardens that grow', $this->draft()['nodes'][$this->f['heroId']]['props']['heading']);
    }

    // ── Untrusted output ───────────────────────────────────────────────────

    public function test_invalid_output_gets_one_repair_run_with_the_problems_listed(): void
    {
        $bad = ['summary' => 'x', 'notes' => [], 'changes' => [['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => ['type' => 'carousel', 'props' => []]]]];
        $runner = new FakeClaudeRunner([$bad, $this->landscaping()]);
        $request = $this->ask();
        $this->assertSame('proposed', $this->tick($runner));
        $this->assertCount(2, $runner->requests);
        $this->assertStringContainsString('Change 1: there is no "carousel" block', $runner->requests[1]->prompt);
        $this->assertSame('proposed', $this->requestView($request['id'])['status']);
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function invalidReplies(): array
    {
        $add = fn (array $block, string $parent = 'page') => ['summary' => 'x', 'notes' => [], 'changes' => [['action' => 'add', 'parent' => $parent, 'index' => null, 'block' => $block]]];
        $button = fn (string $href) => $add(['type' => 'button', 'props' => ['label' => 'Go', 'href' => $href, 'variant' => 'primary', 'size' => 'medium', 'newTab' => false, 'style' => []]]);

        return [
            'not an object' => ['Here is your page: <section>…</section>', 'not a proposal'],
            'not a proposal' => [['changes' => []], 'not a proposal'],
            'unknown component' => [$add(['type' => 'carousel', 'props' => []]), 'there is no "carousel" block'],
            'invented block id' => [['summary' => 'x', 'notes' => [], 'changes' => [['action' => 'remove', 'id' => 'doesNotExist']]], 'there is no block "doesNotExist"'],
            'column at the page level' => [$add(['type' => 'column', 'props' => [], 'children' => []]), 'column is not allowed inside page'],
            'hero inside a column' => [$add(['type' => 'columns', 'props' => ['style' => []], 'children' => [['type' => 'column', 'props' => [], 'children' => [['type' => 'hero', 'props' => ['heading' => 'x', 'headingLevel' => 'h2', 'text' => '', 'image' => null, 'style' => []]]]]]]), 'hero is not allowed inside column'],
            'unsafe link' => [$button('javascript:alert(1)'), 'Use a link starting with'],
            'backslash link' => [$button('/\\evil.example'), 'Use a link starting with'],
            'HTML in text' => [$add(self::text('<b>Bold</b> claims')), 'use plain text, not HTML'],
            'unknown prop' => [$add(['type' => 'text', 'props' => ['text' => 'x', 'element' => 'p', 'style' => [], 'color' => 'red']]), 'Unrecognized key'],
            'too long' => [$add(self::text(str_repeat('a', 5001))), 'Too long'],
            'invented image' => [$add(['type' => 'image', 'props' => ['image' => ['assetId' => '01890a5d-ac96-774b-bcce-b302099a8057', 'alt' => 'x'], 'caption' => '', 'loading' => 'auto', 'style' => []]]), 'is not one of the images on this page'],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function test_invalid_or_unsupported_output_fails_the_request_and_changes_nothing(mixed $reply, string $problem): void
    {
        config(['arkon.ai.repair_attempts' => 0]);
        $before = DB::table('page_drafts')->where('page_id', $this->f['pageId'])->first();
        $request = $this->ask();
        $this->assertSame('failed', $this->tick(new FakeClaudeRunner([$reply])));
        $view = $this->requestView($request['id']);
        $this->assertSame(['failed', AiException::INVALID_OUTPUT], [$view['status'], $view['error']['code']]);
        $this->assertStringContainsString($problem, $view['error']['message']);
        $this->assertEquals($before, DB::table('page_drafts')->where('page_id', $this->f['pageId'])->first());
    }

    public function test_an_answer_with_no_changes_explains_and_cannot_be_applied(): void
    {
        $view = $this->propose(['summary' => 'Arkon has no carousel block.', 'notes' => ['Add a button that links to your email instead.'], 'changes' => []], 'Add a carousel');
        $this->assertSame(['empty', [], null], [$view['status'], $view['proposal']['operations'], $view['proposal']['canvas']]);
        $this->assertSame(['Add a button that links to your email instead.'], $view['proposal']['notes']);
        $this->assertAiError(AiException::STALE_PROPOSAL, fn () => $this->apply($view, [['op' => 'removeNode', 'nodeId' => $this->f['heroId']]]));
    }

    public function test_only_images_already_on_the_page_are_offered(): void
    {
        $asset = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'garden.png');
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [
            ['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['image' => ['assetId' => $asset['id'], 'alt' => 'A garden']]],
        ]]);
        $runner = new FakeClaudeRunner([['summary' => 'Repeated the photo.', 'notes' => [], 'changes' => [
            ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => ['type' => 'image', 'props' => ['image' => ['assetId' => $asset['id'], 'alt' => 'A garden'], 'caption' => 'Our work', 'loading' => 'auto', 'style' => []]]],
        ]]]);
        $request = $this->ask('Show the photo again below');
        $this->tick($runner);
        $this->assertStringContainsString("- {$asset['id']}: \"A garden\" (1×1)", $runner->requests[0]->prompt);
        $this->assertSame([$asset['id']], $runner->requests[0]->schema['$defs']['block_image']['properties']['props']['properties']['image']['anyOf'][0]['properties']['assetId']['enum']);
        $this->assertSame('proposed', $this->requestView($request['id'])['status']);
    }

    // ── Request keys and concurrency ───────────────────────────────────────

    public function test_a_request_key_replays_its_own_request_and_refuses_a_changed_one(): void
    {
        $key = self::key();
        $first = $this->ask('Build a homepage', key: $key);
        $again = $this->ask('Build a homepage', key: $key);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, DB::table('ai_proposals')->count(), 'a lost acknowledgement never queues a second run');
        $this->assertThrows(fn () => $this->ask('Build a different page', key: $key), ConflictException::class, 'already used for a different');
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['text' => 'v2']]]]);
        $this->assertThrows(fn () => $this->ask('Build a homepage', baseVersion: 2, key: $key), ConflictException::class);
        $other = $this->addPage($this->f['siteId'], '/other', 'Other');
        $this->assertThrows(fn () => $this->ai()->request($this->f['ctx'], $other, ['prompt' => 'Build a homepage', 'baseVersion' => 1, 'requestKey' => $key]), ConflictException::class);
    }

    public function test_concurrent_identical_requests_replay_instead_of_colliding(): void
    {
        $key = self::key();
        $parallel = new Parallel;
        foreach (range(1, 3) as $_) {
            $parallel->add('aiRequest', $this->f['ctx'], ['pageId' => $this->f['pageId'], 'prompt' => 'Build a homepage', 'baseVersion' => 1, 'requestKey' => $key]);
        }
        $results = $parallel->wait();
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($result));
        }
        $this->assertCount(1, array_unique(array_column(array_column($results, 'result'), 'id')));
        $this->assertSame(1, DB::table('ai_proposals')->count());
    }

    public function test_a_queued_request_is_leased_to_one_helper_only(): void
    {
        $this->ask();
        $second = $this->pairHelper($this->f['siteId'], $this->f['ctx']->userId);
        $claim = $this->ai()->claimNext($this->helper);
        $this->assertNotNull($claim);
        $this->assertNull($this->ai()->claimNext($second), 'the other helper finds nothing to do');
        $this->assertSame(['running', 1], [DB::table('ai_proposals')->value('status'), (int) DB::table('ai_proposals')->value('attempts')]);
    }

    public function test_a_stopped_helper_s_request_is_recovered_once_and_its_late_result_is_refused(): void
    {
        $request = $this->ask();
        $stale = $this->ai()->claimNext($this->helper);
        // The helper stops; its lease runs out.
        DB::table('ai_proposals')->where('id', $request['id'])->update(['lease_expires_at' => DB::raw("now() - interval '1 second'")]);

        $restarted = $this->pairHelper($this->f['siteId'], $this->f['ctx']->userId);
        $this->assertSame('proposed', $this->tick(new FakeClaudeRunner([$this->landscaping()]), $restarted));
        $this->assertSame(2, (int) DB::table('ai_proposals')->value('attempts'));

        // The first helper comes back with an answer: refused, the recovered result stands.
        $this->assertSame('lost', $this->ai()->execute($stale, new FakeClaudeRunner([['summary' => 'late', 'notes' => [], 'changes' => []]])));
        $this->assertSame('proposed', $this->requestView($request['id'])['status']);

        // A request whose helper keeps stopping fails after the last attempt.
        $next = $this->ask('Something else');
        foreach ([1, 2] as $_) {
            $this->ai()->claimNext($this->helper);
            DB::table('ai_proposals')->where('id', $next['id'])->update(['lease_expires_at' => DB::raw("now() - interval '1 second'")]);
        }
        $this->ai()->claimNext($this->helper);
        $view = $this->requestView($next['id']);
        $this->assertSame(['failed', AiException::INTERRUPTED], [$view['status'], $view['error']['code']]);
    }

    // ── Revocation, eligibility and lease expiry ───────────────────────────

    /** Runs Claude Code for an already-claimed request with a runner that ignores every request to stop. */
    private function stubborn(callable $duringRun, mixed $reply = null): FakeClaudeRunner
    {
        return new FakeClaudeRunner([function (AiRequest $sent, callable $keepGoing) use ($duringRun, $reply) {
            $duringRun();
            $this->assertFalse($keepGoing(), 'the runner is told to stop at its next check');

            return $reply ?? $this->landscaping(); // …but answers anyway
        }]);
    }

    public function test_a_revoked_helper_claims_nothing(): void
    {
        $request = $this->ask();
        app(AiConnections::class)->revoke($this->helper->id);
        $this->assertNull($this->ai()->claimNext($this->helper), 'a stale connection object does not help');
        $this->assertNull($this->tick(new FakeClaudeRunner([$this->landscaping()])));
        $this->assertSame('queued', $this->requestView($request['id'])['status']);

        $replacement = $this->pairHelper($this->f['siteId'], $this->f['ctx']->userId);
        $this->assertSame('proposed', $this->tick(new FakeClaudeRunner([$this->landscaping()]), $replacement));
    }

    public function test_revoking_during_a_run_fences_it_and_rejects_the_late_result(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $request = $this->ask(ctx: $editor);
        $runner = $this->stubborn(fn () => app(AiConnections::class)->revoke($this->helper->id));
        $this->assertSame('lost', $this->tick($runner));

        $row = DB::table('ai_proposals')->where('id', $request['id'])->first();
        $this->assertSame(['queued', null, null, 1], [$row->status, $row->lease_token, $row->summary, (int) $row->attempts], 'fenced: back in the queue, no result');

        // Another helper takes it over; the requester stays the owner.
        $replacement = $this->pairHelper($this->f['siteId'], $this->f['ctx']->userId);
        $this->assertSame('proposed', $this->tick(new FakeClaudeRunner([$this->landscaping()]), $replacement));
        $row = DB::table('ai_proposals')->where('id', $request['id'])->first();
        $this->assertSame([$editor->userId, $replacement->id, 2], [$row->created_by, $row->connection_id, (int) $row->attempts]);
    }

    public function test_losing_the_right_to_edit_during_a_run_rejects_the_result(): void
    {
        // The helper's user is downgraded while Claude works.
        $request = $this->ask();
        $runner = $this->stubborn(fn () => DB::table('site_members')->where('site_id', $this->f['siteId'])->where('user_id', $this->f['ctx']->userId)->update(['role' => 'viewer']));
        $this->assertSame('lost', $this->tick($runner));
        $this->assertSame([null, 'running'], [DB::table('ai_proposals')->where('id', $request['id'])->value('summary'), DB::table('ai_proposals')->where('id', $request['id'])->value('status')]);
        $this->assertNull($this->ai()->claimNext($this->helper), 'nor can it claim more work');

        // The requester is downgraded while Claude works (helper paired by another owner).
        $owner = $this->addMember($this->f['siteId'], 'owner');
        $helper = $this->pairHelper($this->f['siteId'], $owner->userId);
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $other = $this->addPage($this->f['siteId'], '/other', 'Other');
        $mine = $this->ai()->request($editor, $other, ['prompt' => 'Build a page', 'baseVersion' => 1, 'requestKey' => self::key()]);
        DB::table('ai_proposals')->where('id', $request['id'])->update(['status' => 'cancelled']); // out of the way
        $runner = $this->stubborn(fn () => DB::table('site_members')->where('site_id', $this->f['siteId'])->where('user_id', $editor->userId)->update(['role' => 'viewer']),
            ['summary' => 'x', 'notes' => [], 'changes' => []]);
        $this->assertSame('lost', $this->tick($runner, $helper));
        $this->assertNull(DB::table('ai_proposals')->where('id', $mine['id'])->value('summary'));

        $this->assertSame(['owner', 'admin', 'editor'], array_values(array_filter(Permissions::ROLES, fn ($r) => Permissions::allows($r, 'page.edit'))),
            'the ledger\'s SQL role list matches the permission map');
    }

    public function test_an_expired_lease_is_dead_before_recovery_and_after_takeover(): void
    {
        $request = $this->ask();
        $old = $this->ai()->claimNext($this->helper);
        DB::table('ai_proposals')->where('id', $request['id'])->update(['lease_expires_at' => DB::raw("now() - interval '1 second'")]);
        $ledger = app(ProposalLedger::class);
        $compiled = ['operations' => [], 'changes' => [], 'warnings' => [], 'summary' => 'late', 'notes' => []];

        // No recover() has run yet: the expired worker can neither revive its lease nor land an outcome.
        $this->assertFalse($ledger->renew($old->id, $old->lease_token));
        $this->assertFalse($ledger->finish($old->id, $old->lease_token, $compiled));
        $this->assertFalse($ledger->failRun($old->id, $old->lease_token, AiException::CLAUDE_FAILED, 'late'));
        $this->assertSame(['running', null], [DB::table('ai_proposals')->value('status'), DB::table('ai_proposals')->value('summary')]);

        // Recovery hands it to a new execution with a fresh token; the old token stays dead.
        $new = $this->ai()->claimNext($this->pairHelper($this->f['siteId'], $this->f['ctx']->userId));
        $this->assertSame($old->id, $new->id);
        $this->assertNotSame($old->lease_token, $new->lease_token);
        $this->assertSame(2, (int) $new->attempts);
        $this->assertFalse($ledger->renew($old->id, $old->lease_token));
        $this->assertFalse($ledger->finish($old->id, $old->lease_token, $compiled));
        $this->assertTrue($ledger->renew($new->id, $new->lease_token));
        $this->assertTrue($ledger->finish($new->id, $new->lease_token, $compiled));
    }

    public function test_a_lease_expires_exactly_at_its_expiry_time(): void
    {
        $this->ask();
        $claim = $this->ai()->claimNext($this->helper);
        $ledger = app(ProposalLedger::class);
        DB::table('ai_proposals')->where('id', $claim->id)->update(['lease_expires_at' => DB::raw('now()')]);
        $this->assertFalse($ledger->renew($claim->id, $claim->lease_token), 'at the expiry instant the lease is gone (database clock)');
        $this->assertSame(1, $ledger->recover($this->f['siteId']), 'and recovery takes it from that same instant');

        $claim = $this->ai()->claimNext($this->helper);
        DB::table('ai_proposals')->where('id', $claim->id)->update(['lease_expires_at' => DB::raw("now() + interval '3 seconds'")]);
        $this->assertTrue($ledger->renew($claim->id, $claim->lease_token), 'just before it, renewal works');
        $this->assertSame(0, $ledger->recover($this->f['siteId']));
    }

    public function test_requests_nobody_picks_up_expire(): void
    {
        $request = $this->ask();
        DB::table('ai_proposals')->where('id', $request['id'])->update(['created_at' => DB::raw("now() - interval '10 minutes'")]);
        $this->assertNull($this->ai()->claimNext($this->helper));
        $this->assertSame(AiException::EXPIRED, $this->requestView($request['id'])['error']['code']);
    }

    // ── Cancel, supersede, discard ─────────────────────────────────────────

    public function test_cancelling_stops_the_run_and_a_late_result_never_lands(): void
    {
        $request = $this->ask();
        // Claude Code is still working when the user cancels; the fake keeps going regardless.
        $runner = new FakeClaudeRunner([function (AiRequest $sent, callable $keepGoing) use ($request) {
            $this->ai()->cancel($this->f['ctx'], $this->f['pageId'], $request['id']);
            $this->assertFalse($keepGoing(), 'the runner is told to stop');

            return $this->landscaping();
        }]);
        $this->assertSame('lost', $this->tick($runner));
        $view = $this->requestView($request['id']);
        $this->assertSame(['cancelled', AiException::CANCELLED, null], [$view['status'], $view['error']['code'], $view['proposal']]);

        // Cancelled before any helper took it: never runs.
        $queued = $this->ask('Another');
        $this->ai()->cancel($this->f['ctx'], $this->f['pageId'], $queued['id']);
        $this->assertNull($this->tick(new FakeClaudeRunner));
    }

    public function test_a_newer_request_supersedes_the_waiting_one(): void
    {
        $first = $this->ask('First idea');
        $second = $this->ask('Second idea');
        $this->assertSame(['cancelled', AiException::SUPERSEDED], [$this->requestView($first['id'])['status'], $this->requestView($first['id'])['error']['code']]);
        $runner = new FakeClaudeRunner([$this->landscaping()]);
        $this->tick($runner);
        $this->assertStringContainsString('Second idea', $runner->requests[0]->prompt);
        $this->assertSame('proposed', $this->requestView($second['id'])['status']);
    }

    public function test_discarded_proposals_cannot_be_applied(): void
    {
        $view = $this->propose();
        $this->ai()->discard($this->f['ctx'], $this->f['pageId'], $view['id']);
        $this->assertSame('discarded', DB::table('ai_proposals')->value('status'));
        $this->assertAiError(AiException::STALE_PROPOSAL, fn () => $this->apply($view), 'not waiting to be applied');
        $this->assertSame(1, $this->version());
    }

    // ── Stale drafts and unsaved work ──────────────────────────────────────

    public function test_stale_requests_and_proposals_never_overwrite_newer_work(): void
    {
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['text' => 'Newer work']]]]);
        $this->assertThrows(fn () => $this->ask(baseVersion: 1), StaleVersionException::class);

        // The draft changes while the request waits: the helper refuses to run it.
        $request = $this->ask();
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 2, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['text' => 'Even newer']]]]);
        $runner = new FakeClaudeRunner([$this->landscaping()]);
        $this->assertSame('failed', $this->tick($runner));
        $this->assertSame([], $runner->requests, 'Claude Code was not even started');
        $this->assertSame(AiException::STALE_DRAFT, $this->requestView($request['id'])['error']['code']);

        // A proposal made at version 3, then newer work: it can no longer be previewed or applied.
        $view = $this->propose();
        $this->pages()->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 3, 'saveKey' => self::key(), 'operations' => [['op' => 'updateProps', 'nodeId' => $this->f['heroId'], 'set' => ['text' => 'Latest']]]]);
        $this->assertNull($this->requestView($view['id'])['proposal']['canvas'], 'no preview of a proposal that no longer fits');
        $this->assertThrows(fn () => $this->apply($view), StaleVersionException::class);
        $this->assertAiError(AiException::STALE_PROPOSAL, fn () => $this->apply($view, baseVersion: 4), 'changed after');
        $this->assertSame('Latest', $this->draft()['nodes'][$this->f['heroId']]['props']['text']);

        // Altered operations are not the proposal; a proposal applies once.
        $fresh = $this->propose();
        $altered = Json::decode(Json::encode($fresh['proposal']['operations']));
        $altered[0]['set']['heading'] = 'Something the AI never proposed';
        $this->assertAiError(AiException::STALE_PROPOSAL, fn () => $this->apply($fresh, $altered), 'not the ones the AI proposed');
        $this->apply($fresh);
        $this->assertAiError(AiException::STALE_PROPOSAL, fn () => $this->apply($fresh, baseVersion: 5), 'already been applied');
    }

    public function test_a_draft_needing_repair_is_not_sent_to_claude(): void
    {
        $doc = $this->draft();
        $doc['nodes'][$this->f['heroId']]['props']['heading'] = str_repeat('x', 161);
        DB::table('page_drafts')->where('page_id', $this->f['pageId'])->update(['document' => Json::encode($doc)]);
        $this->assertThrows(fn () => $this->ask(), ValidationException::class, 'Repair this draft');
    }

    // ── Helper readiness, Claude Code failures, limits ─────────────────────

    public function test_requests_need_a_connected_ready_helper(): void
    {
        DB::table('ai_connections')->update(['last_seen_at' => DB::raw("now() - interval '5 minutes'")]);
        $this->assertAiError(AiException::HELPER_OFFLINE, fn () => $this->ask(), 'php artisan arkon:ai-helper');
        $this->assertSame(0, DB::table('ai_proposals')->count());

        app(AiConnections::class)->heartbeat($this->helper->id, new RunnerStatus(false, AiException::CLAUDE_BILLING_MODE, 'Claude Code is signed in with "api_key" (API or Console billing).'));
        $this->assertAiError(AiException::HELPER_OFFLINE, fn () => $this->ask(), 'api_key');
        $info = $this->ai()->editorInfo($this->f['ctx'], true);
        $this->assertFalse($info['connection']['ready']);

        // A helper that is not ready reports but never claims work.
        app(AiConnections::class)->heartbeat($this->helper->id, FakeClaudeRunner::ready());
        $this->ask();
        $notReady = new FakeClaudeRunner([], new RunnerStatus(false, AiException::CLAUDE_NOT_LOGGED_IN, 'Claude Code is not signed in.'));
        $this->assertNull($this->tick($notReady));
        $this->assertSame('queued', DB::table('ai_proposals')->value('status'));
        $this->assertFalse(app(AiConnections::class)->helperStatus($this->f['siteId'])['ready']);
    }

    /** @return array<string, array{0: string}> */
    public static function claudeFailures(): array
    {
        return [
            'missing' => [AiException::CLAUDE_MISSING],
            'not signed in' => [AiException::CLAUDE_NOT_LOGGED_IN],
            'subscription limit' => [AiException::CLAUDE_LIMIT],
            'timeout' => [AiException::CLAUDE_TIMEOUT],
            'failed' => [AiException::CLAUDE_FAILED],
        ];
    }

    #[DataProvider('claudeFailures')]
    public function test_claude_code_failures_end_the_request_with_an_understandable_message(string $code): void
    {
        $request = $this->ask();
        $this->assertSame('failed', $this->tick(new FakeClaudeRunner([new AiException($code, "Message for {$code}.")])));
        $view = $this->requestView($request['id']);
        $this->assertSame([$code, "Message for {$code}."], [$view['error']['code'], $view['error']['message']]);
        $this->assertSame(1, $this->version());
    }

    public function test_request_frequency_and_concurrency_limits(): void
    {
        config(['arkon.ai.per_user_per_minute' => 2]);
        $this->ask('one');
        $this->ask('two'); // supersedes one
        $this->assertAiError(AiException::RATE_LIMITED, fn () => $this->ask('three'), '2 AI requests a minute');

        config(['arkon.ai.per_user_per_minute' => 50, 'arkon.ai.max_active_per_site' => 1]);
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $this->assertAiError(AiException::LIMIT_REACHED, fn () => $this->ask('mine', ctx: $editor), 'already waiting or running');

        config(['arkon.ai.max_active_per_site' => 5, 'arkon.ai.per_site_per_day' => 2]);
        $this->assertAiError(AiException::LIMIT_REACHED, fn () => $this->ask('mine', ctx: $editor), '2 AI requests for today');

        $this->assertThrows(fn () => $this->ask(str_repeat('a', 2001)), ValidationException::class);
        $this->assertThrows(fn () => $this->ask('   '), ValidationException::class);
    }

    // ── Permissions and isolation ──────────────────────────────────────────

    public function test_only_editors_of_the_site_can_ask_and_requests_belong_to_their_creator(): void
    {
        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->assertThrows(fn () => $this->ask(ctx: $viewer), ForbiddenException::class);
        $outsider = $this->siteFixture();
        $this->assertThrows(fn () => $this->ask(ctx: new SiteContext($this->f['siteId'], $outsider['ctx']->userId)), NotFoundException::class);
        $this->assertThrows(fn () => $this->ai()->request($outsider['ctx'], $this->f['pageId'], ['prompt' => 'x', 'baseVersion' => 1, 'requestKey' => self::key()]), NotFoundException::class);

        // Another site's helper never sees this site's requests.
        $otherHelper = $this->pairHelper($outsider['siteId'], $outsider['ctx']->userId);
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $request = $this->ask(ctx: $editor);
        $this->assertNull($this->ai()->claimNext($otherHelper));
        $this->tick(new FakeClaudeRunner([$this->landscaping()]));

        // Only the editor who asked can see, apply or discard it.
        $this->assertThrows(fn () => $this->requestView($request['id']), NotFoundException::class);
        $this->assertAiError(AiException::STALE_PROPOSAL, fn () => $this->apply(['id' => $request['id'], 'baseVersion' => 1, 'proposal' => $this->requestView($request['id'], $editor)['proposal']]), 'does not exist');
        $this->assertAiError(AiException::STALE_PROPOSAL, fn () => $this->ai()->discard($this->f['ctx'], $this->f['pageId'], $request['id']));
        $this->assertSame([], $this->ai()->list($this->f['ctx'], $this->f['pageId'])['requests']);
        $this->assertCount(1, $this->ai()->list($editor, $this->f['pageId'])['requests']);
    }

    // ── HTTP ───────────────────────────────────────────────────────────────

    public function test_the_editor_api_queues_polls_cancels_and_discards(): void
    {
        $owner = User::findOrFail($this->f['ctx']->userId);
        $this->actingAs($owner)->get("/admin/editor/{$this->f['pageId']}")->assertInertia(fn (Assert $p) => $p
            ->where('init.ai.available', true)->where('init.ai.connection.ready', true)->where('init.ai.promptMax', 2000));

        $base = "/admin/api/pages/{$this->f['pageId']}/ai/requests";
        $created = $this->actingAs($owner)->postJson($base, ['prompt' => 'Build a landscaping homepage', 'baseVersion' => 1, 'requestKey' => self::key()]);
        $created->assertOk()->assertJsonPath('data.status', 'queued');
        $id = $created->json('data.id');
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('data.requests.0.id', $id)->assertJsonPath('data.connection.ready', true);
        $this->actingAs($owner)->postJson("{$base}/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $again = $this->actingAs($owner)->postJson($base, ['prompt' => 'Build a landscaping homepage', 'baseVersion' => 1, 'requestKey' => self::key()])->json('data.id');
        $this->tick(new FakeClaudeRunner([$this->landscaping()]));
        $this->actingAs($owner)->getJson("{$base}/{$again}")->assertOk()->assertJsonPath('data.status', 'proposed')
            ->assertJsonPath('data.proposal.changes.1', 'Add Text “Our services”')
            ->assertJson(fn ($json) => $json->where('data.proposal.canvas.body', fn ($body) => str_contains($body, 'Our services'))->etc());
        $this->actingAs($owner)->postJson("{$base}/{$again}/discard")->assertOk();

        $viewer = User::findOrFail($this->addMember($this->f['siteId'], 'viewer')->userId);
        $this->actingAs($viewer)->get("/admin/editor/{$this->f['pageId']}")
            ->assertInertia(fn (Assert $p) => $p->where('init.ai.available', false)->where('init.ai.reason', 'Only members who can edit this page can use AI.'));
        $this->actingAs($viewer)->postJson($base, ['prompt' => 'x', 'baseVersion' => 1, 'requestKey' => self::key()])->assertStatus(403);
        $this->assertStringNotContainsString('token', strtolower($this->actingAs($owner)->getJson($base)->getContent()));
    }
}
