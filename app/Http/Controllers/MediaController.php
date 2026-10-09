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
        $path = $decision ? $storage->path($file) : null;
        if ($path === null) {
            return response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        // BinaryFileResponse streams bytes and supports HEAD/ranges without copying
        // the whole image into PHP memory. Authorization precedes every file response.
        $response = response()->file($path, [
            'Content-Type' => $decision['mime'],
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // BinaryFileResponse defaults to public; explicitly restore our access policy.
        $response->headers->set('Cache-Control', $decision['access'] === 'public' ? 'public, max-age=31536000, immutable' : 'private, no-store');
        // Private URLs are never conditionally cacheable. Public immutable keys are
        // validators without hashing/reading file contents, after checking live access.
        $response->headers->remove('Last-Modified');
        if ($decision['access'] === 'public') {
            $response->setEtag($file);
            $response->isNotModified($request);
        }

        return $response;
    }
}
