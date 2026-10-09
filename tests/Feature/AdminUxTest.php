<?php

namespace Tests\Feature;

use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\DatabaseTestCase;

class AdminUxTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'ux.test');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
    }

    public function test_dashboard_is_bounded_but_counts_and_homepage_cover_the_whole_site(): void
    {
        foreach (range(1, 8) as $i) {
            $this->addPage($this->f['siteId'], '/page-'.$i, 'Page '.$i);
        }
        $this->get('/admin')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Dashboard')->has('pages', 5)->where('counts.all', 9)->where('counts.draft', 9)->where('homepage.id', $this->f['pageId']));
    }

    public function test_navigation_destinations_preserve_old_routes_and_separate_design_sections(): void
    {
        foreach (['/admin/pages' => 'Admin/Pages', '/admin/media' => 'Admin/Media', '/admin/navigation' => 'Admin/Navigation', '/admin/seo' => 'Admin/SeoDashboard', '/admin/settings' => 'Admin/SiteOverview'] as $route => $component) {
            $this->get($route)->assertOk()->assertInertia(fn (Assert $p) => $p->component($component));
        }
        foreach (['/admin/design' => 'styles', '/admin/design/components' => 'components', '/admin/performance' => 'performance'] as $route => $section) {
            $this->get($route)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Design')->where('section', $section));
        }
    }

    public function test_search_is_literal_bounded_and_never_returns_other_sites_or_deleted_pages(): void
    {
        $other = $this->siteFixture('owner', 'Other');
        $this->addPage($other['siteId'], '/unique-match', 'Other match');
        $this->addPage($this->f['siteId'], '/match', 'Match');
        $deleted = $this->addPage($this->f['siteId'], '/removed-match', 'Removed match');
        DB::table('pages')->where('id', $deleted)->update(['deleted_at' => now()]);
        $this->getJson('/admin/search?q=match')->assertOk()->assertJsonCount(1, 'pages')->assertJsonPath('pages.0.title', 'Match');
        $this->getJson('/admin/search?q=%25')->assertOk()->assertJsonCount(0, 'pages');
        foreach (range(1, 12) as $i) {
            $this->addPage($this->f['siteId'], '/search-'.$i, 'Search '.$i);
        }
        $this->getJson('/admin/search?q=search')->assertJsonCount(10, 'pages');
    }

    public function test_settings_is_permission_gated_on_the_server_not_only_navigation(): void
    {
        foreach (['editor', 'viewer'] as $role) {
            $ctx = $this->addMember($this->f['siteId'], $role);
            $this->actingAs(User::findOrFail($ctx->userId))->get('/admin/settings')->assertForbidden();
            $this->get('/admin/media')->assertOk();
        }
    }

    public function test_seo_reports_saved_and_live_metadata_separately_without_publishing(): void
    {
        app(PageService::class)->publish($this->f['ctx'], ['pageId' => $this->f['pageId'], 'expectedVersion' => 1, 'idempotencyKey' => Uuid::v7()]);
        $doc = $this->f['document'];
        $doc['seo'] = (object) ['description' => 'Draft description', 'noindex' => true];
        DB::table('page_drafts')->where('page_id', $this->f['pageId'])->update(['document' => Json::encode($doc)]);
        $this->get('/admin/seo')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.draft.description', 'Draft description')->where('rows.0.published.description', '')->where('rows.0.draft.noindex', true)->where('rows.0.published.noindex', false));
    }
}
