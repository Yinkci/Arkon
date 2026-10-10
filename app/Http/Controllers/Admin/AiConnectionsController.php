<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\ProviderRegistry;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Sites\Authorizer;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class AiConnectionsController extends Controller
{
    private function ok(array $data)
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }

    private function context(Request $r)
    {
        $ctx = AdminContext::of($r)->ctx();
        app(Authorizer::class)->authorize($ctx, 'page.edit');

        return $ctx;
    }

    private function data(Request $r, AiConnections $connections): array
    {
        $ctx = $this->context($r);
        $providers = [];
        foreach (ProviderRegistry::definitions() as $id => $definition) {
            $row = DB::table('ai_connections')->where('site_id', $ctx->siteId)->where('user_id', $ctx->userId)->where('provider', $id)->where('kind', 'helper')->whereNull('revoked_at')->orderByDesc('created_at')->first();
            $providers[] = [...$definition, ...$connections->helperStatus($ctx->siteId, $id, $ctx->userId), 'connectionId' => $row?->id, 'checking' => $row && $row->check_requested_at && (! $row->checked_at || strtotime($row->check_requested_at) > strtotime($row->checked_at)), 'testPending' => $row && $row->test_requested_at && $row->test_result === null, 'testResult' => $row?->test_result ? json_decode($row->test_result, true) : null];
        }

        return ['providers' => $providers, 'email' => AdminContext::of($r)->user->email];
    }

    public function index(Request $r, AiConnections $c)
    {
        return Inertia::render('Admin/AiConnections', $this->data($r, $c));
    }

    public function state(Request $r, AiConnections $c)
    {
        return $this->ok($this->data($r, $c));
    }

    public function action(Request $r, AiConnections $c, string $connection, string $action)
    {
        $ctx = $this->context($r);

        return DB::transaction(function () use ($r, $c, $ctx, $connection, $action) {
            $row = DB::table('ai_connections')->where('id', $connection)->where('site_id', $ctx->siteId)->where('user_id', $ctx->userId)->where('kind', 'helper')->whereNull('revoked_at')->lockForUpdate()->first();
            if (! $row) {
                throw new NotFoundException('AI connection');
            }
            if ($action === 'disconnect') {
                $c->revoke($row->id);
            } elseif ($action === 'check') {
                DB::table('ai_connections')->where('id', $row->id)->update(['check_requested_at' => DB::raw('now()')]);
            } elseif ($action === 'test') {
                if (! $c->helperStatus($ctx->siteId, $row->provider, $ctx->userId)['ready']) {
                    throw new AiException('PROVIDER_UNAVAILABLE', 'Start the paired helper before testing a response.');
                }
                if (! $row->test_requested_at || $row->test_result !== null) {
                    DB::table('ai_connections')->where('id', $row->id)->update(['test_requested_at' => DB::raw('now()'), 'test_result' => null]);
                }
            } else {
                throw new NotFoundException('AI action');
            }

            return $this->ok($this->data($r, $c));
        });
    }
}
