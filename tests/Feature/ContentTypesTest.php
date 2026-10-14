<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentTypeRegistry;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\FieldValues;
use App\Arkon\Content\RichText;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Content types a site defines for itself: the definition rules, the typed values of their
 * entries, and what each of them refuses.
 */
final class ContentTypesTest extends DatabaseTestCase
{
    /** The Services type from the brief, as a site would define it. */
    private function services(array $overrides = []): array
    {
        return [
            'key' => 'service',
            'name' => 'Services',
            'singular' => 'Service',
            'description' => 'What we do for customers',
            'icon' => 'briefcase',
            'pathPrefix' => '/services',
            'inMenu' => true,
            'menuOrder' => 10,
            'details' => true,
            'taxonomies' => ['category'],
            'fields' => [
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'settings' => ['required' => true, 'maxLength' => 300]],
                ['key' => 'body', 'label' => 'Description', 'type' => 'rich_text', 'settings' => []],
                ['key' => 'price_from', 'label' => 'Price from', 'type' => 'decimal', 'settings' => ['min' => 0, 'unit' => 'PHP']],
                ['key' => 'duration_days', 'label' => 'Duration in days', 'type' => 'number', 'settings' => ['min' => 1, 'max' => 365]],
                ['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'settings' => [], 'choices' => [
                    ['label' => 'Basic', 'value' => 'basic'],
                    ['label' => 'Premium', 'value' => 'premium'],
                ]],
                ['key' => 'featured', 'label' => 'Featured', 'type' => 'boolean', 'settings' => []],
                ['key' => 'launched_on', 'label' => 'Launched on', 'type' => 'date', 'settings' => []],
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'text', 'settings' => ['unique' => true, 'maxLength' => 40]],
            ],
            ...$overrides,
        ];
    }

    public function test_a_site_defines_a_type_and_every_consumer_sees_it_through_one_registry(): void
    {
        $f = $this->siteFixture();
        $type = app(ContentTypeService::class)->create($f['ctx'], $this->services());

        $this->assertSame('service', $type['key']);
        $this->assertSame(1, $type['schemaVersion']);
        // The registry answers for built-in kinds and defined ones alike.
        $types = app(ContentTypeRegistry::class)->forSite($f['siteId']);
        $this->assertTrue($types->exists('page'));
        $this->assertTrue($types->exists('service'));
        $this->assertSame('Service', $types->get('service')['label']);
        $this->assertSame('services', $types->get('service')['collection'], 'the API name comes from the plural');
        $this->assertCount(8, $types->fields('service'));
        $this->assertTrue($types->isDefined('service'));
        $this->assertFalse($types->isDefined('post'));
        $this->assertTrue($types->uses('service', 'category'));

        // Another site is unaffected: definitions never leak between sites.
        $other = $this->siteFixture();
        $this->assertFalse(app(ContentTypeRegistry::class)->forSite($other['siteId'])->exists('service'));
    }

    public function test_keys_are_checked_reserved_names_refused_and_never_renamed(): void
    {
        $f = $this->siteFixture();
        $service = app(ContentTypeService::class);

        foreach (['Service', '1service', 'service-type', 'a-very-long-'.str_repeat('x', 40)] as $bad) {
            try {
                $service->create($f['ctx'], $this->services(['key' => $bad]));
                $this->fail("Accepted the invalid key {$bad}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('key', strtolower($e->getMessage()));
            }
        }
        // A name the platform owns cannot be taken.
        $this->expectException(ValidationException::class);
        $service->create($f['ctx'], $this->services(['key' => 'media']));
    }

    public function test_a_duplicate_key_is_refused_and_the_key_is_immutable(): void
    {
        $f = $this->siteFixture();
        $service = app(ContentTypeService::class);
        $service->create($f['ctx'], $this->services());

        try {
            $service->create($f['ctx'], $this->services(['name' => 'Other services']));
            $this->fail('Accepted a duplicate key');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already has a content type', $e->getMessage());
        }

        // Renaming what people read is free; renaming the key is not.
        $renamed = $service->update($f['ctx'], 'service', $this->services(['name' => 'Solutions', 'singular' => 'Solution']));
        $this->assertSame('Solutions', $renamed['name']);
        $this->assertSame(1, $renamed['schemaVersion'], 'a label change is not a schema change');

        $this->expectException(ValidationException::class);
        $service->update($f['ctx'], 'service', $this->services(['key' => 'offering']));
    }

    public function test_changing_fields_raises_the_schema_version_and_removals_must_be_confirmed(): void
    {
        $f = $this->siteFixture();
        $service = app(ContentTypeService::class);
        $service->create($f['ctx'], $this->services());

        $fields = $this->services()['fields'];
        $fields[] = ['key' => 'warranty_months', 'label' => 'Warranty in months', 'type' => 'number', 'settings' => []];
        $this->assertSame(2, $service->update($f['ctx'], 'service', $this->services(['fields' => $fields]))['schemaVersion']);

        // Dropping a field would strand its values, so it is refused until confirmed.
        $fewer = array_values(array_filter($fields, fn ($field) => $field['key'] !== 'sku'));
        try {
            $service->update($f['ctx'], 'service', $this->services(['fields' => $fewer]));
            $this->fail('Removed a field without confirmation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('SKU', $e->getMessage());
        }
        $confirmed = $service->update($f['ctx'], 'service', $this->services(['fields' => $fewer, 'confirmRemovals' => true]));
        $this->assertSame(3, $confirmed['schemaVersion']);
    }

    public function test_a_field_definition_refuses_what_it_cannot_store(): void
    {
        $f = $this->siteFixture();
        $service = app(ContentTypeService::class);

        $cases = [
            'unknown type' => [['key' => 'x', 'label' => 'X', 'type' => 'telepathy', 'settings' => []]],
            'reserved key' => [['key' => 'slug', 'label' => 'Slug', 'type' => 'text', 'settings' => []]],
            'invalid key' => [['key' => 'Price Range', 'label' => 'Price', 'type' => 'text', 'settings' => []]],
            'choices missing' => [['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'settings' => [], 'choices' => []]],
            'backwards range' => [['key' => 'n', 'label' => 'N', 'type' => 'number', 'settings' => ['min' => 10, 'max' => 2]]],
        ];
        foreach ($cases as $name => $fields) {
            try {
                $service->create($f['ctx'], $this->services(['key' => 'k'.substr(md5($name), 0, 6), 'fields' => $fields]));
                $this->fail("Accepted a definition with a {$name}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_values_keep_their_type_and_are_refused_when_they_do_not_fit(): void
    {
        $f = $this->siteFixture();
        $definition = app(ContentTypeService::class)->create($f['ctx'], $this->services());

        $values = FieldValues::validate($definition, [
            'summary' => '  Websites that work.  ',
            'price_from' => '1500.50',
            'duration_days' => '14',
            'tier' => 'premium',
            'featured' => true,
            'launched_on' => '2026-03-04',
            'sku' => 'WEB-1',
        ]);
        $this->assertSame('Websites that work.', $values['summary'], 'text is trimmed');
        $this->assertSame(1500.5, $values['price_from'], 'a decimal stays a number');
        $this->assertSame(14, $values['duration_days'], 'a whole number stays an int');
        $this->assertTrue($values['featured']);
        $this->assertSame('2026-03-04', $values['launched_on'], 'dates are normalised');

        $refused = [
            'duration_days' => 'fourteen',
            'price_from' => 'free',
            'tier' => 'enterprise',
            'featured' => 'yes',
            'launched_on' => 'whenever',
            'nonsense' => 'no such field',
        ];
        foreach ($refused as $key => $value) {
            try {
                FieldValues::validate($definition, [$key => $value]);
                $this->fail("Accepted {$key} = ".var_export($value, true));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_bounds_defaults_and_publishing_requirements(): void
    {
        $f = $this->siteFixture();
        $definition = app(ContentTypeService::class)->create($f['ctx'], $this->services());

        foreach ([['duration_days' => 0], ['duration_days' => 400], ['price_from' => -1]] as $outside) {
            try {
                FieldValues::validate($definition, $outside);
                $this->fail('Accepted a value outside its bounds');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        // A draft may be incomplete; publishing applies the required rules.
        $this->assertSame([], FieldValues::validate($definition, []));
        $this->expectException(ValidationException::class);
        FieldValues::validate($definition, [], publishing: true);
    }

    public function test_rich_text_is_cleaned_before_it_is_stored(): void
    {
        $f = $this->siteFixture();
        $definition = app(ContentTypeService::class)->create($f['ctx'], $this->services());

        $values = FieldValues::validate($definition, [
            'body' => '<p>Hello <strong>there</strong></p><script>alert(1)</script><p onclick="steal()">Clean</p>'
                .'<a href="javascript:alert(1)">bad</a><a href="https://example.com">good</a><iframe src="https://evil.test"></iframe>',
        ]);
        $html = $values['body'];
        $this->assertStringContainsString('<strong>there</strong>', $html);
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('alert(1)', $html, 'a dropped script leaves no source behind');
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('iframe', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('rel="noopener nofollow ugc"', $html);
        $this->assertStringContainsString('Clean', $html, 'the words of a stripped tag are kept');
    }

    public function test_values_project_into_typed_index_rows(): void
    {
        $f = $this->siteFixture();
        $definition = app(ContentTypeService::class)->create($f['ctx'], $this->services());
        $values = FieldValues::validate($definition, ['price_from' => 1500.5, 'featured' => true, 'launched_on' => '2026-03-04', 'tier' => 'premium', 'body' => '<p>Free text</p>']);
        $rows = collect(FieldValues::index($definition, $values))->keyBy('field_key');

        $this->assertEqualsWithDelta(1500.5, $rows['price_from']['num_value'], 0.001);
        $this->assertTrue($rows['featured']['bool_value']);
        $this->assertSame('premium', $rows['tier']['text_value']);
        $this->assertSame('2026-03-04', $rows['launched_on']['date_value']);
        $this->assertFalse($rows->has('body'), 'rich text is not queryable by design');
    }

    public function test_unique_fields_are_enforced_by_the_database(): void
    {
        $f = $this->siteFixture();
        $definition = app(ContentTypeService::class)->create($f['ctx'], $this->services());
        $one = $this->addPage($f['siteId'], '/services/a', 'A');
        $two = $this->addPage($f['siteId'], '/services/b', 'B');

        foreach ([$one, $two] as $pageId) {
            $rows = FieldValues::index($definition, FieldValues::validate($definition, ['sku' => 'WEB-1']));
            $insert = array_map(fn ($row) => [...$row, 'site_id' => $f['siteId'], 'page_id' => $pageId], $rows);
            if ($pageId === $two) {
                $this->expectException(UniqueConstraintViolationException::class);
            }
            DB::table('content_field_index')->insert($insert);
        }
    }

    public function test_only_people_who_run_the_site_may_define_a_type(): void
    {
        $f = $this->siteFixture();
        $editorId = $this->createUser('editor');
        DB::table('site_members')->insert(['site_id' => $f['siteId'], 'user_id' => $editorId, 'role' => 'editor']);
        $editor = new SiteContext($f['siteId'], $editorId);

        $this->expectException(ForbiddenException::class);
        app(ContentTypeService::class)->create($editor, $this->services());
    }

    public function test_a_type_is_turned_off_rather_than_deleted_while_it_holds_entries(): void
    {
        $f = $this->siteFixture();
        $service = app(ContentTypeService::class);
        $service->create($f['ctx'], $this->services());
        $entry = $this->addPage($f['siteId'], '/services/web', 'Web');
        DB::table('pages')->where('id', $entry)->update(['kind' => 'service']);

        try {
            $service->delete($f['ctx'], 'service');
            $this->fail('Deleted a type that still holds entries');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('still holds 1 entry', $e->getMessage());
        }
        // Turning it off keeps the entries and the definition.
        $this->assertFalse($service->setActive($f['ctx'], 'service', false)['active']);
        $this->assertFalse(app(ContentTypeRegistry::class)->forSite($f['siteId'])->get('service')['active']);

        DB::table('page_drafts')->where('page_id', $entry)->delete();
        DB::table('pages')->where('id', $entry)->delete();
        $service->delete($f['ctx'], 'service');
        $this->assertSame(0, DB::table('content_types')->where('site_id', $f['siteId'])->count());
    }

    public function test_the_browse_list_counts_entries_for_built_in_and_defined_types(): void
    {
        $f = $this->siteFixture();
        app(ContentTypeService::class)->create($f['ctx'], $this->services());
        $entry = $this->addPage($f['siteId'], '/services/web', 'Web');
        DB::table('pages')->where('id', $entry)->update(['kind' => 'service']);

        $list = collect(app(ContentTypeService::class)->browse($f['ctx']))->keyBy('key');
        $this->assertSame(1, $list['page']['entries'], 'the fixture home page');
        $this->assertSame(1, $list['service']['entries']);
        $this->assertSame(8, $list['service']['fields']);
        $this->assertFalse($list['page']['defined']);
        $this->assertTrue($list['service']['defined']);
    }

    public function test_sanitised_html_survives_a_round_trip_unchanged(): void
    {
        $clean = RichText::sanitize('<p>Already <em>clean</em></p>');
        $this->assertSame($clean, RichText::sanitize($clean), 'sanitising twice changes nothing');
    }

    public function test_the_definition_is_stored_as_given_and_read_back_whole(): void
    {
        $f = $this->siteFixture();
        app(ContentTypeService::class)->create($f['ctx'], $this->services());
        $stored = Json::decode(DB::table('content_types')->where('site_id', $f['siteId'])->value('definition'));

        $this->assertSame('Services', $stored['name']);
        $this->assertSame('/services', $stored['pathPrefix']);
        $this->assertSame('summary', $stored['fields'][0]['key']);
        $this->assertTrue($stored['fields'][0]['settings']['required']);
        $this->assertSame([['label' => 'Basic', 'value' => 'basic'], ['label' => 'Premium', 'value' => 'premium']], $stored['fields'][4]['choices']);
    }
}
