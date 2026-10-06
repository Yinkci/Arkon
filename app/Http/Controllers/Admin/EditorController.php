<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\ProposalService;
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
    public function show(Request $request, string $page, PageService $pages, MediaSigner $signer, ComponentRegistry $registry, ProposalService $proposals): Response
    {
        $admin = AdminContext::of($request);
        // Page, draft, live state, history and the first canvas paint, all from one snapshot.
        $init = $pages->editorInit($admin->ctx(), $page, $signer);

        return Inertia::render('Admin/Editor', [
            'init' => [
                ...$init,
                'site' => ['name' => $admin->siteName],
                'multiline' => $registry->multilineFields(),
                // Whether the AI panel can be used here; never any provider credentials.
                'ai' => $proposals->editorInfo($admin->ctx(), $init['permissions']['edit']),
            ],
        ]);
    }
}
