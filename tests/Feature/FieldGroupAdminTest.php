<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\DatabaseTestCase;

/** The admin screens and endpoints for field groups, and how they reach the entry editor. */
final class FieldGroupAdminTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'groups.test');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
    }

    private function serviceType(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [['key' => 'summary', 'label' => 'Summary', 'type' => 'text', 'settings' => (object) []]],
        ]);
    }

    /** "Content type is X", the rule a group most often has. */
    private static function onType(string $kind): array
    {
        return ['groups' => [['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => $kind]]]]];
    }

    private function body(array $rules = ['groups' => []]): array
    {
        return [
            'key' => 'service_details', 'name' => 'Service Details', 'description' => 'Price and duration',
            'rules' => $rules,
            'fields' => [
                ['key' => 'starting_price', 'label' => 'Starting Price', 'type' => 'decimal', 'settings' => (object) []],
                ['key' => 'duration', 'label' => 'Duration', 'type' => 'text', 'settings' => (object) []],
            ],
        ];
    }

    public function test_the_list_and_editor_screens_render(): void
    {
        $this->serviceType();
        $this->postJson('/admin/api/field-groups', $this->body(self::onType('service')))->assertOk();

        $this->get('/admin/fields')->assertOk()->assertInertia(
            fn (Assert $p) => $p->component('Admin/FieldGroups')->where('canManage', true)->has('groups', 1)
                ->where('groups.0.key', 'service_details')->where('groups.0.fields', 2)->where('groups.0.appliesTo', 'Services')
        );
        $this->get('/admin/fields/new')->assertOk()->assertInertia(
            fn (Assert $p) => $p->component('Admin/FieldGroupEditor')->where('group', null)->has('fieldTypes')
                ->where('attributes.1.key', 'content_type')->where('attributes.1.label', 'Content type')
        );
        $this->get('/admin/fields/service_details')->assertOk()->assertInertia(
            fn (Assert $p) => $p->where('group.name', 'Service Details')->has('group.fields', 2)
                ->where('group.appliesTo', 'Services')
        );
    }

    public function test_a_group_is_created_changed_and_deleted_through_the_endpoints(): void
    {
        $this->serviceType();
        $this->postJson('/admin/api/field-groups', $this->body())->assertOk()->assertJsonPath('data.key', 'service_details');

        // Attaching it later is an ordinary update.
        $this->postJson('/admin/api/field-groups/service_details', [...$this->body(self::onType('service')), 'expectedVersion' => 1])
            ->assertOk()->assertJsonPath('data.appliesTo', 'Services');

        // Deleting one in use needs confirming.
        $this->postJson('/admin/api/field-groups/service_details/delete', [])->assertStatus(422);
        $this->postJson('/admin/api/field-groups/service_details/delete', ['confirmed' => true])->assertOk();
        $this->assertSame(0, DB::table('field_groups')->count());
    }

    public function test_the_endpoints_refuse_what_the_service_refuses(): void
    {
        $this->serviceType();
        $this->postJson('/admin/api/field-groups', $this->body())->assertOk();
        // A second group with the same key.
        $this->postJson('/admin/api/field-groups', $this->body())->assertStatus(422);
        // A target that does not exist.
        // A rule naming a type that does not exist is kept and reported, not refused.
        $this->postJson('/admin/api/field-groups', [...$this->body(self::onType('ghost')), 'key' => 'other'])
            ->assertOk()->assertJsonPath('data.missingTargets.0.value', 'ghost');
        // A field whose key the type already uses.
        $this->postJson('/admin/api/field-groups', [
            'key' => 'clashing', 'name' => 'Clashing', 'description' => '',
            'rules' => self::onType('service'),
            'fields' => [['key' => 'summary', 'label' => 'Summary again', 'type' => 'text', 'settings' => (object) []]],
        ])->assertStatus(422);
    }

    public function test_an_editor_may_read_the_groups_but_not_change_them(): void
    {
        $this->serviceType();
        $this->postJson('/admin/api/field-groups', $this->body())->assertOk();
        $editorId = $this->createUser('editor');
        DB::table('site_members')->insert(['site_id' => $this->f['siteId'], 'user_id' => $editorId, 'role' => 'editor']);
        $this->actingAs(User::findOrFail($editorId));

        $this->get('/admin/fields')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManage', false));
        $this->get('/admin/fields/service_details')->assertStatus(403);
        $this->postJson('/admin/api/field-groups', [...$this->body(), 'key' => 'sneaky'])->assertStatus(403);
        $this->postJson('/admin/api/field-groups/service_details/delete', ['confirmed' => true])->assertStatus(403);
    }

    public function test_the_entry_editor_shows_the_types_own_fields_then_each_group(): void
    {
        $this->serviceType();
        $this->postJson('/admin/api/field-groups', $this->body(self::onType('service')))->assertOk();
        $entry = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Web development'])['id'];

        $this->get('/admin/editor/'.$entry)->assertOk()->assertInertia(
            fn (Assert $p) => $p->has('init.content.sections', 2)
                ->where('init.content.sections.0.name', null)
                ->where('init.content.sections.0.fields.0.key', 'summary')
                ->where('init.content.sections.1.name', 'Service Details')
                ->where('init.content.sections.1.key', 'service_details')
                ->has('init.content.sections.1.fields', 2)
        );

        // Values for a group field save through the same endpoint as any other.
        $this->postJson("/admin/api/pages/{$entry}/fields", ['fields' => ['summary' => 'Fast sites', 'starting_price' => '75000.5']])
            ->assertOk()
            ->assertJsonPath('data.fields.starting_price', 75000.5);
    }

    public function test_a_rule_of_several_parts_survives_the_round_trip_to_the_editor(): void
    {
        $this->serviceType();
        // (content type is service AND edited by an owner) OR (content type is page)
        $rules = ['groups' => [
            ['rules' => [
                ['attribute' => 'content_type', 'operator' => 'is', 'value' => 'service'],
                ['attribute' => 'user_role', 'operator' => 'is', 'value' => 'owner'],
            ]],
            ['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => 'page']]],
        ]];
        $this->postJson('/admin/api/field-groups', [...$this->body($rules), 'key' => 'mixed', 'fields' => [
            ['key' => 'mixed_note', 'label' => 'Note', 'type' => 'text', 'settings' => (object) []],
        ]])->assertOk()->assertJsonPath('data.appliesTo', 'Services and edited by Owners, or Pages');

        $this->get('/admin/fields/mixed')->assertOk()->assertInertia(
            fn (Assert $p) => $p->has('group.rules.groups', 2)
                ->has('group.rules.groups.0.rules', 2)
                ->where('group.rules.groups.0.rules.1.attribute', 'user_role')
                ->where('group.rules.groups.1.rules.0.value', 'page')
                ->where('group.missingTargets', [])
        );
    }

    public function test_the_rule_builder_is_offered_only_what_arkon_has(): void
    {
        $this->serviceType();
        $response = $this->get('/admin/fields/new')->assertOk();
        $attributes = $response->viewData('page')['props']['attributes'];

        // No page-template rule, because Arkon has no page templates.
        $this->assertSame(['scope', 'content_type', 'entry', 'taxonomy_term', 'user_role'], array_column($attributes, 'key'));
        $byKey = array_column($attributes, null, 'key');
        $this->assertSame(['owner', 'admin', 'editor', 'viewer'], array_column($byKey['user_role']['values'], 'value'));
        $this->assertContains('service', array_column($byKey['content_type']['values'], 'value'));
        // Fields can be placed on the site itself, not only on entries.
        $this->assertSame(['entry', 'site'], array_column($byKey['scope']['values'], 'value'));
        // A category rule may only be compared with "has" or "does not have".
        $this->assertSame(['contains', 'does_not_contain'], array_column($byKey['taxonomy_term']['operators'], 'key'));
    }

    public function test_the_content_type_editor_names_the_groups_attached_to_it(): void
    {
        $this->serviceType();
        $this->postJson('/admin/api/field-groups', $this->body(self::onType('service')))->assertOk();

        $this->get('/admin/content-types/service')->assertOk()->assertInertia(
            fn (Assert $p) => $p->has('groups', 1)->where('groups.0.name', 'Service Details')->where('groups.0.fields', 2)
        );
    }
}
