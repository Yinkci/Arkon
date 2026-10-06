<?php

namespace App\Arkon\Ai;

use App\Arkon\Errors\ConflictException;
use App\Arkon\Schema\Operations;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * ai_proposals as a durable request record, shared by both entry points:
 *
 *   panel:  queued → running (leased to one helper) → proposed | empty | failed | cancelled
 *   mcp:    proposed | empty (validated before the row exists)
 *   then:   proposed → applied (a save claims it) | discarded
 *
 * Creation is atomic: a per-site advisory lock is taken before the request-key lookup, so
 * identical concurrent requests replay instead of colliding, and a key is bound to its page,
 * prompt, base version (and, for MCP, the proposal itself). Results are only written while the
 * writer still holds the run's lease, so cancelled, expired or superseded runs cannot land late.
 */
final class ProposalLedger
{
    private const COLUMNS = ['id', 'site_id', 'page_id', 'created_by', 'source', 'prompt', 'base_version', 'status', 'summary', 'details',
        'operations', 'error_code', 'error_message', 'attempts', 'lease_token', 'lease_expires_at', 'started_at', 'created_at', 'resolved_at', 'connection_id'];

    /** The fingerprint of parsed operations, compared when a save claims to apply a proposal. */
    public static function fingerprint(array $parsedOperations): string
    {
        return Fingerprint::of(['kind' => 'ai-proposal', 'operations' => $parsedOperations]);
    }

    /** Operations as a save will see them: sent to the browser as JSON, sent back, parsed. */
    public static function operationsFingerprint(array $operations): string
    {
        return self::fingerprint(Operations::parse(Json::decode(Json::encode($operations)))[0]);
    }

    public static function requestFingerprint(array $request): string
    {
        return Fingerprint::of(['kind' => 'ai-request', ...$request]);
    }

    /** Serialises request creation per site for the rest of the transaction. */
    public function lockSite(string $siteId): void
    {
        DB::select("select pg_advisory_xact_lock(hashtextextended('arkon-ai:' || ?, 0))", [$siteId]);
    }

    /**
     * Inside the site lock: the row this key already created, or null. The same key with a
     * different request (page, prompt, base version, proposal) is refused, never replayed.
     *
     * @throws ConflictException
     */
    public function existing(SiteContext $ctx, string $requestKey, string $pageId, string $fingerprint): ?object
    {
        $row = DB::table('ai_proposals')->where('site_id', $ctx->siteId)->where('created_by', $ctx->userId)->where('request_key', $requestKey)->first();
        if ($row !== null && ($row->page_id !== $pageId || $row->request_fingerprint !== $fingerprint)) {
            throw new ConflictException('This request key was already used for a different AI request. Send the new request with a new key.');
        }

        return $row;
    }

    /**
     * Inside the site lock: how often and how many requests may start. Claude Code enforces the
     * subscription's own usage limits; these only bound what Arkon starts.
     *
     * @throws AiException RATE_LIMITED | LIMIT_REACHED
     */
    public function checkLimits(SiteContext $ctx, bool $startsRun): void
    {
        $limits = config('arkon.ai');
        $recent = DB::table('ai_proposals')->where('created_by', $ctx->userId)->where('created_at', '>', DB::raw("now() - interval '1 minute'"))->count();
        if ($recent >= $limits['per_user_per_minute']) {
            throw new AiException(AiException::RATE_LIMITED, "You can send {$limits['per_user_per_minute']} AI requests a minute. Wait a moment and try again.");
        }
        $today = DB::table('ai_proposals')->where('site_id', $ctx->siteId)->where('created_at', '>=', DB::raw("date_trunc('day', now())"))->count();
        if ($today >= $limits['per_site_per_day']) {
            throw new AiException(AiException::LIMIT_REACHED, "This site has reached its limit of {$limits['per_site_per_day']} AI requests for today.");
        }
        if ($startsRun && DB::table('ai_proposals')->where('site_id', $ctx->siteId)->whereIn('status', ['queued', 'running'])->count() >= $limits['max_active_per_site']) {
            throw new AiException(AiException::LIMIT_REACHED, "{$limits['max_active_per_site']} AI requests are already waiting or running for this site. Wait for one to finish or cancel it.");
        }
    }

    /** Inside the site lock: the user's earlier waiting or running request for this page is superseded. */
    public function supersede(SiteContext $ctx, string $pageId): int
    {
        return DB::table('ai_proposals')->where('site_id', $ctx->siteId)->where('page_id', $pageId)->where('created_by', $ctx->userId)
            ->where('source', 'panel')->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'cancelled', 'error_code' => AiException::SUPERSEDED, 'error_message' => 'Replaced by a newer request.',
                'lease_token' => null, 'lease_expires_at' => null, 'resolved_at' => DB::raw('now()')]);
    }

    public function insert(array $row): string
    {
        $id = Uuid::v7();
        DB::table('ai_proposals')->insert(['id' => $id, ...$row]);

        return $id;
    }

    public function find(SiteContext $ctx, string $pageId, string $id): ?object
    {
        return Uuid::isValid($id)
            ? DB::table('ai_proposals')->where('id', $id)->where('site_id', $ctx->siteId)->where('page_id', $pageId)->where('created_by', $ctx->userId)->first(self::COLUMNS)
            : null;
    }

    /** The user's requests on this page that are still in progress or waiting for review. */
    public function reviewable(SiteContext $ctx, string $pageId): array
    {
        return DB::table('ai_proposals')->where('site_id', $ctx->siteId)->where('page_id', $pageId)->where('created_by', $ctx->userId)
            ->whereIn('status', ['queued', 'running', 'proposed', 'empty'])->orderBy('created_at')->limit(20)->get(self::COLUMNS)->all();
    }

    // ── Execution (local helper) ────────────────────────────────────────────

    /**
     * Expired waits fail; runs whose lease expired (helper stopped, or fenced) are queued again
     * while attempts remain, or fail after the last one. A lease is expired from the moment
     * lease_expires_at <= now() (database clock), exactly when renew/finish stop accepting it.
     */
    public function recover(string $siteId): int
    {
        $ai = config('arkon.ai');
        $expired = DB::update(
            "UPDATE ai_proposals SET status = 'failed', error_code = ?, error_message = ?, resolved_at = now()
             WHERE site_id = ? AND status = 'queued' AND greatest(created_at, coalesce(started_at, created_at)) < now() - make_interval(secs => ?)",
            [AiException::EXPIRED, 'No helper picked this request up in time. Start the helper (php artisan arkon:ai-helper) and ask again.', $siteId, $ai['queue_timeout_seconds']],
        );
        $interrupted = DB::update(
            'UPDATE ai_proposals SET '.self::REQUEUE." WHERE site_id = ? AND status = 'running' AND lease_expires_at <= now()",
            [...self::requeueBindings('The helper stopped while working on this request. Ask again.'), $siteId],
        );

        return $expired + $interrupted;
    }

    /**
     * Revoking a connection fences its active runs in the same transaction: they lose their lease
     * (so nothing the old helper sends is admitted) and are queued again while attempts remain.
     */
    public function fenceConnection(string $connectionId): int
    {
        return DB::update(
            'UPDATE ai_proposals SET '.self::REQUEUE." WHERE connection_id = ? AND status = 'running'",
            [...self::requeueBindings('The helper working on this request was disconnected. Ask again.'), $connectionId],
        );
    }

    /** Atomically leases the oldest queued panel request of the site to one helper, if that helper may work. */
    public function claim(string $siteId, string $connectionId): ?object
    {
        $lease = Uuid::v7();
        $rows = DB::select(
            "UPDATE ai_proposals SET status = 'running', lease_token = ?, lease_expires_at = now() + make_interval(secs => ?),
                attempts = attempts + 1, started_at = now(), connection_id = ?
             WHERE id = (SELECT id FROM ai_proposals WHERE site_id = ? AND status = 'queued' AND source = 'panel'
                         ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1)
               AND ".self::connectionEligible('?').'
             RETURNING id, site_id, page_id, created_by, prompt, base_version, attempts, lease_token',
            [$lease, (int) config('arkon.ai.lease_seconds'), $connectionId, $siteId, $connectionId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Extends the lease while Claude works. False when the run was cancelled, superseded, taken
     * over, its lease already expired (an expired worker cannot revive it), its connection was
     * revoked, or the helper's or the requester's right to edit is gone; the runner then stops.
     */
    public function renew(string $id, string $lease): bool
    {
        return DB::update(
            'UPDATE ai_proposals SET lease_expires_at = now() + make_interval(secs => ?) WHERE id = ? AND '.self::HOLDS_LEASE,
            [(int) config('arkon.ai.lease_seconds'), $id, $lease],
        ) === 1;
    }

    /**
     * Admits a run's result only under the same conditions as renew(), in the same statement:
     * a late result from a cancelled, expired, fenced or revoked run is rejected even if the
     * runner ignored the request to stop. The requester stays the proposal's owner.
     *
     * @param  array{operations: list<array>, changes: list<string>, warnings: list<string>, summary: string, notes: list<string>}  $compiled
     */
    public function finish(string $id, string $lease, array $compiled): bool
    {
        return DB::table('ai_proposals')->where('id', $id)->whereRaw(self::HOLDS_LEASE, [$lease])
            ->update([...$this->proposalColumns($compiled), 'lease_token' => null, 'lease_expires_at' => null]) === 1;
    }

    public function failRun(string $id, string $lease, string $code, string $message): bool
    {
        return DB::table('ai_proposals')->where('id', $id)->whereRaw(self::HOLDS_LEASE, [$lease])
            ->update(['status' => 'failed', 'error_code' => $code, 'error_message' => $message, 'lease_token' => null, 'lease_expires_at' => null, 'resolved_at' => DB::raw('now()')]) === 1;
    }

    /** Back to the queue while attempts remain (a later claim gets a fresh lease token), otherwise failed. */
    private const REQUEUE = "status = CASE WHEN attempts < ? THEN 'queued' ELSE 'failed' END,
                error_code = CASE WHEN attempts < ? THEN NULL ELSE ? END,
                error_message = CASE WHEN attempts < ? THEN NULL ELSE ? END,
                resolved_at = CASE WHEN attempts < ? THEN NULL ELSE now() END,
                lease_token = NULL, lease_expires_at = NULL";

    private static function requeueBindings(string $message): array
    {
        $max = (int) config('arkon.ai.max_attempts');

        return [$max, $max, AiException::INTERRUPTED, $max, $message, $max];
    }

    /**
     * A run may continue or record an outcome only while: it is still running under this lease
     * token, the lease has not expired (database clock), its connection is not revoked and its
     * user may still edit the site, and the requester may still edit the site.
     */
    private const HOLDS_LEASE = "lease_token = ? AND status = 'running' AND lease_expires_at > now()
        AND EXISTS (SELECT 1 FROM ai_connections c JOIN site_members cm ON cm.site_id = c.site_id AND cm.user_id = c.user_id
                    WHERE c.id = ai_proposals.connection_id AND c.site_id = ai_proposals.site_id AND c.revoked_at IS NULL
                      AND cm.role IN ('owner', 'admin', 'editor'))
        AND EXISTS (SELECT 1 FROM site_members m WHERE m.site_id = ai_proposals.site_id AND m.user_id = ai_proposals.created_by
                      AND m.role IN ('owner', 'admin', 'editor'))";

    /** The helper connection (bound as $placeholder) is active and its user may edit the site it serves. */
    private static function connectionEligible(string $placeholder): string
    {
        return "EXISTS (SELECT 1 FROM ai_connections c JOIN site_members cm ON cm.site_id = c.site_id AND cm.user_id = c.user_id
                        WHERE c.id = {$placeholder} AND c.revoked_at IS NULL AND c.kind = 'helper' AND cm.role IN ('owner', 'admin', 'editor'))";
    }

    /** Columns for a validated proposal (from a run or an MCP submission). */
    public function proposalColumns(array $compiled): array
    {
        return [
            'status' => $compiled['operations'] === [] ? 'empty' : 'proposed',
            'summary' => $compiled['summary'],
            'details' => Json::encode(['notes' => $compiled['notes'], 'changes' => $compiled['changes'], 'warnings' => $compiled['warnings']]),
            'operations' => Json::encode($compiled['operations']),
            'operations_fingerprint' => self::operationsFingerprint($compiled['operations']),
        ];
    }

    // ── Review ──────────────────────────────────────────────────────────────

    /** The creator's own request on this page, locked. */
    public function lockOwn(SiteContext $ctx, string $pageId, string $proposalId): ?object
    {
        return Uuid::isValid($proposalId)
            ? DB::table('ai_proposals')->where('id', $proposalId)->where('site_id', $ctx->siteId)->where('page_id', $pageId)->where('created_by', $ctx->userId)->lockForUpdate()->first()
            : null;
    }

    public function cancel(string $id): void
    {
        DB::table('ai_proposals')->where('id', $id)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'cancelled', 'error_code' => AiException::CANCELLED, 'error_message' => 'Cancelled.', 'lease_token' => null, 'lease_expires_at' => null, 'resolved_at' => DB::raw('now()'),
        ]);
    }

    /**
     * Inside a save transaction (after the draft's version check): the saved operations
     * must be exactly the proposal's, based on the version it was made for.
     *
     * @param  list<array>  $parsedOperations  the save's operations as parsed by Operations::parse
     *
     * @throws AiException STALE_PROPOSAL
     */
    public function claimForSave(SiteContext $ctx, string $pageId, string $proposalId, int $baseVersion, array $parsedOperations): object
    {
        $row = $this->lockOwn($ctx, $pageId, $proposalId);
        $problem = match (true) {
            $row === null => 'This AI proposal does not exist for this page.',
            $row->status === 'applied' => 'This AI proposal has already been applied.',
            $row->status !== 'proposed' => 'This AI proposal is not waiting to be applied (it was discarded, cancelled or has no changes).',
            (int) $row->base_version !== $baseVersion => 'The draft changed after this AI proposal was made. Ask again.',
            $row->operations_fingerprint !== self::fingerprint($parsedOperations) => 'These changes are not the ones the AI proposed.',
            default => null,
        };
        if ($problem !== null) {
            throw new AiException(AiException::STALE_PROPOSAL, $problem);
        }

        return $row;
    }

    public function markApplied(string $id, string $revisionId): void
    {
        DB::table('ai_proposals')->where('id', $id)->update(['status' => 'applied', 'applied_revision_id' => $revisionId, 'resolved_at' => DB::raw('now()')]);
    }

    public function markDiscarded(string $id): void
    {
        DB::table('ai_proposals')->where('id', $id)->update(['status' => 'discarded', 'resolved_at' => DB::raw('now()')]);
    }
}
