<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Content\ContentTypes;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The list screen of pages, posts and other content types (one screen, one model). */
class PagesController extends Controller
{
    public function index(Request $request, PageService $pages, PageManagement $management): Response
    {
        $ctx = AdminContext::of($request)->ctx();
        $kind = (string) ($request->route('kind') ?? 'page');
        $type = ContentTypes::get($kind);

        return Inertia::render('Admin/Pages', [
            'type' => ['kind' => $kind, 'label' => $type['label'], 'plural' => $type['plural'], 'pathPrefix' => $type['pathPrefix'], 'taxonomies' => $type['taxonomies']],
            'pages' => $pages->listPages($ctx, $kind),
            'trash' => $management->trash($ctx, $kind),
        ]);
    }
}
