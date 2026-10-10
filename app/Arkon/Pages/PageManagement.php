<?php

namespace App\Arkon\Pages;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Components\Factories;
use App\Arkon\Content\ContentTypes;
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
use App\Arkon\Support\Time;
use App\Arkon\Support\Uuid;
use App\Arkon\Themes\ThemeService;
use Closure;
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
    public function create(SiteContext $ctx, array $input, ?Closure $then = null): array
    {
        $title = Input::title($input['title'] ?? null);
        $path = Input::path($input['path'] ?? null);
        $kind = $input['kind'] ?? 'page';
        ContentTypes::get($kind);
        $requestKey = Input::validate($input, ['requestKey' => Input::requestKeyRule()])['requestKey'];
        // `intent`: everything else a caller creates with the page (content, details), so a retry
        // with the same key but other content is a conflict rather than a silent replay.
        $fingerprint = self::createFingerprint($title, $path, $kind, $input['intent'] ?? null);
        $given = $input['document'] ?? null;

        try {
            return $this->transactions->run(function () use ($ctx, $title, $path, $kind, $requestKey, $fingerprint, $then, $given) {
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
                // A caller may create the page with its content (API, AI): validated like any save.
                $document = $given ?? self::starterDocument($kind, $title);
                if ($given !== null) {
                    $this->store->validateForSave($ctx->siteId, $document);
                    ThemeService::assertAdditions($ctx->siteId, null, $document);
                }
                DB::table('pages')->insert([
                    'id' => $pageId, 'site_id' => $ctx->siteId, 'kind' => $kind, 'path' => $path, 'title' => $title,
                    'request_key' => $requestKey, 'request_fingerprint' => $fingerprint, 'created_by' => $ctx->userId,
                ]);
                $revision = $this->store->insertRevision($ctx, $pageId, $document, $title, $path, $kind === 'page' ? 'Created page' : 'Created '.strtolower(ContentTypes::get($kind)['label']));
                DB::table('page_drafts')->insert([
                    'page_id' => $pageId, 'site_id' => $ctx->siteId, 'document' => Json::encode($document), 'version' => 1,
                    'checkpoint_revision_id' => $revision['id'], 'checkpoint_version' => 1, 'updated_by' => $ctx->userId,
                ]);
                $this->audit->forContext($ctx, 'page.create', 'page', $pageId, ['title' => $title, 'path' => $path, ...($kind === 'page' ? [] : ['kind' => $kind])]);
                // Runs in the same transaction: a failure creates nothing, and a replay never runs it again.
                if ($then !== null) {
                    $then($pageId);
                }

                return ['pageId' => $pageId, 'replayed' => false];
            });
        } catch (Throwable $error) {
            throw PageStore::asPathConflict($error, $path);
        }
    }

    /**
     * Stores a whole document (plus title and URL) as a page's next draft, creating the page when
     * $baseVersion is 0. The caller runs inside a transaction, holds the page's write lock
     * (lockForWrite) when it exists, holds the path-claim lock and has checked the version. Used
     * where a validated document replaces the draft in one step (the AI website application), with
     * the same rules as the editor: page.create or page.edit, a free URL, draft validation, theme
     * availability, a revision (source "ai" for AI contexts) and an audit entry.
     *
     * @return int the new draft version
     */
    public function writeDocumentLocked(SiteContext $ctx, string $pageId, string $title, string $path, mixed $document, int $baseVersion, string $message, ?array $previous = null): int
    {
        $create = $baseVersion === 0;
        $this->authorizer->authorize($ctx, $create ? 'page.create' : 'page.edit');
        $this->store->assertPathAvailable($ctx->siteId, $path, $create ? null : $pageId);
        $this->store->validateForSave($ctx->siteId, $document);
        ThemeService::assertAdditions($ctx->siteId, $previous, $document);
        if ($create) {
            DB::table('pages')->insert(['id' => $pageId, 'site_id' => $ctx->siteId, 'title' => $title, 'path' => $path, 'created_by' => $ctx->userId]);
        } else {
            DB::table('pages')->where('id', $pageId)->update(['title' => $title, 'path' => $path, 'updated_at' => DB::raw('now()')]);
        }
        $revision = $this->store->insertRevision($ctx, $pageId, $document, $title, $path, $message);
        $version = $baseVersion + 1;
        $values = ['document' => Json::encode($document), 'version' => $version, 'checkpoint_revision_id' => $revision['id'], 'checkpoint_version' => $version, 'last_save_key' => null, 'last_save_fingerprint' => null, 'updated_by' => $ctx->userId, 'updated_at' => DB::raw('now()')];
        if ($create) {
            DB::table('page_drafts')->insert(['page_id' => $pageId, 'site_id' => $ctx->siteId, ...$values]);
        } else {
            DB::table('page_drafts')->where('page_id', $pageId)->update($values);
        }
        $this->audit->forContext($ctx, $create ? 'page.create' : 'page.draft.replace', 'page', $pageId, ['title' => $title, 'path' => $path, 'version' => $version]);

        return $version;
    }

    /**
     * Saves a whole new document as the next draft version (the editor saves operations; API and
     * AI content writes replace the content in one step). Version-checked against the draft the
     * caller saw, idempotent per save key like an editor save, validated like any save, and
     * recorded as a revision. Nothing changes on the live site until the page is published.
     *
     * @param  array{pageId: mixed, expectedVersion: mixed, document: mixed, saveKey: mixed, message?: string}  $input
     * @return array{version: int, replayed: bool}
     */
    public function replaceDocument(SiteContext $ctx, array $input): array
    {
        $pageId = Input::id($input['pageId'] ?? null);
        $valid = Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1'], 'saveKey' => Input::requestKeyRule()]);
        $expected = (int) $valid['expectedVersion'];
        $document = $input['document'] ?? null;
        $fingerprint = Fingerprint::of(['kind' => 'replace', 'pageId' => $pageId, 'baseVersion' => $expected, 'document' => $document]);

        return $this->transactions->run(function () use ($ctx, $pageId, $expected, $document, $valid, $fingerprint, $input) {
            $this->authorizer->authorize($ctx, 'page.edit');
            [$page, $draft] = $this->store->lockForWrite($ctx->siteId, $pageId);
            if ($draft->last_save_key === $valid['saveKey']) {
                if ($draft->last_save_fingerprint !== $fingerprint) {
                    throw new ConflictException('This save key was already used for a different save');
                }

                return ['version' => (int) $draft->version, 'replayed' => true];
            }
            $this->store->assertVersion($draft, $expected);
            $version = $this->writeDocumentLocked($ctx, $pageId, $page->title, $page->path, $document, $expected, (string) ($input['message'] ?? 'Replaced content'), $this->store->document($draft->document));
            DB::table('page_drafts')->where('page_id', $pageId)->update(['last_save_key' => $valid['saveKey'], 'last_save_fingerprint' => $fingerprint]);

            return ['version' => $version, 'replayed' => false];
        });
    }

    /** What a create request asked for (normalised inputs). Stored once; never follows later renames. */
    public static function createFingerprint(string $title, string $path, string $kind = 'page', mixed $intent = null): string
    {
        // Plain page creates keep the fingerprint they always had, so older keyed creates still replay.
        return Fingerprint::of(['kind' => 'create', 'title' => $title, 'path' => $path, ...($kind === 'page' ? [] : ['type' => $kind]), ...($intent === null ? [] : ['intent' => $intent])]);
    }

    /** What a new item starts with: a hero for pages, the title as the main heading for other kinds. */
    public static function starterDocument(string $kind, string $title): array
    {
        return $kind === 'page'
            ? Factories::pageDocument([Factories::heroNode(['heading' => $title])])
            : Factories::pageDocument([Factories::node('text', ['text' => $title, 'element' => 'h1'])]);
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
     * Moves a page to the Trash: it leaves the page list, goes offline if it was
     * live, and frees its URL (restore() brings it back). Revisions, publications and audit history are
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

    /**
     * Pages in the Trash, most recently trashed first.
     *
     * @return list<array{id: string, title: string, path: string, version: int, deletedAt: string|null}>
     */
    public function trash(SiteContext $ctx, string $kind = 'page'): array
    {
        $this->authorizer->authorize($ctx, 'page.view');

        return DB::table('pages as p')->join('page_drafts as d', 'd.page_id', '=', 'p.id')
            ->where('p.site_id', $ctx->siteId)->where('p.kind', $kind)->whereNotNull('p.deleted_at')->whereNull('p.purged_at')
            ->orderByDesc('p.deleted_at')->orderBy('p.id')->get(['p.id', 'p.title', 'p.path', 'p.deleted_at', 'd.version'])
            ->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'path' => $r->path, 'version' => (int) $r->version, 'deletedAt' => Time::iso($r->deleted_at)])->all();
    }

    /**
     * Brings a page back from the Trash with the same id, URL, draft, SEO and history. It returns
     * unpublished (moving it to the Trash took it offline). Refused while another page uses its URL.
     *
     * @return array{wasRestored: bool}
     */
    public function restore(SiteContext $ctx, string $pageId): array
    {
        $pageId = Input::id($pageId);

        return $this->transactions->run(function () use ($ctx, $pageId) {
            $this->authorizer->authorize($ctx, 'page.delete');
            $this->store->lockDraft($ctx->siteId, $pageId);
            $row = DB::table('pages')->where('site_id', $ctx->siteId)->where('id', $pageId)->whereNull('purged_at')->first() ?? throw new NotFoundException('Page');
            if ($row->deleted_at === null) {
                return ['wasRestored' => false];
            }
            $taken = DB::table('pages')->where('site_id', $ctx->siteId)->where('path', $row->path)->whereNull('deleted_at')->value('title');
            if ($taken !== null) {
                throw new ConflictException("“{$taken}” now uses {$row->path}. Change that page’s URL, then restore “{$row->title}”.");
            }
            DB::table('pages')->where('id', $pageId)->update(['deleted_at' => null, 'deleted_by' => null, 'updated_at' => now()]);
            $this->audit->forContext($ctx, 'page.restore', 'page', $pageId, ['title' => $row->title, 'path' => $row->path]);

            return ['wasRestored' => true];
        });
    }

    /**
     * Deletes a page in the Trash permanently: it leaves the Trash and cannot be restored. Its
     * revisions, publications and audit history are append-only and stay for the record.
     *
     * @return array{wasPurged: bool}
     */
    public function purge(SiteContext $ctx, string $pageId): array
    {
        $pageId = Input::id($pageId);

        return $this->transactions->run(function () use ($ctx, $pageId) {
            $this->authorizer->authorize($ctx, 'page.delete');
            $this->store->lockDraft($ctx->siteId, $pageId);
            $row = DB::table('pages')->where('site_id', $ctx->siteId)->where('id', $pageId)->first() ?? throw new NotFoundException('Page');
            if ($row->purged_at !== null) {
                return ['wasPurged' => false];
            }
            if ($row->deleted_at === null) {
                throw new ConflictException("Move “{$row->title}” to the Trash before deleting it permanently.");
            }
            DB::table('pages')->where('id', $pageId)->update(['purged_at' => now()]);
            $this->audit->forContext($ctx, 'page.purge', 'page', $pageId, ['title' => $row->title, 'path' => $row->path]);

            return ['wasPurged' => true];
        });
    }
}
