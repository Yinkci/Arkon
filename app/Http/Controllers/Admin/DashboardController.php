<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Pages\Overview;
use App\Arkon\Pages\PageService;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PageService $pages, Overview $overview): Response
    {
        $ctx = AdminContext::of($request)->ctx();

        $all = $pages->listPages($ctx);
        $counts = ['all' => count($all), 'draft' => 0, 'published' => 0, 'changed' => 0];
        foreach ($all as $page) {
            $counts[$page['status']]++;
        }
        $home = collect($all)->first(fn ($p) => $p['path'] === '/' || $p['livePath'] === '/');
        usort($all, fn ($a, $b) => strcmp($b['updatedAt'] ?? '', $a['updatedAt'] ?? ''));

        return Inertia::render('Admin/Dashboard', ['pages' => array_slice($all, 0, 5), 'counts' => $counts, 'homepage' => $home, 'overview' => $overview->forDashboard($ctx)]);
    }
}
