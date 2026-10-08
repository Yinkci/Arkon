<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Design\ComponentService;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Design\TokenService;
use App\Arkon\Media\MediaSigner;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Design tokens, reusable components and the refresh queue. Thin adapters: the
 * services authorize and validate everything; bodies keep `{}` distinct from `[]`.
 */
class DesignApiController extends Controller
{
    public function __construct(private readonly TokenService $tokens, private readonly ComponentService $components, private readonly PageRefreshes $refreshes) {}

    public function tokens(Request $request): JsonResponse
    {
        return EditorApiController::ok($this->tokens->state(AdminContext::of($request)->ctx()));
    }

    public function saveTokens(Request $request): JsonResponse
    {
        return EditorApiController::ok($this->tokens->save(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function publishTokens(Request $request): JsonResponse
    {
        return EditorApiController::ok($this->tokens->publish(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function refreshes(Request $request): JsonResponse
    {
        return EditorApiController::ok($this->refreshes->status(AdminContext::of($request)->ctx()));
    }

    public function retryRefreshes(Request $request): JsonResponse
    {
        $ctx = AdminContext::of($request)->ctx();
        $counts = $this->refreshes->retry($ctx);

        return EditorApiController::ok([...$this->refreshes->status($ctx), 'ran' => $counts]);
    }

    public function components(Request $request): JsonResponse
    {
        return EditorApiController::ok(['components' => $this->components->list(AdminContext::of($request)->ctx())]);
    }

    public function createComponent(Request $request): JsonResponse
    {
        return EditorApiController::ok($this->components->create(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function saveComponent(Request $request, string $component): JsonResponse
    {
        $result = $this->components->save(AdminContext::of($request)->ctx(), $component, EditorApiController::body($request));

        return EditorApiController::ok(['version' => $result['version'], 'replayed' => $result['replayed']]);
    }

    public function publishComponent(Request $request, string $component): JsonResponse
    {
        return EditorApiController::ok($this->components->publish(AdminContext::of($request)->ctx(), $component, EditorApiController::body($request)));
    }

    public function componentCanvas(Request $request, string $component, MediaSigner $signer): JsonResponse
    {
        $body = EditorApiController::body($request);

        return EditorApiController::ok($this->components->renderCanvas(AdminContext::of($request)->ctx(), $component, $body['document'] ?? null, $signer));
    }
}
