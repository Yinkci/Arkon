<?php

namespace App\Arkon\Media;

/**
 * Signed, expiring access to one private file. Used by the editor canvas, which
 * is sandboxed and cannot send the session cookie. The key is derived from
 * APP_KEY, so rotating it invalidates outstanding URLs (and sessions).
 */
class MediaSigner
{
    private const TTL_MS = 2 * 60 * 60 * 1000;

    private const HOUR_MS = 60 * 60 * 1000;

    private const TOKEN = '/^(\d{10,13})\.([A-Za-z0-9_-]{43})$/D';

    private readonly string $key;

    public function __construct(?string $appKey = null)
    {
        $appKey ??= (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7)) : $appKey;
        $this->key = hash_hmac('sha256', 'arkon:media-preview-key:v1', (string) $raw, true);
    }

    /** Expiry is rounded up to the hour so URLs are stable (browser-cacheable) within that hour. */
    public function token(string $storageKey, ?int $nowMs = null): string
    {
        $nowMs ??= self::now();
        $expiresAt = (int) (ceil(($nowMs + self::TTL_MS) / self::HOUR_MS) * self::HOUR_MS);

        return $expiresAt.'.'.$this->signature($storageKey, $expiresAt);
    }

    public function verify(string $storageKey, string $token, ?int $nowMs = null): bool
    {
        if (preg_match(self::TOKEN, $token, $m) !== 1) {
            return false;
        }
        $expiresAt = (int) $m[1];
        if ($expiresAt < ($nowMs ?? self::now())) {
            return false;
        }

        return hash_equals($this->signature($storageKey, $expiresAt), $m[2]);
    }

    /** /media/<key> → /media/<key>?t=<token> */
    public function signUrl(string $url, ?int $nowMs = null): string
    {
        $key = substr($url, strrpos($url, '/') + 1);

        return $url.'?t='.$this->token($key, $nowMs);
    }

    private function signature(string $storageKey, int $expiresAt): string
    {
        $mac = hash_hmac('sha256', "media-preview:v1:{$storageKey}:{$expiresAt}", $this->key, true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    private static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
