<?php

namespace App\Arkon\Navigation;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

final class MenuService
{
    public function __construct(private readonly Authorizer $auth, private readonly Transactions $transactions) {}

    public static function validateDefinition(mixed $input): array
    {
        if (! is_array($input)) {
            throw new ValidationException('A menu definition is required.');
        }
        // Laravel converts empty HTTP strings to null; optional destinations retain their intentional empty/default meaning.
        if (! isset($input['items']) || ! is_array($input['items'])) {
            throw new ValidationException('Menu items must be a list.');
        }
        foreach ($input['items'] as &$item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['href', 'anchor'] as $field) {
                if (array_key_exists($field, $item) && $item[$field] === null) {
                    $item[$field] = '';
                }
            }
        } unset($item);
        $d = Input::validate($input, [
            'name' => ['required', 'string', 'max:100'], 'items' => ['present', 'array', 'max:50'],
            'items.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,39}$/D', 'distinct'],
            'items.*.label' => ['required', 'string', 'max:100'], 'items.*.type' => ['required', 'in:page,url,section'],
            'items.*.pageId' => ['nullable', 'uuid'], 'items.*.href' => ['present', 'string', 'max:2000'],
            'items.*.anchor' => ['present', 'string', 'max:64'], 'items.*.parentId' => ['nullable', 'string', 'max:40'],
        ]);
        $items = [];
        foreach ($d['items'] as $i) {
            if ($i['type'] !== 'url' && empty($i['pageId'])) {
                throw new ValidationException('Choose a page for '.$i['label'].'.');
            }
            if ($i['type'] === 'url' && ! Rules::matches('link', $i['href'])) {
                throw new ValidationException('Menu links must use a safe destination.');
            }
            if ($i['type'] === 'section' && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $i['anchor'])) {
                throw new ValidationException('Section anchors must start with a letter and contain letters, numbers, hyphens or underscores.');
            }
            $items[$i['id']] = ['id' => $i['id'], 'label' => $i['label'], 'type' => $i['type'], 'pageId' => $i['pageId'] ?? null, 'href' => $i['type'] === 'url' ? ($i['href'] ?: '#') : '', 'anchor' => $i['type'] === 'section' ? $i['anchor'] : '', 'parentId' => $i['parentId'] ?? null];
        }
        foreach ($items as $i) {
            if ($i['parentId'] !== null && (! isset($items[$i['parentId']]) || $i['parentId'] === $i['id'] || $items[$i['parentId']]['parentId'] !== null)) {
                throw new ValidationException('Dropdowns support one level; choose a top-level parent.');
            }
        }

        return ['name' => $d['name'], 'items' => array_values($items)];
    }

    public static function assertPages(string $siteId, array $definition): void
    {
        $ids = array_unique(array_filter(array_column($definition['items'], 'pageId')));
        $found = DB::table('pages')->where('site_id', $siteId)->whereNull('deleted_at')->whereIn('id', $ids)->pluck('id')->all();
        if (array_diff($ids, $found) !== []) {
            throw new ValidationException('A menu target page does not exist in this site.');
        }
    }

    /** Resolve the live URL once and pin it in publication inputs, never from a draft path. */
    private static array $pathScopes = [];

    public static function withPaths(string $siteId, array $paths, callable $work): mixed
    {
        $previous = self::$pathScopes[$siteId] ?? null;
        self::$pathScopes[$siteId] = [...($previous ?? []), ...$paths];
        try {
            return $work();
        } finally {
            if ($previous === null) {
                unset(self::$pathScopes[$siteId]);
            } else {
                self::$pathScopes[$siteId] = $previous;
            }
        }
    }

    /** Within a page publication transaction, enqueue menu dependents after live URL changes. */
    public static function targetChanged(string $siteId, string $pageId, int $epoch): void
    {
        if (isset(self::$pathScopes[$siteId])) {
            return;
        }
        foreach (DB::table('site_menus as m')->join('site_menu_versions as v', fn ($j) => $j->on('v.site_id', '=', 'm.site_id')->on('v.menu_id', '=', 'm.id')->on('v.version', '=', 'm.published_version'))->where('m.site_id', $siteId)->get(['m.id', 'v.definition']) as $menu) {
            if (in_array($pageId, array_column(Json::decode($menu->definition)['items'], 'pageId'), true)) {
                app(PageRefreshes::class)->enqueue($siteId, 'menu', $menu->id, $epoch);
            }
        }
    }

    public static function resolve(string $siteId, array $definition, array $pagePaths = []): array
    {
        $pagePaths = [...(self::$pathScopes[$siteId] ?? []), ...$pagePaths];
        foreach ($definition['items'] as &$item) {
            if ($item['type'] === 'url') {
                $item['resolvedHref'] = $item['href'] ?: '#';

                continue;
            }
            $path = $pagePaths[$item['pageId']] ?? DB::table('live_pages')->where('site_id', $siteId)->where('page_id', $item['pageId'])->value('path');
            $item['resolvedHref'] = $path === null ? '#' : $path.($item['type'] === 'section' ? '#'.$item['anchor'] : '');
        } unset($item);

        return $definition;
    }

    /** Read-only warnings: placeholder links are permitted, but never presented as complete destinations. */
    public static function warnings(string $siteId, array $definition, array $documents = []): array
    {
        $warnings = [];
        foreach ($definition['items'] as $item) {
            if (($item['resolvedHref'] ?? '#') === '#') {
                $warnings[] = 'Menu item '.$item['label'].' has a placeholder or unpublished destination.';

                continue;
            }
            if ($item['type'] !== 'section') {
                continue;
            }
            $doc = $documents[$item['pageId']] ?? null;
            if ($doc === null) {
                $raw = DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->join('page_revisions as r', 'r.id', '=', 'p.revision_id')->where('l.site_id', $siteId)->where('l.page_id', $item['pageId'])->value('r.document');
                $doc = $raw ? Json::decode($raw) : null;
            }
            $anchors = [];
            foreach (Json::entries($doc['nodes'] ?? []) as $node) {
                if ($node['type'] === 'section') {
                    $anchors[] = Json::entries($node['props'])['anchor'] ?? '';
                }
            }
            if (! in_array($item['anchor'], $anchors, true)) {
                $warnings[] = 'Menu item '.$item['label'].': section '.$item['anchor'].' is missing on its target page.';
            }
        }

        return array_values(array_unique($warnings));
    }

    public function list(SiteContext $ctx): array
    {
        $role = $this->auth->authorize($ctx, 'page.view');

        return DB::table('site_menus')->where('site_id', $ctx->siteId)->orderBy('name')->get()->map(fn ($row) => [
            'id' => $row->id, 'version' => (int) $row->version, 'publishedVersion' => $row->published_version, 'definition' => Json::decode($row->draft), 'warnings' => self::warnings($ctx->siteId, self::resolve($ctx->siteId, Json::decode($row->draft))),

        ])->all();
    }

    /**
     * Pages a menu item can point to, with the section anchors of each page's draft.
     *
     * @return list<array{id: string, title: string, path: string, anchors: list<string>}>
     */
    public function destinations(SiteContext $ctx): array
    {
        $this->auth->authorize($ctx, 'page.view');

        return DB::table('pages as p')->join('page_drafts as d', 'd.page_id', '=', 'p.id')
            ->where('p.site_id', $ctx->siteId)->whereNull('p.deleted_at')->orderBy('p.title')
            ->get(['p.id', 'p.title', 'p.path', 'd.document'])
            ->map(function ($p) {
                $anchors = [];
                foreach (Json::entries(Json::decode($p->document)['nodes']) as $node) {
                    $anchor = $node['type'] === 'section' ? (Json::entries($node['props'])['anchor'] ?? '') : '';
                    if ($anchor !== '') {
                        $anchors[] = $anchor;
                    }
                }

                return ['id' => $p->id, 'title' => $p->title, 'path' => $p->path, 'anchors' => $anchors];
            })->all();
    }

    public function save(SiteContext $ctx, array $input): array
    {
        $this->auth->authorize($ctx, 'page.edit');
        $v = Input::validate($input, ['id' => ['nullable', 'uuid'], 'baseVersion' => ['required', 'integer', 'min:0'], 'requestKey' => Input::requestKeyRule()]);
        $definition = self::validateDefinition($input['definition'] ?? null);
        $fingerprint = Fingerprint::of(['kind' => 'menu.save', 'id' => $v['id'] ?? null, 'base' => $v['baseVersion'], 'definition' => $definition]);

        return $this->transactions->run(function () use ($ctx, $v, $definition, $fingerprint) {
            $this->auth->authorize($ctx, 'page.edit');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.menus:'.$ctx->siteId]);
            if ($result = $this->replay($ctx, $v['requestKey'], $fingerprint)) {
                return $result;
            }
            $id = $v['id'] ?? Uuid::v7();
            $row = DB::table('site_menus')->where('site_id', $ctx->siteId)->where('id', $id)->lockForUpdate()->first();
            if (isset($v['id']) && ! $row) {
                throw new NotFoundException('Menu');
            }
            if ((int) ($row?->version ?? 0) !== (int) $v['baseVersion']) {
                throw new StaleVersionException((int) $v['baseVersion'], (int) ($row?->version ?? 0));
            }
            self::assertPages($ctx->siteId, $definition);
            $version = (int) $v['baseVersion'] + 1;
            $values = ['name' => $definition['name'], 'draft' => Json::encode($definition), 'version' => $version];
            if ($row) {
                DB::table('site_menus')->where('id', $id)->update($values);
            } else {
                DB::table('site_menus')->insert(['id' => $id, 'site_id' => $ctx->siteId, ...$values]);
            }

            app(AuditLog::class)->forContext($ctx, 'menu.draft.save', 'menu', $id, ['version' => $version]);

            return $this->record($ctx, $v['requestKey'], $fingerprint, ['id' => $id, 'version' => $version, 'replayed' => false]);
        });
    }

    public function publish(SiteContext $ctx, string $id, array $input): array
    {
        $id = Input::id($id, 'Menu');
        $v = Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1'], 'requestKey' => Input::requestKeyRule()]);
        $fp = Fingerprint::of(['kind' => 'menu.publish', 'id' => $id, 'version' => $v['expectedVersion']]);
        $result = $this->transactions->run(function () use ($ctx, $id, $v, $fp) {
            $this->auth->authorize($ctx, 'page.publish');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.menus:'.$ctx->siteId]);
            if ($result = $this->replay($ctx, $v['requestKey'], $fp)) {
                return $result;
            }
            $row = DB::table('site_menus')->where('site_id', $ctx->siteId)->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundException('Menu');
            if ((int) $row->version !== (int) $v['expectedVersion']) {
                throw new StaleVersionException((int) $v['expectedVersion'], (int) $row->version);
            }
            self::assertPages($ctx->siteId, Json::decode($row->draft));
            $epoch = app(PageStore::class)->lockNextEpoch($ctx->siteId);
            $version = (int) ($row->published_version ?? 0) + 1;
            DB::table('site_menu_versions')->insert(['site_id' => $ctx->siteId, 'menu_id' => $id, 'version' => $version, 'definition' => $row->draft, 'epoch' => $epoch]);
            DB::table('site_menus')->where('id', $id)->update(['published_version' => $version]);
            app(PageRefreshes::class)->enqueue($ctx->siteId, 'menu', $id, $epoch);

            app(AuditLog::class)->forContext($ctx, 'menu.publish', 'menu', $id, ['version' => $version]);

            return $this->record($ctx, $v['requestKey'], $fp, ['id' => $id, 'publishedVersion' => $version, 'replayed' => false]);
        });
        if (DB::transactionLevel() === 0) {
            app(PageRefreshes::class)->run($ctx->siteId);
        }

        return $result;
    }

    public static function assertReferences(string $siteId, mixed $doc): void
    {
        $ids = self::references($doc);
        $found = DB::table('site_menus')->where('site_id', $siteId)->whereIn('id', $ids)->pluck('id')->all();
        if (array_diff($ids, $found) !== []) {
            throw new ValidationException('A menu on this page does not exist in this site.');
        }
    }

    public static function references(mixed $doc): array
    {
        $ids = [];
        foreach (Json::entries($doc['nodes'] ?? []) as $node) {
            if (($node['type'] ?? '') === 'navigation') {
                $id = Json::entries($node['props'] ?? [])['menuId'] ?? '';
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public static function published(string $siteId, mixed $doc, array $components = []): array
    {
        $ids = self::references($doc);
        foreach ($components as $component) {
            $ids = [...$ids, ...self::references($component['document'])];
        }
        $out = [];
        foreach (DB::table('site_menus as f')->join('site_menu_versions as v', fn ($j) => $j->on('v.menu_id', '=', 'f.id')->on('v.site_id', '=', 'f.site_id')->on('v.version', '=', 'f.published_version'))->where('f.site_id', $siteId)->whereIn('f.id', array_unique($ids))->get(['f.id', 'v.version', 'v.definition']) as $r) {
            $out[$r->id] = ['version' => (int) $r->version, 'definition' => self::resolve($siteId, Json::decode($r->definition))];
        }

        return $out;
    }

    private function replay(SiteContext $ctx, string $key, string $fp): ?array
    {
        $r = DB::table('site_resource_requests')->where('site_id', $ctx->siteId)->where('request_key', $key)->first();
        if (! $r) {
            return null;
        }if ($r->fingerprint !== $fp) {
            throw new ConflictException('This request key belongs to a different resource change.');
        }

        return [...Json::decode($r->result), 'replayed' => true];
    }

    private function record(SiteContext $ctx, string $key, string $fp, array $result): array
    {
        DB::table('site_resource_requests')->insert(['site_id' => $ctx->siteId, 'request_key' => $key, 'fingerprint' => $fp, 'result' => Json::encode($result)]);

        return $result;
    }
}
