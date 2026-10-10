<?php

namespace Tests\Feature;

use App\Arkon\Forms\FormManagement;
use App\Arkon\Forms\FormService;
use App\Arkon\Media\MediaLibrary;
use App\Arkon\Media\MediaService;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/** Trash, Restore and Delete permanently for pages, forms and media, through the shared bulk endpoints. */
final class TrashTest extends DatabaseTestCase
{
    private array $f;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->owner = User::findOrFail($this->f['ctx']->userId);
    }

    private function bulk(string $resource, string $action, array $items, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->postJson("/admin/api/{$resource}/bulk", ['action' => $action, 'items' => $items]);
    }

    public function test_pages_move_to_trash_restore_with_the_same_identity_and_purge_only_from_the_trash(): void
    {
        $pages = app(PageService::class);
        $pages->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => self::key()]);
        $about = $this->addPage($this->f['siteId'], '/about', 'About us');
        $contact = $this->addPage($this->f['siteId'], '/contact', 'Contact');

        // One stale version fails on its own; the others move. The live page goes offline.
        $this->bulk('pages', 'trash', [['id' => $this->f['pageId'], 'version' => 1], ['id' => $about, 'version' => 1], ['id' => $contact, 'version' => 9]])
            ->assertOk()->assertJsonPath('data.done', [$this->f['pageId'], $about])->assertJsonCount(1, 'data.failed')->assertJsonPath('data.failed.0.id', $contact);
        $this->assertSame(0, DB::table('live_pages')->where('page_id', $this->f['pageId'])->count());
        $list = array_column($pages->listPages($this->f['ctx']), 'id');
        $this->assertSame([$contact], $list);
        $trash = app(PageManagement::class)->trash($this->f['ctx']);
        $this->assertEqualsCanonicalizing([$this->f['pageId'], $about], array_column($trash, 'id'));
        // Times written by PHP read back as written, whatever the database server's own time zone.
        $this->assertLessThan(60, abs(strtotime($trash[0]['deletedAt']) - time()));

        // Restore keeps the id, URL, title and draft; it comes back unpublished.
        $draftBefore = DB::table('page_drafts')->where('page_id', $about)->value('document');
        $this->bulk('pages', 'restore', [['id' => $about]])->assertOk()->assertJsonPath('data.done', [$about]);
        $row = DB::table('pages')->where('id', $about)->first();
        $this->assertSame(['/about', 'About us', null], [$row->path, $row->title, $row->deleted_at]);
        $this->assertSame($draftBefore, DB::table('page_drafts')->where('page_id', $about)->value('document'));

        // Its URL was taken meanwhile: restoring says which page uses it.
        $this->addPage($this->f['siteId'], '/', 'New home');
        $this->bulk('pages', 'restore', [['id' => $this->f['pageId']]])->assertOk()->assertJsonCount(0, 'data.done')
            ->assertJsonPath('data.failed.0.message', '“New home” now uses /. Change that page’s URL, then restore “Home”.');

        // Permanent deletion only from the Trash; history stays; it never comes back.
        $this->bulk('pages', 'purge', [['id' => $about]])->assertJsonPath('data.failed.0.message', 'Move “About us” to the Trash before deleting it permanently.');
        $revisions = DB::table('page_revisions')->where('page_id', $this->f['pageId'])->count();
        $this->bulk('pages', 'purge', [['id' => $this->f['pageId']]])->assertJsonPath('data.done', [$this->f['pageId']]);
        $this->assertSame([], app(PageManagement::class)->trash($this->f['ctx']));
        $this->assertSame($revisions, DB::table('page_revisions')->where('page_id', $this->f['pageId'])->count());
        $this->bulk('pages', 'restore', [['id' => $this->f['pageId']]])->assertJsonPath('data.failed.0.id', $this->f['pageId']);
    }

    public function test_bulk_requests_are_validated_and_permissions_fail_the_whole_request(): void
    {
        $this->bulk('pages', 'erase', [['id' => $this->f['pageId'], 'version' => 1]])->assertStatus(422);
        $this->bulk('pages', 'trash', [])->assertStatus(422);
        $this->bulk('pages', 'trash', [['id' => 'not-a-uuid']])->assertStatus(422);
        $this->bulk('pages', 'trash', array_fill(0, 101, ['id' => $this->f['pageId'], 'version' => 1]))->assertStatus(422);
        $this->bulk('media', 'purge', [['id' => $this->f['pageId']]])->assertStatus(422);

        $editor = User::findOrFail($this->addMember($this->f['siteId'], 'editor')->userId);
        $this->bulk('pages', 'trash', [['id' => $this->f['pageId'], 'version' => 1]], $editor)->assertStatus(403);
        $this->assertNull(DB::table('pages')->where('id', $this->f['pageId'])->value('deleted_at'));

        // Another site's page is "not found" for this member, item by item.
        $other = $this->siteFixture('owner', 'Other');
        $this->bulk('pages', 'trash', [['id' => $other['pageId'], 'version' => 1]])->assertOk()->assertJsonPath('data.failed.0.id', $other['pageId']);
        $this->assertNull(DB::table('pages')->where('id', $other['pageId'])->value('deleted_at'));
    }

    public function test_forms_keep_their_entries_in_the_trash_and_lose_them_only_when_deleted_permanently(): void
    {
        $forms = app(FormService::class);
        $definition = ['name' => 'Enquiry', 'submitLabel' => 'Send', 'successMessage' => 'Thanks', 'fields' => [['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true]]];
        $form = $forms->save($this->f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $definition]);
        $forms->publish($this->f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $draft = $forms->save($this->f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => [...$definition, 'name' => 'Draft form']]);
        foreach ([1, 2] as $n) {
            DB::table('form_submissions')->insert(['id' => Uuid::v7(), 'site_id' => $this->f['siteId'], 'form_id' => $form['id'], 'form_version' => 1, 'payload' => Crypt::encryptString(Json::encode(['name' => "Visitor {$n}"])), 'search_tokens' => '{}', 'notification_status' => 'disabled']);
        }
        $management = app(FormManagement::class);
        $this->assertSame(['all' => 2, 'active' => 1, 'draft' => 1, 'inactive' => 0, 'trash' => 0], $management->browse($this->f['ctx'], '')['counts']);

        $this->bulk('forms', 'trash', [['id' => $form['id'], 'version' => 1], ['id' => $draft['id'], 'version' => 1]])->assertOk()->assertJsonCount(2, 'data.done');
        $trash = $management->browse($this->f['ctx'], '', 1, 'trash');
        $this->assertSame([2, 0, 2], [$trash['counts']['trash'], $trash['counts']['all'], $trash['total']]);
        $this->assertSame(2, DB::table('form_submissions')->where('form_id', $form['id'])->count());
        $this->actingAs($this->owner)->postJson('/admin/api/forms/entry-counts', ['ids' => [$form['id'], $draft['id']]])->assertOk()->assertExactJson(['ok' => true, 'data' => [$form['id'] => 2]]);

        $this->bulk('forms', 'restore', [['id' => $draft['id']]])->assertJsonPath('data.done', [$draft['id']]);
        $this->assertSame('Draft form', $management->detail($this->f['ctx'], $draft['id'])['definition']['name']);

        $this->bulk('forms', 'purge', [['id' => $form['id']]])->assertJsonPath('data.done', [$form['id']]);
        $this->assertSame(0, DB::table('form_submissions')->where('form_id', $form['id'])->count());
        $this->assertSame(1, DB::table('site_form_versions')->where('form_id', $form['id'])->count(), 'published versions are history');
        $this->assertSame(0, $management->browse($this->f['ctx'], '')['counts']['trash']);
    }

    public function test_a_form_on_a_live_page_cannot_move_to_the_trash(): void
    {
        $forms = app(FormService::class);
        $form = $forms->save($this->f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => ['name' => 'Enquiry', 'submitLabel' => 'Send', 'successMessage' => 'Thanks', 'fields' => [['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]]]);
        $forms->publish($this->f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $node = ['id' => 'form_123', 'type' => 'form', 'version' => 4, 'props' => ['form' => ['id' => $form['id']], 'style' => new \stdClass]];
        $pages = app(PageService::class);
        $pages->saveDraft($this->f['ctx'], ['pageId' => $this->f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode([['op' => 'insertNode', 'parentId' => $this->f['document']['root'], 'index' => 1, 'nodes' => [$node]]]))]);
        $pages->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);

        $this->bulk('forms', 'trash', [['id' => $form['id'], 'version' => 1]])->assertOk()
            ->assertJsonPath('data.failed.0.message', '“Enquiry” is on a live page. Remove it from live pages before moving it to the Trash.');
    }

    public function test_images_move_to_the_trash_with_a_usage_summary_and_restore_unchanged(): void
    {
        $media = app(MediaService::class);
        $used = $media->upload($this->f['ctx'], self::png(), 'used.png');
        $unused = $media->upload($this->f['ctx'], self::png(), 'unused.png');
        $document = $this->f['document'];
        $document['nodes'][$this->f['heroId']]['props']['image'] = ['assetId' => $used['id'], 'alt' => 'A dot'];
        DB::table('page_drafts')->where('page_id', $this->f['pageId'])->update(['document' => Json::encode($document)]);
        $this->actingAs($this->owner)->postJson('/admin/api/media/usage', ['ids' => [$used['id'], $unused['id']]])->assertOk()
            ->assertJsonPath('data', ['livePages' => 0, 'drafts' => 1, 'components' => 0]);

        $library = app(MediaLibrary::class);
        $versions = array_column($library->browse($this->f['ctx'])['items'], 'version', 'id');
        $this->bulk('media', 'trash', [['id' => $used['id'], 'version' => $versions[$used['id']]], ['id' => $unused['id'], 'version' => $versions[$unused['id']]]])->assertJsonCount(2, 'data.done');
        $this->assertSame(['library' => 0, 'trash' => 2], $library->browse($this->f['ctx'])['counts']);
        // Trashed images keep being served where they are used.
        $this->assertNotNull(DB::table('media_assets')->where('id', $used['id'])->value('storage_key'));

        $this->bulk('media', 'restore', [['id' => $used['id']]])->assertJsonPath('data.done', [$used['id']]);
        $restored = $library->browse($this->f['ctx'])['items'][0];
        $this->assertSame([$used['id'], 'used.png', $used['url']], [$restored['id'], $restored['title'], $restored['url']]);
    }

    public function test_library_thumbnails_use_small_webp_sizes_never_the_original(): void
    {
        // A 1200 px wide image gets WebP sizes; the grid asks for the smallest ones.
        $image = imagecreatetruecolor(1200, 800);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $asset = app(MediaService::class)->upload($this->f['ctx'], $png, 'wide.png');
        $item = app(MediaLibrary::class)->browse($this->f['ctx'])['items'][0];
        $this->assertNotSame($asset['url'], $item['thumbUrl']);
        $this->assertStringEndsWith('-w320.webp', $item['thumbUrl']);
        $this->assertMatchesRegularExpression('/-w320\.webp 320w, \/media\/\S+-w640\.webp 640w$/', $item['thumbSrcset']);
    }
}
