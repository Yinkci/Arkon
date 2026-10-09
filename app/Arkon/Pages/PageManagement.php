<?php

namespace App\Arkon\Pages;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Components\Factories;
use App\Arkon\Database\Transactions;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Create, draft title/URL, unpublish and delete. Port of
 * packages/core/src/page-management.ts.
 */
class PageManagement
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Transactions $transactions,
        private readonly PageStore $store,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Creates an unpublished page with a starter hero. Nothing becomes live. A
     * retried request (same key, same title and path) returns the page it
     * already created.
     *
     * @return array{pageId: string, replayed: bool}
     */
    public function create(SiteContext $ctx, array $input): array
    {
        $title = Input::title($input['title'] ?? null);
        $path = Input::path($input['path'] ?? null);
        $requestKey = Input::validate($input, ['requestKey' => Input::requestKeyRule()])['requestKey'];
        $fingerprint = self::createFingerprint($title, $path);

        try {
            return $this->transactions->run(function () use ($ctx, $title, $path, $requestKey, $fingerprint) {
                $this->authorizer->authorize($ctx, 'page.create');
                if ($replay = $this->replayCreate($ctx, $requestKey, $fingerprint)) {
                    return $replay;
                }

                $this->store->lockPathClaims($ctx->siteId);
                // An identical request may have committed while this one waited for the lock: it is a
                // retry of the same intent, not a URL conflict.
                if ($replay = $this->replayCreate($ctx, $requestKey, $fingerprint)) {
                    return $replay;
                }
                $this->store->assertPathAvailable($ctx->siteId, $path);

                $pageId = Uuid::v7();
                $document = Factories::pageDocument([Factories::heroNode(['heading' => $title])]);
                DB::table('pages')->insert([
                    'id' => $pageId, 'site_id' => $ctx->siteId, 'path' => $path, 'title' => $title,
                    'request_key' => $requestKey, 'request_fingerprint' => $fingerprint, 'created_by' => $ctx->userId,
                ]);
                $revision = $this->store->insertRevision($ctx, $pageId, $document, $title, $path, 'Created page');
                DB::table('page_drafts')->insert([
                    'page_id' => $pageId, 'site_id' => $ctx->siteId, 'document' => Json::encode($document), 'version' => 1,
                    'checkpoint_revision_id' => $revision['id'], 'checkpoint_version' => 1, 'updated_by' => $ctx->userId,
                ]);
                $this->audit->forContext($ctx, 'page.create', 'page', $pageId, ['title' => $title, 'path' => $path]);

                return ['pageId' => $pageId, 'replayed' => false];
            });
        } catch (Throwable $error) {
            throw PageStore::asPathConflict($error, $path);
        }
    }

    /** What a create request asked for (normalised inputs). Stored once; never follows later renames. */
    public static function createFingerprint(string $title, string $path): string
    {
        return Fingerprint::of(['kind' => 'create', 'title' => $title, 'path' => $path]);
    }

    /**
     * The result of an earlier create with this key, or null if there is none.
     *
     * - Same key, same inputs: a retry; returns that page (`replayed`), even if it
     *   was renamed since, because the intent is about the original inputs.
     * - Same key, different inputs (or no recorded inputs): rejected.
     * - The page was deleted since: rejected explicitly; the key never creates a
     *   second page.
     */
    private function replayCreate(SiteContext $ctx, string $requestKey, string $fingerprint): ?array
    {
        $existing = DB::table('pages')->where('site_id', $ctx->siteId)->where('request_key', $requestKey)->first();
        if ($existing === null) {
            return null;
        }
        if ($existing->request_fingerprint === null || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
            throw new ConflictException('This request key was already used for a different page');
        }
        if ($existing->deleted_at !== null) {
            throw new ConflictException('The page created with this request has since been deleted');
        }

        return ['pageId' => $existing->id, 'replayed' => true];
    }

    /**
     * Changes the draft title and URL. Like any draft change it is
     * version-checked, recorded as a revision and invisible on the live site
     * until the page is published. Publishing a new URL makes the old one
     * redirect to the page.
     *
     * @return array{version: int, title: string, path: string, replayed: bool}
     */
    public function updateSettings(SiteContext $ctx, array $input): array
    {
        $pageId = Input::id($input['pageId'] ?? null);
        $title = Input::title($input['title'] ?? null);
        $path = Input::path($input['path'] ?? null);
        $valid = Input::validate($input, [
            'expectedVersion' => ['required', 'integer', 'min:1'],
            'saveKey' => Input::requestKeyRule(),
        ]);
        $expectedVersion = (int) $valid['expectedVersion'];
        $saveKey = $valid['saveKey'];
        $fingerprint = Fingerprint::of(['kind' => 'settings', 'pageId' => $pageId, 'baseVersion' => $expectedVersion, 'title' => $title, 'path' => $path]);

        try {
            return $this->transactions->run(function () use ($ctx, $pageId, $title, $path, $expectedVersion, $saveKey, $fingerprint) {
                $this->authorizer->authorize($ctx, 'page.edit');
                [$page, $draft] = $this->store->lockForWrite($ctx->siteId, $pageId);
                if ($draft->last_save_key === $saveKey) {
                    if ($draft->last_save_fingerprint !== $fingerprint) {
                        throw new ConflictException('This save key was already used for a different change');
                    }

                    return ['version' => (int) $draft->version, 'title' => $page->title, 'path' => $page->path, 'replayed' => true];
                }
                $this->store->assertVersion($draft, $expectedVersion);
                if ($page->title === $title && $page->path === $path) {
                    return ['version' => (int) $draft->version, 'title' => $page->title, 'path' => $page->path, 'replayed' => false];
                }
                if ($page->path !== $path) {
                    $this->store->lockPathClaims($ctx->siteId);
                    $this->store->assertPathAvailable($ctx->siteId, $path, $pageId);
                }

                $version = (int) $draft->version + 1;
                $changes = array_filter([
                    $page->title !== $title ? "title “{$title}”" : null,
                    $page->path !== $path ? "URL {$path}" : null,
                ]);
                DB::table('pages')->where('id', $pageId)->update(['title' => $title, 'path' => $path, 'updated_at' => now()]);
                $revision = $this->store->insertRevision($ctx, $pageId, $this->store->document($draft->document), $title, $path, 'Changed '.implode(' and ', $changes));
                DB::table('page_drafts')->where('page_id', $pageId)->update([
                    'version' => $version,
                    'checkpoint_revision_id' => $revision['id'],
                    'checkpoint_version' => $version,
                    'last_save_key' => $saveKey,
                    'last_save_fingerprint' => $fingerprint,
                    'updated_by' => $ctx->userId,
                    'updated_at' => now(),
                ]);
                $this->audit->forContext($ctx, 'page.settings.update', 'page', $pageId, [
                    'from' => ['title' => $page->title, 'path' => $page->path], 'to' => ['title' => $title, 'path' => $path], 'version' => $version,
                ]);

                return ['version' => $version, 'title' => $title, 'path' => $path, 'replayed' => false];
            });
        } catch (Throwable $error) {
            throw PageStore::asPathConflict($error, $path);
        }
    }

    /**
     * Takes a page offline: its URL and any redirects to it return 404. The
     * draft, revisions and publications are kept; publishing again brings it
     * back. Repeating the request after it succeeded is a no-op.
     *
     * @return array{wasLive: bool}
     */
    public function unpublish(SiteContext $ctx, array $input): array
    {
        $pageId = Input::id($input['pageId'] ?? null);
        // The live publication the user saw. If something newer went live, the request is stale.
        $expected = Input::id($input['expectedPublicationId'] ?? null, 'Publication');

        $result = $this->transactions->run(function () use ($ctx, $pageId, $expected) {
            $this->authorizer->authorize($ctx, 'page.publish');
            // Serialises with publishes of this page (they take the same lock first, too).
            $this->store->lockForWrite($ctx->siteId, $pageId);
            $live = $this->store->loadLive($ctx->siteId, $pageId);
            if ($live === null) {
                return ['wasLive' => false];
            }
            if ($live->publication_id !== $expected) {
                throw new ConflictException('A newer version of this page went live since you loaded it. Reload and try again.');
            }
            $epoch = $this->store->lockNextEpoch($ctx->siteId);
            DB::table('live_pages')->where('site_id', $ctx->siteId)->where('page_id', $pageId)->delete();
            MenuService::targetChanged($ctx->siteId, $pageId, $epoch);
            $this->audit->forContext($ctx, 'page.unpublish', 'page', $pageId, [
                'publicationId' => $live->publication_id, 'path' => $live->path, 'epoch' => $epoch,
            ]);

            return ['wasLive' => true];
        });
        if (DB::transactionLevel() === 0 && DB::table('page_refreshes')->where('site_id', $ctx->siteId)->where('status', 'pending')->exists()) {
            app(PageRefreshes::class)->run($ctx->siteId);
        }

        return $result;
    }

    /**
     * Soft-deletes a page: it disappears from the admin, goes offline if it was
     * live, and frees its URL. Revisions, publications and audit history are
     * kept. Repeating the request after it succeeded is a no-op.
     *
     * @return array{wasDeleted: bool, wasLive: bool}
     */
    public function delete(SiteContext $ctx, array $input): array
    {
        $pageId = Input::id($input['pageId'] ?? null);
        // The draft version the user confirmed deleting.
        $expectedVersion = (int) Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1']])['expectedVersion'];

        $result = $this->transactions->run(function () use ($ctx, $pageId, $expectedVersion) {
            $this->authorizer->authorize($ctx, 'page.delete');
            $draft = $this->store->lockDraft($ctx->siteId, $pageId);
            // Read after the lock, so a second delete that waited behind the first is a no-op.
            $row = DB::table('pages')->where('site_id', $ctx->siteId)->where('id', $pageId)->first() ?? throw new NotFoundException('Page');
            if ($row->deleted_at !== null) {
                return ['wasDeleted' => false, 'wasLive' => false];
            }
            $this->store->assertVersion($draft, $expectedVersion);

            $live = $this->store->loadLive($ctx->siteId, $pageId);
            $epoch = null;
            if ($live !== null) {
                $epoch = $this->store->lockNextEpoch($ctx->siteId);
                DB::table('live_pages')->where('site_id', $ctx->siteId)->where('page_id', $pageId)->delete();
            }
            DB::table('pages')->where('id', $pageId)->update(['deleted_at' => now(), 'deleted_by' => $ctx->userId, 'updated_at' => now()]);
            if ($epoch !== null) {
                MenuService::targetChanged($ctx->siteId, $pageId, $epoch);
            }
            $this->audit->forContext($ctx, 'page.delete', 'page', $pageId, [
                'title' => $row->title, 'path' => $row->path, 'wasLive' => $live !== null, 'livePath' => $live?->path, 'epoch' => $epoch,
            ]);

            return ['wasDeleted' => true, 'wasLive' => $live !== null];
        });
        if (DB::transactionLevel() === 0 && DB::table('page_refreshes')->where('site_id', $ctx->siteId)->where('status', 'pending')->exists()) {
            app(PageRefreshes::class)->run($ctx->siteId);
        }

        return $result;
    }
}
