<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email and password sign-in. There is no sign-up route: accounts are created
 * only from the command line (arkon:owner-create).
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function show(Request $request): Response|RedirectResponse
    {
        $next = self::safeNext($request->query('next'));
        if (Auth::check()) {
            return redirect($next);
        }

        return Inertia::render('Auth/Login', ['next' => $next]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
        ]);
        $email = strtolower(trim($credentials['email']));
        $key = 'login:'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Wait a minute and try again.'])->status(429);
        }
        if (! Auth::attempt(['email' => $email, 'password' => $credentials['password']])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect(self::safeNext($request->input('next')));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /** Only same-site relative paths, never "//host" or absolute URLs. */
    public static function safeNext(mixed $next): string
    {
        return is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//') && ! str_starts_with($next, '/\\')
            ? $next
            : '/admin';
    }
}
