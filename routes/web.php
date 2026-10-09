<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DesignController;
use App\Http\Controllers\Admin\EditorController;
use App\Http\Controllers\Admin\FormsController;
use App\Http\Controllers\Admin\NavigationController;
use App\Http\Controllers\Admin\PagesController;
use App\Http\Controllers\Admin\SiteOverviewController;
use App\Http\Controllers\Admin\ThemesController;
use App\Http\Controllers\Admin\WebsiteController;
use App\Http\Controllers\Api\AiApiController;
use App\Http\Controllers\Api\DesignApiController;
use App\Http\Controllers\Api\EditorApiController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\PageApiController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\PreviewController;
use Illuminate\Support\Facades\Route;

// Admin, editor, preview and sign-in. Everything public lives in routes/public.php.
// There is no registration route: accounts are created with `php artisan arkon:owner-create`.

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:30,1');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware(['auth', 'admin.site'])->group(function () {
    Route::get('/admin', DashboardController::class)->name('admin');
    Route::get('/admin/pages', [PagesController::class, 'index']);
    Route::get('/admin/media', [SiteOverviewController::class, 'media']);
    Route::get('/admin/search', [SiteOverviewController::class, 'search']);
    Route::get('/admin/seo/defaults', [SeoController::class, 'defaults']);
    Route::get('/admin/seo', [SiteOverviewController::class, 'seo']);
    Route::get('/admin/settings', [SiteOverviewController::class, 'settings']);
    Route::get('/admin/design/components', [DesignController::class, 'show']);
    Route::get('/admin/performance', [DesignController::class, 'show']);
    Route::get('/admin/editor/{page}', [EditorController::class, 'show']);
    Route::get('/preview/{page}', PreviewController::class);
    Route::get('/admin/themes', [ThemesController::class, 'index']);
    Route::get('/admin/themes/{theme}/preview', [ThemesController::class, 'preview']);
    Route::get('/admin/website', [WebsiteController::class, 'index']);
    Route::get('/admin/website/{proposal}/preview/{index}', [WebsiteController::class, 'preview'])->whereNumber('index');
    Route::get('/admin/navigation', [NavigationController::class, 'index']);
    Route::get('/admin/forms', [FormsController::class, 'index']);
    Route::get('/admin/forms/{form}', [FormsController::class, 'show'])->whereUuid('form');
    Route::get('/admin/forms/{form}/preview', [FormsController::class, 'preview'])->whereUuid('form');
    Route::post('/admin/forms/{form}/preview', [FormsController::class, 'previewSubmit'])->whereUuid('form');
    Route::get('/admin/forms/{form}/export', [FormsController::class, 'export'])->whereUuid('form');
    Route::get('/admin/design', [DesignController::class, 'show']);
    Route::get('/admin/components/{component}', [DesignController::class, 'component']);

    Route::prefix('admin/api')->group(function () {
        Route::post('/seo/defaults/save', [SeoController::class, 'save']);
        Route::post('/seo/defaults/publish', [SeoController::class, 'publish']);
        Route::get('/themes', [ThemesController::class, 'state']);
        Route::post('/themes/activate', [ThemesController::class, 'activate']);
        Route::post('/themes/publish', [ThemesController::class, 'publish']);
        Route::post('/pages', [PageApiController::class, 'store']);
        Route::post('/pages/{page}/unpublish', [PageApiController::class, 'unpublish']);
        Route::post('/pages/{page}/delete', [PageApiController::class, 'destroy']);

        Route::post('/pages/{page}/seo-analysis', [SeoController::class, 'analyze'])->middleware('throttle:120,1');
        Route::get('/pages/{page}/status', [EditorApiController::class, 'status']);
        Route::post('/pages/{page}/save', [EditorApiController::class, 'save']);
        Route::post('/pages/{page}/publish', [EditorApiController::class, 'publish']);
        Route::post('/pages/{page}/restore', [EditorApiController::class, 'restore']);
        Route::post('/pages/{page}/settings', [EditorApiController::class, 'settings']);
        Route::post('/pages/{page}/canvas', [EditorApiController::class, 'canvas']);
        // AI proposals (per-user and per-site limits are enforced by ProposalLedger).
        Route::get('/pages/{page}/ai/requests', [AiApiController::class, 'index']);
        Route::post('/pages/{page}/ai/requests', [AiApiController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/pages/{page}/ai/requests/{proposal}', [AiApiController::class, 'show']);
        Route::post('/pages/{page}/ai/requests/{proposal}/cancel', [AiApiController::class, 'cancel']);
        Route::post('/pages/{page}/ai/requests/{proposal}/discard', [AiApiController::class, 'discard']);
        Route::post('/pages/{page}/ai/requests/{proposal}/apply-tokens', [AiApiController::class, 'applyTokens']);

        Route::get('/website/requests', [WebsiteController::class, 'requests']);
        Route::post('/website/requests', [WebsiteController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/website/{proposal}/apply', [WebsiteController::class, 'apply']);
        Route::post('/website/{proposal}/discard', [WebsiteController::class, 'discard']);
        Route::get('/website/{proposal}/readiness', [WebsiteController::class, 'readiness']);
        Route::post('/website/{proposal}/publish', [WebsiteController::class, 'publish']);
        Route::post('/navigation/save', [NavigationController::class, 'save']);
        Route::post('/navigation/{menu}/publish', [NavigationController::class, 'publish']);
        Route::post('/website/layout', [NavigationController::class, 'layout']);
        Route::post('/forms/save', [FormsController::class, 'save']);
        Route::post('/forms/{form}/publish', [FormsController::class, 'publish']);
        Route::post('/forms/{form}/notifications', [FormsController::class, 'notifications']);
        Route::post('/forms/{form}/duplicate', [FormsController::class, 'duplicate']);
        Route::post('/forms/{form}/archive', [FormsController::class, 'archive']);
        Route::get('/forms/{form}/entries', [FormsController::class, 'entries']);
        Route::get('/forms/{form}/entries/{entry}', [FormsController::class, 'entry']);
        Route::post('/forms/{form}/entries', [FormsController::class, 'changeEntries']);
        Route::post('/media', [MediaApiController::class, 'store']);
        Route::get('/media-library', [MediaApiController::class, 'search']);
        Route::get('/media/{asset}', [MediaApiController::class, 'show']);
        Route::post('/media/{asset}/metadata', [MediaApiController::class, 'update']);
        Route::post('/media/{asset}/archive', [MediaApiController::class, 'archive']);

        Route::get('/design/tokens', [DesignApiController::class, 'tokens']);
        Route::post('/design/tokens/save', [DesignApiController::class, 'saveTokens']);
        Route::post('/design/tokens/publish', [DesignApiController::class, 'publishTokens']);
        Route::get('/design/refreshes', [DesignApiController::class, 'refreshes']);
        Route::post('/design/refreshes/retry', [DesignApiController::class, 'retryRefreshes']);
        Route::get('/components', [DesignApiController::class, 'components']);
        Route::post('/components', [DesignApiController::class, 'createComponent']);
        Route::post('/components/{component}/save', [DesignApiController::class, 'saveComponent']);
        Route::post('/components/{component}/publish', [DesignApiController::class, 'publishComponent']);
        Route::post('/components/{component}/canvas', [DesignApiController::class, 'componentCanvas']);
    });
});
