<?php

namespace App\Arkon\Forms;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

final class FormManagement
{
    public function __construct(private Authorizer $auth) {}

    public function row(SiteContext $ctx, string $id): object
    {
        Input::id($id, 'Form');

        return DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->first() ?? throw new NotFoundException('Form');
    }

    /**
     * One form for the admin. The live definitions and entry counts are loaded by the caller,
     * for a whole list page at once.
     *
     * @param  array<string, string>  $live  form id → published definition
     * @param  array<string, int>  $entries  form id → entries not in the trash
     */
    private function present(string $role, object $r, array $live, array $entries): array
    {
        $d = FormDefinition::modern(Json::decode($r->draft));
        if (! Permissions::allows($role, 'form.notifications')) {
            $d['notifications'] = [];
        }
        $published = $r->published_version ? ($live[$r->id] ?? null) : null;

        return ['id' => $r->id, 'version' => (int) $r->version, 'publishedVersion' => $r->published_version, 'definition' => $d,
            'status' => $r->archived_at ? 'In Trash' : (! $r->published_version ? 'Draft' : ((Json::decode($published)['active'] ?? true) ? 'Active' : 'Inactive')),
            'hasDraftChanges' => $published === null || Json::encode(Json::decode($r->draft)) !== Json::encode(Json::decode($published)),
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at, 'notificationEmail' => Permissions::allows($role, 'form.notifications') ? $r->notification_email : null,
            'entries' => Permissions::allows($role, 'form.entries.view') ? ($entries[$r->id] ?? 0) : null];
    }

    /**
     * Presents forms with two queries in total (not three per form).
     *
     * @param  list<object>  $rows
     */
    private function presentAll(SiteContext $ctx, string $role, array $rows): array
    {
        $ids = array_column($rows, 'id');
        if ($ids === []) {
            return [];
        }
        $live = DB::table('site_form_versions as v')
            ->join('site_forms as f', fn ($j) => $j->on('f.id', '=', 'v.form_id')->on('f.site_id', '=', 'v.site_id')->on('f.published_version', '=', 'v.version'))
            ->where('v.site_id', $ctx->siteId)->whereIn('v.form_id', $ids)->pluck('v.definition', 'v.form_id')->all();
        $entries = Permissions::allows($role, 'form.entries.view')
            ? DB::table('form_submissions')->where('site_id', $ctx->siteId)->whereIn('form_id', $ids)->where('status', '!=', 'trash')
                ->groupBy('form_id')->selectRaw('form_id, count(*) as n')->pluck('n', 'form_id')->map(fn ($n) => (int) $n)->all()
            : [];

        return array_map(fn ($r) => $this->present($role, $r, $live, $entries), $rows);
    }

    /** List tabs. Trash holds forms moved there (archived_at), never forms that were deleted permanently. */
    public const STATUSES = ['all', 'active', 'draft', 'inactive', 'trash'];

    public function browse(SiteContext $ctx, string $q, int $page = 1, string $status = 'all'): array
    {
        $role = $this->auth->authorize($ctx, 'form.view');
        $status = in_array($status, self::STATUSES, true) ? $status : 'all';
        // A published form is active unless its live definition says otherwise.
        $active = "f.published_version IS NOT NULL AND (v.definition->>'active') IS DISTINCT FROM 'false'";
        $base = fn () => DB::table('site_forms as f')
            ->leftJoin('site_form_versions as v', fn ($j) => $j->on('v.form_id', '=', 'f.id')->on('v.site_id', '=', 'f.site_id')->on('v.version', '=', 'f.published_version'))
            ->where('f.site_id', $ctx->siteId)->whereNull('f.purged_at')
            ->when($q !== '', fn ($query) => $query->whereRaw('strpos(lower(f.name),lower(?))>0', [$q]));
        $counts = (array) $base()->selectRaw("count(*) FILTER (WHERE f.archived_at IS NULL) AS \"all\",count(*) FILTER (WHERE f.archived_at IS NULL AND {$active}) AS active, count(*) FILTER (WHERE f.archived_at IS NULL AND f.published_version IS NULL) AS draft, count(*) FILTER (WHERE f.archived_at IS NULL AND f.published_version IS NOT NULL AND NOT ({$active})) AS inactive, count(*) FILTER (WHERE f.archived_at IS NOT NULL) AS trash")->first();
        $counts = array_map('intval', $counts);
        $query = $base();
        match ($status) {
            'trash' => $query->whereNotNull('f.archived_at'),
            'active' => $query->whereNull('f.archived_at')->whereRaw($active),
            'draft' => $query->whereNull('f.archived_at')->whereNull('f.published_version'),
            'inactive' => $query->whereNull('f.archived_at')->whereNotNull('f.published_version')->whereRaw("NOT ({$active})"),
            default => $query->whereNull('f.archived_at'),
        };
        $count = $counts[$status];
        $page = min(max(1, $page), max(1, (int) ceil($count / 20)));
        $rows = $query->orderByDesc($status === 'trash' ? 'f.archived_at' : 'f.updated_at')->orderBy('f.id')->offset(($page - 1) * 20)->limit(20)->get(['f.*'])->all();

        return ['items' => $this->presentAll($ctx, $role, $rows), 'total' => $count, 'page' => $page, 'pages' => max(1, (int) ceil($count / 20)), 'q' => $q, 'status' => $status, 'counts' => $counts];
    }

    public function detail(SiteContext $ctx, string $id): array
    {
        $role = $this->auth->authorize($ctx, 'form.view');
        $row = $this->row($ctx, $id);
        if ($row->archived_at) {
            throw new NotFoundException('Form');
        }

        return [...$this->presentAll($ctx, $role, [$row])[0], 'usage' => $this->usage($ctx, $id)];
    }

    public function usage(SiteContext $ctx, string $id): array
    {
        $this->auth->authorize($ctx, 'form.view');

        return DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->where('l.site_id', $ctx->siteId)->whereRaw("jsonb_exists(p.render_inputs->'forms', ?)", [$id])->limit(21)->pluck('p.path')->all();
    }

    public function duplicate(SiteContext $ctx, string $id, string $key): array
    {
        $this->auth->authorize($ctx, 'form.edit');
        Input::validate(['requestKey' => $key], ['requestKey' => Input::requestKeyRule()]);

        // Same key always reaches the resource receipt, even if the source is edited before retry.
        return DB::transaction(function () use ($ctx, $id, $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.forms:'.$ctx->siteId]);
            $receipt = DB::table('site_resource_requests')->where('site_id', $ctx->siteId)->where('request_key', $key)->first();
            if ($receipt) {
                $result = Json::decode($receipt->result);
                if (($result['duplicatedFrom'] ?? null) !== $id) {
                    throw new ConflictException('This key belongs to another action.');
                }

                return $result;
            }
            $source = $this->row($ctx, $id);
            $d = FormDefinition::modern(Json::decode($source->draft));
            $d['name'] = mb_substr($d['name'], 0, 90).' (copy)';
            if (! Permissions::allows($this->auth->authorize($ctx, 'form.edit'), 'form.notifications')) {
                $d['notifications'] = [];
            }
            $result = app(FormService::class)->save($ctx, ['baseVersion' => 0, 'requestKey' => $key.'-save', 'definition' => $d]);
            $result['duplicatedFrom'] = $id;
            if ($source->notification_email && Permissions::allows($this->auth->authorize($ctx, 'form.edit'), 'form.notifications')) {
                DB::table('site_forms')->where('id', $result['id'])->update(['notification_email' => $source->notification_email]);
            }DB::table('site_resource_requests')->insert(['site_id' => $ctx->siteId, 'request_key' => $key, 'fingerprint' => hash('sha256', 'form.duplicate:'.$id), 'result' => Json::encode($result)]);

            return $result;
        });
    }

    public function archive(SiteContext $ctx, string $id, int $version): void
    {
        $this->auth->authorize($ctx, 'form.manage');
        DB::transaction(function () use ($ctx, $id, $version) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.forms:'.$ctx->siteId]);
            $row = DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundException('Form');
            if ($row->archived_at) {
                return;
            }if ($row->version !== $version) {
                throw new ConflictException("“{$row->name}” changed since the list loaded. Reload and try again.");
            }
            // Serialize with page publishing before checking the actual live inputs.
            app(PageStore::class)->lockNextEpoch($ctx->siteId);
            if ($this->usage($ctx, $id)) {
                throw new ConflictException("“{$row->name}” is on a live page. Remove it from live pages before moving it to the Trash.");
            }
            DB::table('site_forms')->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
            app(AuditLog::class)->forContext($ctx, 'form.archive', 'form', $id, []);
        });
    }

    /** Brings a form back from the Trash with the same id, fields, entries, notifications and versions. */
    public function restore(SiteContext $ctx, string $id): void
    {
        $this->auth->authorize($ctx, 'form.manage');
        Input::id($id, 'Form');
        DB::transaction(function () use ($ctx, $id) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.forms:'.$ctx->siteId]);
            $row = DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->whereNull('purged_at')->lockForUpdate()->first() ?? throw new NotFoundException('Form');
            if (! $row->archived_at) {
                return;
            }
            DB::table('site_forms')->where('id', $id)->update(['archived_at' => null, 'updated_at' => now()]);
            app(AuditLog::class)->forContext($ctx, 'form.restore', 'form', $id, []);
        });
    }

    /**
     * Deletes a form in the Trash permanently, with its entries (the visitors' submissions). It
     * cannot be restored. Published form versions are append-only history and stay, so old
     * publications still reproduce.
     *
     * @return array{entriesDeleted: int}
     */
    public function purge(SiteContext $ctx, string $id): array
    {
        $this->auth->authorize($ctx, 'form.manage');
        Input::id($id, 'Form');

        return DB::transaction(function () use ($ctx, $id) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.forms:'.$ctx->siteId]);
            $row = DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundException('Form');
            if ($row->purged_at) {
                return ['entriesDeleted' => 0];
            }
            if (! $row->archived_at) {
                throw new ConflictException("Move “{$row->name}” to the Trash before deleting it permanently.");
            }
            $entries = DB::table('form_submissions')->where('site_id', $ctx->siteId)->where('form_id', $id)->delete();
            DB::table('site_forms')->where('id', $id)->update(['purged_at' => now()]);
            app(AuditLog::class)->forContext($ctx, 'form.purge', 'form', $id, ['entriesDeleted' => $entries]);

            return ['entriesDeleted' => $entries];
        });
    }

    /** Entries kept for each form, for the permanent-delete confirmation. */
    public function entryCounts(SiteContext $ctx, array $ids): array
    {
        $this->auth->authorize($ctx, 'form.manage');

        return DB::table('form_submissions')->where('site_id', $ctx->siteId)->whereIn('form_id', array_values(array_filter($ids, [Uuid::class, 'isValid'])))
            ->groupBy('form_id')->selectRaw('form_id, count(*) as n')->pluck('n', 'form_id')->map(fn ($n) => (int) $n)->all();
    }
}
