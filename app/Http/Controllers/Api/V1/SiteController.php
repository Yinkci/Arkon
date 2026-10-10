<?php

namespace App\Http\Controllers\Api\V1;

use App\Arkon\Api\Scopes;
use App\Arkon\Content\ContentTypes;
use App\Arkon\Content\Taxonomies;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Seo\SeoDefaults;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use App\Http\Api\ApiError;
use App\Http\Api\ApiPrincipal;
use App\Http\Api\ApiResponse;
use App\Http\Api\Pagination;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Discovery, public site details, navigation and authors: the read-only parts of a headless site. */
final class SiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $base = $request->getSchemeAndHttpHost().'/api/v1';

        return ApiResponse::item($request, [
            'name' => 'Arkon API', 'version' => 'v1', 'base_url' => $base, 'openapi' => $base.'/openapi.json',
            'resources' => [
                ...array_values(array_map(fn ($t) => $base.'/'.$t['collection'], ContentTypes::all())),
                ...array_values(array_map(fn ($t) => $base.'/'.$t['collection'], Taxonomies::all())),
                $base.'/media', $base.'/forms', $base.'/navigation', $base.'/site', $base.'/users',
            ],
            'scopes' => Scopes::all(),
        ]);
    }

    public function openapi(): Response
    {
        return response()->file(resource_path('api/openapi.json'), ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300']);
    }

    /** Public site details. Nothing secret: no settings beyond what pages already publish. */
    public function show(Request $request): JsonResponse
    {
        $siteId = ApiPrincipal::of($request)->siteId;
        $site = DB::table('sites')->where('id', $siteId)->first(['name', 'settings']);
        $settings = Json::toArray(Json::decode($site->settings ?: '{}'));
        $seo = SeoDefaults::published($siteId)['values'] ?? [];

        return ApiResponse::item($request, [
            'name' => $site->name,
            'description' => (string) ($seo['description'] ?? ''),
            'url' => $request->getSchemeAndHttpHost(),
            'language' => is_string($settings['lang'] ?? null) ? $settings['lang'] : 'en',
            'timezone' => (string) config('app.timezone'),
            'logo' => null,
            'favicon' => null,
            'organization' => ['name' => (string) ($seo['organizationName'] ?? ''), 'type' => (string) ($seo['organizationType'] ?? 'Organization')],
        ]);
    }

    /** Published menus as trees. Page links resolve to where the page is live now (null when it is not). */
    public function navigation(Request $request): JsonResponse
    {
        $siteId = ApiPrincipal::of($request)->siteId;
        $page = Pagination::from($request, 20);
        $menus = $this->menus($siteId);
        $items = array_map(fn ($m) => $this->menu($request, $siteId, $m), array_slice($menus, ($page->page - 1) * $page->perPage, $page->perPage));

        return ApiResponse::collection($request, $items, $page, count($menus));
    }

    /** One published menu by id, or by location (`main`). */
    public function menuShow(Request $request): JsonResponse
    {
        $menu = (string) $request->route('menu');
        $siteId = ApiPrincipal::of($request)->siteId;
        if ($menu === 'main') {
            $menu = (string) DB::table('site_website_settings')->where('site_id', $siteId)->value('main_menu_id');
        }
        $found = Uuid::isValid($menu) ? ($this->menus($siteId, $menu)[0] ?? null) : null;

        return $found === null ? throw ApiError::notFound('Menu') : ApiResponse::item($request, $this->menu($request, $siteId, $found));
    }

    /** Public author profile: only people who authored something that is live; members with read:users see anyone. */
    public function user(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $principal = ApiPrincipal::of($request);
        $member = $principal->authenticated() && $principal->has('read:users');
        $q = DB::table('users as u')->join('site_members as m', fn ($j) => $j->on('m.user_id', '=', 'u.id')->where('m.site_id', '=', $principal->siteId))->where('u.id', Uuid::isValid($id) ? $id : null);
        if (! $member) {
            $q->whereExists(fn ($e) => $e->from('pages as p')->join('live_pages as l', 'l.page_id', '=', 'p.id')->whereColumn('p.created_by', 'u.id')->where('p.site_id', $principal->siteId)->whereNull('p.deleted_at'));
        }
        $user = $q->first(['u.id', 'u.name', 'm.role']);

        return $user === null ? throw ApiError::notFound('User') : ApiResponse::item($request, ['id' => $user->id, 'name' => $user->name, ...($member ? ['role' => $user->role] : [])]);
    }

    public function users(Request $request): JsonResponse
    {
        $ctx = ApiPrincipal::of($request)->require('read:users');
        $page = Pagination::from($request, 20);
        $q = DB::table('users as u')->join('site_members as m', 'm.user_id', '=', 'u.id')->where('m.site_id', $ctx->siteId);
        $total = (clone $q)->count();
        $rows = $q->orderBy('u.name')->orderBy('u.id')->offset(($page->page - 1) * $page->perPage)->limit($page->perPage)->get(['u.id', 'u.name', 'm.role']);

        return ApiResponse::collection($request, $rows->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role])->all(), $page, $total);
    }

    private function menus(string $siteId, ?string $id = null): array
    {
        return DB::table('site_menus as m')->join('site_menu_versions as v', fn ($j) => $j->on('v.site_id', '=', 'm.site_id')->on('v.menu_id', '=', 'm.id')->on('v.version', '=', 'm.published_version'))
            ->where('m.site_id', $siteId)->when($id, fn ($q) => $q->where('m.id', $id))->orderBy('m.created_at')->orderBy('m.id')->get(['m.id', 'v.version', 'v.definition'])->all();
    }

    private function menu(Request $request, string $siteId, object $m): array
    {
        $definition = MenuService::resolve($siteId, Json::decode($m->definition));
        $main = DB::table('site_website_settings')->where('site_id', $siteId)->value('main_menu_id') === $m->id;
        $targets = DB::table('pages')->where('site_id', $siteId)->whereIn('id', array_filter(array_column($definition['items'], 'pageId')))->pluck('kind', 'id');
        $node = fn ($i) => [
            'id' => $i['id'], 'label' => $i['label'], 'type' => $i['type'],
            'url' => $i['resolvedHref'] === '#' ? null : $i['resolvedHref'],
            'target' => $i['pageId'] !== null && isset($targets[$i['pageId']]) ? ['type' => $targets[$i['pageId']], 'id' => $i['pageId']] : null,
            ...($i['type'] === 'section' ? ['anchor' => $i['anchor']] : []),
        ];
        $items = [];
        foreach ($definition['items'] as $i) {
            if ($i['parentId'] === null) {
                $items[] = [...$node($i), 'children' => array_values(array_map($node, array_filter($definition['items'], fn ($c) => $c['parentId'] === $i['id'])))];
            }
        }

        return ['id' => $m->id, 'name' => $definition['name'], 'location' => $main ? 'main' : null, 'version' => (int) $m->version, 'items' => $items];
    }
}
