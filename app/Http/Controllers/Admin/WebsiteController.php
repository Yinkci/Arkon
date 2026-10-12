<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\Images\ImageJobs;
use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Renderer\Motion;
use App\Arkon\Renderer\RenderException;
use App\Arkon\Sites\WebsitePublishing;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

final class WebsiteController extends Controller
{
    public function index(Request $r, WebsiteProposalService $service)
    {
        $a = AdminContext::of($r);

        return Inertia::render('Admin/Website', [...$service->list($a->ctx()), 'permissions' => ['edit' => $a->can('page.edit'), 'publish' => $a->can('page.publish')]]);
    }

    public function requests(Request $r, WebsiteProposalService $s)
    {
        return $this->ok($s->list(AdminContext::of($r)->ctx()));
    }

    public function store(Request $r, WebsiteProposalService $s)
    {
        return $this->ok($s->request(AdminContext::of($r)->ctx(), $r->all()));
    }

    public function apply(Request $r, WebsiteProposalService $s, string $proposal)
    {
        return $this->ok($s->apply(AdminContext::of($r)->ctx(), $proposal, $r->all()));
    }

    /** An explicit image-only retry of a failed generated image; the layout is not generated again. */
    public function retryImage(Request $r, ImageJobs $jobs, string $asset)
    {
        return $this->ok($jobs->retry(AdminContext::of($r)->ctx(), $asset, $r->all()));
    }

    /** Continues a failed request; finished stages are not generated again. */
    public function resume(Request $r, WebsiteProposalService $s, string $proposal)
    {
        return $this->ok($s->resume(AdminContext::of($r)->ctx(), $proposal));
    }

    public function discard(Request $r, WebsiteProposalService $s, string $proposal)
    {
        $s->discard(AdminContext::of($r)->ctx(), $proposal);

        return $this->ok([]);
    }

    public function readiness(Request $r, WebsitePublishing $s, string $proposal)
    {
        return $this->ok($s->readiness(AdminContext::of($r)->ctx(), $proposal));
    }

    public function publish(Request $r, WebsitePublishing $s, string $proposal)
    {
        return $this->ok($s->publish(AdminContext::of($r)->ctx(), $proposal, $r->all()));
    }

    public function preview(Request $r, WebsiteProposalService $s, MediaSigner $signer, string $proposal, int $index)
    {
        try {
            $html = $s->preview(AdminContext::of($r)->ctx(), $proposal, $index, $signer, $r->boolean('baseline'));
        } catch (NotFoundException|ForbiddenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->previewFailure($e, $proposal, $index);
        }
        $policy = PublicPageController::csp(Motion::loadedBy(Motion::RUNTIME, $html), $r->getSchemeAndHttpHost(), $html);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => str_replace("form-action 'self'", "form-action 'none'", $policy)."; frame-ancestors 'self'", 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The preview is shown inside the workspace canvas, so it never shows the framework's error page there (versions,
     * paths, a stack trace): it gets a plain explanation and the validator's issues. The full exception goes to the
     * log; the class and location are added only in debug mode.
     */
    private function previewFailure(\Throwable $e, string $proposal, int $index)
    {
        $invalid = $e instanceof RenderException || $e instanceof ValidationException;
        $issues = $invalid ? $e->issues : [];
        Log::log($invalid ? 'warning' : 'error', 'Website preview failed', ['proposal' => $proposal, 'page' => $index, 'issues' => $issues, 'exception' => $e]);
        $esc = fn (string $text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $list = '';
        foreach (array_slice($issues, 0, 12) as $issue) {
            $where = $issue['path'] ?? $issue['nodeId'] ?? null;
            $list .= '<li>'.($where !== null ? '<code>'.$esc((string) $where).'</code> ' : '').$esc((string) ($issue['message'] ?? '')).'</li>';
        }
        $debug = config('app.debug') ? '<p class="d">'.$esc($e::class.' at '.basename($e->getFile()).':'.$e->getLine()).'</p>' : '';
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Preview unavailable</title><style>'
            .'body{margin:0;font:15px/1.55 system-ui,sans-serif;color:#2d2d2d;background:#fff}main{max-width:40rem;margin:4rem auto;padding:0 1.5rem}'
            .'h1{font-size:19px;margin:0 0 .5rem}p{margin:.5rem 0;color:#555}ul{margin:.75rem 0;padding-left:1.2rem;color:#555;font-size:13px}'
            .'code{font-size:12px;color:#2d2d2d}.d{font:12px ui-monospace,monospace;color:#888}</style>'
            .'<main><h1>Preview unavailable</h1><p>'.($invalid
                ? 'Arkon found a problem in this proposal while preparing its preview: '.$esc(rtrim($e->getMessage(), '.')).'.'
                : 'This preview could not be prepared because of an unexpected error. It has been logged.')
            .'</p>'.($list !== '' ? '<ul>'.$list.'</ul>' : '').'<p>Nothing was applied. Request changes or discard this proposal.</p>'.$debug.'</main></html>';

        return response($html, $invalid ? 422 : 500, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'", 'X-Content-Type-Options' => 'nosniff']);
    }

    private function ok(array $data)
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }
}
