<?php

namespace Tests\Feature;

use App\Arkon\Forms\FormService;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

final class FormsTest extends DatabaseTestCase
{
    private function setupForm(): array
    {
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'forms.test');
        $def = ['name' => 'Enquiry', 'submitLabel' => 'Send', 'successMessage' => 'Thank you', 'fields' => [['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true], ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true]]];
        $s = app(FormService::class);
        $form = $s->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => $def]);
        $formNode = ['id' => 'form_123', 'type' => 'form', 'version' => 3, 'props' => ['form' => ['id' => $form['id']], 'style' => new \stdClass]];
        $ops = [['op' => 'insertNode', 'parentId' => $f['document']['root'], 'index' => 1, 'nodes' => [$formNode]]];
        app(PageService::class)->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops))]);

        return [$f, $form, $def];
    }

    public function test_notifications_save_without_logging_enquiry_contents_and_rate_limits_apply(): void
    {
        [$f, $form] = $this->setupForm();
        $this->publish($f, $form);
        app(FormService::class)->notifications($f['ctx'], $form['id'], 'owner@example.com');
        $this->assertSame('owner@example.com', DB::table('site_forms')->where('id', $form['id'])->value('notification_email'));
        config(['mail.default' => 'log']);
        $url = 'http://forms.test/_arkon/forms/'.$form['id'].'/1';
        $this->withHeaders(['Origin' => 'http://foreign.test'])->post($url, ['fields' => ['email' => 'hi@example.com', 'message' => 'Hi']])->assertForbidden();
        $this->withHeaders(['Origin' => 'http://forms.test']);
        for ($i = 0; $i < 5; $i++) {
            $this->post($url, ['fields' => ['email' => 'hi@example.com', 'message' => 'Hi']])->assertOk();
        }
        $this->post($url, ['fields' => ['email' => 'hi@example.com', 'message' => 'Hi']])->assertStatus(429);
        $this->assertSame(5, DB::table('form_submissions')->count());
        $this->assertSame(['unconfigured'], DB::table('form_submissions')->pluck('notification_status')->unique()->all());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'form.notifications')->count());
    }

    public function test_sitemap_and_robots_include_only_indexable_live_pages_without_sessions(): void
    {
        [$f, $form] = $this->setupForm();
        $this->get('http://forms.test/sitemap.xml')->assertOk()->assertDontSee('<loc>', false);
        $this->publish($f, $form);
        $map = $this->get('http://forms.test/sitemap.xml')->assertOk()->assertSee('http://forms.test/', false);
        $this->assertFalse($map->headers->has('Set-Cookie'));
        $this->get('http://forms.test/robots.txt')->assertOk()->assertSee('Disallow: /admin', false)->assertSee('Sitemap: http://forms.test/sitemap.xml', false);
        $this->get('http://foreign.test/sitemap.xml')->assertNotFound();
        app(PageService::class)->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 2, 'saveKey' => self::key(), 'operations' => [['op' => 'updateSeo', 'set' => ['noindex' => true]]]]);
        app(PageService::class)->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 3, 'idempotencyKey' => self::key()]);
        $this->get('http://forms.test/sitemap.xml')->assertOk()->assertDontSee('<loc>', false);
    }

    private function publish(array $f, array $form): void
    {
        app(FormService::class)->publish($f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        app(PageService::class)->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
    }

    public function test_draft_form_cannot_receive_public_submissions(): void
    {
        [$f,$form] = $this->setupForm();
        $this->post('http://forms.test/_arkon/forms/'.$form['id'].'/1', ['fields' => ['email' => 'hi@example.com', 'message' => 'Hello']])->assertNotFound();
        $this->assertSame(0, DB::table('form_submissions')->count());
    }

    public function test_submission_validation_encryption_honeypot_and_host_isolation(): void
    {
        [$f,$form] = $this->setupForm();
        $this->publish($f, $form);
        $url = 'http://forms.test/_arkon/forms/'.$form['id'].'/1';
        $this->post($url, ['fields' => ['email' => 'invalid', 'message' => 'Hi']])->assertStatus(422);
        $this->post($url, ['fields' => 'bad'])->assertStatus(422);
        $this->post(str_replace('forms.test', 'unknown.test', $url), ['fields' => ['email' => 'hi@example.com', 'message' => 'Hi']])->assertNotFound();
        $this->post($url, ['website' => 'bot', 'fields' => []])->assertOk();
        $this->assertSame(0, DB::table('form_submissions')->count());
        $this->post($url, ['fields' => ['email' => 'hi@example.com', 'message' => 'Hello', 'untrusted' => 'extra']])->assertOk();
        $r = DB::table('form_submissions')->first();
        $this->assertStringNotContainsString('hi@example.com', $r->payload);
        $this->assertSame(['email' => 'hi@example.com', 'message' => 'Hello'], Json::decode(Crypt::decryptString($r->payload)));
    }

    public function test_form_publishing_refreshes_live_page_and_old_publication_reproduces(): void
    {
        [$f,$form,$def] = $this->setupForm();
        $this->publish($f, $form);
        $old = DB::table('publications')->first();
        $s = app(FormService::class);
        $def['submitLabel'] = 'Send a message';
        $s->save($f['ctx'], ['id' => $form['id'], 'baseVersion' => 1, 'requestKey' => self::key(), 'definition' => $def]);
        $s->publish($f['ctx'], $form['id'], ['expectedVersion' => 2, 'requestKey' => self::key()]);
        $this->assertSame(2, DB::table('publications')->count());
        $repro = app(PageService::class)->reproducePublication($f['siteId'], $old->id);
        $this->assertSame($old->html, $repro['html']);
        $this->post('http://forms.test/_arkon/forms/'.$form['id'].'/1', ['fields' => ['email' => 'hi@example.com', 'message' => 'Hi']])->assertNotFound();
    }

    public function test_form_save_and_publish_retries_and_stale_updates(): void
    {
        $f = $this->siteFixture();
        $s = app(FormService::class);
        $input = ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => ['name' => 'Basic', 'submitLabel' => 'Send', 'successMessage' => 'Thanks', 'fields' => [['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true]]]];
        $a = $s->save($f['ctx'], $input);
        $this->assertSame($a['id'], $s->save($f['ctx'], $input)['id']);
        $key = self::key();
        $s->publish($f['ctx'], $a['id'], ['expectedVersion' => 1, 'requestKey' => $key]);
        $this->assertTrue($s->publish($f['ctx'], $a['id'], ['expectedVersion' => 1, 'requestKey' => $key])['replayed']);
        $this->assertSame(1, DB::table('site_form_versions')->count());
    }
}
