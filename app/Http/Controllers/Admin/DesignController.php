<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Design\ComponentService;
use App\Arkon\Design\TokenService;
use App\Arkon\Media\MediaSigner;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The site's design: tokens and reusable components (drafts, publishing, refresh status). */
class DesignController extends Controller
{
    public function show(Request $request, TokenService $tokens, ComponentService $components): Response
    {
        $admin = AdminContext::of($request);
        $ctx = $admin->ctx();

        return Inertia::render('Admin/Design', [
            'section' => $request->is('admin/performance') ? 'performance' : ($request->is('admin/design/components') ? 'components' : 'styles'),
            'tokens' => $tokens->state($ctx),
            'components' => $components->list($ctx),
            'permissions' => ['edit' => $admin->can('page.edit'), 'publish' => $admin->can('page.publish')],
        ]);
    }

    public function component(Request $request, string $component, ComponentService $components, MediaSigner $signer): Response
    {
        return Inertia::render('Admin/ComponentEditor', ['init' => $components->editorInit(AdminContext::of($request)->ctx(), $component, $signer)]);
    }
}
