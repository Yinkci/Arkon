<?php

namespace Tests\Feature;

use App\Arkon\Content\ContentTypeService;
use App\Arkon\Content\FieldGroupService;
use App\Arkon\Content\FieldSchemaResolver;
use App\Arkon\Content\SiteDataService;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Site data: the values that belong to the website rather than to any entry. The same field
 * engine, the same validation, the same storage shape — only the owner differs.
 */
final class SiteDataTest extends DatabaseTestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
    }

    /** A rule that puts a group's fields on site data. */
    private static function onSiteData(): array
    {
        return ['groups' => [['rules' => [['attribute' => 'scope', 'operator' => 'is', 'value' => 'site']]]]];
    }

    /** The Company Information group from the plan's demonstration. */
    private function companyInformation(array $fields = []): array
    {
        return app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'company_information',
            'name' => 'Company Information',
            'description' => 'How to reach us',
            'rules' => self::onSiteData(),
            'fields' => $fields ?: [
                ['key' => 'company_name', 'label' => 'Company Name', 'type' => 'text', 'settings' => (object) []],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'settings' => (object) []],
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'settings' => (object) []],
                ['key' => 'address', 'label' => 'Address', 'type' => 'group', 'settings' => (object) [], 'fields' => [
                    ['key' => 'street', 'label' => 'Street', 'type' => 'text', 'settings' => (object) []],
                    ['key' => 'city', 'label' => 'City', 'type' => 'text', 'settings' => (object) []],
                ]],
                ['key' => 'hours', 'label' => 'Business Hours', 'type' => 'repeater', 'settings' => ['rowLabel' => 'day'], 'fields' => [
                    ['key' => 'day', 'label' => 'Day', 'type' => 'text', 'settings' => (object) []],
                    ['key' => 'opens', 'label' => 'Opens', 'type' => 'time', 'settings' => (object) []],
                ]],
            ],
        ]);
    }

    public function test_site_data_carries_the_fields_of_the_groups_placed_there(): void
    {
        $this->companyInformation();
        $shown = app(SiteDataService::class)->show($this->f['ctx']);

        $this->assertCount(1, $shown['sections']);
        $this->assertSame('Company Information', $shown['sections'][0]['name']);
        $this->assertSame(['company_name', 'phone', 'email', 'address', 'hours'], array_column($shown['sections'][0]['fields'], 'key'));
        $this->assertSame(1, $shown['version']);
    }

    public function test_a_group_meant_for_entries_does_not_land_on_site_data_and_the_reverse(): void
    {
        app(ContentTypeService::class)->create($this->f['ctx'], [
            'key' => 'service', 'name' => 'Services', 'singular' => 'Service', 'description' => '', 'icon' => 'component',
            'pathPrefix' => '/services', 'inMenu' => true, 'menuOrder' => 1, 'details' => false, 'taxonomies' => [], 'fields' => [],
        ]);
        app(FieldGroupService::class)->create($this->f['ctx'], [
            'key' => 'service_details', 'name' => 'Service Details', 'description' => '',
            'rules' => ['groups' => [['rules' => [['attribute' => 'content_type', 'operator' => 'is', 'value' => 'service']]]]],
            'fields' => [['key' => 'price', 'label' => 'Price', 'type' => 'decimal', 'settings' => (object) []]],
        ]);
        $this->companyInformation();

        // Site data has the company fields and not the service ones.
        $siteKeys = array_column(app(SiteDataService::class)->show($this->f['ctx'])['sections'][0]['fields'], 'key');
        $this->assertContains('company_name', $siteKeys);
        $this->assertNotContains('price', $siteKeys);

        // And an entry has the service ones and not the company ones.
        $entryFields = array_column(app(FieldSchemaResolver::class)->fieldsFor($this->f['siteId'], ['kind' => 'service']), 'key');
        $this->assertSame(['price'], $entryFields);
    }

    public function test_values_are_saved_validated_and_read_back_with_their_types(): void
    {
        $this->companyInformation();
        $service = app(SiteDataService::class);

        $after = $service->update($this->f['ctx'], [
            'company_name' => 'Arkon Studio',
            'phone' => '+63 900 000 0000',
            'email' => 'hello@example.test',
            'address' => ['street' => '12 Mabini St', 'city' => 'Tarlac'],
            'hours' => [['day' => 'Monday', 'opens' => '09:00'], ['day' => 'Tuesday', 'opens' => '09:00']],
        ]);

        $values = (array) $after['values'];
        $this->assertSame('Arkon Studio', $values['company_name']);
        $this->assertSame('Tarlac', $values['address']['city'], 'a group inside site data works like anywhere else');
        $this->assertCount(2, $values['hours']);
        $this->assertSame('09:00', $values['hours'][0]['opens']);
        $this->assertSame(2, $after['version'], 'saving moves the version on');

        // The same field rules apply here as anywhere.
        $this->expectException(ValidationException::class);
        $service->update($this->f['ctx'], ['email' => 'not-an-email']);
    }

    public function test_a_field_left_out_keeps_its_value_and_an_emptied_one_is_cleared(): void
    {
        $this->companyInformation();
        $service = app(SiteDataService::class);
        $service->update($this->f['ctx'], ['company_name' => 'Arkon Studio', 'phone' => '+63 900 000 0000']);

        $after = $service->update($this->f['ctx'], ['phone' => '+63 911 111 1111']);
        $this->assertSame('Arkon Studio', (array) $after['values'] ? ((array) $after['values'])['company_name'] : null);

        $cleared = $service->update($this->f['ctx'], ['phone' => null]);
        $this->assertArrayNotHasKey('phone', (array) $cleared['values']);
    }

    public function test_two_people_editing_at_once_cannot_quietly_overwrite_each_other(): void
    {
        $this->companyInformation();
        $service = app(SiteDataService::class);
        $version = $service->show($this->f['ctx'])['version'];
        $service->update($this->f['ctx'], ['company_name' => 'First'], $version);

        $this->expectException(ConflictException::class);
        $service->update($this->f['ctx'], ['company_name' => 'Second'], $version);
    }

    public function test_what_it_said_before_is_kept_and_can_be_put_back(): void
    {
        $this->companyInformation();
        $service = app(SiteDataService::class);
        $service->update($this->f['ctx'], ['phone' => '+63 900 000 0000']);
        $service->update($this->f['ctx'], ['phone' => '+63 911 111 1111']);

        $history = $service->history($this->f['ctx']);
        $this->assertCount(2, $history, 'each save keeps what it replaced');
        $this->assertSame(2, $history[0]['version']);

        // Putting an old version back is a new version, not a rewrite of the past.
        $restored = $service->restore($this->f['ctx'], $history[0]['id']);
        $this->assertSame('+63 900 000 0000', ((array) $restored['values'])['phone']);
        $this->assertSame(4, $restored['version']);
        $this->assertCount(3, $service->history($this->f['ctx']));
    }

    public function test_a_private_field_never_leaves_the_admin(): void
    {
        $this->companyInformation([
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'settings' => (object) []],
            ['key' => 'escalation_email', 'label' => 'Escalation email', 'type' => 'email', 'settings' => ['private' => true]],
        ]);
        $service = app(SiteDataService::class);
        $service->update($this->f['ctx'], ['phone' => '+63 900 000 0000', 'escalation_email' => 'oncall@example.test']);

        // The admin sees both, because that is where it is managed.
        $admin = (array) $service->show($this->f['ctx'])['values'];
        $this->assertSame('oncall@example.test', $admin['escalation_email']);

        // Everything public sees only what is not private.
        $public = $service->publicValues($this->f['siteId']);
        $this->assertSame('+63 900 000 0000', $public['phone']);
        $this->assertArrayNotHasKey('escalation_email', $public);
    }

    public function test_values_of_a_field_no_longer_placed_on_site_data_stop_being_read(): void
    {
        $group = $this->companyInformation([
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'settings' => (object) []],
        ]);
        $service = app(SiteDataService::class);
        $service->update($this->f['ctx'], ['phone' => '+63 900 000 0000']);

        // The group is moved off site data; its values are kept but no longer belong here.
        app(FieldGroupService::class)->update($this->f['ctx'], 'company_information', [...$group, 'rules' => ['groups' => []], 'confirmRemovals' => true]);
        $this->assertSame([], (array) $service->show($this->f['ctx'])['values']);
        // Saving what remains works: the value of the field moved away is kept, not checked.
        $this->assertSame('+63 900 000 0000', Json::decode(DB::table('site_data')->where('site_id', $this->f['siteId'])->value('values'))['phone']);
        $this->assertSame([], $service->publicValues($this->f['siteId']));

        // Put it back and the value is there again.
        app(FieldGroupService::class)->update($this->f['ctx'], 'company_information', [...$group, 'rules' => self::onSiteData()]);
        $this->assertSame('+63 900 000 0000', ((array) $service->show($this->f['ctx'])['values'])['phone']);
    }

    public function test_what_is_public_carries_no_bookkeeping_of_arkons_own(): void
    {
        $this->companyInformation();
        app(SiteDataService::class)->update($this->f['ctx'], [
            'hours' => [['day' => 'Monday', 'opens' => '09:00'], ['day' => 'Tuesday', 'opens' => '10:00']],
        ]);
        $public = app(SiteDataService::class)->publicValues($this->f['siteId']);

        // The row ids exist so the editor can move rows; a reader has no use for them.
        $this->assertSame([['day' => 'Monday', 'opens' => '09:00'], ['day' => 'Tuesday', 'opens' => '10:00']], $public['hours']);
        // The admin still has them, because that is what reordering needs.
        $this->assertArrayHasKey('_id', ((array) app(SiteDataService::class)->show($this->f['ctx'])['values'])['hours'][0]);
    }

    public function test_placing_fields_on_site_data_reads_as_a_sentence(): void
    {
        $group = $this->companyInformation();
        $this->assertSame('Site data', $group['appliesTo']);
        $this->assertSame([], $group['missingTargets'], 'a scope rule is not a missing target');
    }

    public function test_site_data_never_crosses_between_sites(): void
    {
        $this->companyInformation();
        app(SiteDataService::class)->update($this->f['ctx'], ['company_name' => 'Arkon Studio']);

        $other = $this->siteFixture();
        $this->assertSame([], (array) app(SiteDataService::class)->show($other['ctx'])['values']);
        $this->assertSame([], app(SiteDataService::class)->publicValues($other['siteId']));
    }

    public function test_changing_what_the_whole_website_says_needs_more_than_content_editing(): void
    {
        $this->companyInformation();
        $editorId = $this->createUser('editor');
        DB::table('site_members')->insert(['site_id' => $this->f['siteId'], 'user_id' => $editorId, 'role' => 'editor']);
        $editor = new SiteContext($this->f['siteId'], $editorId);

        // An editor may read it — it is on the pages they work on — but not change it.
        $this->assertIsArray(app(SiteDataService::class)->show($editor)['sections']);
        $this->expectException(ForbiddenException::class);
        app(SiteDataService::class)->update($editor, ['company_name' => 'Changed by an editor']);
    }

    public function test_saving_before_any_group_is_placed_there_says_so(): void
    {
        $this->expectException(ValidationException::class);
        app(SiteDataService::class)->update($this->f['ctx'], ['anything' => 'at all']);
    }

    public function test_media_in_site_data_stays_a_reference(): void
    {
        $asset = Uuid::v7();
        DB::table('media_assets')->insert([
            'id' => $asset, 'site_id' => $this->f['siteId'], 'storage_key' => $asset.'.png', 'mime' => 'image/png',
            'bytes' => 70, 'width' => 1, 'height' => 1, 'original_name' => 'logo.png',
        ]);
        $this->companyInformation([['key' => 'logo', 'label' => 'Logo', 'type' => 'media', 'settings' => (object) []]]);

        $after = app(SiteDataService::class)->update($this->f['ctx'], ['logo' => ['id' => $asset]]);
        $this->assertSame(['id' => $asset], ((array) $after['values'])['logo']);
    }

    /** The plan's demonstration: define it once, read it everywhere. */
    public function test_the_company_information_demonstration(): void
    {
        $this->companyInformation();
        $service = app(SiteDataService::class);
        $service->update($this->f['ctx'], [
            'company_name' => 'Arkon Studio',
            'phone' => '+63 900 000 0000',
            'email' => 'hello@example.test',
            'address' => ['street' => '12 Mabini St', 'city' => 'Tarlac'],
            'hours' => [['day' => 'Monday to Friday', 'opens' => '09:00']],
        ]);

        // One edit, and every reader sees the new value.
        $service->update($this->f['ctx'], ['phone' => '+63 922 222 2222']);
        $this->assertSame('+63 922 222 2222', $service->publicValues($this->f['siteId'])['phone']);
        $this->assertSame('+63 922 222 2222', ((array) $service->show($this->f['ctx'])['values'])['phone']);
        $this->assertSame('Tarlac', $service->publicValues($this->f['siteId'])['address']['city'], 'the rest is untouched');
    }
}
