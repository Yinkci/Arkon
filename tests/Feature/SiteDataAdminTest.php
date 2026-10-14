<?php

namespace Tests\Feature;

use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\SiteDataService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\DatabaseTestCase;

/** The site data screen, its endpoints, and what the public API does and does not show. */
final class SiteDataAdminTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'sitedata.test');
        $this->actingAs(User::findOrFail($this->f['ctx']->userId));
    }

    private function companyGroup(): void
    {
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'company_information', 'name' => 'Company Information', 'description' => 'How to reach us',
            'rules' => ['groups' => [['rules' => [['attribute' => 'scope', 'operator' => 'is', 'value' => 'site']]]]],
            'fields' => [
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'settings' => (object) []],
                ['key' => 'support_rota', 'label' => 'Support rota', 'type' => 'text', 'settings' => ['private' => true]],
            ],
        ]);
    }

    public function test_the_screen_shows_the_fields_a_group_put_there(): void
    {
        $this->companyGroup();

        $this->get('/admin/site-data')->assertOk()->assertInertia(
            fn (Assert $p) => $p->component('Admin/SiteData')
                ->where('canEdit', true)
                ->has('data.sections', 1)
                ->where('data.sections.0.name', 'Company Information')
                ->has('data.sections.0.fields', 2)
                ->where('data.version', 1)
                ->has('history', 0)
        );
    }

    public function test_the_screen_explains_itself_before_any_group_is_placed_there(): void
    {
        $this->get('/admin/site-data')->assertOk()->assertInertia(fn (Assert $p) => $p->has('data.sections', 0));
    }

    public function test_values_save_through_the_endpoint_and_earlier_versions_are_kept(): void
    {
        $this->companyGroup();
        $this->postJson('/admin/api/site-data', ['values' => ['phone' => '+63 900 000 0000'], 'expectedVersion' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.values.phone', '+63 900 000 0000');

        $this->postJson('/admin/api/site-data', ['values' => ['phone' => '+63 911 111 1111']])->assertOk();
        $this->get('/admin/site-data')->assertOk()->assertInertia(fn (Assert $p) => $p->has('history', 2));

        // Saving against a version somebody else has already moved past is refused.
        $this->postJson('/admin/api/site-data', ['values' => ['phone' => '+63 922 222 2222'], 'expectedVersion' => 1])->assertStatus(409);
    }

    public function test_an_earlier_version_can_be_put_back_through_the_endpoint(): void
    {
        $this->companyGroup();
        $service = app(SiteDataService::class);
        $service->update($this->f['ctx'], ['phone' => '+63 900 000 0000']);
        $service->update($this->f['ctx'], ['phone' => '+63 911 111 1111']);
        $first = collect($service->history($this->f['ctx']))->firstWhere('version', 2);

        $this->postJson('/admin/api/site-data/restore', ['id' => $first['id']])
            ->assertOk()->assertJsonPath('data.values.phone', '+63 900 000 0000');
    }

    public function test_an_editor_may_read_site_data_but_not_change_it(): void
    {
        $this->companyGroup();
        $editorId = $this->createUser('editor');
        DB::table('site_members')->insert(['site_id' => $this->f['siteId'], 'user_id' => $editorId, 'role' => 'editor']);
        $this->actingAs(User::findOrFail($editorId));

        $this->get('/admin/site-data')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canEdit', false));
        $this->postJson('/admin/api/site-data', ['values' => ['phone' => '+63 900 000 0000']])->assertStatus(403);
    }

    public function test_the_public_api_shows_site_data_without_the_private_fields(): void
    {
        $this->companyGroup();
        app(SiteDataService::class)->update($this->f['ctx'], [
            'phone' => '+63 900 000 0000',
            'support_rota' => 'Alex on call until Friday',
        ]);

        $response = $this->getJson('http://sitedata.test/api/v1/site')->assertOk();
        $this->assertSame('+63 900 000 0000', $response->json('data.data.phone'));
        $this->assertNull($response->json('data.data.support_rota'), 'a private field never leaves the admin');
    }
}
