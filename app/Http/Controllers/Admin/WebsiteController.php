<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\WebsiteProposalService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Renderer\Motion;
use App\Arkon\Sites\WebsitePublishing;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicPageController;
use Illuminate\Http\Request;
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
        return $this->ok($s->apply(AdminContext::of($r)->ctx(), $proposal));
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
        $html = $s->preview(AdminContext::of($r)->ctx(), $proposal, $index, $signer);
        $policy = PublicPageController::csp(Motion::loadedBy(Motion::RUNTIME, $html), $r->getSchemeAndHttpHost(), $html);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Content-Security-Policy' => str_replace("form-action 'self'", "form-action 'none'", $policy)."; frame-ancestors 'self'", 'X-Content-Type-Options' => 'nosniff']);
    }

    private function ok(array $data)
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }
}
