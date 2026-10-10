<?php

namespace App\Arkon\Content;

use App\Arkon\Pages\PageService;
use App\Arkon\Support\Uuid;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model for pages, posts and future content types, in two views:
 *
 * - live: what visitors see. Title, URL, details and HTML come from the live publication (never
 *   the draft); items in the Trash or unpublished are absent. Used for anonymous API reads.
 * - draft: what members edit, with status draft | published | trash and whether the draft has
 *   unpublished changes. Callers authorize first (page.view and the token's read scope).
 *
 * Rows are normalized to one shape (see normalize()).
 */
final class ContentReader
{
    public const SORTS = ['published_at', 'modified_at', 'title', 'path'];

    public const STATUSES = ['published', 'draft', 'trash', 'any'];

    /**
     * @param  'live'|'draft'  $view
     * @param  array{status?: string, search?: ?string, slug?: ?string, path?: ?string, author?: ?string, terms?: array<string, list<string>>, after?: ?string, before?: ?string, ids?: list<string>}  $f
     * @return array{0: Builder, 1: array<string, string>} the query and the SQL expression of each sort field
     */
    public function query(string $siteId, string $kind, string $view, array $f = []): array
    {
        if ($view === 'live') {
            $q = DB::table('live_pages as l')
                ->join('publications as p', 'p.id', '=', 'l.publication_id')
                ->join('page_revisions as r', 'r.id', '=', 'p.revision_id')
                ->join('pages as pg', fn ($j) => $j->on('pg.site_id', '=', 'l.site_id')->on('pg.id', '=', 'l.page_id'))
                ->leftJoin('users as u', 'u.id', '=', 'pg.created_by')
                ->where('l.site_id', $siteId)->where('pg.kind', $kind)->whereNull('pg.deleted_at')
                ->select([
                    'pg.id', 'pg.kind', 'r.title', 'l.path', DB::raw("'published' AS status"), 'p.id as publication_id', 'p.content_meta',
                    DB::raw('COALESCE(pg.first_published_at, p.created_at) AS published_at'), 'p.created_at as modified_at',
                    'pg.created_by', 'u.name as author_name',
                ]);
            $title = 'r.title';
            $path = 'l.path';
            $published = 'COALESCE(pg.first_published_at, p.created_at)';
            $modified = 'p.created_at';
            $excerpt = "p.content_meta->>'excerpt'";
        } else {
            $q = DB::table('pages as pg')
                ->join('page_drafts as d', 'd.page_id', '=', 'pg.id')
                ->leftJoin('live_pages as l', 'l.page_id', '=', 'pg.id')
                ->leftJoin('publications as p', 'p.id', '=', 'l.publication_id')
                ->leftJoin('users as u', 'u.id', '=', 'pg.created_by')
                ->where('pg.site_id', $siteId)->where('pg.kind', $kind)->whereNull('pg.purged_at')
                ->select([
                    'pg.id', 'pg.kind', 'pg.title', 'pg.path', 'l.path as live_path', 'p.id as publication_id', 'p.revision_id as live_revision_id',
                    DB::raw("CASE WHEN pg.deleted_at IS NOT NULL THEN 'trash' WHEN l.page_id IS NULL THEN 'draft' ELSE 'published' END AS status"),
                    'pg.excerpt', 'pg.featured_media_id', DB::raw('(SELECT array_agg(pt.term_id ORDER BY pt.term_id) FROM page_terms pt WHERE pt.page_id = pg.id) AS term_ids'),
                    'pg.first_published_at as published_at', 'd.updated_at as modified_at', 'd.version', 'd.checkpoint_version', 'd.checkpoint_revision_id',
                    'pg.deleted_at', 'pg.created_by', 'u.name as author_name',
                ]);
            match ($f['status'] ?? 'any') {
                'draft' => $q->whereNull('pg.deleted_at')->whereNull('l.page_id'),
                'published' => $q->whereNull('pg.deleted_at')->whereNotNull('l.page_id'),
                'trash' => $q->whereNotNull('pg.deleted_at'),
                default => $q->whereNull('pg.deleted_at'),
            };
            $title = 'pg.title';
            $path = 'pg.path';
            $published = 'pg.first_published_at';
            $modified = 'd.updated_at';
            $excerpt = 'pg.excerpt';
        }
        if (isset($f['ids'])) {
            $q->whereIn('pg.id', array_values(array_filter($f['ids'], Uuid::isValid(...))));
        }
        if (($f['search'] ?? '') !== '') {
            $like = '%'.addcslashes($f['search'], '\\%_').'%';
            $q->where(fn ($w) => $w->where(DB::raw($title), 'ilike', $like)->orWhere(DB::raw($excerpt), 'ilike', $like));
        }
        if (isset($f['path'])) {
            $q->where(DB::raw($path), $f['path']);
        }
        if (isset($f['slug'])) {
            $q->whereRaw("regexp_replace({$path}, '^.*/', '') = ?", [$f['slug']]);
        }
        if (isset($f['author'])) {
            $q->where('pg.created_by', Uuid::isValid($f['author']) ? $f['author'] : null);
        }
        foreach ($f['terms'] ?? [] as $ids) {
            $ids = array_values(array_filter($ids, Uuid::isValid(...)));
            $view === 'live'
                ? $q->whereRaw("jsonb_exists_any(COALESCE(p.content_meta->'terms', '[]'::jsonb), ?::text[])", ['{'.implode(',', $ids).'}'])
                : $q->whereExists(fn ($e) => $e->from('page_terms as pt')->whereColumn('pt.page_id', 'pg.id')->whereRaw('pt.term_id = ANY(?::uuid[])', ['{'.implode(',', $ids).'}']));
        }
        if (isset($f['after'])) {
            $q->whereRaw("{$published} > ?", [$f['after']]);
        }
        if (isset($f['before'])) {
            $q->whereRaw("{$published} < ?", [$f['before']]);
        }

        return [$q, ['published_at' => $published, 'modified_at' => $modified, 'title' => $title, 'path' => $path]];
    }

    /** @return array{rows: list<array>, total: int} */
    public function page(array $query, string $sort, int $page, int $perPage): array
    {
        [$q, $columns] = $query;
        $total = (clone $q)->reorder()->count();
        $field = ltrim($sort, '-');
        $q->orderByRaw($columns[$field].' '.(str_starts_with($sort, '-') ? 'DESC NULLS LAST' : 'ASC NULLS LAST'))->orderBy('pg.id');
        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return ['rows' => $rows->map(fn ($r) => self::normalize($r))->all(), 'total' => $total];
    }

    public function find(string $siteId, string $kind, string $view, string $id, array $f = []): ?array
    {
        if (! Uuid::isValid($id)) {
            return null;
        }
        $row = $this->query($siteId, $kind, $view, [...$f, 'ids' => [$id]])[0]->first();

        return $row ? self::normalize($row) : null;
    }

    /**
     * @return array{id: string, kind: string, title: string, path: string, status: string, publicationId: ?string, excerpt: string, featuredMediaId: ?string, terms: list<string>, publishedAt: ?string, modifiedAt: ?string, authorId: ?string, authorName: ?string, version: ?int, hasChanges: ?bool, livePath: ?string}
     */
    public static function normalize(object $r): array
    {
        $live = property_exists($r, 'content_meta');
        $details = $live ? ContentDetails::fromPublication($r->content_meta) : [
            'excerpt' => (string) $r->excerpt, 'featuredMediaId' => $r->featured_media_id,
            'terms' => $r->term_ids ? array_values(array_filter(explode(',', trim($r->term_ids, '{}')))) : [],
        ];

        return [
            'id' => $r->id, 'kind' => $r->kind, 'title' => $r->title, 'path' => $r->path, 'status' => $r->status,
            'publicationId' => $r->publication_id, 'excerpt' => $details['excerpt'], 'featuredMediaId' => $details['featuredMediaId'], 'terms' => $details['terms'],
            'publishedAt' => $r->published_at, 'modifiedAt' => $r->modified_at, 'authorId' => $r->created_by, 'authorName' => $r->author_name,
            'version' => $live ? null : (int) $r->version,
            'hasChanges' => $live || $r->status !== 'published' ? null : PageService::statusOf($r, $r->live_revision_id) === 'changed',
            'livePath' => $live ? $r->path : $r->live_path,
        ];
    }
}
