<?php

namespace App\Arkon\Api;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Time;
use App\Arkon\Support\Uuid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Personal access tokens for the public API: one user on one site, with explicit scopes and an
 * optional expiry. The token is shown once; only its SHA-256 hash is stored. A token stops
 * working at once when it is revoked, expires, or its user leaves the site, and it never
 * grants more than the user's current role allows.
 *
 * (The AI helper and MCP connections have their own credentials, AiConnections: they carry
 * leases for local processes and are not API tokens.)
 */
final class ApiTokens
{
    public const PREFIX = 'arkon_pat_';

    public function __construct(private readonly Authorizer $auth, private readonly Membership $membership, private readonly AuditLog $audit) {}

    /** @return array{token: string, item: array} */
    public function create(SiteContext $ctx, mixed $name, mixed $scopes, mixed $expiresAt = null): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $v = Input::validate(['name' => $name, 'scopes' => $scopes, 'expiresAt' => $expiresAt], [
            'name' => ['required', 'string', 'max:100'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['string', 'distinct'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ]);
        if (! Scopes::valid($v['scopes'])) {
            throw new ValidationException('Unknown scope. Choose from: '.implode(', ', array_keys(Scopes::all())), [['path' => 'scopes', 'message' => 'Unknown scope']]);
        }
        $name = trim($v['name']);
        if ($name === '') {
            throw new ValidationException('Name the token after where it is used.', [['path' => 'name', 'message' => 'Name the token after where it is used.']]);
        }
        $token = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Uuid::v7();
        DB::table('api_tokens')->insert([
            'id' => $id, 'site_id' => $ctx->siteId, 'user_id' => $ctx->userId, 'name' => $name, 'token_hash' => self::hash($token),
            'token_hint' => substr($token, -4), 'scopes' => '{'.implode(',', $v['scopes']).'}',
            'expires_at' => isset($v['expiresAt']) ? CarbonImmutable::parse($v['expiresAt'])->utc() : null,
        ]);
        $this->audit->forContext($ctx, 'api.token.create', 'api_token', $id, ['name' => $name, 'scopes' => $v['scopes']]);

        return ['token' => $token, 'item' => $this->list($ctx, $id)[0]];
    }

    /** The signed-in user's tokens on this site (never the hash). */
    public function list(SiteContext $ctx, ?string $id = null): array
    {
        $this->auth->authorize($ctx, 'page.view');

        return DB::table('api_tokens')->where('site_id', $ctx->siteId)->where('user_id', $ctx->userId)->when($id, fn ($q) => $q->where('id', $id))
            ->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'hint' => '…'.$t->token_hint, 'scopes' => self::scopesOf($t),
                'createdAt' => Time::iso($t->created_at), 'lastUsedAt' => Time::iso($t->last_used_at), 'expiresAt' => Time::iso($t->expires_at),
                'revokedAt' => Time::iso($t->revoked_at),
                'state' => $t->revoked_at ? 'revoked' : ($t->expires_at && CarbonImmutable::parse($t->expires_at)->isPast() ? 'expired' : 'active'),
            ])->all();
    }

    /** Revokes one of the user's own tokens; owners and admins may revoke any token of the site. */
    public function revoke(SiteContext $ctx, string $id): void
    {
        $role = $this->auth->authorize($ctx, 'page.view');
        $id = Input::id($id, 'Token');
        $q = DB::table('api_tokens')->where('site_id', $ctx->siteId)->where('id', $id)->whereNull('revoked_at');
        if (! Permissions::allows($role, 'page.publish')) {
            $q->where('user_id', $ctx->userId);
        }
        if ($q->update(['revoked_at' => DB::raw('now()')]) !== 1) {
            throw new NotFoundException('Token');
        }
        $this->audit->forContext($ctx, 'api.token.revoke', 'api_token', $id);
    }

    /** The active token row, if the token is valid for this site and its user is still a member. */
    public function resolve(string $token, string $siteId): ?object
    {
        if (! str_starts_with($token, self::PREFIX) || strlen($token) > 200) {
            return null;
        }
        $row = DB::table('api_tokens')->where('token_hash', self::hash($token))->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', DB::raw('now()')))->first();
        if ($row === null || ! hash_equals($row->site_id, $siteId) || $this->membership->roleOf($row->site_id, $row->user_id) === null) {
            return null;
        }
        // At most one write a minute per token.
        DB::table('api_tokens')->where('id', $row->id)->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', DB::raw("now() - interval '1 minute'")))->update(['last_used_at' => DB::raw('now()')]);

        return $row;
    }

    /** @return list<string> */
    public static function scopesOf(object $row): array
    {
        return array_values(array_filter(explode(',', trim((string) $row->scopes, '{}'))));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
