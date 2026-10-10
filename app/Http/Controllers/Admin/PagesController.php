<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PagesController extends Controller
{
    public function index(Request $request, PageService $pages, PageManagement $management): Response
    {
        $ctx = AdminContext::of($request)->ctx();

        return Inertia::render('Admin/Pages', ['pages' => $pages->listPages($ctx), 'trash' => $management->trash($ctx)]);
    }
}
