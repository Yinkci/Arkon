<?php

namespace App\Arkon\Pages;

use App\Arkon\Ai\ProposalLedger;
use App\Arkon\Audit\AuditLog;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Database\Transactions;
use App\Arkon\Design\DesignResources;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Schema\OperationException;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Time;
use App\Arkon\Support\Uuid;
use App\Arkon\Themes\ThemeService;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Drafts, revisions, publishing and the public lookups. Port of
 * packages/core/src/pages.ts. Every method authorizes first, inside its
 * transaction, and every query is scoped to the context's site.
 */
class PageService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Transactions $transactions,
        private readonly PageStore $store,
        private readonly MediaService $media,
        private readonly PageRenderer $renderer,
        private readonly AuditLog $audit,
        private readonly DocumentValidator $validator,
        private readonly ProposalLedger $proposals,
        private readonly DesignResources $resources,
    ) {}

    // ── Reading ─────────────────────────────────────────────────────────────

    /** @return 'draft'|'published'|'changed' */
    public static function statusOf(object $draft, ?string $liveRevisionId): string
    {
        if ($liveRevisionId === null) {
            return 'draft';
        }
        $matchesCheckpoint = $draft->checkpoint_version !== null && (int) $draft->checkpoint_version === (int) $draft->version;

        return $matchesCheckpoint && $draft->checkpoint_revision_id === $liveRevisionId ? 'published' : 'changed';
    }

    public function listPages(SiteContext $ctx): array
    {
        $this->authorizer->authorize($ctx, 'page.view');
        $rows = DB::table('pages as p')
            ->join('page_drafts as d', 'd.page_id', '=', 'p.id')
            ->leftJoin('live_pages as l', 'l.page_id', '=', 'p.id')
            ->leftJoin('publications as pub', 'pub.id', '=', 'l.publication_id')
            ->where('p.site_id', $ctx->siteId)
            ->whereNull('p.deleted_at')
            ->orderBy('p.path')
            ->get([
                'p.id', 'p.path', 'p.title', 'd.updated_at', 'd.version', 'd.checkpoint_version', 'd.checkpoint_revision_id',
                'pub.revision_id as live_revision_id', 'pub.id as live_publication_id', 'l.path as live_path', 'pub.created_at as published_at',
            ]);

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'path' => $r->path,
            'title' => $r->title,
            // Where the page is served now; differs from `path` while a URL change is unpublished.
            'livePath' => $r->live_path,
            // For stale checks: the draft version and live publication the user acts on.
            'version' => (int) $r->version,
            'livePublicationId' => $r->live_publication_id,
            'updatedAt' => Time::iso($r->updated_at),
            'publishedAt' => Time::iso($r->published_at),
            'status' => self::statusOf($r, $r->live_revision_id),
        ])->all();
    }

    /**
     * Runs coupled reads in one snapshot (REPEATABLE READ, READ ONLY): the page's
     * title and URL, its draft document and version, live state and history all
     * come from the same moment, without taking any row locks. A writer that
     * commits in between is either entirely visible or not at all, so the editor
     * can never be handed old metadata with a newer version (and then pass a
     * version check that would roll that metadata back). Inside an existing
     * transaction it simply joins it.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function readSnapshot(Closure $read): mixed
    {
        return DB::transactionLevel() > 0 ? $read() : $this->transactions->run($read, isolation: 'REPEATABLE READ', readOnly: true);
    }

    /** Everything the editor opens with, from one snapshot. */
    public function editorInit(SiteContext $ctx, string $pageId, MediaSigner $signer): array
    {
        return $this->readSnapshot(function () use ($ctx, $pageId, $signer) {
            $state = $this->editorState($ctx, $pageId);
            // The sandboxed canvas cannot send the session cookie, so it gets signed preview URLs.
            $state['media'] = array_map(fn ($m) => [...$m, 'url' => $signer->signUrl($m['url']), 'variants' => array_map(fn ($v) => [...$v, 'url' => $signer->signUrl($v['url'])], $m['variants'] ?? [])], $state['media']);

            return [
                ...$state,
                'revisions' => $this->listRevisions($ctx, $pageId),
                // Published design tokens (with defaults) and reusable components, never their drafts.
                'tokens' => $this->resources->resolvedTokens($ctx->siteId),
                'components' => $this->resources->componentsForEditor($ctx->siteId),
                // A draft in recovery is shown as stored (its first paint only); every later canvas
                // render goes through the normal endpoint and the current rules.
                'canvas' => $this->readSnapshot(fn () => $this->readCanvas($ctx, $pageId, $state['draft']['document'], $signer, recorded: $state['recovery'] !== null)),
            ];
        });
    }

    /** The editor's status refresh (live state and history), from one snapshot. */
    public function editorStatus(SiteContext $ctx, string $pageId): array
    {
        return $this->readSnapshot(function () use ($ctx, $pageId) {
            $state = $this->editorState($ctx, $pageId);

            return ['live' => $state['live'], 'status' => $state['status'], 'revisions' => $this->listRevisions($ctx, $pageId)];
        });
    }

    public function editorState(SiteContext $ctx, string $pageId): array
    {
        return $this->readSnapshot(fn () => $this->readEditorState($ctx, $pageId));
    }

    private function readEditorState(SiteContext $ctx, string $pageId): array
    {
        $role = $this->authorizer->authorize($ctx, 'page.view');
        $page = $this->store->loadPage($ctx->siteId, Input::id($pageId));
        $draft = $this->store->loadDraft($ctx->siteId, $page->id);
        $live = $this->store->loadLive($ctx->siteId, $page->id);
        $doc = $this->store->document($draft->document);
        $recovery = $this->recoveryFor($doc);

        return [
            'page' => ['id' => $page->id, 'path' => $page->path, 'title' => $page->title],
            'draft' => ['document' => $doc, 'version' => (int) $draft->version],
            'recovery' => $recovery,
            'live' => $live ? self::liveInfo($live) : null,
            'status' => self::statusOf($draft, $live?->revision_id),
            // The page's images first, then the site's most recent uploads (to choose from).
            'media' => $this->media->withNames($ctx->siteId, array_values($this->media->mediaMap($ctx->siteId, array_values(array_unique([
                ...$this->store->safeMediaRefs($doc, pinned: $recovery !== null),
                ...DB::table('media_assets')->where('site_id', $ctx->siteId)->orderByDesc('created_at')->limit(100)->pluck('id')->all(),
            ]))))),
            'permissions' => [
                'edit' => Permissions::allows($role, 'page.edit'),
                'publish' => Permissions::allows($role, 'page.publish'),
                'delete' => Permissions::allows($role, 'page.delete'),
                'upload' => Permissions::allows($role, 'media.upload'),
            ],
        ];
    }

    /**
     * Null for a valid draft. A draft stored before a rule was tightened (links with
     * backslashes; Columns widths that don't match the columns) is valid only under the recorded policy: it opens in recovery, listing
     * each stored value the editor must correct or remove before anything else saves.
     * Nothing is changed here; the stored draft stays as it is until the user's repair is
     * saved as a normal revision. A draft invalid in any other way is not recoverable this
     * way and fails as before.
     *
     * @return list<array{nodeId: string, type: string, path: string, value: string, message: string}>|null
     */
    private function recoveryFor(mixed $doc): ?array
    {
        $issues = $this->validator->validate($doc);
        if ($issues === [] || $this->validator->validatePinned($doc) !== []) {
            return null;
        }
        $nodes = Json::entries($doc['nodes']);
        $items = [];
        foreach ($issues as $issue) {
            $node = $nodes[$issue['nodeId'] ?? ''] ?? null;
            $value = $node ? (Json::entries($node['props'])[$issue['path'] ?? ''] ?? null) : null;
            // Only issues the recorded policy explains: an unsafe link stored as a string, or Columns
            // widths that don't give one width per column (accepted before that rule existed).
            if ($node !== null && $issue['message'] !== Rules::message('unsafeLink') && in_array($issue, DocumentValidator::widthIssues($node, $this->validator->definitionOf($node)->label, count($node['children'] ?? [])), true)) {
                $screen = explode('.', (string) $issue['path'])[2];
                $value = Json::entries(Json::entries(Json::entries(Json::entries($node['props'])['style'] ?? [])['root'] ?? [])[$screen] ?? [])['columns'] ?? null;
            } elseif ($node === null || $issue['message'] !== Rules::message('unsafeLink')) {
                return null;
            }
            if (! is_string($value)) {
                return null;
            }
            $items[] = ['nodeId' => $node['id'], 'type' => $node['type'], 'path' => $issue['path'], 'value' => $value, 'message' => $issue['message']];
        }

        return $items;
    }

    public static function liveInfo(object $live): array
    {
        return [
            'revisionNumber' => (int) $live->revision_number,
            'publishedAt' => Time::iso($live->published_at),
            'publicationId' => $live->publication_id,
            'path' => $live->path,
            'title' => $live->title,
        ];
    }

    public function listRevisions(SiteContext $ctx, string $pageId): array
    {
        return $this->readSnapshot(fn () => $this->readRevisions($ctx, $pageId));
    }

    private function readRevisions(SiteContext $ctx, string $pageId): array
    {
        $this->authorizer->authorize($ctx, 'page.view');
        $page = $this->store->loadPage($ctx->siteId, Input::id($pageId));
        $live = $this->store->loadLive($ctx->siteId, $page->id);

        return DB::table('page_revisions as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.author_id')
            ->where('r.site_id', $ctx->siteId)
            ->where('r.page_id', $page->id)
            ->orderByDesc('r.number')
            ->limit(100)
            ->get(['r.id', 'r.number', 'r.source', 'r.message', 'r.created_at', 'u.name as author_name'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'number' => (int) $r->number,
                'source' => $r->source,
                'message' => $r->message,
                'createdAt' => Time::iso($r->created_at),
                'authorName' => $r->author_name,
                'isLive' => $r->id === $live?->revision_id,
            ])->all();
    }

    /** Renders the current draft exactly as it would be published (production mode). */
    public function renderPreview(SiteContext $ctx, string $pageId): string
    {
        return $this->renderPreviewPage($ctx, $pageId)['html'];
    }

    /**
     * The member preview: production HTML of the draft, and the animation runtime it loads
     * (null when it has no "when scrolled into view" animations), for its script policy.
     *
     * @return array{html: string, runtime: string|null}
     */
    public function renderPreviewPage(SiteContext $ctx, string $pageId): array
    {
        // Title and URL from the same moment as the document they are rendered with.
        return $this->readSnapshot(function () use ($ctx, $pageId) {
            $this->authorizer->authorize($ctx, 'page.view');
            $page = $this->store->loadPage($ctx->siteId, Input::id($pageId));
            $draft = $this->store->loadDraft($ctx->siteId, $page->id);
            $out = $this->store->renderForSite($ctx->siteId, $page->title, $page->path, $this->store->document($draft->document), false);

            return ['html' => $out['html'], 'runtime' => $out['report']['motion']['runtime']];
        });
    }

    /**
     * Editor-mode rendering of the editor's local document for the canvas: the
     * same renderer as production, plus data-ak-* annotations. Images get signed
     * URLs because the sandboxed canvas cannot send the session cookie.
     *
     * @return array{body: string, css: string}
     */
    public function renderCanvas(SiteContext $ctx, string $pageId, mixed $document, MediaSigner $signer): array
    {
        return $this->readSnapshot(fn () => $this->readCanvas($ctx, $pageId, $document, $signer));
    }

    /** @param bool $recorded the stored draft in recovery (see recoveryFor); never for documents sent by the editor */
    private function readCanvas(SiteContext $ctx, string $pageId, mixed $document, MediaSigner $signer, bool $recorded = false): array
    {
        $this->authorizer->authorize($ctx, 'page.view');
        $page = $this->store->loadPage($ctx->siteId, Input::id($pageId));
        $site = DB::table('sites')->where('id', $ctx->siteId)->first(['name']);
        $resources = $this->resources->published($ctx->siteId, $document);
        $media = $this->media->signedMediaMap($ctx->siteId, $this->store->mediaIdsFor($document, $resources['components'], $recorded), $signer);
        try {
            $out = $this->renderer->render($document, 'editor', ['title' => $page->title, 'path' => $page->path], ['name' => $site->name], $media, pinned: $recorded, resources: $resources);
        } catch (RenderException $error) {
            throw new ValidationException('The page could not be rendered', $error->issues);
        }

        // Animations the renderer left off (they would hide the likely LCP), for the inspector to explain.
        return ['body' => $out['body'], 'css' => $out['css'], 'motion' => ['protected' => (object) $out['report']['motion']['protected']]];
    }

    /**
     * Public lookup: the published HTML for a path, or null. Never reads drafts.
     * Deleting a page removes its live row in the same transaction; the join on
     * a non-deleted page is a second line of defence, not the guarantee.
     */
    public function livePage(string $siteId, string $path): ?object
    {
        return DB::table('live_pages as l')
            ->join('publications as p', 'p.id', '=', 'l.publication_id')
            ->join('pages as pg', fn ($j) => $j->on('pg.site_id', '=', 'l.site_id')->on('pg.id', '=', 'l.page_id'))
            ->whereNull('pg.deleted_at')
            ->where('l.site_id', $siteId)
            ->where('l.path', $path)
            // The animation runtime the stored HTML loads, if any (its script policy allows exactly that file).
            ->first(['p.id as publication_id', 'p.html', DB::raw("p.render_inputs->>'motion' AS motion_runtime")]);
    }

    /**
     * Public lookup for an old URL. Redirects point at a page and resolve to its
     * current live path, so they never chain or loop; a page that is unpublished
     * or deleted has no target and the old URL is simply not found.
     */
    public function resolveRedirect(string $siteId, string $path): ?string
    {
        $target = DB::table('redirects as r')
            ->join('live_pages as l', fn ($j) => $j->on('l.site_id', '=', 'r.site_id')->on('l.page_id', '=', 'r.page_id'))
            ->join('pages as p', fn ($j) => $j->on('p.site_id', '=', 'r.site_id')->on('p.id', '=', 'r.page_id'))
            ->whereNull('p.deleted_at')
            ->where('r.site_id', $siteId)
            ->where('r.from_path', $path)
            ->value('l.path');

        return $target !== null && $target !== $path ? $target : null;
    }

    // ── Writing drafts ──────────────────────────────────────────────────────

    /**
     * Applies operations to the draft the caller was looking at. Rejects the save
     * if anyone changed the draft since (StaleVersionException), so nothing is
     * overwritten. A retry of an already-applied save (same key, same request)
     * returns the original result instead of failing as stale or applying twice.
     * Saving creates a checkpoint revision. It never touches the live page.
     *
     * @param  array{pageId: mixed, baseVersion: mixed, operations: mixed, saveKey: mixed, message?: mixed}  $input  operations in raw JSON form
     * @return array{version: int, revision: array{id: string, number: int}, document: mixed, replayed: bool}
     */
    public function saveDraft(SiteContext $ctx, array $input): array
    {
        $pageId = Input::id($input['pageId'] ?? null);
        $valid = Input::validate($input, [
            'baseVersion' => ['required', 'integer', 'min:1'],
            'saveKey' => Input::requestKeyRule(),
            'message' => ['nullable', 'string', 'max:'.Rules::get('limits.saveMessage')],
        ]);
        [$operations, $issues] = Operations::parse($input['operations'] ?? null);
        $max = Rules::get('limits.operations');
        if ($issues === [] && (count($operations) < 1 || count($operations) > $max)) {
            $issues[] = ['path' => 'operations', 'message' => "Expected 1 to {$max} operations"];
        }
        if ($issues !== []) {
            throw new ValidationException('The change is not valid', $issues);
        }
        $baseVersion = (int) $valid['baseVersion'];
        $saveKey = $valid['saveKey'];
        $message = trim((string) ($valid['message'] ?? '')) ?: 'Saved draft';
        // Applying an AI proposal: the save must be exactly that proposal (checked below).
        $proposalId = isset($input['proposalId']) ? Input::id($input['proposalId'], 'AI proposal') : null;
        $fingerprint = Fingerprint::of(['kind' => 'save', 'pageId' => $pageId, 'baseVersion' => $baseVersion, 'operations' => $operations, ...($proposalId ? ['proposalId' => $proposalId] : [])]);

        return $this->transactions->run(function () use ($ctx, $pageId, $baseVersion, $operations, $saveKey, $message, $fingerprint, $proposalId) {
            $this->authorizer->authorize($ctx, 'page.edit');
            [$page, $draft] = $this->store->lockForWrite($ctx->siteId, $pageId);

            if ($draft->last_save_key === $saveKey) {
                if ($draft->last_save_fingerprint !== $fingerprint) {
                    throw new ConflictException('This save key was already used for a different save');
                }
                $revision = DB::table('page_revisions')->where('id', $draft->checkpoint_revision_id)->first(['id', 'number']);

                return [
                    'version' => (int) $draft->version,
                    'revision' => ['id' => $revision->id, 'number' => (int) $revision->number],
                    'document' => Json::decode($draft->document),
                    'replayed' => true,
                ];
            }
            $this->store->assertVersion($draft, $baseVersion);
            $proposal = $proposalId ? $this->proposals->claimForSave($ctx, $pageId, $proposalId, $baseVersion, $operations) : null;

            try {
                $next = Operations::apply($this->store->document($draft->document), $operations)['doc'];
            } catch (OperationException $error) {
                throw new ValidationException($error->getMessage());
            }
            ThemeService::assertAdditions($ctx->siteId, $this->store->document($draft->document), $next);
            $this->store->validateForSave($ctx->siteId, $next);

            $version = (int) $draft->version + 1;
            $revision = $proposal
                // Recorded as AI-sourced content, applied by this user.
                ? $this->store->insertRevision(new SiteContext($ctx->siteId, $ctx->userId, 'ai'), $pageId, $next, $page->title, $page->path, mb_substr('AI: '.($proposal->summary ?: $proposal->prompt), 0, (int) Rules::get('limits.saveMessage')))
                : $this->store->insertRevision($ctx, $pageId, $next, $page->title, $page->path, $message);
            DB::table('page_drafts')->where('page_id', $pageId)->update([
                'document' => Json::encode($next),
                'version' => $version,
                'checkpoint_revision_id' => $revision['id'],
                'checkpoint_version' => $version,
                'last_save_key' => $saveKey,
                'last_save_fingerprint' => $fingerprint,
                'updated_by' => $ctx->userId,
                'updated_at' => now(),
            ]);
            DB::table('pages')->where('id', $pageId)->update(['updated_at' => now()]);
            $this->audit->forContext($ctx, 'page.draft.save', 'page', $pageId, [
                'version' => $version, 'revision' => $revision['number'], 'operations' => count($operations),
            ]);
            if ($proposal) {
                $this->proposals->markApplied($proposal->id, $revision['id']);
                $this->audit->forContext($ctx, 'page.ai.apply', 'page', $pageId, ['proposal' => $proposal->id, 'revision' => $revision['number']]);
            }

            return ['version' => $version, 'revision' => $revision, 'document' => $next, 'replayed' => false];
        });
    }

    /**
     * Copies a revision into the draft as a new version. History itself is never
     * modified. Restore brings back content only: the page keeps its current
     * draft title and URL.
     *
     * @return array{version: int, revision: array{id: string, number: int}, document: mixed}
     */
    public function restoreRevision(SiteContext $ctx, array $input): array
    {
        $pageId = Input::id($input['pageId'] ?? null);
        $revisionId = Input::id($input['revisionId'] ?? null, 'Revision');
        $expectedVersion = (int) Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1']])['expectedVersion'];

        return $this->transactions->run(function () use ($ctx, $pageId, $revisionId, $expectedVersion) {
            $this->authorizer->authorize($ctx, 'page.edit');
            [$page, $draft] = $this->store->lockForWrite($ctx->siteId, $pageId);
            $this->store->assertVersion($draft, $expectedVersion);
            $source = DB::table('page_revisions')
                ->where('site_id', $ctx->siteId)->where('page_id', $pageId)->where('id', $revisionId)
                ->first() ?? throw new NotFoundException('Revision');
            $doc = $this->store->document($source->document);
            $this->store->validateForSave($ctx->siteId, $doc);

            $version = (int) $draft->version + 1;
            $revision = $this->store->insertRevision($ctx, $pageId, $doc, $page->title, $page->path, "Restored from #{$source->number}");
            DB::table('page_drafts')->where('page_id', $pageId)->update([
                'document' => Json::encode($doc),
                'version' => $version,
                'checkpoint_revision_id' => $revision['id'],
                'checkpoint_version' => $version,
                'last_save_key' => null,
                'last_save_fingerprint' => null,
                'updated_by' => $ctx->userId,
                'updated_at' => now(),
            ]);
            $this->audit->forContext($ctx, 'page.revision.restore', 'page', $pageId, [
                'from' => (int) $source->number, 'revision' => $revision['number'], 'version' => $version,
            ]);

            return ['version' => $version, 'revision' => $revision, 'document' => $doc];
        });
    }

    // ── Publishing ──────────────────────────────────────────────────────────

    /**
     * Publishes exactly the draft version the caller saw. Idempotent per key: a
     * retry with the same key and request returns the first result; the same key
     * for a different page or version is rejected. The live pointer only moves to
     * a higher epoch, so retries and late duplicates cannot roll the page back.
     *
     * @return array{publicationId: string, revisionId: string, epoch: int, publishedAt: string, replayed: bool}
     */
    public function publish(SiteContext $ctx, array $input): array
    {
        $pageId = Input::id($input['pageId'] ?? null);
        $valid = Input::validate($input, [
            'expectedVersion' => ['required', 'integer', 'min:1'],
            'idempotencyKey' => Input::requestKeyRule(),
        ]);
        $expectedVersion = (int) $valid['expectedVersion'];
        $key = $valid['idempotencyKey'];
        $fingerprint = Fingerprint::of(['kind' => 'publish', 'pageId' => $pageId, 'expectedVersion' => $expectedVersion]);

        return $this->transactions->run(function () use ($ctx, $pageId, $expectedVersion, $key, $fingerprint) {
            $this->authorizer->authorize($ctx, 'page.publish');

            $replay = function () use ($ctx, $key, $fingerprint): ?array {
                $existing = DB::table('publications')->where('site_id', $ctx->siteId)->where('idempotency_key', $key)->first();
                if ($existing === null) {
                    return null;
                }
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new ConflictException('This publish key was already used for a different page or version');
                }

                return [
                    'publicationId' => $existing->id, 'revisionId' => $existing->revision_id, 'epoch' => (int) $existing->epoch,
                    'publishedAt' => Time::iso($existing->created_at), 'replayed' => true,
                ];
            };
            if ($early = $replay()) {
                return $early;
            }

            // Re-reads the page after the lock: a publish that waited behind a delete must not resurrect it.
            [$page, $draft] = $this->store->lockForWrite($ctx->siteId, $pageId);
            // A concurrent duplicate may have committed while this request waited for the lock.
            if ($late = $replay()) {
                return $late;
            }
            $this->store->assertVersion($draft, $expectedVersion);

            // Lock order: draft row, then site row (epoch). Saves only lock the draft, so this cannot deadlock.
            $epoch = $this->store->lockNextEpoch($ctx->siteId);

            // Older component versions are migrated forward in memory; what gets rendered is $doc.
            $doc = $this->store->document($draft->document);
            $revisionId = $draft->checkpoint_version !== null && (int) $draft->checkpoint_version === (int) $draft->version
                ? $draft->checkpoint_revision_id
                : null;
            // A publication's revision document is exactly the document rendered, so the publication can be
            // reproduced from it. A checkpoint stored at older component versions is not that document.
            $upgraded = $revisionId !== null
                && Json::canonical(Json::decode(DB::table('page_revisions')->where('id', $revisionId)->value('document'))) !== Json::canonical($doc);
            if ($revisionId === null || $upgraded) {
                $message = $upgraded ? 'Published with components upgraded to current versions' : 'Published';
                $revisionId = $this->store->insertRevision($ctx, $pageId, $doc, $page->title, $page->path, $message)['id'];
                DB::table('page_drafts')->where('page_id', $pageId)->update([
                    'checkpoint_revision_id' => $revisionId, 'checkpoint_version' => $draft->version,
                ]);
            }

            // The title and URL being published are the revision's, not whatever the page row says later.
            $meta = DB::table('page_revisions')->where('id', $revisionId)->first(['title', 'path']);
            $previous = $this->store->loadLive($ctx->siteId, $pageId);
            $occupied = DB::table('live_pages')->where('site_id', $ctx->siteId)->where('path', $meta->path)->where('page_id', '!=', $pageId)->exists();
            if ($occupied) {
                throw new ConflictException("Another page is live at {$meta->path}. Change this page's URL or unpublish the other page first.");
            }

            // Rendered while holding the epoch lock: what it reads is the published state at `epoch`.
            $rendered = $this->store->renderForSite($ctx->siteId, $meta->title, $meta->path, $doc, true);
            $publicationId = Uuid::v7();
            $created = DB::selectOne(
                'INSERT INTO publications (id, site_id, page_id, revision_id, path, html, epoch, idempotency_key, request_fingerprint, render_inputs, published_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?) RETURNING created_at',
                [$publicationId, $ctx->siteId, $pageId, $revisionId, $meta->path, $rendered['html'], $epoch, $key, $fingerprint, Json::encode($rendered['inputs']), $ctx->userId],
            );
            $this->store->recordPublicationMedia($ctx->siteId, $publicationId, $rendered['mediaIds']);
            $this->store->recordDependencies($ctx->siteId, $pageId, $publicationId, $rendered['inputs']);

            DB::statement(
                'INSERT INTO live_pages (page_id, site_id, path, publication_id, epoch, updated_at) VALUES (?, ?, ?, ?, ?, now())
                 ON CONFLICT (page_id) DO UPDATE SET publication_id = EXCLUDED.publication_id, epoch = EXCLUDED.epoch, path = EXCLUDED.path, updated_at = now()
                 WHERE live_pages.epoch < EXCLUDED.epoch',
                [$pageId, $ctx->siteId, $meta->path, $publicationId, $epoch],
            );

            // A live page always wins over a redirect from its own URL.
            DB::table('redirects')->where('site_id', $ctx->siteId)->where('from_path', $meta->path)->delete();
            // The URL this page was served at until now keeps working: it redirects to the page.
            $movedFrom = $previous && $previous->path !== $meta->path ? $previous->path : null;
            if ($movedFrom !== null) {
                DB::statement(
                    'INSERT INTO redirects (site_id, from_path, page_id) VALUES (?, ?, ?)
                     ON CONFLICT (site_id, from_path) DO UPDATE SET page_id = EXCLUDED.page_id, created_at = now()',
                    [$ctx->siteId, $movedFrom, $pageId],
                );
            }

            $this->audit->forContext($ctx, 'page.publish', 'page', $pageId, [
                'publicationId' => $publicationId, 'revisionId' => $revisionId, 'epoch' => $epoch,
                'version' => (int) $draft->version, 'path' => $meta->path, 'redirectedFrom' => $movedFrom,
            ]);

            return ['publicationId' => $publicationId, 'revisionId' => $revisionId, 'epoch' => $epoch, 'publishedAt' => Time::iso($created->created_at), 'replayed' => false];
        });
    }

    // ── Reproduction (internal: audits and tooling) ─────────────────────────────

    /**
     * Renders a publication again from its own revision document and recorded
     * inputs (renderer version, component versions, site, page and media
     * metadata) and compares the result with the stored HTML.
     *
     * Publications made before inputs were recorded (render_inputs NULL) are
     * "legacy": they are not reproducible, and their stored HTML stays the
     * authoritative copy. "unavailable" means the recorded renderer or a
     * component version is no longer registered.
     *
     * @return array{status: 'reproduced'|'legacy'|'unavailable', matches: bool|null, html: string|null, reason: string|null}
     */
    public function reproducePublication(string $siteId, string $publicationId): array
    {
        $publication = Uuid::isValid($publicationId)
            ? DB::table('publications')->where('site_id', $siteId)->where('id', $publicationId)->first()
            : null;
        if ($publication === null) {
            throw new NotFoundException('Publication');
        }
        if ($publication->render_inputs === null) {
            return ['status' => 'legacy', 'matches' => null, 'html' => null, 'reason' => 'Published before render inputs were recorded'];
        }
        // A compatibility record names the development build that made it (see publication_render_compat).
        $build = DB::table('publication_render_compat')->where('publication_id', $publication->id)->value('renderer');
        $html = $this->renderPublication($siteId, $publication, $build);
        if (is_array($html)) {
            return $html;
        }

        return ['status' => 'reproduced', 'matches' => $html === $publication->html, 'html' => $html, 'reason' => null, 'build' => $build];
    }

    /** @return string|array the reproduced HTML, or the "unavailable" result */
    private function renderPublication(string $siteId, object $publication, ?string $build): string|array
    {
        $revision = DB::table('page_revisions')->where('site_id', $siteId)->where('id', $publication->revision_id)->value('document');
        try {
            $inputs = Json::toArray(Json::decode($publication->render_inputs));
            $components = $this->resources->recordedComponents($siteId, array_map('intval', $inputs['reusable'] ?? []));

            return $this->renderer->reproduce(Json::decode($revision), $inputs, $components, $build)['html'];
        } catch (RenderException $error) {
            return ['status' => 'unavailable', 'matches' => null, 'html' => null, 'reason' => $error->getMessage().': '.implode('; ', array_column($error->issues, 'message'))];
        }
    }

    /**
     * Records which development build of its renderer version produced a publication. Only
     * when the recorded version does not reproduce it and the named build reproduces it byte
     * for byte; append-only (one record per publication, never changed). The publication,
     * its revision and its render inputs are not modified.
     *
     * @return array{recorded: bool, reason: string}
     */
    public function recordRenderCompat(string $publicationId, string $build, string $reason): array
    {
        if (! isset(PageRenderer::COMPAT_BUILDS[$build])) {
            return ['recorded' => false, 'reason' => "Unknown renderer build {$build}"];
        }
        $publication = Uuid::isValid($publicationId) ? DB::table('publications')->where('id', $publicationId)->first() : null;
        if ($publication === null || $publication->render_inputs === null) {
            return ['recorded' => false, 'reason' => 'No publication with recorded render inputs has that id'];
        }
        if (DB::table('publication_render_compat')->where('publication_id', $publication->id)->exists()) {
            return ['recorded' => false, 'reason' => 'This publication already has a compatibility record'];
        }
        $recorded = $this->renderPublication($publication->site_id, $publication, null);
        if ($recorded === $publication->html) {
            return ['recorded' => false, 'reason' => 'Its recorded renderer version already reproduces it'];
        }
        $candidate = $this->renderPublication($publication->site_id, $publication, $build);
        if ($candidate !== $publication->html) {
            return ['recorded' => false, 'reason' => "Renderer build {$build} does not reproduce it byte for byte either"];
        }
        DB::table('publication_render_compat')->insert([
            'publication_id' => $publication->id, 'site_id' => $publication->site_id, 'page_id' => $publication->page_id,
            'renderer' => $build, 'reason' => $reason,
        ]);

        return ['recorded' => true, 'reason' => "Recorded: rendered by {$build}"];
    }

    // ── Background re-render (internal: called by jobs, never directly from HTTP) ──

    /**
     * Phase 1 of a dependency-driven re-render: read the live revision, its
     * dependencies and the site's epoch from one REPEATABLE READ snapshot and
     * render. Nothing is written.
     */
    public function prepareRerender(string $siteId, string $pageId): ?array
    {
        return $this->transactions->run(function () use ($siteId, $pageId) {
            $epoch = DB::table('sites')->where('id', $siteId)->value('publish_epoch');
            $live = $this->store->loadLive($siteId, $pageId);
            if ($epoch === null || $live === null) {
                return null;
            }
            $this->store->loadPage($siteId, $pageId);
            $revision = DB::table('page_revisions')->where('id', $live->revision_id)->first();
            // The live revision exactly as published, each node at its own component version: a dependency
            // change must not silently upgrade content. Only the site's data is current.
            $doc = Json::decode($revision->document);
            $rendered = $this->store->renderForSite($siteId, $revision->title, $live->path, $doc, false, pinned: true);

            return [
                'siteId' => $siteId, 'pageId' => $pageId, 'revisionId' => $live->revision_id, 'path' => $live->path,
                'html' => $rendered['html'], 'inputs' => $rendered['inputs'], 'mediaIds' => $rendered['mediaIds'],
                'epoch' => (int) $epoch,
            ];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    /**
     * Phase 2: store the re-render and move the live pointer only if nothing
     * newer became live since the snapshot. Retrying is safe (same idempotency
     * key), and a job that finishes late can never replace a newer publication.
     *
     * @return array{applied: bool}
     */
    public function commitRerender(array $prepared): array
    {
        return $this->transactions->run(function () use ($prepared) {
            $publicationId = Uuid::v7();
            $key = "rerender-{$prepared['pageId']}-{$prepared['epoch']}";
            $inserted = DB::select(
                'INSERT INTO publications (id, site_id, page_id, revision_id, path, html, epoch, idempotency_key, request_fingerprint, render_inputs, published_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, NULL) ON CONFLICT DO NOTHING RETURNING id',
                [
                    $publicationId, $prepared['siteId'], $prepared['pageId'], $prepared['revisionId'], $prepared['path'], $prepared['html'],
                    $prepared['epoch'], $key, Fingerprint::of(['kind' => 'rerender', 'pageId' => $prepared['pageId'], 'epoch' => $prepared['epoch']]),
                    Json::encode($prepared['inputs']),
                ],
            );
            if ($inserted === []) {
                return ['applied' => false];
            }
            $this->store->recordPublicationMedia($prepared['siteId'], $publicationId, $prepared['mediaIds']);
            $this->store->recordDependencies($prepared['siteId'], $prepared['pageId'], $publicationId, $prepared['inputs']);

            // UPDATE only: a re-render must never re-publish a page that was unpublished meanwhile.
            $moved = DB::update(
                'UPDATE live_pages SET publication_id = ?, epoch = ?, updated_at = now() WHERE site_id = ? AND page_id = ? AND epoch < ?
                 AND EXISTS (SELECT 1 FROM pages WHERE pages.site_id = live_pages.site_id AND pages.id = live_pages.page_id AND pages.deleted_at IS NULL)',
                [$publicationId, $prepared['epoch'], $prepared['siteId'], $prepared['pageId'], $prepared['epoch']],
            );

            return ['applied' => $moved > 0, 'publicationId' => $publicationId];
        });
    }
}
