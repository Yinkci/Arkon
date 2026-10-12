<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiCompletion;
use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\AiRunner;
use App\Arkon\Ai\FormChanges;
use App\Arkon\Ai\Images\GeneratedImage;
use App\Arkon\Ai\Images\GeneratedImageImporter;
use App\Arkon\Ai\Images\ImageGenerator;
use App\Arkon\Ai\Images\ImageJobs;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Ai\RunnerStatus;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Ai\WebsiteRevisions;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Sites\WebsitePublishing;
use App\Arkon\Support\Json;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;
use Tests\Support\FakeImageGenerator;

/**
 * Website revisions ("Request changes") and generated images, end to end with fake providers:
 * the acceptance scenario (a contact page with a generated hero background, then a hero-only
 * revision), scope enforcement, the image policy, capability checks before any model run, image
 * jobs that are never generated twice, the media pipeline, privacy and stale-baseline conflicts.
 */
final class WebsiteRevisionsTest extends DatabaseTestCase
{
    private const BRIEF = 'Build a contact page. Create a form with first name, last name, email, phone and message. Add a hero with a generated professional background photograph featuring Black African adults.';

    private const HERO_ONLY = 'Replace only the hero background with another generated image. Keep the form, text and other sections unchanged.';

    /** A Claude Code helper (text) and a Codex helper whose image generation is ready or not. @return array{0: object, 1: object} */
    private function connect(array $f, bool $images = true): array
    {
        $c = app(AiConnections::class);
        $claude = $c->create($f['siteId'], $f['ctx']->userId, 'helper', 'claude', 'claude-code');
        $c->heartbeat($claude['id'], FakeClaudeRunner::ready());
        $codex = $c->create($f['siteId'], $f['ctx']->userId, 'helper', 'codex', 'codex');
        $text = new RunnerStatus(true, null, 'Codex 0.160.0, signed in with ChatGPT.', '0.160.0', 'chatgpt');
        $c->heartbeat($codex['id'], $text->withImages($images
            ? new RunnerStatus(true, null, 'Codex 0.160.0 image generation with your ChatGPT account.')
            : new RunnerStatus(false, 'IMAGE_GENERATION_DISABLED', 'Image generation through Codex is turned off in Arkon.')));

        return [$c->resolve($claude['token'], 'helper'), $c->resolve($codex['token'], 'helper')];
    }

    private function ask(array $f, array $input): array
    {
        return app(WebsiteProposalService::class)->request($f['ctx'], ['requestKey' => self::key(), 'provider' => 'claude-code', 'selectionMode' => 'explicit', 'includeLayout' => false, ...$input]);
    }

    private function snapshot(string $id): array
    {
        return Json::decode(DB::table('ai_proposals')->where('id', $id)->value('website_snapshot'));
    }

    private function stored(string $id): array
    {
        return Json::decode(DB::table('ai_proposals')->where('id', $id)->value('website_result'));
    }

    /** Runs the next queued layout request with a scripted reply. */
    private function runLayout(object $helper, mixed $reply, ?FakeClaudeRunner &$runner = null): string
    {
        $claim = app(ProposalService::class)->claimNext($helper);
        $this->assertNotNull($claim, 'a queued layout request');
        $runner = new FakeClaudeRunner([$reply]);

        return app(ProposalService::class)->execute($claim, $runner);
    }

    private function runImage(object $codex, ?FakeImageGenerator $generator = null): string
    {
        $jobs = app(ImageJobs::class);
        $job = $jobs->claimNext($codex);
        $this->assertNotNull($job, 'a queued image');

        return $jobs->execute($job, $generator ?? new FakeImageGenerator, app(GeneratedImageImporter::class));
    }

    private function review(array $f, string $id): array
    {
        return app(WebsiteProposalService::class)->status($f['ctx'], $id);
    }

    private static function add(string $type, array $props, string $parent = 'page', ?string $ref = null): array
    {
        return ['action' => 'add', 'parent' => $parent, 'index' => null, 'ref' => $ref, 'block' => ['type' => $type, 'props' => $props]];
    }

    /** The model's reply to the acceptance brief: one contact page, a hero (with a generated background when given) and the five-field form. */
    private function contactReply(array $snapshot, ?string $background, ?string $libraryImage = null): array
    {
        $style = [];
        if ($background !== null || $libraryImage !== null) {
            $style = [['slot' => 'root', 'screen' => 'base', 'property' => 'backgroundImage', 'value' => $background ?? $libraryImage],
                ['slot' => 'root', 'screen' => 'base', 'property' => 'backgroundSize', 'value' => 'cover'],
                ['slot' => 'root', 'screen' => 'base', 'property' => 'backgroundPosition', 'value' => 'center'],
                ['slot' => 'root', 'screen' => 'base', 'property' => 'backgroundOverlay', 'value' => '#00000080'],
                ['slot' => 'root', 'screen' => 'base', 'property' => 'color', 'value' => '#ffffff']];
        }
        $field = fn ($id, $label, $type) => ['id' => $id, 'label' => $label, 'type' => $type, 'required' => in_array($id, ['first_name', 'email', 'message'], true), 'options' => []];

        return ['summary' => 'A contact page with a hero and an enquiry form', 'pages' => [[
            'pageId' => null, 'title' => 'Contact', 'path' => '/contact', 'seo' => ['title' => 'Contact us', 'description' => 'Send us a message using the enquiry form.'],
            'proposal' => ['summary' => 'Contact page', 'notes' => [], 'tokenChanges' => [], 'changes' => [
                self::add('hero', ['heading' => 'Contact us', 'text' => 'Send us a message and we will reply.', ...($style !== [] ? ['style' => $style] : [])]),
                self::add('form', ['form' => ['id' => $snapshot['form']['id']]]),
            ]],
        ]], 'header' => null, 'footer' => null, 'menu' => null, 'tokenChanges' => [],
            'form' => ['name' => 'Contact enquiry', 'submitLabel' => 'Send message', 'successMessage' => 'Thank you. Your message was received.', 'fields' => [
                $field('first_name', 'First name', 'text'), $field('last_name', 'Last name', 'text'), $field('email', 'Email', 'email'), $field('phone', 'Phone', 'tel'), $field('message', 'Message', 'textarea'),
            ]],
            'generatedImages' => $background === null ? [] : [['assetId' => $background, 'subject' => 'Two professional Black African adults talking warmly at a bright, modern reception desk', 'visualDirection' => 'Natural daylight, warm tones, calm negative space on the left for text', 'placement' => 'background', 'aspectRatio' => '16:9', 'keepVisible' => 'Both people, right of centre', 'alt' => '', 'title' => 'Contact hero background']],
        ];
    }

    /** @return array{0: array, 1: string, 2: array} the contact page and hero node of a result */
    private function contactPage(array $result): array
    {
        foreach ($result['pages'] as $page) {
            if ($page['path'] === '/contact') {
                foreach (Json::entries($page['document']['nodes']) as $id => $node) {
                    if ($node['type'] === 'hero') {
                        return [$page, $id, $node];
                    }
                }
            }
        }
        $this->fail('No contact hero in the result');
    }

    private function heroOnlyReply(string $heroId, string $background, array $extraChanges = []): array
    {
        return ['summary' => 'Replaced the hero background with a new generated photograph', 'notes' => [], 'seo' => null, 'outOfScope' => null,
            'proposal' => ['summary' => 'New hero background', 'notes' => [], 'tokenChanges' => [], 'changes' => [
                ['action' => 'update', 'change' => ['id' => $heroId, 'type' => 'hero', 'props' => ['style' => [['slot' => 'root', 'screen' => 'base', 'property' => 'backgroundImage', 'value' => $background]]]]],
                ...$extraChanges,
            ]],
            'generatedImages' => [['assetId' => $background, 'subject' => 'Black African adults in a professional, welcoming office setting, a different composition', 'visualDirection' => 'Soft morning light, warm neutral palette', 'placement' => 'background', 'aspectRatio' => '16:9', 'keepVisible' => 'Faces near the centre', 'alt' => '', 'title' => 'Contact hero background 2']],
        ];
    }

    private function background(array $node): ?string
    {
        return Json::entries(Json::entries(Json::entries(Json::entries($node['props'])['style'] ?? [])['root'] ?? [])['base'] ?? [])['backgroundImage']['assetId'] ?? null;
    }

    public function test_acceptance_contact_page_with_generated_hero_then_a_hero_only_revision(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'contact.test');
        [$claude, $codex] = $this->connect($f);
        $service = app(WebsiteProposalService::class);

        // An explicit request for a generated image needs the user's choice before anything runs.
        try {
            $this->ask($f, ['prompt' => self::BRIEF]);
            $this->fail('A generated-image request needs a choice');
        } catch (AiException $e) {
            $this->assertSame(AiException::IMAGE_CHOICE_REQUIRED, $e->code());
        }
        $this->assertSame(0, DB::table('ai_proposals')->count());

        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate']);
        $snapshot = $this->snapshot($request['id']);
        $this->assertSame('codex', $snapshot['images']['provider'], 'the image connection is selected explicitly, separate from text');
        $this->assertCount(2, $snapshot['images']['reserved']);
        $background = $snapshot['images']['reserved'][0];

        $this->assertSame('proposed', $this->runLayout($claude, $this->contactReply($snapshot, $background), $runner));
        $this->assertStringContainsString('If the user explicitly requests a generated image, use the available image-generation capability', $runner->requests[0]->instructions);
        $this->assertStringContainsString('gen-1', $runner->requests[0]->instructions, 'short placeholders stand for the reserved ids');
        $this->assertStringNotContainsString('Only use the supplied image library', $runner->requests[0]->instructions);

        // The layout is ready; the image is not, so the requirement is not reported as met and Apply is refused.
        $view = $this->review($f, $request['id']);
        $this->assertSame('in_progress', $view['requirements'][0]['status']);
        $this->assertSame(['generated'], array_column($view['images'], 'kind'));
        $this->assertSame('queued', $view['images'][0]['status']);
        try {
            $service->apply($f['ctx'], $request['id']);
            $this->fail('Apply must wait for the generated image');
        } catch (AiException $e) {
            $this->assertSame(AiException::IMAGES_INCOMPLETE, $e->code());
        }

        // The Codex helper generates the image; it goes through the media pipeline and stays private.
        $generator = new FakeImageGenerator;
        $this->assertSame('completed', $this->runImage($codex, $generator));
        $this->assertCount(1, $generator->calls);
        $this->assertStringContainsString('Black African adults', $generator->calls[0]['prompt']);
        $this->assertStringContainsString('No text, letters, captions, logos', $generator->calls[0]['prompt']);
        $this->assertSame('1536x1024', $generator->calls[0]['size']);
        $asset = DB::table('media_assets')->where('id', $background)->first();
        $this->assertSame('ai-generated', $asset->source);
        $provenance = Json::decode($asset->provenance);
        $this->assertSame([$request['id'], 'codex', 'background'], [$provenance['proposalId'], $provenance['provider'], $provenance['request']['placement']]);
        $this->assertGreaterThan(0, DB::table('media_variants')->where('asset_id', $background)->count(), 'responsive variants');
        $this->get('http://contact.test/media/'.$asset->storage_key)->assertNotFound();

        $view = $this->review($f, $request['id']);
        $this->assertSame('fulfilled', $view['requirements'][0]['status']);
        $this->assertSame('ready', $view['images'][0]['status']);
        $this->assertSame('First name', $view['result']['form']['definition']['fields'][0]['label']);
        // What the website is built from, for the review's block list: catalogue labels, no structural nodes.
        $blocks = array_column($view['blocks'], 'label', 'type');
        $this->assertSame(['hero' => 'Hero', 'form' => 'Form'], $blocks);
        $this->assertSame([false, false], array_column($view['blocks'], 'theme'));
        $preview = $service->preview($f['ctx'], $request['id'], 0, app(MediaSigner::class));
        $this->assertStringContainsString('Contact us', $preview);
        $this->assertStringContainsString($background, $preview, 'the private preview shows the generated image through a signed URL');
        $this->assertStringContainsString('<form', $preview);

        // "Request changes" on the unapplied proposal: only the hero background.
        [$page, $heroId, $hero] = $this->contactPage($this->stored($request['id']));
        $this->assertSame($background, $this->background($hero));
        $sections = $view['sections'][0];
        $this->assertSame(['hero', 'form'], array_column($sections, 'type'));
        $revision = $this->ask($f, ['prompt' => self::HERO_ONLY, 'images' => 'generate', 'parentId' => $request['id'], 'target' => ['scope' => 'section', 'pageId' => $page['id'], 'nodeId' => $heroId]]);
        $revSnapshot = $this->snapshot($revision['id']);
        $this->assertSame('proposal', $revSnapshot['revision']['baselineKind']);
        $this->assertSame($request['id'], DB::table('ai_proposals')->where('id', $revision['id'])->value('parent_id'));
        $this->assertContains($background, $service->allowedAssets($revSnapshot), 'the generated image is part of the baseline the revision may keep');
        $second = $revSnapshot['images']['reserved'][0];
        $this->assertSame('proposed', $this->runLayout($claude, $this->heroOnlyReply($heroId, $second), $runner));
        $this->assertStringContainsString('<baseline>', $runner->requests[0]->prompt);
        $this->assertStringContainsString('SECTION: the block with id '.$heroId.' and everything inside it may change', $runner->requests[0]->instructions);
        $this->assertStringContainsString('Contact us', $runner->requests[0]->prompt, 'the baseline is the proposal, not the saved drafts');

        $parentResult = $this->stored($request['id']);
        $revResult = $this->stored($revision['id']);
        [$revPage, , $revHero] = $this->contactPage($revResult);
        $this->assertSame($second, $this->background($revHero));
        $this->assertCount(1, $revResult['pages'], 'no duplicate pages');
        $this->assertSame($parentResult['form'], $revResult['form'], 'the form is not recreated or changed');
        $before = Json::entries($page['document']['nodes']);
        $after = Json::entries($revPage['document']['nodes']);
        $this->assertSame(array_keys($before), array_keys($after), 'no sections added or removed');
        foreach ($before as $id => $node) {
            if ($id !== $heroId) {
                $this->assertSame(WebsiteRevisions::canon($node), WebsiteRevisions::canon($after[$id]));
            }
        }
        $this->assertSame([Json::entries($hero['props'])['heading'], Json::entries($hero['props'])['text']], [Json::entries($revHero['props'])['heading'], Json::entries($revHero['props'])['text']]);
        $this->assertSame(Json::encode($parentResult), Json::encode($this->stored($request['id'])), 'the parent proposal is immutable');
        $this->assertStringContainsString($background, $service->preview($f['ctx'], $revision['id'], 0, app(MediaSigner::class), baseline: true), 'compare with the baseline');

        $this->assertSame('completed', $this->runImage($codex));
        $applied = $service->apply($f['ctx'], $revision['id']);
        $this->assertSame([], $applied['withoutImages']);
        $this->assertSame(2, DB::table('pages')->count(), 'Home and one Contact page');
        $this->assertSame(1, DB::table('site_forms')->count(), 'one form');
        $draft = Json::decode(DB::table('page_drafts')->where('page_id', $revPage['id'])->value('document'));
        $types = array_map(fn ($id) => Json::entries($draft['nodes'])[$id]['type'], $draft['nodes'][$draft['root']]['children']);
        $this->assertSame(['hero', 'form'], $types);
        $this->assertCount(5, Json::decode(DB::table('site_forms')->value('draft'))['fields']);
        $this->assertSame(0, DB::table('live_pages')->where('page_id', $revPage['id'])->count(), 'applying never publishes');

        // The parent was the baseline and is now stale: it cannot overwrite the applied revision.
        try {
            $service->apply($f['ctx'], $request['id']);
            $this->fail('The parent is stale');
        } catch (ConflictException|StaleVersionException) {
        }
        $this->assertSame(2, DB::table('pages')->count());
    }

    public function test_builder_preview_and_published_rendering_agree_on_the_generated_hero(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'agree.test');
        [$claude, $codex] = $this->connect($f);
        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate']);
        $background = $this->snapshot($request['id'])['images']['reserved'][0];
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), $background));
        $this->runImage($codex);
        $service = app(WebsiteProposalService::class);
        $preview = $service->preview($f['ctx'], $request['id'], 0, app(MediaSigner::class));
        $applied = $service->apply($f['ctx'], $request['id']);
        $pageId = $applied['pages'][0]['id'];
        $this->assertTrue(app(WebsitePublishing::class)->readiness($f['ctx'], $request['id'])['ready']);
        app(WebsitePublishing::class)->publish($f['ctx'], $request['id'], ['requestKey' => self::key()]);
        $html = DB::table('publications')->where('page_id', $pageId)->value('html');
        $this->assertNotNull($html);
        // Same markup and the same hero CSS; the preview differs only by editor attributes, disabled form
        // fields and signed (private) media URLs, which also change the hashed style class name.
        $norm = fn (string $h) => preg_replace(['# data-ak-[a-z-]+="[^"]*"#', '# disabled="disabled"#', '#ak-s[0-9a-f]{10}#', '#\?t=[^"\')]+#'], ['', '', 'ak-sX', ''], $h);
        $main = fn (string $h) => preg_match('#<main class="ak-main"[^>]*>(.*)</main>#s', $h, $m) === 1 ? $m[1] : $this->fail('no main');
        $this->assertSame($norm($main($preview)), $norm($main($html)));
        $css = fn (string $h) => preg_match_all('#\.ak-s[0-9a-f]{10}\{[^}]*\}#', $h, $m) ? array_map($norm, $m[0]) : [];
        $this->assertSame($css($preview), $css($html));
        $this->assertStringContainsString('background-size:cover;background-position:center;background-image:linear-gradient(#00000080,#00000080),url(', $html);
        // The likely LCP image: preloaded per screen with high priority, phones get a smaller variant.
        $this->assertSame(3, substr_count($html, 'rel="preload" as="image" fetchpriority="high"'));
        $this->assertMatchesRegularExpression('#media="\(max-width:599px\)" href="/media/[^"]+-w960\.webp"#', $html);
        $this->assertSame('image/webp', DB::table('media_assets')->where('id', $background)->value('mime'), 'no large PNG original is served');
        // Published now: the generated asset is public on the site's own host.
        $variant = DB::table('media_variants')->where('asset_id', $background)->orderByDesc('width')->value('storage_key');
        $this->get('http://agree.test/media/'.$variant)->assertOk();
    }

    public function test_revision_after_apply_starts_from_the_latest_saved_drafts_and_rejects_stale_baselines(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $service = app(WebsiteProposalService::class);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form with first name, last name, email, phone and message.']);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        $applied = $service->apply($f['ctx'], $request['id']);
        $pageId = $applied['pages'][0]['id'];

        // A manual edit after applying: the revision must start from it.
        $draft = DB::table('page_drafts')->where('page_id', $pageId)->first();
        $doc = Json::decode($draft->document);
        $heroId = $doc['nodes'][$doc['root']]['children'][0];
        $doc['nodes'][$heroId]['props']['text'] = 'Edited by hand after applying.';
        DB::table('page_drafts')->where('page_id', $pageId)->update(['document' => Json::encode($doc), 'version' => $draft->version + 1]);

        $sections = $this->review($f, $request['id'])['sections'][0];
        $this->assertSame($heroId, $sections[0]['id'], 'targets come from the saved draft');
        $revision = $this->ask($f, ['prompt' => 'Make the hero heading say “Get in touch”.', 'parentId' => $request['id'], 'target' => ['scope' => 'section', 'pageId' => $pageId, 'nodeId' => $heroId]]);
        $this->assertSame('drafts', $this->snapshot($revision['id'])['revision']['baselineKind']);
        $this->assertSame('proposed', $this->runLayout($claude, ['summary' => 'New heading', 'notes' => [], 'seo' => null, 'outOfScope' => null, 'generatedImages' => [],
            'proposal' => ['summary' => 'Heading', 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'update', 'change' => ['id' => $heroId, 'type' => 'hero', 'props' => ['heading' => 'Get in touch']]]]]], $runner));
        $this->assertStringContainsString('Edited by hand after applying.', $runner->requests[0]->prompt);
        $result = $this->stored($revision['id']);
        $this->assertCount(1, $result['pages']);
        $this->assertTrue($result['pages'][0]['existing']);
        $this->assertNull($result['form'], 'the form is untouched');
        $this->assertNull($this->review($f, $revision['id'])['conflict']);

        // Another manual edit while the revision waits for review: shown as a conflict, never overwritten.
        DB::table('page_drafts')->where('page_id', $pageId)->increment('version');
        $this->assertStringContainsString('edited after this proposal', $this->review($f, $revision['id'])['conflict']);
        try {
            $service->apply($f['ctx'], $revision['id']);
            $this->fail('A stale revision must not apply');
        } catch (StaleVersionException) {
        }
        $kept = Json::decode(DB::table('page_drafts')->where('page_id', $pageId)->value('document'));
        $this->assertSame('Edited by hand after applying.', $kept['nodes'][$heroId]['props']['text']);
        $this->assertSame('Contact us', $kept['nodes'][$heroId]['props']['heading']);
        $this->assertSame(2, DB::table('pages')->count());
    }

    public function test_a_section_revision_cannot_change_anything_outside_its_scope(): void
    {
        config(['arkon.ai.per_user_per_minute' => 50]);
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.']);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        [$page, $heroId] = $this->contactPage($this->stored($request['id']));
        $nodes = Json::entries($page['document']['nodes']);
        $formNode = array_values(array_filter(array_keys($nodes), fn ($id) => $nodes[$id]['type'] === 'form'))[0];
        $target = ['scope' => 'section', 'pageId' => $page['id'], 'nodeId' => $heroId];
        $heading = ['action' => 'update', 'change' => ['id' => $heroId, 'type' => 'hero', 'props' => ['heading' => 'Talk to us']]];
        $reply = fn (array $changes, ?array $seo = null, ?array $outOfScope = null) => ['summary' => 'Change', 'notes' => [], 'seo' => $seo, 'outOfScope' => $outOfScope, 'generatedImages' => [],
            'proposal' => ['summary' => 'Change', 'notes' => [], 'tokenChanges' => [], 'changes' => $changes]];

        $cases = [
            'another block' => $reply([$heading, ['action' => 'remove', 'id' => $formNode]]),
            'a new section beside it' => $reply([$heading, self::add('section', [])]),
            'the page SEO' => $reply([$heading], ['title' => 'Other', 'description' => 'Other description for this page.']),
            'declared out of scope' => $reply([], null, ['scope' => 'page', 'reason' => 'Adding an FAQ needs a new section on the page.']),
        ];
        foreach ($cases as $label => $r) {
            $revision = $this->ask($f, ['prompt' => 'Change the hero ('.$label.')', 'parentId' => $request['id'], 'target' => $target]);
            $this->assertSame('failed', $this->runLayout($claude, $r), $label);
            $row = DB::table('ai_proposals')->where('id', $revision['id'])->first();
            $this->assertSame(AiException::SCOPE_EXCEEDED, $row->error_code, $label);
            $this->assertNull($row->website_result, $label);
        }
        $this->assertStringContainsString('choose “Selected page”', DB::table('ai_proposals')->where('id', $revision['id'])->value('error_message'));
        // Within scope it passes.
        $ok = $this->ask($f, ['prompt' => 'New hero heading', 'parentId' => $request['id'], 'target' => $target]);
        $this->assertSame('proposed', $this->runLayout($claude, $reply([$heading])));
        $this->assertSame('Talk to us', Json::entries($this->contactPage($this->stored($ok['id']))[2]['props'])['heading']);
    }

    public function test_generated_image_intent_never_silently_selects_a_library_image(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $library = app(MediaService::class)->upload($f['ctx'], FakeImageGenerator::photo('640x427'), 'office.png');

        // The user chose to continue without generation; a library image is not the requested one.
        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'library']);
        $snapshot = $this->snapshot($request['id']);
        $this->assertSame([], $snapshot['images']['reserved']);
        $this->runLayout($claude, $this->contactReply($snapshot, null, $library['id']), $runner);
        $this->assertStringContainsString('Do not place a library image as a substitute', $runner->requests[0]->instructions);
        $view = $this->review($f, $request['id']);
        $this->assertSame('skipped', $view['requirements'][0]['status']);
        $this->assertSame(['library'], array_column($view['images'], 'kind'));
        $this->assertSame(0, DB::table('ai_image_jobs')->count());

        // Generation chosen, but the model placed a library image and generated nothing: not met.
        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate']);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null, $library['id']));
        $view = $this->review($f, $request['id']);
        $this->assertSame('missing', $view['requirements'][0]['status']);
        $this->assertSame('library', $view['images'][0]['kind']);
        $this->assertSame(0, DB::table('ai_image_jobs')->count());
    }

    public function test_requests_without_generation_intent_use_suitable_library_images_by_their_metadata(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $library = app(MediaService::class)->upload($f['ctx'], FakeImageGenerator::photo('640x427'), 'reception.png');
        DB::table('media_assets')->where('id', $library['id'])->update(['title' => 'Clinic reception', 'alt_text' => 'Reception desk with two staff members', 'description' => 'Bright reception area of the clinic']);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero using our reception photo and a form.']);
        $snapshot = $this->snapshot($request['id']);
        $this->assertSame('library', $snapshot['images']['mode']);
        $this->assertFalse($snapshot['images']['requested']);
        $this->assertSame('proposed', $this->runLayout($claude, $this->contactReply($snapshot, null, $library['id']), $runner));
        $this->assertStringContainsString('Bright reception area of the clinic', $runner->requests[0]->prompt, 'verified metadata is in context');
        $this->assertStringContainsString('You cannot see image pixels', $runner->requests[0]->instructions);
        $view = $this->review($f, $request['id']);
        $this->assertSame([], $view['requirements']);
        $this->assertSame([['library', 'ready']], array_map(fn ($i) => [$i['kind'], $i['status']], $view['images']));
        app(WebsiteProposalService::class)->apply($f['ctx'], $request['id']);
    }

    public function test_missing_image_capability_is_explained_before_any_model_run_and_never_switches_provider(): void
    {
        $f = $this->siteFixture();
        // Only Claude Code: it cannot generate images.
        $c = app(AiConnections::class);
        $claude = $c->create($f['siteId'], $f['ctx']->userId, 'helper', 'claude', 'claude-code');
        $c->heartbeat($claude['id'], FakeClaudeRunner::ready());
        $availability = $c->forContext($f['ctx'], 'image_generation');
        $this->assertSame('unavailable', $availability['selectionState']);
        $this->assertStringContainsString('Claude Code cannot generate images', $availability['message']);
        $this->assertSame('automatic', $c->forContext($f['ctx'], 'website_proposal')['selectionState'], 'text generation is reported separately');
        try {
            app(WebsiteProposalService::class)->request($f['ctx'], ['prompt' => self::BRIEF, 'images' => 'generate', 'requestKey' => self::key()]);
            $this->fail('No image capability');
        } catch (AiException $e) {
            $this->assertSame(AiException::IMAGE_GENERATION_UNAVAILABLE, $e->code());
            $this->assertStringContainsString('nothing was started', $e->getMessage());
        }

        // Codex connected but its image generation is off: explained, and never routed elsewhere.
        $codex = $c->create($f['siteId'], $f['ctx']->userId, 'helper', 'codex', 'codex');
        $c->heartbeat($codex['id'], (new RunnerStatus(true, null, 'Codex ready', '0.160.0', 'chatgpt'))->withImages(new RunnerStatus(false, 'IMAGE_GENERATION_DISABLED', 'Image generation through Codex is turned off in Arkon.')));
        foreach ([[], ['imageProvider' => 'codex', 'imageSelectionMode' => 'explicit'], ['imageProvider' => 'claude-code', 'imageSelectionMode' => 'explicit']] as $choice) {
            try {
                $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate', ...$choice]);
                $this->fail('Image generation is off');
            } catch (AiException $e) {
                $this->assertSame(AiException::IMAGE_GENERATION_UNAVAILABLE, $e->code());
            }
        }
        $this->assertStringContainsString('turned off in Arkon', $c->forContext($f['ctx'], 'image_generation')['providers'][1]['message']);
        $this->assertSame(0, DB::table('ai_proposals')->count());

        // The supported portion can proceed when the user chooses it.
        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'library']);
        $this->assertSame('queued', $request['status']);
    }

    public function test_image_generation_is_bound_to_its_placement_retried_only_explicitly_and_never_twice(): void
    {
        $f = $this->siteFixture();
        [$claude, $codex] = $this->connect($f);
        $service = app(WebsiteProposalService::class);
        $jobs = app(ImageJobs::class);
        $key = self::key();
        $request = $service->request($f['ctx'], ['prompt' => self::BRIEF, 'images' => 'generate', 'requestKey' => $key, 'provider' => 'claude-code', 'selectionMode' => 'explicit', 'includeLayout' => false]);
        // A lost response or repeated click replays the same request.
        $this->assertSame($request['id'], $service->request($f['ctx'], ['prompt' => self::BRIEF, 'images' => 'generate', 'requestKey' => $key, 'provider' => 'claude-code', 'selectionMode' => 'explicit', 'includeLayout' => false])['id']);
        $background = $this->snapshot($request['id'])['images']['reserved'][0];
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), $background));
        $layout = $this->stored($request['id']);

        // Generation fails: the layout is kept, the requirement is marked failed, nothing retries by itself.
        $failing = new FakeImageGenerator([new AiException('PROVIDER_LIMIT_REACHED', 'Your Codex image generation limit has been reached.')]);
        $this->assertSame('failed', $this->runImage($codex, $failing));
        $this->assertNull($jobs->claimNext($codex));
        $view = $this->review($f, $request['id']);
        $this->assertSame('failed', $view['requirements'][0]['status']);
        $this->assertSame('proposed', $view['status']);
        $this->assertSame(Json::encode($layout), Json::encode($this->stored($request['id'])), 'the completed layout is kept');
        $this->assertStringContainsString('limit has been reached', $view['images'][0]['error']);

        // An explicit image-only retry; the same key (lost response) and repeated clicks change nothing more.
        $retryKey = self::key();
        $this->assertSame('queued', $jobs->retry($f['ctx'], $background, ['requestKey' => $retryKey])['status']);
        $this->assertSame('queued', $jobs->retry($f['ctx'], $background, ['requestKey' => $retryKey])['status']);
        $this->assertSame('queued', $jobs->retry($f['ctx'], $background, ['requestKey' => self::key()])['status']);
        $this->assertSame(1, DB::table('ai_image_jobs')->count());
        $generator = new FakeImageGenerator;
        $this->assertSame('completed', $this->runImage($codex, $generator));
        $this->assertNull($jobs->claimNext($codex));
        $this->assertSame('completed', $jobs->retry($f['ctx'], $background, ['requestKey' => self::key()])['status'], 'a finished image is kept, not replaced');
        $this->assertNull($jobs->claimNext($codex));
        $this->assertCount(1, $generator->calls);
        $this->assertSame(1, DB::table('media_assets')->where('id', $background)->count());
        $this->assertSame(1, DB::table('ai_proposals')->count(), 'the layout was never generated again');

        // A crash after import but before completion finishes without generating again.
        $request2 = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate']);
        $second = $this->snapshot($request2['id'])['images']['reserved'][0];
        $this->runLayout($claude, $this->contactReply($this->snapshot($request2['id']), $second));
        $job = $jobs->claimNext($codex);
        app(MediaService::class)->importGenerated(new SiteContext($f['siteId'], $f['ctx']->userId, 'ai'), $second, FakeImageGenerator::photo('800x600'), 'x.png', ['title' => 't', 'alt' => '', 'description' => 'd', 'provenance' => []]);
        $none = new FakeImageGenerator;
        $this->assertSame('completed', $jobs->execute($job, $none, app(GeneratedImageImporter::class)));
        $this->assertSame([], $none->calls);
    }

    public function test_an_interrupted_image_fails_without_another_attempt_and_the_layout_can_apply_without_it(): void
    {
        $f = $this->siteFixture();
        [$claude, $codex] = $this->connect($f);
        $jobs = app(ImageJobs::class);
        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate']);
        $background = $this->snapshot($request['id'])['images']['reserved'][0];
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), $background));
        $job = $jobs->claimNext($codex);
        DB::table('ai_image_jobs')->where('id', $job->id)->update(['lease_expires_at' => DB::raw("now() - interval '1 second'")]);
        // The helper died; a late result from it is not admitted.
        $this->assertSame('lost', $jobs->execute($job, new FakeImageGenerator, app(GeneratedImageImporter::class)));
        $this->assertSame(0, DB::table('media_assets')->where('id', $background)->count());
        app(WebsiteProposalService::class)->list($f['ctx']);
        $row = DB::table('ai_image_jobs')->where('id', $job->id)->first();
        $this->assertSame(['failed', AiException::INTERRUPTED], [$row->status, $row->error_code]);
        $this->assertNull($jobs->claimNext($codex), 'no automatic new attempt');

        $applied = app(WebsiteProposalService::class)->apply($f['ctx'], $request['id'], ['withoutMissingImages' => true]);
        $this->assertContains($background, $applied['withoutImages']);
        $draft = Json::decode(DB::table('page_drafts')->where('page_id', $applied['pages'][0]['id'])->value('document'));
        $hero = array_values(array_filter(Json::entries($draft['nodes']), fn ($n) => $n['type'] === 'hero'))[0];
        $this->assertNull($this->background($hero), 'the missing image is left out; the rest of the layout applies');
        $this->assertSame('failed', DB::table('ai_image_jobs')->where('id', $job->id)->value('status'), 'the record of what happened is kept');
    }

    public function test_generated_files_are_validated_and_optimized_before_they_reach_the_library(): void
    {
        $f = $this->siteFixture();
        [$claude, $codex] = $this->connect($f);
        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate']);
        $background = $this->snapshot($request['id'])['images']['reserved'][0];
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), $background));

        $this->assertSame('failed', $this->runImage($codex, new FakeImageGenerator(['<svg onload="alert(1)"></svg>'])));
        $this->assertSame('IMAGE_INVALID', DB::table('ai_image_jobs')->value('error_code'));
        $this->assertSame(0, DB::table('media_assets')->count());

        app(ImageJobs::class)->retry($f['ctx'], $background, ['requestKey' => self::key()]);
        $png = FakeImageGenerator::photo();
        $this->assertSame('completed', $this->runImage($codex, new FakeImageGenerator([$png])));
        $asset = DB::table('media_assets')->where('id', $background)->first();
        if (function_exists('imagewebp')) {
            $this->assertSame('image/webp', $asset->mime, 'a PNG photograph is stored as WebP');
            $this->assertLessThan(strlen($png), (int) $asset->bytes);
        }
        $this->assertSame([1536, 1024], [(int) $asset->width, (int) $asset->height]);
        $this->assertSame('Contact hero background', $asset->title);
        $this->assertStringStartsWith('AI-generated (codex)', $asset->description);
        $this->assertGreaterThanOrEqual(3, DB::table('media_variants')->where('asset_id', $background)->count());
    }

    public function test_the_codex_helper_reports_image_readiness_separately_and_runs_image_jobs_when_idle(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $c = app(AiConnections::class);
        $pair = $c->create($f['siteId'], $f['ctx']->userId, 'helper', 'codex helper', 'codex');
        $codex = $c->resolve($pair['token'], 'helper');
        $runner = new class implements AiRunner, ImageGenerator
        {
            public FakeImageGenerator $images;

            public function __construct()
            {
                $this->images = new FakeImageGenerator;
            }

            public function check(): RunnerStatus
            {
                return new RunnerStatus(true, null, 'Codex 0.160.0, signed in with ChatGPT.', '0.160.0', 'chatgpt');
            }

            public function run(AiRequest $request, callable $keepGoing): AiCompletion
            {
                throw new \LogicException('No layout run expected');
            }

            public function imageStatus(?RunnerStatus $text = null): RunnerStatus
            {
                return $this->images->imageStatus($text);
            }

            public function generate(string $prompt, string $size, callable $keepGoing): GeneratedImage
            {
                return $this->images->generate($prompt, $size, $keepGoing);
            }
        };
        $helper = new AiHelper(app(ProposalService::class), $c, $runner);
        $this->assertNull($helper->tick($codex));
        $status = $c->helperStatus($f['siteId'], 'codex');
        $this->assertTrue($status['imageGeneration']['ready']);
        $this->assertSame(AiConnections::WEBSITE_PROTOCOL, $status['websiteProtocol']);
        $this->assertSame('automatic', $c->forContext($f['ctx'], 'image_generation')['selectionState']);

        $request = $this->ask($f, ['prompt' => self::BRIEF, 'images' => 'generate']);
        $background = $this->snapshot($request['id'])['images']['reserved'][0];
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), $background));
        $this->assertSame('image_completed', $helper->tick($codex));
        $this->assertNull($helper->tick($codex));
        $this->assertCount(1, $runner->images->calls);
        $this->assertSame('fulfilled', $this->review($f, $request['id'])['requirements'][0]['status']);
    }

    /** One formChanges entry with every other setting null (the reply format requires all keys). */
    private static function formOp(string $form, array $op): array
    {
        return [...array_fill_keys(['field', 'text', 'type', 'required', 'width', 'row', 'placeholder', 'help', 'default', 'choices', 'min', 'max', 'step', 'when', 'before'], null), 'form' => $form, ...$op];
    }

    private static function pageReply(array $extra = [], array $changes = []): array
    {
        return ['summary' => 'Change', 'notes' => [], 'seo' => null, 'outOfScope' => null, 'generatedImages' => [], 'settings' => ['title' => null, 'path' => null],
            'proposal' => ['summary' => 'Change', 'notes' => [], 'tokenChanges' => [], 'changes' => $changes], ...$extra];
    }

    /**
     * Regression (October 2026): "Change the About Us page's H1" on an applied website with a shared header and
     * footer. The revision's starting point copied the shared layout from the snapshot, where it is page-shaped for
     * editing, so the proposal was "ready" while its preview failed with "Reusable component … is invalid" (root
     * node must be a reusable component fragment).
     */
    public function test_a_heading_revision_of_an_applied_website_keeps_its_shared_layout_valid_and_previewable(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $service = app(WebsiteProposalService::class);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.', 'includeLayout' => true]);
        $this->assertSame('proposed', $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null)));
        $applied = $service->apply($f['ctx'], $request['id']);
        $pageId = collect($applied['pages'])->firstWhere('path', '/contact')['id'];
        $shared = DB::table('reusable_components')->orderBy('name')->get(['id', 'name', 'version', 'draft'])->keyBy('id');
        $this->assertCount(2, $shared, 'a shared header and footer');

        $doc = Json::decode(DB::table('page_drafts')->where('page_id', $pageId)->value('document'));
        $heroId = collect(Json::entries($doc['nodes']))->firstWhere('type', 'hero')['id'];
        $revision = $this->ask($f, ['prompt' => 'Change the Contact page H1 to “Talk to Yuna”.', 'parentId' => $request['id'], 'target' => ['scope' => 'page', 'pageId' => $pageId]]);
        $this->assertSame('drafts', $this->snapshot($revision['id'])['revision']['baselineKind']);
        $this->assertSame('proposed', $this->runLayout($claude, self::pageReply([], [['action' => 'update', 'change' => ['id' => $heroId, 'type' => 'hero', 'props' => ['heading' => 'Talk to Yuna']]]])), Json::encode($this->review($f, $revision['id'])));

        // Stored as reusable components, unchanged, and valid.
        $result = $this->stored($revision['id']);
        $validator = app(DocumentValidator::class);
        foreach ($result['shared'] as $slot => $s) {
            $this->assertSame('fragment', $s['document']['nodes'][$s['document']['root']]['type'], $slot.' is stored as a component');
            $this->assertSame([], $validator->validateFragment($s['document'], pinned: true), $slot);
            $this->assertFalse($s['changed'], 'a heading change leaves the shared '.$slot.' alone');
        }
        // Only the heading changed.
        $this->assertSame([['page' => 'Contact (/contact)', 'changes' => ['Change Hero “Contact us”: heading']]], $result['revisionChanges']);

        // After and Before both render, with the shared layout.
        $after = $service->preview($f['ctx'], $revision['id'], 0, app(MediaSigner::class));
        $this->assertStringContainsString('Talk to Yuna', $after);
        $this->assertStringContainsString('<footer', $after);
        $before = $service->preview($f['ctx'], $revision['id'], 0, app(MediaSigner::class), baseline: true);
        $this->assertStringContainsString('Contact us', $before);
        $this->assertStringContainsString('<footer', $before);

        // Applying leaves the shared components exactly as they were.
        $service->apply($f['ctx'], $revision['id']);
        foreach (DB::table('reusable_components')->get(['id', 'version', 'draft']) as $row) {
            $this->assertSame($shared[$row->id]->version, $row->version);
            $this->assertSame($shared[$row->id]->draft, $row->draft);
        }
    }

    /** A result whose shared layout is not a valid component never becomes "ready": the run fails with the validator's issues. */
    public function test_a_proposal_that_cannot_be_previewed_is_never_marked_ready(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $service = app(WebsiteProposalService::class);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.', 'includeLayout' => true]);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        $result = $this->stored($request['id']);
        $snapshot = $this->snapshot($request['id']);
        $gate = new \ReflectionMethod($service, 'assertPreviewable');
        $gate->invoke($service, $f['siteId'], $snapshot, $result);

        // The October 2026 defect: a page-shaped shared layout in a result.
        $broken = $result;
        $broken['shared']['footer']['document'] = $service->asPage($result['shared']['footer']['document']);
        try {
            $gate->invoke($service, $f['siteId'], $snapshot, $broken);
            $this->fail('A page-shaped shared layout must be refused before the proposal is ready');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('shared footer', $e->getMessage());
            $this->assertSame('Root node must be a reusable component fragment', $e->issues[0]['message']);
        }
        // A dangling child reference in a page.
        $broken = $result;
        $doc = &$broken['pages'][0]['document'];
        $doc['nodes'][$doc['root']]['children'][] = 'missingNode1';
        unset($doc);
        $this->expectException(ValidationException::class);
        $gate->invoke($service, $f['siteId'], $snapshot, $broken);
    }

    /** A preview that fails never shows the framework's error page in the canvas; the issues are named instead. */
    public function test_a_failing_preview_shows_a_safe_explanation_not_the_exception_page(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'admin.test');
        [$claude] = $this->connect($f);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.', 'includeLayout' => true]);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        $result = $this->stored($request['id']);
        $footer = &$result['shared']['footer']['document'];
        $footer['nodes'][$footer['root']]['children'][] = 'missingNode1';
        unset($footer);
        DB::table('ai_proposals')->where('id', $request['id'])->update(['website_result' => Json::encode($result)]);

        $this->withoutVite();
        $this->actingAs(User::findOrFail($f['ctx']->userId));
        $this->assertStringContainsString('RenderException', $this->get('/admin/website/'.$request['id'].'/preview/0')->getContent(), 'developers see the class in debug mode');
        config(['app.debug' => false]);
        $response = $this->get('/admin/website/'.$request['id'].'/preview/0');
        $response->assertStatus(422);
        $response->assertSee('Preview unavailable');
        $response->assertSee('Nothing was applied');
        $html = $response->getContent();
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString('PageRenderer.php', $html);
        $this->assertStringNotContainsString(PHP_VERSION, $html);
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_page_revision_puts_first_and_last_name_side_by_side_and_renames_the_button(): void
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'forms.test');
        [$claude] = $this->connect($f);
        $service = app(WebsiteProposalService::class);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.']);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        $parent = $this->stored($request['id']);
        [$page, $heroId, $hero] = $this->contactPage($parent);
        $formId = $parent['form']['id'];
        $this->assertArrayNotHasKey('schemaVersion', $parent['form']['definition'], 'a simple form keeps the script-free format');

        $revision = $this->ask($f, ['prompt' => 'I dont like how fields structure, make it like this First Name Last Name Email Phone Message then button text should be Submit Enquiry', 'parentId' => $request['id'], 'target' => ['scope' => 'page', 'pageId' => $page['id']]]);
        $snapshot = $this->snapshot($revision['id']);
        $this->assertTrue($snapshot['scope']['siteForm'], 'the form shown on the page is in scope');
        $reply = self::pageReply(['formChanges' => [
            self::formOp($formId, ['op' => 'update', 'field' => 'first_name', 'row' => 'name', 'width' => 6]),
            self::formOp($formId, ['op' => 'update', 'field' => 'last_name', 'row' => 'name', 'width' => 6]),
            self::formOp($formId, ['op' => 'submit', 'text' => 'Submit Enquiry']),
        ]]);
        $this->assertSame('proposed', $this->runLayout($claude, $reply, $runner), Json::encode($this->review($f, $revision['id'])));
        $this->assertStringContainsString('formChanges', json_encode($runner->requests[0]->schema));
        $this->assertStringContainsString('"first_name"', $runner->requests[0]->prompt, 'the model sees the form fields');
        $this->assertStringNotContainsString('notifications', $runner->requests[0]->prompt);

        $result = $this->stored($revision['id']);
        $form = $result['form']['definition'];
        $this->assertSame(2, $form['schemaVersion'], 'side-by-side fields need the current format');
        $this->assertSame(['first_name', 'last_name', 'email', 'phone', 'message'], array_column($form['fields'], 'id'), 'field ids and order are kept');
        $this->assertSame([['name', 6], ['name', 6]], array_map(fn ($x) => [$x['row'], $x['width']], array_slice($form['fields'], 0, 2)));
        $this->assertSame('Submit Enquiry', $form['submitLabel']);
        $this->assertSame(Json::encode($hero), Json::encode($this->contactPage($result)[2]), 'the hero is untouched');
        $changes = $this->review($f, $revision['id'])['result']['revisionChanges'];
        $this->assertSame('Form “Contact enquiry”', $changes[0]['page']);
        $this->assertContains('Submit button: Send message → Submit Enquiry', $changes[0]['changes']);

        $applied = $service->apply($f['ctx'], $revision['id']);
        $this->assertSame(1, DB::table('site_forms')->count(), 'no form recreated');
        $this->assertSame(2, Json::decode(DB::table('site_forms')->value('draft'))['schemaVersion']);
        $this->assertTrue(app(WebsitePublishing::class)->readiness($f['ctx'], $revision['id'])['ready']);
        app(WebsitePublishing::class)->publish($f['ctx'], $revision['id'], ['requestKey' => self::key()]);
        $html = DB::table('publications')->where('page_id', $applied['pages'][0]['id'])->value('html');
        $this->assertMatchesRegularExpression('#<div class="ak-form4__row"><div class="ak-form4__field ak-form4__field--6" data-field="first_name">.*?</div><div class="ak-form4__field ak-form4__field--6" data-field="last_name">#s', $html);
        $this->assertStringContainsString('>Submit Enquiry</button>', $html);
    }

    public function test_section_scope_covers_the_form_shown_in_it_but_not_a_form_elsewhere(): void
    {
        config(['arkon.ai.per_user_per_minute' => 50]);
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.']);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        $parent = $this->stored($request['id']);
        [$page, $heroId] = $this->contactPage($parent);
        $nodes = Json::entries($page['document']['nodes']);
        $formNode = array_values(array_filter(array_keys($nodes), fn ($id) => $nodes[$id]['type'] === 'form'))[0];
        $submit = ['formChanges' => [self::formOp($parent['form']['id'], ['op' => 'submit', 'text' => 'Submit Enquiry'])]];

        $onForm = $this->ask($f, ['prompt' => 'Rename the button', 'parentId' => $request['id'], 'target' => ['scope' => 'section', 'pageId' => $page['id'], 'nodeId' => $formNode]]);
        $this->assertSame('proposed', $this->runLayout($claude, self::pageReply($submit)));
        $this->assertSame('Submit Enquiry', $this->stored($onForm['id'])['form']['definition']['submitLabel']);

        $onHero = $this->ask($f, ['prompt' => 'Rename the form button', 'parentId' => $request['id'], 'target' => ['scope' => 'section', 'pageId' => $page['id'], 'nodeId' => $heroId]]);
        $this->assertSame([], WebsiteRevisions::formIds($this->snapshot($onHero['id'])), 'the hero shows no form');
        $this->assertSame('failed', $this->runLayout($claude, self::pageReply($submit)));
        $this->assertSame(AiException::SCOPE_EXCEEDED, DB::table('ai_proposals')->where('id', $onHero['id'])->value('error_code'));
    }

    public function test_changing_a_form_other_pages_show_is_disclosed_and_confirmed_before_applying(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $service = app(WebsiteProposalService::class);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.']);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        $applied = $service->apply($f['ctx'], $request['id']);
        $formId = $applied['form']['id'];
        $contact = $applied['pages'][0]['id'];
        // The same form placed on another page by hand.
        $doc = Json::decode(DB::table('page_drafts')->where('page_id', $contact)->value('document'));
        $this->addPage($f['siteId'], '/quote', 'Quote', $doc);

        $revision = $this->ask($f, ['prompt' => 'Button text Submit Enquiry', 'parentId' => $request['id'], 'target' => ['scope' => 'page', 'pageId' => $contact]]);
        $this->runLayout($claude, self::pageReply(['formChanges' => [self::formOp($formId, ['op' => 'submit', 'text' => 'Submit Enquiry'])]]));
        $impacts = $this->review($f, $revision['id'])['impacts'];
        $this->assertSame([['form', ['/quote']]], array_map(fn ($i) => [$i['kind'], $i['otherPages']], $impacts));
        try {
            $service->apply($f['ctx'], $revision['id']);
            $this->fail('A shared change needs confirmation');
        } catch (AiException $e) {
            $this->assertSame(AiException::SHARED_CHANGE_UNCONFIRMED, $e->code());
            $this->assertStringContainsString('/quote', $e->getMessage());
        }
        $service->apply($f['ctx'], $revision['id'], ['confirmShared' => true]);
        $this->assertSame('Submit Enquiry', Json::decode(DB::table('site_forms')->where('id', $formId)->value('draft'))['submitLabel']);
    }

    public function test_a_page_revision_can_change_its_url_and_the_shared_header_with_the_impact_shown(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $service = app(WebsiteProposalService::class);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form, with a shared header.', 'includeLayout' => true]);
        $snapshot = $this->snapshot($request['id']);
        $this->runLayout($claude, [...$this->contactReply($snapshot, null), 'header' => ['summary' => 'Header', 'notes' => [], 'tokenChanges' => [], 'changes' => [self::add('group', ['element' => 'header'], 'page', 'h'), self::add('text', ['text' => 'Garden Studio', 'element' => 'p'], 'new:h')]]]);
        $applied = $service->apply($f['ctx'], $request['id']);
        $contact = collect($applied['pages'])->firstWhere('path', '/contact')['id'];
        $header = $applied['shared']['header']['id'];
        $headerDoc = Json::decode(DB::table('reusable_components')->where('id', $header)->value('draft'));
        $textId = collect(Json::entries($headerDoc['nodes']))->firstWhere('type', 'text')['id'];
        // Another page that shows the same shared header.
        $this->addPage($f['siteId'], '/quote', 'Quote', Json::decode(DB::table('page_drafts')->where('page_id', $contact)->value('document')));

        $revision = $this->ask($f, ['prompt' => 'Move this page to /get-in-touch and make the header say Garden Studio Ltd', 'parentId' => $request['id'], 'target' => ['scope' => 'page', 'pageId' => $contact]]);
        $this->assertContains($header, WebsiteRevisions::componentIds($this->snapshot($revision['id'])), 'the header shown on the page is in scope');
        $this->assertSame('proposed', $this->runLayout($claude, self::pageReply(['settings' => ['title' => null, 'path' => '/get-in-touch'], 'components' => [['id' => $header, 'proposal' => ['summary' => 'Header', 'notes' => [], 'tokenChanges' => [], 'changes' => [['action' => 'update', 'change' => ['id' => $textId, 'type' => 'text', 'props' => ['text' => 'Garden Studio Ltd']]]]]]]])), Json::encode($this->review($f, $revision['id'])));
        $view = $this->review($f, $revision['id']);
        $this->assertSame('/get-in-touch', collect($view['result']['pages'])->firstWhere('id', $contact)['path']);
        $this->assertStringContainsString('the old URL redirects to the new one', Json::encode($view['result']['revisionChanges']));
        $this->assertContains('the shared header', array_column($view['impacts'], 'name'));
        $this->assertSame(['/quote'], collect($view['impacts'])->firstWhere('name', 'the shared header')['otherPages'], 'the other page showing the header');
        $service->apply($f['ctx'], $revision['id'], ['confirmShared' => true]);
        $this->assertSame('/get-in-touch', DB::table('pages')->where('id', $contact)->value('path'));
        $this->assertStringContainsString('Garden Studio Ltd', DB::table('reusable_components')->where('id', $header)->value('draft'));
        $this->assertSame(3, DB::table('pages')->count());
    }

    public function test_form_edits_follow_the_form_editor_rules(): void
    {
        $base = ['name' => 'Contact', 'submitLabel' => 'Send', 'successMessage' => 'Thanks.', 'fields' => [['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]];
        $op = fn (array $o) => self::formOp('f', $o);
        $simple = FormChanges::apply($base, [$op(['op' => 'add', 'field' => 'message', 'type' => 'textarea', 'text' => 'Message', 'required' => true])]);
        $this->assertArrayNotHasKey('schemaVersion', $simple['definition'], 'nothing newer used: the script-free format is kept');
        $modern = FormChanges::apply($base, [$op(['op' => 'add', 'field' => 'date', 'type' => 'date', 'text' => 'Preferred date', 'before' => 'email']), $op(['op' => 'update', 'field' => 'email', 'placeholder' => 'you@example.com'])]);
        $this->assertSame(['date', 'email'], array_column($modern['definition']['fields'], 'id'));
        $this->assertSame(2, $modern['definition']['schemaVersion']);
        foreach ([
            [$op(['op' => 'update', 'field' => 'nope', 'text' => 'X'])],
            [$op(['op' => 'add', 'field' => 'email', 'type' => 'text', 'text' => 'Again'])],
            [$op(['op' => 'redirect', 'text' => 'javascript:alert(1)'])],
            [$op(['op' => 'update', 'field' => 'email', 'width' => 5])],
        ] as $bad) {
            try {
                FormChanges::apply($base, $bad);
                $this->fail(Json::encode($bad));
            } catch (ValidationException) {
            }
        }
    }

    public function test_revisions_keep_ownership_and_site_isolation(): void
    {
        $f = $this->siteFixture();
        [$claude] = $this->connect($f);
        $request = $this->ask($f, ['prompt' => 'Build a contact page with a hero and a form.']);
        $this->runLayout($claude, $this->contactReply($this->snapshot($request['id']), null));
        [$page, $heroId] = $this->contactPage($this->stored($request['id']));
        $other = $this->addMember($f['siteId'], 'editor');
        $c = app(AiConnections::class);
        $p = $c->create($f['siteId'], $other->userId, 'helper', 'other', 'claude-code');
        $c->heartbeat($p['id'], FakeClaudeRunner::ready());
        $this->expectException(NotFoundException::class);
        app(WebsiteProposalService::class)->request($other, ['prompt' => 'Change it', 'requestKey' => self::key(), 'parentId' => $request['id'], 'target' => ['scope' => 'section', 'pageId' => $page['id'], 'nodeId' => $heroId]]);
    }
}
