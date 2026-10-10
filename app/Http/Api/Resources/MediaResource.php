<?php

namespace App\Http\Api\Resources;

use App\Arkon\Media\MediaService;
use App\Arkon\Support\Time;
use Illuminate\Support\Facades\DB;

/**
 * An image in the API. Storage keys, file paths and editorial notes stay internal; URLs are
 * absolute. Variants are the responsive WebP sizes Arkon generated (keyed by width). An image is
 * public once a live page uses it; until then its URLs only work for signed-in members.
 */
final class MediaResource
{
    /**
     * @param  list<object>  $rows  media_assets rows of one site
     * @return array<string, array> by id
     */
    public static function many(string $siteId, array $rows, string $origin, bool $member, ?array $publicIds = null): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $variants = DB::table('media_variants')->where('site_id', $siteId)->whereIn('asset_id', $ids)->orderBy('width')->get()->groupBy('asset_id');
        $public = array_flip($publicIds ?? self::publicIds($siteId, $ids));
        $out = [];
        foreach ($rows as $a) {
            $out[$a->id] = self::present($a, $variants->get($a->id, collect())->all(), $origin, isset($public[$a->id]), $member);
        }

        return $out;
    }

    /** @return array<string, array> by id: the images among $ids that this caller may see */
    public static function byIds(string $siteId, array $ids, string $origin, bool $member): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('media_assets')->where('site_id', $siteId)->whereIn('id', $ids)->get()->all();
        $public = self::publicIds($siteId, $ids);
        if (! $member) {
            $rows = array_values(array_filter($rows, fn ($a) => in_array($a->id, $public, true)));
        }

        return self::many($siteId, $rows, $origin, $member, $public);
    }

    /** @return list<string> ids used by a publication that is live on the site right now */
    public static function publicIds(string $siteId, array $ids): array
    {
        return $ids === [] ? [] : DB::table('publication_media as pm')->join('live_pages as l', 'l.publication_id', '=', 'pm.publication_id')
            ->where('l.site_id', $siteId)->whereIn('pm.asset_id', $ids)->distinct()->pluck('pm.asset_id')->all();
    }

    private static function present(object $a, array $variants, string $origin, bool $public, bool $member): array
    {
        $url = fn (string $key) => $origin.MediaService::url($key);
        $webp = array_values(array_filter($variants, fn ($v) => $v->format === 'webp'));
        $sizes = [];
        foreach ($webp as $v) {
            $sizes[(string) $v->width] = ['url' => $url($v->storage_key), 'width' => (int) $v->width, 'height' => (int) $v->height, 'mime_type' => 'image/webp', 'filesize' => (int) $v->bytes];
        }
        $thumb = $webp[0] ?? null;

        return [
            'id' => $a->id,
            'title' => $a->title ?: $a->original_name,
            'filename' => $a->original_name,
            'mime_type' => $a->mime,
            'width' => (int) $a->width,
            'height' => (int) $a->height,
            'filesize' => (int) $a->bytes,
            'alt' => (string) $a->alt_text,
            'caption' => (string) $a->caption,
            'url' => $url($a->storage_key),
            'thumbnail' => $thumb ? ['url' => $url($thumb->storage_key), 'width' => (int) $thumb->width, 'height' => (int) $thumb->height] : null,
            'variants' => (object) $sizes,
            'formats' => ['webp' => $webp !== [], 'avif' => false],
            'uploaded_at' => Time::iso($a->created_at),
            ...($member ? ['public' => $public, 'status' => $a->archived_at === null ? 'library' : 'trash', 'description' => (string) $a->description, 'version' => (int) $a->metadata_version] : []),
        ];
    }
}
