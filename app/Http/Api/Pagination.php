<?php

namespace App\Http\Api;

use Illuminate\Http\Request;

/** `?page=2&per_page=20`: page from 1, per_page from 1 to the endpoint's maximum (100). */
final class Pagination
{
    public function __construct(public readonly int $page, public readonly int $perPage) {}

    public static function from(Request $request, int $default = 10, int $max = 100): self
    {
        $v = ApiResponse::query($request, ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', "max:{$max}"]]);

        return new self((int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? $default));
    }

    public function meta(int $total): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $total, 'total_pages' => (int) max(1, ceil($total / $this->perPage))];
    }

    /** Absolute links that keep every other query parameter; prev/next are null at the ends. */
    public function links(Request $request, int $total): array
    {
        $last = (int) max(1, ceil($total / $this->perPage));
        $url = fn (int $page) => $request->url().'?'.http_build_query([...$request->query(), 'page' => $page, 'per_page' => $this->perPage], '', '&', PHP_QUERY_RFC3986);

        return [
            'self' => $url($this->page), 'first' => $url(1),
            'prev' => $this->page > 1 ? $url(min($this->page - 1, $last)) : null,
            'next' => $this->page < $last ? $url($this->page + 1) : null,
            'last' => $url($last),
        ];
    }
}
