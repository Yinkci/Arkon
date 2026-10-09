<?php

namespace App\Arkon\Seo;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/** Draft defaults are private; publishing is versioned, ordered and refreshes live revisions. */
final class SeoDefaults
{
    public function __construct(private Authorizer $auth, private Transactions $transactions, private PageStore $store, private PageRefreshes $refreshes, private AuditLog $audit) {}

    public static function defaults(): array
    {
        return ['titlePattern' => '{page} · {site}', 'description' => '', 'socialImage' => '', 'noindex' => false, 'nofollow' => false, 'organizationName' => '', 'organizationType' => 'Organization'];
    }

    public static function published(string $siteId): array
    {
        $row = DB::table('site_seo_sets as s')->join('site_seo_versions as v', fn ($j) => $j->on('s.site_id', '=', 'v.site_id')->on('s.published_version', '=', 'v.version'))->where('s.site_id', $siteId)->first(['v.version', 'v.settings']);

        return ['version' => $row ? (int) $row->version : 0, 'values' => $row ? Json::toArray(Json::decode($row->settings)) : self::defaults()];
    }

    public function state(SiteContext $ctx): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $r = DB::table('site_seo_sets')->where('site_id', $ctx->siteId)->first();

        return ['draft' => $r ? Json::toArray(Json::decode($r->draft)) : self::defaults(), 'version' => $r ? (int) $r->version : 1, 'published' => self::published($ctx->siteId), 'refreshes' => $this->refreshes->status($ctx)];
    }

    public function save(SiteContext $ctx, array $input): array
    {
        $valid = Input::validate($input, ['baseVersion' => ['required', 'integer', 'min:1'], 'saveKey' => Input::requestKeyRule()]);
        if (! Json::isObject($input['settings'] ?? null)) {
            throw new ValidationException('SEO settings must be an object.');
        }
        $settings = Json::entries($input['settings']);
        if (array_diff(array_keys($settings), array_keys(self::defaults())) !== []) {
            throw new ValidationException('Unknown SEO default');
        }
        $settings = Input::validate([...self::defaults(), ...$settings], ['titlePattern' => 'required|string|max:160', 'description' => 'present|string|max:320', 'socialImage' => 'present|string|max:36', 'noindex' => 'required|boolean', 'nofollow' => 'required|boolean', 'organizationName' => 'present|string|max:120', 'organizationType' => 'required|in:Organization,Person']);
        if (! str_contains($settings['titlePattern'], '{page}')) {
            throw new ValidationException('The title pattern must include {page}.');
        }
        $fingerprint = Fingerprint::of(['baseVersion' => $valid['baseVersion'], 'settings' => $settings]);

        return $this->transactions->run(function () use ($ctx, $settings, $valid, $fingerprint) {
            $this->auth->authorize($ctx, 'page.publish');
            if ($settings['socialImage'] !== '' && (! Uuid::isValid($settings['socialImage']) || ! DB::table('media_assets')->where('site_id', $ctx->siteId)->where('id', $settings['socialImage'])->exists())) {
                throw new ValidationException('Choose an image from this site.');
            }
            $r = $this->lock($ctx->siteId);
            if ($r->last_save_key === $valid['saveKey']) {
                if ($r->last_save_fingerprint !== $fingerprint) {
                    throw new ConflictException('The save key was used for other settings');
                }

                return ['version' => (int) $r->version, 'replayed' => true];
            }
            if ((int) $r->version !== (int) $valid['baseVersion']) {
                throw new StaleVersionException((int) $valid['baseVersion'], (int) $r->version);
            }
            $v = (int) $r->version + 1;
            DB::table('site_seo_sets')->where('site_id', $ctx->siteId)->update(['draft' => Json::encode($settings), 'version' => $v, 'last_save_key' => $valid['saveKey'], 'last_save_fingerprint' => $fingerprint, 'updated_at' => DB::raw('now()')]);
            $this->audit->forContext($ctx, 'seo.defaults.save', 'site', $ctx->siteId, ['version' => $v]);

            return ['version' => $v, 'replayed' => false];
        });
    }

    public function publish(SiteContext $ctx, array $input): array
    {
        $valid = Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1'], 'idempotencyKey' => Input::requestKeyRule()]);
        $result = $this->transactions->run(function () use ($ctx, $valid) {
            $this->auth->authorize($ctx, 'page.publish');
            $r = $this->lock($ctx->siteId);
            $old = DB::table('site_seo_versions')->where('site_id', $ctx->siteId)->where('idempotency_key', $valid['idempotencyKey'])->first();
            $f = Fingerprint::of(['expectedVersion' => $valid['expectedVersion']]);
            if ($old) {
                if ($old->request_fingerprint !== $f) {
                    throw new ConflictException('This publish key names other settings');
                }

                return ['version' => (int) $old->version, 'replayed' => true];
            }
            if ((int) $r->version !== (int) $valid['expectedVersion']) {
                throw new StaleVersionException((int) $valid['expectedVersion'], (int) $r->version);
            }
            $this->store->lockNextEpoch($ctx->siteId);
            $v = (int) ($r->published_version ?? 0) + 1;
            DB::table('site_seo_versions')->insert(['site_id' => $ctx->siteId, 'version' => $v, 'settings' => $r->draft, 'idempotency_key' => $valid['idempotencyKey'], 'request_fingerprint' => $f, 'published_by' => $ctx->userId]);
            DB::table('site_seo_sets')->where('site_id', $ctx->siteId)->update(['published_version' => $v]);
            $this->refreshes->enqueue($ctx->siteId, 'seo', $ctx->siteId, $v);
            $this->audit->forContext($ctx, 'seo.defaults.publish', 'site', $ctx->siteId, ['version' => $v]);

            return ['version' => $v, 'replayed' => false];
        });

        return [...$result, 'refreshes' => $this->refreshes->run($ctx->siteId)];
    }

    private function lock(string $site): object
    {
        DB::table('site_seo_sets')->insertOrIgnore(['site_id' => $site, 'draft' => Json::encode(self::defaults())]);

        return DB::table('site_seo_sets')->where('site_id', $site)->lockForUpdate()->first();
    }

    public static function resolve(array $seo, array $page, array $site): array
    {
        $d = $site['seoDefaults']['values'] ?? self::defaults();
        $seo['title'] = trim($seo['title'] ?? '') ?: str_replace(['{page}', '{site}'], [$page['title'], $site['name']], $d['titlePattern']);
        $seo['description'] = trim($seo['description'] ?? '') ?: $d['description'];
        $seo['socialImage'] = trim($seo['socialImage'] ?? '') ?: $d['socialImage'];
        $seo['noindex'] = $seo['noindex'] ?? $d['noindex'];
        $seo['nofollow'] = $seo['nofollow'] ?? $d['nofollow'];

        return $seo;
    }
}
