<?php

namespace App\Providers;

use App\Arkon\Ai\ClaudeCodeCli;
use App\Arkon\Ai\ClaudeRunner;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Database\Transactions;
use App\Arkon\Media\MediaStorage;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use App\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ComponentRegistry::class, fn () => ComponentRegistry::default());
        $this->app->singleton(DocumentValidator::class);
        $this->app->singleton(PageRenderer::class);
        $this->app->singleton(Transactions::class);
        $this->app->singleton(MediaStorage::class, fn () => MediaStorage::fromConfig());
        // AI runs through the Claude Code CLI under the user's own login (used by the local helper only).
        $this->app->bind(ClaudeRunner::class, fn () => ClaudeCodeCli::fromConfig());
    }

    public function boot(): void
    {
        // Site permissions as Gate abilities, e.g. Gate::allows('page.publish', $siteId).
        // UI hints and controllers use these; the services authorize again themselves
        // (App\Arkon\Sites\Authorizer), so no caller can skip the check.
        foreach (Permissions::ALL as $permission) {
            Gate::define($permission, fn (User $user, string $siteId) => Permissions::allows(
                app(Membership::class)->roleOf($siteId, $user->id),
                $permission,
            ));
        }

        // Schema changes run only through `arkon:migrate`, which connects as the schema
        // owner. The runtime role cannot change the schema anyway; this explains why.
        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, function (CommandStarting $event) {
                $command = (string) $event->command;
                $guarded = str_starts_with($command, 'migrate') || in_array($command, ['db:wipe', 'schema:dump'], true);
                if ($guarded && ! $this->app->bound('arkon.migrating')) {
                    throw new RuntimeException("Run `php artisan arkon:migrate` instead of `{$command}`: migrations use the schema-owner role from .migrate.env.");
                }
            });
        }
    }
}
