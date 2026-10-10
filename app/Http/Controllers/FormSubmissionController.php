<?php

namespace App\Http\Controllers;

use App\Arkon\Components\Render\FormV4;
use App\Arkon\Errors\ArkonException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Forms\FormRuntime;
use App\Arkon\Forms\FormSubmissions;
use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\Serializer;
use App\Arkon\Sites\Membership;
use Illuminate\Http\Request;

/**
 * The endpoint rendered forms post to (HTML, or JSON from the forms runtime). The submission
 * rules live in FormSubmissions, shared with the public API; this adds what only applies to a
 * same-site browser form: origin checks, the body size limit, the spam trap and HTML answers.
 */
final class FormSubmissionController extends Controller
{
    public function __invoke(Request $request, Membership $membership, FormSubmissions $submissions, string $form, int $version)
    {
        $site = $membership->siteForHost($request->getHttpHost());
        $published = $site ? $submissions->version($site, $form, $version) : null;
        if ($published === null) {
            abort(404);
        }
        if (($request->header('Origin') !== null && $request->header('Origin') !== $request->getSchemeAndHttpHost()) || $request->header('Sec-Fetch-Site') === 'cross-site') {
            abort(403);
        }
        if ((int) $request->server('CONTENT_LENGTH', 0) > 32768 || strlen($request->getContent()) > 32768) {
            return self::answer($request, 'This submission is too large.', 413);
        }
        if ($published['modern'] && $request->header('Origin') === null && ! in_array($request->header('Sec-Fetch-Site'), ['same-origin', 'same-site'], true)) {
            abort(403);
        }
        $raw = $request->input('fields', []);
        try {
            $result = $submissions->submit($site, $form, $version, $published, $raw, $request->input('requestKey'), (string) $request->ip(), (bool) $request->input('website'));
        } catch (ValidationException $e) {
            $retry = is_array($raw) ? [FormV4::element($form, $version, $published['definition'], 'retry', false, $raw, $e->issues)] : [];

            return self::answer($request, $e->getMessage(), 422, null, $retry, $e->issues);
        } catch (NotFoundException) {
            abort(404);
        } catch (ArkonException $e) {
            return self::answer($request, $e->getMessage(), $e->status());
        }
        $c = $result['confirmation'];
        if ($c['type'] === 'redirect') {
            return $request->expectsJson() ? self::answer($request, 'Submission received.', 200, $c['url']) : redirect()->away($c['url'], 303)->withHeaders(['Cache-Control' => 'no-store']);
        }

        return self::answer($request, $c['message']);
    }

    private static function answer(Request $request, string $message, int $status = 200, ?string $redirect = null, array $extra = [], array $issues = [])
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $status < 400, 'message' => $message, ...($redirect ? ['redirect' => $redirect] : []), ...($issues ? ['issues' => $issues] : [])], $status, ['Cache-Control' => 'no-store']);
        }
        $body = Serializer::serialize(Element::h('main', [], Element::h('h1', ['tabindex' => -1], $message), $extra, Element::h('a', ['href' => '/'], 'Back to website')));

        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Form submission</title><style>'.file_get_contents(resource_path('arkon/components/form/v4.css')).'body{font:1rem system-ui;margin:2rem;max-width:50rem}</style>'.($extra ? FormRuntime::tag(2) : '').'</head><body>'.$body.'</body></html>', $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", 'X-Content-Type-Options' => 'nosniff']);
    }
}
