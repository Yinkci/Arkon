<?php

namespace Tests\Feature;

use App\Arkon\Ai\Capabilities;
use App\Arkon\Content\BindingContext;
use App\Arkon\Content\BindingSources;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentReader;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\Relationships;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * References from one entry to others: a property's agent, an article's related services.
 *
 * A reference is a typed pointer and nothing else. These tests hold that: the target must exist
 * and be of the kind the field asked for, the order of several is editorial, a trashed target
 * disappears from pages but not from the editor, a permanently deleted one is taken out of
 * everything that pointed at it, and a page may bind one step through a reference and no further.
 */
final class RelationshipsTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    /** Team members (the target) and properties (the referrer), the plan's own example. */
    private function types(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'team_member', 'name' => 'Team members', 'singular' => 'Team member', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/team', 'inMenu' => true, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [
                ['key' => 'full_name', 'label' => 'Name', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'photo', 'label' => 'Photo', 'type' => 'media', 'settings' => (object) []],
                ['key' => 'home_address', 'label' => 'Home address', 'type' => 'text', 'settings' => ['private' => true]],
            ],
        ]);
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'property', 'name' => 'Properties', 'singular' => 'Property', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/properties', 'inMenu' => true, 'menuOrder' => 2, 'details' => false, 'taxonomies' => [],
            'fields' => [
                ['key' => 'agent', 'label' => 'Agent', 'type' => 'relationship', 'settings' => ['targetType' => 'team_member']],
                ['key' => 'viewings', 'label' => 'Viewings with', 'type' => 'relationship', 'settings' => ['targetType' => 'team_member', 'multiple' => true, 'maxItems' => 3]],
            ],
        ]);
    }

    private function entry(string $kind, string $title, array $values = []): string
    {
        $id = app(ContentItems::class)->create($this->f['ctx'], $kind, ['title' => $title])['id'];
        if ($values !== []) {
            app(EntryFields::class)->update($this->f['ctx'], $id, $values);
        }

        return $id;
    }

    private function values(string $pageId): array
    {
        return app(EntryFields::class)->drafts($this->f['siteId'], [$pageId])[$pageId] ?? [];
    }

    private function publish(string $pageId): void
    {
        $version = (int) DB::table('page_drafts')->where('page_id', $pageId)->value('version');
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
    }

    public function test_a_single_relationship_stores_one_typed_reference(): void
    {
        $this->types();
        $jane = $this->entry('team_member', 'Jane Doe', ['full_name' => 'Jane Doe']);
        $house = $this->entry('property', 'Riverside House', ['agent' => ['id' => $jane]]);

        $this->assertSame(['id' => $jane], $this->values($house)['agent']);
        // Only the reference is stored: the agent's name is not copied anywhere.
        $stored = Json::encode($this->values($house));
        $this->assertStringNotContainsString('Jane Doe', $stored);
        // And it is a row in the index, which is what makes it queryable.
        $this->assertSame($jane, DB::table('content_field_index')->where('page_id', $house)->where('field_key', 'agent')->value('ref_id'));
    }

    public function test_a_single_relationship_refuses_more_than_one_item(): void
    {
        $this->types();
        $a = $this->entry('team_member', 'Jane Doe');
        $b = $this->entry('team_member', 'John Roe');
        $house = $this->entry('property', 'Riverside House');

        $this->expectExceptionMessage('Agent takes one item.');
        app(EntryFields::class)->update($this->f['ctx'], $house, ['agent' => [['id' => $a], ['id' => $b]]]);
    }

    public function test_several_references_keep_the_order_they_were_given_and_can_be_reordered(): void
    {
        $this->types();
        $one = $this->entry('team_member', 'One');
        $two = $this->entry('team_member', 'Two');
        $three = $this->entry('team_member', 'Three');
        $house = $this->entry('property', 'Riverside House', ['viewings' => [['id' => $one], ['id' => $two], ['id' => $three]]]);

        $this->assertSame([$one, $two, $three], array_column($this->values($house)['viewings'], 'id'));
        // The index keeps that order as positions, so a reader can reproduce it.
        $positions = DB::table('content_field_index')->where('page_id', $house)->where('field_key', 'viewings')->orderBy('position')->pluck('ref_id')->all();
        $this->assertSame([$one, $two, $three], $positions);

        app(EntryFields::class)->update($this->f['ctx'], $house, ['viewings' => [['id' => $three], ['id' => $one], ['id' => $two]]]);
        $this->assertSame([$three, $one, $two], array_column($this->values($house)['viewings'], 'id'));
        $this->assertSame(
            [$three, $one, $two],
            DB::table('content_field_index')->where('page_id', $house)->where('field_key', 'viewings')->orderBy('position')->pluck('ref_id')->all(),
        );
    }

    public function test_a_multiple_relationship_honours_its_limit_and_refuses_repeats(): void
    {
        $this->types();
        $ids = array_map(fn ($n) => $this->entry('team_member', "Member {$n}"), [1, 2, 3, 4]);
        $house = $this->entry('property', 'Riverside House');

        try {
            app(EntryFields::class)->update($this->f['ctx'], $house, ['viewings' => array_map(fn ($id) => ['id' => $id], $ids)]);
            $this->fail('Accepted more items than the field allows');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('at most 3 items', $e->getMessage());
        }

        try {
            app(EntryFields::class)->update($this->f['ctx'], $house, ['viewings' => [['id' => $ids[0]], ['id' => $ids[0]]]]);
            $this->fail('Accepted the same item twice');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('same item twice', $e->getMessage());
        }
    }

    public function test_a_reference_to_something_that_does_not_exist_is_refused(): void
    {
        $this->types();
        $house = $this->entry('property', 'Riverside House');

        try {
            app(EntryFields::class)->update($this->f['ctx'], $house, ['agent' => ['id' => Uuid::v7()]]);
            $this->fail('Accepted a reference to nothing');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no longer exists', $e->getMessage());
        }
        // Not an id at all is refused by shape, before anything is looked up.
        try {
            app(EntryFields::class)->update($this->f['ctx'], $house, ['agent' => 'jane']);
            $this->fail('Accepted something that is not a reference');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be an item of the related content type', $e->getMessage());
        }
    }

    public function test_a_reference_to_the_wrong_kind_of_content_is_refused_by_name(): void
    {
        $this->types();
        $house = $this->entry('property', 'Riverside House');
        $other = $this->entry('property', 'Hillside Cottage');

        try {
            app(EntryFields::class)->update($this->f['ctx'], $house, ['agent' => ['id' => $other]]);
            $this->fail('Accepted a reference to another kind of content');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Agent takes Team member', $e->getMessage());
        }
    }

    public function test_a_relationship_field_must_say_what_it_relates_to(): void
    {
        $this->types();
        $base = [
            'key' => 'listing', 'name' => 'Listings', 'singular' => 'Listing', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/listings', 'inMenu' => false, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
        ];

        try {
            app(ContentTypeService::class)->create($this->f['ctx'], [...$base, 'fields' => [
                ['key' => 'agent', 'label' => 'Agent', 'type' => 'relationship', 'settings' => (object) []],
            ]]);
            $this->fail('Accepted a relationship with no target');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('choose the kind of content it relates to', $e->getMessage());
        }

        try {
            app(ContentTypeService::class)->create($this->f['ctx'], [...$base, 'fields' => [
                ['key' => 'agent', 'label' => 'Agent', 'type' => 'relationship', 'settings' => ['targetType' => 'nobody']],
            ]]);
            $this->fail('Accepted a relationship to a type the site does not have');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not a kind of content this site has', $e->getMessage());
        }

        // A type may relate to itself, which is how "related listings" is described.
        app(ContentTypeService::class)->create($this->f['ctx'], [...$base, 'fields' => [
            ['key' => 'related', 'label' => 'Related listings', 'type' => 'relationship', 'settings' => ['targetType' => 'listing', 'multiple' => true]],
        ]]);
        $this->assertSame('listing', app(ContentTypeService::class)->show($this->f['ctx'], 'listing')['fields'][0]['settings']['targetType']);
    }

    public function test_a_relationship_inside_a_field_group_is_checked_the_same_way(): void
    {
        $this->types();
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'handover', 'name' => 'Handover', 'description' => '',
            'rules' => ['groups' => [['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => 'property']]]]],
            'fields' => [['key' => 'signed_off_by', 'label' => 'Signed off by', 'type' => 'relationship', 'settings' => ['targetType' => 'team_member']]],
        ]);
        $house = $this->entry('property', 'Riverside House');
        $wrong = $this->entry('property', 'Hillside Cottage');

        $this->expectExceptionMessage('Signed off by takes Team member');
        app(EntryFields::class)->update($this->f['ctx'], $house, ['signed_off_by' => ['id' => $wrong]]);
    }

    public function test_a_trashed_target_keeps_its_reference_and_is_left_off_a_published_page(): void
    {
        $this->types();
        $jane = $this->entry('team_member', 'Jane Doe', ['full_name' => 'Jane Doe']);
        $house = $this->entry('property', 'Riverside House', ['agent' => ['id' => $jane]]);
        $version = (int) DB::table('page_drafts')->where('page_id', $jane)->value('version');
        app(PageManagement::class)->delete($this->f['ctx'], ['pageId' => $jane, 'expectedVersion' => $version]);

        // The reference is still there, and the admin can see what state it is in.
        $this->assertSame(['id' => $jane], $this->values($house)['agent']);
        $this->assertSame('trash', app(Relationships::class)->summaries($this->f['siteId'], [$jane])[$jane]['status']);

        // Resolved for a visitor, the trashed target is simply not there.
        $definition = ['fields' => [['key' => 'agent', 'label' => 'Agent', 'type' => 'relationship', 'settings' => ['targetType' => 'team_member']]]];
        $this->assertArrayNotHasKey('agent', app(Relationships::class)->resolve($this->f['siteId'], $definition, $this->values($house), public: true));
        $this->assertArrayHasKey('agent', app(Relationships::class)->resolve($this->f['siteId'], $definition, $this->values($house)));

        // Restoring brings it back without anyone re-pointing anything. A draft target does
        // resolve: pointing at it was a choice, and most entries of this kind are never
        // published as pages of their own.
        app(PageManagement::class)->restore($this->f['ctx'], $jane);
        $resolved = app(Relationships::class)->resolve($this->f['siteId'], $definition, $this->values($house), public: true);
        $this->assertSame('Jane Doe', $resolved['agent']['title']);
        $this->assertSame('draft', $resolved['agent']['status']);
    }

    public function test_deleting_an_entry_permanently_takes_it_out_of_everything_that_pointed_at_it(): void
    {
        $this->types();
        $jane = $this->entry('team_member', 'Jane Doe');
        $john = $this->entry('team_member', 'John Roe');
        $house = $this->entry('property', 'Riverside House', ['agent' => ['id' => $jane], 'viewings' => [['id' => $jane], ['id' => $john]]]);

        $version = (int) DB::table('page_drafts')->where('page_id', $jane)->value('version');
        app(PageManagement::class)->delete($this->f['ctx'], ['pageId' => $jane, 'expectedVersion' => $version]);
        app(PageManagement::class)->purge($this->f['ctx'], $jane);

        $values = $this->values($house);
        $this->assertArrayNotHasKey('agent', $values, 'the single reference is gone, not left pointing at nothing');
        $this->assertSame([$john], array_column($values['viewings'], 'id'), 'the others keep their order');
        $this->assertSame(0, DB::table('content_field_index')->where('site_id', $this->f['siteId'])->where('ref_id', $jane)->count());
        // The entry that lost a reference is still valid: saving it again needs no repair.
        app(EntryFields::class)->update($this->f['ctx'], $house, ['viewings' => [['id' => $john]]]);
    }

    public function test_what_uses_an_entry_is_reported_before_it_is_deleted(): void
    {
        $this->types();
        $jane = $this->entry('team_member', 'Jane Doe');
        foreach (['Riverside House', 'Hillside Cottage', 'Seaview Flat'] as $title) {
            $this->entry('property', $title, ['agent' => ['id' => $jane]]);
        }

        $uses = app(Relationships::class)->usedBy($this->f['siteId'], $jane);
        $this->assertSame([[
            'kind' => 'property', 'label' => 'Properties', 'singular' => 'Property',
            'fieldKey' => 'agent', 'fieldLabel' => 'Agent', 'count' => 3,
        ]], $uses);
        $this->assertSame(['Used by 3 Properties as Agent.'], Relationships::describeUses($uses));

        // An entry nothing points at says so by saying nothing.
        $this->assertSame([], app(Relationships::class)->usedBy($this->f['siteId'], $this->entry('team_member', 'Unused')));
    }

    public function test_entries_can_be_filtered_by_what_they_refer_to(): void
    {
        $this->types();
        $jane = $this->entry('team_member', 'Jane Doe');
        $john = $this->entry('team_member', 'John Roe');
        $hers = $this->entry('property', 'Riverside House', ['agent' => ['id' => $jane]]);
        $this->entry('property', 'Hillside Cottage', ['agent' => ['id' => $john]]);

        $reader = app(ContentReader::class);
        $result = $reader->page($reader->query($this->f['siteId'], 'property', 'draft', ['refs' => ['agent' => [$jane]]]), 'title', 1, 20);
        $this->assertSame([$hers], array_column($result['rows'], 'id'));

        // Nobody's entries are nobody's: an unknown id matches nothing rather than everything.
        $none = $reader->page($reader->query($this->f['siteId'], 'property', 'draft', ['refs' => ['agent' => [Uuid::v7()]]]), 'title', 1, 20);
        $this->assertSame([], $none['rows']);
    }

    public function test_the_api_serializes_a_reference_without_the_target_s_own_fields(): void
    {
        // The public API serves the built-in types, which carry fields through a field group:
        // a post whose coverage names the team member it is about.
        $this->types();
        $this->addDomain($this->f['siteId'], 'relations.test');
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'coverage', 'name' => 'Coverage', 'description' => '',
            'rules' => ['groups' => [['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => 'post']]]]],
            'fields' => [['key' => 'about', 'label' => 'About', 'type' => 'relationship', 'settings' => ['targetType' => 'team_member']]],
        ]);
        $jane = $this->entry('team_member', 'Jane Doe', ['full_name' => 'Jane Doe', 'home_address' => '1 Private Road']);
        $post = $this->entry('post', 'Meet the team', ['about' => ['id' => $jane]]);
        $this->publish($post);

        $body = $this->getJson('http://relations.test/api/v1/posts/'.$post)->assertOk()->json('data');
        $this->assertSame(['id', 'type', 'typeLabel', 'title', 'path', 'status'], array_keys($body['fields']['about']));
        $this->assertSame('Jane Doe', $body['fields']['about']['title']);
        $this->assertSame('team_member', $body['fields']['about']['type']);
        // Not the target's own fields, so a chain of references cannot grow the response, and
        // nothing private travels with it.
        $this->assertArrayNotHasKey('fields', $body['fields']['about']);
        $this->assertStringNotContainsString('Private Road', Json::encode($body));

        // A list asks for them explicitly, and the same filter the admin uses works here too.
        $list = $this->getJson('http://relations.test/api/v1/posts?include=fields&related=about:'.$jane)->assertOk()->json('data');
        $this->assertSame([$post], array_column($list, 'id'));
        $this->assertSame('Jane Doe', $list[0]['fields']['about']['title']);
        $this->assertSame([], $this->getJson('http://relations.test/api/v1/posts?related=about:'.Uuid::v7())->assertOk()->json('data'));
        // Without the include, no field values are serialized at all.
        $this->assertArrayNotHasKey('fields', $this->getJson('http://relations.test/api/v1/posts')->assertOk()->json('data.0'));
    }

    public function test_a_cycle_resolves_one_step_and_stops(): void
    {
        $this->types();
        // A team member whose featured property points back at the property that names them.
        app(ContentTypeService::class)->update($this->f['ctx'], 'team_member', [
            'key' => 'team_member', 'name' => 'Team members', 'singular' => 'Team member', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/team', 'inMenu' => true, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [
                ['key' => 'full_name', 'label' => 'Name', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'photo', 'label' => 'Photo', 'type' => 'media', 'settings' => (object) []],
                ['key' => 'home_address', 'label' => 'Home address', 'type' => 'text', 'settings' => ['private' => true]],
                ['key' => 'featured', 'label' => 'Featured property', 'type' => 'relationship', 'settings' => ['targetType' => 'property']],
            ],
            'expectedVersion' => app(ContentTypeService::class)->show($this->f['ctx'], 'team_member')['schemaVersion'],
        ]);
        $jane = $this->entry('team_member', 'Jane Doe', ['full_name' => 'Jane Doe']);
        $house = $this->entry('property', 'Riverside House', ['agent' => ['id' => $jane]]);
        app(EntryFields::class)->update($this->f['ctx'], $jane, ['featured' => ['id' => $house]]);

        $html = $this->canvas($house, $this->heroBoundTo(['source' => 'entry', 'field' => 'agent', 'path' => ['full_name']]));
        $this->assertStringContainsString('Jane Doe', $html);

        // One step is all a binding may take: the agent's own reference stays a reference.
        $context = app(BindingContext::class)->draft($this->f['siteId'], $house);
        $this->assertSame(['id' => $house], $context['entry']['agent']['featured']);
    }

    public function test_a_page_binds_through_a_reference_to_the_target_s_name_and_picture(): void
    {
        $this->types();
        $asset = $this->mediaAsset();
        $jane = $this->entry('team_member', 'Jane Doe', ['full_name' => 'Jane Doe', 'photo' => ['id' => $asset], 'home_address' => '1 Private Road']);
        $house = $this->entry('property', 'Riverside House', ['agent' => ['id' => $jane]]);

        $html = $this->canvas($house, $this->heroBoundTo(['source' => 'entry', 'field' => 'agent', 'path' => ['full_name']]));
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringNotContainsString('Static heading', $html);

        // The editor offers exactly those paths, and never a private field of the target.
        $offered = BindingSources::all(app(BindingContext::class)->entryFields($this->f['siteId'], $house), [], 'Property');
        $labels = array_column($offered[0]['fields'], 'label');
        $this->assertContains('Agent → Name', $labels);
        $this->assertContains('Agent → Photo', $labels);
        $this->assertNotContains('Agent → Home address', $labels);
        $this->assertNotContains('Agent', $labels, 'the reference itself is not a value a property can take');
    }

    public function test_a_published_page_shows_a_reference_until_the_target_is_trashed(): void
    {
        $this->types();
        $jane = $this->entry('team_member', 'Jane Doe', ['full_name' => 'Jane Doe']);
        $house = $this->entry('property', 'Riverside House', ['agent' => ['id' => $jane]]);
        DB::table('page_drafts')->where('page_id', $house)->update(['document' => Json::encode($this->heroBoundTo(['source' => 'entry', 'field' => 'agent', 'path' => ['full_name']]))]);

        $this->publish($house);
        $live = DB::table('publications')->where('page_id', $house)->orderByDesc('created_at')->value('html');
        $this->assertStringContainsString('Jane Doe', $live);

        // Trashing the agent takes her off the page: what renders now is the block's own text.
        $version = (int) DB::table('page_drafts')->where('page_id', $jane)->value('version');
        app(PageManagement::class)->delete($this->f['ctx'], ['pageId' => $jane, 'expectedVersion' => $version]);
        $again = app(PageService::class)->renderPreview($this->f['ctx'], $house);
        $this->assertStringContainsString('Static heading', $again);
        $this->assertStringNotContainsString('Jane Doe', $again);
    }

    public function test_the_picker_searches_on_the_server_and_a_stranger_cannot_use_it(): void
    {
        $this->types();
        $this->entry('team_member', 'Jane Doe');
        $this->entry('team_member', 'John Roe');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));

        $items = $this->getJson('/admin/api/related?type=team_member&q=jane')->assertOk()->json('data.items');
        $this->assertSame(['Jane Doe'], array_column($items, 'title'));
        // The whole list, capped, when nothing is typed.
        $this->assertCount(2, $this->getJson('/admin/api/related?type=team_member')->assertOk()->json('data.items'));
        // A kind that is not a type key narrows to nothing rather than erroring.
        $this->assertSame([], $this->getJson('/admin/api/related?type=NOT A TYPE')->assertOk()->json('data.items'));

        $this->post('/logout');
        $this->getJson('/admin/api/related?type=team_member')->assertUnauthorized();
    }

    public function test_the_ai_capabilities_describe_what_a_relationship_relates_to(): void
    {
        $this->types();
        $caps = app(Capabilities::class)->describe($this->f['ctx']);
        $property = array_values(array_filter($caps['contentTypes'], fn ($t) => $t['type'] === 'property'))[0];
        $agent = array_values(array_filter($property['fields'], fn ($f) => $f['key'] === 'agent'))[0];

        $this->assertSame(['key' => 'agent', 'label' => 'Agent', 'type' => 'relationship', 'relatesTo' => 'team_member', 'holds' => 'one'], $agent);
        // A private field is not something AI is told about.
        $member = array_values(array_filter($caps['contentTypes'], fn ($t) => $t['type'] === 'team_member'))[0];
        $this->assertNotContains('home_address', array_column($member['fields'], 'key'));
    }

    /** A real asset in this site's library, for a media field to point at. */
    private function mediaAsset(): string
    {
        $id = Uuid::v7();
        DB::table('media_assets')->insert([
            'id' => $id, 'site_id' => $this->f['siteId'], 'storage_key' => $id.'.png', 'mime' => 'image/png',
            'bytes' => 70, 'width' => 1, 'height' => 1, 'original_name' => 'dot.png',
        ]);

        return $id;
    }

    /** A hero whose heading comes from a binding, with a static heading as the fallback. */
    private function heroBoundTo(array $binding): array
    {
        return [
            'schemaVersion' => 1,
            'root' => 'root0001',
            'nodes' => [
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new \stdClass, 'children' => ['hero0001']],
                'hero0001' => [
                    'id' => 'hero0001', 'type' => 'hero', 'version' => 5,
                    'props' => ['heading' => 'Static heading', 'headingLevel' => 'h1', 'text' => '', 'image' => null],
                    'children' => [],
                    'bindings' => ['heading' => $binding],
                ],
            ],
            'seo' => new \stdClass,
        ];
    }

    private function canvas(string $pageId, array $document): string
    {
        DB::table('page_drafts')->where('page_id', $pageId)->update(['document' => Json::encode($document)]);

        return app(PageService::class)->renderCanvas($this->f['ctx'], $pageId, $document, app(MediaSigner::class))['body'];
    }
}
