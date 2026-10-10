<?php

namespace App\Providers;

use App\Arkon\Ai\Actions\ActionRegistry;
use App\Arkon\Ai\Actions\CoreActions;
use App\Arkon\Ai\ClaudeCodeCli;
use App\Arkon\Ai\ClaudeRunner;
use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Database\Transactions;
use App\Arkon\Media\MediaStorage;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use App\Http\Api\ApiPrincipal;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // What AI may do: Arkon's actions; extensions register theirs on this registry.
        $this->app->singleton(ActionRegistry::class, fn () => CoreActions::registry());
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

        // Public API rate limits (config/arkon.php `api.limits`); a 429 uses the API's error shape.
        $limit = fn (string $name) => (int) config('arkon.api.limits.'.$name);
        $principal = fn (Request $r) => $r->attributes->get(ApiPrincipal::class);
        RateLimiter::for('api-ip', fn (Request $r) => Limit::perMinute($limit('ip'))->by('api-ip:'.$r->ip()));
        RateLimiter::for('api', fn (Request $r) => $principal($r)?->authenticated()
            ? Limit::perMinute($limit('token'))->by('api-token:'.$principal($r)->tokenId)
            : Limit::perMinute($limit('anonymous'))->by('api-anon:'.$r->ip()));
        RateLimiter::for('api-write', fn (Request $r) => Limit::perMinute($limit('write'))->by('api-write:'.($principal($r)?->tokenId ?? $r->ip())));
        RateLimiter::for('api-submit', fn (Request $r) => Limit::perMinute($limit('submissions'))->by('api-submit:'.$r->ip()));

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
