<?php

namespace App\Arkon\Seo;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The SEO workspace: the site score, issues ranked by impact, technical diagnostics and page rows.
 * Site-wide figures come from the published pages' cached reports; only the listed drafts are
 * rendered. Batch site-local lookups: no HTTP crawler and no external link probes.
 */
final class SeoDashboard
{
    public const PER_PAGE = 25;

    /** Health filters: the published label each one matches (unpublished pages have no live score). */
    public const FILTERS = ['all' => null, 'excellent' => 'Excellent', 'good' => 'Good', 'improvement' => 'Needs improvement', 'attention' => 'Needs attention', 'unpublished' => null];

    /** Short names for checks, for lists where the full message would be too long. */
    private const ISSUE_LABELS = [
        'title' => 'Missing search title',
        'description' => 'Missing meta description',
        'h1' => 'Primary heading issue',
        'hierarchy' => 'Skipped heading levels',
        'canonical' => 'No valid canonical URL',
        'viewport' => 'Missing mobile viewport',
        'alt' => 'Images without alt text',
        'links' => 'Placeholder or broken links',
        'social' => 'Missing social title',
        'title-length' => 'Search title length',
        'description-length' => 'Description length',
        'internal-links' => 'No internal links',
        'schema' => 'Structured data',
        'topic' => 'Focus topic not covered',
        'schema-purpose' => 'Article schema on a non-article page',
    ];

    private const SEVERITY = ['critical' => 3, 'important' => 2, 'suggestion' => 1];

    public function __construct(private PageStore $store, private SeoAnalysis $analysis) {}

    public function overview(string $siteId, int $page, string $q = '', string $status = 'all'): array
    {
        $status = array_key_exists($status, self::FILTERS) ? $status : 'all';
        $origin = $this->store->origin($siteId) ?? '';
        $live = DB::table('live_pages as l')->join('publications as pub', 'pub.id', '=', 'l.publication_id')->join('pages as p', 'p.id', '=', 'l.page_id')
            ->where('l.site_id', $siteId)->get(['l.page_id', 'l.path', 'p.title as page_title', 'pub.id', 'pub.html']);
        $paths = $live->pluck('path')->all();
        $redirects = DB::table('redirects as r')->join('live_pages as l', fn ($j) => $j->on('r.site_id', '=', 'l.site_id')->on('r.page_id', '=', 'l.page_id'))->where('r.site_id', $siteId)->pluck('r.from_path')->all();
        $destinations = [...$paths, ...$redirects];
        $distribution = ['Excellent' => 0, 'Good' => 0, 'Needs improvement' => 0, 'Needs attention' => 0];
        $reports = [];
        $titles = [];
        $canonicalCounts = [];
        foreach ($live as $row) {
            $report = Cache::remember('seo:published:'.hash('sha256', $row->id.Json::encode($destinations)), 300, fn () => $this->analysis->analyze($row->html, [], $destinations, $origin));
            $reports[$row->page_id] = $report;
            $titles[$row->page_id] = $row->page_title;
            $distribution[$report['label']]++;
            if ($report['canonical'] !== '') {
                $canonicalCounts[$report['canonical']] = ($canonicalCounts[$report['canonical']] ?? 0) + 1;
            }
        }

        $query = DB::table('pages as p')->join('page_drafts as d', fn ($j) => $j->on('p.site_id', '=', 'd.site_id')->on('p.id', '=', 'd.page_id'))->where('p.site_id', $siteId)->whereNull('p.deleted_at');
        $allPages = (clone $query)->count();
        $counts = ['all' => $allPages, 'unpublished' => $allPages - count($reports)];
        foreach (self::FILTERS as $key => $label) {
            if ($label !== null) {
                $counts[$key] = $distribution[$label];
            }
        }
        if ($q !== '') {
            // strpos is literal: '%' and '_' in search text are not wildcards.
            $query->whereRaw('(strpos(lower(p.title), lower(?)) > 0 OR strpos(lower(p.path), lower(?)) > 0)', [$q, $q]);
        }
        if ($status === 'unpublished') {
            $query->whereNotIn('p.id', array_keys($reports));
        } elseif (self::FILTERS[$status] !== null) {
            $query->whereIn('p.id', array_keys(array_filter($reports, fn ($r) => $r['label'] === self::FILTERS[$status])));
        }
        $total = (clone $query)->count();
        $page = min(max(1, $page), max(1, (int) ceil($total / self::PER_PAGE)));
        $rows = [];
        foreach ($query->orderBy('p.title')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get(['p.id', 'p.title', 'p.path', 'd.document']) as $row) {
            try {
                $out = $this->store->renderForSite($siteId, $row->title, $row->path, $this->store->document($row->document), false);
                $draft = Cache::remember('seo:draft:'.hash('sha256', $siteId.$out['html'].Json::encode($destinations).$row->path), 60, fn () => $this->analysis->analyze($out['html'], [], [...$destinations, $row->path], $origin));
            } catch (ValidationException) {
                $draft = null;
            }
            $published = $reports[$row->id] ?? null;
            // The draft is what gets edited, so its findings drive the row; without one, what is live.
            $findings = self::issues($draft ?? $published);
            $rows[] = [
                'id' => $row->id, 'title' => $row->title, 'path' => $row->path, 'draft' => $draft, 'published' => $published, 'needsRepair' => $draft === null,
                'issues' => array_values(array_filter($findings, fn ($i) => $i['severity'] !== 'suggestion')),
                'suggestions' => count(array_filter($findings, fn ($i) => $i['severity'] === 'suggestion')),
            ];
        }
        $scores = array_column($reports, 'score');
        $score = $scores ? (int) round(array_sum($scores) / count($scores)) : null;

        return [
            'rows' => $rows, 'page' => $page, 'total' => $total, 'perPage' => self::PER_PAGE, 'q' => $q, 'status' => $status, 'counts' => $counts, 'origin' => $origin,
            'summary' => ['score' => $score, 'label' => $score === null ? null : self::label($score), 'published' => count($reports), 'pages' => $allPages, 'distribution' => $distribution],
            'attention' => self::attention($reports, $titles),
            'technical' => self::technical($reports, $canonicalCounts),
        ];
    }

    /** The score bands of SeoAnalysis. */
    public static function label(int $score): string
    {
        return $score >= 85 ? 'Excellent' : ($score >= 70 ? 'Good' : ($score >= 50 ? 'Needs improvement' : 'Needs attention'));
    }

    /**
     * A report's unresolved checks, most severe and most heavily weighted first. Notices (such as a
     * deliberate noindex) are choices, not issues.
     *
     * @return list<array{id: string, label: string, message: string, severity: string, aiFixable: bool}>
     */
    private static function issues(?array $report): array
    {
        $checks = array_values(array_filter($report['checks'] ?? [], fn ($c) => isset(self::SEVERITY[$c['status']])));
        usort($checks, fn ($a, $b) => [self::SEVERITY[$b['status']], $b['weight']] <=> [self::SEVERITY[$a['status']], $a['weight']]);

        return array_map(fn ($c) => ['id' => $c['id'], 'label' => self::ISSUE_LABELS[$c['id']] ?? $c['message'], 'message' => $c['message'], 'severity' => $c['status'], 'aiFixable' => $c['fixType'] !== null], $checks);
    }

    /**
     * Issues on published pages that cost points, grouped by check and ranked by impact (points lost
     * across the site), then severity. Suggestions are left to the page rows.
     */
    private static function attention(array $reports, array $titles): array
    {
        $groups = [];
        foreach ($reports as $pageId => $report) {
            foreach ($report['checks'] as $check) {
                if (! in_array($check['status'], ['critical', 'important'], true)) {
                    continue;
                }
                $group = &$groups[$check['id']];
                $group ??= ['id' => $check['id'], 'label' => self::ISSUE_LABELS[$check['id']] ?? $check['message'], 'severity' => $check['status'], 'aiFixable' => $check['fixType'] !== null, 'impact' => 0, 'pages' => []];
                $group['impact'] += $check['weight'] - $check['earned'];
                $group['pages'][] = ['id' => $pageId, 'title' => $titles[$pageId] ?? ''];
                unset($group);
            }
        }
        usort($groups, fn ($a, $b) => [$b['impact'], self::SEVERITY[$b['severity']]] <=> [$a['impact'], self::SEVERITY[$a['severity']]]);

        return $groups;
    }

    /**
     * Site-wide checks on what is live, grouped for scanning. Status: ok, warning, error, or info (a
     * choice worth confirming, such as noindex).
     */
    private static function technical(array $reports, array $canonicalCounts): array
    {
        $published = count($reports);
        $count = fn (callable $test) => count(array_filter($reports, $test));
        $failed = fn (string $id) => $count(fn ($r) => (bool) array_filter($r['checks'], fn ($c) => $c['id'] === $id && $c['status'] !== 'passed'));
        $pages = fn (int $n) => $n === 1 ? '1 page' : "{$n} pages";
        if ($published === 0) {
            return [];
        }
        $noindex = $count(fn ($r) => $r['noindex']);
        $broken = array_values(array_unique(array_merge(...array_values(array_map(fn ($r) => $r['brokenLinks'], $reports)))));
        $placeholders = $count(fn ($r) => $r['brokenLinks'] === [] && (bool) array_filter($r['checks'], fn ($c) => $c['id'] === 'links' && $c['status'] !== 'passed'));
        $duplicateTitles = array_keys(array_filter(array_count_values(array_filter(array_column($reports, 'title'))), fn ($n) => $n > 1));
        $duplicateCanonicals = array_keys(array_filter($canonicalCounts, fn ($n) => $n > 1));
        $missingTitles = $count(fn ($r) => $r['title'] === '');
        $missingDescriptions = $count(fn ($r) => $r['description'] === '');
        $missingCanonical = $failed('canonical');
        $withSchema = $count(fn ($r) => $r['schemaPresent']);
        $malformedSchema = $count(fn ($r) => (bool) array_filter($r['checks'], fn ($c) => $c['id'] === 'schema' && $c['status'] === 'critical'));
        $indexable = $published - $noindex;

        return [
            ['group' => 'Indexing', 'items' => [
                ['id' => 'indexing', 'label' => 'Search indexing', 'status' => $noindex ? 'info' : 'ok', 'detail' => $noindex ? "{$pages($noindex)} ask search engines not to index them. Confirm this is intended." : 'Every published page can be indexed.'],
                ['id' => 'sitemap', 'label' => 'Sitemap', 'status' => $indexable ? 'ok' : 'warning', 'detail' => $indexable ? "Lists {$pages($indexable)}, updated when you publish." : 'No indexable published pages to list yet.', 'href' => '/sitemap.xml'],
                ['id' => 'robots', 'label' => 'Robots.txt', 'status' => 'ok', 'detail' => 'Served by Arkon. Keeps crawlers out of the admin and points them to the sitemap.', 'href' => '/robots.txt'],
            ]],
            ['group' => 'Metadata', 'items' => [
                ['id' => 'titles', 'label' => 'Search titles', 'status' => $missingTitles ? 'error' : ($duplicateTitles ? 'warning' : 'ok'), 'detail' => $missingTitles ? "{$pages($missingTitles)} without a search title." : ($duplicateTitles ? 'Used on more than one page: '.implode(', ', array_slice($duplicateTitles, 0, 3)) : 'Every page has its own title.')],
                ['id' => 'descriptions', 'label' => 'Meta descriptions', 'status' => $missingDescriptions ? 'warning' : 'ok', 'detail' => $missingDescriptions ? "{$pages($missingDescriptions)} without a description." : 'Every page has a description.'],
                ['id' => 'structured-data', 'label' => 'Structured data', 'status' => $malformedSchema ? 'error' : 'ok', 'detail' => $malformedSchema ? "{$pages($malformedSchema)} with malformed structured data." : ($withSchema === $published ? 'Declared on every page.' : "Declared on {$withSchema} of {$published} pages.")],
            ]],
            ['group' => 'Links and URLs', 'items' => [
                ['id' => 'destinations', 'label' => 'Internal destinations', 'status' => $broken ? 'error' : ($placeholders ? 'warning' : 'ok'), 'detail' => $broken ? 'Links to pages that are not live: '.implode(', ', array_slice($broken, 0, 3)) : ($placeholders ? "{$pages($placeholders)} with placeholder links (#)." : 'Every internal link reaches a live page.')],
                ['id' => 'canonical', 'label' => 'Canonical URLs', 'status' => $missingCanonical ? 'error' : ($duplicateCanonicals ? 'warning' : 'ok'), 'detail' => $missingCanonical ? "{$pages($missingCanonical)} without a valid canonical URL. Check the site URL." : ($duplicateCanonicals ? 'Shared by more than one page: '.implode(', ', array_slice($duplicateCanonicals, 0, 2)) : 'Each page points to its own URL.')],
            ]],
        ];
    }
}
