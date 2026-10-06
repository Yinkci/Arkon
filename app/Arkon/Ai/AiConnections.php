<?php

namespace App\Arkon\Ai;

use App\Arkon\Sites\Membership;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Revocable Arkon credentials for the two local connections:
 *   helper  runs Claude Code for the editor's AI panel (one site),
 *   mcp     lets Claude Code in VS Code read pages and submit proposals (one user, one site).
 * A token is shown once at pairing; only its SHA-256 hash is stored. It identifies the user
 * and site itself: tool arguments never choose them, and every call is authorised again
 * against the user's current role on that site.
 */
final class AiConnections
{
    public function __construct(private readonly Membership $membership, private readonly ProposalLedger $ledger) {}

    /** @return array{id: string, token: string} */
    public function create(string $siteId, string $userId, string $kind, string $name): array
    {
        $token = 'arkon_'.$kind.'_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Uuid::v7();
        DB::table('ai_connections')->insert([
            'id' => $id, 'site_id' => $siteId, 'user_id' => $userId, 'kind' => $kind, 'name' => $name, 'token_hash' => self::hash($token),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /** The active connection for a token of this kind, whose user is still a member of its site. */
    public function resolve(?string $token, string $kind): ?object
    {
        if (! is_string($token) || $token === '') {
            return null;
        }
        $row = DB::table('ai_connections')->where('token_hash', self::hash(trim($token)))->where('kind', $kind)->whereNull('revoked_at')->first();

        return $row && $this->membership->roleOf($row->site_id, $row->user_id) !== null ? $row : null;
    }

    public function context(object $connection, string $via): SiteContext
    {
        return new SiteContext($connection->site_id, $connection->user_id, $via);
    }

    /**
     * Revokes a connection and, in the same transaction, fences the runs it holds: they lose their
     * lease (nothing the old helper sends is admitted any more) and go back to the queue.
     */
    public function revoke(string $id): bool
    {
        if (! Uuid::isValid($id)) {
            return false;
        }

        return DB::transaction(function () use ($id) {
            if (DB::table('ai_connections')->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => DB::raw('now()')]) !== 1) {
                return false;
            }
            $this->ledger->fenceConnection($id);

            return true;
        });
    }

    public function heartbeat(string $id, RunnerStatus $status): void
    {
        DB::table('ai_connections')->where('id', $id)->update(['last_seen_at' => DB::raw('now()'), 'status' => Json::encode($status->toArray())]);
    }

    public function touch(string $id): void
    {
        DB::table('ai_connections')->where('id', $id)->update(['last_seen_at' => DB::raw('now()')]);
    }

    /**
     * Whether a helper for this site reported recently, and what it said. No credentials.
     *
     * @return array{ready: bool, message: string, claudeVersion: ?string, lastSeenAt: ?string}
     */
    public function helperStatus(string $siteId): array
    {
        $row = DB::table('ai_connections')->where('site_id', $siteId)->where('kind', 'helper')->whereNull('revoked_at')
            ->whereNotNull('last_seen_at')->orderByDesc('last_seen_at')
            ->select(['*', DB::raw('last_seen_at >= now() - make_interval(secs => '.(int) config('arkon.ai.helper_stale_seconds').') as fresh'), DB::raw("to_char(last_seen_at, 'HH24:MI:SS') as seen_at")])->first();
        // Compared in the database: its clock and time zone, not PHP's.
        $fresh = $row && (bool) $row->fresh;
        $status = $row ? Json::toArray(Json::decode((string) $row->status)) : [];
        $start = 'Start it in a terminal in the project folder with: php artisan arkon:ai-helper';

        return [
            'ready' => $fresh && ($status['ready'] ?? false) === true,
            'message' => match (true) {
                $row === null => "The local Claude Code helper is not connected. {$start}",
                ! $fresh => "The local Claude Code helper is not running (last seen at {$row->seen_at}). {$start}",
                default => (string) ($status['message'] ?? 'The helper is starting.'),
            },
            'claudeVersion' => $status['claudeVersion'] ?? null,
            'lastSeenAt' => $row?->last_seen_at,
        ];
    }

    /** @return list<object> connections of a site (no hashes) */
    public function list(?string $siteId = null): array
    {
        return DB::table('ai_connections')->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('created_at')->get(['id', 'site_id', 'user_id', 'kind', 'name', 'created_at', 'last_seen_at', 'revoked_at'])->all();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
