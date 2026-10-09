<?php

namespace App\Arkon\Design;

use App\Arkon\Database\Transactions;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageService;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Time;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps live pages in step with the shared resources they were published with.
 *
 * Publishing design tokens or a reusable component records, in the same transaction,
 * one refresh per live page whose live publication depends on that resource. A
 * refresh re-renders the page's *live revision* (never its draft) with the resources
 * published at that moment, through PageService::prepareRerender/commitRerender:
 * epoch-ordered, so a page published again meanwhile is never rolled back, and
 * idempotent per page and epoch, so retries and concurrent runners cannot publish
 * twice. Refreshes run right after the publish (bounded), and again from
 * `arkon:refresh-pages` or Retry; status is pending, done, skipped (no longer live)
 * or failed (with the error, retried up to MAX_ATTEMPTS automatically).
 */
class PageRefreshes
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PageService $pages,
        private readonly Transactions $transactions,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * Call inside the transaction that publishes the resource, after its epoch lock.
     *
     * @param  'tokens'|'component'  $kind
     */
    public function enqueue(string $siteId, string $kind, string $resourceId, int $version): int
    {
        $pageIds = DB::table('live_pages as l')
            ->join('publication_dependencies as d', 'd.publication_id', '=', 'l.publication_id')
            ->join('pages as p', fn ($j) => $j->on('p.site_id', '=', 'l.site_id')->on('p.id', '=', 'l.page_id'))
            ->whereNull('p.deleted_at')
            ->where('l.site_id', $siteId)
            ->where('d.kind', $kind)
            ->where('d.resource_id', $resourceId)
            ->distinct()
            ->pluck('l.page_id')
            ->all();
        if ($pageIds === []) {
            return 0;
        }

        return DB::table('page_refreshes')->insertOrIgnore(array_map(fn ($pageId) => [
            'id' => Uuid::v7(), 'site_id' => $siteId, 'page_id' => $pageId,
            'cause_kind' => $kind, 'cause_id' => $resourceId, 'cause_version' => $version,
        ], $pageIds));
    }

    /**
     * Processes pending refreshes of a site, oldest first, until none are left or the
     * budget is used up (the rest stay pending for the next run).
     *
     * @return array{done: int, skipped: int, failed: int}
     */
    public function run(string $siteId, int $limit = 50, float $seconds = 20.0): array
    {
        $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0];
        $started = microtime(true);
        for ($i = 0; $i < $limit && microtime(true) - $started < $seconds; $i++) {
            $claim = $this->claim($siteId);
            if ($claim === null) {
                break;
            }
            $outcome = $this->process($claim);
            if ($outcome !== 'retry') {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }

    /** Takes the oldest pending refresh (SKIP LOCKED: concurrent runners take different ones). */
    private function claim(string $siteId): ?object
    {
        return $this->transactions->run(function () use ($siteId) {
            // Attempts that crashed mid-way and used up their tries are failed, not retried forever.
            DB::table('page_refreshes')->where('site_id', $siteId)->where('status', 'pending')->where('attempts', '>=', self::MAX_ATTEMPTS)
                ->update(['status' => 'failed', 'last_error' => DB::raw("coalesce(last_error, 'Stopped after repeated attempts')"), 'updated_at' => DB::raw('now()')]);

            return DB::selectOne(
                "UPDATE page_refreshes SET attempts = attempts + 1, updated_at = now()
                 WHERE id = (SELECT id FROM page_refreshes WHERE site_id = ? AND status = 'pending' AND attempts < ?
                             ORDER BY created_at, id FOR UPDATE SKIP LOCKED LIMIT 1)
                 RETURNING id, site_id, page_id, attempts",
                [$siteId, self::MAX_ATTEMPTS],
            );
        });
    }

    /** @return 'done'|'skipped'|'failed'|'retry' (failed, attempts remain: it stays pending) */
    private function process(object $refresh): string
    {
        try {
            $prepared = $this->pages->prepareRerender($refresh->site_id, $refresh->page_id);
            if ($prepared === null) {
                $this->finish($refresh->id, 'skipped', null, null);

                return 'skipped';
            }
            $result = $this->pages->commitRerender($prepared);
            // Not applied: something at least as new is live (a later publish, or this epoch's re-render).
            $this->finish($refresh->id, 'done', null, $result['applied'] ? $result['publicationId'] : null);

            return 'done';
        } catch (NotFoundException) {
            // The page was deleted meanwhile: nothing is live to refresh.
            $this->finish($refresh->id, 'skipped', null, null);

            return 'skipped';
        } catch (Throwable $error) {
            $message = $error instanceof ValidationException
                ? $error->getMessage().': '.implode('; ', array_slice(array_column($error->issues, 'message'), 0, 3))
                : 'The page could not be re-rendered ('.class_basename($error).')';
            if (! $error instanceof ValidationException) {
                report($error);
            }
            // Retried automatically while attempts remain; then failed until someone retries it.
            $status = (int) $refresh->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $this->finish($refresh->id, $status, mb_substr($message, 0, 500), null);

            return $status === 'failed' ? 'failed' : 'retry';
        }
    }

    private function finish(string $id, string $status, ?string $error, ?string $publicationId): void
    {
        DB::table('page_refreshes')->where('id', $id)->update([
            'status' => $status, 'last_error' => $error, 'publication_id' => $publicationId, 'updated_at' => DB::raw('now()'),
        ]);
    }

    /** Puts failed refreshes back in the queue and runs them. */
    public function retry(SiteContext $ctx): array
    {
        $this->authorizer->authorize($ctx, 'page.publish');
        DB::table('page_refreshes')->where('site_id', $ctx->siteId)->where('status', 'failed')
            ->update(['status' => 'pending', 'attempts' => 0, 'updated_at' => DB::raw('now()')]);

        return $this->run($ctx->siteId);
    }

    /**
     * Open refreshes of a site (pending and failed), for the design screens.
     *
     * @return array{pending: int, failed: int, items: list<array{id: string, page: string, path: string|null, cause: string, status: string, attempts: int, error: string|null, updatedAt: string}>}
     */
    public function status(SiteContext $ctx): array
    {
        $this->authorizer->authorize($ctx, 'page.view');
        $rows = DB::table('page_refreshes as r')
            ->join('pages as p', fn ($j) => $j->on('p.site_id', '=', 'r.site_id')->on('p.id', '=', 'r.page_id'))
            ->leftJoin('reusable_components as c', 'c.id', '=', 'r.cause_id')
            ->where('r.site_id', $ctx->siteId)
            ->whereIn('r.status', ['pending', 'failed'])
            ->orderBy('r.created_at')
            ->limit(100)
            ->get(['r.id', 'p.title', 'p.path', 'r.cause_kind', 'r.cause_version', 'c.name as component', 'r.status', 'r.attempts', 'r.last_error', 'r.updated_at']);

        return [
            'pending' => $rows->where('status', 'pending')->count(),
            'failed' => $rows->where('status', 'failed')->count(),
            'items' => $rows->map(fn ($r) => [
                'id' => $r->id, 'page' => $r->title, 'path' => $r->path,
                'cause' => $r->cause_kind === 'menu' ? 'Navigation update' : ($r->cause_kind === 'tokens' ? "Design tokens v{$r->cause_version}" : "“{$r->component}” v{$r->cause_version}"),
                'status' => $r->status, 'attempts' => (int) $r->attempts, 'error' => $r->last_error, 'updatedAt' => Time::iso($r->updated_at),
            ])->values()->all(),
        ];
    }
}
