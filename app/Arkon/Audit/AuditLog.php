<?php

namespace App\Arkon\Audit;

use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/** Insert-only: the runtime role cannot update or delete audit rows. */
class AuditLog
{
    /** @param 'ui'|'ai'|'api'|'system'|'cli' $via */
    public function record(?string $siteId, ?string $actorUserId, string $via, string $action, string $targetKind, string $targetId, array $data = []): void
    {
        DB::table('audit_logs')->insert([
            'id' => Uuid::v7(),
            'site_id' => $siteId,
            'actor_user_id' => $actorUserId,
            'actor_via' => $via,
            'action' => $action,
            'target_kind' => $targetKind,
            'target_id' => $targetId,
            'data' => Json::encode($data === [] ? new \stdClass : $data),
        ]);
    }

    public function forContext(SiteContext $ctx, string $action, string $targetKind, string $targetId, array $data = []): void
    {
        $this->record($ctx->siteId, $ctx->userId, $ctx->via, $action, $targetKind, $targetId, $data);
    }
}
