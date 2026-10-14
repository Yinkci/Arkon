<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentTypeRegistry;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldTypes;
use App\Arkon\Content\TypeDefinition;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * The shape a content type is sent in, from the browser's JSON to the database and back.
 *
 * A request body is decoded in "raw" form, where an empty JSON object stays a stdClass so a
 * document's `{}` is never read as `[]`. A definition has no such distinction, so `settings: {}`
 * must be as acceptable as `settings: {"required": true}`. That asymmetry is what broke the
 * first content type anyone tried to create, and these tests hold the contract in place.
 */
final class ContentTypeContractTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'contract.test');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
    }

    /** The body the admin screen sends, as JSON, exactly as the browser would. */
    private function sendJson(string $path, array $body)
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], Json::encode($body));
    }

    public function test_the_service_type_from_the_report_saves_with_fields_that_have_no_settings(): void
    {
        // Precisely what the screen sent: two fields, neither with a single setting chosen.
        $body = [
            'key' => 'service', 'name' => 'services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '', 'inMenu' => true, 'menuOrder' => 10, 'details' => true, 'taxonomies' => [],
            'fields' => [
                ['key' => 'service_name', 'label' => 'Service Name', 'type' => 'text', 'settings' => (object) [], 'choices' => []],
                ['key' => 'services_image', 'label' => 'Services Image', 'type' => 'media', 'settings' => (object) [], 'choices' => []],
            ],
        ];
        $this->sendJson('/admin/api/content-types', $body)->assertOk()->assertJsonPath('data.key', 'service');

        $types = app(ContentTypeRegistry::class)->forSite($this->f['siteId']);
        $this->assertSame(['service_name', 'services_image'], array_column($types->fields('service'), 'key'));
        // The plural is kept as it was typed: display names are the author's, not the system's.
        $this->assertSame('services', $types->get('service')['plural']);

        // Reloading the editor gives the definition back whole.
        $this->get('/admin/content-types/service')->assertOk();
        $shown = app(ContentTypeService::class)->show($this->f['ctx'], 'service');
        $this->assertSame('Services Image', $shown['fields'][1]['label']);
        $this->assertSame('media', $shown['fields'][1]['type']);
        $this->assertIsArray($shown['fields'][1]['settings'], 'settings come back as a map, never a string or a list');
    }

    public function test_settings_are_accepted_as_a_map_and_refused_as_anything_else(): void
    {
        $base = [
            'key' => 'thing', 'name' => 'Things', 'singular' => 'Thing', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '', 'inMenu' => true, 'menuOrder' => 10, 'details' => false, 'taxonomies' => [],
        ];
        $field = fn (mixed $settings) => ['key' => 'a', 'label' => 'A', 'type' => 'text', 'settings' => $settings];

        // Every honest spelling of "no settings" is accepted.
        foreach ([[], (object) []] as $empty) {
            $this->assertSame([], array_filter(TypeDefinition::validate([...$base, 'fields' => [$field($empty)]])['fields'][0]['settings'], fn ($v) => $v !== '' && $v !== false && $v !== null));
        }
        // A real setting survives with its type.
        $withSetting = TypeDefinition::validate([...$base, 'fields' => [$field(['required' => true, 'maxLength' => 40])]])['fields'][0];
        $this->assertTrue($withSetting['settings']['required']);
        $this->assertSame(40, $withSetting['settings']['maxLength']);

        // A JSON string, a scalar or a list is refused; nothing is quietly decoded for the caller.
        foreach (['{}', '{"required":true}', 5, true, [['required' => true]]] as $malformed) {
            try {
                TypeDefinition::validate([...$base, 'fields' => [$field($malformed)]]);
                $this->fail('Accepted settings of the wrong shape: '.var_export($malformed, true));
            } catch (ValidationException $e) {
                $this->assertStringContainsString('A:', $e->getMessage(), 'the message names the field');
            }
        }
    }

    public function test_a_setting_that_does_not_belong_to_the_type_is_refused_by_name(): void
    {
        $base = [
            'key' => 'thing', 'name' => 'Things', 'singular' => 'Thing', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '', 'inMenu' => true, 'menuOrder' => 10, 'details' => false, 'taxonomies' => [],
        ];
        // "unique" belongs to text, not to a toggle.
        try {
            TypeDefinition::validate([...$base, 'fields' => [['key' => 'on', 'label' => 'On', 'type' => 'boolean', 'settings' => ['unique' => true]]]]);
            $this->fail('Accepted a setting the field type has no use for');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('toggle field has no "unique" setting', $e->getMessage());
        }
        // A setting nobody has heard of is refused rather than stored forever.
        try {
            TypeDefinition::validate([...$base, 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text', 'settings' => ['colour' => 'red']]]]);
            $this->fail('Accepted an unknown setting');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not a setting Arkon knows', $e->getMessage());
        }
    }

    public function test_every_field_type_survives_create_reload_and_a_second_save_unchanged(): void
    {
        // One type carrying one field of every kind Arkon offers, each with no settings chosen:
        // the case that failed, applied to the whole registry rather than two types.
        $fields = [];
        foreach (FieldTypes::registry()['types'] as $type => $info) {
            $field = ['key' => 'f_'.$type, 'label' => ucfirst(str_replace('_', ' ', $type)), 'type' => $type, 'settings' => (object) []];
            if ($info['choices'] ?? false) {
                $field['choices'] = [['label' => 'One', 'value' => 'one'], ['label' => 'Two', 'value' => 'two']];
            }
            if ($info['children'] ?? false) {
                $field['fields'] = [
                    ['key' => 'inner_text', 'label' => 'Inner text', 'type' => 'text', 'settings' => (object) []],
                    ['key' => 'inner_number', 'label' => 'Inner number', 'type' => 'number', 'settings' => (object) []],
                ];
            }
            // A relationship has to say what it relates to; a type may relate to itself.
            if ($type === 'relationship') {
                $field['settings'] = ['targetType' => 'field_test'];
            }
            // Content sections have to offer at least one layout, with fields of its own.
            if ($info['layouts'] ?? false) {
                $field['layouts'] = [['key' => 'a_layout', 'label' => 'A layout', 'fields' => [
                    ['key' => 'inner_text', 'label' => 'Inner text', 'type' => 'text', 'settings' => (object) []],
                ]]];
            }
            $fields[] = $field;
        }
        $body = [
            'key' => 'field_test', 'name' => 'Field tests', 'singular' => 'Field test', 'description' => 'One of every kind',
            'icon' => 'component', 'pathPrefix' => '/field-tests', 'inMenu' => true, 'menuOrder' => 1, 'details' => true,
            'taxonomies' => [], 'fields' => $fields,
        ];
        $this->sendJson('/admin/api/content-types', $body)->assertOk();

        $created = app(ContentTypeService::class)->show($this->f['ctx'], 'field_test');
        $this->assertCount(count(FieldTypes::registry()['types']), $created['fields']);

        // Saving what was just loaded, unchanged, must not count as a change: the shape that
        // comes out is the shape that goes back in.
        $this->sendJson('/admin/api/content-types/field_test', [...$created, 'expectedVersion' => $created['schemaVersion']])->assertOk();
        $again = app(ContentTypeService::class)->show($this->f['ctx'], 'field_test');
        $this->assertSame(1, $again['schemaVersion'], 'a round trip through the editor is not a schema change');
        $this->assertEquals($created['fields'], $again['fields']);
    }

    public function test_every_field_type_stores_and_reads_back_a_value_of_its_own_kind(): void
    {
        $asset = $this->mediaAsset();
        $cases = [
            'text' => ['Hello', 'Hello'],
            'textarea' => ["Two\nlines", "Two\nlines"],
            'rich_text' => ['<p>Kept</p>', '<p>Kept</p>'],
            'number' => ['42', 42],
            'decimal' => ['1.5', 1.5],
            'boolean' => [true, true],
            'select' => ['one', 'one'],
            'multi_select' => [['one', 'two'], ['one', 'two']],
            'email' => ['a@b.test', 'a@b.test'],
            'url' => ['https://example.test', 'https://example.test'],
            'phone' => ['+63 900 000 0000', '+63 900 000 0000'],
            'date' => ['2026-03-04', '2026-03-04'],
            'datetime' => ['2026-03-04T10:00:00+00:00', '2026-03-04T10:00:00+00:00'],
            'time' => ['14:30', '14:30'],
            'color' => ['#336699', '#336699'],
            'media' => [['id' => $asset], ['id' => $asset]],
            'gallery' => [[['id' => $asset]], [['id' => $asset]]],
            'group' => [['inner_text' => 'Inside', 'inner_number' => 7], ['inner_text' => 'Inside', 'inner_number' => 7]],
            'repeater' => [[['inner_text' => 'Row one']], [['inner_text' => 'Row one']]],
            'flexible' => [
                [['_layout' => 'a_layout', 'inner_text' => 'In a section']],
                [['_layout' => 'a_layout', 'inner_text' => 'In a section']],
            ],
            // Filled in below: a reference needs a real entry to point at.
            'relationship' => [null, null],
        ];
        $this->assertSame(array_keys(FieldTypes::registry()['types']), array_keys($cases), 'every registered type is exercised here');

        $fields = [];
        foreach ($cases as $type => $_) {
            $field = ['key' => 'f_'.$type, 'label' => ucfirst($type), 'type' => $type, 'settings' => (object) []];
            if (FieldTypes::takesChoices($type)) {
                $field['choices'] = [['label' => 'One', 'value' => 'one'], ['label' => 'Two', 'value' => 'two']];
            }
            if (FieldTypes::takesChildren($type)) {
                $field['fields'] = [
                    ['key' => 'inner_text', 'label' => 'Inner text', 'type' => 'text', 'settings' => (object) []],
                    ['key' => 'inner_number', 'label' => 'Inner number', 'type' => 'number', 'settings' => (object) []],
                ];
            }
            if ($type === 'relationship') {
                $field['settings'] = ['targetType' => 'every'];
            }
            if (FieldTypes::takesLayouts($type)) {
                $field['layouts'] = [['key' => 'a_layout', 'label' => 'A layout', 'fields' => [
                    ['key' => 'inner_text', 'label' => 'Inner text', 'type' => 'text', 'settings' => (object) []],
                ]]];
            }
            $fields[] = $field;
        }
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'every', 'name' => 'Everies', 'singular' => 'Every', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/every', 'inMenu' => false, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [], 'fields' => $fields,
        ]);
        $entry = $this->addPage($this->f['siteId'], '/every/one', 'One');
        DB::table('pages')->where('id', $entry)->update(['kind' => 'every']);
        $other = $this->addPage($this->f['siteId'], '/every/two', 'Two');
        DB::table('pages')->where('id', $other)->update(['kind' => 'every']);
        $cases['relationship'] = [['id' => $other], ['id' => $other]];

        $values = [];
        foreach ($cases as $type => [$given, $_]) {
            $values['f_'.$type] = $given;
        }
        $saved = app(EntryFields::class)->update($this->f['ctx'], $entry, $values);
        // Rows and sections keep an id of their own, which the author never typed; a section
        // also keeps the layout it is, which is part of the value.
        $withoutIds = fn ($value) => is_array($value) && array_is_list($value)
            ? array_map(fn ($row) => is_array($row) ? array_diff_key($row, ['_id' => true]) : $row, $value)
            : $value;
        foreach ($cases as $type => [$_, $expected]) {
            $this->assertSame($expected, $withoutIds($saved['f_'.$type]), "{$type} did not come back as it went in");
        }
        // And again from storage, not from the writer's own return value.
        $reloaded = app(EntryFields::class)->drafts($this->f['siteId'], [$entry])[$entry];
        foreach ($cases as $type => [$_, $expected]) {
            $this->assertSame($expected, $withoutIds($reloaded['f_'.$type]), "{$type} did not survive storage");
        }
    }

    public function test_a_media_value_is_stored_as_a_reference_not_a_path(): void
    {
        $asset = $this->mediaAsset();
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'shot', 'name' => 'Shots', 'singular' => 'Shot', 'description' => '', 'icon' => 'image',
            'pathPrefix' => '/shots', 'inMenu' => false, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [['key' => 'picture', 'label' => 'Picture', 'type' => 'media', 'settings' => (object) []]],
        ]);
        $entry = $this->addPage($this->f['siteId'], '/shots/one', 'One');
        DB::table('pages')->where('id', $entry)->update(['kind' => 'shot']);

        $saved = app(EntryFields::class)->update($this->f['ctx'], $entry, ['picture' => ['id' => $asset]]);
        $this->assertSame(['id' => $asset], $saved['picture']);
        // The index keeps it as a reference, so what points at an asset can be found.
        $this->assertSame($asset, DB::table('content_field_index')->where('page_id', $entry)->where('field_key', 'picture')->value('ref_id'));

        // A browser URL or a file path is not a media reference.
        foreach (['blob:http://localhost/abc', '/media/thing.png', ['url' => 'https://example.test/a.png']] as $notAReference) {
            try {
                app(EntryFields::class)->update($this->f['ctx'], $entry, ['picture' => $notAReference]);
                $this->fail('Accepted something that is not a media reference');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('media library', $e->getMessage());
            }
        }
    }

    public function test_choices_keep_their_structure_through_a_round_trip(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'plan', 'name' => 'Plans', 'singular' => 'Plan', 'description' => '', 'icon' => 'list',
            'pathPrefix' => '', 'inMenu' => false, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'settings' => (object) [], 'choices' => [
                ['label' => 'Premium', 'value' => 'premium'],
            ]]],
        ]);
        $stored = app(ContentTypeService::class)->show($this->f['ctx'], 'plan')['fields'][0]['choices'];
        $this->assertSame([['label' => 'Premium', 'value' => 'premium']], $stored, 'a choice stays a label and a stored value');
    }

    public function test_field_identity_follows_the_key_not_the_position(): void
    {
        $fields = [
            ['key' => 'service_name', 'label' => 'Service Name', 'type' => 'text', 'settings' => ['maxLength' => 40]],
            ['key' => 'services_image', 'label' => 'Services Image', 'type' => 'media', 'settings' => (object) []],
        ];
        $base = [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 10, 'details' => true, 'taxonomies' => [],
        ];
        app(ContentTypeService::class)->create($this->f['ctx'], [...$base, 'fields' => $fields]);
        $entry = $this->addPage($this->f['siteId'], '/services/web', 'Web');
        DB::table('pages')->where('id', $entry)->update(['kind' => 'service']);
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['service_name' => 'Web development']);

        // Reordered and relabelled, but the keys are untouched: the stored value still belongs
        // to the same field, and its settings travel with it rather than with its position.
        $swapped = [
            [...$fields[1], 'label' => 'Featured Image'],
            $fields[0],
        ];
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$base, 'fields' => $swapped]);
        $after = app(ContentTypeService::class)->show($this->f['ctx'], 'service');
        $this->assertSame(['services_image', 'service_name'], array_column($after['fields'], 'key'));
        $this->assertSame('Featured Image', $after['fields'][0]['label']);
        $this->assertSame(40, $after['fields'][1]['settings']['maxLength'], 'settings stayed with their field');
        $this->assertSame('Web development', app(EntryFields::class)->drafts($this->f['siteId'], [$entry])[$entry]['service_name']);
    }

    public function test_two_fields_may_not_share_a_key(): void
    {
        try {
            TypeDefinition::validate([
                'key' => 'thing', 'name' => 'Things', 'singular' => 'Thing', 'description' => '', 'icon' => 'component',
                'pathPrefix' => '', 'inMenu' => false, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
                'fields' => [
                    ['key' => 'service_name', 'label' => 'One', 'type' => 'text', 'settings' => (object) []],
                    ['key' => 'service_name', 'label' => 'Two', 'type' => 'text', 'settings' => (object) []],
                ],
            ]);
            $this->fail('Accepted two fields with one key');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot share the key "service_name"', $e->getMessage());
        }
    }

    public function test_a_failed_save_changes_nothing_at_all(): void
    {
        $base = [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 10, 'details' => true, 'taxonomies' => [],
        ];
        // The second field is broken, so the type must not be created at all.
        $this->sendJson('/admin/api/content-types', [...$base, 'fields' => [
            ['key' => 'ok', 'label' => 'Fine', 'type' => 'text', 'settings' => (object) []],
            ['key' => 'bad', 'label' => 'Broken', 'type' => 'telepathy', 'settings' => (object) []],
        ]])->assertStatus(422);
        $this->assertSame(0, DB::table('content_types')->count(), 'nothing was half created');

        // Now a good one, then a bad update: the stored definition must be untouched.
        app(ContentTypeService::class)->create($this->f['ctx'], [...$base, 'fields' => [['key' => 'ok', 'label' => 'Fine', 'type' => 'text', 'settings' => (object) []]]]);
        $before = DB::table('content_types')->where('key', 'service')->value('definition');
        $this->sendJson('/admin/api/content-types/service', [...$base, 'fields' => [
            ['key' => 'ok', 'label' => 'Fine', 'type' => 'text', 'settings' => (object) []],
            ['key' => 'bad', 'label' => 'Broken', 'type' => 'telepathy', 'settings' => (object) []],
        ]])->assertStatus(422);
        $this->assertSame($before, DB::table('content_types')->where('key', 'service')->value('definition'), 'a refused update left the definition alone');
    }

    public function test_an_error_names_the_field_a_person_would_recognise(): void
    {
        $response = $this->sendJson('/admin/api/content-types', [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '', 'inMenu' => true, 'menuOrder' => 10, 'details' => true, 'taxonomies' => [],
            'fields' => [['key' => 'services_image', 'label' => 'Services Image', 'type' => 'media', 'settings' => ['pattern' => 'nope']]],
        ])->assertStatus(422);

        // What the person reads names the field, not an index into the request.
        $this->assertStringContainsString('Services Image', $response->json('message'));
        $this->assertStringNotContainsString('fields.0', $response->json('message'));
        // What the editor needs to put the message in the right place travels with it.
        $this->assertSame('services_image', $response->json('issues.0.fieldKey'));
        $this->assertSame('setting_not_for_type', $response->json('issues.0.code'));
    }

    /** A real asset in this site's library, for media fields to point at. */
    private function mediaAsset(): string
    {
        $id = Uuid::v7();
        DB::table('media_assets')->insert([
            'id' => $id, 'site_id' => $this->f['siteId'], 'storage_key' => $id.'.png', 'mime' => 'image/png',
            'bytes' => 70, 'width' => 1, 'height' => 1, 'original_name' => 'dot.png',
        ]);

        return $id;
    }
}
