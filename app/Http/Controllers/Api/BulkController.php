<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormManagement;
use App\Arkon\Media\MediaLibrary;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Support\Bulk;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trash, Restore and Delete permanently for one or many list items, with the same body everywhere:
 * { action, items: [{ id, version? }] } → { done: [ids], failed: [{ id, message }] }. Each item goes
 * through the same service method as a single action (see Support\Bulk).
 */
final class BulkController extends Controller
{
    private static function action(Request $r, array $allowed): string
    {
        $action = $r->input('action');

        return in_array($action, $allowed, true) ? $action : throw new ValidationException('Choose a valid action.');
    }

    public function pages(Request $r, PageManagement $pages): JsonResponse
    {
        $ctx = AdminContext::of($r)->ctx();
        $action = self::action($r, ['trash', 'restore', 'purge']);

        return EditorApiController::ok(Bulk::run($r->input('items'), fn (string $id, ?int $version) => match ($action) {
            'trash' => $pages->delete($ctx, ['pageId' => $id, 'expectedVersion' => $version]),
            'restore' => $pages->restore($ctx, $id),
            'purge' => $pages->purge($ctx, $id),
        }));
    }

    public function forms(Request $r, FormManagement $forms): JsonResponse
    {
        $ctx = AdminContext::of($r)->ctx();
        $action = self::action($r, ['trash', 'restore', 'purge']);

        return EditorApiController::ok(Bulk::run($r->input('items'), fn (string $id, ?int $version) => match ($action) {
            'trash' => $forms->archive($ctx, $id, $version ?? throw new ValidationException('A version is required.')),
            'restore' => $forms->restore($ctx, $id),
            'purge' => $forms->purge($ctx, $id),
        }));
    }

    public function media(Request $r, MediaLibrary $media): JsonResponse
    {
        $ctx = AdminContext::of($r)->ctx();
        $action = self::action($r, ['trash', 'restore']);

        return EditorApiController::ok(Bulk::run($r->input('items'), fn (string $id, ?int $version) => match ($action) {
            'trash' => $media->archive($ctx, $id, $version ?? throw new ValidationException('A version is required.')),
            'restore' => $media->restore($ctx, $id),
        }));
    }

    /** What a confirmation needs to say before a bulk action: images in use, entries that go with forms. */
    public function mediaUsage(Request $r, MediaLibrary $media): JsonResponse
    {
        return EditorApiController::ok($media->usageSummary(AdminContext::of($r)->ctx(), (array) $r->input('ids', [])));
    }

    public function formEntries(Request $r, FormManagement $forms): JsonResponse
    {
        return EditorApiController::ok($forms->entryCounts(AdminContext::of($r)->ctx(), (array) $r->input('ids', [])));
    }
}
