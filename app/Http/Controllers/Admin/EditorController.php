<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageService;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EditorController extends Controller
{
    public function show(Request $request, string $page, PageService $pages, MediaSigner $signer, ComponentRegistry $registry): Response
    {
        $admin = AdminContext::of($request);

        return Inertia::render('Admin/Editor', [
            'init' => [
                // Page, draft, live state, history and the first canvas paint, all from one snapshot.
                ...$pages->editorInit($admin->ctx(), $page, $signer),
                'site' => ['name' => $admin->siteName],
                'multiline' => $registry->multilineFields(),
            ],
        ]);
    }
}
