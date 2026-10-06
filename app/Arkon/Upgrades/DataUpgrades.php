<?php

namespace App\Arkon\Upgrades;

use App\Arkon\Components\DocumentValidator;
use App\Arkon\Support\Json;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Data upgrades: steps that need component logic and run after the SQL
 * migrations, as the schema owner (arkon:migrate). Each is recorded in
 * data_upgrades only after it completes and is safe to re-run. Names are
 * permanent: never rename or reorder applied ones.
 */
class DataUpgrades
{
    public function __construct(private readonly DocumentValidator $validator) {}

    /** @return array<string, Closure(): array> */
    public function all(): array
    {
        return [
            '2026-10-06-backfill-publication-media' => fn () => $this->backfillPublicationMedia(),
        ];
    }

    /** @param Closure(string): void $log */
    public function runPending(Closure $log): void
    {
        foreach ($this->all() as $name => $run) {
            if (DB::table('data_upgrades')->where('name', $name)->exists()) {
                continue;
            }
            $details = $run();
            DB::statement('INSERT INTO data_upgrades (name, details) VALUES (?, ?::jsonb) ON CONFLICT DO NOTHING', [$name, Json::encode($details)]);
            $log("Data upgrade {$name}: ".Json::encode($details));
        }
    }

    /**
     * Links every publication to the media its revision actually uses.
     * Publications created before publication_media existed have no rows, and
     * public media delivery depends on them, so without this their live images
     * would start returning 404.
     *
     * - Input is the publication's immutable revision document (what it was
     *   rendered from), read with each node's own component version.
     * - Site-scoped: one transaction per site, linking only that site's assets
     *   (the composite foreign keys enforce the same).
     * - Retry-safe: ON CONFLICT DO NOTHING, so a re-run inserts nothing.
     *
     * @return array{sites: int, publications: int, inserted: int, unknownAssets: int, skippedNodes: int}
     */
    public function backfillPublicationMedia(): array
    {
        $report = ['sites' => 0, 'publications' => 0, 'inserted' => 0, 'unknownAssets' => 0, 'skippedNodes' => 0];
        foreach (DB::table('sites')->orderBy('id')->pluck('id') as $siteId) {
            DB::transaction(function () use ($siteId, &$report) {
                $publications = DB::table('publications as p')
                    ->join('page_revisions as r', fn ($j) => $j->on('r.site_id', '=', 'p.site_id')->on('r.page_id', '=', 'p.page_id')->on('r.id', '=', 'p.revision_id'))
                    ->where('p.site_id', $siteId)
                    ->get(['p.id as publication_id', 'r.document']);
                $siteAssets = array_flip(DB::table('media_assets')->where('site_id', $siteId)->pluck('id')->all());

                $rows = [];
                foreach ($publications as $publication) {
                    $refs = $this->validator->mediaRefsLenient(Json::decode($publication->document));
                    $report['skippedNodes'] += $refs['skippedNodes'];
                    foreach ($refs['ids'] as $assetId) {
                        if (isset($siteAssets[$assetId])) {
                            $rows[] = [$siteId, $publication->publication_id, $assetId];
                        } else {
                            $report['unknownAssets']++;
                        }
                    }
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    $values = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
                    $inserted = DB::select(
                        "INSERT INTO publication_media (site_id, publication_id, asset_id) VALUES {$values} ON CONFLICT DO NOTHING RETURNING asset_id",
                        array_merge(...$chunk),
                    );
                    $report['inserted'] += count($inserted);
                }
                $report['sites']++;
                $report['publications'] += count($publications);
            });
        }

        return $report;
    }
}
