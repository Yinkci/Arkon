<?php

namespace App\Arkon\Media;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Text;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/** Metadata never changes immutable file keys or publication render inputs. */
class MediaLibrary
{
    public function __construct(private Authorizer $auth, private Transactions $tx, private AuditLog $audit) {}

    public function browse(SiteContext $ctx, string $q = '', string $sort = 'newest', int $page = 1): array
    {
        $this->auth->authorize($ctx, 'media.view');
        $query = DB::table('media_assets')->where('site_id', $ctx->siteId)->whereNull('archived_at');
        if ($q !== '') {
            $query->whereRaw('(strpos(lower(title),lower(?))>0 OR strpos(lower(original_name),lower(?))>0 OR strpos(lower(alt_text),lower(?))>0 OR strpos(lower(caption),lower(?))>0)', [$q, $q, $q, $q]);
        }
        $total = (clone $query)->count();
        $page = min(max(1, $page), max(1, (int) ceil($total / 36)));
        [$column,$direction] = match ($sort) {
            'oldest' => ['created_at', 'asc'],'name' => ['title', 'asc'],'largest' => ['bytes', 'desc'],'smallest' => ['bytes', 'asc'],default => ['created_at', 'desc']
        };
        $rows = $query->orderBy($column, $direction)->orderBy('id')->offset(($page - 1) * 36)->limit(36)->get();
        $variants = DB::table('media_variants')->where('site_id', $ctx->siteId)->whereIn('asset_id', $rows->pluck('id'))->orderBy('width')->get()->groupBy('asset_id');

        return ['items' => $rows->map(fn ($a) => $this->present($a, $variants->get($a->id, collect())->all()))->all(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 36)), 'q' => $q, 'sort' => $sort];
    }

    private function asset(SiteContext $ctx, string $id, bool $lock = false): object
    {
        if (! Uuid::isValid($id)) {
            throw new NotFoundException('Image');
        }
        $q = DB::table('media_assets')->where('site_id', $ctx->siteId)->where('id', $id);
        $a = ($lock ? $q->lockForUpdate() : $q)->first();
        if (! $a) {
            throw new NotFoundException('Image');
        }

        return $a;
    }

    public function detail(SiteContext $ctx, string $id): array
    {
        $this->auth->authorize($ctx, 'media.view');
        $a = $this->asset($ctx, $id);

        return [...$this->present($a, DB::table('media_variants')->where('site_id', $ctx->siteId)->where('asset_id', $id)->orderBy('width')->get()->all()), 'usage' => $this->usage($ctx, $id)];
    }

    private function present(object $a, array $variants): array
    {
        $preview = null;
        foreach ($variants as $v) {
            $preview = $v;
            if ((int) $v->width >= 960) {
                break;
            }
        }

        return ['id' => $a->id, 'title' => $a->title ?: $a->original_name, 'alt' => $a->alt_text, 'caption' => $a->caption, 'description' => $a->description, 'version' => (int) $a->metadata_version, 'originalName' => $a->original_name, 'url' => MediaService::url($a->storage_key), 'previewUrl' => MediaService::url($preview?->storage_key ?? $a->storage_key), 'mime' => $a->mime, 'width' => (int) $a->width, 'height' => (int) $a->height, 'bytes' => (int) $a->bytes, 'createdAt' => $a->created_at, 'archived' => $a->archived_at !== null, 'webpVariants' => array_values(array_map(fn ($v) => ['url' => MediaService::url($v->storage_key), 'width' => (int) $v->width, 'height' => (int) $v->height, 'bytes' => (int) $v->bytes], array_filter($variants, fn ($v) => $v->format === 'webp'))), 'optimization' => ['count' => count($variants), 'previewBytes' => $preview ? (int) $preview->bytes : null, 'status' => $variants ? 'WebP sizes available' : 'Original available; no smaller WebP sizes recorded']];
    }

    public function save(SiteContext $ctx, string $id, array $input): array
    {
        $this->auth->authorize($ctx, 'media.upload');
        // Match native input maxlength and component validation (UTF-16 units), including emoji.
        foreach (['title' => 200, 'alt' => 300, 'caption' => 300, 'description' => 2000] as $field => $max) {
            if (! isset($input[$field]) || ! is_string($input[$field]) || Text::utf16Length($input[$field]) > $max) {
                throw new ValidationException("{$field} must be text of {$max} characters or fewer.");
            }
        }
        if (trim($input['title']) === '') {
            throw new ValidationException('Provide an image title.');
        }
        if (! isset($input['version']) || ! is_int($input['version']) || $input['version'] < 1 || ! isset($input['requestKey']) || ! is_string($input['requestKey']) || strlen($input['requestKey']) < 1 || strlen($input['requestKey']) > 100) {
            throw new ValidationException('Provide a valid metadata version and save key.');
        }
        $input = ['title' => $input['title'], 'alt' => $input['alt'], 'caption' => $input['caption'], 'description' => $input['description'], 'version' => $input['version'], 'requestKey' => $input['requestKey']];
        $fingerprint = hash('sha256', Json::encode($input));

        return $this->tx->run(function () use ($ctx, $id, $input, $fingerprint) {
            $this->auth->authorize($ctx, 'media.upload');
            $a = $this->asset($ctx, $id, true);
            $receipt = DB::table('media_metadata_saves')->where('site_id', $ctx->siteId)->where('asset_id', $id)->where('request_key', $input['requestKey'])->first();
            if ($receipt) {
                if ($receipt->fingerprint !== $fingerprint) {
                    throw new ConflictException('This save key belongs to different metadata.');
                }

                return Json::decode($receipt->result);
            }
            if ($a->archived_at !== null) {
                throw new ConflictException('This image has been removed from the library.');
            }
            if ((int) $a->metadata_version !== $input['version']) {
                throw new ConflictException('Image details changed in another window. Reload the library before saving.');
            }
            DB::table('media_assets')->where('id', $id)->where('site_id', $ctx->siteId)->update(['title' => $input['title'], 'alt_text' => $input['alt'], 'caption' => $input['caption'], 'description' => $input['description'], 'metadata_version' => $input['version'] + 1]);
            $result = ['version' => $input['version'] + 1];
            DB::table('media_metadata_saves')->insert(['site_id' => $ctx->siteId, 'asset_id' => $id, 'request_key' => $input['requestKey'], 'fingerprint' => $fingerprint, 'result' => Json::encode($result)]);
            $this->audit->forContext($ctx, 'media.metadata', 'media', $id, ['version' => $result['version']]);

            return $result;
        });
    }

    /** Removing from discovery keeps all immutable files and references working, including history. */
    public function archive(SiteContext $ctx, string $id, int $version): void
    {
        $this->auth->authorize($ctx, 'page.delete');
        $this->tx->run(function () use ($ctx, $id, $version) {
            $this->auth->authorize($ctx, 'page.delete');
            $a = $this->asset($ctx, $id, true);
            if ($a->archived_at !== null) {
                return;
            }
            if ((int) $a->metadata_version !== $version) {
                throw new ConflictException('Image details changed. Reload before removing.');
            }
            DB::table('media_assets')->where('site_id', $ctx->siteId)->where('id', $id)->update(['archived_at' => DB::raw('now()'), 'metadata_version' => $version + 1]);
            $this->audit->forContext($ctx, 'media.archive', 'media', $id);
        });
    }

    private function usage(SiteContext $ctx, string $id): array
    {
        // Conservative reference scan includes backgrounds and nested blocks; no documents leave the server.
        $drafts = DB::table('page_drafts as d')->join('pages as p', 'p.id', '=', 'd.page_id')->where('d.site_id', $ctx->siteId)->whereNull('p.deleted_at')->whereRaw('strpos(d.document::text,?)>0', [$id])->orderBy('p.title')->limit(21)->pluck('p.title')->all();
        $live = DB::table('publication_media as m')->join('live_pages as l', 'l.publication_id', '=', 'm.publication_id')->join('pages as p', 'p.id', '=', 'l.page_id')->where('m.site_id', $ctx->siteId)->where('m.asset_id', $id)->whereNull('p.deleted_at')->distinct()->limit(21)->pluck('p.title')->all();
        $components = DB::table('reusable_components')->where('site_id', $ctx->siteId)->whereRaw('strpos(draft::text,?)>0', [$id])->limit(21)->pluck('name')->all();

        return ['draftPages' => $drafts, 'livePages' => $live, 'components' => $components, 'note' => 'Removal only hides this asset from the library. Existing references, published pages and revision history keep their files.'];
    }
}
