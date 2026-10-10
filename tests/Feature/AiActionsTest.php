<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\Mcp\McpServer;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Content\ContentItems;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Media\MediaService;
use App\Arkon\Pages\PageService;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * AI through approved actions: capability discovery, AI-created posts and pages that are normal
 * drafts made by the same services as the admin and the API, permissions, validation, targeted
 * edits through reviewed proposals, and the website reference flow.
 */
class AiActionsTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'clinic.test');
    }

    private function tool(string $name, array $arguments = [], ?SiteContext $as = null): array
    {
        $ctx = $as ?? $this->f['ctx'];
        $token = app(AiConnections::class)->create($ctx->siteId, $ctx->userId, 'mcp', 'Claude Code')['token'];
        $result = app()->make(McpServer::class, ['token' => $token])->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])['result'];

        return ['error' => $result['isError'], 'data' => json_decode($result['content'][0]['text'], true)];
    }

    public function test_capabilities_describe_what_the_site_supports_for_this_member(): void
    {
        $caps = $this->tool('arkon_get_capabilities')['data'];
        $this->assertSame(['page', 'post'], array_column($caps['contentTypes'], 'type'));
        $this->assertSame(['category', 'tag'], array_column($caps['taxonomies'], 'taxonomy'));
        $types = array_column($caps['components'], 'type');
        foreach (['section', 'text', 'image', 'button', 'columns', 'form', 'navigation'] as $type) {
            $this->assertContains($type, $types, 'registered components only');
        }
        $this->assertSame(['allow' => ['column'], 'max' => 6], collect($caps['components'])->firstWhere('type', 'columns')['children']);
        $this->assertContains('primary', $caps['designTokens']['color']);
        $this->assertTrue(collect($caps['actions'])->firstWhere('name', 'arkon_create_post')['allowed']);
        $this->assertNotEmpty($caps['rules']);
        $this->assertLessThan(20000, strlen(json_encode($caps)), 'a compact map, not the whole catalogue');

        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $this->assertFalse(collect($this->tool('arkon_get_capabilities', [], $viewer)['data']['actions'])->firstWhere('name', 'arkon_create_post')['allowed']);
    }

    /** Reference: "Create a blog post about improving Laravel application performance." */
    public function test_ai_blog_post_is_a_normal_draft_post_with_seo_from_the_analyzer(): void
    {
        $cover = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'servers.png');
        $found = $this->tool('arkon_search_media', ['search' => 'servers'])['data'];
        $this->assertSame([$cover['id']], array_column($found['items'], 'id'));
        $arguments = [
            'title' => 'Improving Laravel application performance', 'excerpt' => 'Practical steps to make a Laravel app faster.',
            'categories' => ['Engineering'], 'tags' => ['Laravel', 'Performance'], 'featuredMediaId' => $cover['id'],
            'seo' => ['title' => 'Improving Laravel performance', 'description' => 'Caching, queries, queues and profiling: practical steps to make a Laravel application faster.'],
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Slow pages cost users. These steps make a Laravel application faster.'],
                ['type' => 'heading', 'level' => 2, 'text' => 'Cache configuration and routes'],
                ['type' => 'paragraph', 'text' => 'Run config:cache and route:cache in production.'],
                ['type' => 'heading', 'level' => 2, 'text' => 'Avoid N+1 queries'],
                ['type' => 'paragraph', 'text' => 'Load relations eagerly and watch the query count.'],
            ],
            'requestKey' => 'claude-blog-request-0001',
        ];
        $result = $this->tool('arkon_create_post', $arguments);
        $this->assertFalse($result['error'], json_encode($result['data']));
        $created = $result['data'];
        $this->assertTrue($created['success']);
        $this->assertEquals(['type' => 'post', 'status' => 'draft', 'path' => '/blog/improving-laravel-application-performance'], array_intersect_key($created['resource'], array_flip(['type', 'status', 'path'])));
        $this->assertIsInt($created['seo']['score'], 'the score comes from the analyzer');
        $id = $created['resource']['id'];

        // A normal post: same table, same draft and revision model, source "ai", nothing live.
        $this->assertSame('post', DB::table('pages')->where('id', $id)->value('kind'));
        $this->assertSame('ai', DB::table('page_revisions')->where('page_id', $id)->value('source'));
        $this->assertSame('ai', DB::table('audit_logs')->where('target_id', $id)->where('action', 'page.create')->value('actor_via'));
        $this->assertNull(DB::table('live_pages')->where('page_id', $id)->first());
        $this->assertSame(['Engineering', 'Laravel', 'Performance'], DB::table('page_terms as pt')->join('terms as t', 't.id', '=', 'pt.term_id')->where('pt.page_id', $id)->orderBy('t.name')->pluck('t.name')->all());
        $this->assertSame([], app(DocumentValidator::class)->validate(Json::decode(DB::table('page_drafts')->where('page_id', $id)->value('document'))));
        $this->assertSame([$id], array_column(app(PageService::class)->listPages($this->f['ctx'], 'post'), 'id'), 'it appears in the Posts admin');
        $this->assertSame([], $this->getJson('http://clinic.test/api/v1/posts')->json('data'), 'not public until a person publishes it');

        // Retrying the same request returns the same post.
        $again = $this->tool('arkon_create_post', $arguments)['data'];
        $this->assertSame([$id, true], [$again['resource']['id'], $again['replayed']]);

        // A person publishes it; it is then in the public API like any other post.
        app(ContentItems::class)->update($this->f['ctx'], 'post', $id, ['status' => 'published']);
        $public = $this->getJson('http://clinic.test/api/v1/posts/'.$id)->assertOk()->json('data');
        $this->assertSame('Improving Laravel application performance', $public['title']);
        $this->assertSame('Improving Laravel performance', $public['seo']['title']);
        $this->assertStringContainsString('Avoid N+1 queries', $public['content']['rendered']);
        $this->assertSame($cover['id'], $public['featured_media']['id']);
    }

    public function test_ai_cannot_publish_or_act_beyond_the_role(): void
    {
        // There is no publish action, and create actions take no status.
        $this->assertTrue($this->tool('arkon_create_post', ['title' => 'Publish me', 'status' => 'published', 'blocks' => [['type' => 'paragraph', 'text' => 'x']], 'requestKey' => 'claude-blog-request-0002'])['error']);
        $this->assertSame(0, DB::table('pages')->where('kind', 'post')->count());

        $viewer = $this->addMember($this->f['siteId'], 'viewer');
        $refused = $this->tool('arkon_create_post', ['title' => 'By a viewer', 'blocks' => [['type' => 'paragraph', 'text' => 'x']], 'requestKey' => 'claude-blog-request-0003'], $viewer);
        $this->assertSame('FORBIDDEN', $refused['data']['code']);

        $editor = $this->addMember($this->f['siteId'], 'editor');
        $draft = $this->tool('arkon_create_post', ['title' => 'By an editor', 'blocks' => [['type' => 'paragraph', 'text' => 'x']], 'requestKey' => 'claude-blog-request-0004'], $editor);
        $this->assertSame('draft', $draft['data']['resource']['status']);
        $names = array_column(app(McpServer::class, ['token' => null])->tools(), 'name');
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/publish|apply|sql|shell|exec|file|delete|trash/i', $name);
        }
    }

    public function test_invalid_ai_output_is_refused_with_a_path_and_nothing_is_created(): void
    {
        $cases = [
            [['type' => 'html', 'text' => '<script>alert(1)</script>']],
            [['type' => 'button', 'label' => 'Go', 'href' => 'javascript:alert(1)']],
            [['type' => 'image', 'media_id' => '01a11c9a-1198-705e-87be-06da3e33ad58', 'alt' => 'Invented image']],
            [['type' => 'heading', 'level' => 7, 'text' => 'Too deep']],
        ];
        foreach ($cases as $i => $blocks) {
            $result = $this->tool('arkon_create_post', ['title' => 'Bad '.$i, 'blocks' => $blocks, 'requestKey' => 'claude-invalid-request-000'.$i]);
            $this->assertTrue($result['error']);
            $this->assertSame('VALIDATION', $result['data']['code'], json_encode($result['data']));
        }
        $this->assertSame(0, DB::table('pages')->where('kind', 'post')->count());
    }

    /** Reference: "Add a testimonials section below Services on the homepage." A targeted, reviewed edit. */
    public function test_a_targeted_edit_inserts_one_section_and_preserves_everything_else(): void
    {
        $home = app(ContentItems::class)->create($this->f['ctx'], 'page', ['title' => 'Bright Smile Dental', 'path' => '/home', 'blocks' => [
            ['type' => 'paragraph', 'text' => 'Gentle family dentistry.'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Services'], ['type' => 'paragraph', 'text' => 'Check-ups and cleaning.'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Contact'], ['type' => 'paragraph', 'text' => 'Book a visit.'],
        ]])['id'];
        $before = Json::decode(DB::table('page_drafts')->where('page_id', $home)->value('document'));
        [$intro, $services, $contact] = $before['nodes'][$before['root']]['children'];

        $page = $this->tool('arkon_get_page', ['pageId' => $home])['data'];
        $submitted = $this->tool('arkon_submit_proposal', ['pageId' => $home, 'baseVersion' => $page['draftVersion'], 'request' => 'Add a testimonials section below Services on the homepage.', 'requestKey' => 'claude-edit-request-0001', 'proposal' => [
            'summary' => 'Testimonials section after Services', 'notes' => ['The quote is a placeholder: replace it with a real patient testimonial before publishing.'], 'tokenChanges' => [],
            'changes' => [
                ['action' => 'add', 'parent' => 'page', 'index' => 2, 'ref' => 'testimonials', 'block' => ['type' => 'section', 'props' => ['anchor' => 'testimonials']]],
                ['action' => 'add', 'parent' => 'new:testimonials', 'index' => null, 'block' => ['type' => 'text', 'props' => ['text' => 'What patients say', 'element' => 'h2']]],
                ['action' => 'add', 'parent' => 'new:testimonials', 'index' => null, 'block' => ['type' => 'text', 'props' => ['text' => 'Placeholder testimonial: replace with a real patient quote.', 'element' => 'p']]],
            ],
        ]]);
        $this->assertFalse($submitted['error'], json_encode($submitted['data']));
        $this->assertEquals($before, Json::decode(DB::table('page_drafts')->where('page_id', $home)->value('document')), 'nothing changes until a person applies it');

        $view = app(ProposalService::class)->status($this->f['ctx'], $home, $submitted['data']['proposalId']);
        app(PageService::class)->saveDraft($this->f['ctx'], ['pageId' => $home, 'baseVersion' => $page['draftVersion'], 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($view['proposal']['operations'])), 'proposalId' => $view['id']]);
        $after = Json::decode(DB::table('page_drafts')->where('page_id', $home)->value('document'));
        $children = $after['nodes'][$after['root']]['children'];
        $this->assertCount(4, $children);
        $this->assertSame([$intro, $services], array_slice($children, 0, 2));
        $this->assertSame($contact, $children[3], 'the new section sits between Services and Contact');
        foreach ([$intro, $services, $contact] as $id) {
            $this->assertEquals($before['nodes'][$id], $after['nodes'][$id], 'unrelated sections are untouched');
        }
        $this->assertSame([], app(DocumentValidator::class)->validate($after));
        $this->assertSame('ai', DB::table('page_revisions')->where('page_id', $home)->orderByDesc('number')->value('source'), 'one AI revision, revertible from History');
    }

    /** Reference: "Create a website for a local dental clinic with Home, About, Services and Contact pages." */
    public function test_dental_clinic_website_is_four_draft_pages_with_navigation_and_an_embedded_contact_form(): void
    {
        $context = $this->tool('arkon_get_website_context')['data'];
        $submitted = $this->tool('arkon_submit_website_proposal', ['contextId' => $context['contextId'], 'prompt' => 'Create a website for a local dental clinic with Home, About, Services and Contact pages.', 'requestKey' => 'claude-site-request-0001', 'proposal' => $this->dentalProposal($context['context'])]);
        $this->assertFalse($submitted['error'], json_encode($submitted['data']));
        $this->assertSame(1, DB::table('pages')->count(), 'proposed, not applied');

        $applied = app(WebsiteProposalService::class)->apply($this->f['ctx'], $submitted['data']['id']);
        $this->assertSame(['/', '/about', '/services', '/contact'], array_column($applied['pages'], 'path'));
        $this->assertSame(0, DB::table('live_pages')->count(), 'everything stays draft');
        $validator = app(DocumentValidator::class);
        foreach ($applied['pages'] as $p) {
            $doc = Json::decode(DB::table('page_drafts')->where('page_id', $p['id'])->value('document'));
            $this->assertSame([], $validator->validate($doc), $p['path'].' is a valid builder document');
            $this->assertNotEmpty($doc['seo']['title']);
            $this->assertNotEmpty($doc['seo']['description']);
            $this->assertSame('ai', DB::table('page_revisions')->where('page_id', $p['id'])->orderByDesc('number')->value('source'));
        }
        // Navigation is a normal menu pointing at the real pages, created after them.
        $menu = Json::decode(DB::table('site_menus')->where('site_id', $this->f['siteId'])->value('draft'));
        $this->assertSame(array_column($applied['pages'], 'id'), array_column($menu['items'], 'pageId'));
        // The contact form is a normal form, embedded by id on the Contact page.
        $form = DB::table('site_forms')->where('site_id', $this->f['siteId'])->first();
        $this->assertSame('Appointment request', $form->name);
        $contact = DB::table('page_drafts')->where('page_id', $applied['pages'][3]['id'])->value('document');
        $this->assertStringContainsString($form->id, $contact);
        $this->assertSame('ai', DB::table('audit_logs')->where('target_id', $form->id)->where('action', 'form.draft.save')->value('actor_via'), 'the form went through the Forms service');
    }

    public function test_an_editor_cannot_set_form_notifications_through_a_website_proposal(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $context = $this->tool('arkon_get_website_context', [], $editor)['data'];
        $submitted = $this->tool('arkon_submit_website_proposal', ['contextId' => $context['contextId'], 'prompt' => 'Dental site', 'requestKey' => 'claude-site-request-0002', 'proposal' => $this->dentalProposal($context['context'])], $editor);
        $this->assertFalse($submitted['error'], json_encode($submitted['data']));
        // A recipient smuggled into the stored result (what the old direct write accepted).
        $result = Json::decode(DB::table('ai_proposals')->where('id', $submitted['data']['id'])->value('website_result'));
        $result['form']['definition']['notifications'] = [['id' => 'leak', 'recipient' => 'attacker@example.com']];
        DB::table('ai_proposals')->where('id', $submitted['data']['id'])->update(['website_result' => Json::encode($result)]);

        try {
            app(WebsiteProposalService::class)->apply($editor, $submitted['data']['id']);
            $this->fail('an editor configured notifications');
        } catch (ForbiddenException) {
            $this->assertSame(0, DB::table('site_forms')->count(), 'nothing was applied');
            $this->assertSame(1, DB::table('pages')->count());
        }
    }

    private function dentalProposal(array $context): array
    {
        $changes = fn (array $c) => ['summary' => 'Page content', 'notes' => [], 'tokenChanges' => [], 'changes' => $c];
        $add = fn ($type, $props, $parent = 'page', $ref = null) => ['action' => 'add', 'parent' => $parent, 'index' => null, 'ref' => $ref, 'block' => ['type' => $type, 'props' => $props]];
        $pages = [];
        foreach (['Home' => '/', 'About' => '/about', 'Services' => '/services', 'Contact' => '/contact'] as $title => $path) {
            $c = $path === '/' ? [['action' => 'remove', 'id' => $this->f['heroId']]] : [];
            $c[] = $add('hero', ['heading' => $title === 'Home' ? 'Gentle dental care for the whole family' : $title, 'text' => 'A local dental clinic focused on comfortable, preventive care.']);
            $c[] = $add('section', ['anchor' => strtolower($title).'-intro'], 'page', 'intro');
            $c[] = $add('text', ['text' => 'Add your clinic\'s details here.', 'element' => 'p'], 'new:intro');
            if ($path === '/contact') {
                $c[] = $add('form', ['form' => ['id' => $context['form']['id']]]);
            }
            $pages[] = ['pageId' => $path === '/' ? $this->f['pageId'] : null, 'title' => $title, 'path' => $path, 'seo' => ['title' => $title.' | Bright Smile Dental', 'description' => 'Bright Smile Dental: gentle, preventive dental care for families. '.$title.'.'], 'proposal' => $changes($c)];
        }
        $items = array_map(fn ($p) => ['id' => strtolower($p['title']), 'label' => $p['title'], 'type' => 'page', 'pagePath' => $p['path'], 'href' => '', 'anchor' => '', 'parentId' => null], $pages);

        return [
            'summary' => 'Four-page dental clinic website', 'pages' => $pages,
            'header' => $changes([$add('group', ['element' => 'header'], 'page', 'header'), $add('text', ['text' => 'Bright Smile Dental', 'element' => 'p'], 'new:header')]),
            'footer' => $changes([$add('group', ['element' => 'footer'], 'page', 'footer'), $add('text', ['text' => 'Bright Smile Dental', 'element' => 'p'], 'new:footer')]),
            'menu' => ['name' => 'Main navigation', 'items' => $items],
            'form' => ['name' => 'Appointment request', 'submitLabel' => 'Request an appointment', 'successMessage' => 'Thank you. We will contact you to confirm a time.', 'fields' => [
                ['id' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'options' => []], ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'options' => []],
                ['id' => 'message', 'label' => 'How can we help?', 'type' => 'textarea', 'required' => true, 'options' => []],
            ]],
            'tokenChanges' => [],
        ];
    }
}
