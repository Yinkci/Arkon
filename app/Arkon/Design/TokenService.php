<?php

namespace App\Arkon\Design;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Style\Tokens;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Time;
use Illuminate\Support\Facades\DB;

/**
 * The site's design tokens: one editable draft and immutable published versions.
 * Saving changes only the draft; publishing creates the next version, which every
 * page published or refreshed afterwards uses, and queues a refresh of the live
 * pages that depend on the tokens (they are re-rendered from their live revisions).
 */
class TokenService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly Transactions $transactions,
        private readonly DesignResources $resources,
        private readonly PageStore $store,
        private readonly PageRefreshes $refreshes,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{draft: array, version: int, published: array{version: int|null, tokens: array}, defaults: array, groups: array, changed: bool, refreshes: array} */
    public function state(SiteContext $ctx): array
    {
        return $this->transactions->run(function () use ($ctx) {
            $this->authorizer->authorize($ctx, 'page.view');
            $row = DB::table('site_token_sets')->where('site_id', $ctx->siteId)->first();
            $published = $this->resources->publishedTokens($ctx->siteId);
            $draft = $row ? Json::toArray(Json::decode($row->draft)) : $published['values'];

            return [
                'draft' => $draft === [] ? new \stdClass : $draft,
                // A site without a token set row gets one at version 1 on its first save.
                'version' => $row ? (int) $row->version : 1,
                'published' => ['version' => $published['version'], 'tokens' => $published['values'] === [] ? new \stdClass : $published['values']],
                'defaults' => Tokens::defaults(),
                'groups' => array_map(fn ($g) => ['label' => $g['label'], 'kind' => $g['kind'], 'lengths' => $g['lengths'] ?? null], Tokens::groups()),
                'changed' => Json::canonical(Tokens::resolve($draft)) !== Json::canonical(Tokens::resolve($published['values'])),
                'refreshes' => $this->refreshes->status($ctx),
            ];
        }, isolation: 'REPEATABLE READ', readOnly: true);
    }

    /**
     * Replaces the draft with `tokens` (a partial set: missing tokens keep their defaults).
     *
     * @param  array{baseVersion: mixed, tokens: mixed, saveKey: mixed}  $input  tokens in raw JSON form
     * @return array{version: int, replayed: bool}
     */
    public function save(SiteContext $ctx, array $input): array
    {
        $valid = Input::validate($input, ['baseVersion' => ['required', 'integer', 'min:0'], 'saveKey' => Input::requestKeyRule()]);
        [$tokens, $issues] = Tokens::parse($input['tokens'] ?? null);
        if ($tokens === null) {
            throw new ValidationException('The design tokens are not valid', $issues);
        }
        $base = (int) $valid['baseVersion'];
        $fingerprint = Fingerprint::of(['kind' => 'tokens.save', 'baseVersion' => $base, 'tokens' => $tokens]);

        return $this->transactions->run(function () use ($ctx, $tokens, $base, $valid, $fingerprint) {
            $this->authorizer->authorize($ctx, 'page.edit');
            $row = $this->lockSet($ctx->siteId);
            if ($row->last_save_key === $valid['saveKey']) {
                if ($row->last_save_fingerprint !== $fingerprint) {
                    throw new ConflictException('This save key was already used for a different change');
                }

                return ['version' => (int) $row->version, 'replayed' => true];
            }
            if ((int) $row->version !== $base) {
                throw new StaleVersionException($base, (int) $row->version);
            }
            $version = (int) $row->version + 1;
            DB::table('site_token_sets')->where('site_id', $ctx->siteId)->update([
                'draft' => Json::encode($tokens === [] ? new \stdClass : $tokens), 'version' => $version,
                'last_save_key' => $valid['saveKey'], 'last_save_fingerprint' => $fingerprint,
                'updated_by' => $ctx->userId, 'updated_at' => DB::raw('now()'),
            ]);
            $this->audit->forContext($ctx, 'tokens.draft.save', 'site', $ctx->siteId, ['version' => $version]);

            return ['version' => $version, 'replayed' => false];
        });
    }

    /**
     * Applies token changes (from an AI proposal) to the draft they were made against. Runs
     * inside the caller's transaction, so the caller's own record of the change commits with
     * it. `$baseVersion` is the draft version the proposal saw: if the draft moved on since
     * (or it is unknown), nothing is applied, so a proposal never overwrites newer edits.
     *
     * @param  list<array{token: string, value: string}>  $changes
     * @return int the new draft version
     *
     * @throws StaleVersionException
     */
    public function applyChangesLocked(SiteContext $ctx, array $changes, ?int $baseVersion, string $source): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('applyChangesLocked must run inside the caller\'s transaction');
        }
        $this->authorizer->authorize($ctx, 'page.edit');
        $row = $this->lockSet($ctx->siteId);
        if ($baseVersion === null || (int) $row->version !== $baseVersion) {
            throw new StaleVersionException($baseVersion ?? 0, (int) $row->version);
        }
        $draft = Json::toArray(Json::decode($row->draft));
        foreach ($changes as $change) {
            [$group, $name] = explode('.', substr($change['token'], 1), 2);
            $draft[$group][$name] = $change['value'];
        }
        [$tokens, $issues] = Tokens::parse(Json::decode(Json::encode($draft === [] ? new \stdClass : $draft)));
        if ($tokens === null) {
            throw new ValidationException('The design tokens are not valid', $issues);
        }
        $version = (int) $row->version + 1;
        DB::table('site_token_sets')->where('site_id', $ctx->siteId)->update([
            'draft' => Json::encode($tokens === [] ? new \stdClass : $tokens), 'version' => $version,
            // A retry of an earlier Design-page save must not be mistaken for this change.
            'last_save_key' => $source, 'last_save_fingerprint' => Fingerprint::of(['kind' => 'tokens.apply', 'source' => $source, 'changes' => $changes]),
            'updated_by' => $ctx->userId, 'updated_at' => DB::raw('now()'),
        ]);
        $this->audit->forContext($ctx, 'tokens.draft.save', 'site', $ctx->siteId, ['version' => $version, 'source' => $source]);

        return $version;
    }

    /** The draft version AI proposals record as their base (a site without a token set row starts at 1). */
    public static function draftVersion(string $siteId): int
    {
        return (int) (DB::table('site_token_sets')->where('site_id', $siteId)->value('version') ?? 1);
    }

    /**
     * Publishes the draft the caller saw as the next version. Idempotent per key.
     *
     * @return array{version: int, replayed: bool, refreshes: array{queued: int, done: int, skipped: int, failed: int}}
     */
    public function publish(SiteContext $ctx, array $input): array
    {
        $valid = Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:0'], 'idempotencyKey' => Input::requestKeyRule()]);
        $expected = (int) $valid['expectedVersion'];
        $key = $valid['idempotencyKey'];
        $fingerprint = Fingerprint::of(['kind' => 'tokens.publish', 'expectedVersion' => $expected]);

        $result = $this->transactions->run(function () use ($ctx, $expected, $key, $fingerprint) {
            $this->authorizer->authorize($ctx, 'page.publish');
            $row = $this->lockSet($ctx->siteId);
            $existing = DB::table('site_token_versions')->where('site_id', $ctx->siteId)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw new ConflictException('This publish key was already used for a different version');
                }

                return ['version' => (int) $existing->version, 'replayed' => true, 'queued' => 0];
            }
            if ((int) $row->version !== $expected) {
                throw new StaleVersionException($expected, (int) $row->version);
            }
            // Every change to published state takes the site's epoch lock (see PageStore::lockNextEpoch):
            // re-renders after this publish then carry a newer epoch than what is live now.
            $this->store->lockNextEpoch($ctx->siteId);
            $version = (int) ($row->published_version ?? 0) + 1;
            DB::table('site_token_versions')->insert([
                'site_id' => $ctx->siteId, 'version' => $version, 'tokens' => $row->draft,
                'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'published_by' => $ctx->userId,
            ]);
            DB::table('site_token_sets')->where('site_id', $ctx->siteId)->update(['published_version' => $version]);
            $queued = $this->refreshes->enqueue($ctx->siteId, 'tokens', $ctx->siteId, $version);
            $this->audit->forContext($ctx, 'tokens.publish', 'site', $ctx->siteId, ['version' => $version, 'refreshes' => $queued]);

            return ['version' => $version, 'replayed' => false, 'queued' => $queued];
        });

        // After commit: re-render the dependent live pages (what is left stays queued, with status).
        $counts = DB::transactionLevel() === 0 ? $this->refreshes->run($ctx->siteId) : ['done' => 0, 'skipped' => 0, 'failed' => 0];

        return ['version' => $result['version'], 'replayed' => $result['replayed'], 'refreshes' => ['queued' => $result['queued'], ...$counts]];
    }

    /** The site's token set row, created on first use, locked for the rest of the transaction. */
    private function lockSet(string $siteId): object
    {
        DB::statement('INSERT INTO site_token_sets (site_id) VALUES (?) ON CONFLICT (site_id) DO NOTHING', [$siteId]);

        return DB::table('site_token_sets')->where('site_id', $siteId)->lockForUpdate()->first();
    }

    /** @return list<array{version: int, publishedAt: string}> */
    public function versions(SiteContext $ctx): array
    {
        $this->authorizer->authorize($ctx, 'page.view');

        return DB::table('site_token_versions')->where('site_id', $ctx->siteId)->orderByDesc('version')->limit(50)->get(['version', 'created_at'])
            ->map(fn ($r) => ['version' => (int) $r->version, 'publishedAt' => Time::iso($r->created_at)])->all();
    }
}
