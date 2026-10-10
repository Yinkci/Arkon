<?php

namespace App\Arkon\Content;

use App\Arkon\Database\Transactions;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whole-item operations on pages, posts and future content types, for callers that change
 * several aspects in one request: the public API and AI actions. It only composes the domain
 * services the admin uses (PageManagement, PageService, ContentDetails), inside one
 * transaction, so every rule (permissions, URL claims, validation, revisions, publish checks,
 * audit) is theirs and a request either happens completely or not at all.
 */
final class ContentItems
{
    public const STATUSES = ['draft', 'published'];

    /** SEO settings writable through the API and AI actions (the editor's SEO tab has more). */
    public const SEO_FIELDS = ['title', 'description', 'focusTopic', 'noindex'];

    public function __construct(
        private readonly PageManagement $management,
        private readonly PageService $pages,
        private readonly ContentDetails $details,
        private readonly PageStore $store,
        private readonly Transactions $tx,
    ) {}

    /**
     * Creates an item, optionally with content (public blocks), details and status "published".
     * A retry with the same request key returns the same item; with other inputs it is refused.
     *
     * @param  array{title: mixed, path?: mixed, slug?: mixed, blocks?: mixed, seo?: mixed, excerpt?: mixed, featuredMediaId?: mixed, terms?: mixed, status?: mixed, requestKey?: mixed}  $input
     * @return array{id: string, replayed: bool}
     */
    public function create(SiteContext $ctx, string $kind, array $input): array
    {
        $type = ContentTypes::get($kind);
        $title = Input::title($input['title'] ?? null);
        $key = $input['requestKey'] ?? self::key();
        $path = $this->path($ctx->siteId, $kind, $input, $title, requestKey: is_string($key) ? $key : null);
        $blocks = array_key_exists('blocks', $input) && $input['blocks'] !== null ? PublicBlocks::validate($input['blocks']) : null;
        $details = ContentDetails::validated(array_intersect_key($input, array_flip(['excerpt', 'featuredMediaId', 'terms'])));
        $status = self::status($input['status'] ?? 'draft');
        $seo = self::seo($input['seo'] ?? null);
        $intent = array_filter(['blocks' => $blocks, 'seo' => $seo, 'details' => $details ?: null, 'status' => $status === 'draft' ? null : $status], fn ($v) => $v !== null);
        $document = $blocks === null ? null : PublicBlocks::toDocument($ctx->siteId, $blocks, $title, $type['details'] ? 'narrow' : 'default');
        if ($seo !== null || $type['details']) {
            // Posts are articles for search engines (structured data) unless the caller says otherwise.
            $document ??= PageManagement::starterDocument($kind, $title);
            $document['seo'] = [...($type['details'] ? ['pageType' => 'article'] : []), ...($seo ?? [])];
        }

        $created = $this->management->create($ctx, [
            'title' => $title, 'path' => $path, 'kind' => $kind, 'requestKey' => $key,
            ...($intent === [] ? [] : ['intent' => $intent]), ...($document === null ? [] : ['document' => $document]),
        ], function (string $pageId) use ($ctx, $details, $status, $key) {
            [$page] = $this->store->lockForWrite($ctx->siteId, $pageId);
            $this->details->updateLocked($ctx, $page, $details);
            if ($status === 'published') {
                $this->pages->publish($ctx, ['pageId' => $pageId, 'expectedVersion' => 1, 'idempotencyKey' => substr($key, 0, 80).'-publish']);
            }
        });

        return ['id' => $created['pageId'], 'replayed' => $created['replayed']];
    }

    /**
     * Changes the given aspects of an item in one transaction: title and URL (a draft revision),
     * content (replaces it), details, then status (publish or unpublish). `version` is the draft
     * version the caller last read; when given, a newer draft makes the request stale (409).
     *
     * @param  array{title?: mixed, path?: mixed, slug?: mixed, blocks?: mixed, seo?: mixed, excerpt?: mixed, featuredMediaId?: mixed, terms?: mixed, status?: mixed, version?: mixed}  $input
     */
    public function update(SiteContext $ctx, string $kind, string $id, array $input): void
    {
        $type = ContentTypes::get($kind);
        $id = $this->assertKind($ctx->siteId, $kind, $id);
        $blocks = array_key_exists('blocks', $input) && $input['blocks'] !== null ? PublicBlocks::validate($input['blocks']) : null;
        $details = ContentDetails::validated(array_intersect_key($input, array_flip(['excerpt', 'featuredMediaId', 'terms'])));
        $status = array_key_exists('status', $input) ? self::status($input['status']) : null;
        $seo = self::seo($input['seo'] ?? null);
        $expected = array_key_exists('version', $input) ? Input::validate($input, ['version' => ['integer', 'min:1']])['version'] : null;

        $this->tx->run(function () use ($ctx, $type, $id, $input, $blocks, $seo, $details, $status, $expected) {
            [$page, $draft] = $this->store->lockForWrite($ctx->siteId, $id);
            $version = (int) $draft->version;
            if ($expected !== null && $expected !== $version) {
                throw new StaleVersionException($expected, $version);
            }
            $title = array_key_exists('title', $input) ? Input::title($input['title']) : $page->title;
            $path = array_key_exists('path', $input) || array_key_exists('slug', $input) ? $this->path($ctx->siteId, $page->kind, $input, $title, $id) : $page->path;
            if ($title !== $page->title || $path !== $page->path) {
                $version = $this->management->updateSettings($ctx, ['pageId' => $id, 'title' => $title, 'path' => $path, 'expectedVersion' => $version, 'saveKey' => self::key()])['version'];
            }
            if ($blocks !== null || $seo !== null) {
                $current = $this->store->document($draft->document);
                $document = $blocks === null ? $current : PublicBlocks::replaceContent($ctx->siteId, $current, $blocks, $title, $type['details'] ? 'narrow' : 'default');
                if ($seo !== null) {
                    $document['seo'] = [...Json::entries($document['seo'] ?? []), ...$seo];
                }
                $version = $this->management->replaceDocument($ctx, ['pageId' => $id, 'expectedVersion' => $version, 'document' => $document, 'saveKey' => self::key()])['version'];
            }
            if ($details !== []) {
                [$page] = $this->store->lockForWrite($ctx->siteId, $id);
                $this->details->updateLocked($ctx, $page, $details);
            }
            if ($status === 'published') {
                $this->pages->publish($ctx, ['pageId' => $id, 'expectedVersion' => $version, 'idempotencyKey' => self::key()]);
            } elseif ($status === 'draft' && ($live = $this->store->loadLive($ctx->siteId, $id)) !== null) {
                $this->management->unpublish($ctx, ['pageId' => $id, 'expectedPublicationId' => $live->publication_id]);
            }
        });
    }

    /** Moves an item to the Trash (offline, URL freed, restorable). */
    public function trash(SiteContext $ctx, string $kind, string $id): void
    {
        $id = $this->assertKind($ctx->siteId, $kind, $id);
        $version = (int) DB::table('page_drafts')->where('site_id', $ctx->siteId)->where('page_id', $id)->value('version');
        $this->management->delete($ctx, ['pageId' => $id, 'expectedVersion' => $version]);
    }

    /** The item's id if it exists on this site with this kind (in the Trash or not), else not found. */
    public function assertKind(string $siteId, string $kind, string $id, bool $trashed = false): string
    {
        $label = ContentTypes::get($kind)['label'];
        $id = Input::id($id, $label);
        $exists = DB::table('pages')->where('site_id', $siteId)->where('id', $id)->where('kind', $kind)->whereNull('purged_at')
            ->when(! $trashed, fn ($q) => $q->whereNull('deleted_at'))->exists();

        return $exists ? $id : throw new NotFoundException($label);
    }

    /** The URL for an item: an explicit path, else the type's prefix plus a slug (given or from the title). */
    private function path(string $siteId, string $kind, array $input, string $title, ?string $except = null, ?string $requestKey = null): string
    {
        if (isset($input['path'])) {
            return Input::path($input['path']);
        }
        $prefix = ContentTypes::get($kind)['pathPrefix'];
        if (isset($input['slug'])) {
            if (! is_string($input['slug']) || ! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $input['slug']) || strlen($input['slug']) > 100) {
                throw new ValidationException('A slug uses lowercase letters, digits and single hyphens.', [['path' => 'slug', 'message' => 'A slug uses lowercase letters, digits and single hyphens.']]);
            }

            return Input::path($prefix.'/'.$input['slug']);
        }
        // From the title: the first free variant, so "Hello" twice gives /blog/hello and /blog/hello-2.
        // A retry ignores the item its own request key created, so it derives the same URL and replays.
        $base = trim(Str::limit(Str::slug($title), 80, ''), '-') ?: 'untitled';
        for ($i = 1; ; $i++) {
            $candidate = $prefix.'/'.$base.($i === 1 ? '' : '-'.$i);
            $taken = DB::table('pages')->where('site_id', $siteId)->where('path', $candidate)->whereNull('deleted_at')->when($except, fn ($q) => $q->where('id', '!=', $except))
                ->when($requestKey, fn ($q) => $q->where(fn ($w) => $w->whereNull('request_key')->orWhere('request_key', '!=', $requestKey)))->exists()
                || DB::table('live_pages')->where('site_id', $siteId)->where('path', $candidate)->when($except, fn ($q) => $q->where('page_id', '!=', $except))->exists();
            if (! $taken) {
                return Input::path($candidate);
            }
        }
    }

    /** @return ?array<string, mixed> the given SEO settings (validated with the document) */
    private static function seo(mixed $seo): ?array
    {
        if ($seo === null) {
            return null;
        }
        if (! is_array($seo) || ($seo !== [] && array_is_list($seo)) || array_diff(array_keys($seo), self::SEO_FIELDS) !== []) {
            throw new ValidationException('seo accepts '.implode(', ', self::SEO_FIELDS).'.', [['path' => 'seo', 'message' => 'seo accepts '.implode(', ', self::SEO_FIELDS).'.']]);
        }

        return $seo;
    }

    private static function status(mixed $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : throw new ValidationException('Status must be draft or published.', [['path' => 'status', 'message' => 'Status must be draft or published.']]);
    }

    /** A fresh request key (the same format the editor sends). */
    public static function key(): string
    {
        return bin2hex(random_bytes(16));
    }
}
