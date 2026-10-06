<?php

namespace App\Console\Commands;

/**
 * php artisan arkon:owner-create --email=you@example.com --name="Your Name"
 *
 * The first owner is created from the command line, never from a web page.
 */
class OwnerCreate extends MemberCreate
{
    protected $signature = 'arkon:owner-create
        {--email= : Email address (sign-in name)}
        {--name= : Display name}
        {--site= : Site id (required when several sites exist)}
        {--password-env= : Read the password from this environment variable instead of generating one (automation only)}';

    protected $description = 'Create an owner account (prints a generated password once)';

    protected function role(): string
    {
        return 'owner';
    }
}
