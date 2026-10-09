<?php

namespace App\Http\Controllers;

use App\Arkon\Errors\ArkonException;
use App\Arkon\Pages\PageService;
use App\Http\AdminContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Draft preview: production rendering of the current draft, for signed-in members only. */
class PreviewController extends Controller
{
    private const HEADERS = [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __invoke(Request $request, string $page, PageService $pages): Response
    {
        try {
            $preview = $pages->renderPreviewPage(AdminContext::of($request)->ctx(), $page);

            // Same script policy as the live page: none, or exactly the animation runtime the page uses.
            return response($preview['html'], 200, [
                ...self::HEADERS,
                'Content-Security-Policy' => PublicPageController::csp($preview['runtime'], $request->getSchemeAndHttpHost(), $preview['html']),
            ]);
        } catch (ArkonException $error) {
            $notFound = $error->code() === 'NOT_FOUND';

            return response($notFound ? 'Not found' : $error->getMessage(), $notFound ? 404 : 400, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }
    }
}
