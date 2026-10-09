<?php

namespace Tests\Feature;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormDefinition;
use App\Arkon\Forms\FormEntries;
use App\Arkon\Forms\FormManagement;
use App\Arkon\Forms\FormNotifications;
use App\Arkon\Forms\FormService;
use App\Arkon\Pages\PageService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Arkon\Upgrades\DataUpgrades;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\DatabaseTestCase;
use Tests\Support\Parallel;

final class FormsRedesignTest extends DatabaseTestCase
{
    private function definition(): array
    {
        return FormDefinition::modern(['name' => 'Enquiry', 'submitLabel' => 'Send', 'successMessage' => 'Thank you', 'fields' => [['id' => 'email', 'label' => 'Your email', 'type' => 'email', 'required' => true], ['id' => 'service', 'label' => 'Service', 'type' => 'select', 'required' => true, 'options' => ['Design', 'Marketing']], ['id' => 'details', 'label' => 'Project details', 'type' => 'textarea', 'required' => true]]]);
    }

    private function fixture(?array $d = null): array
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'forms2.test');
        $d ??= $this->definition();
        $s = app(FormService::class);
        $form = $s->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $d]);
        $s->publish($f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $node = ['id' => 'form_123', 'type' => 'form', 'version' => 4, 'props' => ['form' => ['id' => $form['id']], 'style' => new \stdClass]];
        app(PageService::class)->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => [['op' => 'insertNode', 'parentId' => $f['document']['root'], 'index' => 1, 'nodes' => [$node]]]]);
        app(PageService::class)->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);

        return [$f, $form];
    }

    private function submit(array $form, array $values, string $key)
    {
        return $this->withHeaders(['Origin' => 'http://forms2.test'])->postJson('http://forms2.test/_arkon/forms/'.$form['id'].'/1', ['fields' => $values, 'requestKey' => $key]);
    }

    public function test_blank_draft_is_allowed_but_cannot_be_published(): void
    {
        $f = $this->siteFixture();
        $d = $this->definition();
        $d['fields'] = [];
        $s = app(FormService::class);
        $form = $s->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $d]);
        $this->expectException(ValidationException::class);
        $s->publish($f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
    }

    public function test_conditional_fields_are_validated_on_server_and_hidden_values_are_discarded(): void
    {
        $d = $this->definition();
        $d['fields'][2]['condition'] = ['mode' => 'all', 'rules' => [['fieldId' => 'service', 'operator' => 'is', 'value' => 'Design']]];
        $d = FormDefinition::validate($d);
        $this->assertSame(['email' => 'a@example.com', 'service' => 'Marketing'], FormDefinition::submission($d, ['email' => 'a@example.com', 'service' => 'Marketing', 'details' => 'forged hidden value']));
        $this->expectException(ValidationException::class);
        FormDefinition::submission($d, ['email' => 'a@example.com', 'service' => 'Design']);
    }

    public function test_conditional_cycles_and_malformed_conditions_are_refused(): void
    {
        $d = $this->definition();
        $d['fields'][0]['condition'] = ['mode' => 'all', 'rules' => [['fieldId' => 'details', 'operator' => 'not_empty', 'value' => '']]];
        $d['fields'][2]['condition'] = ['mode' => 'all', 'rules' => [['fieldId' => 'email', 'operator' => 'not_empty', 'value' => '']]];
        try {
            FormDefinition::validate($d);
            $this->fail('Cycle accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('loop', $e->getMessage());
        }
        $d = $this->definition();
        $d['fields'][0]['condition'] = 'bad';
        $this->expectException(ValidationException::class);
        FormDefinition::validate($d);
    }

    public function test_choices_keep_independent_labels_and_values_and_refuse_unknown_values(): void
    {
        $d = $this->definition();
        $d['fields'][1]['type'] = 'checkboxes';
        $d['fields'][1]['choices'] = [['label' => 'Brand design', 'value' => 'design'], ['label' => 'Search marketing', 'value' => 'seo']];
        $d = FormDefinition::validate($d);
        $v = FormDefinition::submission($d, ['email' => 'a@example.com', 'service' => ['design', 'seo'], 'details' => 'Hello']);
        $this->assertSame(['design', 'seo'], $v['service']);
        $this->expectException(ValidationException::class);
        FormDefinition::submission($d, ['email' => 'a@example.com', 'service' => ['not-real'], 'details' => 'Hello']);
    }

    public function test_numeric_bounds_and_step_are_checked(): void
    {
        $d = $this->definition();
        $d['fields'] = [array_replace($d['fields'][0], ['id' => 'budget', 'type' => 'number', 'min' => 10, 'max' => 20, 'step' => 2])];
        $d = FormDefinition::validate($d);
        $this->assertSame(['budget' => '12'], FormDefinition::submission($d, ['budget' => '12']));
        $this->expectException(ValidationException::class);
        FormDefinition::submission($d, ['budget' => '13']);
    }

    public function test_submissions_are_encrypted_searchable_and_exact_retries_create_one_entry(): void
    {
        [$f,$form] = $this->fixture();
        $values = ['email' => 'visitor@example.com', 'service' => 'Design', 'details' => 'A garden project'];
        $key = self::key();
        $this->submit($form, $values, $key)->assertOk()->assertJsonPath('ok', true);
        $this->submit($form, $values, $key)->assertOk();
        $this->assertSame(1, DB::table('form_submissions')->count());
        $entry = DB::table('form_submissions')->first();
        $this->assertStringNotContainsString('visitor@example.com', $entry->payload);
        $this->assertStringNotContainsString('garden', $entry->search_tokens);
        $this->assertSame($values, Json::decode(Crypt::decryptString($entry->payload)));
        $entries = app(FormEntries::class);
        $this->assertSame(1, $entries->browse($f['ctx'], $form['id'], ['q' => 'garden'])['total']);
        $this->assertSame(1, $entries->browse($f['ctx'], $form['id'], ['q' => 'visitor@example.com'])['total']);
        $this->assertSame(0, $entries->browse($f['ctx'], $form['id'], ['q' => '!!!'])['total']);
        $this->submit($form, [...$values, 'details' => 'Changed'], $key)->assertStatus(409);
        $this->assertSame(1, DB::table('form_submissions')->count());
    }

    public function test_entries_use_original_field_labels_and_can_be_trashed_and_restored(): void
    {
        [$f,$form] = $this->fixture();
        $this->submit($form, ['email' => 'visitor@example.com', 'service' => 'Design', 'details' => 'Old values'], self::key())->assertOk();
        $entry = DB::table('form_submissions')->first();
        $d = $this->definition();
        $d['fields'][0]['label'] = 'New email label';
        app(FormService::class)->save($f['ctx'], ['id' => $form['id'], 'baseVersion' => 1, 'requestKey' => self::key(), 'definition' => $d]);
        $s = app(FormEntries::class);
        $detail = $s->detail($f['ctx'], $form['id'], $entry->id);
        $this->assertSame('Your email', $detail['fields'][0]['label']);
        $s->change($f['ctx'], $form['id'], ['ids' => [$entry->id], 'action' => 'trash']);
        $this->assertSame(0, $s->browse($f['ctx'], $form['id'], [])['total']);
        $this->assertSame(1, $s->browse($f['ctx'], $form['id'], ['filter' => 'trash'])['total']);
        $s->change($f['ctx'], $form['id'], ['ids' => [$entry->id], 'action' => 'restore']);
        $this->assertSame(1, $s->browse($f['ctx'], $form['id'], [])['total']);
    }

    public function test_editor_cannot_read_entries_or_notification_addresses_and_other_sites_are_isolated(): void
    {
        [$f,$form] = $this->fixture();
        $d = $this->definition();
        $d['notifications'] = [$this->notification()];
        app(FormService::class)->save($f['ctx'], ['id' => $form['id'], 'baseVersion' => 1, 'requestKey' => self::key(), 'definition' => $d]);
        $editor = $this->addMember($f['siteId'], 'editor');
        $this->assertSame([], app(FormManagement::class)->detail($editor, $form['id'])['definition']['notifications']);
        $this->assertSame([], app(FormService::class)->list($editor)[0]['definition']['notifications']);
        try {
            app(FormEntries::class)->browse($editor, $form['id'], []);
            $this->fail('Entry access granted');
        } catch (ForbiddenException) {
            $this->assertTrue(true);
        }
        $other = $this->siteFixture();
        $this->expectException(NotFoundException::class);
        app(FormManagement::class)->detail($other['ctx'], $form['id']);
    }

    private function notification(): array
    {
        return ['id' => 'admin_notice', 'name' => 'Design requests', 'enabled' => true, 'recipient' => 'owner@example.com', 'replyTo' => '{email}', 'fromName' => 'Arkon', 'subject' => 'New {service} request', 'message' => '{all_fields}', 'condition' => ['mode' => 'all', 'rules' => [['fieldId' => 'service', 'operator' => 'is', 'value' => 'Design']]]];
    }

    public function test_notifications_route_conditionally_and_strip_header_newlines(): void
    {
        $d = $this->definition();
        $d['notifications'] = [$this->notification()];
        $d = FormDefinition::validate($d);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->withArgs(function ($body, $configure) {
            $this->assertStringContainsString('Your email: visitor@example.com', $body);
            $m = new Message(new Email);
            $configure($m);
            $this->assertSame('New Design request', $m->getSymfonyMessage()->getSubject());
            $this->assertSame('visitor@example.com', $m->getSymfonyMessage()->getReplyTo()[0]->getAddress());

            return true;
        });
        $r = FormNotifications::send($d, ['email' => 'visitor@example.com', 'service' => 'Design', 'details' => 'Hello'], Uuid::v7(), '2026-10-10', null);
        $this->assertSame('sent', $r[0]['status']);
        $this->assertSame([], FormNotifications::send($d, ['email' => 'visitor@example.com', 'service' => 'Marketing'], Uuid::v7(), '2026-10-10', null));
        $d['notifications'][0]['recipient'] = "x@example.com\r\nBcc: bad@example.com";
        $this->expectException(ValidationException::class);
        FormDefinition::validate($d);
    }

    public function test_mail_failure_does_not_lose_entry_and_log_mail_never_receives_private_values(): void
    {
        $d = $this->definition();
        $d['notifications'] = [$this->notification()];
        [$f,$form] = $this->fixture($d);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('Transport down'));
        $this->submit($form, ['email' => 'visitor@example.com', 'service' => 'Design', 'details' => 'Private message'], self::key())->assertOk();
        $this->assertSame('failed', DB::table('form_submissions')->value('notification_status'));
        config(['mail.default' => 'log']);
        $r = FormNotifications::send($d, ['email' => 'visitor@example.com', 'service' => 'Design'], Uuid::v7(), '2026-10-10', null);
        $this->assertSame('unconfigured', $r[0]['status']);
    }

    public function test_redirect_confirmation_is_safe_and_submission_origin_is_checked(): void
    {
        $d = $this->definition();
        $d['confirmation'] = ['type' => 'redirect', 'message' => '', 'url' => '/thank-you'];
        [$f,$form] = $this->fixture($d);
        $this->submit($form, ['email' => 'visitor@example.com', 'service' => 'Design', 'details' => 'Hello'], self::key())->assertJsonPath('redirect', '/thank-you');
        $this->withHeaders(['Origin' => 'http://foreign.test'])->postJson('http://forms2.test/_arkon/forms/'.$form['id'].'/1', ['fields' => []])->assertForbidden();
        $d['confirmation']['url'] = '//evil.test';
        $this->expectException(ValidationException::class);
        FormDefinition::validate($d);
    }

    public function test_native_validation_preserves_input_and_escapes_html(): void
    {
        [$f,$form] = $this->fixture();
        $response = $this->withHeaders(['Origin' => 'http://forms2.test'])->post('http://forms2.test/_arkon/forms/'.$form['id'].'/1', ['fields' => ['email' => 'bad', 'service' => 'Design', 'details' => '<script>alert(1)</script>']]);
        $response->assertStatus(422);
        $response->assertSee('value="bad"', false);
        $response->assertSee('&lt;script&gt;', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_duplicate_retries_keep_one_new_form_without_entries_and_archive_retains_history(): void
    {
        $f = $this->siteFixture();
        $s = app(FormService::class);
        $form = $s->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $this->definition()]);
        $m = app(FormManagement::class);
        $key = self::key();
        $a = $m->duplicate($f['ctx'], $form['id'], $key);
        $b = $m->duplicate($f['ctx'], $form['id'], $key);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(2, DB::table('site_forms')->count());
        $s->publish($f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $m->archive($f['ctx'], $form['id'], 1);
        $this->assertSame(1, DB::table('site_form_versions')->count());
        $this->assertSame(1, $m->browse($f['ctx'], '', 1)['total']);
    }

    public function test_form_used_on_live_page_cannot_be_archived(): void
    {
        [$f,$form] = $this->fixture();
        $this->expectException(ConflictException::class);
        app(FormManagement::class)->archive($f['ctx'], $form['id'], 1);
    }

    public function test_csv_export_filters_and_neutralizes_formula_cells(): void
    {
        [$f,$form] = $this->fixture();
        $this->submit($form, ['email' => 'visitor@example.com', 'service' => 'Design', 'details' => '=HYPERLINK("bad")'], self::key())->assertOk();
        $response = app(FormEntries::class)->export($f['ctx'], $form['id'], ['q' => 'visitor@example.com']);
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Your email', $csv);
        $this->assertStringContainsString('visitor@example.com', $csv);
    }

    public function test_old_embedding_adapts_to_new_form_definition_without_rewriting_revision_and_reproduces(): void
    {
        $f = $this->siteFixture();
        $s = app(FormService::class);
        $legacy = ['name' => 'Legacy', 'submitLabel' => 'Send', 'successMessage' => 'Thanks', 'fields' => [['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]];
        $form = $s->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $legacy]);
        $s->publish($f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $doc = $f['document'];
        $node = ['id' => 'old_form', 'type' => 'form', 'version' => 3, 'props' => ['form' => ['id' => $form['id']], 'style' => new \stdClass]];
        $doc['nodes'][$doc['root']]['children'][] = $node['id'];
        $doc['nodes'][$node['id']] = $node;
        $renderer = app(PageRenderer::class);
        $before = $renderer->render($doc, 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test'], [], pinned: true, rendererVersion: 'arkon-php-5', resources: ['forms' => [$form['id'] => ['version' => 1, 'definition' => $legacy]]]);
        $this->assertSame($before['html'], $renderer->reproduce($doc, $before['inputs'])['html']);
        $after = $renderer->render($doc, 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test'], [], pinned: true, resources: ['forms' => [$form['id'] => ['version' => 2, 'definition' => $this->definition()]]]);
        $this->assertStringContainsString('ak-form4', $after['html']);
        $this->assertStringContainsString('/_arkon/forms-2.js', $after['html']);
        $this->assertSame($after['html'], $renderer->reproduce($doc, $after['inputs'])['html']);
        $v6 = $renderer->render($doc, 'production', ['title' => 'Home', 'path' => '/'], ['name' => 'Test'], [], pinned: true, rendererVersion: 'arkon-php-6', resources: ['forms' => [$form['id'] => ['version' => 2, 'definition' => $this->definition()]]]);
        $this->assertStringContainsString('/_arkon/forms-1.js', $v6['html']);
        $this->assertStringNotContainsString('/_arkon/forms-2.js', $v6['html']);
        $this->assertSame($v6['html'], $renderer->reproduce($doc, $v6['inputs'])['html']);
        $this->assertSame(3, $doc['nodes']['old_form']['version']);
    }

    public function test_conditions_agree_with_browser_shared_cases(): void
    {
        $cases = json_decode(file_get_contents(base_path('tests/Conformance/form-conditions.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $c) {
            $this->assertSame($c['expected'], FormDefinition::matches($c['condition'], $c['values']), $c['name']);
        }
    }

    public function test_parallel_save_and_publish_retries_create_one_version_each(): void
    {
        $f = $this->siteFixture();
        $input = ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $this->definition()];
        $race = new Parallel;
        for ($i = 0; $i < 3; $i++) {
            $race->add('formSave', $f['ctx'], $input);
        }$results = $race->wait();
        foreach ($results as $r) {
            $this->assertTrue($r['ok'], json_encode($r));
        }$ids = array_unique(array_column(array_column($results, 'result'), 'id'));
        $this->assertCount(1, $ids);
        $this->assertSame(1, DB::table('site_forms')->count());
        $publish = ['id' => $ids[0], 'expectedVersion' => 1, 'requestKey' => self::key()];
        $race = new Parallel;
        for ($i = 0; $i < 3; $i++) {
            $race->add('formPublish', $f['ctx'], $publish);
        }foreach ($race->wait() as $r) {
            $this->assertTrue($r['ok'], json_encode($r));
        }$this->assertSame(1, DB::table('site_form_versions')->count());
    }

    public function test_existing_entries_are_indexed_without_changing_encrypted_payload_or_labels(): void
    {
        [$f,$form] = $this->fixture();
        $this->submit($form, ['email' => 'visitor@example.com', 'service' => 'Design', 'details' => 'A garden project'], self::key())->assertOk();
        $entry = DB::table('form_submissions')->first();
        DB::table('form_submissions')->where('id', $entry->id)->update(['search_tokens' => '{}']);
        $upgrade = app(DataUpgrades::class);
        $this->assertSame(['entries' => 1], $upgrade->indexFormEntries());
        $this->assertSame(['entries' => 1], $upgrade->indexFormEntries());
        $after = DB::table('form_submissions')->first();
        $this->assertSame($entry->payload, $after->payload);
        $this->assertSame($entry->created_at, $after->created_at);
        $this->assertSame($entry->form_version, $after->form_version);
        $this->assertSame(1, app(FormEntries::class)->browse($f['ctx'], $form['id'], ['q' => 'garden'])['total']);
    }
}
