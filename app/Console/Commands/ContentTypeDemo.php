<?php

namespace App\Console\Commands;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentTypeRegistry;
use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\EntryFields;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Sites\SiteContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Services example from the content-types brief: one defined type with fields of
 * several kinds, and a few entries filled in. Everything goes through the ordinary services,
 * so what it creates is exactly what the admin, the API or AI would create.
 */
final class ContentTypeDemo extends Command
{
    protected $signature = 'arkon:content-demo {--email= : The owner the content is made by} {--fresh : Remove the demo entries and type first}';

    protected $description = 'Create the example Services content type with entries';

    public function handle(ContentTypeService $types, ContentItems $items, EntryFields $fields, ContentTypeRegistry $registry): int
    {
        $user = DB::table('users')->where('email', (string) $this->option('email'))->first();
        $member = $user ? DB::table('site_members')->where('user_id', $user->id)->first() : null;
        if ($member === null) {
            $this->error('No such owner.');

            return self::FAILURE;
        }
        $ctx = new SiteContext($member->site_id, $user->id, 'cli');

        if ($this->option('fresh')) {
            $this->removeDemo($ctx);
            $registry->forget($ctx->siteId);
        }
        if ($registry->forSite($ctx->siteId)->exists('service')) {
            $this->warn('This site already has a content type with the key "service". Nothing was changed.');

            return self::SUCCESS;
        }

        $types->create($ctx, [
            'key' => 'service',
            'name' => 'Services',
            'singular' => 'Service',
            'description' => 'What this studio does for its customers',
            'icon' => 'component',
            'pathPrefix' => '/services',
            'inMenu' => true,
            'menuOrder' => 10,
            'details' => true,
            'taxonomies' => ['category'],
            'fields' => [
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'settings' => ['required' => true, 'maxLength' => 300, 'help' => 'One or two sentences, used in listings.']],
                ['key' => 'body', 'label' => 'Description', 'type' => 'rich_text', 'settings' => []],
                ['key' => 'price_from', 'label' => 'Price from', 'type' => 'decimal', 'settings' => ['min' => 0, 'unit' => 'PHP']],
                ['key' => 'duration_days', 'label' => 'Typical duration', 'type' => 'number', 'settings' => ['min' => 1, 'max' => 365, 'unit' => 'days']],
                ['key' => 'tier', 'label' => 'Tier', 'type' => 'select', 'settings' => [], 'choices' => [
                    ['label' => 'Starter', 'value' => 'starter'],
                    ['label' => 'Standard', 'value' => 'standard'],
                    ['label' => 'Enterprise', 'value' => 'enterprise'],
                ]],
                ['key' => 'featured', 'label' => 'Show on the home page', 'type' => 'boolean', 'settings' => []],
                ['key' => 'sku', 'label' => 'Reference code', 'type' => 'text', 'settings' => ['unique' => true, 'maxLength' => 20]],
            ],
        ]);
        $this->info('Defined the Services content type.');

        $entries = [
            ['Web development', ['summary' => 'Websites that are fast, accessible and easy to edit.', 'body' => '<p>From a one-page site to a full content model, built on <strong>Arkon</strong>.</p>', 'price_from' => 75000, 'duration_days' => 30, 'tier' => 'standard', 'featured' => true, 'sku' => 'WEB-01']],
            ['SEO consulting', ['summary' => 'Find out why pages do not rank, and fix the causes.', 'body' => '<p>A technical audit, then the changes that matter most.</p>', 'price_from' => 25000, 'duration_days' => 14, 'tier' => 'starter', 'featured' => true, 'sku' => 'SEO-01']],
            ['Website maintenance', ['summary' => 'Updates, backups and a person to call.', 'body' => '<p>Monthly care so a site stays current and safe.</p>', 'price_from' => 9000, 'duration_days' => 365, 'tier' => 'starter', 'featured' => false, 'sku' => 'CARE-01']],
        ];
        foreach ($entries as [$title, $values]) {
            $id = $items->create($ctx, 'service', ['title' => $title])['id'];
            $fields->update($ctx, $id, $values);
            $this->line('  '.DB::table('pages')->where('id', $id)->value('path'));
        }
        $this->info('Created '.count($entries).' services. They are drafts: publish them from the admin.');

        // A field group beside them, so the same set of fields can be given to another type later
        // without redefining it: the fields belong to the group, the values to each entry.
        app(FieldGroupService::class)->create($ctx, [
            'key' => 'service_details',
            'name' => 'Service Details',
            'description' => 'What a service costs and how long it takes',
            'assignments' => [['targetKind' => 'content_type', 'targetKey' => 'service']],
            'fields' => [
                ['key' => 'short_description', 'label' => 'Short Description', 'type' => 'textarea', 'settings' => ['maxLength' => 300, 'help' => 'One or two sentences, used in listings.']],
                ['key' => 'starting_price', 'label' => 'Starting Price', 'type' => 'decimal', 'settings' => ['min' => 0, 'unit' => 'PHP']],
                ['key' => 'duration_weeks', 'label' => 'Duration', 'type' => 'number', 'settings' => ['min' => 1, 'max' => 104, 'unit' => 'weeks']],
            ],
        ]);
        $this->info('Attached the Service Details field group to Services.');

        return self::SUCCESS;
    }

    /** Removes what an earlier run of this command made, so it can be run again from clean. */
    private function removeDemo(SiteContext $ctx): void
    {
        // Entries go through the ordinary Trash and purge, like anything else being removed:
        // revisions and publication history are append-only for the runtime role by design, so
        // nothing here deletes rows behind the application's back.
        $management = app(PageManagement::class);
        $ids = DB::table('pages')->where('site_id', $ctx->siteId)->where('kind', 'service')->whereNull('purged_at')->pluck('id');
        foreach ($ids as $id) {
            $page = DB::table('pages')->where('id', $id)->first(['deleted_at']);
            $version = (int) DB::table('page_drafts')->where('page_id', $id)->value('version');
            // A page still in use goes to the Trash first; one whose draft is already gone can
            // only be purged, which is also what Trash → Delete permanently does.
            if ($page->deleted_at === null && $version > 0) {
                $management->delete($ctx, ['pageId' => $id, 'expectedVersion' => $version]);
            }
            $management->purge($ctx, $id);
        }
        DB::table('field_group_assignments')->where('site_id', $ctx->siteId)->where('target_key', 'service')->delete();
        DB::table('field_groups')->where('site_id', $ctx->siteId)->where('key', 'service_details')->delete();
        DB::table('content_types')->where('site_id', $ctx->siteId)->where('key', 'service')->delete();
    }
}
