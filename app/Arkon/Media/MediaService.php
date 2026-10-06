<?php

namespace App\Arkon\Media;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Membership;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Uploads and the delivery policy. Uploads are private until published. A file
 * is served:
 *  - publicly, when the request host belongs to the asset's site and a publication
 *    that is live right now uses it (publication_media joined to live_pages);
 *  - privately (no-store), to a signed-in member of the asset's site (media.view);
 *  - privately, with a short-lived signed token (the sandboxed editor canvas).
 * Anything else is "not found", including unknown hosts.
 */
class MediaService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const MAX_DIMENSION = 12000;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Membership $membership,
        private readonly Transactions $transactions,
        private readonly MediaStorage $storage,
        private readonly AuditLog $audit,
    ) {}

    public static function url(string $storageKey): string
    {
        return "/media/{$storageKey}";
    }

    /** @return array{id: string, url: string, width: int, height: int, mime: string, bytes: int, originalName: string} */
    public function upload(SiteContext $ctx, string $data, string $originalName): array
    {
        $this->authorizer->authorize($ctx, 'media.upload');
        $bytes = strlen($data);
        if ($bytes === 0) {
            throw new ValidationException('The file is empty');
        }
        if ($bytes > self::MAX_UPLOAD_BYTES) {
            throw new ValidationException('Images must be 5 MB or smaller');
        }
        $type = ImageType::detect($data);
        if ($type === null) {
            throw new ValidationException('Only JPEG, PNG, GIF, WebP and AVIF images are supported');
        }
        $size = @getimagesizefromstring($data);
        $width = is_array($size) ? (int) $size[0] : 0;
        $height = is_array($size) ? (int) $size[1] : 0;
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new ValidationException('The image dimensions are invalid or too large');
        }

        $id = Uuid::v7();
        $storageKey = "{$id}.{$type['ext']}";
        $name = mb_substr((string) preg_replace('/[^\w.\- ]+/u', '_', $originalName), 0, 200);
        $name = $name === '' ? 'image' : $name;
        $this->storage->put($storageKey, $data);

        $this->transactions->run(function () use ($ctx, $id, $storageKey, $type, $bytes, $width, $height, $name) {
            DB::table('media_assets')->insert([
                'id' => $id,
                'site_id' => $ctx->siteId,
                'storage_key' => $storageKey,
                'mime' => $type['mime'],
                'bytes' => $bytes,
                'width' => $width,
                'height' => $height,
                'original_name' => $name,
                'created_by' => $ctx->userId,
            ]);
            $this->audit->forContext($ctx, 'media.upload', 'media', $id, ['bytes' => $bytes, 'mime' => $type['mime']]);
        });

        return [
            'id' => $id, 'url' => self::url($storageKey), 'width' => $width, 'height' => $height,
            'mime' => $type['mime'], 'bytes' => $bytes, 'originalName' => $name,
        ];
    }

    /** @return list<array{id: string, url: string, width: int, height: int, mime: string, bytes: int, originalName: string}> */
    public function list(SiteContext $ctx): array
    {
        $this->authorizer->authorize($ctx, 'media.view');

        return DB::table('media_assets')->where('site_id', $ctx->siteId)->orderByDesc('created_at')->limit(200)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'url' => self::url($r->storage_key), 'width' => (int) $r->width, 'height' => (int) $r->height,
                'mime' => $r->mime, 'bytes' => (int) $r->bytes, 'originalName' => $r->original_name,
            ])
            ->all();
    }

    /**
     * Media for rendering, restricted to one site. Missing ids are simply absent.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, url: string, width: int, height: int, mime: string}>
     */
    public function mediaMap(string $siteId, array $ids): array
    {
        $ids = array_values(array_filter($ids, Uuid::isValid(...)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (DB::table('media_assets')->where('site_id', $siteId)->whereIn('id', $ids)->orderBy('id')->get() as $r) {
            $map[$r->id] = ['id' => $r->id, 'url' => self::url($r->storage_key), 'width' => (int) $r->width, 'height' => (int) $r->height, 'mime' => $r->mime];
        }

        return $map;
    }

    /** @return array{access: 'public'|'private', mime: string}|null */
    public function resolveAccess(string $storageKey, ?string $host, ?string $userId, ?string $token, MediaSigner $signer, ?int $nowMs = null): ?array
    {
        if (! MediaStorage::isValidKey($storageKey)) {
            return null;
        }
        $asset = DB::table('media_assets')->where('storage_key', $storageKey)->first(['id', 'site_id', 'mime']);
        if ($asset === null) {
            return null;
        }

        if ($host !== null && $host !== '') {
            $live = DB::table('site_domains as d')
                ->join('publication_media as pm', 'pm.site_id', '=', 'd.site_id')
                ->join('live_pages as l', 'l.publication_id', '=', 'pm.publication_id')
                ->join('pages as pg', fn ($j) => $j->on('pg.site_id', '=', 'l.site_id')->on('pg.id', '=', 'l.page_id'))
                ->whereNull('pg.deleted_at')
                ->where('d.hostname', strtolower($host))
                ->where('d.site_id', $asset->site_id)
                ->where('pm.asset_id', $asset->id)
                ->exists();
            if ($live) {
                return ['access' => 'public', 'mime' => $asset->mime];
            }
        }
        if ($token !== null && $token !== '' && $signer->verify($storageKey, $token, $nowMs)) {
            return ['access' => 'private', 'mime' => $asset->mime];
        }
        if ($userId !== null && Permissions::allows($this->membership->roleOf($asset->site_id, $userId), 'media.view')) {
            return ['access' => 'private', 'mime' => $asset->mime];
        }

        return null;
    }
}
