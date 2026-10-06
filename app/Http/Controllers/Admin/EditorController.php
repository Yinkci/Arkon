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
        $ctx = $admin->ctx();
        $state = $pages->editorState($ctx, $page);
        // The sandboxed canvas cannot send the session cookie, so it gets signed preview URLs.
        $state['media'] = array_map(fn ($m) => [...$m, 'url' => $signer->signUrl($m['url'])], $state['media']);

        return Inertia::render('Admin/Editor', [
            'init' => [
                ...$state,
                'revisions' => $pages->listRevisions($ctx, $page),
                'site' => ['name' => $admin->siteName],
                // First paint of the canvas; later renders come from the canvas endpoint.
                'canvas' => $pages->renderCanvas($ctx, $page, $state['draft']['document'], $signer),
                'multiline' => $registry->multilineFields(),
            ],
        ]);
    }
}
