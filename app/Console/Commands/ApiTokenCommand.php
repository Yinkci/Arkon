<?php

namespace App\Console\Commands;

use App\Arkon\Api\ApiTokens;
use App\Arkon\Api\Scopes;
use App\Arkon\Sites\SiteContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Personal access tokens for the public API (/api/v1), from the command line (the admin has
 * the same under Settings → Developer):
 *   arkon:api-token create you@example.com --name="Blog frontend" --scopes=read:posts
 *   arkon:api-token list you@example.com
 *   arkon:api-token revoke you@example.com <token id>
 * A token is shown once. It acts as that member on that site, limited to its scopes.
 */
class ApiTokenCommand extends Command
{
    protected $signature = 'arkon:api-token
        {action : create, list or revoke}
        {email : The member the token acts as}
        {id? : Token id (revoke)}
        {--name= : Where the token is used (create)}
        {--scopes= : Comma-separated scopes (create); see --help for the list}
        {--expires= : Expiry date, e.g. 2027-01-31 (create; optional)}
        {--site= : Site id (when the account belongs to several sites)}';

    protected $description = 'Create, list or revoke personal access tokens for the public API';

    public function handle(ApiTokens $tokens): int
    {
        $user = DB::table('users')->whereRaw('lower(email) = ?', [mb_strtolower((string) $this->argument('email'))])->first(['id', 'email']);
        $sites = $user ? DB::table('site_members')->where('user_id', $user->id)->when($this->option('site'), fn ($q, $s) => $q->where('site_id', $s))->pluck('site_id') : collect();
        if ($sites->count() !== 1) {
            $this->error(! $user ? 'No Arkon account with that email.' : ($sites->isEmpty() ? 'That account is not a member of that site.' : 'The account belongs to several sites; pass --site.'));

            return self::FAILURE;
        }
        $ctx = new SiteContext($sites->first(), $user->id, 'cli');

        return match ($this->argument('action')) {
            'create' => $this->create($tokens, $ctx),
            'list' => $this->list($tokens, $ctx),
            'revoke' => $this->revoke($tokens, $ctx),
            default => $this->fail('Choose create, list or revoke.'),
        };
    }

    private function create(ApiTokens $tokens, SiteContext $ctx): int
    {
        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('scopes')))));
        if ($scopes === []) {
            $this->error('Choose scopes with --scopes. Available: '.implode(', ', array_keys(Scopes::all())));

            return self::FAILURE;
        }
        $created = $tokens->create($ctx, (string) ($this->option('name') ?: 'Command line'), $scopes, $this->option('expires'));
        $this->info("Created \"{$created['item']['name']}\" ({$created['item']['id']}) with ".implode(', ', $scopes).'.');
        $this->line('Copy the token now; it is not shown again:');
        $this->line($created['token']);

        return self::SUCCESS;
    }

    private function list(ApiTokens $tokens, SiteContext $ctx): int
    {
        $this->table(['Id', 'Name', 'Token', 'Scopes', 'Last used', 'Expires', 'State'], array_map(fn ($t) => [
            $t['id'], $t['name'], $t['hint'], implode(' ', $t['scopes']), $t['lastUsedAt'] ?? 'never', $t['expiresAt'] ?? 'never', $t['state'],
        ], $tokens->list($ctx)));

        return self::SUCCESS;
    }

    private function revoke(ApiTokens $tokens, SiteContext $ctx): int
    {
        $tokens->revoke($ctx, (string) $this->argument('id'));
        $this->info('Revoked. Requests with that token are refused from now on.');

        return self::SUCCESS;
    }
}
