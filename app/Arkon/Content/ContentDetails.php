<?php

namespace App\Arkon\Content;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * A content item's details besides its document, title and URL: the excerpt, the featured
 * image and its terms (categories, tags, …), for kinds that have them (posts).
 *
 * Like the title, the stored details are the draft's. Publishing records them in
 * publications.content_meta (snapshot()), so the live site and the API only show new details
 * once the item is published again, and the featured image becomes public with it.
 */
final class ContentDetails
{
    public function __construct(private readonly Authorizer $auth, private readonly Transactions $tx, private readonly PageStore $store, private readonly AuditLog $audit) {}

    /**
     * Draft details by page id.
     *
     * @param  list<string>  $pageIds
     * @return array<string, array{excerpt: string, featuredMediaId: ?string, terms: array<string, list<string>>}>
     */
    public function drafts(string $siteId, array $pageIds): array
    {
        $pageIds = array_values(array_filter($pageIds, Uuid::isValid(...)));
        if ($pageIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('pages')->where('site_id', $siteId)->whereIn('id', $pageIds)->get(['id', 'kind', 'excerpt', 'featured_media_id']) as $p) {
            $out[$p->id] = ['excerpt' => $p->excerpt, 'featuredMediaId' => $p->featured_media_id, 'terms' => array_fill_keys(ContentTypes::get($p->kind)['taxonomies'], [])];
        }
        foreach (DB::table('page_terms as pt')->join('terms as t', 't.id', '=', 'pt.term_id')->where('pt.site_id', $siteId)->whereIn('pt.page_id', $pageIds)->orderBy('t.name')->get(['pt.page_id', 't.id', 't.taxonomy']) as $r) {
            $out[$r->page_id]['terms'][$r->taxonomy][] = $r->id;
        }

        return $out;
    }

    /**
     * Changes the given details of a draft (fields left out stay as they are). Nothing changes on
     * the live site until the item is published.
     *
     * @param  array{excerpt?: mixed, featuredMediaId?: mixed, terms?: mixed}  $input
     * @return array{excerpt: string, featuredMediaId: ?string, terms: array<string, list<string>>}
     */
    public function update(SiteContext $ctx, string $pageId, array $input): array
    {
        $pageId = Input::id($pageId);
        $v = self::validated($input);

        return $this->tx->run(function () use ($ctx, $pageId, $v) {
            $this->auth->authorize($ctx, 'page.edit');
            [$page] = $this->store->lockForWrite($ctx->siteId, $pageId);
            $this->updateLocked($ctx, $page, $v);

            return $this->drafts($ctx->siteId, [$pageId])[$pageId];
        });
    }

    /** The validated-input part of update(), for callers that already hold the page's write lock. */
    public function updateLocked(SiteContext $ctx, object $page, array $v): void
    {
        if ($v === []) {
            return;
        }
        $this->auth->authorize($ctx, 'page.edit');
        $type = ContentTypes::get($page->kind);
        if (! $type['details'] && (array_key_exists('excerpt', $v) || array_key_exists('featuredMediaId', $v))) {
            throw new ValidationException($type['plural'].' have no excerpt or featured image.');
        }
        $values = [];
        if (array_key_exists('excerpt', $v)) {
            $values['excerpt'] = $v['excerpt'];
        }
        if (array_key_exists('featuredMediaId', $v)) {
            $id = $v['featuredMediaId'];
            if ($id !== null && ! DB::table('media_assets')->where('site_id', $ctx->siteId)->where('id', $id)->whereNull('archived_at')->exists()) {
                throw new ValidationException('The featured image does not exist in this site\'s media library.', [['path' => 'featuredMediaId', 'message' => 'Unknown image']]);
            }
            $values['featured_media_id'] = $id;
        }
        foreach ($v['terms'] ?? [] as $taxonomy => $ids) {
            if (! ContentTypes::uses($page->kind, $taxonomy)) {
                throw new ValidationException($type['plural'].' do not use '.strtolower(Taxonomies::get($taxonomy)['plural']).'.');
            }
            TermService::assertIds($ctx->siteId, $taxonomy, $ids);
            DB::table('page_terms')->where('page_id', $page->id)->whereIn('term_id', DB::table('terms')->where('site_id', $ctx->siteId)->where('taxonomy', $taxonomy)->select('id'))->delete();
            DB::table('page_terms')->insert(array_map(fn ($id) => ['site_id' => $ctx->siteId, 'page_id' => $page->id, 'term_id' => $id], array_values(array_unique($ids))));
        }
        DB::table('pages')->where('id', $page->id)->update([...$values, 'updated_at' => DB::raw('now()')]);
        $this->audit->forContext($ctx, 'page.details.save', 'page', $page->id, ['fields' => [...array_keys($values), ...array_keys($v['terms'] ?? [])]]);
    }

    /**
     * What publishing records about an item besides its document (publications.content_meta):
     * read under the page's write lock, inside the publish transaction.
     *
     * @return array{kind: string, excerpt: string, featuredMediaId: ?string, terms: list<string>}
     */
    public static function snapshot(string $siteId, string $pageId): array
    {
        $page = DB::table('pages')->where('site_id', $siteId)->where('id', $pageId)->first(['kind', 'excerpt', 'featured_media_id']);
        $terms = DB::table('page_terms')->where('site_id', $siteId)->where('page_id', $pageId)->orderBy('term_id')->pluck('term_id')->all();

        return ['kind' => $page->kind, 'excerpt' => $page->excerpt, 'featuredMediaId' => $page->featured_media_id, 'terms' => $terms];
    }

    /** @param array{excerpt?: mixed, featuredMediaId?: mixed, terms?: mixed} $input */
    public static function validated(array $input): array
    {
        $v = Input::validate($input, [
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'featuredMediaId' => ['sometimes', 'nullable', 'uuid'],
            'terms' => ['sometimes', 'array'],
            'terms.*' => ['array', 'max:50'],
            'terms.*.*' => ['string'],
        ]);
        if (array_key_exists('excerpt', $v)) {
            $v['excerpt'] = trim((string) $v['excerpt']);
        }
        foreach (array_keys($v['terms'] ?? []) as $taxonomy) {
            Taxonomies::get($taxonomy);
        }

        return $v;
    }

    /** content_meta as stored, or the empty details of publications made before it existed. */
    public static function fromPublication(?string $json): array
    {
        $meta = $json ? Json::toArray(Json::decode($json)) : [];

        return ['kind' => $meta['kind'] ?? 'page', 'excerpt' => (string) ($meta['excerpt'] ?? ''), 'featuredMediaId' => $meta['featuredMediaId'] ?? null, 'terms' => $meta['terms'] ?? []];
    }
}
