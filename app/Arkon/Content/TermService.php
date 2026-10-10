<?php

namespace App\Arkon\Content;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Time;
use App\Arkon\Support\Uuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Categories, tags and other taxonomy terms of a site. Terms are not versioned: they are names
 * that drafts point to (page_terms) and that publications record by id (content_meta.terms), so
 * renaming a term renames it everywhere at once, and deleting one removes it from drafts while
 * published content simply stops listing it. Editors may add terms (term.create); renaming and
 * deleting change what visitors see, so they need term.manage (owners and admins).
 */
final class TermService
{
    public function __construct(private readonly Authorizer $auth, private readonly Transactions $tx, private readonly AuditLog $audit) {}

    /**
     * One page of terms with the number of published items using each (`count`) and, for the
     * admin, of drafts using each (`draftCount`). Terms are public names: no authorization here;
     * callers that show drafts authorize first.
     *
     * @param  array{q?: string, slug?: ?string, parent?: ?string, ids?: ?list<string>, hideEmpty?: bool, sort?: string, page?: int, perPage?: int}  $o
     * @return array{items: list<array>, total: int}
     */
    public function browse(string $siteId, string $taxonomy, array $o = []): array
    {
        Taxonomies::get($taxonomy);
        $published = $this->publishedCounts($siteId);
        $q = DB::table('terms')->where('site_id', $siteId)->where('taxonomy', $taxonomy);
        if (($o['q'] ?? '') !== '') {
            $q->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($o['q'], '\\%_').'%')->orWhere('slug', 'ilike', '%'.addcslashes($o['q'], '\\%_').'%'));
        }
        if (isset($o['slug'])) {
            $q->where('slug', $o['slug']);
        }
        if (array_key_exists('parent', $o) && $o['parent'] !== null) {
            $o['parent'] === 'none' ? $q->whereNull('parent_id') : $q->where('parent_id', Uuid::isValid($o['parent']) ? $o['parent'] : null);
        }
        if (isset($o['ids'])) {
            $q->whereIn('id', array_values(array_filter($o['ids'], Uuid::isValid(...))));
        }
        if ($o['hideEmpty'] ?? false) {
            $q->whereIn('id', array_keys(array_filter($published)));
        }
        $total = (clone $q)->count();
        $sort = $o['sort'] ?? 'name';
        $byCount = $published;
        arsort($byCount);
        match (ltrim($sort, '-')) {
            'count' => $q->orderByRaw('array_position(?::uuid[], id) NULLS LAST', ['{'.implode(',', array_keys($byCount)).'}']),
            'slug' => $q->orderBy('slug', str_starts_with($sort, '-') ? 'desc' : 'asc'),
            default => $q->orderBy('name', str_starts_with($sort, '-') ? 'desc' : 'asc'),
        };
        $perPage = (int) ($o['perPage'] ?? 50);
        $rows = $q->orderBy('id')->offset(max(0, ((int) ($o['page'] ?? 1)) - 1) * $perPage)->limit($perPage)->get();
        $drafts = $rows->isEmpty() ? [] : DB::table('page_terms as t')->join('pages as p', 'p.id', '=', 't.page_id')->whereNull('p.deleted_at')->where('t.site_id', $siteId)
            ->whereIn('t.term_id', $rows->pluck('id'))->groupBy('t.term_id')->selectRaw('t.term_id, count(*) AS n')->pluck('n', 'term_id')->all();

        return ['items' => $rows->map(fn ($r) => self::present($r, (int) ($published[$r->id] ?? 0), (int) ($drafts[$r->id] ?? 0)))->all(), 'total' => $total];
    }

    public function find(string $siteId, string $taxonomy, string $id): array
    {
        $items = $this->browse($siteId, $taxonomy, ['ids' => [Input::id($id, Taxonomies::get($taxonomy)['label'])], 'perPage' => 1])['items'];

        return $items[0] ?? throw new NotFoundException(Taxonomies::get($taxonomy)['label']);
    }

    /** @param array{name: mixed, slug?: mixed, description?: mixed, parentId?: mixed} $input */
    public function create(SiteContext $ctx, string $taxonomy, array $input): array
    {
        $tax = Taxonomies::get($taxonomy);
        $v = $this->validated($input, $tax, true);

        return $this->tx->run(function () use ($ctx, $taxonomy, $v) {
            $this->auth->authorize($ctx, 'term.create');
            $this->lock($ctx->siteId);
            $this->assertParent($ctx->siteId, $taxonomy, $v['parentId'] ?? null, null);
            $slug = $v['slug'] ?? $this->freeSlug($ctx->siteId, $taxonomy, $v['name']);
            $id = Uuid::v7();
            $this->unique(fn () => DB::table('terms')->insert(['id' => $id, 'site_id' => $ctx->siteId, 'taxonomy' => $taxonomy, 'name' => $v['name'], 'slug' => $slug, 'description' => $v['description'] ?? '', 'parent_id' => $v['parentId'] ?? null]), $slug);
            $this->audit->forContext($ctx, 'term.create', 'term', $id, ['taxonomy' => $taxonomy, 'name' => $v['name']]);

            return $this->find($ctx->siteId, $taxonomy, $id);
        });
    }

    /** @param array{name?: mixed, slug?: mixed, description?: mixed, parentId?: mixed} $input */
    public function update(SiteContext $ctx, string $taxonomy, string $id, array $input): array
    {
        $tax = Taxonomies::get($taxonomy);
        $id = Input::id($id, $tax['label']);
        $v = $this->validated($input, $tax, false);

        return $this->tx->run(function () use ($ctx, $taxonomy, $id, $v, $input) {
            $this->auth->authorize($ctx, 'term.manage');
            $this->lock($ctx->siteId);
            $row = $this->row($ctx->siteId, $taxonomy, $id);
            if (array_key_exists('parentId', $input)) {
                $this->assertParent($ctx->siteId, $taxonomy, $v['parentId'] ?? null, $id);
            }
            $values = array_filter(['name' => $v['name'] ?? null, 'slug' => $v['slug'] ?? null, 'description' => $v['description'] ?? null], fn ($x) => $x !== null);
            if (array_key_exists('parentId', $input)) {
                $values['parent_id'] = $v['parentId'] ?? null;
            }
            $this->unique(fn () => DB::table('terms')->where('id', $id)->update([...$values, 'updated_at' => DB::raw('now()')]), $values['slug'] ?? $row->slug);
            $this->audit->forContext($ctx, 'term.update', 'term', $id, ['taxonomy' => $taxonomy, 'fields' => array_keys($values)]);

            return $this->find($ctx->siteId, $taxonomy, $id);
        });
    }

    /** Deletes a term: drafts lose it, its children move up one level, published content stops listing it. */
    public function delete(SiteContext $ctx, string $taxonomy, string $id): void
    {
        $tax = Taxonomies::get($taxonomy);
        $id = Input::id($id, $tax['label']);
        $this->tx->run(function () use ($ctx, $taxonomy, $id) {
            $this->auth->authorize($ctx, 'term.manage');
            $this->lock($ctx->siteId);
            $row = $this->row($ctx->siteId, $taxonomy, $id);
            DB::table('terms')->where('site_id', $ctx->siteId)->where('parent_id', $id)->update(['parent_id' => $row->parent_id]);
            DB::table('terms')->where('id', $id)->delete();
            $this->audit->forContext($ctx, 'term.delete', 'term', $id, ['taxonomy' => $taxonomy, 'name' => $row->name]);
        });
    }

    /**
     * Term ids for names: an existing term with that name or slug, else a new one (term.create).
     * Used by the API and AI actions that name categories and tags.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function idsForNames(SiteContext $ctx, string $taxonomy, array $names): array
    {
        Taxonomies::get($taxonomy);
        $ids = [];
        foreach (array_unique(array_map(fn ($n) => trim((string) $n), $names)) as $name) {
            if ($name === '') {
                continue;
            }
            $slug = Str::slug($name);
            $found = DB::table('terms')->where('site_id', $ctx->siteId)->where('taxonomy', $taxonomy)
                ->where(fn ($w) => $w->whereRaw('lower(name) = lower(?)', [$name])->orWhere('slug', $slug))->value('id');
            $ids[] = $found ?? $this->create($ctx, $taxonomy, ['name' => $name])['id'];
        }

        return array_values(array_unique($ids));
    }

    /** Throws unless every id is a term of this taxonomy on this site. */
    public static function assertIds(string $siteId, string $taxonomy, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        foreach ($ids as $id) {
            if (! Uuid::isValid($id)) {
                throw new ValidationException('Unknown '.strtolower(Taxonomies::get($taxonomy)['label']).'.', [['path' => $taxonomy, 'message' => "Unknown term {$id}"]]);
            }
        }
        $found = $ids === [] ? [] : DB::table('terms')->where('site_id', $siteId)->where('taxonomy', $taxonomy)->whereIn('id', $ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw new ValidationException('Unknown '.strtolower(Taxonomies::get($taxonomy)['label']).'.', [['path' => $taxonomy, 'message' => 'One or more terms do not exist in this site']]);
        }
    }

    /** @return array<string, int> published (live, not deleted) items per term id */
    public function publishedCounts(string $siteId): array
    {
        return collect(DB::select(
            "SELECT t.term_id, count(*) AS n FROM live_pages l
             JOIN publications p ON p.id = l.publication_id
             JOIN pages pg ON pg.site_id = l.site_id AND pg.id = l.page_id AND pg.deleted_at IS NULL
             CROSS JOIN LATERAL jsonb_array_elements_text(COALESCE(p.content_meta->'terms', '[]'::jsonb)) AS t(term_id)
             WHERE l.site_id = ? GROUP BY t.term_id",
            [$siteId],
        ))->mapWithKeys(fn ($r) => [$r->term_id => (int) $r->n])->all();
    }

    public static function present(object $r, int $count = 0, ?int $draftCount = null): array
    {
        return [
            'id' => $r->id, 'taxonomy' => $r->taxonomy, 'name' => $r->name, 'slug' => $r->slug, 'description' => $r->description,
            'parentId' => $r->parent_id, 'count' => $count, ...($draftCount === null ? [] : ['draftCount' => $draftCount]),
            'createdAt' => Time::iso($r->created_at), 'updatedAt' => Time::iso($r->updated_at),
        ];
    }

    private function validated(array $input, array $tax, bool $create): array
    {
        if (array_key_exists('slug', $input) && ($input['slug'] === '' || $input['slug'] === null)) {
            unset($input['slug']);
        }
        $v = Input::validate($input, [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/D'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parentId' => ['sometimes', 'nullable', 'uuid'],
        ]);
        if (isset($v['name'])) {
            $v['name'] = trim($v['name']);
            if ($v['name'] === '') {
                throw new ValidationException('Give it a name.', [['path' => 'name', 'message' => 'Give it a name.']]);
            }
        }
        if (array_key_exists('description', $v)) {
            $v['description'] = (string) $v['description'];
        }
        if (! $tax['hierarchical'] && ($v['parentId'] ?? null) !== null) {
            throw new ValidationException($tax['plural'].' cannot be nested.', [['path' => 'parentId', 'message' => $tax['plural'].' cannot be nested.']]);
        }

        return $v;
    }

    private function row(string $siteId, string $taxonomy, string $id): object
    {
        return DB::table('terms')->where('site_id', $siteId)->where('taxonomy', $taxonomy)->where('id', $id)->lockForUpdate()->first()
            ?? throw new NotFoundException(Taxonomies::get($taxonomy)['label']);
    }

    private function assertParent(string $siteId, string $taxonomy, ?string $parentId, ?string $self): void
    {
        $seen = [];
        for ($at = $parentId; $at !== null; $at = DB::table('terms')->where('id', $at)->value('parent_id')) {
            if ($at === $self || isset($seen[$at])) {
                throw new ValidationException('A category cannot be inside itself.', [['path' => 'parentId', 'message' => 'A category cannot be inside itself.']]);
            }
            $seen[$at] = true;
            if (! DB::table('terms')->where('site_id', $siteId)->where('taxonomy', $taxonomy)->where('id', $at)->exists()) {
                throw new ValidationException('The parent does not exist.', [['path' => 'parentId', 'message' => 'The parent does not exist.']]);
            }
        }
    }

    private function freeSlug(string $siteId, string $taxonomy, string $name): string
    {
        $base = Str::limit(Str::slug($name), 90, '') ?: 'term';
        $base = trim($base, '-') ?: 'term';
        $taken = DB::table('terms')->where('site_id', $siteId)->where('taxonomy', $taxonomy)->where('slug', 'like', $base.'%')->pluck('slug')->flip();
        for ($i = 1, $slug = $base; isset($taken[$slug]); $slug = $base.'-'.++$i);

        return $slug;
    }

    private function lock(string $siteId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.terms:'.$siteId]);
    }

    private function unique(callable $write, string $slug): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw new ConflictException("The slug {$slug} is already used.");
            }
            throw $e;
        }
    }
}
