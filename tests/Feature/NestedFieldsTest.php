<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldSchema;
use App\Arkon\Content\FieldValues;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Group and repeater fields: structured values that keep their shape and their types all the way
 * through validation and storage, not JSON hidden in a text box.
 */
final class NestedFieldsTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    /** An address group and an FAQ repeater: the two shapes the plan asks for. */
    private function definition(array $overrides = []): array
    {
        return [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [
                ['key' => 'address', 'label' => 'Address', 'type' => 'group', 'settings' => (object) [], 'fields' => [
                    ['key' => 'street', 'label' => 'Street', 'type' => 'text', 'settings' => (object) []],
                    ['key' => 'city', 'label' => 'City', 'type' => 'text', 'settings' => ['required' => true]],
                    ['key' => 'postcode', 'label' => 'Postal code', 'type' => 'text', 'settings' => (object) []],
                    ['key' => 'since', 'label' => 'Here since', 'type' => 'number', 'settings' => ['min' => 1800]],
                ]],
                ['key' => 'faqs', 'label' => 'FAQs', 'type' => 'repeater', 'settings' => ['maxRows' => 5, 'rowLabel' => 'question', 'addLabel' => 'Add FAQ'], 'fields' => [
                    ['key' => 'question', 'label' => 'Question', 'type' => 'text', 'settings' => ['required' => true]],
                    ['key' => 'answer', 'label' => 'Answer', 'type' => 'textarea', 'settings' => (object) []],
                    ['key' => 'featured', 'label' => 'Featured', 'type' => 'boolean', 'settings' => (object) []],
                ]],
            ],
            ...$overrides,
        ];
    }

    private function entry(): array
    {
        $definition = app(ContentTypeService::class)->create($this->f['ctx'], $this->definition());
        $id = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Web development'])['id'];

        return [$definition, $id];
    }

    public function test_a_group_definition_keeps_its_children_with_their_own_settings(): void
    {
        [$definition] = $this->entry();
        $address = $definition['fields'][0];

        $this->assertSame('group', $address['type']);
        $this->assertSame(['street', 'city', 'postcode', 'since'], array_column($address['fields'], 'key'));
        $this->assertTrue($address['fields'][1]['settings']['required'], 'a child keeps its own settings');
        $this->assertSame(1800, $address['fields'][3]['settings']['min'], 'a number child keeps its bounds');
        $this->assertSame(5, $definition['fields'][1]['settings']['maxRows'], 'the repeater keeps its row limit');
        $this->assertSame('Add FAQ', $definition['fields'][1]['settings']['addLabel']);
    }

    public function test_a_group_stores_an_object_and_a_repeater_stores_rows(): void
    {
        [, $entry] = $this->entry();
        $saved = app(EntryFields::class)->update($this->f['ctx'], $entry, [
            'address' => ['street' => '12 Mabini St', 'city' => 'Tarlac', 'since' => '1994'],
            'faqs' => [
                ['question' => 'What does it cost?', 'answer' => 'From ₱75,000.', 'featured' => true],
                ['question' => 'How long does it take?', 'answer' => 'About four weeks.'],
            ],
        ]);

        // The group is an object whose members keep their types.
        $this->assertSame('Tarlac', $saved['address']['city']);
        $this->assertSame(1994, $saved['address']['since'], 'a number inside a group is still a number');
        $this->assertArrayNotHasKey('postcode', $saved['address'], 'nothing given is nothing stored');

        // The repeater is a list of rows, each with a stable id of its own.
        $this->assertCount(2, $saved['faqs']);
        $this->assertSame('What does it cost?', $saved['faqs'][0]['question']);
        $this->assertTrue($saved['faqs'][0]['featured']);
        $this->assertArrayNotHasKey('featured', $saved['faqs'][1]);
        $this->assertNotSame($saved['faqs'][0]['_id'], $saved['faqs'][1]['_id']);
    }

    public function test_values_come_back_from_storage_exactly_as_they_went_in(): void
    {
        [, $entry] = $this->entry();
        $given = [
            'address' => ['street' => '12 Mabini St', 'city' => 'Tarlac'],
            'faqs' => [['question' => 'One?', 'answer' => 'Yes.'], ['question' => 'Two?', 'featured' => false]],
        ];
        $saved = app(EntryFields::class)->update($this->f['ctx'], $entry, $given);
        $reloaded = app(EntryFields::class)->drafts($this->f['siteId'], [$entry])[$entry];

        $this->assertSame($saved, $reloaded);
        $this->assertFalse($reloaded['faqs'][1]['featured'], 'false is a value, not an absence');
        // And what is in the database is structured, not a string.
        $stored = Json::toArray(Json::decode(DB::table('page_drafts')->where('page_id', $entry)->value('fields')));
        $this->assertIsArray($stored['address']);
        $this->assertIsArray($stored['faqs'][0]);
    }

    public function test_rows_keep_their_identity_when_they_are_reordered(): void
    {
        [, $entry] = $this->entry();
        $fields = app(EntryFields::class);
        $saved = $fields->update($this->f['ctx'], $entry, [
            'faqs' => [['question' => 'First?'], ['question' => 'Second?'], ['question' => 'Third?']],
        ]);
        $ids = array_column($saved['faqs'], '_id');

        // The editor sends them back in a new order, ids and all.
        $reordered = [$saved['faqs'][2], $saved['faqs'][0], $saved['faqs'][1]];
        $after = $fields->update($this->f['ctx'], $entry, ['faqs' => $reordered]);

        $this->assertSame(['Third?', 'First?', 'Second?'], array_column($after['faqs'], 'question'));
        $this->assertSame([$ids[2], $ids[0], $ids[1]], array_column($after['faqs'], '_id'), 'rows kept their identity');
    }

    public function test_an_empty_row_is_dropped_and_an_empty_group_stores_nothing(): void
    {
        [, $entry] = $this->entry();
        $saved = app(EntryFields::class)->update($this->f['ctx'], $entry, [
            'address' => ['street' => '', 'city' => '', 'postcode' => ''],
            'faqs' => [['question' => 'Real one?'], ['question' => '', 'answer' => '']],
        ]);

        $this->assertArrayNotHasKey('address', $saved, 'a group nobody filled in is not stored');
        $this->assertCount(1, $saved['faqs'], 'a row nobody filled in is not stored');
    }

    public function test_a_value_inside_a_structure_is_checked_like_any_other(): void
    {
        [, $entry] = $this->entry();
        $fields = app(EntryFields::class);

        $refused = [
            'a number that is not one' => ['address' => ['since' => 'ages ago']],
            'a number below its minimum' => ['address' => ['since' => 1700]],
            'a toggle that is not one' => ['faqs' => [['question' => 'Q', 'featured' => 'yes']]],
            'a field the group does not have' => ['address' => ['planet' => 'Earth']],
            'a group given a list' => ['address' => [['city' => 'Tarlac']]],
            'a repeater given an object' => ['faqs' => ['question' => 'Q']],
        ];
        foreach ($refused as $name => $values) {
            try {
                $fields->update($this->f['ctx'], $entry, $values);
                $this->fail("Accepted {$name}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_error_inside_a_repeater_says_which_row_it_means(): void
    {
        [, $entry] = $this->entry();
        try {
            app(EntryFields::class)->update($this->f['ctx'], $entry, [
                'faqs' => [['question' => 'Fine'], ['question' => 'Also fine', 'featured' => 'nonsense']],
            ]);
            $this->fail('Accepted a bad value inside a row');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('FAQs row 2', $e->getMessage());
            $this->assertStringContainsString('Featured', $e->getMessage());
        }
    }

    public function test_a_required_field_inside_a_structure_is_needed_only_to_publish(): void
    {
        [, $entry] = $this->entry();
        $fields = app(EntryFields::class);
        // A draft may be incomplete: the group has a city missing and a row has no question.
        $fields->update($this->f['ctx'], $entry, ['address' => ['street' => '12 Mabini St']]);

        try {
            $fields->snapshot($this->f['ctx'], $entry, 'service');
            $this->fail('Published with a required field missing inside a group');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('City', $e->getMessage());
        }
        $fields->update($this->f['ctx'], $entry, ['address' => ['street' => '12 Mabini St', 'city' => 'Tarlac']]);
        $this->assertSame('Tarlac', $fields->snapshot($this->f['ctx'], $entry, 'service')['address']['city']);
    }

    public function test_how_many_rows_a_repeater_may_hold_is_enforced(): void
    {
        [, $entry] = $this->entry();
        $rows = array_map(fn ($i) => ['question' => "Question {$i}?"], range(1, 6));

        $this->expectException(ValidationException::class);
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['faqs' => $rows]);
    }

    public function test_media_keeps_working_inside_a_structure(): void
    {
        $asset = Uuid::v7();
        DB::table('media_assets')->insert([
            'id' => $asset, 'site_id' => $this->f['siteId'], 'storage_key' => $asset.'.png', 'mime' => 'image/png',
            'bytes' => 70, 'width' => 1, 'height' => 1, 'original_name' => 'dot.png',
        ]);
        app(ContentTypeService::class)->create($this->f['ctx'], $this->definition(['fields' => [
            ['key' => 'people', 'label' => 'People', 'type' => 'repeater', 'settings' => (object) [], 'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'photo', 'label' => 'Photo', 'type' => 'media', 'settings' => (object) []],
            ]],
        ]]));
        $entry = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => 'Team'])['id'];

        $saved = app(EntryFields::class)->update($this->f['ctx'], $entry, [
            'people' => [['name' => 'Alex', 'photo' => ['id' => $asset]]],
        ]);
        $this->assertSame(['id' => $asset], $saved['people'][0]['photo']);

        $this->expectException(ValidationException::class);
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['people' => [['photo' => 'not-an-asset']]]);
    }

    public function test_a_structure_may_not_hold_another_structure(): void
    {
        $nested = ['key' => 'outer', 'label' => 'Outer', 'type' => 'repeater', 'settings' => (object) [], 'fields' => [
            ['key' => 'inner', 'label' => 'Inner', 'type' => 'group', 'settings' => (object) [], 'fields' => [
                ['key' => 'deep', 'label' => 'Deep', 'type' => 'text', 'settings' => (object) []],
            ]],
        ]];
        try {
            FieldSchema::fields([$nested]);
            $this->fail('Accepted a structure inside a structure');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot go inside another group or repeater', $e->getMessage());
        }
    }

    public function test_a_structure_needs_fields_of_its_own(): void
    {
        foreach (['group', 'repeater'] as $type) {
            try {
                FieldSchema::fields([['key' => 'empty', 'label' => 'Empty', 'type' => $type, 'settings' => (object) [], 'fields' => []]]);
                $this->fail("Accepted an empty {$type}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('needs at least one field inside it', $e->getMessage());
            }
        }
    }

    public function test_children_are_checked_by_the_same_engine_as_any_other_field(): void
    {
        $bad = [
            'a child with a reserved key' => ['key' => 'slug', 'label' => 'Slug', 'type' => 'text', 'settings' => (object) []],
            'a child with an unknown type' => ['key' => 'x', 'label' => 'X', 'type' => 'telepathy', 'settings' => (object) []],
            'a child with a setting its type has no use for' => ['key' => 'x', 'label' => 'X', 'type' => 'boolean', 'settings' => ['unique' => true]],
        ];
        foreach ($bad as $name => $child) {
            try {
                FieldSchema::fields([['key' => 'g', 'label' => 'G', 'type' => 'group', 'settings' => (object) [], 'fields' => [$child]]]);
                $this->fail("Accepted {$name}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        // Two children of one structure may not share a key either.
        $this->expectException(ValidationException::class);
        FieldSchema::fields([['key' => 'g', 'label' => 'G', 'type' => 'group', 'settings' => (object) [], 'fields' => [
            ['key' => 'same', 'label' => 'One', 'type' => 'text', 'settings' => (object) []],
            ['key' => 'same', 'label' => 'Two', 'type' => 'text', 'settings' => (object) []],
        ]]]);
    }

    public function test_structured_values_are_not_put_into_the_query_index(): void
    {
        [$definition, $entry] = $this->entry();
        app(EntryFields::class)->update($this->f['ctx'], $entry, [
            'address' => ['city' => 'Tarlac'],
            'faqs' => [['question' => 'Q']],
        ]);
        // Deliberate: a structure is not a scalar, so it is not something a list filters on.
        $this->assertSame(0, DB::table('content_field_index')->where('page_id', $entry)->count());
        $this->assertSame([], FieldValues::index($definition, ['address' => ['city' => 'Tarlac']]));
    }

    /** The plan's demonstration: Service Details with three FAQs, saved, reloaded and reordered. */
    public function test_the_faq_demonstration(): void
    {
        [, $entry] = $this->entry();
        $fields = app(EntryFields::class);
        $saved = $fields->update($this->f['ctx'], $entry, ['faqs' => [
            ['question' => 'What does it cost?', 'answer' => 'From ₱75,000.'],
            ['question' => 'How long does it take?', 'answer' => 'About four weeks.'],
            ['question' => 'Do you maintain it?', 'answer' => 'Yes, monthly.'],
        ]]);
        $this->assertCount(3, $saved['faqs']);

        $reloaded = $fields->drafts($this->f['siteId'], [$entry])[$entry];
        $this->assertSame('About four weeks.', $reloaded['faqs'][1]['answer']);

        // Move the last one first and change its answer; the others are untouched.
        $rows = [$reloaded['faqs'][2], $reloaded['faqs'][0], $reloaded['faqs'][1]];
        $rows[0]['answer'] = 'Yes — monthly care.';
        $after = $fields->update($this->f['ctx'], $entry, ['faqs' => $rows]);

        $this->assertSame('Do you maintain it?', $after['faqs'][0]['question']);
        $this->assertSame('Yes — monthly care.', $after['faqs'][0]['answer']);
        $this->assertSame('What does it cost?', $after['faqs'][1]['question']);
        $this->assertSame('From ₱75,000.', $after['faqs'][1]['answer'], 'the rows that moved kept their values');
    }
}
