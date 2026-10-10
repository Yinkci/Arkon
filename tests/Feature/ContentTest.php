<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentDetails;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\TermService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** Posts are pages of kind "post": the same drafts, revisions, publishing and Trash, plus details and terms. */
class ContentTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function items(): ContentItems
    {
        return app(ContentItems::class);
    }

    public function test_a_post_is_a_normal_page_with_its_own_kind_and_stays_out_of_the_pages_list(): void
    {
        $post = $this->items()->create($this->f['ctx'], 'post', ['title' => 'Laravel performance']);
        $row = DB::table('pages')->where('id', $post['id'])->first();
        $this->assertSame('post', $row->kind);
        $this->assertSame('/blog/laravel-performance', $row->path);
        $this->assertSame('Created post', DB::table('page_revisions')->where('page_id', $post['id'])->value('message'));

        $pages = app(PageService::class)->listPages($this->f['ctx']);
        $this->assertSame([$this->f['pageId']], array_column($pages, 'id'));
        $this->assertSame([$post['id']], array_column(app(PageService::class)->listPages($this->f['ctx'], 'post'), 'id'));

        // A second post with the same title gets the next free URL.
        $again = $this->items()->create($this->f['ctx'], 'post', ['title' => 'Laravel performance']);
        $this->assertSame('/blog/laravel-performance-2', DB::table('pages')->where('id', $again['id'])->value('path'));
    }

    public function test_content_blocks_become_native_editable_blocks_in_sections(): void
    {
        $post = $this->items()->create($this->f['ctx'], 'post', ['title' => 'Guide', 'blocks' => [
            ['type' => 'paragraph', 'text' => 'Intro'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Caching'],
            ['type' => 'paragraph', 'text' => 'Use the cache.'],
            ['type' => 'button', 'label' => 'Read more', 'href' => '/docs'],
        ]]);
        $doc = Json::decode(DB::table('page_drafts')->where('page_id', $post['id'])->value('document'));
        $root = $doc['nodes'][$doc['root']];
        $this->assertCount(2, $root['children'], 'a new section starts at the level-2 heading');
        $types = array_map(fn ($id) => [$doc['nodes'][$id]['type'], $doc['nodes'][$id]['props']['element'] ?? null], $doc['nodes'][$root['children'][0]]['children']);
        $this->assertSame([['text', 'h1'], ['text', 'p']], $types, 'the title becomes the main heading');
        $this->assertSame(1, DB::table('page_revisions')->where('page_id', $post['id'])->count(), 'created with its content in one revision');

        $this->expectException(ValidationException::class);
        $this->items()->create($this->f['ctx'], 'post', ['title' => 'Bad', 'blocks' => [['type' => 'script', 'text' => 'x']]]);
    }

    public function test_details_are_draft_until_published_and_then_recorded_with_the_publication(): void
    {
        $image = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'cover.png');
        $category = app(TermService::class)->create($this->f['ctx'], 'category', ['name' => 'Engineering']);
        $tag = app(TermService::class)->create($this->f['ctx'], 'tag', ['name' => 'Laravel']);
        $post = $this->items()->create($this->f['ctx'], 'post', ['title' => 'Speed', 'excerpt' => 'How to be fast', 'featuredMediaId' => $image['id'], 'terms' => ['category' => [$category['id']], 'tag' => [$tag['id']]]]);
        $this->assertNull(DB::table('pages')->where('id', $post['id'])->value('first_published_at'));
        $this->assertSame(0, app(TermService::class)->find($this->f['siteId'], 'category', $category['id'])['count'], 'drafts are not counted as published');
        $this->assertSame(1, app(TermService::class)->find($this->f['siteId'], 'category', $category['id'])['draftCount']);

        $this->items()->update($this->f['ctx'], 'post', $post['id'], ['status' => 'published']);
        $meta = ContentDetails::fromPublication(DB::table('publications')->where('page_id', $post['id'])->value('content_meta'));
        $this->assertSame(['kind' => 'post', 'excerpt' => 'How to be fast', 'featuredMediaId' => $image['id'], 'terms' => [$category['id'], $tag['id']]], [...$meta, 'terms' => $meta['terms']]);
        $first = DB::table('pages')->where('id', $post['id'])->value('first_published_at');
        $this->assertNotNull($first);
        $this->assertTrue(DB::table('publication_media')->where('asset_id', $image['id'])->exists(), 'the featured image becomes public with the post');
        $this->assertSame(1, app(TermService::class)->find($this->f['siteId'], 'category', $category['id'])['count']);

        // A draft change of the excerpt does not reach the live record until the next publish.
        app(ContentDetails::class)->update($this->f['ctx'], $post['id'], ['excerpt' => 'Changed']);
        $this->assertSame('How to be fast', ContentDetails::fromPublication(DB::table('publications')->where('page_id', $post['id'])->orderByDesc('created_at')->value('content_meta'))['excerpt']);
        $this->items()->update($this->f['ctx'], 'post', $post['id'], ['status' => 'published']);
        $this->assertSame('Changed', ContentDetails::fromPublication(DB::table('publications')->where('page_id', $post['id'])->orderByDesc('created_at')->value('content_meta'))['excerpt']);
        $this->assertSame($first, DB::table('pages')->where('id', $post['id'])->value('first_published_at'), 'the published date stays the first one');
    }

    public function test_pages_have_no_post_details_and_terms_must_belong_to_the_site_and_taxonomy(): void
    {
        $this->expectException(ValidationException::class);
        app(ContentDetails::class)->update($this->f['ctx'], $this->f['pageId'], ['excerpt' => 'x']);
    }

    public function test_a_term_of_another_site_or_taxonomy_is_refused(): void
    {
        $other = $this->siteFixture();
        $foreign = app(TermService::class)->create($other['ctx'], 'category', ['name' => 'Elsewhere']);
        $tag = app(TermService::class)->create($this->f['ctx'], 'tag', ['name' => 'Laravel']);
        $post = $this->items()->create($this->f['ctx'], 'post', ['title' => 'Speed']);
        foreach ([['category' => [$foreign['id']]], ['category' => [$tag['id']]]] as $terms) {
            try {
                app(ContentDetails::class)->update($this->f['ctx'], $post['id'], ['terms' => $terms]);
                $this->fail('accepted a term that is not a category of this site');
            } catch (ValidationException) {
                $this->assertSame(0, DB::table('page_terms')->where('page_id', $post['id'])->count());
            }
        }
    }

    public function test_a_request_key_replays_the_same_post_and_refuses_other_content(): void
    {
        $input = ['title' => 'Once', 'blocks' => [['type' => 'paragraph', 'text' => 'A']], 'requestKey' => self::key()];
        $first = $this->items()->create($this->f['ctx'], 'post', $input);
        $again = $this->items()->create($this->f['ctx'], 'post', $input);
        $this->assertSame($first['id'], $again['id']);
        $this->assertTrue($again['replayed']);
        $this->assertSame(1, DB::table('pages')->where('kind', 'post')->count());

        $this->expectException(ConflictException::class);
        $this->items()->create($this->f['ctx'], 'post', [...$input, 'blocks' => [['type' => 'paragraph', 'text' => 'B']]]);
    }

    public function test_an_editor_cannot_publish_and_create_and_publish_creates_nothing(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        try {
            $this->items()->create($editor, 'post', ['title' => 'Publish me', 'status' => 'published']);
            $this->fail('an editor published');
        } catch (ForbiddenException) {
            $this->assertSame(0, DB::table('pages')->where('kind', 'post')->count(), 'the whole request was rolled back');
        }
        $draft = $this->items()->create($editor, 'post', ['title' => 'Draft only']);
        $this->assertNull(DB::table('live_pages')->where('page_id', $draft['id'])->first());
    }

    public function test_terms_nest_without_cycles_and_deleting_one_moves_children_up(): void
    {
        $terms = app(TermService::class);
        $a = $terms->create($this->f['ctx'], 'category', ['name' => 'A']);
        $b = $terms->create($this->f['ctx'], 'category', ['name' => 'B', 'parentId' => $a['id']]);
        $c = $terms->create($this->f['ctx'], 'category', ['name' => 'C', 'parentId' => $b['id']]);
        try {
            $terms->update($this->f['ctx'], 'category', $a['id'], ['parentId' => $c['id']]);
            $this->fail('created a cycle');
        } catch (ValidationException) {
        }
        $terms->delete($this->f['ctx'], 'category', $b['id']);
        $this->assertSame($a['id'], $terms->find($this->f['siteId'], 'category', $c['id'])['parentId']);
        $this->assertSame('a-2', $terms->create($this->f['ctx'], 'category', ['name' => 'A'])['slug'], 'names may repeat; slugs stay unique');

        $this->expectException(ValidationException::class);
        $terms->create($this->f['ctx'], 'tag', ['name' => 'Nested tag', 'parentId' => $a['id']]);
    }

    public function test_editors_add_terms_but_only_owners_and_admins_rename_or_delete_them(): void
    {
        $editor = $this->addMember($this->f['siteId'], 'editor');
        $term = app(TermService::class)->create($editor, 'tag', ['name' => 'php']);
        $this->expectException(ForbiddenException::class);
        app(TermService::class)->update($editor, 'tag', $term['id'], ['name' => 'PHP']);
    }

    public function test_trash_and_restore_work_for_posts_like_pages(): void
    {
        $post = $this->items()->create($this->f['ctx'], 'post', ['title' => 'Gone soon', 'status' => 'published']);
        $this->items()->trash($this->f['ctx'], 'post', $post['id']);
        $this->assertNull(DB::table('live_pages')->where('page_id', $post['id'])->first());
        $this->assertSame([$post['id']], array_column(app(PageManagement::class)->trash($this->f['ctx'], 'post'), 'id'));
        $this->assertSame([], app(PageManagement::class)->trash($this->f['ctx']), 'the pages Trash lists pages only');
        app(PageManagement::class)->restore($this->f['ctx'], $post['id']);
        $this->assertSame([$post['id']], array_column(app(PageService::class)->listPages($this->f['ctx'], 'post'), 'id'));
    }
}
