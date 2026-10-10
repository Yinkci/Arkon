<?php

namespace App\Arkon\Ai;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Revocable Arkon credentials for the two local connections:
 *   helper  runs a selected local provider for the editor's AI panel (one site),
 *   mcp     lets a native coding assistant read pages and submit proposals (one user, one site).
 * A token is shown once at pairing; only its SHA-256 hash is stored. It identifies the user
 * and site itself: tool arguments never choose them, and every call is authorised again
 * against the user's current role on that site.
 */
final class AiConnections
{
    public function __construct(private readonly Membership $membership, private readonly ProposalLedger $ledger) {}

    /** @return array{id: string, token: string} */
    public function create(string $siteId, string $userId, string $kind, string $name, string $provider = 'claude-code'): array
    {
        ProviderRegistry::validate($provider);
        $token = 'arkon_'.$kind.'_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Uuid::v7();
        DB::table('ai_connections')->insert([
            'id' => $id, 'site_id' => $siteId, 'user_id' => $userId, 'kind' => $kind, 'name' => $name, 'provider' => $provider, 'token_hash' => self::hash($token),
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
     * lease (nothing the old helper sends is admitted any more) and fail without another model attempt.
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
        DB::table('ai_connections')->where('id', $id)->whereNull('revoked_at')->update(['last_seen_at' => DB::raw('now()'), 'status' => Json::encode([...$status->toArray($this->providerForConnection($id)), 'websiteProtocol' => 3])]);
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
    public function helperStatus(string $siteId, string $provider = 'claude-code', ?string $userId = null): array
    {
        ProviderRegistry::validate($provider);
        $row = DB::table('ai_connections')->where('site_id', $siteId)->where('kind', 'helper')->where('provider', $provider)->whereNull('revoked_at')->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('site_members as sm')->whereColumn('sm.site_id', 'ai_connections.site_id')->whereColumn('sm.user_id', 'ai_connections.user_id')->whereIn('sm.role', ['owner', 'admin', 'editor']);
            })->whereNotNull('last_seen_at')->orderByDesc('last_seen_at')
            ->select(['*', DB::raw('last_seen_at >= now() - make_interval(secs => '.(int) config('arkon.ai.helper_stale_seconds').') as fresh'), DB::raw("to_char(last_seen_at, 'HH24:MI:SS') as seen_at")])->first();
        // Compared in the database: its clock and time zone, not PHP's.
        $fresh = $row && (bool) $row->fresh;
        $status = $row ? Json::toArray(Json::decode((string) $row->status)) : [];
        $name = ProviderRegistry::definitions()[$provider]['name'];
        $start = 'Start it in a terminal in the project folder with: php artisan arkon:ai-helper'.($provider === 'claude-code' ? '' : ' --provider='.$provider);

        return [
            'provider' => $provider,
            'providerName' => $name,
            'code' => $status['code'] ?? null,
            'version' => $status['version'] ?? $status['claudeVersion'] ?? null,
            'state' => ! $fresh ? 'disconnected' : (($status['ready'] ?? false) ? 'connected' : match ($status['code'] ?? '') {
                'CLAUDE_MISSING', 'PROVIDER_NOT_INSTALLED' => 'not_installed', 'CLAUDE_NOT_LOGGED_IN', 'PROVIDER_NOT_AUTHENTICATED' => 'not_authenticated', default => 'connection_error'
            }),
            'ready' => $fresh && ($status['ready'] ?? false) === true,
            'message' => match (true) {
                $row === null => "The local {$name} helper is not connected. {$start}",
                ! $fresh => "The local {$name} helper is not running (last seen at {$row->seen_at}). {$start}",
                default => (string) ($status['message'] ?? 'The helper is starting.'),
            },
            'websiteProtocol' => (int) ($status['websiteProtocol'] ?? 0),
            'claudeVersion' => $status['claudeVersion'] ?? null,
            'lastSeenAt' => $row?->last_seen_at,
        ];
    }

    /** @return list<object> connections of a site (no hashes) */
    public function list(?string $siteId = null): array
    {
        return DB::table('ai_connections')->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('created_at')->get(['id', 'site_id', 'user_id', 'kind', 'name', 'provider', 'created_at', 'last_seen_at', 'revoked_at'])->all();
    }

    /** User-owned helper availability for a specific workflow; never consults saved preferences. */
    public function forContext(SiteContext $ctx, string $capability = 'page_proposal'): array
    {
        $role = app(Authorizer::class)->authorize($ctx, 'page.view');
        if (! Permissions::allows($role, 'page.edit')) {
            return ['selectionState' => 'unavailable', 'ready' => false, 'provider' => null, 'providerName' => null, 'message' => 'Only members who can edit this site can use AI.', 'providers' => []];
        }
        $providers = [];
        foreach (ProviderRegistry::definitions() as $id => $definition) {
            $status = $this->helperStatus($ctx->siteId, $id, $ctx->userId);
            if (! in_array($capability, $definition['capabilities'], true) || ($status['ready'] && $capability === 'website_proposal' && $status['websiteProtocol'] < 3)) {
                $status['ready'] = false;
                $status['message'] = 'This helper needs an update before it can handle this task. Restart its provider-specific helper.';
            }
            $providers[] = [...$definition, ...$status];
        }
        $ready = array_values(array_filter($providers, fn ($p) => $p['ready']));
        $automatic = count($ready) === 1 ? $ready[0] : null;

        return [
            'selectionState' => $automatic ? 'automatic' : (count($ready) ? 'choice_required' : 'unavailable'),
            'ready' => $automatic !== null,
            'provider' => $automatic['id'] ?? null,
            'providerName' => $automatic['name'] ?? null,
            'message' => $automatic ? 'Using '.$automatic['name'].'. '.$automatic['message'] : (count($ready) ? 'Choose a connected AI for this task.' : 'No AI is connected and ready. Connect an AI to continue.'),
            'claudeVersion' => $automatic['claudeVersion'] ?? null,
            'lastSeenAt' => $automatic['lastSeenAt'] ?? null,
            'checkedAt' => DB::selectOne('SELECT now() AS checked_at')->checked_at,
            'providers' => $providers,
        ];
    }

    /** Called only after exact-retry lookup. Explicit selections and automatic hints never fall back. */
    public function selectForRequest(SiteContext $ctx, array $input, string $capability): string
    {
        $mode = $input['selectionMode'] ?? (isset($input['provider']) ? 'explicit' : 'automatic');
        if (! in_array($mode, ['automatic', 'explicit'], true)) {
            throw new ValidationException('Choose a valid AI selection method.');
        }
        $availability = $this->forContext($ctx, $capability);
        if ($mode === 'automatic') {
            if ($availability['selectionState'] !== 'automatic') {
                throw new AiException($availability['selectionState'] === 'choice_required' ? 'PROVIDER_CHOICE_REQUIRED' : 'PROVIDER_UNAVAILABLE', $availability['message']);
            }
            if (isset($input['provider']) && $input['provider'] !== $availability['provider']) {
                throw new AiException('PROVIDER_SELECTION_CHANGED', 'Connections changed. Review the AI provider before starting this task.');
            }

            return $availability['provider'];
        }
        if (! isset($input['provider'])) {
            throw new AiException('PROVIDER_CHOICE_REQUIRED', 'Choose a connected AI for this task.');
        }
        $provider = ProviderRegistry::validate((string) $input['provider']);
        foreach ($availability['providers'] as $p) {
            if ($p['id'] === $provider && $p['ready']) {
                return $provider;
            }
        }
        throw new AiException('PROVIDER_UNAVAILABLE', 'The selected AI is no longer available. Review your connections and choose again.');
    }

    public function providerForConnection(string $id): string
    {
        return ProviderRegistry::validate((string) DB::table('ai_connections')->where('id', $id)->value('provider'));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
