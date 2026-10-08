<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DesignController;
use App\Http\Controllers\Admin\EditorController;
use App\Http\Controllers\Admin\PagesController;
use App\Http\Controllers\Admin\ThemesController;
use App\Http\Controllers\Api\AiApiController;
use App\Http\Controllers\Api\DesignApiController;
use App\Http\Controllers\Api\EditorApiController;
use App\Http\Controllers\Api\MediaApiController;
use App\Http\Controllers\Api\PageApiController;
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
    Route::get('/admin/editor/{page}', [EditorController::class, 'show']);
    Route::get('/preview/{page}', PreviewController::class);
    Route::get('/admin/themes', [ThemesController::class, 'index']);
    Route::get('/admin/themes/{theme}/preview', [ThemesController::class, 'preview']);
    Route::get('/admin/design', [DesignController::class, 'show']);
    Route::get('/admin/components/{component}', [DesignController::class, 'component']);

    Route::prefix('admin/api')->group(function () {
        Route::get('/themes', [ThemesController::class, 'state']);
        Route::post('/themes/activate', [ThemesController::class, 'activate']);
        Route::post('/themes/publish', [ThemesController::class, 'publish']);
        Route::post('/pages', [PageApiController::class, 'store']);
        Route::post('/pages/{page}/unpublish', [PageApiController::class, 'unpublish']);
        Route::post('/pages/{page}/delete', [PageApiController::class, 'destroy']);

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

        Route::post('/media', [MediaApiController::class, 'store']);

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
