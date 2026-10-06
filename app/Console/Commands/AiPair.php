<?php

namespace App\Console\Commands;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pairs a local Claude Code connection with an Arkon user and site:
 *   --helper  the helper for the editor's AI panel; the token is saved to a git-ignored file
 *             that `php artisan arkon:ai-helper` reads (an earlier helper token of the same
 *             user and site is revoked)
 *   --mcp     Claude Code in VS Code; prints the token once, with the `claude mcp add` command
 * The token carries the user and site; revoke it with `php artisan arkon:ai-revoke <id>`.
 */
class AiPair extends Command
{
    protected $signature = 'arkon:ai-pair
        {email : Your Arkon account (the proposals are yours; you must be able to edit pages)}
        {--helper : Pair the local helper used by the editor\'s AI panel}
        {--mcp : Pair Claude Code in VS Code (MCP server)}
        {--site= : Site id (required when the account belongs to several sites)}
        {--name= : A name for this connection}';

    protected $description = 'Pair a local Claude Code connection (helper or MCP) with an Arkon user and site';

    public function handle(AiConnections $connections, Membership $membership): int
    {
        if ($this->option('helper') === $this->option('mcp')) {
            $this->error('Choose one: --helper (AI panel) or --mcp (Claude Code in VS Code).');

            return self::FAILURE;
        }
        $kind = $this->option('helper') ? 'helper' : 'mcp';
        $user = DB::table('users')->whereRaw('lower(email) = ?', [mb_strtolower((string) $this->argument('email'))])->first(['id', 'email']);
        if (! $user) {
            $this->error('No Arkon account with that email.');

            return self::FAILURE;
        }
        $sites = DB::table('site_members as m')->join('sites as s', 's.id', '=', 'm.site_id')->where('m.user_id', $user->id)
            ->when($this->option('site'), fn ($q, $site) => $q->where('s.id', $site))->get(['s.id', 's.name']);
        if ($sites->count() !== 1) {
            $this->error($sites->isEmpty() ? 'That account is not a member of that site.' : 'The account belongs to several sites; pass --site with one of: '.$sites->map(fn ($s) => "{$s->id} ({$s->name})")->join(', '));

            return self::FAILURE;
        }
        $site = $sites->first();
        if (! Permissions::allows($membership->roleOf($site->id, $user->id), 'page.edit')) {
            $this->error('That account cannot edit pages on this site (owner, admin or editor needed).');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: ($kind === 'helper' ? 'AI panel helper on '.gethostname() : 'Claude Code in VS Code'));
        if ($kind === 'helper') {
            // The previous helper of this user and site is revoked (and its runs fenced).
            DB::table('ai_connections')->where('site_id', $site->id)->where('user_id', $user->id)->where('kind', 'helper')->whereNull('revoked_at')
                ->pluck('id')->each(fn ($id) => $connections->revoke($id));
        }
        $created = $connections->create($site->id, $user->id, $kind, $name);
        $this->info("Paired \"{$name}\" ({$created['id']}) for {$user->email} on {$site->name}.");

        if ($kind === 'helper') {
            $file = (string) config('arkon.ai.helper_token_file');
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }
            file_put_contents($file, $created['token']);
            $this->line("The helper's token is saved in {$file} (git-ignored). Start the helper with:");
            $this->line('  php artisan arkon:ai-helper');

            return self::SUCCESS;
        }

        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $this->line('Register Arkon in Claude Code (run once in a terminal; the token is shown only now):');
        $this->newLine();
        $this->line("  claude mcp add arkon --scope user -e ARKON_MCP_TOKEN={$created['token']} -- \"{$php}\" \"{$artisan}\" arkon:mcp");
        $this->newLine();
        $this->line('Then restart Claude Code in VS Code and ask, for example: "Use Arkon to build a homepage for a landscaping business on the Home page."');
        $this->line("Revoke this connection any time with: php artisan arkon:ai-revoke {$created['id']}");

        return self::SUCCESS;
    }
}
