<?php

namespace App\Arkon\Pages;

use App\Arkon\Database\Transactions;
use App\Arkon\Design\ComponentService;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Design\TokenService;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Time;
use Illuminate\Support\Facades\DB;

/**
 * What the dashboard shows besides the page list, from recorded data only: recent
 * activity on the site (the audit log), and things waiting for someone (AI proposals
 * to review, live pages not yet updated after a design change, unpublished design
 * drafts). No metrics are estimated or invented.
 */
final class Overview
{
    /** Audit actions worth showing, with how they read ("Ana published Home"). */
    private const ACTIVITY = [
        'page.create' => 'created',
        'page.publish' => 'published',
        'page.unpublish' => 'unpublished',
        'page.revision.restore' => 'restored an earlier version of',
        'page.ai.apply' => 'applied an AI proposal to',
        'page.settings.update' => 'changed the title or URL of',
        'page.delete' => 'deleted',
        'component.create' => 'created the component',
        'component.publish' => 'published the component',
        'tokens.publish' => 'published the design tokens',
        'tokens.ai.apply' => 'applied AI design token changes to the token draft',
    ];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Transactions $transactions,
        private readonly TokenService $tokens,
        private readonly ComponentService $components,
        private readonly PageRefreshes $refreshes,
    ) {}

    /**
     * @return array{activity: list<array>, proposals: list<array>, refreshes: array{pending: int, failed: int}, design: array{tokensChanged: bool, componentsChanged: int}}
     */
    public function forDashboard(SiteContext $ctx): array
    {
        // Each part reads in its own transaction (the services set their own isolation); the dashboard
        // only reports, so parts a moment apart are fine.
        $tokens = $this->tokens->state($ctx);
        $components = $this->components->list($ctx);
        [$activity, $proposals] = $this->transactions->run(function () use ($ctx) {
            $this->authorizer->authorize($ctx, 'page.view');

            return [$this->activity($ctx->siteId), $this->proposalsToReview($ctx)];
        }, isolation: 'REPEATABLE READ', readOnly: true);

        return [
            'activity' => $activity,
            'proposals' => $proposals,
            'refreshes' => ['pending' => $tokens['refreshes']['pending'], 'failed' => $tokens['refreshes']['failed']],
            'design' => [
                'tokensChanged' => (bool) $tokens['changed'],
                'componentsChanged' => count(array_filter($components, fn ($c) => $c['changed'])),
            ],
        ];
    }

    /** @return list<array{id: string, actor: string|null, verb: string, target: string|null, href: string|null, at: string, kind: string}> */
    private function activity(string $siteId, int $limit = 8): array
    {
        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
            ->where('a.site_id', $siteId)
            ->whereIn('a.action', array_keys(self::ACTIVITY))
            ->orderByDesc('a.created_at')
            ->limit($limit)
            ->get(['a.id', 'a.action', 'a.target_kind', 'a.target_id', 'a.data', 'a.actor_via', 'a.created_at', 'u.name as actor']);
        $pageIds = $rows->where('target_kind', 'page')->pluck('target_id')->unique()->values()->all();
        $componentIds = $rows->where('target_kind', 'component')->pluck('target_id')->unique()->values()->all();
        $pages = $pageIds === [] ? collect() : DB::table('pages')->where('site_id', $siteId)->whereIn('id', $pageIds)->get(['id', 'title', 'deleted_at'])->keyBy('id');
        $components = $componentIds === [] ? collect() : DB::table('reusable_components')->where('site_id', $siteId)->whereIn('id', $componentIds)->pluck('name', 'id');

        return $rows->map(function ($r) use ($pages, $components) {
            $data = json_decode((string) $r->data, true) ?: [];
            [$target, $href] = match ($r->target_kind) {
                'page' => [
                    $pages[$r->target_id]->title ?? ($data['title'] ?? 'a page'),
                    isset($pages[$r->target_id]) && $pages[$r->target_id]->deleted_at === null ? "/admin/editor/{$r->target_id}" : null,
                ],
                'component' => [$components[$r->target_id] ?? ($data['name'] ?? 'a component'), isset($components[$r->target_id]) ? "/admin/components/{$r->target_id}" : null],
                default => [null, '/admin/design'],
            };

            return [
                'id' => $r->id,
                'actor' => $r->actor ?? ($r->actor_via === 'system' ? 'Arkon' : null),
                'verb' => self::ACTIVITY[$r->action],
                'target' => $target,
                'href' => $href,
                'at' => Time::iso($r->created_at),
                'kind' => explode('.', $r->action)[0],
                'version' => isset($data['version']) ? (int) $data['version'] : null,
            ];
        })->values()->all();
    }

    /** The viewer's own AI proposals that are ready and not yet applied or discarded. */
    private function proposalsToReview(SiteContext $ctx): array
    {
        return DB::table('ai_proposals as r')
            ->join('pages as p', fn ($j) => $j->on('p.site_id', '=', 'r.site_id')->on('p.id', '=', 'r.page_id'))
            ->where('r.site_id', $ctx->siteId)
            ->where('r.created_by', $ctx->userId)
            ->where('r.status', 'proposed')
            ->whereNull('p.deleted_at')
            ->orderByDesc('r.created_at')
            ->limit(5)
            ->get(['r.id', 'r.prompt', 'r.created_at', 'p.id as page_id', 'p.title'])
            ->map(fn ($r) => ['id' => $r->id, 'prompt' => $r->prompt, 'pageId' => $r->page_id, 'page' => $r->title, 'at' => Time::iso($r->created_at)])
            ->values()->all();
    }
}
