<?php

namespace App\Arkon\Seo;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Batch site-local lookups. No HTTP crawler and no external link probes. */
final class SeoDashboard
{
    public function __construct(private PageStore $store, private SeoAnalysis $analysis) {}

    public function overview(string $siteId, int $page): array
    {
        $origin = $this->store->origin($siteId) ?? '';
        $live = DB::table('live_pages as l')->join('publications as pub', 'pub.id', '=', 'l.publication_id')->where('l.site_id', $siteId)->get(['l.page_id', 'l.path', 'pub.id', 'pub.html']);
        $paths = $live->pluck('path')->all();
        $redirects = DB::table('redirects as r')->join('live_pages as l', fn ($j) => $j->on('r.site_id', '=', 'l.site_id')->on('r.page_id', '=', 'l.page_id'))->where('r.site_id', $siteId)->pluck('r.from_path')->all();
        $destinations = [...$paths, ...$redirects];
        $distribution = ['Excellent' => 0, 'Good' => 0, 'Needs improvement' => 0, 'Needs attention' => 0];
        $reports = [];
        $canonicalCounts = [];
        foreach ($live as $row) {
            $report = Cache::remember('seo:published:'.hash('sha256', $row->id.Json::encode($destinations)), 300, fn () => $this->analysis->analyze($row->html, [], $destinations, $origin));
            $reports[$row->page_id] = $report;
            $distribution[$report['label']]++;
            if ($report['canonical'] !== '') {
                $canonicalCounts[$report['canonical']] = ($canonicalCounts[$report['canonical']] ?? 0) + 1;
            }
        }
        $query = DB::table('pages as p')->join('page_drafts as d', fn ($j) => $j->on('p.site_id', '=', 'd.site_id')->on('p.id', '=', 'd.page_id'))->where('p.site_id', $siteId)->whereNull('p.deleted_at');
        $total = (clone $query)->count();
        $page = min(max(1, $page), max(1, (int) ceil($total / 50)));
        $rows = [];
        foreach ($query->orderBy('p.title')->offset(($page - 1) * 50)->limit(50)->get(['p.id', 'p.title', 'p.path', 'd.document', 'd.version']) as $row) {
            try {
                $out = $this->store->renderForSite($siteId, $row->title, $row->path, $this->store->document($row->document), false);
                $draft = Cache::remember('seo:draft:'.hash('sha256', $siteId.$out['html'].Json::encode($destinations).$row->path), 60, fn () => $this->analysis->analyze($out['html'], [], [...$destinations, $row->path], $origin));
                $error = null;
            } catch (ValidationException $e) {
                $draft = null;
                $error = 'Repair this draft before analyzing SEO.';
            }
            $published = $reports[$row->id] ?? null;
            $liveIssues = array_values(array_filter($published['checks'] ?? [], fn ($c) => $c['status'] !== 'passed' && $c['status'] !== 'notice'));
            $issues = array_values(array_filter($draft['checks'] ?? [], fn ($c) => $c['status'] !== 'passed' && $c['status'] !== 'notice'));
            usort($issues, fn ($a, $b) => $b['weight'] <=> $a['weight']);
            usort($liveIssues, fn ($a, $b) => $b['weight'] <=> $a['weight']);
            $rows[] = ['id' => $row->id, 'title' => $row->title, 'path' => $row->path, 'draft' => $draft, 'published' => $published, 'issue' => $error ?? (isset($liveIssues[0]) ? 'Live: '.$liveIssues[0]['message'] : ($issues[0]['message'] ?? 'No scored issues detected.'))];
        }
        $scores = array_column($reports, 'score');

        return ['rows' => $rows, 'page' => $page, 'total' => $total, 'summary' => ['score' => $scores ? round(array_sum($scores) / count($scores)) : null, 'published' => count($reports), 'distribution' => $distribution, 'noindex' => count(array_filter($reports, fn ($r) => $r['noindex'])), 'brokenLinks' => array_values(array_unique(array_merge(...array_values(array_map(fn ($r) => $r['brokenLinks'], $reports))))), 'missingDescriptions' => count(array_filter($reports, fn ($r) => $r['description'] === '')), 'duplicateTitles' => array_keys(array_filter(array_count_values(array_column($reports, 'title')), fn ($count) => $count > 1)), 'duplicateCanonicals' => array_keys(array_filter($canonicalCounts, fn ($count) => $count > 1))], 'origin' => $origin];
    }
}
