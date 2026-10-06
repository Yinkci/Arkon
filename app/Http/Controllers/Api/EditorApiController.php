<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Support\Json;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Editor endpoints. Session-authenticated and CSRF-protected (web group). Each
 * one is a thin adapter: the services authorize and validate everything again.
 * Bodies are decoded with JSON object fidelity (see Support\Json), because
 * documents and operations must keep `{}` distinct from `[]`.
 */
class EditorApiController extends Controller
{
    public function __construct(private readonly PageService $pages, private readonly PageManagement $management) {}

    public function save(Request $request, string $page): JsonResponse
    {
        $body = self::body($request);
        $result = $this->pages->saveDraft(AdminContext::of($request)->ctx(), [...$body, 'pageId' => $page]);

        return self::ok(['version' => $result['version'], 'revision' => $result['revision'], 'replayed' => $result['replayed']]);
    }

    public function publish(Request $request, string $page): JsonResponse
    {
        $ctx = AdminContext::of($request)->ctx();
        $result = $this->pages->publish($ctx, [...self::body($request), 'pageId' => $page]);
        $state = $this->pages->editorState($ctx, $page);

        return self::ok(['replayed' => $result['replayed'], 'live' => $state['live'], 'status' => $state['status'], 'version' => $state['draft']['version']]);
    }

    public function restore(Request $request, string $page): JsonResponse
    {
        $result = $this->pages->restoreRevision(AdminContext::of($request)->ctx(), [...self::body($request), 'pageId' => $page]);

        return self::ok(['version' => $result['version'], 'document' => $result['document']]);
    }

    public function settings(Request $request, string $page): JsonResponse
    {
        return self::ok($this->management->updateSettings(AdminContext::of($request)->ctx(), [...self::body($request), 'pageId' => $page]));
    }

    public function status(Request $request, string $page): JsonResponse
    {
        $ctx = AdminContext::of($request)->ctx();
        $state = $this->pages->editorState($ctx, $page);

        return self::ok(['live' => $state['live'], 'status' => $state['status'], 'revisions' => $this->pages->listRevisions($ctx, $page)]);
    }

    /** Editor-mode HTML of the editor's local (unsaved) document, from the same renderer as production. */
    public function canvas(Request $request, string $page, MediaSigner $signer): JsonResponse
    {
        $body = self::body($request);

        return self::ok($this->pages->renderCanvas(AdminContext::of($request)->ctx(), $page, $body['document'] ?? null, $signer));
    }

    /** @return array<string, mixed> */
    public static function body(Request $request): array
    {
        try {
            $body = Json::decode((string) $request->getContent());
        } catch (JsonException) {
            throw new ValidationException('The request body is not valid JSON');
        }
        if (! Json::isObject($body)) {
            throw new ValidationException('The request body must be a JSON object');
        }

        return Json::entries($body);
    }

    public static function ok(mixed $data): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
