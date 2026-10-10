<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * The public API's response shapes:
 *
 *   item        {"data": {…}}
 *   collection  {"data": […], "meta": {page, per_page, total, total_pages}, "links": {self, first, prev, next, last}}
 *
 * Anonymous reads are cacheable and revalidated: a strong ETag of the body, `public, max-age=0,
 * must-revalidate`, and 304 for a matching If-None-Match. Token requests are `private, no-store`.
 */
final class ApiResponse
{
    public const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function item(Request $request, array $data, int $status = 200, ?string $lastModified = null): JsonResponse
    {
        return self::cached($request, response()->json(['data' => $data], $status, [], self::JSON), $lastModified);
    }

    public static function collection(Request $request, array $items, Pagination $page, int $total): JsonResponse
    {
        return self::cached($request, response()->json(['data' => $items, 'meta' => $page->meta($total), 'links' => $page->links($request, $total)], 200, [], self::JSON));
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * Validates query parameters; a bad one is a 400 with the parameter named.
     *
     * @return array<string, mixed>
     */
    public static function query(Request $request, array $rules): array
    {
        $validator = Validator::make($request->query(), $rules);
        if ($validator->fails()) {
            throw ApiError::invalidParameter(implode(' ', $validator->errors()->all()), $validator->errors()->toArray());
        }

        return $validator->validated();
    }

    /** `sort=-published_at`: one field, `-` for descending, from an allowlist. */
    public static function sort(Request $request, array $allowed, string $default): string
    {
        $sort = (string) $request->query('sort', $default);
        if (! in_array(ltrim($sort, '-'), $allowed, true) || substr_count($sort, '-') > 1 || (str_contains($sort, '-') && ! str_starts_with($sort, '-'))) {
            throw ApiError::invalidParameter('sort must be one of '.implode(', ', $allowed).', optionally prefixed with - for descending order.', ['sort' => ['Unsupported sort field.']]);
        }

        return $sort;
    }

    /** @return list<string> the requested `include` values, each from the allowlist */
    public static function includes(Request $request, array $allowed): array
    {
        $values = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('include', '')))));
        if (array_diff($values, $allowed) !== []) {
            throw ApiError::invalidParameter('include supports: '.implode(', ', $allowed).'.', ['include' => ['Unsupported value.']]);
        }

        return $values;
    }

    private static function cached(Request $request, JsonResponse $response, ?string $lastModified = null): JsonResponse
    {
        if (! $request->isMethod('GET') || ApiPrincipal::of($request)->authenticated()) {
            return $response;
        }
        $response->setEtag(sha1((string) $response->getContent()));
        $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');
        if ($lastModified !== null) {
            $response->setLastModified(new \DateTimeImmutable($lastModified));
        }
        $response->isNotModified($request);

        return $response;
    }
}
