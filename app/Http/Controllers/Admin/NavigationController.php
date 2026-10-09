<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Navigation\MenuService;
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

        return Inertia::render('Admin/Navigation', [
            'menus' => $menus->list($a->ctx()),
            'pages' => $menus->destinations($a->ctx()),
            'layout' => DB::table('site_website_settings')->where('site_id', $a->siteId)->first(['header_id', 'footer_id']),
            'permissions' => ['edit' => $a->can('page.edit'), 'publish' => $a->can('page.publish')],
        ]);
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
