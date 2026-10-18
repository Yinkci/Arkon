<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeRegistry;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldSchemaResolver;
use App\Arkon\Content\Flexible;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Content sections: an ordered sequence of blocks, each of a layout the field defines.
 *
 * What these tests hold is the distinction the whole field rests on. The content is structured —
 * named layouts, ordinary fields, stable ids, nothing about spacing or colour — and how it looks
 * belongs to a Builder template that draws each layout. Blocks keep their identity through
 * reordering and duplication; a layout removed from the definition does not take an author's
 * words with it; and a change is one operation on one block rather than a new array.
 */
final class FlexibleContentTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->caseStudyType();
    }

    /** The plan's own example: a case study whose story is built from four kinds of section. */
    private function caseStudyType(array $layouts = []): void
    {
        $definition = [
            'key' => 'case_study', 'name' => 'Case studies', 'singular' => 'Case study', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/work', 'inMenu' => true, 'menuOrder' => 1, 'details' => true, 'taxonomies' => [],
            'fields' => [
                ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'story', 'label' => 'Story sections', 'type' => 'flexible', 'settings' => ['addLabel' => 'Add a section'], 'layouts' => $layouts === [] ? $this->layouts() : $layouts],
            ],
        ];
        if (app(ContentTypeRegistry::class)->forSite($this->f['siteId'])->exists('case_study')) {
            $current = app(ContentTypeService::class)->show($this->f['ctx'], 'case_study');
            app(ContentTypeService::class)->update($this->f['ctx'], 'case_study', [...$definition, 'expectedVersion' => $current['schemaVersion'], 'confirmRemovals' => true]);

            return;
        }
        app(ContentTypeService::class)->create($this->f['ctx'], $definition);
    }

    private function layouts(): array
    {
        return [
            ['key' => 'text_section', 'label' => 'Text section', 'fields' => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'body', 'label' => 'Body', 'type' => 'textarea', 'settings' => (object) []],
            ]],
            ['key' => 'image_text', 'label' => 'Image and text', 'fields' => [
                ['key' => 'picture', 'label' => 'Picture', 'type' => 'media', 'settings' => (object) []],
                ['key' => 'words', 'label' => 'Words', 'type' => 'textarea', 'settings' => (object) []],
            ]],
            ['key' => 'quote', 'label' => 'Quote', 'max' => 2, 'fields' => [
                ['key' => 'quote_text', 'label' => 'Quote', 'type' => 'textarea', 'settings' => (object) []],
                ['key' => 'person', 'label' => 'Person', 'type' => 'text', 'settings' => (object) []],
            ]],
            ['key' => 'gallery_section', 'label' => 'Gallery', 'fields' => [
                ['key' => 'pictures', 'label' => 'Pictures', 'type' => 'gallery', 'settings' => (object) []],
            ]],
        ];
    }

    private function entry(string $title = 'Riverside rebuild'): string
    {
        return app(ContentItems::class)->create($this->f['ctx'], 'case_study', ['title' => $title])['id'];
    }

    private function values(string $pageId): array
    {
        return app(EntryFields::class)->drafts($this->f['siteId'], [$pageId])[$pageId] ?? [];
    }

    private function story(string $pageId): array
    {
        return $this->values($pageId)['story'] ?? [];
    }

    /** One operation on one block, the way the editor, the API and AI all send it. */
    private function section(string $pageId, array $operation): array
    {
        return app(EntryFields::class)->section($this->f['ctx'], $pageId, $operation)['story'] ?? [];
    }

    private function fieldDefinition(): array
    {
        $fields = app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => 'case_study']);

        return Flexible::fieldsIn($fields)['story'];
    }

    private function mediaAsset(): string
    {
        $id = Uuid::v7();
        DB::table('media_assets')->insert([
            'id' => $id, 'site_id' => $this->f['siteId'], 'storage_key' => $id.'.png', 'mime' => 'image/png',
            'bytes' => 70, 'width' => 1, 'height' => 1, 'original_name' => 'dot.png',
        ]);

        return $id;
    }

    public function test_an_author_builds_an_ordered_story_from_the_layouts_on_offer(): void
    {
        $entry = $this->entry();
        foreach ([
            ['text_section', ['heading' => 'How it began', 'body' => 'A long time ago']],
            ['image_text', ['words' => 'The site as it was']],
            ['quote', ['quote_text' => 'They understood the brief', 'person' => 'Jane Doe']],
            ['gallery_section', []],
        ] as [$layout, $set]) {
            $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => $layout, 'set' => $set]);
        }

        $story = $this->story($entry);
        $this->assertSame(['text_section', 'image_text', 'quote', 'gallery_section'], array_column($story, '_layout'));
        $this->assertSame('How it began', $story[0]['heading']);
        $this->assertSame('Jane Doe', $story[2]['person']);
        // Every block has an id of its own, and no two share one.
        $ids = array_column($story, '_id');
        $this->assertCount(4, array_filter($ids, fn ($id) => Uuid::isValid($id)));
        $this->assertCount(4, array_unique($ids));
    }

    public function test_reordering_moves_a_block_without_changing_what_it_is(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'First']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'A quote']]);
        $quote = $this->story($entry)[1]['_id'];

        $story = $this->section($entry, ['field' => 'story', 'op' => 'moveBlock', 'id' => $quote, 'index' => 0]);
        $this->assertSame(['quote', 'text_section'], array_column($story, '_layout'));
        $this->assertSame($quote, $story[0]['_id'], 'the moved block is the same block');
        $this->assertSame('A quote', $story[0]['quote_text']);

        // And back again, from storage rather than from the writer's return value.
        $this->section($entry, ['field' => 'story', 'op' => 'moveBlock', 'id' => $quote, 'index' => 1]);
        $this->assertSame(['text_section', 'quote'], array_column($this->story($entry), '_layout'));
    }

    public function test_one_field_of_one_block_changes_and_nothing_else_does(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'Kept', 'body' => 'Kept too']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'Also kept', 'person' => 'Jane']]);
        $before = $this->story($entry);

        $story = $this->section($entry, ['field' => 'story', 'op' => 'updateBlock', 'id' => $before[1]['_id'], 'set' => ['person' => 'Jane Doe']]);

        $this->assertSame($before[0], $story[0], 'the other block is untouched');
        $this->assertSame('Jane Doe', $story[1]['person']);
        $this->assertSame('Also kept', $story[1]['quote_text'], 'the rest of the block is untouched');
        $this->assertSame($before[1]['_id'], $story[1]['_id']);
    }

    public function test_a_block_is_duplicated_deeply_with_an_id_of_its_own(): void
    {
        $entry = $this->entry();
        $asset = $this->mediaAsset();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'image_text', 'set' => ['words' => 'Shared words', 'picture' => ['id' => $asset]]]);
        $original = $this->story($entry)[0]['_id'];

        $story = $this->section($entry, ['field' => 'story', 'op' => 'duplicateBlock', 'id' => $original]);

        $this->assertCount(2, $story);
        $this->assertNotSame($story[0]['_id'], $story[1]['_id']);
        $this->assertSame('Shared words', $story[1]['words']);
        $this->assertSame(['id' => $asset], $story[1]['picture'], 'nested values come with the copy');

        // Changing the copy leaves the original alone.
        $this->section($entry, ['field' => 'story', 'op' => 'updateBlock', 'id' => $story[1]['_id'], 'set' => ['words' => 'Its own words']]);
        $after = $this->story($entry);
        $this->assertSame('Shared words', $after[0]['words']);
        $this->assertSame('Its own words', $after[1]['words']);
    }

    public function test_a_block_is_removed_by_id_and_the_others_keep_their_order(): void
    {
        $entry = $this->entry();
        foreach (['text_section', 'quote', 'gallery_section'] as $layout) {
            $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => $layout]);
        }
        $middle = $this->story($entry)[1]['_id'];

        $story = $this->section($entry, ['field' => 'story', 'op' => 'removeBlock', 'id' => $middle]);
        $this->assertSame(['text_section', 'gallery_section'], array_column($story, '_layout'));

        // A block that is already gone is a stale request, not a silent success.
        $this->expectExceptionMessage('that section is no longer there');
        $this->section($entry, ['field' => 'story', 'op' => 'removeBlock', 'id' => $middle]);
    }

    public function test_a_layout_that_does_not_exist_is_refused(): void
    {
        $entry = $this->entry();

        try {
            $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'invented']);
            $this->fail('Added a section of a kind the field does not offer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('has no “invented” section', $e->getMessage());
        }
        // Nor through a straight save, which is the same engine.
        try {
            app(EntryFields::class)->update($this->f['ctx'], $entry, ['story' => [['_layout' => 'invented', 'heading' => 'Hello']]]);
            $this->fail('Saved a section of a kind the field does not offer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not a kind of section this content offers', $e->getMessage());
        }
    }

    public function test_a_layouts_own_limit_is_enforced(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote']);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote']);

        $this->expectExceptionMessage('at most 2 “Quote” sections');
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote']);
    }

    public function test_the_fields_inside_a_layout_are_the_ordinary_field_engine(): void
    {
        $entry = $this->entry();
        $asset = $this->mediaAsset();

        // Media is a reference to the library, and a gallery a list of them — the same storage
        // as anywhere else, because it is the same field engine.
        $story = $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'gallery_section', 'set' => ['pictures' => [['id' => $asset]]]]);
        $this->assertSame([['id' => $asset]], $story[0]['pictures']);

        // And its validation: a browser URL is not a media reference, inside a section either.
        $this->expectExceptionMessage('media library');
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['story' => [
            ['_id' => $story[0]['_id'], '_layout' => 'gallery_section', 'pictures' => [['url' => 'https://example.test/a.png']]],
        ]]);
    }

    public function test_a_relationship_inside_a_section_is_checked_like_any_other(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'person', 'name' => 'People', 'singular' => 'Person', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/people', 'inMenu' => false, 'menuOrder' => 2, 'details' => false, 'taxonomies' => [],
            'fields' => [['key' => 'full_name', 'label' => 'Name', 'type' => 'text', 'settings' => (object) []]],
        ]);
        $this->caseStudyType([
            ['key' => 'quote', 'label' => 'Quote', 'fields' => [
                ['key' => 'quote_text', 'label' => 'Quote', 'type' => 'textarea', 'settings' => (object) []],
                ['key' => 'said_by', 'label' => 'Said by', 'type' => 'relationship', 'settings' => ['targetType' => 'person']],
            ]],
        ]);
        $jane = app(ContentItems::class)->create($this->f['ctx'], 'person', ['title' => 'Jane Doe'])['id'];
        $entry = $this->entry();

        $story = $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'Quite so', 'said_by' => ['id' => $jane]]]);
        $this->assertSame(['id' => $jane], $story[0]['said_by']);

        // A reference to something of the wrong kind is refused inside a section too.
        $this->expectExceptionMessage('Said by takes Person');
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['story' => [
            ['_id' => $story[0]['_id'], '_layout' => 'quote', 'quote_text' => 'Quite so', 'said_by' => ['id' => $entry]],
        ]]);
    }

    public function test_removing_a_layout_keeps_the_sections_and_says_so(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'Worth keeping', 'person' => 'Jane']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'Still here']]);

        // The type is changed to offer only text sections. Removing a layout is confirmed first.
        $onlyText = [['key' => 'text_section', 'label' => 'Text section', 'fields' => [
            ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'settings' => (object) []],
            ['key' => 'body', 'label' => 'Body', 'type' => 'textarea', 'settings' => (object) []],
        ]]];
        $definition = app(ContentTypeService::class)->show($this->f['ctx'], 'case_study');
        $fields = array_map(fn ($field) => $field['key'] === 'story' ? [...$field, 'layouts' => $onlyText] : $field, $definition['fields']);
        try {
            app(ContentTypeService::class)->update($this->f['ctx'], 'case_study', [...$definition, 'fields' => $fields, 'expectedVersion' => $definition['schemaVersion']]);
            $this->fail('Removed a layout without saying how many entries use it');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('“Quote” (used by 1 entry)', $e->getMessage());
            $this->assertStringContainsString('kept but stop being shown', $e->getMessage());
            $this->assertContains('layout_removed', array_column($e->issues, 'code'), 'the editor offers the confirmation by this code');
        }
        app(ContentTypeService::class)->update($this->f['ctx'], 'case_study', [...$definition, 'fields' => $fields, 'expectedVersion' => $definition['schemaVersion'], 'confirmRemovals' => true]);

        // The author's words are still there, and the editor is told about them.
        $story = $this->story($entry);
        $this->assertSame(['quote', 'text_section'], array_column($story, '_layout'));
        $this->assertSame('Worth keeping', $story[0]['quote_text']);
        $deprecated = Flexible::deprecatedIn(
            app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => 'case_study']),
            $this->values($entry),
        );
        $this->assertSame([['fieldKey' => 'story', 'fieldLabel' => 'Story sections', 'layout' => 'quote', 'count' => 1]], $deprecated);
        $this->assertStringContainsString('of a kind that was removed', Flexible::describeDeprecated($deprecated)[0]);

        // Saving the entry again keeps them: nothing silently drops an author's content.
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['client' => 'Riverside']);
        $this->assertCount(2, $this->story($entry));
    }

    public function test_a_section_of_a_removed_kind_is_not_in_the_api_or_rendered(): void
    {
        $this->addDomain($this->f['siteId'], 'sections.test');
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'Hidden now']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'Shown']]);
        $this->caseStudyType([['key' => 'text_section', 'label' => 'Text section', 'fields' => [
            ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'settings' => (object) []],
        ]]]);

        $fields = app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => 'case_study']);
        $api = Flexible::forApi($fields, $this->values($entry));
        $this->assertSame(['text_section'], array_column($api['story'], 'type'));
        $this->assertStringNotContainsString('Hidden now', Json::encode($api));
    }

    public function test_the_api_serializes_sections_as_blocks_with_their_layout_and_id(): void
    {
        $this->addDomain($this->f['siteId'], 'sections.test');
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'How it began', 'body' => 'A long time ago']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'Quite so', 'person' => 'Jane']]);
        $story = $this->story($entry);

        $values = app(EntryFields::class)->publicValues($this->f['siteId'], [$entry], asVisitor: true)[$entry];
        $this->assertSame(['id', 'type', 'fields'], array_keys($values['story'][0]));
        $this->assertSame($story[0]['_id'], $values['story'][0]['id']);
        $this->assertSame('text_section', $values['story'][0]['type']);
        $this->assertEquals(['heading' => 'How it began', 'body' => 'A long time ago'], (array) $values['story'][0]['fields']);
        $this->assertSame('quote', $values['story'][1]['type']);
        // No internal bookkeeping travels with it.
        $this->assertStringNotContainsString('_layout', Json::encode($values));
        $this->assertStringNotContainsString('_id', Json::encode($values));
    }

    public function test_a_builder_template_draws_each_section_with_that_sections_values(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'How it began', 'body' => 'A long time ago']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'They understood the brief', 'person' => 'Jane Doe']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'What changed']]);

        $html = $this->preview($entry, $this->sectionsDocument());

        // Each block drawn by its own layout's template, in the order the author put them.
        $this->assertSame(
            ['How it began', 'They understood the brief', 'What changed'],
            array_map(fn ($m) => $m, self::headings($html)),
        );
        $this->assertStringContainsString('Jane Doe', $html);
        // The template's own placeholder text never reaches a reader when a section fills it.
        $this->assertStringNotContainsString('A heading', $html);
        $this->assertSame(3, self::drawn($html));
    }

    public function test_a_section_whose_template_is_missing_renders_nothing_and_does_not_crash(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'Drawn']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'gallery_section']);

        // The document has a template for text sections only.
        $document = $this->sectionsDocument(['sect0001' => 'text_section']);
        $html = $this->preview($entry, $document);
        $this->assertStringContainsString('Drawn', $html);
        $this->assertSame(1, self::drawn($html));
    }

    public function test_the_canvas_draws_every_template_so_a_layout_can_be_designed_before_it_is_used(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'Only a quote so far']]);
        $document = $this->sectionsDocument();

        $canvas = app(PageService::class)->renderCanvas($this->f['ctx'], $entry, $document, app(MediaSigner::class));
        $this->assertStringContainsString('Only a quote so far', $canvas['body']);
        // Both templates are on the canvas: the one with a section, and the one without.
        $this->assertSame(2, self::drawn($canvas['body']));
        $this->assertStringContainsString('A heading', $canvas['body'], 'the empty template shows its own text, so it can be designed');

        // A visitor sees only what the entry holds.
        $this->assertSame(1, self::drawn($this->preview($entry, $document)));
    }

    public function test_the_builder_is_told_which_layouts_exist_and_what_they_hold(): void
    {
        $fields = app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => 'case_study']);
        $forBuilder = Flexible::forBuilder($fields);

        $this->assertSame(['story'], array_column($forBuilder, 'key'));
        $this->assertSame(['text_section', 'image_text', 'quote', 'gallery_section'], array_column($forBuilder[0]['layouts'], 'key'));
        $quote = array_values(array_filter($forBuilder[0]['layouts'], fn ($l) => $l['key'] === 'quote'))[0];
        $this->assertSame(['Quote', 'Person'], array_column($quote['bindable'], 'label'));
        // A gallery is a list, so no single property can stand for it.
        $gallery = array_values(array_filter($forBuilder[0]['layouts'], fn ($l) => $l['key'] === 'gallery_section'))[0];
        $this->assertSame([], $gallery['bindable']);
    }

    public function test_the_whole_story_survives_a_revision_and_a_restore(): void
    {
        $entry = $this->entry();
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'text_section', 'set' => ['heading' => 'How it began']]);
        $this->section($entry, ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote', 'set' => ['quote_text' => 'Quite so']]);
        $before = $this->story($entry);

        DB::table('page_drafts')->where('page_id', $entry)->update(['document' => Json::encode($this->sectionsDocument())]);
        $version = (int) DB::table('page_drafts')->where('page_id', $entry)->value('version');
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $entry, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);

        $live = DB::table('publications')->where('page_id', $entry)->orderByDesc('created_at')->value('html');
        $this->assertStringContainsString('How it began', $live);
        $this->assertStringContainsString('Quite so', $live);
        // Publishing changed nothing about the sections themselves, ids included.
        $this->assertSame($before, $this->story($entry));
    }

    public function test_a_section_change_needs_permission_and_a_field_that_exists(): void
    {
        $entry = $this->entry();
        $viewerId = $this->createUser('viewer');
        DB::table('site_members')->insert(['site_id' => $this->f['siteId'], 'user_id' => $viewerId, 'role' => 'viewer']);
        $this->withoutVite();
        $this->actingAs(User::findOrFail($viewerId));

        $this->postJson("/admin/api/pages/{$entry}/sections", ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote'])->assertForbidden();
        $this->assertSame([], $this->story($entry));

        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
        $this->postJson("/admin/api/pages/{$entry}/sections", ['field' => 'nothing', 'op' => 'insertBlock', 'layout' => 'quote'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This entry has no content sections called "nothing".');
        $this->postJson("/admin/api/pages/{$entry}/sections", ['field' => 'story', 'op' => 'insertBlock', 'layout' => 'quote'])->assertOk();
        $this->assertCount(1, $this->story($entry));
    }

    public function test_the_number_of_sections_is_capped(): void
    {
        $entry = $this->entry();
        $blocks = array_map(fn ($n) => ['_layout' => 'text_section', 'heading' => "Section {$n}"], range(1, 51));

        $this->expectExceptionMessage('at most 50 sections');
        app(EntryFields::class)->update($this->f['ctx'], $entry, ['story' => $blocks]);
    }

    public function test_a_content_sections_field_needs_layouts_with_fields(): void
    {
        $base = [
            'key' => 'thing', 'name' => 'Things', 'singular' => 'Thing', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/things', 'inMenu' => false, 'menuOrder' => 3, 'details' => false, 'taxonomies' => [],
        ];
        $field = fn (array $layouts) => [...$base, 'fields' => [['key' => 'story', 'label' => 'Story', 'type' => 'flexible', 'settings' => (object) [], 'layouts' => $layouts]]];

        foreach ([
            [[], 'needs at least one layout'],
            [[['key' => 'Quote', 'label' => 'Quote', 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text', 'settings' => (object) []]]]], 'a layout key uses lowercase'],
            [[['key' => 'quote', 'label' => '', 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text', 'settings' => (object) []]]]], 'needs a name'],
            [[['key' => 'quote', 'label' => 'Quote', 'fields' => []]], 'needs at least one field'],
            [[
                ['key' => 'quote', 'label' => 'Quote', 'fields' => [['key' => 'a', 'label' => 'A', 'type' => 'text', 'settings' => (object) []]]],
                ['key' => 'quote', 'label' => 'Again', 'fields' => [['key' => 'b', 'label' => 'B', 'type' => 'text', 'settings' => (object) []]]],
            ], 'cannot share the key'],
        ] as [$layouts, $expected]) {
            try {
                app(ContentTypeService::class)->create($this->f['ctx'], $field($layouts));
                $this->fail("Accepted layouts that should have been refused: {$expected}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function test_a_layout_cannot_hold_a_group_a_repeater_or_more_sections(): void
    {
        $base = [
            'key' => 'thing', 'name' => 'Things', 'singular' => 'Thing', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/things', 'inMenu' => false, 'menuOrder' => 3, 'details' => false, 'taxonomies' => [],
        ];
        foreach (['group', 'repeater', 'flexible'] as $type) {
            $inner = ['key' => 'inner', 'label' => 'Inner', 'type' => $type, 'settings' => (object) []];
            if ($type === 'flexible') {
                $inner['layouts'] = [['key' => 'a', 'label' => 'A', 'fields' => [['key' => 'b', 'label' => 'B', 'type' => 'text', 'settings' => (object) []]]]];
            } else {
                $inner['fields'] = [['key' => 'b', 'label' => 'B', 'type' => 'text', 'settings' => (object) []]];
            }
            try {
                app(ContentTypeService::class)->create($this->f['ctx'], [...$base, 'fields' => [
                    ['key' => 'story', 'label' => 'Story', 'type' => 'flexible', 'settings' => (object) [], 'layouts' => [
                        ['key' => 'one', 'label' => 'One', 'fields' => [$inner]],
                    ]],
                ]]);
                $this->fail("A layout accepted a {$type} inside it");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('cannot go inside another group', $e->getMessage());
            }
        }
    }

    /** How many sections a rendering drew (the stylesheet names the class too). */
    private static function drawn(string $html): int
    {
        return substr_count($html, 'class="ak-section-template');
    }

    /** The headings each drawn section shows, in document order. */
    private static function headings(string $html): array
    {
        preg_match_all('/<h3[^>]*>(.*?)<\/h3>/s', $html, $matches);

        return array_map(fn ($one) => trim(strip_tags($one)), $matches[1]);
    }

    /**
     * A page whose content-sections block draws one template per layout: a heading for a text
     * section, the words for a quote.
     *
     * @param  array<string, string>  $templates  node id => layout key
     */
    private function sectionsDocument(array $templates = ['sect0001' => 'text_section', 'sect0002' => 'quote']): array
    {
        $nodes = [
            'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 6, 'props' => new \stdClass, 'children' => ['flex0001']],
            'flex0001' => ['id' => 'flex0001', 'type' => 'sections', 'version' => 1, 'props' => ['field' => 'story'], 'children' => array_keys($templates)],
        ];
        $i = 0;
        foreach ($templates as $id => $layout) {
            $textId = 'text000'.++$i;
            $nodes[$id] = ['id' => $id, 'type' => 'section-template', 'version' => 1, 'props' => ['layout' => $layout], 'children' => [$textId]];
            $nodes[$textId] = [
                'id' => $textId, 'type' => 'text', 'version' => 3,
                'props' => ['text' => 'A heading', 'element' => 'h3'],
                'bindings' => ['text' => ['source' => 'entry', 'field' => $layout === 'quote' ? 'quote_text' : 'heading']],
            ];
            if ($layout === 'quote') {
                $nodes[$id]['children'][] = 'said0001';
                $nodes['said0001'] = [
                    'id' => 'said0001', 'type' => 'text', 'version' => 3, 'props' => ['text' => 'Someone'],
                    'bindings' => ['text' => ['source' => 'entry', 'field' => 'person']],
                ];
            }
        }

        return ['schemaVersion' => 1, 'root' => 'root0001', 'nodes' => $nodes, 'seo' => new \stdClass];
    }

    private function preview(string $pageId, array $document): string
    {
        DB::table('page_drafts')->where('page_id', $pageId)->update(['document' => Json::encode($document)]);

        return app(PageService::class)->renderPreview($this->f['ctx'], $pageId);
    }
}
