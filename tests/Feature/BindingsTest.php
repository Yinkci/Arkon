<?php

namespace Tests\Feature;

use App\Arkon\Components\DocumentValidator;
use App\Arkon\Content\BindingContext;
use App\Arkon\Content\Bindings;
use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\SiteDataService;
use App\Arkon\Design\ComponentService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Schema\DocumentShape;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Builder properties that take their value from content instead of holding it. One template
 * design, many entries: the block says where to look, the entry says what to show.
 */
final class BindingsTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    private function serviceType(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [],
            'fields' => [
                ['key' => 'headline', 'label' => 'Headline', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'settings' => (object) []],
                ['key' => 'picture', 'label' => 'Picture', 'type' => 'media', 'settings' => (object) []],
                ['key' => 'office', 'label' => 'Office', 'type' => 'group', 'settings' => (object) [], 'fields' => [
                    ['key' => 'city', 'label' => 'City', 'type' => 'text', 'settings' => (object) []],
                ]],
                ['key' => 'faqs', 'label' => 'FAQs', 'type' => 'repeater', 'settings' => (object) [], 'fields' => [
                    ['key' => 'question', 'label' => 'Question', 'type' => 'text', 'settings' => (object) []],
                ]],
            ],
        ]);
    }

    private function siteGroup(): void
    {
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'company', 'name' => 'Company', 'description' => '',
            'rules' => ['groups' => [['rules' => [['attribute' => 'scope', 'operator' => 'is', 'value' => 'site']]]]],
            'fields' => [
                ['key' => 'company_name', 'label' => 'Company name', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'secret_note', 'label' => 'Internal note', 'type' => 'text', 'settings' => ['private' => true]],
            ],
        ]);
    }

    /** A hero whose heading is bound to a field, with its static value kept as the fallback. */
    private function heroDocument(array $bindings, string $staticHeading = 'Static heading'): array
    {
        return [
            'schemaVersion' => 1,
            'root' => 'root0001',
            'nodes' => [
                'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new \stdClass, 'children' => ['hero0001']],
                'hero0001' => [
                    'id' => 'hero0001', 'type' => 'hero', 'version' => 5,
                    'props' => ['heading' => $staticHeading, 'headingLevel' => 'h1', 'text' => '', 'image' => null],
                    'children' => [],
                    // Left out entirely when there is nothing bound: an empty list is not an
                    // object, and the document schema is right to refuse one.
                    ...($bindings === [] ? [] : ['bindings' => $bindings]),
                ],
            ],
            'seo' => new \stdClass,
        ];
    }

    private function entry(string $title, array $values): string
    {
        $id = app(ContentItems::class)->create($this->f['ctx'], 'service', ['title' => $title])['id'];
        app(EntryFields::class)->update($this->f['ctx'], $id, $values);

        return $id;
    }

    private function canvas(string $pageId, array $document): string
    {
        DB::table('page_drafts')->where('page_id', $pageId)->update(['document' => Json::encode($document)]);

        return app(PageService::class)->renderCanvas($this->f['ctx'], $pageId, $document, app(MediaSigner::class))['body'];
    }

    public function test_a_bound_property_renders_the_entrys_value_not_the_static_one(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        $html = $this->canvas($entry, $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'headline']]));

        $this->assertStringContainsString('Websites that work', $html);
        $this->assertStringNotContainsString('Static heading', $html);
    }

    public function test_one_design_renders_every_entry_with_its_own_values(): void
    {
        $this->serviceType();
        $document = $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'headline']]);
        $entries = [
            'Web development' => 'Websites that work',
            'SEO consulting' => 'Found by the right people',
            'Maintenance' => 'Looked after every month',
        ];
        foreach ($entries as $title => $headline) {
            $id = $this->entry($title, ['headline' => $headline]);
            $this->assertStringContainsString($headline, $this->canvas($id, $document), "{$title} did not render its own value");
        }
    }

    public function test_an_empty_or_missing_value_leaves_the_static_value_standing(): void
    {
        $this->serviceType();
        // Nothing stored for the field at all.
        $empty = $this->entry('Nothing here', []);
        $html = $this->canvas($empty, $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'headline']], 'Learn more about our service'));
        $this->assertStringContainsString('Learn more about our service', $html, 'the static value is the fallback');
        $this->assertStringNotContainsString('null', strtolower(strip_tags($html)));
    }

    public function test_a_binding_can_reach_inside_a_group(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['office' => ['city' => 'Tarlac City']]);
        $html = $this->canvas($entry, $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'office', 'path' => ['city']]]));

        $this->assertStringContainsString('Tarlac City', $html);
    }

    public function test_a_repeater_is_a_collection_and_cannot_stand_for_one_value(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['faqs' => [['question' => 'What does it cost?']]]);
        $html = $this->canvas($entry, $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'faqs']], 'Still the static one'));

        // Deliberate: a list is not a heading, so the static value stands rather than something
        // arbitrary being picked out of the rows.
        $this->assertStringContainsString('Still the static one', $html);
        $this->assertStringNotContainsString('What does it cost?', $html);
    }

    public function test_a_binding_to_site_data_works_on_any_page(): void
    {
        $this->siteGroup();
        app(SiteDataService::class)->update($this->f['ctx'], ['company_name' => 'Arkon Studio']);
        $html = $this->canvas($this->f['pageId'], $this->heroDocument(['heading' => ['source' => 'site', 'field' => 'company_name']]));

        $this->assertStringContainsString('Arkon Studio', $html);
    }

    public function test_media_bound_to_an_image_stays_a_media_reference(): void
    {
        $this->serviceType();
        $asset = Uuid::v7();
        DB::table('media_assets')->insert([
            'id' => $asset, 'site_id' => $this->f['siteId'], 'storage_key' => $asset.'.png', 'mime' => 'image/png',
            'bytes' => 70, 'width' => 1, 'height' => 1, 'original_name' => 'dot.png',
        ]);
        $entry = $this->entry('Web development', ['picture' => ['id' => $asset]]);

        // A media field holds {id}; the hero keeps its picture in image.assetId, which it says so
        // itself through mediaRefs. The binding renames the reference to what this component
        // calls it rather than either side knowing about the other.
        $resolved = Bindings::value(['source' => 'entry', 'field' => 'picture'], Bindings::context(['picture' => ['id' => $asset]], []));
        $this->assertSame(['id' => $asset], $resolved, 'a media field resolves to its reference, not a URL');

        $html = $this->canvas($entry, $this->heroDocument(['image' => ['source' => 'entry', 'field' => 'picture']]));
        $this->assertStringContainsString($asset, $html, 'the bound picture reached the page');
        // The alt text the block already had is kept, because it belongs to this use of the image.
        $this->assertStringNotContainsString('"id":', $html);
    }

    public function test_changing_a_fields_label_does_not_break_a_binding(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        $document = $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'headline']]);

        $definition = app(ContentTypeService::class)->show($this->f['ctx'], 'service');
        $fields = $definition['fields'];
        $fields[0]['label'] = 'Main headline';
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$definition, 'fields' => $fields]);

        // The binding names the key, which did not change.
        $this->assertStringContainsString('Websites that work', $this->canvas($entry, $document));
    }

    public function test_a_binding_whose_field_is_gone_is_reported_and_renders_the_fallback(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        $document = $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'headline']], 'The static one');

        $definition = app(ContentTypeService::class)->show($this->f['ctx'], 'service');
        $fewer = array_values(array_filter($definition['fields'], fn ($field) => $field['key'] !== 'headline'));
        app(ContentTypeService::class)->update($this->f['ctx'], 'service', [...$definition, 'fields' => $fewer, 'confirmRemovals' => true]);

        // Nothing crashes, and the page falls back to what it held.
        $this->assertStringContainsString('The static one', $this->canvas($entry, $document));

        // And it is reported, so a person can fix it rather than a visitor finding it.
        $context = app(BindingContext::class);
        $broken = Bindings::broken($document, $context->entryFields($this->f['siteId'], $entry), $context->siteFields($this->f['siteId']));
        $this->assertSame([['nodeId' => 'hero0001', 'prop' => 'heading', 'source' => 'entry', 'field' => 'headline']], $broken);
    }

    public function test_what_a_document_depends_on_can_be_listed(): void
    {
        $document = $this->heroDocument([
            'heading' => ['source' => 'entry', 'field' => 'headline'],
            'text' => ['source' => 'site', 'field' => 'company_name'],
        ]);
        $used = Bindings::inDocument($document);

        $this->assertCount(2, $used);
        $this->assertSame(['heading', 'text'], array_column($used, 'prop'));
        $this->assertSame(['entry', 'site'], array_column($used, 'source'));
    }

    public function test_a_published_page_shows_the_published_values_and_no_private_site_data(): void
    {
        $this->serviceType();
        $this->siteGroup();
        app(SiteDataService::class)->update($this->f['ctx'], ['company_name' => 'Arkon Studio', 'secret_note' => 'Do not publish me']);
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        $document = $this->heroDocument([
            'heading' => ['source' => 'entry', 'field' => 'headline'],
            'text' => ['source' => 'site', 'field' => 'secret_note'],
        ], 'Fallback text');
        DB::table('page_drafts')->where('page_id', $entry)->update(['document' => Json::encode($document)]);

        $version = (int) DB::table('page_drafts')->where('page_id', $entry)->value('version');
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $entry, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
        $html = (string) DB::table('publications')->where('page_id', $entry)->orderByDesc('created_at')->value('html');

        $this->assertStringContainsString('Websites that work', $html);
        $this->assertStringNotContainsString('Do not publish me', $html, 'a private site value never reaches a public page');
    }

    public function test_a_bound_property_satisfies_what_publishing_requires(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        // The block itself has no heading; the entry supplies one. Publishing is about what goes
        // live, so this is not a page missing its heading.
        $document = $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'headline']], '');
        DB::table('page_drafts')->where('page_id', $entry)->update(['document' => Json::encode($document)]);

        $context = app(BindingContext::class)->published($this->f['siteId'], $entry);
        $validator = app(DocumentValidator::class);
        $this->assertSame([], $validator->publishIssues(Json::entries(Json::decode(Json::encode($document))), $context));
        // Without the content it would be reported, which is what makes the check meaningful.
        $this->assertNotSame([], $validator->publishIssues(Json::entries(Json::decode(Json::encode($document)))));
    }

    public function test_a_broken_binding_is_reported_before_publishing(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        $document = $this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'gone_away']]);

        $context = app(BindingContext::class);
        $issues = app(DocumentValidator::class)->bindingIssues(
            Json::entries(Json::decode(Json::encode($document))),
            $context->entryFields($this->f['siteId'], $entry),
            $context->siteFields($this->f['siteId']),
        );

        $this->assertCount(1, $issues);
        $this->assertSame('hero0001', $issues[0]['nodeId']);
        $this->assertStringContainsString('gone_away', $issues[0]['message']);
        $this->assertStringContainsString('no longer exists', $issues[0]['message']);
    }

    public function test_the_member_preview_resolves_bindings_like_the_canvas_does(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        DB::table('page_drafts')->where('page_id', $entry)->update([
            'document' => Json::encode($this->heroDocument(['heading' => ['source' => 'entry', 'field' => 'headline']])),
        ]);

        // The preview is the draft as it would be published, so it shows the entry's value and
        // not the fallback the block was left holding.
        $html = app(PageService::class)->renderPreview($this->f['ctx'], $entry);
        $this->assertStringContainsString('Websites that work', $html);
        $this->assertStringNotContainsString('Static heading', $html);
    }

    public function test_a_component_is_previewed_as_a_sample_entry(): void
    {
        $this->serviceType();
        $entry = $this->entry('Web development', ['headline' => 'Websites that work']);
        $components = app(ComponentService::class);
        $made = $components->create($this->f['ctx'], ['name' => 'Service hero', 'nodes' => [
            ['id' => 'hero0001', 'type' => 'hero', 'version' => 5, 'props' => ['heading' => 'A service', 'headingLevel' => 'h2', 'text' => '', 'image' => null],
                'children' => [], 'bindings' => ['heading' => ['source' => 'entry', 'field' => 'headline']]],
        ]]);
        $document = $components->editorInit($this->f['ctx'], $made['id'], app(MediaSigner::class))['draft']['document'];

        // With nothing to stand in for, the component shows what was typed.
        $alone = $components->renderCanvas($this->f['ctx'], $made['id'], $document, app(MediaSigner::class));
        $this->assertStringContainsString('A service', $alone['body']);

        // Previewed as a real entry, it shows that entry's words.
        $asEntry = $components->renderCanvas($this->f['ctx'], $made['id'], $document, app(MediaSigner::class), $entry);
        $this->assertStringContainsString('Websites that work', $asEntry['body']);
        $this->assertStringNotContainsString('A service', $asEntry['body']);

        // An id that is not one is simply nothing to stand in for, not an error.
        $nonsense = $components->renderCanvas($this->f['ctx'], $made['id'], $document, app(MediaSigner::class), 'not-an-id');
        $this->assertStringContainsString('A service', $nonsense['body']);
    }

    public function test_the_document_schema_refuses_a_binding_that_makes_no_sense(): void
    {
        $refused = [
            'an unknown source' => ['heading' => ['source' => 'elsewhere', 'field' => 'headline']],
            'a key that is not a field key' => ['heading' => ['source' => 'entry', 'field' => 'Not A Key']],
            'a path that goes too deep' => ['heading' => ['source' => 'entry', 'field' => 'a', 'path' => ['b', 'c', 'd']]],
            'something that is not a binding' => ['heading' => 'entry.headline'],
        ];
        foreach ($refused as $name => $bindings) {
            $issues = DocumentShape::bindingIssues(Json::decode(Json::encode($bindings)), 'nodes.hero0001.bindings');
            $this->assertNotSame([], $issues, "Accepted {$name}");
        }
        // And an honest one passes.
        $this->assertSame([], DocumentShape::bindingIssues(
            Json::decode(Json::encode(['heading' => ['source' => 'entry', 'field' => 'headline', 'path' => ['city']]])),
            'nodes.hero0001.bindings'
        ));
    }
}
