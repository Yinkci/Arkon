<?php

namespace App\Arkon\Support;

/** Identifies what an idempotency key was used for, so a reused key can be checked on replay. */
final class Fingerprint
{
    public static function of(array $request): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', Json::canonical($request), true)), '+/', '-_'), '=');
    }
}
