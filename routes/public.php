<?php

use App\Arkon\Schema\PagePath;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PublicPageController;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

// Loaded without the `web` middleware group: public pages and media never start a
// session, set cookies or load admin assets.

// EncryptCookies only decrypts the session cookie so members can see private media.
Route::get('/media/{file}', MediaController::class)->middleware(EncryptCookies::class)->where('file', '[^/]+');

// Every path whose first segment is not reserved by the application (admin, login,
// media, …; the same list the page URL validation uses) is a public page.
Route::get('/{path?}', PublicPageController::class)->where('path', PagePath::publicRoutePattern());
