<?php

namespace App\Console\Commands;

use App\Arkon\Errors\ArkonException;
use App\Arkon\Setup\SetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates an account from the server's command line and adds it to a site.
 * Without --password-env a strong password is generated and printed once;
 * only its hash is stored.
 */
class MemberCreate extends Command
{
    protected $signature = 'arkon:member-create
        {--email= : Email address (sign-in name)}
        {--name= : Display name}
        {--role=editor : owner, admin, editor or viewer}
        {--site= : Site id (required when several sites exist)}
        {--password-env= : Read the password from this environment variable instead of generating one (automation only)}';

    protected $description = 'Create a user account with a role on a site';

    public function handle(SetupService $setup): int
    {
        $email = (string) $this->option('email');
        if ($email === '') {
            $this->error('Usage: php artisan '.$this->name.' --email=you@example.com --name="Your Name"');

            return self::FAILURE;
        }
        $siteId = $this->option('site');
        if (! $siteId) {
            $sites = DB::table('sites')->orderBy('created_at')->get(['id', 'name']);
            if ($sites->isEmpty()) {
                $this->error('No site exists yet. Run `php artisan arkon:seed` first.');

                return self::FAILURE;
            }
            if ($sites->count() > 1) {
                $this->error('Several sites exist; pass --site with one of: '.$sites->map(fn ($s) => "{$s->id} ({$s->name})")->join(', '));

                return self::FAILURE;
            }
            $siteId = $sites->first()->id;
        }

        $fromEnv = $this->option('password-env');
        $password = $fromEnv ? (string) getenv($fromEnv) : SetupService::generatePassword();
        if ($fromEnv && $password === '') {
            $this->error("Environment variable {$fromEnv} is empty.");

            return self::FAILURE;
        }

        try {
            $setup->createMember($email, (string) $this->option('name'), $password, $siteId, $this->role());
        } catch (ArkonException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("\n".ucfirst($this->role())." account created for {$email}");
        if (! $fromEnv) {
            $this->line("Password (shown once, store it in your password manager): {$password}\n");
        }

        return self::SUCCESS;
    }

    protected function role(): string
    {
        return (string) $this->option('role');
    }
}
