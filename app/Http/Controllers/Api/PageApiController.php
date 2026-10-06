<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Pages\PageManagement;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Page list actions: create, unpublish, delete. */
class PageApiController extends Controller
{
    public function __construct(private readonly PageManagement $management) {}

    public function store(Request $request): JsonResponse
    {
        return EditorApiController::ok($this->management->create(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function unpublish(Request $request, string $page): JsonResponse
    {
        return EditorApiController::ok($this->management->unpublish(AdminContext::of($request)->ctx(), [...EditorApiController::body($request), 'pageId' => $page]));
    }

    public function destroy(Request $request, string $page): JsonResponse
    {
        return EditorApiController::ok($this->management->delete(AdminContext::of($request)->ctx(), [...EditorApiController::body($request), 'pageId' => $page]));
    }
}
