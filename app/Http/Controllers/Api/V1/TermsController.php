<?php

namespace App\Http\Controllers\Api\V1;

use App\Arkon\Content\Taxonomies;
use App\Arkon\Content\TermService;
use App\Http\Api\ApiError;
use App\Http\Api\ApiPrincipal;
use App\Http\Api\ApiResponse;
use App\Http\Api\Pagination;
use App\Http\Api\Resources\TermResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** /api/v1/{categories|tags|…}: terms are public names; changing them needs write:{taxonomy}. */
final class TermsController extends Controller
{
    public function __construct(private readonly TermService $terms) {}

    public function index(Request $request): JsonResponse
    {
        $taxonomy = (string) $request->route('taxonomy');
        $tax = Taxonomies::get($taxonomy);
        $v = ApiResponse::query($request, [
            'search' => ['sometimes', 'string', 'max:100'], 'slug' => ['sometimes', 'string', 'max:100'], 'hide_empty' => ['sometimes', 'in:true,false,1,0'],
            'parent' => $tax['hierarchical'] ? ['sometimes', 'string'] : ['prohibited'], 'sort' => ['sometimes', 'string'], 'page' => ['sometimes'], 'per_page' => ['sometimes'],
        ]);
        $page = Pagination::from($request, 50);
        $result = $this->terms->browse(ApiPrincipal::of($request)->siteId, $taxonomy, [
            'q' => $v['search'] ?? '', 'slug' => $v['slug'] ?? null, 'parent' => $v['parent'] ?? null, 'hideEmpty' => in_array($v['hide_empty'] ?? 'false', ['true', '1'], true),
            'sort' => ApiResponse::sort($request, ['name', 'slug', 'count'], 'name'), 'page' => $page->page, 'perPage' => $page->perPage,
        ]);

        return ApiResponse::collection($request, array_map(TermResource::present(...), $result['items']), $page, $result['total']);
    }

    public function show(Request $request): JsonResponse
    {
        [$taxonomy, $id] = [(string) $request->route('taxonomy'), (string) $request->route('id')];

        return ApiResponse::item($request, TermResource::present($this->terms->find(ApiPrincipal::of($request)->siteId, $taxonomy, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        $taxonomy = (string) $request->route('taxonomy');
        $ctx = ApiPrincipal::of($request)->require('write:'.Taxonomies::get($taxonomy)['collection']);
        $term = $this->terms->create($ctx, $taxonomy, $this->body($request));

        return ApiResponse::item($request, TermResource::present($term), 201)->header('Location', $request->url().'/'.$term['id']);
    }

    public function update(Request $request): JsonResponse
    {
        [$taxonomy, $id] = [(string) $request->route('taxonomy'), (string) $request->route('id')];
        $ctx = ApiPrincipal::of($request)->require('write:'.Taxonomies::get($taxonomy)['collection']);

        return ApiResponse::item($request, TermResource::present($this->terms->update($ctx, $taxonomy, $id, $this->body($request))));
    }

    public function destroy(Request $request): Response
    {
        [$taxonomy, $id] = [(string) $request->route('taxonomy'), (string) $request->route('id')];
        $ctx = ApiPrincipal::of($request)->require('write:'.Taxonomies::get($taxonomy)['collection']);
        $this->terms->delete($ctx, $taxonomy, $id);

        return ApiResponse::noContent();
    }

    private function body(Request $request): array
    {
        $body = $request->json()->all();
        if (($unknown = array_diff(array_keys($body), ['name', 'slug', 'description', 'parent'])) !== []) {
            throw new ApiError(422, 'validation_error', 'Unknown field: '.implode(', ', $unknown).'.', array_fill_keys(array_values($unknown), ['This field is not accepted.']));
        }
        if (array_key_exists('parent', $body)) {
            $body['parentId'] = $body['parent'];
            unset($body['parent']);
        }

        return $body;
    }
}
