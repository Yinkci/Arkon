<?php

namespace App\Arkon\Content;

use App\Arkon\Design\PageRefreshes;
use Illuminate\Support\Facades\DB;

/**
 * What a published page shows that lives outside the page itself: the site's own values, and
 * other entries (a content list, or a value read through a relationship).
 *
 * A live page is stored HTML, so when one of those changes the page is re-rendered through the
 * same refresh queue a token or component change uses (PageRefreshes): recorded with the
 * publication as a dependency, enqueued by whatever changes it, under the site's publish epoch.
 */
final class ContentDependencies
{
    /**
     * The dependency rows a publication of this document records.
     *
     * @return list<array{kind: string, resource_id: string, version: int}>
     */
    public static function of(string $siteId, mixed $document): array
    {
        $bindings = Bindings::inDocument($document);
        $lists = Collections::inDocument($document) !== [];
        $rows = [];
        // A content list's items may bind site values too, so a list counts as both.
        if ($lists || array_filter($bindings, fn ($b) => $b['source'] === 'site') !== []) {
            $rows[] = ['kind' => 'site_data', 'resource_id' => $siteId, 'version' => self::siteDataVersion($siteId)];
        }
        // A path reads inside a field: through a relationship that is another entry's values.
        if ($lists || array_filter($bindings, fn ($b) => $b['source'] === 'entry' && $b['path'] !== []) !== []) {
            $rows[] = ['kind' => 'content', 'resource_id' => $siteId, 'version' => 0];
        }

        return $rows;
    }

    /** Call inside the transaction that changed site data, after the site's epoch lock. */
    public static function siteDataChanged(string $siteId, int $version): int
    {
        return app(PageRefreshes::class)->enqueue($siteId, 'site_data', $siteId, $version);
    }

    /**
     * Call inside the transaction that changed what is live (publish, unpublish, Trash), after
     * the site's epoch lock: pages that list or read other entries are re-rendered.
     */
    public static function liveContentChanged(string $siteId, int $epoch): int
    {
        return app(PageRefreshes::class)->enqueue($siteId, 'content', $siteId, $epoch);
    }

    private static function siteDataVersion(string $siteId): int
    {
        return (int) (DB::table('site_data')->where('site_id', $siteId)->value('version') ?? 0);
    }
}
