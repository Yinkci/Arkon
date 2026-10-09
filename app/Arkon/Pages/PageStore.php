<?php

namespace App\Arkon\Pages;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Design\DesignResources;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormService;
use App\Arkon\Media\MediaService;
use App\Arkon\Navigation\MenuService;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Building blocks shared by the page services (PageService, PageManagement).
 * Callers go through those services, which authorize first.
 *
 * Lock order is always: draft row → path-claim lock, and draft row → site row
 * (epoch). The path lock is never taken while holding the epoch lock.
 */
class PageStore
{
    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly DocumentValidator $validator,
        private readonly PageRenderer $renderer,
        private readonly MediaService $media,
        private readonly DesignResources $resources,
    ) {}

    /** A page that is not deleted, scoped to one site. */
    public function loadPage(string $siteId, string $pageId): object
    {
        $row = Uuid::isValid($pageId)
            ? DB::table('pages')->where('site_id', $siteId)->where('id', $pageId)->whereNull('deleted_at')->first()
            : null;

        return $row ?? throw new NotFoundException('Page');
    }

    /** What is live for a page: publication, its revision, and the live title and path. */
    public function loadLive(string $siteId, string $pageId): ?object
    {
        return DB::table('live_pages as l')
            ->join('publications as p', 'p.id', '=', 'l.publication_id')
            ->join('page_revisions as r', 'r.id', '=', 'p.revision_id')
            ->where('l.site_id', $siteId)
            ->where('l.page_id', $pageId)
            ->first([
                'p.id as publication_id', 'p.revision_id', 'r.number as revision_number', 'p.created_at as published_at',
                'l.epoch', 'l.path', 'r.title',
            ]);
    }

    public function loadDraft(string $siteId, string $pageId): object
    {
        return DB::table('page_drafts')->where('site_id', $siteId)->where('page_id', $pageId)->first()
            ?? throw new NotFoundException('Page');
    }

    /** Locks the draft row for the rest of the transaction. */
    public function lockDraft(string $siteId, string $pageId): object
    {
        return DB::table('page_drafts')->where('site_id', $siteId)->where('page_id', $pageId)->lockForUpdate()->first()
            ?? throw new NotFoundException('Page');
    }

    /**
     * The shared write gate for one page. Every change to a page (save, restore,
     * title/URL, publish, unpublish, delete) takes the draft row lock first and
     * only then reads the page row, so it sees what the previous writer committed:
     * a writer that waited behind a delete finds the page deleted, and one that
     * waited behind a rename sees the new title and path. Lock order stays
     * draft → path claims and draft → site epoch.
     *
     * @return array{0: object, 1: object} [page, draft] of a page that is not deleted
     */
    public function lockForWrite(string $siteId, string $pageId): array
    {
        $draft = $this->lockDraft($siteId, $pageId);
        // A new statement after the lock: under READ COMMITTED it sees the latest committed page row.
        $page = $this->loadPage($siteId, $pageId);

        return [$page, $draft];
    }

    public function assertVersion(object $draft, int $expectedVersion): void
    {
        if ((int) $draft->version !== $expectedVersion) {
            throw new StaleVersionException($expectedVersion, (int) $draft->version);
        }
    }

    /** A stored document in raw form, components migrated to their current versions (in memory). */
    public function document(string $json): mixed
    {
        return $this->registry->migrateDocument(Json::decode($json));
    }

    /** Draft-level validation: a well-formed document whose images all belong to this site. */
    public function validateForSave(string $siteId, mixed $doc): void
    {
        FormService::assertReferences($siteId, $doc);
        MenuService::assertReferences($siteId, $doc);
        $issues = $this->validator->validate($doc);
        if ($issues !== []) {
            throw new ValidationException('The page is not valid', $issues);
        }
        $refs = $this->validator->mediaRefs($doc);
        $found = $this->media->mediaMap($siteId, $refs);
        $missing = array_values(array_filter($refs, fn ($id) => ! isset($found[$id])));
        if ($missing !== []) {
            throw new ValidationException('An image on this page does not exist', array_map(fn ($id) => ['message' => "Image {$id} not found"], $missing));
        }
        // Reusable components must be this site's (published or not: an unpublished one only blocks publishing).
        $components = DesignResources::componentIds($doc);
        $known = $components === [] ? [] : DB::table('reusable_components')->where('site_id', $siteId)->whereIn('id', $components)->pluck('id')->all();
        $unknown = array_values(array_diff($components, $known));
        if ($unknown !== []) {
            throw new ValidationException('A reusable component on this page does not exist', array_map(fn ($id) => ['message' => "Reusable component {$id} not found"], $unknown));
        }
    }

    /**
     * Inserts the next immutable revision: content plus the title and path it is served under.
     *
     * @return array{id: string, number: int}
     */
    public function insertRevision(SiteContext $ctx, string $pageId, mixed $doc, string $title, string $path, string $message): array
    {
        $current = (int) DB::table('page_revisions')->where('page_id', $pageId)->max('number');
        $revision = ['id' => Uuid::v7(), 'number' => $current + 1];
        DB::table('page_revisions')->insert([
            ...$revision,
            'site_id' => $ctx->siteId,
            'page_id' => $pageId,
            'document' => Json::encode($doc),
            'title' => $title,
            'path' => $path,
            'schema_version' => $doc['schemaVersion'],
            'source' => $ctx->via === 'ai' ? 'ai' : 'human',
            'author_id' => $ctx->userId,
            'message' => $message,
        ]);

        return $revision;
    }

    /**
     * Production rendering against the site's current (published) state: its published
     * design tokens and the published versions of the reusable components the page uses.
     *
     * @return array{html: string, body: string, css: string, title: string, inputs: array, report: array, mediaIds: list<string>}
     */
    public function renderForSite(string $siteId, string $title, string $path, mixed $doc, bool $strict, bool $pinned = false): array
    {
        $site = DB::table('sites')->where('id', $siteId)->first() ?? throw new NotFoundException('Site');
        $settings = Json::entries(Json::decode($site->settings));
        $resources = $this->resources->published($siteId, $doc);
        $mediaIds = $this->mediaIdsFor($doc, $resources['components'], $pinned);
        $media = $this->media->mediaMap($siteId, $mediaIds);
        try {
            $rendered = $this->renderer->render($doc, 'production', ['title' => $title, 'path' => $path], [
                'name' => $site->name,
                'origin' => $this->origin($siteId),
                'lang' => is_string($settings['lang'] ?? null) ? $settings['lang'] : 'en',
            ], $media, $strict, $pinned, resources: $resources);
            $themeVersion = DB::table('site_theme_sets')->where('site_id', $siteId)->value('published_version');
            $rendered['inputs']['themeSelection'] = $themeVersion === null ? null : (int) $themeVersion;

            return [...$rendered, 'mediaIds' => $mediaIds];
        } catch (RenderException $error) {
            throw new ValidationException($strict ? 'Fix these problems before publishing' : 'The page could not be rendered', $error->issues);
        }
    }

    public function origin(string $siteId): ?string
    {
        return app(PublicPages::class)->origin($siteId);
    }

    /** Media a rendering uses: the document's own, plus that of the reusable components it shows. */
    public function mediaIdsFor(mixed $doc, array $components, bool $pinned = false): array
    {
        return array_values(array_unique([...$this->validator->safeMediaRefs($doc, $pinned), ...$this->resources->componentMediaRefs($components)]));
    }

    /** Records which token version and component versions a publication was rendered with. */
    public function recordDependencies(string $siteId, string $pageId, string $publicationId, array $inputs): void
    {
        $rows = DesignResources::dependencies($siteId, $inputs);
        if ($rows !== []) {
            DB::table('publication_dependencies')->insert(array_map(
                fn ($row) => ['publication_id' => $publicationId, 'site_id' => $siteId, 'page_id' => $pageId, ...$row],
                $rows,
            ));
        }
    }

    /**
     * Increments the site's publish epoch and holds the site row lock until
     * commit. Every change to published state takes this lock first, so while a
     * publisher holds it no other publish-state change can commit: everything it
     * reads afterwards (READ COMMITTED) is exactly the published state at its epoch.
     */
    public function lockNextEpoch(string $siteId): int
    {
        $row = DB::selectOne('UPDATE sites SET publish_epoch = publish_epoch + 1 WHERE id = ? RETURNING publish_epoch', [$siteId]);

        return $row ? (int) $row->publish_epoch : throw new NotFoundException('Site');
    }

    /** @param list<string> $assetIds */
    public function recordPublicationMedia(string $siteId, string $publicationId, array $assetIds): void
    {
        if ($assetIds !== []) {
            DB::table('publication_media')->insert(array_map(
                fn ($assetId) => ['site_id' => $siteId, 'publication_id' => $publicationId, 'asset_id' => $assetId],
                $assetIds,
            ));
        }
    }

    public function safeMediaRefs(mixed $doc, bool $pinned = false): array
    {
        return $this->validator->safeMediaRefs($doc, $pinned);
    }

    // ── Path claims ─────────────────────────────────────────────────────────

    /**
     * Serialises changes to which page claims which path within one site
     * (create, URL change). Transaction-scoped advisory lock, released at commit.
     */
    public function lockPathClaims(string $siteId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["arkon:page-paths:{$siteId}"]);
    }

    /**
     * A path is claimed by every non-deleted page whose draft path or live path
     * it is. Two pages can therefore never be live at the same URL, and a rename
     * cannot take a URL another page is still served at.
     */
    public function assertPathAvailable(string $siteId, string $path, ?string $exceptPageId = null): void
    {
        $draftOwner = DB::table('pages')->where('site_id', $siteId)->where('path', $path)->whereNull('deleted_at')
            ->when($exceptPageId, fn ($q) => $q->where('id', '!=', $exceptPageId))
            ->value('title');
        if ($draftOwner !== null) {
            throw new ConflictException("The URL {$path} is already used by the page “{$draftOwner}”");
        }
        $liveOwner = DB::table('live_pages as l')
            ->join('pages as p', fn ($join) => $join->on('p.site_id', '=', 'l.site_id')->on('p.id', '=', 'l.page_id'))
            ->where('l.site_id', $siteId)->where('l.path', $path)
            ->when($exceptPageId, fn ($q) => $q->where('l.page_id', '!=', $exceptPageId))
            ->value('p.title');
        if ($liveOwner !== null) {
            throw new ConflictException("The URL {$path} is still the live address of “{$liveOwner}”. Publish or unpublish that page first.");
        }
    }

    /** Maps a unique-index race (another transaction claimed the path first) to a domain error. */
    public static function asPathConflict(Throwable $error, string $path): Throwable
    {
        return $error instanceof QueryException && ($error->errorInfo[0] ?? null) === '23505'
            ? new ConflictException("The URL {$path} is already in use")
            : $error;
    }
}
