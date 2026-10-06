<?php

namespace App\Arkon\Sites;

use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

class Membership
{
    public function roleOf(string $siteId, ?string $userId): ?string
    {
        if ($userId === null || ! Uuid::isValid($siteId) || ! Uuid::isValid($userId)) {
            return null;
        }

        return DB::table('site_members')->where('site_id', $siteId)->where('user_id', $userId)->value('role');
    }

    /**
     * Sites the user belongs to, oldest membership first. The admin works on the
     * first one until a site switcher exists.
     *
     * @return list<object{site_id: string, site_name: string, role: string}>
     */
    public function sitesOf(string $userId): array
    {
        return DB::table('site_members as m')
            ->join('sites as s', 's.id', '=', 'm.site_id')
            ->where('m.user_id', $userId)
            ->orderBy('m.created_at')
            ->orderBy('s.id')
            ->get(['s.id as site_id', 's.name as site_name', 'm.role'])
            ->all();
    }

    public function siteForHost(string $host): ?string
    {
        return DB::table('site_domains')->where('hostname', strtolower($host))->value('site_id');
    }
}
