<?php

namespace App\Http\Controllers\Api\V1;

use App\Arkon\Content\ContentItems;
use App\Arkon\Media\MediaLibrary;
use App\Arkon\Media\MediaService;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Support\Uuid;
use App\Http\Api\ApiError;
use App\Http\Api\ApiPrincipal;
use App\Http\Api\ApiResponse;
use App\Http\Api\Pagination;
use App\Http\Api\Resources\MediaResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/v1/media. Anonymous requests see the images live pages use (the only public ones);
 * read:media sees the whole library and its Trash. Uploads go through the same pipeline as the
 * admin (MediaService: type from the bytes, size and dimension limits, original kept, WebP sizes).
 */
final class MediaController extends Controller
{
    public function __construct(private readonly MediaLibrary $library, private readonly Authorizer $auth) {}

    public function index(Request $request): JsonResponse
    {
        $principal = ApiPrincipal::of($request);
        $v = ApiResponse::query($request, [
            'search' => ['sometimes', 'string', 'max:200'], 'mime_type' => ['sometimes', 'string', 'in:image/jpeg,image/png,image/gif,image/webp,image/avif'],
            'status' => ['sometimes', 'in:library,trash'], 'sort' => ['sometimes', 'string'], 'page' => ['sometimes'], 'per_page' => ['sometimes'],
        ]);
        $page = Pagination::from($request, 20);
        $sort = ApiResponse::sort($request, ['uploaded_at', 'title', 'filesize'], '-uploaded_at');
        $member = $this->member($request, isset($v['status']) || ($principal->authenticated() && $principal->has('read:media')));
        $q = DB::table('media_assets as a')->where('a.site_id', $principal->siteId)
            ->when(($v['status'] ?? 'library') === 'trash', fn ($q) => $q->whereNotNull('a.archived_at'), fn ($q) => $q->whereNull('a.archived_at'));
        if (! $member) {
            $q->whereExists(fn ($e) => $e->from('publication_media as pm')->join('live_pages as l', 'l.publication_id', '=', 'pm.publication_id')->whereColumn('pm.asset_id', 'a.id')->whereColumn('l.site_id', 'a.site_id'));
        }
        if (isset($v['search'])) {
            $like = '%'.addcslashes($v['search'], '\\%_').'%';
            $q->where(fn ($w) => $w->where('a.title', 'ilike', $like)->orWhere('a.original_name', 'ilike', $like)->orWhere('a.alt_text', 'ilike', $like));
        }
        if (isset($v['mime_type'])) {
            $q->where('a.mime', $v['mime_type']);
        }
        $total = (clone $q)->count();
        $column = ['uploaded_at' => 'a.created_at', 'title' => 'a.title', 'filesize' => 'a.bytes'][ltrim($sort, '-')];
        $rows = $q->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('a.id')->offset(($page->page - 1) * $page->perPage)->limit($page->perPage)->get(['a.*'])->all();

        return ApiResponse::collection($request, array_values(MediaResource::many($principal->siteId, $rows, $request->getSchemeAndHttpHost(), $member)), $page, $total);
    }

    public function show(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $principal = ApiPrincipal::of($request);
        $member = $principal->authenticated() && $principal->has('read:media');
        if ($member) {
            $this->member($request, true);
        }
        $item = Uuid::isValid($id) ? (MediaResource::byIds($principal->siteId, [$id], $request->getSchemeAndHttpHost(), $member)[$id] ?? null) : null;

        return $item === null ? throw ApiError::notFound('Image') : ApiResponse::item($request, $item);
    }

    /** multipart/form-data with `file`, optional title, alt, caption. */
    public function store(Request $request, MediaService $media): JsonResponse
    {
        $ctx = ApiPrincipal::of($request)->require('write:media');
        $file = $request->file('file');
        if ($file === null || ! $file->isValid()) {
            throw new ApiError(422, 'validation_error', 'Send the image as multipart/form-data in a field named "file".', ['file' => ['An image file is required.']]);
        }
        $uploaded = $media->upload($ctx, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
        $details = array_filter($request->only(['title', 'alt', 'caption', 'description']), 'is_string');
        if ($details !== []) {
            $this->saveDetails($ctx, $uploaded['id'], $details);
        }
        $item = MediaResource::byIds($ctx->siteId, [$uploaded['id']], $request->getSchemeAndHttpHost(), true)[$uploaded['id']];
        $response = ApiResponse::item($request, [...$item, ...($uploaded['optimizationWarning'] ? ['warning' => $uploaded['optimizationWarning']] : [])], 201);

        return $response->header('Location', $request->url().'/'.$uploaded['id']);
    }

    public function update(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $ctx = ApiPrincipal::of($request)->require('write:media');
        $body = $request->json()->all();
        if (($unknown = array_diff(array_keys($body), ['title', 'alt', 'caption', 'description', 'version'])) !== []) {
            throw new ApiError(422, 'validation_error', 'Unknown field: '.implode(', ', $unknown).'.', array_fill_keys(array_values($unknown), ['This field is not accepted.']));
        }
        $this->saveDetails($ctx, $id, $body);

        return ApiResponse::item($request, MediaResource::byIds($ctx->siteId, [$id], $request->getSchemeAndHttpHost(), true)[$id]);
    }

    /** Moves the image to the Trash: it leaves the library, pages that use it keep working. */
    public function destroy(Request $request): Response
    {
        $id = (string) $request->route('id');
        $ctx = ApiPrincipal::of($request)->require('write:media');
        $version = Uuid::isValid($id) ? DB::table('media_assets')->where('site_id', $ctx->siteId)->where('id', $id)->value('metadata_version') : null;
        $this->library->archive($ctx, $id, (int) $version);

        return ApiResponse::noContent();
    }

    public function restore(Request $request): JsonResponse
    {
        $id = (string) $request->route('id');
        $ctx = ApiPrincipal::of($request)->require('write:media');
        $this->library->restore($ctx, $id);

        return ApiResponse::item($request, MediaResource::byIds($ctx->siteId, [$id], $request->getSchemeAndHttpHost(), true)[$id]);
    }

    /** Partial details: fields left out keep their values; `version` (optional) guards against overwriting. */
    private function saveDetails($ctx, string $id, array $changes): void
    {
        $current = Uuid::isValid($id) ? DB::table('media_assets')->where('site_id', $ctx->siteId)->where('id', $id)->first() : null;
        if ($current === null) {
            throw ApiError::notFound('Image');
        }
        $version = $changes['version'] ?? (int) $current->metadata_version;
        $this->library->save($ctx, $id, [
            'title' => $changes['title'] ?? ($current->title ?: $current->original_name), 'alt' => $changes['alt'] ?? (string) $current->alt_text,
            'caption' => $changes['caption'] ?? (string) $current->caption, 'description' => $changes['description'] ?? (string) $current->description,
            'version' => $version, 'requestKey' => ContentItems::key(),
        ]);
    }

    private function member(Request $request, bool $wanted): bool
    {
        if (! $wanted) {
            return false;
        }
        $this->auth->authorize(ApiPrincipal::of($request)->require('read:media'), 'media.view');

        return true;
    }
}
