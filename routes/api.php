<?php

use App\Arkon\Content\ContentTypes;
use App\Arkon\Content\Taxonomies;
use App\Http\Api\AuthenticateApi;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\FormsController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\TermsController;
use Illuminate\Support\Facades\Route;

// The public developer API, version 1: /api/v1/… (docs/api.md, resources/api/openapi.json).
// Stateless: no session, cookies or CSRF; anonymous reads or a Bearer token (AuthenticateApi).
// Content types and taxonomies get their routes from their registries.

Route::middleware(['throttle:api-ip', AuthenticateApi::class, 'throttle:api'])->group(function () {
    Route::get('/', [SiteController::class, 'index']);
    Route::get('/openapi.json', [SiteController::class, 'openapi']);
    Route::get('/site', [SiteController::class, 'show']);
    Route::get('/navigation', [SiteController::class, 'navigation']);
    Route::get('/navigation/{menu}', [SiteController::class, 'menuShow']);
    Route::get('/users', [SiteController::class, 'users']);
    Route::get('/users/{id}', [SiteController::class, 'user']);

    foreach (ContentTypes::all() as $kind => $type) {
        $path = '/'.$type['collection'];
        Route::get($path, [ContentController::class, 'index'])->defaults('kind', $kind);
        Route::get("{$path}/{id}", [ContentController::class, 'show'])->defaults('kind', $kind);
        Route::get("{$path}/{id}/seo-analysis", [ContentController::class, 'seoAnalysis'])->defaults('kind', $kind);
        Route::middleware('throttle:api-write')->group(function () use ($path, $kind) {
            Route::post($path, [ContentController::class, 'store'])->defaults('kind', $kind);
            Route::patch("{$path}/{id}", [ContentController::class, 'update'])->defaults('kind', $kind);
            Route::delete("{$path}/{id}", [ContentController::class, 'destroy'])->defaults('kind', $kind);
            Route::post("{$path}/{id}/restore", [ContentController::class, 'restore'])->defaults('kind', $kind);
        });
    }

    // Content types a site defined for itself: one set of routes for all of them, named by the
    // type's key (which never changes), resolved against the requesting site's own registry.
    Route::prefix('content/{type}')->where(['type' => '[a-z][a-z0-9_]{0,31}'])->group(function () {
        Route::get('/', [ContentController::class, 'index']);
        Route::get('/{id}', [ContentController::class, 'show']);
        Route::get('/{id}/seo-analysis', [ContentController::class, 'seoAnalysis']);
        Route::middleware('throttle:api-write')->group(function () {
            Route::post('/', [ContentController::class, 'store']);
            Route::patch('/{id}', [ContentController::class, 'update']);
            Route::delete('/{id}', [ContentController::class, 'destroy']);
            Route::post('/{id}/restore', [ContentController::class, 'restore']);
        });
    });

    foreach (Taxonomies::all() as $name => $taxonomy) {
        $path = '/'.$taxonomy['collection'];
        Route::get($path, [TermsController::class, 'index'])->defaults('taxonomy', $name);
        Route::get("{$path}/{id}", [TermsController::class, 'show'])->defaults('taxonomy', $name);
        Route::middleware('throttle:api-write')->group(function () use ($path, $name) {
            Route::post($path, [TermsController::class, 'store'])->defaults('taxonomy', $name);
            Route::patch("{$path}/{id}", [TermsController::class, 'update'])->defaults('taxonomy', $name);
            Route::delete("{$path}/{id}", [TermsController::class, 'destroy'])->defaults('taxonomy', $name);
        });
    }

    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/{id}', [MediaController::class, 'show']);
    Route::middleware('throttle:api-write')->group(function () {
        Route::post('/media', [MediaController::class, 'store']);
        Route::patch('/media/{id}', [MediaController::class, 'update']);
        Route::delete('/media/{id}', [MediaController::class, 'destroy']);
        Route::post('/media/{id}/restore', [MediaController::class, 'restore']);
    });

    Route::get('/forms', [FormsController::class, 'index']);
    Route::get('/forms/{id}', [FormsController::class, 'show']);
    Route::post('/forms/{id}/submissions', [FormsController::class, 'submit'])->middleware('throttle:api-submit');
    Route::get('/forms/{id}/entries', [FormsController::class, 'entries']);
    Route::get('/forms/{id}/entries/{entry}', [FormsController::class, 'entry']);
});
