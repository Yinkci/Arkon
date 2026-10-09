<?php

use App\Arkon\Schema\PagePath;
use App\Http\Controllers\FormSubmissionController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\SeoController;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

// Loaded without the `web` middleware group: public pages and media never start a
// session, set cookies or load admin assets.

// EncryptCookies only decrypts the session cookie so members can see private media.
Route::get('/media/{file}', MediaController::class)->middleware(EncryptCookies::class)->where('file', '[^/]+');

// Every path whose first segment is not reserved by the application (admin, login,
// media, …; the same list the page URL validation uses) is a public page.
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/robots.txt', [SeoController::class, 'robots']);
Route::post('/_arkon/forms/{form}/{version}', FormSubmissionController::class)->whereUuid('form')->whereNumber('version');

Route::get('/{path?}', PublicPageController::class)->where('path', PagePath::publicRoutePattern());
