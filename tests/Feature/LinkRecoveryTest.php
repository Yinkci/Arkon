<?php

namespace Tests\Feature;

use App\Arkon\Components\Factories;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use stdClass;
use Tests\DatabaseTestCase;
use Tests\Support\OlderLinkPolicy;

/**
 * Drafts stored before backslash links were refused: they open in a recovery state
 * (instead of failing to load), show the stored values, and return to a normal draft
 * once the user corrects or removes the affected blocks. Stored revisions, publications
 * and live output are never rewritten.
 */
class LinkRecoveryTest extends DatabaseTestCase
{
    use OlderLinkPolicy;

    private array $f;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->siteFixture();
        $this->addDomain($this->f['siteId'], 'recovery.test');
        $this->assetId = app(MediaService::class)->upload($this->f['ctx'], self::png(), 'dot.png')['id'];
    }

    private function pages(): PageService
    {
        return app(PageService::class);
    }

    /**
     * A draft as older code stored it: a hero with an image, one button per href (the first at
     * the top level, the others inside a column), written directly like any stored draft.
     */
    private function olderDraft(array $hrefs): string
    {
        $hero = Factories::heroNode(['heading' => 'Older page', 'image' => ['assetId' => $this->assetId, 'alt' => 'A dot']]);
        $nodes = [
            'root0001' => ['id' => 'root0001', 'type' => 'page', 'version' => 2, 'props' => new stdClass, 'children' => [$hero['id'], 'butn0001', 'cols0001']],
            $hero['id'] => $hero,
            'cols0001' => ['id' => 'cols0001', 'type' => 'columns', 'version' => 1, 'props' => new stdClass, 'children' => ['colu0001']],
            'colu0001' => ['id' => 'colu0001', 'type' => 'column', 'version' => 1, 'props' => new stdClass, 'children' => []],
        ];
        foreach (array_values($hrefs) as $i => $href) {
            $id = sprintf('butn%04d', $i + 1);
            $nodes[$id] = ['id' => $id, 'type' => 'button', 'version' => 1, 'props' => ['label' => "Button {$id}", 'href' => $href]];
            if ($i > 0) {
                $nodes['colu0001']['children'][] = $id;
            }
        }

        return $this->addPage($this->f['siteId'], '/older', 'Older', ['schemaVersion' => 1, 'root' => 'root0001', 'nodes' => $nodes, 'seo' => new stdClass]);
    }

    private function version(string $pageId): int
    {
        return (int) DB::table('page_drafts')->where('page_id', $pageId)->value('version');
    }

    private function save(string $pageId, array $ops): array
    {
        return $this->pages()->saveDraft($this->f['ctx'], [
            'pageId' => $pageId, 'baseVersion' => $this->version($pageId), 'saveKey' => self::key(), 'operations' => Json::decode(Json::encode($ops)),
        ]);
    }

    private function publish(string $pageId): array
    {
        return $this->pages()->publish($this->f['ctx'], ['pageId' => $pageId, 'expectedVersion' => $this->version($pageId), 'idempotencyKey' => self::key()]);
    }

    private function item(string $nodeId, string $value): array
    {
        return ['nodeId' => $nodeId, 'type' => 'button', 'path' => 'href', 'value' => $value, 'message' => Rules::message('unsafeLink')];
    }

    public function test_a_draft_with_a_backslash_link_opens_in_recovery_instead_of_failing_to_load(): void
    {
        $pageId = $this->olderDraft(['/\\example.com']);
        $stored = DB::table('page_drafts')->where('page_id', $pageId)->value('document');
        $owner = User::findOrFail($this->f['ctx']->userId);

        $this->actingAs($owner)->get("/admin/editor/{$pageId}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Editor')
            ->where('init.recovery', [$this->item('butn0001', '/\\example.com')])
            ->where('init.draft.version', 1)
            // The page is shown as stored, image included, so the user sees what they are repairing.
            ->where('init.canvas.body', fn ($body) => str_contains($body, 'data-ak-id="butn0001"') && str_contains($body, $this->assetId))
            ->where('init.media', fn ($media) => count($media) === 1 && $media[0]['id'] === $this->assetId));

        // The member preview renders under the current rules: not until the repair is saved.
        $this->actingAs($owner)->get("/preview/{$pageId}")->assertStatus(400);

        // Opening changed nothing.
        $this->assertSame($stored, DB::table('page_drafts')->where('page_id', $pageId)->value('document'));
        $this->assertSame(1, $this->version($pageId));
        $this->assertSame(0, DB::table('page_revisions')->where('page_id', $pageId)->count());
    }

    public function test_correcting_and_removing_several_links_returns_to_a_normal_draft_with_its_media(): void
    {
        $pageId = $this->olderDraft(['/\\example.com', 'https://\\evil.example']);
        $state = $this->pages()->editorInit($this->f['ctx'], $pageId, new MediaSigner);
        $this->assertSame([$this->item('butn0001', '/\\example.com'), $this->item('butn0002', 'https://\\evil.example')], $state['recovery']);
        $this->assertSame([$this->assetId], array_column($state['media'], 'id'));

        // Until both are resolved, nothing else saves and nothing publishes.
        $heroId = $state['draft']['document']['nodes']['root0001']['children'][0];
        $this->assertThrows(fn () => $this->save($pageId, [['op' => 'updateProps', 'nodeId' => $heroId, 'set' => ['heading' => 'Changed']]]), ValidationException::class);
        $this->assertThrows(fn () => $this->save($pageId, [['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['href' => '/contact']]]), ValidationException::class);
        $this->assertThrows(fn () => $this->save($pageId, [['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['href' => '/\\other.example']], ['op' => 'removeNode', 'nodeId' => 'butn0002']]), ValidationException::class);
        $this->assertThrows(fn () => $this->publish($pageId), ValidationException::class);
        $this->assertSame(1, $this->version($pageId));

        // The repair: one link corrected, one block removed, in one save.
        $this->save($pageId, [['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['href' => '/contact']], ['op' => 'removeNode', 'nodeId' => 'butn0002']]);
        $state = $this->pages()->editorInit($this->f['ctx'], $pageId, new MediaSigner);
        $this->assertNull($state['recovery']);
        $this->assertSame(2, $state['draft']['version']);
        $this->assertSame([$this->assetId], array_column($state['media'], 'id'));

        // A normal draft again: it saves and publishes, with its image.
        $this->save($pageId, [['op' => 'updateProps', 'nodeId' => $heroId, 'set' => ['heading' => 'Repaired page']]]);
        $this->publish($pageId);
        $html = $this->pages()->livePage($this->f['siteId'], '/older')->html;
        $this->assertStringContainsString('href="/contact"', $html);
        $this->assertStringContainsString("/media/{$this->assetId}.png", $html);
        $this->assertStringContainsString('Repaired page', $html);
        $this->assertStringNotContainsString('\\', $html);
    }

    public function test_live_output_and_history_recorded_with_old_links_are_untouched_by_recovery(): void
    {
        $pageId = $this->olderDraft(['/contact']);
        $this->underOlderLinkPolicy(function () use ($pageId) {
            $this->save($pageId, [['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['href' => '/\\example.com']]]);
            $this->publish($pageId);
        });
        $publicationId = DB::table('publications')->where('page_id', $pageId)->value('id');
        $liveBefore = $this->pages()->livePage($this->f['siteId'], '/older')->html;
        $history = DB::table('page_revisions')->where('page_id', $pageId)->orderBy('number')->pluck('document')->all();
        $this->assertStringContainsString('href="/\\example.com"', $liveBefore);

        $this->assertSame([$this->item('butn0001', '/\\example.com')], $this->pages()->editorState($this->f['ctx'], $pageId)['recovery']);
        $this->save($pageId, [['op' => 'updateProps', 'nodeId' => 'butn0001', 'set' => ['href' => '/example']]]);

        // Repaired in the draft only: the live page and history are what they were until the next publish.
        $this->assertSame($liveBefore, $this->pages()->livePage($this->f['siteId'], '/older')->html);
        $this->assertSame($history, array_slice(DB::table('page_revisions')->where('page_id', $pageId)->orderBy('number')->pluck('document')->all(), 0, count($history)));
        $this->publish($pageId);
        $this->assertStringContainsString('href="/example"', $this->pages()->livePage($this->f['siteId'], '/older')->html);
        $result = $this->pages()->reproducePublication($this->f['siteId'], $publicationId);
        $this->assertSame('reproduced', $result['status'], (string) $result['reason']);
        $this->assertTrue($result['matches'], 'the publication with the old link still reproduces byte for byte');
    }

    public function test_drafts_invalid_in_other_ways_are_not_opened_in_recovery(): void
    {
        $pageId = $this->olderDraft(['/\\example.com']);
        $doc = Json::decode(DB::table('page_drafts')->where('page_id', $pageId)->value('document'));
        $doc['nodes']['butn0001']['props']['label'] = str_repeat('x', 81); // too long: not explained by an older policy
        DB::table('page_drafts')->where('page_id', $pageId)->update(['document' => Json::encode($doc)]);

        $this->assertNull($this->pages()->editorState($this->f['ctx'], $pageId)['recovery']);
        $this->assertThrows(fn () => $this->pages()->editorInit($this->f['ctx'], $pageId, new MediaSigner), ValidationException::class);
    }
}
