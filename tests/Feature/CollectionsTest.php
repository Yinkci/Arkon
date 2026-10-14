<?php

namespace Tests\Feature;

use App\Arkon\Ai\ProposalSchema;
use App\Arkon\Components\Factories;
use App\Arkon\Content\Collections;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Content lists: one item template, many entries.
 *
 * What these tests hold is the promise of the block — the page stores a query and a template,
 * never copies of the content, so publishing a new entry changes the page without anybody
 * editing it — and the limits that make that safe: published entries only, a query checked
 * against the schema rather than trusted, a fixed number of queries however many entries are
 * shown, and a problem reported to the author rather than a blank page shown to a visitor.
 */
final class CollectionsTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->serviceType();
    }

    private function serviceType(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 1, 'details' => true, 'taxonomies' => ['category'],
            'fields' => [
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'settings' => (object) []],
                ['key' => 'price_from', 'label' => 'Price from', 'type' => 'decimal', 'settings' => ['min' => 0]],
                ['key' => 'featured', 'label' => 'Featured', 'type' => 'boolean', 'settings' => (object) []],
                ['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'settings' => (object) [], 'choices' => [
                    ['label' => 'Starter', 'value' => 'starter'],
                    ['label' => 'Standard', 'value' => 'standard'],
                ]],
                ['key' => 'notes', 'label' => 'Internal notes', 'type' => 'text', 'settings' => ['private' => true]],
            ],
        ]);
    }

    /** A service with values, published unless asked otherwise. */
    private function service(string $title, array $values = [], bool $publish = true): string
    {
        $id = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => $title])['id'];
        if ($values !== []) {
            app(EntryFields::class)->update($this->f['ctx'], $id, $values);
        }
        if ($publish) {
            $this->publish($id);
        }

        return $id;
    }

    private function publish(string $pageId): void
    {
        $version = (int) DB::table('page_drafts')->where('page_id', $pageId)->value('version');
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
    }

    /**
     * A page holding one content list whose item template is a heading bound to each entry's
     * title and a line bound to its summary.
     */
    private function listDocument(array $query, array $itemProps = []): array
    {
        $heading = ['id' => 'item0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'A title', ...$itemProps], 'bindings' => ['text' => ['source' => 'entry', 'field' => 'title']]];
        $summary = ['id' => 'item0002', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'A summary'], 'bindings' => ['text' => ['source' => 'entry', 'field' => 'summary']]];

        return [
            'schemaVersion' => 1,
            'root' => 'root0001',
            'nodes' => [
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new \stdClass, 'children' => ['coll0001']],
                'coll0001' => ['id' => 'coll0001', 'type' => 'collection', 'version' => 1, 'props' => ['query' => $query], 'children' => ['item0001', 'item0002']],
                'item0001' => $heading,
                'item0002' => $summary,
            ],
            'seo' => new \stdClass,
        ];
    }

    /** How many entries a rendering shows (the stylesheet names the class too, so match markup). */
    private static function items(string $html): int
    {
        return substr_count($html, 'class="ak-collection__item');
    }

    private function canvas(string $pageId, array $document): array
    {
        DB::table('page_drafts')->where('page_id', $pageId)->update(['document' => Json::encode($document)]);

        return app(PageService::class)->renderCanvas($this->f['ctx'], $pageId, $document, app(MediaSigner::class));
    }

    private function preview(string $pageId, array $document): string
    {
        DB::table('page_drafts')->where('page_id', $pageId)->update(['document' => Json::encode($document)]);

        return app(PageService::class)->renderPreview($this->f['ctx'], $pageId);
    }

    public function test_one_item_template_renders_every_entry(): void
    {
        $this->service('Web development', ['summary' => 'Sites that work']);
        $this->service('SEO consulting', ['summary' => 'Found by the right people']);
        $this->service('Maintenance', ['summary' => 'Looked after monthly']);

        $html = $this->preview($this->f['pageId'], $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]));

        foreach (['Web development', 'SEO consulting', 'Maintenance', 'Sites that work', 'Found by the right people'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        // The template's own text is the fallback, and never shown when every entry has a value.
        $this->assertStringNotContainsString('A title', $html);
        $this->assertSame(3, self::items($html), 'one wrapper per entry');
        // The document holds one template, not three copies of it.
        $document = Json::toArray(Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document')));
        $this->assertCount(4, $document['nodes'], 'the page, the list and its two template blocks');
    }

    public function test_publishing_another_entry_changes_the_page_without_editing_it(): void
    {
        $this->service('Web development');
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);
        $this->assertStringNotContainsString('Hosting', $this->preview($this->f['pageId'], $document));

        // Nothing about the page changes: a new service is published and the list follows.
        $this->service('Hosting');
        $this->assertStringContainsString('Hosting', app(PageService::class)->renderPreview($this->f['ctx'], $this->f['pageId']));
    }

    public function test_only_published_entries_are_shown_anywhere(): void
    {
        $this->service('Published service');
        $this->service('Draft service', publish: false);
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);

        $preview = $this->preview($this->f['pageId'], $document);
        $this->assertStringContainsString('Published service', $preview);
        $this->assertStringNotContainsString('Draft service', $preview, 'a draft entry is not published content');

        // The canvas shows the same entries, so the editor is not a different page.
        $canvas = $this->canvas($this->f['pageId'], $document);
        $this->assertStringContainsString('Published service', $canvas['body']);
        $this->assertStringNotContainsString('Draft service', $canvas['body']);
        $this->assertFalse($canvas['collections']->coll0001['sample']);
        $this->assertSame(1, $canvas['collections']->coll0001['shown']);
    }

    public function test_a_trashed_entry_leaves_the_list(): void
    {
        $one = $this->service('Stays');
        $two = $this->service('Goes');
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);
        $this->assertStringContainsString('Goes', $this->preview($this->f['pageId'], $document));

        $version = (int) DB::table('page_drafts')->where('page_id', $two)->value('version');
        app(PageManagement::class)->delete($this->f['ctx'], ['pageId' => $two, 'expectedVersion' => $version]);
        $html = app(PageService::class)->renderPreview($this->f['ctx'], $this->f['pageId']);
        $this->assertStringNotContainsString('Goes', $html);
        $this->assertStringContainsString('Stays', $html);
        unset($one);
    }

    public function test_the_limit_is_respected_and_the_total_reported(): void
    {
        foreach (range(1, 5) as $n) {
            $this->service("Service {$n}");
        }
        $canvas = $this->canvas($this->f['pageId'], $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 2]));

        $this->assertSame(2, self::items($canvas['body']));
        $this->assertSame(['total' => 5, 'shown' => 2, 'sample' => false, 'issues' => []], $canvas['collections']->coll0001);
    }

    public function test_entries_are_filtered_and_sorted_by_their_own_fields(): void
    {
        $this->service('Cheap', ['price_from' => 100, 'featured' => false, 'tier' => 'starter']);
        $this->service('Middle', ['price_from' => 500, 'featured' => true, 'tier' => 'standard']);
        $this->service('Dear', ['price_from' => 900, 'featured' => true, 'tier' => 'standard']);

        $only = fn (array $filters, string $sort = 'title') => $this->preview($this->f['pageId'], $this->listDocument([
            'source' => 'service', 'sort' => $sort, 'limit' => 6, 'filters' => $filters,
        ]));

        $featured = $only([['field' => 'featured', 'operator' => 'is_true']]);
        $this->assertStringNotContainsString('Cheap', $featured);
        $this->assertStringContainsString('Middle', $featured);

        $dear = $only([['field' => 'price_from', 'operator' => 'gt', 'value' => '600']]);
        $this->assertStringContainsString('Dear', $dear);
        $this->assertStringNotContainsString('Middle', $dear);

        $between = $only([['field' => 'price_from', 'operator' => 'between', 'value' => '200', 'value2' => '800']]);
        $this->assertStringContainsString('Middle', $between);
        $this->assertStringNotContainsString('Cheap', $between);
        $this->assertStringNotContainsString('Dear', $between);

        $starter = $only([['field' => 'tier', 'operator' => 'is', 'value' => 'starter']]);
        $this->assertStringContainsString('Cheap', $starter);
        $this->assertStringNotContainsString('Middle', $starter);

        // Sorted by one of the type's own fields, in both directions.
        $byPrice = $only([], 'field:price_from');
        $this->assertLessThan(strpos($byPrice, 'Dear'), strpos($byPrice, 'Cheap'));
        $byPriceDown = $only([], '-field:price_from');
        $this->assertLessThan(strpos($byPriceDown, 'Cheap'), strpos($byPriceDown, 'Dear'));
    }

    public function test_entries_can_be_filtered_by_a_category(): void
    {
        $term = DB::table('terms')->insertGetId([
            'id' => Uuid::v7(), 'site_id' => $this->f['siteId'], 'taxonomy' => 'category', 'name' => 'Development', 'slug' => 'development',
        ], 'id');
        $tagged = $this->service('Tagged', publish: false);
        DB::table('page_terms')->insert(['site_id' => $this->f['siteId'], 'page_id' => $tagged, 'term_id' => $term]);
        $this->publish($tagged);
        $this->service('Untagged');

        $html = $this->preview($this->f['pageId'], $this->listDocument([
            'source' => 'service', 'sort' => 'title', 'limit' => 6,
            'filters' => [['field' => 'category', 'operator' => 'has_term', 'value' => $term]],
        ]));
        $this->assertStringContainsString('Tagged', $html);
        $this->assertStringNotContainsString('Untagged', $html);
    }

    public function test_a_list_of_what_an_entry_relates_to_keeps_the_chosen_order(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'bundle', 'name' => 'Bundles', 'singular' => 'Bundle', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/bundles', 'inMenu' => false, 'menuOrder' => 2, 'details' => false, 'taxonomies' => [],
            'fields' => [['key' => 'includes', 'label' => 'Includes', 'type' => 'relationship', 'settings' => ['targetType' => 'service', 'multiple' => true]]],
        ]);
        $first = $this->service('Alpha service');
        $second = $this->service('Beta service');
        $this->service('Unrelated service');
        $bundle = app(ContentItems::class)->create($this->f['ctx'], 'bundle', ['title' => 'Starter bundle'])['id'];
        app(EntryFields::class)->update($this->f['ctx'], $bundle, ['includes' => [['id' => $second], ['id' => $first]]]);

        $html = $this->preview($bundle, $this->listDocument(['source' => 'related:includes', 'sort' => 'chosen', 'limit' => 6]));
        $this->assertStringNotContainsString('Unrelated service', $html);
        $this->assertLessThan(strpos($html, 'Alpha service'), strpos($html, 'Beta service'), 'the order chosen in the field');
    }

    public function test_zero_results_show_the_message_the_author_wrote(): void
    {
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);
        $document['nodes']['coll0001']['props']['emptyText'] = 'No services yet.';

        $html = $this->preview($this->f['pageId'], $document);
        $this->assertStringContainsString('No services yet.', $html);
        $this->assertSame(0, self::items($html));
    }

    public function test_the_editor_shows_one_sample_entry_when_nothing_is_published_yet(): void
    {
        $this->service('Not published yet', ['summary' => 'A draft service'], publish: false);
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);

        // The canvas: one sample, so the item template can be designed.
        $canvas = $this->canvas($this->f['pageId'], $document);
        $this->assertStringContainsString('Not published yet', $canvas['body']);
        $this->assertTrue($canvas['collections']->coll0001['sample']);

        // A visitor gets the empty state instead: a sample is never published.
        $this->assertStringNotContainsString('Not published yet', app(PageService::class)->renderPreview($this->f['ctx'], $this->f['pageId']));
    }

    public function test_a_query_naming_something_that_is_gone_is_reported_and_shows_nothing(): void
    {
        $this->service('A service');

        $missingField = $this->canvas($this->f['pageId'], $this->listDocument([
            'source' => 'service', 'sort' => 'title', 'limit' => 6,
            'filters' => [['field' => 'colour', 'operator' => 'is', 'value' => 'red']],
        ]));
        $this->assertSame(['This content list filters by “colour”, which is not a field this content has any more.'], $missingField['collections']->coll0001['issues']);
        $this->assertStringNotContainsString('A service', $missingField['body']);

        $missingType = $this->canvas($this->f['pageId'], $this->listDocument(['source' => 'widget', 'sort' => 'title', 'limit' => 6]));
        $this->assertSame(['This content list shows a kind of content this site no longer has.'], $missingType['collections']->coll0001['issues']);

        $badSort = $this->canvas($this->f['pageId'], $this->listDocument(['source' => 'service', 'sort' => 'field:summary', 'limit' => 6]));
        $this->assertSame([], $badSort['collections']->coll0001['issues'], 'a paragraph can be ordered as text');

        $unsortable = $this->canvas($this->f['pageId'], $this->listDocument(['source' => 'service', 'sort' => 'field:nothing', 'limit' => 6]));
        $this->assertStringContainsString('not a field this content has any more', $unsortable['collections']->coll0001['issues'][0]);
    }

    public function test_a_broken_query_stops_the_page_from_being_published(): void
    {
        $this->service('A service');
        $document = $this->listDocument([
            'source' => 'service', 'sort' => 'title', 'limit' => 6,
            'filters' => [['field' => 'colour', 'operator' => 'is', 'value' => 'red']],
        ]);
        DB::table('page_drafts')->where('page_id', $this->f['pageId'])->update(['document' => Json::encode($document)]);
        $version = (int) DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('version');

        try {
            app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
            $this->fail('Published a page whose content list asks for something that is gone');
        } catch (ValidationException $e) {
            $this->assertSame('Fix these problems before publishing', $e->getMessage());
            $this->assertStringContainsString('not a field this content has any more', Json::encode($e->issues));
        }
        $this->assertNull(DB::table('live_pages')->where('page_id', $this->f['pageId'])->first(), 'nothing went live');
    }

    public function test_a_private_field_cannot_be_shown_or_filtered(): void
    {
        $this->service('A service', ['summary' => 'Public summary', 'notes' => 'Internal only']);
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);
        $document['nodes']['item0002']['bindings']['text'] = ['source' => 'entry', 'field' => 'notes'];

        $html = $this->preview($this->f['pageId'], $document);
        $this->assertStringContainsString('A service', $html);
        $this->assertStringNotContainsString('Internal only', $html, 'a private field is not published content');

        // Nor is it offered to the editor as something to filter, sort or bind.
        $service = array_values(array_filter(app(Collections::class)->sources($this->f['siteId'])['types'], fn ($t) => $t['key'] === 'service'))[0];
        $this->assertNotContains('notes', array_column($service['fields'], 'key'));
        $this->assertNotContains('notes', array_column($service['bindable'], 'field'));
    }

    public function test_many_entries_cost_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 12) as $n) {
            $this->service("Service {$n}", ['summary' => "Summary {$n}"]);
        }
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 12]);
        DB::table('page_drafts')->where('page_id', $this->f['pageId'])->update(['document' => Json::encode($document)]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $html = app(PageService::class)->renderPreview($this->f['ctx'], $this->f['pageId']);
        DB::listen(fn () => null);

        $this->assertSame(12, self::items($html));
        $this->assertLessThan(40, $queries, "rendering twelve entries took {$queries} queries");
    }

    public function test_the_item_template_binds_to_the_entry_being_rendered_not_the_page(): void
    {
        // The page is itself an entry with its own values: inside the list, "this entry" is the
        // item, not the page.
        $pageEntry = $this->service('The page itself', ['summary' => 'The page summary']);
        $this->service('A listed service', ['summary' => 'The listed summary']);

        $html = $this->preview($pageEntry, $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]));
        $this->assertStringContainsString('The listed summary', $html);
        $this->assertStringContainsString('The page summary', $html, 'the page is in its own list');
    }

    public function test_a_list_cannot_hold_another_list(): void
    {
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);
        $document['nodes']['coll0001']['children'] = ['item0001', 'coll0002'];
        $document['nodes']['coll0002'] = ['id' => 'coll0002', 'type' => 'collection', 'version' => 1, 'props' => ['query' => ['source' => 'service']], 'children' => ['item0002']];

        $this->expectExceptionMessage('The page could not be rendered');
        $this->canvas($this->f['pageId'], $document);
    }

    public function test_removing_a_field_a_list_uses_warns_about_the_page(): void
    {
        $this->service('A service', ['price_from' => 100]);
        $document = $this->listDocument([
            'source' => 'service', 'sort' => 'field:price_from', 'limit' => 6,
            'filters' => [['field' => 'featured', 'operator' => 'is_true']],
        ]);
        DB::table('page_drafts')->where('page_id', $this->f['pageId'])->update(['document' => Json::encode($document)]);

        $definition = app(ContentTypeService::class)->show($this->f['ctx'], 'service');
        $without = array_values(array_filter($definition['fields'], fn ($f) => $f['key'] !== 'featured'));
        try {
            app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$definition, 'fields' => $without, 'expectedVersion' => $definition['schemaVersion']]);
            $this->fail('Removed a field a content list uses without a word about it');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('A content list on Home uses it.', $e->getMessage());
        }

        // Confirmed, it goes ahead, and the list says what is wrong rather than crashing.
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$definition, 'fields' => $without, 'expectedVersion' => $definition['schemaVersion'], 'confirmRemovals' => true]);
        // Read back in raw form, exactly as the editor sends it (an empty object is not a list).
        $stored = Json::decode(DB::table('page_drafts')->where('page_id', $this->f['pageId'])->value('document'));
        $canvas = app(PageService::class)->renderCanvas($this->f['ctx'], $this->f['pageId'], $stored, app(MediaSigner::class));
        $this->assertStringContainsString('not a field this content has any more', $canvas['collections']->coll0001['issues'][0]);
    }

    public function test_a_viewer_cannot_make_a_list_show_what_they_may_not_see(): void
    {
        // The query is read against one site's schema only: a type belonging to another site is
        // unknown here, however it is spelled.
        $other = $this->siteFixture(siteName: 'Another site');
        app(ContentTypeService::class)->create($other['ctx'], [
            'key' => 'secret', 'name' => 'Secrets', 'singular' => 'Secret', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/secrets', 'inMenu' => false, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [['key' => 'detail', 'label' => 'Detail', 'type' => 'text', 'settings' => (object) []]],
        ]);
        $theirs = app(ContentItems::class)->create($other['ctx'], 'secret', ['title' => 'Their secret'])['id'];
        $version = (int) DB::table('page_drafts')->where('page_id', $theirs)->value('version');
        app(PageService::class)->publish($other['ctx'], ['pageId' => $theirs, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);

        $canvas = $this->canvas($this->f['pageId'], $this->listDocument(['source' => 'secret', 'sort' => 'title', 'limit' => 6]));
        $this->assertStringNotContainsString('Their secret', $canvas['body']);
        $this->assertSame(['This content list shows a kind of content this site no longer has.'], $canvas['collections']->coll0001['issues']);
    }

    public function test_ai_can_describe_a_content_list_in_its_own_schema(): void
    {
        $schema = app(ProposalSchema::class)->schema([]);
        $this->assertArrayHasKey('collectionQuery', $schema['$defs'], 'the query is described once and shared');
        $query = $schema['$defs']['collectionQuery'];
        $this->assertSame('integer', $query['properties']['limit']['type']);
        $this->assertSame(48, $query['properties']['limit']['maximum']);
        $this->assertSame('array', $query['properties']['filters']['type']);
        $this->assertSame(5, $query['properties']['filters']['maxItems']);
        $this->assertArrayHasKey('block_collection', $schema['$defs'], 'AI can add one');
    }

    public function test_a_list_inside_a_reusable_component_renders_the_sites_entries(): void
    {
        $this->service('From the component');
        // A content list is an ordinary block, so it works wherever blocks do: here, a page
        // whose list sits inside a section.
        $document = $this->listDocument(['source' => 'service', 'sort' => 'title', 'limit' => 6]);
        $section = Factories::node('section', []);
        $document['nodes'][$section['id']] = [...$section, 'children' => ['coll0001']];
        $document['nodes']['root0001']['children'] = [$section['id']];

        $this->assertStringContainsString('From the component', $this->preview($this->f['pageId'], $document));
    }
}
