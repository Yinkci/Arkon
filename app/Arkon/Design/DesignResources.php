<?php

namespace App\Arkon\Design;

use App\Arkon\Components\DocumentValidator;
use App\Arkon\Forms\FormService;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Style\Tokens;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;

/**
 * What rendering a page depends on besides its own document: the site's published
 * design tokens and the published versions of the reusable components it uses.
 * Drafts of either are never read here, so they can never reach a live page, a
 * preview or the page canvas.
 */
final class DesignResources
{
    public function __construct(private readonly DocumentValidator $validator) {}

    /**
     * The published state for rendering `$doc` (current versions).
     *
     * @return array{tokens: array{version: int|null, values: array}, components: array<string, array{version: int, document: array, name: string}>}
     */
    public function published(string $siteId, mixed $doc): array
    {
        $components = $this->publishedComponents($siteId, self::componentIds($doc));

        return ['tokens' => $this->publishedTokens($siteId), 'components' => $components, 'forms' => FormService::published($siteId, $doc, $components), 'menus' => MenuService::published($siteId, $doc, $components)];
    }

    /** @return array{version: int|null, values: array} */
    public function publishedTokens(string $siteId): array
    {
        $row = DB::table('site_token_sets as s')
            ->join('site_token_versions as v', fn ($j) => $j->on('v.site_id', '=', 's.site_id')->on('v.version', '=', 's.published_version'))
            ->where('s.site_id', $siteId)
            ->first(['v.version', 'v.tokens']);

        return $row ? ['version' => (int) $row->version, 'values' => Json::toArray(Json::decode($row->tokens))] : ['version' => null, 'values' => []];
    }

    /**
     * Published versions of the given components of this site (unpublished or foreign ids are left out).
     *
     * @param  list<string>  $ids
     * @return array<string, array{version: int, document: array, name: string}>
     */
    public function publishedComponents(string $siteId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('reusable_components as c')
            ->join('reusable_component_versions as v', fn ($j) => $j->on('v.component_id', '=', 'c.id')->on('v.version', '=', 'c.published_version'))
            ->where('c.site_id', $siteId)
            ->whereIn('c.id', $ids)
            ->get(['c.id', 'v.version', 'v.document', 'v.name']);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->id] = ['version' => (int) $row->version, 'document' => Json::decode($row->document), 'name' => $row->name];
        }
        ksort($out);

        return $out;
    }

    /**
     * The component versions a publication recorded (immutable rows), for reproduction.
     *
     * @param  array<string, int>  $versions  component id → version
     * @return array<string, array{version: int, document: array, name: string}>
     */
    public function recordedComponents(string $siteId, array $versions): array
    {
        $out = [];
        foreach ($versions as $id => $version) {
            $row = DB::table('reusable_component_versions')->where('site_id', $siteId)->where('component_id', $id)->where('version', $version)->first(['document', 'name']);
            if ($row) {
                $out[(string) $id] = ['version' => (int) $version, 'document' => Json::decode($row->document), 'name' => $row->name];
            }
        }

        return $out;
    }

    /** Media used by reusable component documents (their images become public with the page). */
    public function componentMediaRefs(array $components): array
    {
        $ids = [];
        foreach ($components as $component) {
            array_push($ids, ...$this->validator->mediaRefsLenient($component['document'])['ids']);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Reusable components referenced by instances in a document (raw form).
     *
     * @return list<string>
     */
    public static function componentIds(mixed $doc): array
    {
        $ids = [];
        $nodes = is_array($doc) ? Json::entries($doc['nodes'] ?? []) : [];
        foreach ($nodes as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'instance') {
                $id = Json::entries($node['props'] ?? [])['componentId'] ?? null;
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Dependency rows of a publication from its render inputs.
     *
     * @return list<array{kind: string, resource_id: string, version: int}>
     */
    public static function dependencies(string $siteId, array $inputs): array
    {
        if (! array_key_exists('tokens', $inputs)) {
            return []; // rendered before tokens existed (renderer arkon-php-1): depends on nothing shared
        }
        $rows = [['kind' => 'tokens', 'resource_id' => $siteId, 'version' => (int) (Json::entries($inputs['tokens'])['version'] ?? 0)]];
        foreach (Json::entries($inputs['reusable'] ?? []) as $id => $version) {
            $rows[] = ['kind' => 'component', 'resource_id' => (string) $id, 'version' => (int) $version];
        }

        foreach (Json::entries($inputs['forms'] ?? []) as $id => $form) {
            $rows[] = ['kind' => 'form', 'resource_id' => (string) $id, 'version' => (int) $form['version']];
        }

        foreach (Json::entries($inputs['menus'] ?? []) as $id => $menu) {
            $rows[] = ['kind' => 'menu', 'resource_id' => (string) $id, 'version' => (int) $menu['version']];
        }

        return $rows;
    }

    /**
     * The site's reusable components as page editors see them: name and the published
     * version (with its document, for detaching). Drafts are not included.
     *
     * @return list<array{id: string, name: string, published: int|null, document: array|null}>
     */
    public function componentsForEditor(string $siteId): array
    {
        $rows = DB::table('reusable_components as c')
            ->leftJoin('reusable_component_versions as v', fn ($j) => $j->on('v.component_id', '=', 'c.id')->on('v.version', '=', 'c.published_version'))
            ->where('c.site_id', $siteId)
            ->orderBy('c.name')
            ->get(['c.id', 'c.name', 'c.published_version', 'v.document', 'v.name as published_name']);

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->published_name ?? $r->name,
            'published' => $r->published_version === null ? null : (int) $r->published_version,
            'document' => $r->document === null ? null : Json::decode($r->document),
        ])->all();
    }

    /** Resolved token values for a site (published, defaults applied), for editor pickers. */
    public function resolvedTokens(string $siteId): array
    {
        return Tokens::resolve($this->publishedTokens($siteId)['values']);
    }
}
