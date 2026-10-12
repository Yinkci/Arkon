<?php

namespace App\Arkon\Ai\Images;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generated images of website proposals, one row per placement (ai_image_jobs):
 *
 *   queued → running (leased to one image-capable helper) → completed | failed | cancelled
 *   failed → queued only through retry(), an explicit user action with its own request key
 *
 * The asset id is reserved when the layout is proposed and is unique, so a placement is imported
 * at most once: a lost response, a repeated click or a confirmation retry finds the same job, and a
 * completed job is never generated again. An interrupted job (lease expired, helper revoked) fails;
 * nothing starts another generation automatically.
 */
final class ImageJobs
{
    public function __construct(private readonly Transactions $tx, private readonly Authorizer $auth, private readonly AiConnections $connections, private readonly AuditLog $audit) {}

    /** Inside the transaction that records the layout: one queued job per generated placement. */
    public function createFor(string $proposalId, string $siteId, string $userId, string $provider, array $images): void
    {
        foreach ($images as $image) {
            DB::table('ai_image_jobs')->insertOrIgnore([
                'id' => Uuid::v7(), 'site_id' => $siteId, 'proposal_id' => $proposalId, 'asset_id' => $image['assetId'], 'created_by' => $userId,
                'provider' => $provider, 'spec' => Json::encode($image['spec']), 'status' => 'queued',
            ]);
        }
    }

    /** Running jobs whose lease expired fail (no new attempt); queued jobs nobody picked up in time fail. */
    public function recover(string $siteId): int
    {
        $expired = DB::update(
            "UPDATE ai_image_jobs SET status = 'failed', error_code = ?, error_message = ?, completed_at = now()
             WHERE site_id = ? AND status = 'queued' AND created_at < now() - make_interval(secs => ?) AND coalesce(started_at, created_at) < now() - make_interval(secs => ?)",
            [AiException::EXPIRED, 'No image-capable helper picked this image up in time. Check AI Connections, then retry the image.', $siteId, (int) config('arkon.ai.queue_timeout_seconds'), (int) config('arkon.ai.queue_timeout_seconds')],
        );
        $interrupted = DB::update(
            "UPDATE ai_image_jobs SET status = 'failed', error_code = ?, error_message = ?, completed_at = now(), lease_token = NULL, lease_expires_at = NULL
             WHERE site_id = ? AND status = 'running' AND lease_expires_at <= now()",
            [AiException::INTERRUPTED, 'The helper stopped while generating this image. Nothing was generated again automatically; retry the image when the helper is running.', $siteId],
        );

        return $expired + $interrupted;
    }

    /** Leases the oldest queued image of this helper's site, provider and user, for a proposal still under review. */
    public function claimNext(object $connection): ?object
    {
        return $this->tx->run(function () use ($connection) {
            $this->recover($connection->site_id);
            $lease = Uuid::v7();
            $rows = DB::select(
                "UPDATE ai_image_jobs SET status = 'running', lease_token = ?, lease_expires_at = now() + make_interval(secs => ?), attempts = attempts + 1,
                    started_at = now(), heartbeat_at = now(), connection_id = ?, activity = 'generating', error_code = NULL, error_message = NULL
                 WHERE id = (SELECT j.id FROM ai_image_jobs j JOIN ai_proposals p ON p.id = j.proposal_id
                             WHERE j.site_id = ? AND j.status = 'queued' AND p.status = 'proposed' AND j.provider = (SELECT provider FROM ai_connections WHERE id = ?)
                             ORDER BY j.created_at FOR UPDATE OF j SKIP LOCKED LIMIT 1)
                   AND ".self::eligible('?').'
                 RETURNING id, site_id, proposal_id, asset_id, created_by, provider, spec, attempts, lease_token',
                [$lease, (int) config('arkon.ai.lease_seconds'), $connection->id, $connection->site_id, $connection->id, $connection->id],
            );

            return $rows[0] ?? null;
        });
    }

    /**
     * Generates and imports one leased image. The lease is renewed while the provider works and checked
     * again before every write, so a cancelled, discarded or revoked job cannot land late.
     */
    public function execute(object $job, ImageGenerator $generator, GeneratedImageImporter $importer, ?callable $alive = null): string
    {
        $ctx = new SiteContext($job->site_id, $job->created_by, 'ai');
        $spec = Json::decode($job->spec);
        try {
            $this->auth->authorize($ctx, 'page.edit');
            // A crash after the import but before completion: the asset exists, so it is never generated again.
            if (DB::table('media_assets')->where('site_id', $job->site_id)->where('id', $job->asset_id)->exists()) {
                return $this->complete($job) ? 'completed' : 'lost';
            }
            $keepGoing = function () use ($job, $alive) {
                if ($alive) {
                    $alive();
                }

                return $this->renew($job->id, $job->lease_token);
            };
            $image = $generator->generate(ImageSpec::prompt($spec), ImageSpec::size($spec['aspectRatio']), $keepGoing);
            if (! $this->activity($job->id, $job->lease_token, 'optimizing')) {
                return 'lost';
            }
            $importer->import($ctx, $job->asset_id, $image, $spec, ['proposalId' => $job->proposal_id, 'jobId' => $job->id, 'generatedAt' => now()->toIso8601String()]);

            return $this->complete($job) ? 'completed' : 'lost';
        } catch (\Throwable $e) {
            if (! $e instanceof AiException) {
                Log::error('Image generation failed', ['exception' => $e, 'job' => $job->id]);
            }
            $code = $e instanceof AiException ? $e->code() : 'IMAGE_FAILED';
            $message = $e instanceof AiException ? $e->getMessage() : 'The image could not be generated or imported. Check the server log.';

            return DB::table('ai_image_jobs')->where('id', $job->id)->whereRaw(self::HOLDS_LEASE, [$job->lease_token])
                ->update(['status' => 'failed', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'lease_token' => null, 'lease_expires_at' => null, 'completed_at' => DB::raw('now()'), 'activity' => null]) === 1 ? 'failed' : 'lost';
        }
    }

    private function complete(object $job): bool
    {
        return DB::table('ai_image_jobs')->where('id', $job->id)->whereRaw(self::HOLDS_LEASE, [$job->lease_token])
            ->update(['status' => 'completed', 'activity' => null, 'lease_token' => null, 'lease_expires_at' => null, 'completed_at' => DB::raw('now()')]) === 1;
    }

    public function renew(string $id, string $lease): bool
    {
        return DB::update('UPDATE ai_image_jobs SET heartbeat_at = now(), lease_expires_at = now() + make_interval(secs => ?) WHERE id = ? AND '.self::HOLDS_LEASE, [(int) config('arkon.ai.lease_seconds'), $id, $lease]) === 1;
    }

    public function activity(string $id, string $lease, string $activity): bool
    {
        return DB::update('UPDATE ai_image_jobs SET activity = ?, heartbeat_at = now() WHERE id = ? AND '.self::HOLDS_LEASE, [$activity, $id, $lease]) === 1;
    }

    /**
     * An explicit image-only retry of a failed placement: the layout is kept, nothing else runs again.
     * The same request key replays; a queued, running or completed job is returned as it is.
     */
    public function retry(SiteContext $ctx, string $assetId, array $input): array
    {
        $v = Input::validate($input, ['requestKey' => Input::requestKeyRule()]);

        return $this->tx->run(function () use ($ctx, $assetId, $v) {
            $this->auth->authorize($ctx, 'page.edit');
            $job = DB::table('ai_image_jobs')->where('site_id', $ctx->siteId)->where('created_by', $ctx->userId)->where('asset_id', Input::id($assetId, 'Generated image'))->lockForUpdate()->first()
                ?? throw new NotFoundException('Generated image');
            $key = Fingerprint::of(['kind' => 'image.retry', 'job' => $job->id, 'requestKey' => $v['requestKey']]);
            if ($job->status !== 'failed' || $job->retry_key === $key) {
                return $this->view($job);
            }
            $proposal = DB::table('ai_proposals')->where('id', $job->proposal_id)->first(['status']);
            if ($proposal?->status !== 'proposed') {
                throw new ConflictException('The proposal that requested this image is no longer under review, so the image cannot be generated again.');
            }
            $available = $this->connections->forContext($ctx, 'image_generation');
            $ready = array_filter($available['providers'], fn ($p) => $p['id'] === $job->provider && $p['ready']);
            if ($ready === []) {
                $provider = array_values(array_filter($available['providers'], fn ($p) => $p['id'] === $job->provider))[0] ?? null;
                throw new AiException(AiException::IMAGE_GENERATION_UNAVAILABLE, 'Image generation is not available, so the image was not retried. '.($provider['message'] ?? ''));
            }
            DB::table('ai_image_jobs')->where('id', $job->id)->update(['status' => 'queued', 'retry_key' => $key, 'error_code' => null, 'error_message' => null, 'completed_at' => null, 'activity' => null, 'created_at' => DB::raw('now()')]);
            $this->audit->forContext($ctx, 'website.ai.image.retry', 'site', $ctx->siteId, ['asset' => $job->asset_id, 'proposal' => $job->proposal_id]);

            return $this->view(DB::table('ai_image_jobs')->where('id', $job->id)->first());
        });
    }

    /** Stops waiting and running images of a proposal (discarded or applied without them). */
    public function cancelFor(string $proposalId): void
    {
        DB::table('ai_image_jobs')->where('proposal_id', $proposalId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'cancelled', 'activity' => null, 'lease_token' => null, 'lease_expires_at' => null, 'completed_at' => DB::raw('now()')]);
    }

    /** @return array<string, array> jobs by asset id, for any of these asset ids */
    public function byAsset(string $siteId, array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        return DB::table('ai_image_jobs')->where('site_id', $siteId)->whereIn('asset_id', array_values(array_unique($assetIds)))->get()
            ->mapWithKeys(fn ($j) => [$j->asset_id => $this->view($j)])->all();
    }

    public function view(object $j): array
    {
        $spec = Json::decode($j->spec);

        return ['assetId' => $j->asset_id, 'proposalId' => $j->proposal_id, 'provider' => $j->provider, 'status' => $j->status, 'activity' => $j->activity,
            'attempts' => (int) $j->attempts, 'error' => $j->error_message, 'errorCode' => $j->error_code, 'heartbeatAt' => $j->heartbeat_at, 'startedAt' => $j->started_at,
            'spec' => array_intersect_key($spec, array_flip(['subject', 'visualDirection', 'placement', 'aspectRatio', 'keepVisible', 'alt', 'title', 'pageTitle', 'pagePath', 'blockType']))];
    }

    private const HOLDS_LEASE = "lease_token = ? AND status = 'running' AND lease_expires_at > now()
        AND EXISTS (SELECT 1 FROM ai_proposals p WHERE p.id = ai_image_jobs.proposal_id AND p.status = 'proposed')
        AND EXISTS (SELECT 1 FROM ai_connections c JOIN site_members cm ON cm.site_id = c.site_id AND cm.user_id = c.user_id
                    WHERE c.id = ai_image_jobs.connection_id AND c.site_id = ai_image_jobs.site_id AND c.provider = ai_image_jobs.provider AND c.user_id = ai_image_jobs.created_by
                      AND c.revoked_at IS NULL AND cm.role IN ('owner', 'admin', 'editor'))";

    private static function eligible(string $placeholder): string
    {
        return "EXISTS (SELECT 1 FROM ai_connections c JOIN site_members cm ON cm.site_id = c.site_id AND cm.user_id = c.user_id
                        WHERE c.id = {$placeholder} AND c.site_id = ai_image_jobs.site_id AND c.provider = ai_image_jobs.provider AND c.user_id = ai_image_jobs.created_by
                          AND c.revoked_at IS NULL AND c.kind = 'helper' AND cm.role IN ('owner', 'admin', 'editor'))";
    }
}
