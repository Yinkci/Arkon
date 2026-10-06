<?php

namespace App\Http\Controllers;

use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Media\MediaStorage;
use App\Http\SessionUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Media delivery (policy in MediaService::resolveAccess): public only while used
 * by a live page of the requesting host's site; otherwise members or signed
 * preview URLs only. Runs outside the session middleware so public responses
 * never set cookies.
 */
class MediaController extends Controller
{
    public function __invoke(Request $request, string $file, MediaService $media, MediaSigner $signer, MediaStorage $storage): Response
    {
        $host = $request->getHttpHost();
        $token = $request->query('t');
        $token = is_string($token) ? $token : null;

        // Cheap anonymous checks first; the session is read only when they fail.
        $decision = $media->resolveAccess($file, $host, null, $token, $signer);
        if ($decision === null && ($userId = SessionUser::id($request)) !== null) {
            $decision = $media->resolveAccess($file, $host, $userId, $token, $signer);
        }
        $data = $decision ? $storage->read($file) : null;
        if ($data === null) {
            return response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        return response($data, 200, [
            'Content-Type' => $decision['mime'],
            'Content-Length' => (string) strlen($data),
            // Public files never change (unique key per upload). Private ones must not be stored by shared caches.
            'Cache-Control' => $decision['access'] === 'public' ? 'public, max-age=31536000, immutable' : 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
