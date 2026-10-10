<?php

namespace App\Http\Controllers\Api\V1;

use App\Arkon\Content\ContentItems;
use App\Arkon\Content\ContentReader;
use App\Arkon\Content\ContentTypes;
use App\Arkon\Content\RenderedOutput;
use App\Arkon\Content\Taxonomies;
use App\Arkon\Pages\PageManagement;
use App\Arkon\Pages\PageService;
use App\Arkon\Seo\PageSeo;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Uuid;
use App\Http\Api\ApiError;
use App\Http\Api\ApiPrincipal;
use App\Http\Api\ApiResponse;
use App\Http\Api\Pagination;
use App\Http\Api\Resources\ContentResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/{pages|posts|…}: one controller for every content type (routes come from the
 * ContentTypes registry). HTTP only: reads go through ContentReader, writes through
 * ContentItems, which composes the same domain services the admin uses.
 *
 * Anonymous requests see published content only, as visitors do. A token with read:{type} may
 * ask for drafts and the Trash (`status`) or the draft state of published items (`context=edit`).
 */
final class ContentController extends Controller
{
    public function __construct(private readonly ContentReader $reader, private readonly ContentItems $items, private readonly Authorizer $auth) {}

    public function index(Request $request): JsonResponse
    {
        $kind = (string) $request->route('kind');
        $type = ContentTypes::get($kind);
        $taxonomyParams = array_fill_keys($type['taxonomies'], ['sometimes', 'string', 'max:500']);
        $v = ApiResponse::query($request, [
            'status' => ['sometimes', 'in:'.implode(',', ContentReader::STATUSES)], 'context' => ['sometimes', 'in:view,edit'],
            'search' => ['sometimes', 'string', 'max:200'], 'slug' => ['sometimes', 'string', 'max:100'], 'path' => ['sometimes', 'string', 'max:2000'],
            'author' => ['sometimes', 'uuid'], 'after' => ['sometimes', 'date'], 'before' => ['sometimes', 'date'],
            'include' => ['sometimes', 'string'], 'sort' => ['sometimes', 'string'], 'page' => ['sometimes'], 'per_page' => ['sometimes'],
            ...$taxonomyParams,
        ]);
        $page = Pagination::from($request);
        $sort = ApiResponse::sort($request, ContentReader::SORTS, $type['details'] ? '-published_at' : 'path');
        $include = ApiResponse::includes($request, ['content', 'seo']);
        $status = $v['status'] ?? 'published';
        $member = $this->member($request, $type, $status !== 'published' || ($v['context'] ?? 'view') === 'edit');
        $filters = [
            'status' => $status, 'search' => $v['search'] ?? null, 'slug' => $v['slug'] ?? null, 'path' => $v['path'] ?? null, 'author' => $v['author'] ?? null,
            'after' => $v['after'] ?? null, 'before' => $v['before'] ?? null,
            'terms' => $this->termFilters(ApiPrincipal::of($request)->siteId, $type, $v),
        ];
        $result = $this->reader->page($this->reader->query(ApiPrincipal::of($request)->siteId, $kind, $member ? 'draft' : 'live', array_filter($filters, fn ($x) => $x !== null)), $sort, $page->page, $page->perPage);
        $rendered = $include === [] ? [] : $this->rendered($request, $result['rows'], $member);

        return ApiResponse::collection($request, $this->resource($request, $member)->many($result['rows'], $rendered, $include), $page, $result['total']);
    }

    public function show(Request $request): JsonResponse
    {
        [$kind, $id] = [(string) $request->route('kind'), (string) $request->route('id')];
        $type = ContentTypes::get($kind);
        $v = ApiResponse::query($request, ['context' => ['sometimes', 'in:view,edit']]);
        $principal = ApiPrincipal::of($request);
        $row = null;
        if (($v['context'] ?? 'view') === 'view') {
            $row = $this->reader->find($principal->siteId, $kind, 'live', $id);
        }
        // Not live (or the edit view asked for): members with the read scope see the draft or Trash.
        $member = $row === null && $principal->authenticated() && ($principal->has('read:'.$type['collection']) || ($v['context'] ?? 'view') === 'edit');
        if ($member) {
            $this->member($request, $type, true);
            $row = $this->reader->find($principal->siteId, $kind, 'draft', $id, ['status' => 'any']) ?? $this->reader->find($principal->siteId, $kind, 'draft', $id, ['status' => 'trash']);
        }
        if ($row === null) {
            throw ApiError::notFound($type['label']);
        }
        $data = $this->resource($request, $member)->many([$row], $this->rendered($request, [$row], $member), ['content', 'seo'])[0];

        return ApiResponse::item($request, $data, lastModified: $member ? null : $row['modifiedAt']);
    }

    public function store(Request $request): JsonResponse
    {
        $kind = (string) $request->route('kind');
        $type = ContentTypes::get($kind);
        $ctx = ApiPrincipal::of($request)->require('write:'.$type['collection']);
        $created = $this->items->create($ctx, $kind, [...$this->input($request, $type), 'requestKey' => $this->idempotencyKey($request)]);
        $row = $this->reader->find($ctx->siteId, $kind, 'draft', $created['id'], ['status' => 'any']);
        $data = $this->resource($request, true)->many([$row], $this->rendered($request, [$row], true), ['content', 'seo'])[0];

        return ApiResponse::item($request, $data, $created['replayed'] ? 200 : 201)->header('Location', $request->url().'/'.$created['id']);
    }

    public function update(Request $request): JsonResponse
    {
        [$kind, $id] = [(string) $request->route('kind'), (string) $request->route('id')];
        $type = ContentTypes::get($kind);
        $ctx = ApiPrincipal::of($request)->require('write:'.$type['collection']);
        $input = $this->input($request, $type, partial: true);
        if ($request->json()->has('version')) {
            $input['version'] = $request->json('version');
        }
        $this->items->update($ctx, $kind, $id, $input);
        $row = $this->reader->find($ctx->siteId, $kind, 'draft', $id, ['status' => 'any']);
        $data = $this->resource($request, true)->many([$row], $this->rendered($request, [$row], true), ['content', 'seo'])[0];

        return ApiResponse::item($request, $data);
    }

    /** Moves the item to the Trash; `?force=true` deletes an item that is already in the Trash permanently. */
    public function destroy(Request $request): Response
    {
        [$kind, $id] = [(string) $request->route('kind'), (string) $request->route('id')];
        $type = ContentTypes::get($kind);
        $ctx = ApiPrincipal::of($request)->require('write:'.$type['collection']);
        $v = ApiResponse::query($request, ['force' => ['sometimes', 'in:true,false,1,0']]);
        if (in_array($v['force'] ?? 'false', ['true', '1'], true)) {
            app(PageManagement::class)->purge($ctx, $this->items->assertKind($ctx->siteId, $kind, $id, trashed: true));
        } else {
            $this->items->trash($ctx, $kind, $id);
        }

        return ApiResponse::noContent();
    }

    public function restore(Request $request): JsonResponse
    {
        [$kind, $id] = [(string) $request->route('kind'), (string) $request->route('id')];
        $type = ContentTypes::get($kind);
        $ctx = ApiPrincipal::of($request)->require('write:'.$type['collection']);
        app(PageManagement::class)->restore($ctx, $this->items->assertKind($ctx->siteId, $kind, $id, trashed: true));
        $row = $this->reader->find($ctx->siteId, $kind, 'draft', $id, ['status' => 'any']);

        return ApiResponse::item($request, $this->resource($request, true)->many([$row])[0]);
    }

    /** The deterministic SEO report: of the live page, or of the draft with context=edit. */
    public function seoAnalysis(Request $request, PageSeo $seo): JsonResponse
    {
        [$kind, $id] = [(string) $request->route('kind'), (string) $request->route('id')];
        $type = ContentTypes::get($kind);
        $ctx = ApiPrincipal::of($request)->require('read:'.$type['collection']);
        $v = ApiResponse::query($request, ['context' => ['sometimes', 'in:view,edit']]);
        $id = $this->items->assertKind($ctx->siteId, $kind, $id);
        $this->auth->authorize($ctx, 'page.view');
        $edit = ($v['context'] ?? 'view') === 'edit';
        $report = $edit ? $seo->analyzeDraft($ctx, $id) : $seo->analyzeLive($ctx->siteId, $id);
        if ($report === null) {
            throw new ApiError(404, 'not_found', 'This item is not published. Use context=edit to analyze its draft.');
        }

        return ApiResponse::item($request, [
            'context' => $edit ? 'edit' : 'view',
            'score' => (int) $report['score'],
            'label' => $report['label'],
            'checks' => array_map(fn ($c) => ['id' => $c['id'], 'category' => $c['category'], 'status' => $c['status'], 'message' => $c['message'], 'weight' => $c['weight'], 'earned' => $c['earned']], $report['checks']),
            'categories' => $report['categories'],
            'title' => $report['title'], 'description' => $report['description'], 'canonical' => $report['canonical'] ?: null,
            'robots' => ['index' => ! $report['noindex'], 'follow' => ! $report['nofollow']],
            'broken_links' => $report['brokenLinks'],
        ]);
    }

    /** Members' view: needs the type's read scope and the role's page.view; otherwise 401/403. */
    private function member(Request $request, array $type, bool $wanted): bool
    {
        if (! $wanted) {
            return false;
        }
        $ctx = ApiPrincipal::of($request)->require('read:'.$type['collection']);
        $this->auth->authorize($ctx, 'page.view');

        return true;
    }

    private function resource(Request $request, bool $member): ContentResource
    {
        return new ContentResource(ApiPrincipal::of($request)->siteId, $request->getSchemeAndHttpHost(), $member);
    }

    /** @return array<string, array{html: string, css: string, seo: array}|null> */
    private function rendered(Request $request, array $rows, bool $member): array
    {
        $out = [];
        $principal = ApiPrincipal::of($request);
        $draft = fn (array $row) => $member && $row['status'] !== 'trash' && ($row['hasChanges'] !== false || $row['status'] === 'draft');
        // Published state: the stored publication HTML, read in one query and cached forever (immutable).
        $published = RenderedOutput::forPublications(array_values(array_filter(array_map(fn ($row) => ! $draft($row) && $row['status'] !== 'trash' ? $row['publicationId'] : null, $rows))));
        foreach ($rows as $row) {
            $out[$row['id']] = match (true) {
                // Draft state: rendered now by the production renderer, exactly as it would be published.
                $draft($row) => RenderedOutput::of(app(PageService::class)->renderPreview(new SiteContext($principal->siteId, $principal->userId, 'api'), $row['id'])),
                $row['status'] !== 'trash' && $row['publicationId'] !== null => $published[$row['publicationId']] ?? null,
                default => null,
            };
        }

        return $out;
    }

    /** `category=technology,12` style filters: ids or slugs, any of them (an unknown slug matches nothing). */
    private function termFilters(string $siteId, array $type, array $v): array
    {
        $filters = [];
        foreach ($type['taxonomies'] as $taxonomy) {
            if (! isset($v[$taxonomy])) {
                continue;
            }
            $values = array_values(array_filter(array_map('trim', explode(',', $v[$taxonomy]))));
            $slugs = array_values(array_filter($values, fn ($x) => ! Uuid::isValid($x)));
            $ids = array_values(array_filter($values, Uuid::isValid(...)));
            if ($slugs !== []) {
                $ids = [...$ids, ...DB::table('terms')->where('site_id', $siteId)->where('taxonomy', $taxonomy)->whereIn('slug', $slugs)->pluck('id')->all()];
            }
            $filters[$taxonomy] = $ids === [] ? ['00000000-0000-7000-8000-000000000000'] : $ids;
        }

        return $filters;
    }

    /** The JSON body in the domain's terms: content.blocks, featured_media and taxonomy id lists. */
    private function input(Request $request, array $type, bool $partial = false): array
    {
        $body = $request->json()->all();
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ApiError(400, 'invalid_body', 'Send a JSON object.');
        }
        $known = ['title', 'slug', 'path', 'status', 'content', 'seo', 'version', ...($type['details'] ? ['excerpt', 'featured_media'] : []), ...array_map(fn ($t) => Taxonomies::get($t)['collection'], $type['taxonomies'])];
        if (($unknown = array_diff(array_keys($body), $known)) !== []) {
            throw new ApiError(422, 'validation_error', 'Unknown field: '.implode(', ', $unknown).'.', array_fill_keys(array_values($unknown), ['This field is not accepted.']));
        }
        $input = array_intersect_key($body, array_flip(['title', 'slug', 'path', 'status', 'excerpt', 'seo']));
        if (array_key_exists('content', $body)) {
            if (! is_array($body['content']) || ! array_key_exists('blocks', $body['content'])) {
                throw new ApiError(422, 'validation_error', 'content must be {"blocks": [...]} (see the public block format).', ['content' => ['Expected {"blocks": [...]}.']]);
            }
            $input['blocks'] = $body['content']['blocks'];
        }
        if (array_key_exists('featured_media', $body)) {
            $input['featuredMediaId'] = $body['featured_media'];
        }
        foreach ($type['taxonomies'] as $taxonomy) {
            $key = Taxonomies::get($taxonomy)['collection'];
            if (array_key_exists($key, $body)) {
                if (! is_array($body[$key]) || ! array_is_list($body[$key]) || array_filter($body[$key], fn ($id) => ! Uuid::isValid($id)) !== []) {
                    throw new ApiError(422, 'validation_error', "{$key} must be a list of ids.", [$key => ['Expected a list of ids.']]);
                }
                $input['terms'][$taxonomy] = $body[$key];
            }
        }
        if (! $partial && ! array_key_exists('title', $input)) {
            throw new ApiError(422, 'validation_error', 'A title is required.', ['title' => ['A title is required.']]);
        }

        return $input;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return null;
        }
        if (! Rules::matches('requestKey', $key)) {
            throw ApiError::invalidParameter('Idempotency-Key must be 16 to 100 characters: letters, digits, - and _.', ['Idempotency-Key' => ['Invalid format.']]);
        }

        return $key;
    }
}
