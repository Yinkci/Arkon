<?php

namespace App\Arkon\Sites;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Database\Transactions;
use App\Arkon\Design\ComponentService;
use App\Arkon\Design\DesignResources;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Design\TokenService;
use App\Arkon\Errors\ArkonException;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormService;
use App\Arkon\Media\MediaService;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Pages\PageService;
use App\Arkon\Pages\PageStore;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;

final class WebsitePublishing
{
    public function __construct(private readonly Authorizer $auth, private readonly Transactions $tx, private readonly PageStore $store) {}

    public function readiness(SiteContext $ctx, string $proposal): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $r = $this->application($ctx, $proposal);
        $issues = [];
        $reports = [];
        $resources = ['tokens' => ['version' => null, 'values' => Json::toArray(Json::decode(DB::table('site_token_sets')->where('site_id', $ctx->siteId)->value('draft') ?? '{}'))], 'components' => [], 'forms' => []];
        foreach ($r['shared'] as $s) {
            $row = DB::table('reusable_components')->where('site_id', $ctx->siteId)->where('id', $s['id'])->first();
            if (! $row || (int) $row->version !== $s['version']) {
                $issues[] = 'Shared layout changed after this website was applied.';
            }if ($row) {
                $resources['components'][$row->id] = ['version' => 1, 'document' => app(ComponentRegistry::class)->migrateDocument(Json::decode($row->draft)), 'name' => $row->name];
            }
        }
        if ($r['menu'] ?? null) {
            $menu = DB::table('site_menus')->where('site_id', $ctx->siteId)->where('id', $r['menu']['id'])->first();
            if (! $menu || (int) $menu->version !== $r['menu']['version']) {
                $issues[] = 'Navigation changed after this website was applied.';
            }
            if ($menu) {
                $resources['menus'][$menu->id] = ['version' => 1, 'definition' => MenuService::resolve($ctx->siteId, Json::decode($menu->draft), array_column($r['pages'], 'path', 'id'))];
            }
        }
        if ($r['form']) {
            $row = DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $r['form']['id'])->first();
            if (! $row || (int) $row->version !== $r['form']['version']) {
                $issues[] = 'Contact form changed after this website was applied.';
            }if ($row) {
                $resources['forms'][$row->id] = ['version' => 1, 'definition' => Json::decode($row->draft)];
            }
        }
        if (TokenService::draftVersion($ctx->siteId) !== $r['tokenVersion']) {
            $issues[] = 'Branding changed after this website was applied.';
        }
        // Read shared metadata once, and measure the same head as actual publishing.
        $site = DB::table('sites')->where('id', $ctx->siteId)->first(['name', 'settings']) ?? throw new NotFoundException('Site');
        $settings = Json::entries(Json::decode($site->settings));
        $renderSite = ['name' => $site->name, 'origin' => $this->store->origin($ctx->siteId), 'lang' => is_string($settings['lang'] ?? null) ? $settings['lang'] : 'en'];
        $documents = [];
        foreach ($r['pages'] as $p) {
            $raw = DB::table('page_drafts')->where('site_id', $ctx->siteId)->where('page_id', $p['id'])->value('document');
            if ($raw) {
                $documents[$p['id']] = Json::decode($raw);
            }
        }
        foreach ($r['pages'] as $p) {
            try {
                $page = $this->store->loadPage($ctx->siteId, $p['id']);
                $draft = $this->store->loadDraft($ctx->siteId, $p['id']);
                $this->store->assertVersion($draft, $p['version']);
                $doc = $this->store->document($draft->document);
                $seo = Json::entries($doc['seo']);
                $warnings = [];
                foreach (Json::entries($doc['nodes']) as $node) {
                    if ($node['type'] === 'button' && (Json::entries($node['props'])['href'] ?? '') === '#') {
                        $warnings[] = 'A button has a placeholder destination (#).';
                    }
                }
                foreach ($resources['menus'] ?? [] as $m) {
                    foreach ($m['definition']['items'] as $item) {
                        if (($item['resolvedHref'] ?? '#') === '#') {
                            $warnings[] = 'Menu item '.$item['label'].' has a placeholder or unpublished destination.';
                        }
                    }
                }
                foreach ($resources['menus'] ?? [] as $menu) {
                    $warnings = [...$warnings, ...MenuService::warnings($ctx->siteId, $menu['definition'], $documents)];
                }
                if (trim($seo['description'] ?? '') === '') {
                    $warnings[] = 'Add a search description in Page settings.';
                }
                $own = app(DesignResources::class)->published($ctx->siteId, $doc);
                $combined = [...$own, ...$resources, 'components' => [...$own['components'], ...$resources['components']], 'forms' => [...$own['forms'], ...$resources['forms']], 'menus' => [...($own['menus'] ?? []), ...($resources['menus'] ?? [])]];
                $refs = $this->store->mediaIdsFor($doc, $combined['components']);
                $media = app(MediaService::class)->mediaMap($ctx->siteId, $refs);
                $out = app(PageRenderer::class)->render($doc, 'production', ['title' => $page->title, 'path' => $page->path], $renderSite, $media, true, resources: $combined);
                $warnings = [...$warnings, ...array_column($out['report']['diagnostics']['warnings'], 'message')];
                foreach ($media as $m) {
                    if (($m['width'] ?? 0) > 2000 && empty($m['variants'])) {
                        $warnings[] = 'A large image has no responsive variants.';
                    }
                }
                $reports[] = ['id' => $p['id'], 'title' => $page->title, 'path' => $page->path, 'htmlBytes' => strlen($out['html']), 'cssBytes' => $out['report']['cssBytes'], 'scripts' => $out['report']['scripts'], 'diagnostics' => $out['report']['diagnostics'], 'warnings' => array_values(array_unique($warnings))];
            } catch (ArkonException|RenderException $e) {
                $issues[] = $p['title'].': '.$e->getMessage();
            }
        }

        return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues)), 'pages' => $reports, 'note' => 'These checks catch publishing errors and large output. Check real mobile layouts and run the performance command before launch; field Core Web Vitals require real visitors.'];
    }

    public function publish(SiteContext $ctx, string $proposal, array $input): array
    {
        $key = Input::validate($input, ['requestKey' => Input::requestKeyRule()])['requestKey'];
        $fp = Fingerprint::of(['kind' => 'website.publish', 'proposal' => $proposal]);
        $result = $this->tx->run(function () use ($ctx, $proposal, $key, $fp) {
            $this->auth->authorize($ctx, 'page.publish');
            // All page locks precede shared resource locks and the site's epoch. This also keeps refreshes out of the transaction.
            foreach (DB::table('pages')->where('site_id', $ctx->siteId)->whereNull('deleted_at')->orderBy('id')->pluck('id') as $id) {
                $this->store->lockForWrite($ctx->siteId, $id);
            }
            $old = DB::table('site_resource_requests')->where('site_id', $ctx->siteId)->where('request_key', $key)->first();
            if ($old) {
                if ($old->fingerprint !== $fp) {
                    throw new ConflictException('This publish key belongs to another request.');
                }

                return [...Json::decode($old->result), 'replayed' => true];
            }
            $r = $this->application($ctx, $proposal);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.forms:'.$ctx->siteId]);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.menus:'.$ctx->siteId]);
            if ($r['menu'] ?? null) {
                DB::table('site_menus')->where('site_id', $ctx->siteId)->where('id', $r['menu']['id'])->lockForUpdate()->first();
            }
            DB::table('reusable_components')->where('site_id', $ctx->siteId)->whereIn('id', array_column($r['shared'], 'id'))->orderBy('id')->lockForUpdate()->get();
            if ($r['form']) {
                DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $r['form']['id'])->lockForUpdate()->first();
            }
            DB::table('site_token_sets')->where('site_id', $ctx->siteId)->lockForUpdate()->first();
            $this->store->lockPathClaims($ctx->siteId);
            $check = $this->readiness($ctx, $proposal);
            if (! $check['ready']) {
                throw new ValidationException('Website is not ready to publish', array_map(fn ($m) => ['message' => $m], $check['issues']));
            }
            app(TokenService::class)->publish($ctx, ['expectedVersion' => $r['tokenVersion'], 'idempotencyKey' => $key.'-tokens']);
            foreach ($r['shared'] as $slot => $s) {
                app(ComponentService::class)->publish($ctx, $s['id'], ['expectedVersion' => $s['version'], 'idempotencyKey' => $key.'-'.$slot]);
            }
            if ($r['form']) {
                app(FormService::class)->publish($ctx, $r['form']['id'], ['expectedVersion' => $r['form']['version'], 'requestKey' => $key.'-form']);
            }
            if ($r['menu'] ?? null) {
                app(MenuService::class)->publish($ctx, $r['menu']['id'], ['expectedVersion' => $r['menu']['version'], 'requestKey' => $key.'-menu']);
            }
            $pages = MenuService::withPaths($ctx->siteId, array_column($r['pages'], 'path', 'id'), function () use ($ctx, $r, $key) {
                $pages = [];
                foreach ($r['pages'] as $p) {
                    $pages[] = app(PageService::class)->publish($ctx, ['pageId' => $p['id'], 'expectedVersion' => $p['version'], 'idempotencyKey' => $key.'-'.$p['id']]);
                }

                return $pages;
            });
            $result = ['pages' => $pages, 'replayed' => false];
            DB::table('site_resource_requests')->insert(['site_id' => $ctx->siteId, 'request_key' => $key, 'fingerprint' => $fp, 'result' => Json::encode($result)]);
            app(AuditLog::class)->forContext($ctx, 'website.publish', 'site', $ctx->siteId, ['proposal' => $proposal, 'pages' => array_column($r['pages'], 'id')]);

            return $result;
        });
        app(PageRefreshes::class)->run($ctx->siteId);

        return $result;
    }

    private function application(SiteContext $ctx, string $id): array
    {
        $r = DB::table('website_applications as a')->join('ai_proposals as p', 'p.id', '=', 'a.proposal_id')->where('a.site_id', $ctx->siteId)->where('p.created_by', $ctx->userId)->where('a.proposal_id', Input::id($id, 'Website proposal'))->first(['a.result']);

        return $r ? Json::decode($r->result) : throw new NotFoundException('Applied website proposal');
    }
}
