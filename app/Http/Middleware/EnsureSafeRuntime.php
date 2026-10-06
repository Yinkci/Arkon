<?php

namespace App\Http\Middleware;

use App\Arkon\Database\ConfigurationException;
use App\Arkon\Database\RuntimeSafety;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to serve with privileged credentials in the environment or a
 * database role that can change the schema. The environment check is free and
 * runs on every request; the role check queries the database and is cached.
 */
class EnsureSafeRuntime
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            RuntimeSafety::validateEnvironment(RuntimeSafety::processEnvironment(), config('app.key'));
            $db = DB::connection();
            $key = 'arkon:runtime-role-ok:'.sha1(implode('|', [$db->getConfig('host'), $db->getConfig('port'), $db->getDatabaseName(), $db->getConfig('username')]));
            if (! Cache::get($key)) {
                RuntimeSafety::assertRestrictedRole($db);
                Cache::put($key, true, (int) config('arkon.runtime_check_ttl'));
            }
        } catch (ConfigurationException $error) {
            Log::critical('Arkon is not serving because its runtime configuration is unsafe. '.$error->getMessage());

            return response('Service unavailable: unsafe server configuration (see the application log).', 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
