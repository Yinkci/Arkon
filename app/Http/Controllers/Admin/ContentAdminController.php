<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Api\ApiTokens;
use App\Arkon\Api\Scopes;
use App\Arkon\Content\ContentDetails;
use App\Arkon\Content\Taxonomies;
use App\Arkon\Content\TermService;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Http\AdminContext;
use App\Http\Controllers\Api\EditorApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin screens and JSON endpoints for posts' details, categories and tags, and the member's own
 * API tokens. HTTP only: TermService, ContentDetails and ApiTokens hold the rules (the public API
 * and AI actions use the same services).
 */
final class ContentAdminController extends Controller
{
    public function terms(Request $request, TermService $terms, Authorizer $auth): Response
    {
        $taxonomy = (string) $request->route('taxonomy');
        $ctx = AdminContext::of($request)->ctx();
        $auth->authorize($ctx, 'page.view');
        Taxonomies::get($taxonomy);

        return Inertia::render('Admin/Terms', [
            'taxonomy' => $taxonomy,
            'taxonomies' => array_map(fn ($t, $name) => ['name' => $name, 'label' => $t['label'], 'plural' => $t['plural'], 'hierarchical' => $t['hierarchical']], Taxonomies::all(), array_keys(Taxonomies::all())),
            'terms' => $terms->browse($ctx->siteId, $taxonomy, ['perPage' => 1000])['items'],
        ]);
    }

    public function createTerm(Request $request, TermService $terms): JsonResponse
    {
        return EditorApiController::ok($terms->create(AdminContext::of($request)->ctx(), (string) $request->route('taxonomy'), EditorApiController::body($request)));
    }

    public function updateTerm(Request $request, TermService $terms): JsonResponse
    {
        return EditorApiController::ok($terms->update(AdminContext::of($request)->ctx(), (string) $request->route('taxonomy'), (string) $request->route('term'), EditorApiController::body($request)));
    }

    public function deleteTerm(Request $request, TermService $terms): JsonResponse
    {
        $terms->delete(AdminContext::of($request)->ctx(), (string) $request->route('taxonomy'), (string) $request->route('term'));

        return EditorApiController::ok(['deleted' => true]);
    }

    /** The editor's post details: excerpt, featured image and terms of the draft. */
    public function details(Request $request, ContentDetails $details): JsonResponse
    {
        return EditorApiController::ok($details->update(AdminContext::of($request)->ctx(), (string) $request->route('page'), EditorApiController::body($request)));
    }

    public function developer(Request $request, ApiTokens $tokens): Response
    {
        $ctx = AdminContext::of($request)->ctx();

        return Inertia::render('Admin/Developer', [
            'tokens' => $tokens->list($ctx),
            'scopes' => Scopes::all(),
            // The API answers at the site's own address (like its pages), not necessarily the admin's.
            'baseUrl' => (app(PageStore::class)->origin($ctx->siteId) ?? $request->getSchemeAndHttpHost()).'/api/v1',
        ]);
    }

    public function createToken(Request $request, ApiTokens $tokens): JsonResponse
    {
        $body = EditorApiController::body($request);

        return EditorApiController::ok($tokens->create(AdminContext::of($request)->ctx(), $body['name'] ?? null, $body['scopes'] ?? null, $body['expiresAt'] ?? null));
    }

    public function revokeToken(Request $request, ApiTokens $tokens): JsonResponse
    {
        $tokens->revoke(AdminContext::of($request)->ctx(), (string) $request->route('token'));

        return EditorApiController::ok(['revoked' => true]);
    }
}
