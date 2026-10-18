<?php

namespace Tests\Feature;

use App\Arkon\Content\BindingContext;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\Relationships;
use App\Arkon\Content\SiteDataService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * What a schema change, a role, publishing and a permanent delete may and may not do to the
 * values entries and site data already hold. Each case is a failure found by the October 2026
 * audit of the content-type system.
 */
final class ContentFieldIntegrityTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function type(string $key, string $plural, array $fields, string $prefix = ''): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => $key, 'name' => $plural, 'singular' => rtrim($plural, 's'), 'description' => '', 'icon' => 'grid',
            'pathPrefix' => $prefix, 'inMenu' => true, 'menuOrder' => 10, 'details' => false, 'taxonomies' => [], 'fields' => $fields,
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

    private function definition(string $key): array
    {
        return app(ContentTypeService::class)->show($this->f['ctx'], $key);
    }

    private function stored(string $id): array
    {
        return Json::toArray(Json::decode(DB::table('page_drafts')->where('page_id', $id)->value('fields'))) ?: [];
    }

    private function publish(string $id): void
    {
        $version = (int) DB::table('page_drafts')->where('page_id', $id)->value('version');
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $id, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
    }

    private static function text(string $key, string $label, array $settings = []): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'text', 'settings' => $settings];
    }

    public function test_an_entry_still_saves_after_one_of_its_fields_is_removed(): void
    {
        $this->type('service', 'Services', [self::text('summary', 'Summary'), self::text('sku', 'SKU')]);
        $id = $this->entry('service', 'Web', ['summary' => 'A', 'sku' => 'S1']);
        $d = $this->definition('service');
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$d, 'fields' => [$d['fields'][0]], 'confirmRemovals' => true]);

        app(EntryFields::class)->update($this->f['ctx'], $id, ['summary' => 'B']);
        $this->assertSame(['summary' => 'B'], app(EntryFields::class)->drafts($this->f['siteId'], [$id])[$id]);
        $this->assertSame('S1', $this->stored($id)['sku'], 'the removed field\'s value is kept, hidden');
        $this->publish($id);
    }

    public function test_a_field_removed_from_inside_a_repeater_needs_confirming_and_leaves_rows_saveable(): void
    {
        $this->type('faq', 'Faqs', [['key' => 'faqs', 'label' => 'FAQs', 'type' => 'repeater', 'settings' => [], 'fields' => [self::text('q', 'Question'), self::text('a', 'Answer')]]]);
        $id = $this->entry('faq', 'Help', ['faqs' => [['q' => 'Why?', 'a' => 'Because']]]);
        $d = $this->definition('faq');
        $d['fields'][0]['fields'] = [$d['fields'][0]['fields'][0]];
        try {
            app(ContentTypeService::class)->update($this->f['ctx'], 'faq', $d);
            $this->fail('Removed a field inside a repeater without confirmation');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('FAQs → Answer', $e->getMessage());
        }
        app(ContentTypeService::class)->update($this->f['ctx'], 'faq', [...$d, 'confirmRemovals' => true]);

        $rows = app(EntryFields::class)->drafts($this->f['siteId'], [$id])[$id]['faqs'];
        $this->assertArrayNotHasKey('a', $rows[0]);
        app(EntryFields::class)->update($this->f['ctx'], $id, ['faqs' => $rows]);
    }

    public function test_a_role_rule_decides_what_a_person_may_edit_not_what_an_entry_holds(): void
    {
        $this->type('service', 'Services', [self::text('summary', 'Summary')]);
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'owner_notes', 'name' => 'Owner notes', 'description' => '', 'fields' => [self::text('notes', 'Notes')],
            'rules' => ['groups' => [['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => 'service'], ['attribute' => 'user_role', 'operator' => 'is', 'value' => 'owner']]]]],
        ]);
        $id = $this->entry('service', 'Web', ['notes' => 'Owner only', 'summary' => 'A']);
        $editor = $this->addMember($this->f['siteId'], 'editor');

        app(EntryFields::class)->update($editor, $id, ['summary' => 'Edited']);
        $this->assertSame('Owner only', $this->stored($id)['notes'], 'an editor\'s save keeps what only owners edit');
        $this->expectException(ValidationException::class);
        app(EntryFields::class)->update($editor, $id, ['notes' => 'Not mine to change']);
    }

    public function test_site_data_still_saves_after_a_field_leaves_it(): void
    {
        $site = ['groups' => [['rules' => [['attribute' => 'scope', 'operator' => 'is', 'value' => 'site']]]]];
        $fields = [['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'settings' => []], ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'settings' => []]];
        app(FieldGroupService::class)->create($this->f['ctx'], ['key' => 'contact', 'name' => 'Contact', 'description' => '', 'fields' => $fields, 'rules' => $site]);
        app(SiteDataService::class)->update($this->f['ctx'], ['phone' => '+63 900 000', 'email' => 'a@b.co']);
        app(FieldGroupService::class)->update($this->f['ctx'], 'contact', ['name' => 'Contact', 'description' => '', 'fields' => [$fields[1]], 'rules' => $site, 'confirmRemovals' => true]);

        app(SiteDataService::class)->update($this->f['ctx'], ['email' => 'c@d.co']);
        $this->assertSame(['email' => 'c@d.co'], (array) app(SiteDataService::class)->show($this->f['ctx'])['values']);
    }

    public function test_a_private_field_never_reaches_a_published_binding(): void
    {
        $this->type('service', 'Services', [self::text('cost', 'Internal cost', ['private' => true]), self::text('headline', 'Headline')]);
        $id = $this->entry('service', 'Web', ['cost' => 'SECRET-123', 'headline' => 'Hello']);
        $context = app(BindingContext::class)->published($this->f['siteId'], $id, $this->stored($id));

        $this->assertSame(['headline' => 'Hello'], $context['entry']);
    }

    public function test_visitors_read_the_values_that_went_live_and_revisions_restore_them(): void
    {
        $this->type('service', 'Services', [self::text('headline', 'Headline')]);
        $id = $this->entry('service', 'Web', ['headline' => 'Published words']);
        $this->publish($id);
        $published = (string) DB::table('page_drafts')->where('page_id', $id)->value('checkpoint_revision_id');
        app(EntryFields::class)->update($this->f['ctx'], $id, ['headline' => 'Unpublished words']);

        $visitor = app(EntryFields::class)->publicValues($this->f['siteId'], [$id], asVisitor: true)[$id];
        $member = app(EntryFields::class)->publicValues($this->f['siteId'], [$id], asVisitor: false)[$id];
        $this->assertSame('Published words', $visitor['headline'], 'an unpublished edit is not public');
        $this->assertSame('Unpublished words', $member['headline']);
        $this->assertSame('changed', PageService::statusOf(DB::table('page_drafts')->where('page_id', $id)->first(), $published));

        $version = (int) DB::table('page_drafts')->where('page_id', $id)->value('version');
        app(PageService::class)->restoreRevision($this->f['ctx'], ['pageId' => $id, 'revisionId' => $published, 'expectedVersion' => $version]);
        $this->assertSame('Published words', $this->stored($id)['headline']);
    }

    public function test_publishing_applies_the_required_rule(): void
    {
        $this->type('service', 'Services', [self::text('summary', 'Summary', ['required' => true])]);
        $id = $this->entry('service', 'Web');
        try {
            $this->publish($id);
            $this->fail('Published without a required value');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Summary is needed before this can be published', $e->getMessage());
        }
        app(EntryFields::class)->update($this->f['ctx'], $id, ['summary' => 'Done']);
        $this->publish($id);
        $this->assertTrue(DB::table('live_pages')->where('page_id', $id)->exists());
    }

    public function test_removing_a_field_from_one_type_leaves_another_types_index_alone(): void
    {
        $price = ['key' => 'price', 'label' => 'Price', 'type' => 'number', 'settings' => []];
        $this->type('service', 'Services', [$price, self::text('name2', 'Name')]);
        $this->type('project', 'Projects', [$price]);
        $this->entry('service', 'A', ['price' => 10]);
        $project = $this->entry('project', 'P', ['price' => 20]);
        $d = $this->definition('service');
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$d, 'fields' => [$d['fields'][1]], 'confirmRemovals' => true]);

        $this->assertSame(1, DB::table('content_field_index')->where('page_id', $project)->where('field_key', 'price')->count());
    }

    public function test_a_unique_value_is_unique_within_one_type(): void
    {
        $code = self::text('code', 'Code', ['unique' => true]);
        $this->type('service', 'Services', [$code]);
        $this->type('project', 'Projects', [$code]);
        $this->entry('service', 'A', ['code' => 'X1']);
        $this->entry('project', 'P', ['code' => 'X1']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Services');
        $this->entry('service', 'B', ['code' => 'X1']);
    }

    public function test_a_change_that_would_strand_stored_values_is_refused(): void
    {
        $this->type('service', 'Services', [self::text('size', 'Size'), self::text('summary', 'Summary')]);
        $this->entry('service', 'Big one', ['size' => 'large']);
        $d = $this->definition('service');
        $d['fields'][0] = ['key' => 'size', 'label' => 'Size', 'type' => 'number', 'settings' => []];
        try {
            app(ContentTypeService::class)->update($this->f['ctx'], 'service', $d);
            $this->fail('Changed a field to a type its stored values cannot be read as');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('“Big one”', $e->getMessage());
            $this->assertSame('values_do_not_fit', $e->issues[0]['code']);
        }
    }

    public function test_a_choice_still_in_use_cannot_be_taken_away(): void
    {
        $this->type('service', 'Services', [['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'settings' => [], 'choices' => [['label' => 'Basic', 'value' => 'basic'], ['label' => 'Gold', 'value' => 'gold']]]]);
        $this->entry('service', 'Web', ['tier' => 'gold']);
        $d = $this->definition('service');
        $d['fields'][0]['choices'] = [['label' => 'Basic', 'value' => 'basic']];

        $this->expectException(ValidationException::class);
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', $d);
    }

    public function test_a_reference_inside_a_repeater_is_counted_and_cleaned_up(): void
    {
        $this->type('team_member', 'Team members', [self::text('role', 'Role')]);
        $this->type('project', 'Projects', [['key' => 'crew', 'label' => 'Crew', 'type' => 'repeater', 'settings' => [], 'fields' => [
            ['key' => 'person', 'label' => 'Person', 'type' => 'relationship', 'settings' => ['targetType' => 'team_member']],
            self::text('task', 'Task'),
        ]]]);
        $jane = $this->entry('team_member', 'Jane');
        $project = $this->entry('project', 'Alpha', ['crew' => [['person' => ['id' => $jane], 'task' => 'Design']]]);

        $this->assertSame(1, app(Relationships::class)->usedBy($this->f['siteId'], $jane)[0]['count'] ?? 0);
        $version = (int) DB::table('page_drafts')->where('page_id', $jane)->value('version');
        app(PageManagement::class)->delete($this->f['ctx'], ['pageId' => $jane, 'expectedVersion' => $version]);
        app(PageManagement::class)->purge($this->f['ctx'], $jane);

        $this->assertArrayNotHasKey('person', $this->stored($project)['crew'][0]);
        app(EntryFields::class)->update($this->f['ctx'], $project, ['crew' => [['task' => 'Build']]]);
    }

    public function test_a_type_cannot_declare_a_key_a_group_already_gives_it(): void
    {
        $this->type('service', 'Services', [self::text('summary', 'Summary')]);
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'pricing', 'name' => 'Pricing', 'description' => '', 'fields' => [['key' => 'price', 'label' => 'Price', 'type' => 'number', 'settings' => []]],
            'rules' => ['groups' => [['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => 'service']]]]],
        ]);
        $d = $this->definition('service');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Pricing');
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$d, 'fields' => [...$d['fields'], self::text('price', 'Price text')]]);
    }

    public function test_site_data_keys_are_not_compared_with_content_type_fields(): void
    {
        $this->type('team_member', 'Team members', [['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'settings' => []]]);
        $group = app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'contact', 'name' => 'Contact', 'description' => '', 'fields' => [['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'settings' => []]],
            'rules' => ['groups' => [['rules' => [['attribute' => 'scope', 'operator' => 'is', 'value' => 'site']]]]],
        ]);

        $this->assertSame('contact', $group['key']);
    }

    public function test_dates_and_their_bounds_are_real_dates(): void
    {
        $this->type('event', 'Events', [['key' => 'starts', 'label' => 'Starts', 'type' => 'date', 'settings' => ['min' => '2026-01-01']]]);
        $this->assertSame('2026-01-01', $this->definition('event')['fields'][0]['settings']['min']);
        $id = $this->entry('event', 'Launch');
        foreach (['2026-02-31', 'next monday', '2025-12-31'] as $bad) {
            try {
                app(EntryFields::class)->update($this->f['ctx'], $id, ['starts' => $bad]);
                $this->fail("Accepted {$bad} as a date");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(['starts' => '2026-03-04'], app(EntryFields::class)->update($this->f['ctx'], $id, ['starts' => '2026-03-04']));

        $this->expectException(ValidationException::class);
        $this->type('event2', 'Meetups', [['key' => 'starts', 'label' => 'Starts', 'type' => 'date', 'settings' => ['min' => 2026]]]);
    }

    public function test_a_picture_must_be_in_this_sites_library(): void
    {
        $this->type('service', 'Services', [['key' => 'image', 'label' => 'Image', 'type' => 'media', 'settings' => []]]);
        $id = $this->entry('service', 'Web');

        $this->expectException(ValidationException::class);
        app(EntryFields::class)->update($this->f['ctx'], $id, ['image' => Uuid::v7()]);
    }

    public function test_a_pattern_that_does_not_compile_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->type('service', 'Services', [self::text('code', 'Code', ['pattern' => '(abc'])]);
    }

    public function test_a_type_still_in_use_cannot_be_deleted(): void
    {
        $this->type('team_member', 'Team members', []);
        $this->type('project', 'Projects', [['key' => 'lead', 'label' => 'Lead', 'type' => 'relationship', 'settings' => ['targetType' => 'team_member']]]);

        try {
            app(ContentTypeService::class)->delete($this->f['ctx'], 'team_member');
            $this->fail('Deleted a type another type relates to');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Projects', $e->getMessage());
        }
    }

    public function test_two_types_cannot_share_a_name_or_a_url_prefix(): void
    {
        $this->type('service', 'Services', [], '/services');
        foreach ([['offering', 'Services', '/offerings'], ['offering', 'Offerings', '/services'], ['article', 'Posts', '/articles']] as [$key, $plural, $prefix]) {
            try {
                $this->type($key, $plural, [], $prefix);
                $this->fail("Created {$plural} at {$prefix}");
            } catch (ValidationException) {
            }
        }
        $this->type('offering', 'Offerings', [], '/offerings');
        $this->assertTrue(true);
    }

    public function test_a_save_does_not_overwrite_a_change_made_in_the_meantime(): void
    {
        $this->type('service', 'Services', [self::text('summary', 'Summary')]);
        $id = $this->entry('service', 'Web', ['summary' => 'First']);
        app(EntryFields::class)->update($this->f['ctx'], $id, ['summary' => 'From another tab'], expected: ['summary' => 'First']);

        $this->expectException(ConflictException::class);
        app(EntryFields::class)->update($this->f['ctx'], $id, ['summary' => 'Stale'], expected: ['summary' => 'First']);
    }

    public function test_a_change_to_site_data_refreshes_live_pages_that_show_it(): void
    {
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'company', 'name' => 'Company', 'description' => '', 'fields' => [self::text('company_name', 'Company name')],
            'rules' => ['groups' => [['rules' => [['attribute' => 'scope', 'operator' => 'is', 'value' => 'site']]]]],
        ]);
        app(SiteDataService::class)->update($this->f['ctx'], ['company_name' => 'Old name']);
        $page = app(ContentItems::class)->create($this->f['ctx'], 'page', ['title' => 'About', 'path' => '/about-us'])['id'];
        DB::table('page_drafts')->where('page_id', $page)->update(['document' => Json::encode([
            'schemaVersion' => 1, 'root' => 'root0001', 'seo' => new \stdClass,
            'nodes' => [
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new \stdClass, 'children' => ['hero0001']],
                'hero0001' => ['id' => 'hero0001', 'type' => 'hero', 'version' => 5, 'children' => [],
                    'props' => ['heading' => 'Fallback', 'headingLevel' => 'h1', 'text' => '', 'image' => null],
                    'bindings' => ['heading' => ['source' => 'site', 'field' => 'company_name']]],
            ],
        ])]);
        $this->publish($page);
        $live = fn () => (string) DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.page_id', $page)->value('p.html');
        $this->assertStringContainsString('Old name', $live());

        app(SiteDataService::class)->update($this->f['ctx'], ['company_name' => 'New name']);
        $this->assertStringContainsString('New name', $live());
    }

    public function test_an_unchanged_save_records_nothing(): void
    {
        $this->type('service', 'Services', [self::text('summary', 'Summary')]);
        $id = $this->entry('service', 'Web', ['summary' => 'Same']);
        $revisions = DB::table('page_revisions')->where('page_id', $id)->count();
        app(EntryFields::class)->update($this->f['ctx'], $id, ['summary' => 'Same']);

        $this->assertSame($revisions, DB::table('page_revisions')->where('page_id', $id)->count());
    }
}
