<?php

namespace App\Arkon\Themes;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;

/** Per-site component availability; historical rendering never depends on this mutable choice. */
final class ThemeService
{
    public function __construct(private readonly Authorizer $auth, private readonly Transactions $transactions, private readonly AuditLog $audit, private readonly PageStore $pages) {}

    public static function selection(string $siteId): ?array
    {
        $draft = DB::table('site_theme_sets')->where('site_id', $siteId)->value('draft');

        return $draft === null ? null : Json::toArray(Json::decode($draft));
    }

    public static function availableTypes(string $siteId, mixed $document = null): array
    {
        $types = self::selection($siteId)['types'] ?? [];
        foreach (is_array($document) ? Json::entries($document['nodes'] ?? []) : [] as $node) {
            if (str_starts_with($node['type'] ?? '', 'theme-')) {
                $types[] = $node['type'];
            }
        }

        return array_values(array_unique($types));
    }

    private static function lock(string $siteId, bool $shared = false): void
    {
        $function = $shared ? 'pg_advisory_xact_lock_shared' : 'pg_advisory_xact_lock';
        DB::select("SELECT {$function}(hashtextextended(?, 0))", ['arkon.theme:'.$siteId]);
    }

    /** Called inside saves. Previously used blocks stay editable/duplicable after switching themes. */
    public static function assertAdditions(string $siteId, mixed $before, mixed $after): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Theme availability checks require a transaction.');
        }
        self::lock($siteId, true);
        $allowed = self::availableTypes($siteId, $before);
        foreach (Json::entries($after['nodes'] ?? []) as $node) {
            if (str_starts_with($node['type'] ?? '', 'theme-') && ! in_array($node['type'], $allowed, true)) {
                throw new ValidationException('Activate this component’s theme in Appearance → Themes before adding it.');
            }
        }
    }

    public function state(SiteContext $ctx): array
    {
        return $this->transactions->run(function () use ($ctx) {
            $this->auth->authorize($ctx, 'page.view');
            $row = DB::table('site_theme_sets')->where('site_id', $ctx->siteId)->first();
            $draft = $row?->draft === null ? null : Json::toArray(Json::decode($row->draft));
            $published = $row?->published_version === null ? null : DB::table('site_theme_versions')->where('site_id', $ctx->siteId)->where('version', $row->published_version)->first();
            $live = $published?->selection === null ? null : Json::toArray(Json::decode($published->selection));
            $used = [];
            foreach ([...DB::table('page_drafts')->where('site_id', $ctx->siteId)->pluck('document')->all(), ...DB::table('reusable_components')->where('site_id', $ctx->siteId)->pluck('draft')->all()] as $doc) {
                foreach (Json::entries(Json::decode($doc)['nodes']) as $node) {
                    if (str_starts_with($node['type'], 'theme-')) {
                        $used[] = $node['type'];
                    }
                }
            }

            return ['version' => (int) ($row?->version ?? 1), 'draft' => $draft, 'published' => $live, 'publishedVersion' => $row?->published_version,
                'changed' => Json::canonical($draft) !== Json::canonical($live), 'themes' => ThemeCatalogue::listing(),
                'retainedTypes' => array_values(array_diff(array_unique($used), $draft['types'] ?? []))];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    public function activate(SiteContext $ctx, array $input): array
    {
        $this->auth->authorize($ctx, 'page.publish');
        $v = Input::validate($input, ['themeId' => ['present', 'nullable', 'string', 'max:25'], 'baseVersion' => ['required', 'integer', 'min:1'], 'requestKey' => Input::requestKeyRule()]);
        $id = $v['themeId'] ?? null;
        $fingerprint = Fingerprint::of(['id' => $id, 'base' => (int) $v['baseVersion']]);

        return $this->transactions->run(function () use ($ctx, $v, $id, $fingerprint) {
            $this->auth->authorize($ctx, 'page.publish');
            self::lock($ctx->siteId);
            if ($replay = $this->replay($ctx, 'activate', $v['requestKey'], $fingerprint)) {
                return $replay;
            }
            $row = $this->lockSet($ctx->siteId);
            if ((int) $row->version !== (int) $v['baseVersion']) {
                throw new StaleVersionException((int) $v['baseVersion'], (int) $row->version);
            }
            $selection = null;
            if ($id !== null) {
                try {
                    $candidate = ThemeCatalogue::candidate($id);
                    // Fixed source root, trusted local package; no web upload or arbitrary path input.
                    $installed = ThemeStore::install(ThemeCatalogue::source($id));
                    if (array_column($installed, 'hash') !== array_column($candidate['packages'], 'hash')) {
                        throw new \RuntimeException('Theme changed during activation. Retry.');
                    }
                    unset($candidate['packages']);
                    $selection = $candidate;
                } catch (\RuntimeException|\JsonException $e) {
                    throw new ValidationException($e->getMessage());
                }
            }
            $version = (int) $row->version + 1;
            DB::table('site_theme_sets')->where('site_id', $ctx->siteId)->update(['draft' => $selection === null ? null : Json::encode($selection), 'version' => $version, 'updated_by' => $ctx->userId, 'updated_at' => DB::raw('now()')]);
            $result = ['version' => $version, 'selection' => $selection, 'replayed' => false];
            $this->record($ctx, 'activate', $v['requestKey'], $fingerprint, $result);
            $this->audit->forContext($ctx, 'theme.activate.draft', 'site', $ctx->siteId, ['theme' => $id, 'version' => $version]);

            return $result;
        });
    }

    public function publish(SiteContext $ctx, array $input): array
    {
        $v = Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1'], 'requestKey' => Input::requestKeyRule()]);
        $fingerprint = Fingerprint::of(['version' => (int) $v['expectedVersion']]);

        return $this->transactions->run(function () use ($ctx, $v, $fingerprint) {
            $this->auth->authorize($ctx, 'page.publish');
            self::lock($ctx->siteId);
            if ($replay = $this->replay($ctx, 'publish', $v['requestKey'], $fingerprint)) {
                return $replay;
            }
            $row = $this->lockSet($ctx->siteId);
            if ((int) $row->version !== (int) $v['expectedVersion']) {
                throw new StaleVersionException((int) $v['expectedVersion'], (int) $row->version);
            }
            $version = (int) ($row->published_version ?? 0) + 1;
            $epoch = $this->pages->lockNextEpoch($ctx->siteId);
            DB::table('site_theme_versions')->insert(['site_id' => $ctx->siteId, 'version' => $version, 'selection' => $row->draft, 'published_by' => $ctx->userId, 'epoch' => $epoch]);
            DB::table('site_theme_sets')->where('site_id', $ctx->siteId)->update(['published_version' => $version]);
            $result = ['version' => $version, 'replayed' => false];
            $this->record($ctx, 'publish', $v['requestKey'], $fingerprint, $result);
            $this->audit->forContext($ctx, 'theme.publish', 'site', $ctx->siteId, ['version' => $version, 'epoch' => $epoch]);

            return $result;
        });
    }

    private function lockSet(string $siteId): object
    {
        DB::table('site_theme_sets')->insertOrIgnore(['site_id' => $siteId]);

        return DB::table('site_theme_sets')->where('site_id', $siteId)->lockForUpdate()->first();
    }

    private function replay(SiteContext $ctx, string $kind, string $key, string $fingerprint): ?array
    {
        $row = DB::table('site_theme_requests')->where('site_id', $ctx->siteId)->where('kind', $kind)->where('request_key', $key)->first();
        if (! $row) {
            return null;
        }
        if ($row->fingerprint !== $fingerprint) {
            throw new ConflictException('This request key was used for a different theme change.');
        }

        return [...Json::toArray(Json::decode($row->result)), 'replayed' => true];
    }

    private function record(SiteContext $ctx, string $kind, string $key, string $fingerprint, array $result): void
    {
        DB::table('site_theme_requests')->insert(['site_id' => $ctx->siteId, 'kind' => $kind, 'request_key' => $key, 'fingerprint' => $fingerprint, 'result' => Json::encode($result)]);
    }
}
