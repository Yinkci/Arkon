<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;

/**
 * Reads the signed-in user from the session cookie without starting a session.
 * Used by media delivery, which runs outside the `web` middleware group so that
 * public image responses never carry Set-Cookie (they are cacheable for a year).
 * Requires the EncryptCookies middleware (to decrypt the cookie).
 */
final class SessionUser
{
    public static function id(Request $request): ?string
    {
        $sessionId = $request->cookies->get((string) config('session.cookie'));
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }
        $store = new Store(
            (string) config('session.cookie'),
            app('session')->driver()->getHandler(),
            $sessionId,
            (string) config('session.serialization', 'php'),
        );
        $store->start();
        $userId = $store->get(Auth::guard('web')->getName());

        return is_string($userId) ? $userId : null;
    }
}
