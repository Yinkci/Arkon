<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Media\MediaLibrary;
use App\Arkon\Pages\PublicPages;
use App\Arkon\Seo\SeoDashboard;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Support\Json;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SiteOverviewController extends Controller
{
    private function identity(string $siteId): array
    {
        $row = DB::table('sites')->where('id', $siteId)->first(['name', 'settings']);
        $settings = Json::entries(Json::decode($row->settings));

        return ['name' => $row->name, 'language' => $settings['lang'] ?? 'en'];
    }

    public function media(Request $r, MediaLibrary $media)
    {
        $input = $r->validate(['q' => 'nullable|string|max:120', 'sort' => 'nullable|in:newest,oldest,name,largest,smallest', 'page' => 'nullable|integer|min:1|max:100000', 'status' => 'nullable|in:library,trash']);

        return Inertia::render('Admin/Media', ['library' => $media->browse(AdminContext::of($r)->ctx(), trim($input['q'] ?? ''), $input['sort'] ?? 'newest', (int) ($input['page'] ?? 1), $input['status'] ?? 'library')]);
    }

    public function settings(Request $r, Authorizer $auth, PublicPages $public)
    {
        $ctx = AdminContext::of($r)->ctx();
        $auth->authorize($ctx, 'page.publish');

        return Inertia::render('Admin/SiteOverview', ['section' => 'settings', 'identity' => $this->identity($ctx->siteId), 'origin' => $public->origin($ctx->siteId), 'domains' => DB::table('site_domains')->where('site_id', $ctx->siteId)->orderBy('hostname')->pluck('hostname')->all()]);
    }

    public function seo(Request $r, Authorizer $auth, SeoDashboard $seo)
    {
        $ctx = AdminContext::of($r)->ctx();
        $auth->authorize($ctx, 'page.view');
        $input = $r->validate(['page' => 'nullable|integer|min:1|max:100000', 'q' => 'nullable|string|max:120', 'status' => 'nullable|string|in:'.implode(',', array_keys(SeoDashboard::FILTERS))]);

        return Inertia::render('Admin/SeoDashboard', $seo->overview($ctx->siteId, (int) ($input['page'] ?? 1), trim($input['q'] ?? ''), $input['status'] ?? 'all'));
    }
}
