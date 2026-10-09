<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Sites\Permissions;
use App\Arkon\Support\Json;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class NavigationController extends Controller
{
    public function index(Request $r, MenuService $menus)
    {
        $a = AdminContext::of($r);

        return Inertia::render('Admin/Navigation', ['menus' => $menus->list($a->ctx()), 'pages' => DB::table('pages as p')->join('page_drafts as d', 'd.page_id', '=', 'p.id')->where('p.site_id', $a->siteId)->whereNull('p.deleted_at')->orderBy('p.title')->get(['p.id', 'p.title', 'p.path', 'd.document'])->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'path' => $p->path, 'anchors' => array_values(array_filter(array_map(fn ($n) => $n['type'] === 'section' ? (Json::entries($n['props'])['anchor'] ?? '') : '', Json::entries(Json::decode($p->document)['nodes']))))]), 'layout' => DB::table('site_website_settings')->where('site_id', $a->siteId)->first(['header_id', 'footer_id']), 'permissions' => ['edit' => Permissions::allows($a->role, 'page.edit'), 'publish' => Permissions::allows($a->role, 'page.publish')]]);
    }

    public function save(Request $r, MenuService $s)
    {
        return response()->json(['ok' => true, 'data' => $s->save(AdminContext::of($r)->ctx(), $r->all())]);
    }

    public function publish(Request $r, MenuService $s, string $menu)
    {
        return response()->json(['ok' => true, 'data' => $s->publish(AdminContext::of($r)->ctx(), $menu, $r->all())]);
    }

    public function layout(Request $r, WebsiteProposalService $s)
    {
        return response()->json(['ok' => true, 'data' => $s->prepareLayout(AdminContext::of($r)->ctx(), $r->all())]);
    }
}
