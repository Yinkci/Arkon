<?php

use App\Arkon\Errors\ArkonException;
use App\Http\Middleware\AdminSecurityHeaders;
use App\Http\Middleware\EnsureSafeRuntime;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveAdminSite;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** JSON endpoints used by the editor and the admin pages. */
$isApi = fn (Request $request) => $request->is('admin/api/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Public site and media: outside the `web` group, so no session, cookies,
        // CSRF or Inertia. Registered last: the page route is a catch-all.
        then: fn () => Route::group([], base_path('routes/public.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(EnsureSafeRuntime::class);
        $middleware->web(append: [HandleInertiaRequests::class, AdminSecurityHeaders::class]);
        $middleware->alias(['admin.site' => ResolveAdminSite::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => '/login?next='.urlencode($request->getRequestUri()));
        $middleware->redirectUsersTo('/admin');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isApi): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request) || $request->expectsJson());
        $exceptions->dontReport([ArkonException::class]);

        // One error envelope for the editor: { ok: false, code, message, issues?, currentVersion? }.
        $exceptions->render(function (ArkonException $error, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json($error->toArray(), $error->status());
            }
            abort($error->status(), $error->getMessage());
        });
        $exceptions->render(function (AuthenticationException $error, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['ok' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'Your session has expired. Sign in again.'], 401);
            }
        });
        $exceptions->render(function (TokenMismatchException $error, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['ok' => false, 'code' => 'UNAUTHENTICATED', 'message' => 'Your session has expired. Reload the page and sign in again.'], 419);
            }
        });
        $exceptions->render(function (ValidationException $error, Request $request) use ($isApi) {
            if ($isApi($request)) {
                $issues = collect($error->errors())->flatMap(fn ($messages, $path) => array_map(fn ($m) => ['path' => $path, 'message' => $m], $messages))->values();

                return response()->json(['ok' => false, 'code' => 'VALIDATION', 'message' => $issues->pluck('message')->join(' '), 'issues' => $issues], 422);
            }
        });
        $exceptions->render(function (PostTooLargeException $error, Request $request) use ($isApi) {
            if ($isApi($request)) {
                Log::warning('media.upload.rejected', ['stage' => 'request_body', 'content_length' => $request->server('CONTENT_LENGTH'), 'post_max_size' => ini_get('post_max_size')]);

                return response()->json(['ok' => false, 'code' => 'REQUEST_TOO_LARGE', 'message' => 'The server rejected the request body before the image could be validated. Ask the administrator to check PHP and proxy upload limits.'], 413);
            }
        });
        $exceptions->render(function (Throwable $error, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            if ($error instanceof HttpExceptionInterface && $error->getStatusCode() < 500) {
                $code = match ($error->getStatusCode()) {
                    404 => 'NOT_FOUND',
                    403 => 'FORBIDDEN',
                    default => 'BAD_REQUEST',
                };

                return response()->json(['ok' => false, 'code' => $code, 'message' => $error->getMessage() ?: 'Request failed'], $error->getStatusCode());
            }

            // Unexpected: logged by the handler, never leaked. The editor treats this as "uncertain".
            return response()->json(['ok' => false, 'code' => 'INTERNAL', 'message' => 'Something went wrong. Please try again.'], 500);
        });
    })->create();
