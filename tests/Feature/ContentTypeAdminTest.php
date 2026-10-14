<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\DatabaseTestCase;

/** The admin screens and endpoints for defining content types and filling in their entries. */
final class ContentTypeAdminTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'admin.test');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
    }

    private function definition(array $overrides = []): array
    {
        return [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => 'What we do', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 10, 'details' => true, 'taxonomies' => [],
            'fields' => [
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'settings' => ['required' => true]],
                ['key' => 'price_from', 'label' => 'Price from', 'type' => 'decimal', 'settings' => ['min' => 0]],
            ],
            ...$overrides,
        ];
    }

    public function test_the_list_shows_built_in_kinds_beside_the_ones_this_site_defined(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], $this->definition());

        $this->get('/admin/content-types')->assertOk()->assertInertia(
            fn (Assert $p) => $p->component('Admin/ContentTypes')
                ->where('canManage', true)
                ->has('types', 3)
                ->where('types.0.key', 'page')
                ->where('types.2.key', 'service')
                ->where('types.2.defined', true)
                ->where('types.2.fields', 2)
        );
    }

    public function test_the_editor_opens_blank_for_a_new_type_and_filled_for_an_existing_one(): void
    {
        $this->get('/admin/content-types/new')->assertOk()->assertInertia(
            fn (Assert $p) => $p->component('Admin/ContentTypeEditor')->where('type', null)
                ->has('fieldTypes')
                ->has('taxonomies', 2)
        );

        app(ContentTypeService::class)->create($this->f['ctx'], $this->definition());
        $this->get('/admin/content-types/service')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('type.key', 'service')->where('type.name', 'Services')->has('type.fields', 2)
                ->where('type.fields.0.key', 'summary')
        );
    }

    public function test_a_type_is_created_changed_and_turned_off_through_the_admin_endpoints(): void
    {
        $this->postJson('/admin/api/content-types', $this->definition())->assertOk()->assertJsonPath('data.key', 'service');

        $this->postJson('/admin/api/content-types/service', $this->definition(['name' => 'Solutions']))
            ->assertOk()->assertJsonPath('data.schemaVersion', 1);

        $this->postJson('/admin/api/content-types/service/active', ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertFalse((bool) DB::table('content_types')->where('key', 'service')->value('active'));

        $this->postJson('/admin/api/content-types/service/delete', [])->assertOk();
        $this->assertSame(0, DB::table('content_types')->count());
    }

    public function test_the_endpoints_refuse_what_the_service_refuses(): void
    {
        $this->postJson('/admin/api/content-types', $this->definition())->assertOk();
        // A second type with the same key.
        $this->postJson('/admin/api/content-types', $this->definition())->assertStatus(422);
        // A reserved key.
        $this->postJson('/admin/api/content-types', $this->definition(['key' => 'media']))->assertStatus(422);
        // Removing a field without confirming.
        $this->postJson('/admin/api/content-types/service', $this->definition(['fields' => []]))->assertStatus(422);
    }

    public function test_an_editor_may_write_entries_but_not_reshape_the_content_model(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], $this->definition());
        $editorId = $this->createUser('editor');
        DB::table('site_members')->insert(['site_id' => $this->f['siteId'], 'user_id' => $editorId, 'role' => 'editor']);
        $this->actingAs(User::findOrFail($editorId));

        $this->get('/admin/content-types')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManage', false));
        $this->postJson('/admin/api/content-types', $this->definition(['key' => 'project', 'name' => 'Projects', 'singular' => 'Project']))->assertStatus(403);
        $this->get('/admin/content-types/service')->assertStatus(403);
    }

    public function test_entries_of_a_defined_type_get_their_own_list_and_their_values_save(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], $this->definition());
        $entry = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Web development'])['id'];

        // The entry took the type's URL prefix, and the list screen is generated for the kind.
        $this->assertSame('/services/web-development', DB::table('pages')->where('id', $entry)->value('path'));
        $this->get('/admin/content/service')->assertOk()->assertInertia(
            fn (Assert $p) => $p->component('Admin/Pages')->where('type.kind', 'service')->where('type.plural', 'Services')->has('pages', 1)
        );

        $this->postJson("/admin/api/pages/{$entry}/fields", ['fields' => ['summary' => 'Sites that work', 'price_from' => '1500.5']])
            ->assertOk()
            ->assertJsonPath('data.fields.summary', 'Sites that work')
            ->assertJsonPath('data.fields.price_from', 1500.5);

        // A value the field cannot hold is refused with the field named.
        $this->postJson("/admin/api/pages/{$entry}/fields", ['fields' => ['price_from' => 'free']])->assertStatus(422);
    }

    public function test_an_unknown_kind_is_not_a_screen(): void
    {
        $this->get('/admin/content/nonsense')->assertStatus(422);
    }

    public function test_entries_keep_their_values_through_the_editor_screen(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], $this->definition());
        $entry = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Web'])['id'];
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['summary' => 'Stored']);

        $this->get('/admin/editor/'.$entry)->assertOk()->assertInertia(fn (Assert $p) => $p->where('init.content.kind', 'service'));
    }
}
