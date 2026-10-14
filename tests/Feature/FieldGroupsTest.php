<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\FieldSchemaResolver;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Field groups: a set of fields several content types can share. A group holds schema only —
 * the values stay with the entry that owns them — so attaching a group to a second type must
 * copy nothing, and editing a group must change every target at once.
 */
final class FieldGroupsTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function type(string $key, string $plural, array $fields = []): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => $key, 'name' => $plural, 'singular' => rtrim($plural, 's'), 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/'.str_replace('_', '-', $key), 'inMenu' => true, 'menuOrder' => 1, 'details' => false,
            'taxonomies' => [], 'fields' => $fields,
        ]);
    }

    /** The Service Details group from the plan's demonstration. */
    /** "Content type is X", the rule a group most often has. */
    private static function onType(string ...$kinds): array
    {
        return ['groups' => array_map(fn ($kind) => ['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => $kind]]], $kinds)];
    }

    private function serviceDetails(array $rules = ['groups' => []]): array
    {
        return [
            'key' => 'service_details',
            'name' => 'Service Details',
            'description' => 'What a service costs and how long it takes',
            'rules' => $rules,
            'fields' => [
                ['key' => 'short_description', 'label' => 'Short Description', 'type' => 'textarea', 'settings' => ['required' => true]],
                ['key' => 'featured_image', 'label' => 'Featured Image', 'type' => 'media', 'settings' => (object) []],
                ['key' => 'starting_price', 'label' => 'Starting Price', 'type' => 'decimal', 'settings' => ['min' => 0]],
                ['key' => 'duration', 'label' => 'Duration', 'type' => 'text', 'settings' => (object) []],
            ],
        ];
    }

    private function entry(string $kind, string $title, string $path): string
    {
        $id = app(ContentItems::class)->create($this->f['ctx'], $kind, ['title' => $title, 'path' => $path])['id'];

        return $id;
    }

    public function test_a_group_is_created_and_reports_what_it_holds(): void
    {
        $group = app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails());

        $this->assertSame('service_details', $group['key']);
        $this->assertSame('Service Details', $group['name']);
        $this->assertCount(4, $group['fields']);
        $this->assertSame(1, $group['schemaVersion']);
        $this->assertSame('Nowhere yet', $group['appliesTo'], 'a new group is placed nowhere');
        // The fields went through the one field engine, so they came back canonical.
        $this->assertTrue($group['fields'][0]['settings']['required']);
        $this->assertSame('', $group['fields'][1]['settings']['help']);
    }

    public function test_the_same_group_serves_several_content_types_without_copying_anything(): void
    {
        $this->type('service', 'Services');
        $this->type('workshop', 'Workshops');
        $groups = app(FieldGroupService::class);
        $groups->create($this->f['ctx'], $this->serviceDetails(self::onType('service', 'workshop')));

        $resolver = app(FieldSchemaResolver::class);
        foreach (['service', 'workshop'] as $kind) {
            $keys = array_column($resolver->fieldsFor($this->f['siteId'], ['kind' => $kind]), 'key');
            $this->assertSame(['short_description', 'featured_image', 'starting_price', 'duration'], $keys);
        }
        // One definition, not two copies of it.
        $this->assertSame(1, DB::table('field_groups')->where('site_id', $this->f['siteId'])->count());
        $this->assertSame('Services, or Workshops', $groups->show($this->f['ctx'], 'service_details')['appliesTo']);
    }

    public function test_a_type_keeps_its_own_fields_and_the_group_adds_to_them(): void
    {
        $this->type('service', 'Services', [
            ['key' => 'summary', 'label' => 'Summary', 'type' => 'text', 'settings' => (object) []],
        ]);
        app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));

        // Its own fields come first, then the group's, which is the order an editor shows.
        $keys = array_column(app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => 'service']), 'key');
        $this->assertSame(['summary', 'short_description', 'featured_image', 'starting_price', 'duration'], $keys);

        $sections = app(FieldSchemaResolver::class)->sectionsFor($this->f['siteId'], ['kind' => 'service']);
        $this->assertNull($sections[0]['name'], "the type's own fields are the unnamed first section");
        $this->assertSame('Service Details', $sections[1]['name']);
        $this->assertCount(4, $sections[1]['fields']);
    }

    public function test_values_of_group_fields_belong_to_the_entry_that_holds_them(): void
    {
        $this->type('service', 'Services');
        $this->type('workshop', 'Workshops');
        app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails(self::onType('service', 'workshop')));
        $asset = $this->asset();
        $service = $this->entry('service', 'Web development', '/service/web');
        $workshop = $this->entry('workshop', 'React workshop', '/workshop/react');

        $fields = app(EntryFields::class);
        $fields->update($this->f['ctx'], $service, ['short_description' => 'Sites that work', 'starting_price' => 75000, 'featured_image' => ['id' => $asset]]);
        $fields->update($this->f['ctx'], $workshop, ['short_description' => 'Two days of React', 'starting_price' => 9000]);

        // Each entry keeps its own values, through the same group.
        $this->assertSame('Sites that work', $fields->drafts($this->f['siteId'], [$service])[$service]['short_description']);
        $this->assertSame('Two days of React', $fields->drafts($this->f['siteId'], [$workshop])[$workshop]['short_description']);
        $this->assertSame(['id' => $asset], $fields->drafts($this->f['siteId'], [$service])[$service]['featured_image']);
        $this->assertArrayNotHasKey('featured_image', $fields->drafts($this->f['siteId'], [$workshop])[$workshop]);
        // Values are stored flat on the entry, with no group name in the key.
        $stored = Json::toArray(Json::decode(DB::table('page_drafts')->where('page_id', $service)->value('fields')));
        $this->assertSame(['featured_image', 'short_description', 'starting_price'], array_keys($stored) === [] ? [] : self::sorted($stored));
    }

    public function test_group_fields_obey_the_same_validation_as_a_types_own(): void
    {
        $this->type('service', 'Services');
        app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));
        $entry = $this->entry('service', 'Web', '/service/web');
        $fields = app(EntryFields::class);

        foreach ([['starting_price' => 'free'], ['starting_price' => -5], ['featured_image' => 'not-an-asset']] as $refused) {
            try {
                $fields->update($this->f['ctx'], $entry, $refused);
                $this->fail('Accepted '.json_encode($refused));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        // And the publishing rule of a group field applies like any other.
        try {
            $fields->snapshot($this->f['ctx'], $entry, 'service');
            $this->fail('Published without the required group field');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Short Description', $e->getMessage());
        }
    }

    public function test_editing_a_group_changes_every_target_at_once(): void
    {
        $this->type('service', 'Services');
        $this->type('workshop', 'Workshops');
        $groups = app(FieldGroupService::class);
        $groups->create($this->f['ctx'], $this->serviceDetails(self::onType('service', 'workshop')));

        $definition = $groups->show($this->f['ctx'], 'service_details');
        $fields = [...$definition['fields'], ['key' => 'warranty', 'label' => 'Warranty', 'type' => 'text', 'settings' => (object) []]];
        $updated = $groups->update($this->f['ctx'], 'service_details', [...$definition, 'fields' => $fields]);
        $this->assertSame(2, $updated['schemaVersion']);

        foreach (['service', 'workshop'] as $kind) {
            $keys = array_column(app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => $kind]), 'key');
            $this->assertContains('warranty', $keys, "the new field did not reach {$kind}");
        }
        // Renaming the group is not a schema change.
        $this->assertSame(2, $groups->update($this->f['ctx'], 'service_details', [...$updated, 'name' => 'Service Info'])['schemaVersion']);
    }

    public function test_removing_a_shared_field_must_be_confirmed_and_says_who_it_affects(): void
    {
        $this->type('service', 'Services');
        $groups = app(FieldGroupService::class);
        $groups->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));
        $definition = $groups->show($this->f['ctx'], 'service_details');
        $fewer = array_values(array_filter($definition['fields'], fn ($f) => $f['key'] !== 'duration'));

        try {
            $groups->update($this->f['ctx'], 'service_details', [...$definition, 'fields' => $fewer]);
            $this->fail('Removed a shared field without confirmation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Duration', $e->getMessage());
            $this->assertStringContainsString('Services', $e->getMessage(), 'the warning names what it affects');
        }
        $this->assertCount(3, $groups->update($this->f['ctx'], 'service_details', [...$definition, 'fields' => $fewer, 'confirmRemovals' => true])['fields']);
    }

    public function test_a_group_may_not_bring_a_key_the_target_already_has(): void
    {
        $this->type('service', 'Services', [
            ['key' => 'duration', 'label' => 'Duration', 'type' => 'text', 'settings' => (object) []],
        ]);
        try {
            app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));
            $this->fail('Attached a group whose key clashes with the type');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('"duration" is already a field on Services', $e->getMessage());
        }
        // Nothing was half written.
        $this->assertSame(0, DB::table('field_groups')->count());
    }

    public function test_two_groups_on_one_target_may_not_share_a_key(): void
    {
        $this->type('service', 'Services');
        $groups = app(FieldGroupService::class);
        $groups->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));

        $this->expectException(ValidationException::class);
        $groups->create($this->f['ctx'], [
            'key' => 'more_details', 'name' => 'More Details', 'description' => '',
            'rules' => self::onType('service'),
            'fields' => [['key' => 'duration', 'label' => 'How long', 'type' => 'text', 'settings' => (object) []]],
        ]);
    }

    public function test_keys_are_checked_and_never_change(): void
    {
        $groups = app(FieldGroupService::class);
        foreach (['Service Details', '1details', 'service-details'] as $bad) {
            try {
                $groups->create($this->f['ctx'], [...$this->serviceDetails(), 'key' => $bad]);
                $this->fail("Accepted the key {$bad}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('key', strtolower($e->getMessage()));
            }
        }
        $groups->create($this->f['ctx'], $this->serviceDetails());
        $this->expectException(ValidationException::class);
        $groups->update($this->f['ctx'], 'service_details', [...$this->serviceDetails(), 'key' => 'renamed']);
    }

    public function test_a_duplicate_group_key_is_refused(): void
    {
        $groups = app(FieldGroupService::class);
        $groups->create($this->f['ctx'], $this->serviceDetails());
        $this->expectException(ValidationException::class);
        $groups->create($this->f['ctx'], $this->serviceDetails());
    }

    public function test_detaching_a_group_leaves_the_stored_values_alone(): void
    {
        $this->type('service', 'Services');
        $groups = app(FieldGroupService::class);
        $groups->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));
        $entry = $this->entry('service', 'Web', '/service/web');
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['short_description' => 'Kept safe']);

        $definition = $groups->show($this->f['ctx'], 'service_details');
        $groups->update($this->f['ctx'], 'service_details', [...$definition, 'rules' => ['groups' => []]]);

        // The entry no longer reads the field, because its type no longer has it…
        $this->assertArrayNotHasKey('short_description', app(EntryFields::class)->drafts($this->f['siteId'], [$entry])[$entry]);
        // …but nothing was destroyed, so re-attaching brings the values back.
        $groups->update($this->f['ctx'], 'service_details', [...$definition, 'rules' => self::onType('service')]);
        $this->assertSame('Kept safe', app(EntryFields::class)->drafts($this->f['siteId'], [$entry])[$entry]['short_description']);
    }

    public function test_deleting_a_group_in_use_must_be_confirmed(): void
    {
        $this->type('service', 'Services');
        $groups = app(FieldGroupService::class);
        $groups->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));

        try {
            $groups->delete($this->f['ctx'], 'service_details');
            $this->fail('Deleted a group that is in use');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('applies to Services', $e->getMessage());
        }
        $groups->delete($this->f['ctx'], 'service_details', confirmed: true);
        $this->assertSame(0, DB::table('field_groups')->count());
    }

    public function test_a_rule_about_something_that_no_longer_exists_is_kept_and_reported(): void
    {
        $groups = app(FieldGroupService::class);
        // Allowed on purpose: dropping the rule would quietly change where the fields appear.
        // It is reported as missing instead, so it can be repaired.
        $group = $groups->create($this->f['ctx'], $this->serviceDetails(self::onType('ghost')));
        $this->assertSame([['attribute' => 'content_type', 'value' => 'ghost']], $group['missingTargets']);
        $this->assertSame('ghost (missing)', $group['appliesTo']);
        // Nothing real is reached, because no content type is called "ghost", and asking about
        // the kinds that do exist is unaffected rather than broken.
        $this->assertSame([], app(FieldSchemaResolver::class)->groupsFor($this->f['siteId'], ['kind' => 'page']));
        $this->assertSame([], app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => 'post']));
        $this->assertSame(0, app(FieldGroupService::class)->show($this->f['ctx'], 'service_details')['entries']);
    }

    public function test_a_rule_asking_about_something_arkon_does_not_have_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails([
            'groups' => [['rules' => [['attribute' => 'wishful_thinking', 'operator' => 'is', 'value' => 'x']]]],
        ]));
    }

    public function test_groups_never_cross_between_sites(): void
    {
        $this->type('service', 'Services');
        app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));

        $other = $this->siteFixture();
        $this->assertSame([], app(FieldGroupService::class)->browse($other['ctx']));
        $this->assertSame([], app(FieldSchemaResolver::class)->fieldsFor($other['siteId'], ['kind' => 'service']));
        $this->expectException(NotFoundException::class);
        app(FieldGroupService::class)->show($other['ctx'], 'service_details');
    }

    public function test_only_people_who_may_manage_schemas_may_change_a_group(): void
    {
        $editorId = $this->createUser('editor');
        DB::table('site_members')->insert(['site_id' => $this->f['siteId'], 'user_id' => $editorId, 'role' => 'editor']);
        $editor = new SiteContext($this->f['siteId'], $editorId);

        // An editor writes content; redesigning the schema is not content.
        $this->expectException(ForbiddenException::class);
        app(FieldGroupService::class)->create($editor, $this->serviceDetails());
    }

    public function test_an_editor_may_still_read_the_list_of_groups(): void
    {
        app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails());
        $editorId = $this->createUser('editor');
        DB::table('site_members')->insert(['site_id' => $this->f['siteId'], 'user_id' => $editorId, 'role' => 'editor']);

        $this->assertCount(1, app(FieldGroupService::class)->browse(new SiteContext($this->f['siteId'], $editorId)));
    }

    /** The plan's demonstration, end to end. */
    public function test_the_service_details_demonstration(): void
    {
        $this->type('service', 'Services');
        app(FieldGroupService::class)->create($this->f['ctx'], $this->serviceDetails(self::onType('service')));
        $asset = $this->asset();
        $entry = $this->entry('service', 'Website Development', '/service/website-development');

        $saved = app(EntryFields::class)->update($this->f['ctx'], $entry, [
            'short_description' => 'Fast, accessible websites.',
            'featured_image' => ['id' => $asset],
            'starting_price' => '75000.50',
            'duration' => '4 weeks',
        ]);
        $this->assertSame('Fast, accessible websites.', $saved['short_description']);
        $this->assertSame(75000.5, $saved['starting_price'], 'a decimal stays a number');
        $this->assertSame('4 weeks', $saved['duration']);

        // Read back from storage, not from the writer's own answer.
        $reloaded = app(EntryFields::class)->drafts($this->f['siteId'], [$entry])[$entry];
        $this->assertSame(['id' => $asset], $reloaded['featured_image']);
        $this->assertSame(75000.5, $reloaded['starting_price']);
        // And the entry is publishable now that everything required is there.
        $this->assertSame('Fast, accessible websites.', app(EntryFields::class)->snapshot($this->f['ctx'], $entry, 'service')['short_description']);
    }

    private function asset(): string
    {
        $id = Uuid::v7();
        DB::table('media_assets')->insert([
            'id' => $id, 'site_id' => $this->f['siteId'], 'storage_key' => $id.'.png', 'mime' => 'image/png',
            'bytes' => 70, 'width' => 1, 'height' => 1, 'original_name' => 'dot.png',
        ]);

        return $id;
    }

    private static function sorted(array $values): array
    {
        $keys = array_keys($values);
        sort($keys);

        return $keys;
    }
}
