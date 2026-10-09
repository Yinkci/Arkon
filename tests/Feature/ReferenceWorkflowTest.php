<?php

namespace Tests\Feature;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\ProposalService;
use App\Arkon\Components\PatternLibrary;
use App\Arkon\Forms\FormService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\FakeClaudeRunner;

class ReferenceWorkflowTest extends DatabaseTestCase
{
    public function test_ai_can_propose_the_reference_layout_and_known_newsletter_without_images_and_requires_explicit_apply_publish(): void
    {
        config(['arkon.ai.repair_attempts' => 0]);
        $f = $this->siteFixture();
        $this->addDomain($f['siteId'], 'reference.test');
        $forms = app(FormService::class);
        $form = $forms->save($f['ctx'], ['baseVersion' => 0, 'requestKey' => self::key(), 'definition' => ['name' => 'Newsletter', 'submitLabel' => 'Subscribe', 'successMessage' => 'Signup recorded', 'fields' => [['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]]]);
        $forms->publish($f['ctx'], $form['id'], ['expectedVersion' => 1, 'requestKey' => self::key()]);
        $library = app(PatternLibrary::class);
        $doc = $library->document(['agency-hero', 'agency-newsletter']);
        $changes = [['action' => 'remove', 'id' => $f['heroId']]];
        $visit = function (string $id, string $parent = 'page') use (&$visit, &$changes, $doc, $form): void {
            $node = $doc['nodes'][$id];
            $props = Json::toArray($node['props']);
            if ($node['type'] === 'form') {
                $props['form'] = ['id' => $form['id']];
            }
            if (isset($props['style'])) {
                $settings = [];
                foreach ($props['style'] as $slot => $screens) {
                    foreach ($screens as $screen => $values) {
                        foreach ($values as $property => $value) {
                            $settings[] = compact('slot', 'screen', 'property', 'value');
                        }
                    }
                }
                $props['style'] = $settings;
            }
            $changes[] = ['action' => 'add', 'parent' => $parent, 'index' => null, 'ref' => $id, 'block' => ['type' => $node['type'], 'props' => $props]];
            foreach ($node['children'] ?? [] as $child) {
                $visit($child, 'new:'.$id);
            }
        };
        foreach ($doc['nodes'][$doc['root']]['children'] as $id) {
            $visit($id);
        }
        $connection = app(AiConnections::class)->create($f['siteId'], $f['ctx']->userId, 'helper', 'Reference test')['id'];
        app(AiConnections::class)->heartbeat($connection, FakeClaudeRunner::ready());
        $helper = DB::table('ai_connections')->where('id', $connection)->first();
        $ai = app(ProposalService::class);
        $request = $ai->request($f['ctx'], $f['pageId'], ['prompt' => 'Build the editable agency layout with a slider, service cards and newsletter.', 'baseVersion' => 1, 'requestKey' => self::key()]);
        $runner = new FakeClaudeRunner([['summary' => 'Editable agency website', 'notes' => ['Upload your photographs.'], 'tokenChanges' => [], 'changes' => $changes]]);
        (new AiHelper($ai, app(AiConnections::class), $runner))->tick($helper);
        $view = $ai->status($f['ctx'], $f['pageId'], $request['id'], new MediaSigner);
        $this->assertSame('proposed', $view['status'], Json::encode($view));
        $this->assertSame(1, DB::table('page_drafts')->where('page_id', $f['pageId'])->value('version'));
        $pages = app(PageService::class);
        $pages->saveDraft($f['ctx'], ['pageId' => $f['pageId'], 'baseVersion' => 1, 'saveKey' => self::key(), 'proposalId' => $request['id'], 'operations' => Json::decode(Json::encode($view['proposal']['operations']))]);
        $this->assertNull($pages->livePage($f['siteId'], '/'));
        $published = $pages->publish($f['ctx'], ['pageId' => $f['pageId'], 'expectedVersion' => 2, 'idempotencyKey' => self::key()]);
        $this->assertTrue($pages->reproducePublication($f['siteId'], $published['publicationId'])['matches']);
        $live = $pages->livePage($f['siteId'], '/');
        $this->assertStringContainsString('components-5.js', $live->html);
        $this->assertStringContainsString('ak-form3--inline', $live->html);
        $this->assertStringContainsString('Stay in the loop', $live->html);
    }

    public function test_unlisted_forms_cannot_be_submitted_as_a_page_proposal(): void
    {
        $f = $this->siteFixture();
        $connection = app(AiConnections::class)->create($f['siteId'], $f['ctx']->userId, 'mcp', 'Test')['id'];
        $proposal = ['summary' => 'Newsletter', 'notes' => [], 'tokenChanges' => [], 'changes' => [
            ['action' => 'add', 'parent' => 'page', 'index' => null, 'block' => ['type' => 'form', 'props' => ['form' => ['id' => Uuid::v7()]]]],
        ]];
        try {
            app(ProposalService::class)->submit($f['ctx'], $f['pageId'], [
                'prompt' => 'Add a newsletter', 'baseVersion' => 1, 'requestKey' => self::key(), 'proposal' => $proposal,
            ], $connection);
            $this->fail('An unknown form must not be offered for review.');
        } catch (AiException $error) {
            $this->assertStringContainsString('published forms supplied', $error->getMessage());
        }
        $this->assertSame(0, DB::table('ai_proposals')->count());
        $this->assertSame(1, DB::table('page_drafts')->where('page_id', $f['pageId'])->value('version'));
    }
}
