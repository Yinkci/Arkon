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
        'Content-Security-Policy' => "default-src 'self'; script-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'",
    ];

    public function __invoke(Request $request, string $page, PageService $pages): Response
    {
        try {
            return response($pages->renderPreview(AdminContext::of($request)->ctx(), $page), 200, self::HEADERS);
        } catch (ArkonException $error) {
            $notFound = $error->code() === 'NOT_FOUND';

            return response($notFound ? 'Not found' : $error->getMessage(), $notFound ? 404 : 400, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
            ]);
        }
    }
}
