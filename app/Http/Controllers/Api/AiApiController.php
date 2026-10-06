<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Ai\ProposalService;
use App\Arkon\Media\MediaSigner;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The editor's AI panel. Asking only records a request (answered at once); the local helper
 * runs Claude Code and the panel polls the request's status. Applying is a normal save.
 */
class AiApiController extends Controller
{
    public function __construct(private readonly ProposalService $proposals) {}

    public function index(Request $request, string $page): JsonResponse
    {
        return EditorApiController::ok($this->proposals->list(AdminContext::of($request)->ctx(), $page));
    }

    public function store(Request $request, string $page): JsonResponse
    {
        return EditorApiController::ok($this->proposals->request(AdminContext::of($request)->ctx(), $page, EditorApiController::body($request)));
    }

    public function show(Request $request, string $page, string $proposal, MediaSigner $signer): JsonResponse
    {
        return EditorApiController::ok($this->proposals->status(AdminContext::of($request)->ctx(), $page, $proposal, $signer));
    }

    public function cancel(Request $request, string $page, string $proposal): JsonResponse
    {
        return EditorApiController::ok($this->proposals->cancel(AdminContext::of($request)->ctx(), $page, $proposal));
    }

    public function discard(Request $request, string $page, string $proposal): JsonResponse
    {
        $this->proposals->discard(AdminContext::of($request)->ctx(), $page, $proposal);

        return EditorApiController::ok(['discarded' => true]);
    }
}
