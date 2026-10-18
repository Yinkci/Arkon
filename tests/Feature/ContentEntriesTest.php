<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Entries of a defined content type: their values ride the draft, project into the query index,
 * and meet the type's rules before they go live.
 */
final class ContentEntriesTest extends DatabaseTestCase
{
    private function site(): array
    {
        $f = $this->siteFixture();
        app(ContentTypeService::class)->create($f['ctx'], [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'briefcase',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 10, 'details' => true, 'taxonomies' => [],
            'fields' => [
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'settings' => ['required' => true]],
                ['key' => 'price_from', 'label' => 'Price from', 'type' => 'decimal', 'settings' => ['min' => 0]],
                ['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'settings' => [], 'choices' => [
                    ['label' => 'Basic', 'value' => 'basic'], ['label' => 'Premium', 'value' => 'premium'],
                ]],
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'text', 'settings' => ['unique' => true]],
            ],
        ]);

        return $f;
    }

    private function entry(array $f, string $path, string $title): string
    {
        $id = $this->addPage($f['siteId'], $path, $title);
        DB::table('pages')->where('id', $id)->update(['kind' => 'service']);

        return $id;
    }

    public function test_values_are_stored_on_the_draft_and_read_back_typed(): void
    {
        $f = $this->site();
        $id = $this->entry($f, '/services/web', 'Web development');
        $fields = app(EntryFields::class);

        $saved = $fields->update($f['ctx'], $id, ['summary' => 'Sites that work', 'price_from' => '1500.50', 'tier' => 'premium']);
        $this->assertSame('Sites that work', $saved['summary']);
        $this->assertSame(1500.5, $saved['price_from']);

        // Stored beside the document, on the draft, so it versions and publishes with it.
        // Compared by key, because jsonb keeps its own key order.
        $stored = Json::decode(DB::table('page_drafts')->where('page_id', $id)->value('fields'));
        ksort($stored);
        $this->assertSame(['price_from' => 1500.5, 'summary' => 'Sites that work', 'tier' => 'premium'], $stored);
    }

    public function test_an_update_leaves_fields_it_does_not_mention_alone_and_null_clears_one(): void
    {
        $f = $this->site();
        $id = $this->entry($f, '/services/web', 'Web');
        $fields = app(EntryFields::class);
        $fields->update($f['ctx'], $id, ['summary' => 'First', 'tier' => 'basic']);

        $after = $fields->update($f['ctx'], $id, ['tier' => 'premium']);
        $this->assertSame('First', $after['summary'], 'a field nobody mentioned keeps its value');
        $this->assertSame('premium', $after['tier']);

        $cleared = $fields->update($f['ctx'], $id, ['tier' => null]);
        $this->assertArrayNotHasKey('tier', $cleared);
        $this->assertSame('First', $cleared['summary']);
    }

    public function test_values_are_projected_into_the_index_and_rewritten_on_every_save(): void
    {
        $f = $this->site();
        $id = $this->entry($f, '/services/web', 'Web');
        $fields = app(EntryFields::class);
        $fields->update($f['ctx'], $id, ['summary' => 'A', 'price_from' => 2000, 'tier' => 'basic']);

        $rows = DB::table('content_field_index')->where('page_id', $id)->pluck('text_value', 'field_key');
        $this->assertSame('basic', $rows['tier']);
        $this->assertEqualsWithDelta(2000, (float) DB::table('content_field_index')->where('page_id', $id)->where('field_key', 'price_from')->value('num_value'), 0.001);

        // Saving again replaces the rows rather than adding to them.
        $fields->update($f['ctx'], $id, ['tier' => 'premium']);
        $this->assertSame(1, DB::table('content_field_index')->where('page_id', $id)->where('field_key', 'tier')->count());
        $this->assertSame('premium', DB::table('content_field_index')->where('page_id', $id)->where('field_key', 'tier')->value('text_value'));
    }

    public function test_a_unique_field_refuses_a_value_another_entry_already_uses(): void
    {
        $f = $this->site();
        $one = $this->entry($f, '/services/a', 'A');
        $two = $this->entry($f, '/services/b', 'B');
        $fields = app(EntryFields::class);
        $fields->update($f['ctx'], $one, ['sku' => 'WEB-1']);

        try {
            $fields->update($f['ctx'], $two, ['sku' => 'WEB-1']);
            $this->fail('Accepted a duplicate value in a unique field');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('SKU', $e->getMessage());
        }
        // The refused save left nothing behind.
        $this->assertSame([], app(EntryFields::class)->drafts($f['siteId'], [$two])[$two]);
    }

    public function test_the_index_follows_an_entry_that_is_deleted(): void
    {
        $f = $this->site();
        $id = $this->entry($f, '/services/web', 'Web');
        app(EntryFields::class)->update($f['ctx'], $id, ['summary' => 'A', 'tier' => 'basic']);
        $this->assertSame(2, DB::table('content_field_index')->where('page_id', $id)->count());

        // Saving values records a revision, and revisions are append-only, so an entry leaves the
        // way it does in the product: the Trash, then Delete permanently.
        $version = (int) DB::table('page_drafts')->where('page_id', $id)->value('version');
        app(PageManagement::class)->delete($f['ctx'], ['pageId' => $id, 'expectedVersion' => $version]);
        app(PageManagement::class)->purge($f['ctx'], $id);
        $this->assertSame(0, DB::table('content_field_index')->where('page_id', $id)->count(), 'the derived rows go with the entry');
    }

    public function test_a_draft_may_be_incomplete_but_publishing_applies_the_required_rules(): void
    {
        $f = $this->site();
        $id = $this->entry($f, '/services/web', 'Web');
        $fields = app(EntryFields::class);
        $fields->update($f['ctx'], $id, ['tier' => 'basic']);

        try {
            $fields->snapshot($f['ctx'], $id, 'service');
            $this->fail('Published an entry without its required summary');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Summary', $e->getMessage());
        }
        $fields->update($f['ctx'], $id, ['summary' => 'Now complete']);
        $this->assertSame(['summary' => 'Now complete', 'tier' => 'basic'], $fields->snapshot($f['ctx'], $id, 'service'));
    }

    public function test_values_of_a_field_the_type_no_longer_defines_stop_being_read(): void
    {
        $f = $this->site();
        $id = $this->entry($f, '/services/web', 'Web');
        $fields = app(EntryFields::class);
        $fields->update($f['ctx'], $id, ['summary' => 'A', 'sku' => 'WEB-9']);

        $definition = app(ContentTypeService::class)->show($f['ctx'], 'service');
        $kept = array_values(array_filter($definition['fields'], fn ($field) => $field['key'] !== 'sku'));
        app(ContentTypeService::class)->update($f['ctx'], 'service', [...$definition, 'fields' => $kept, 'confirmRemovals' => true]);

        $read = $fields->drafts($f['siteId'], [$id])[$id];
        $this->assertArrayNotHasKey('sku', $read, 'a removed field is not read back');
        $this->assertSame('A', $read['summary']);
        // The value is still in storage, so putting the field back brings it back.
        $this->assertSame('WEB-9', Json::decode(DB::table('page_drafts')->where('page_id', $id)->value('fields'))['sku']);
        $this->assertSame(0, DB::table('content_field_index')->where('page_id', $id)->where('field_key', 'sku')->count());
    }

    public function test_a_unique_value_is_held_through_the_trash_and_released_by_a_purge(): void
    {
        $f = $this->site();
        $one = $this->entry($f, '/services/a', 'A');
        $two = $this->entry($f, '/services/b', 'B');
        $fields = app(EntryFields::class);
        $fields->update($f['ctx'], $one, ['sku' => 'WEB-1']);

        // In the Trash it is still restorable, so its reference code stays reserved.
        $version = (int) DB::table('page_drafts')->where('page_id', $one)->value('version');
        app(PageManagement::class)->delete($f['ctx'], ['pageId' => $one, 'expectedVersion' => $version]);
        try {
            $fields->update($f['ctx'], $two, ['sku' => 'WEB-1']);
            $this->fail('A trashed entry released its unique value, so restoring it could fail');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // Deleted permanently, it is gone: the value is free for somebody else.
        app(PageManagement::class)->purge($f['ctx'], $one);
        $this->assertSame(0, DB::table('content_field_index')->where('page_id', $one)->count());
        $this->assertSame('WEB-1', $fields->update($f['ctx'], $two, ['sku' => 'WEB-1'])['sku']);
    }

    public function test_a_built_in_kind_has_no_fields_to_set(): void
    {
        $f = $this->site();
        $this->expectException(ValidationException::class);
        app(EntryFields::class)->update($f['ctx'], $f['pageId'], ['summary' => 'Pages have no fields']);
    }
}
