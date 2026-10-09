<?php

namespace App\Arkon\Forms;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Design\PageRefreshes;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Fingerprint;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class FormService
{
    public function __construct(private readonly Authorizer $auth, private readonly Transactions $transactions) {}

    public static function validateDefinition(mixed $input): array
    {
        if (! is_array($input)) {
            throw new ValidationException('A form definition is required.');
        }
        $definition = Input::validate($input, [
            'name' => ['required', 'string', 'max:100'], 'submitLabel' => ['required', 'string', 'max:60'], 'successMessage' => ['required', 'string', 'max:400'],
            'fields' => ['required', 'array', 'min:1', 'max:20'], 'fields.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/D', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:120'], 'fields.*.type' => ['required', 'in:text,email,tel,textarea,select,checkbox'],
            'fields.*.required' => ['required', 'boolean'], 'fields.*.options' => ['sometimes', 'array', 'max:20'], 'fields.*.options.*' => ['string', 'max:100', 'distinct'],
        ]);
        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'select' && empty($field['options'])) {
                throw new ValidationException('Dropdowns need options.');
            }
        }
        $definition['fields'] = array_map(fn ($f) => array_intersect_key($f, array_flip(['id', 'label', 'type', 'required', 'options'])), $definition['fields']);

        return array_intersect_key($definition, array_flip(['name', 'submitLabel', 'successMessage', 'fields']));
    }

    public function list(SiteContext $ctx): array
    {
        $role = $this->auth->authorize($ctx, 'page.view');

        return DB::table('site_forms')->where('site_id', $ctx->siteId)->orderBy('name')->get()->map(fn ($row) => [
            'id' => $row->id, 'version' => (int) $row->version, 'publishedVersion' => $row->published_version, 'definition' => Json::decode($row->draft),
            'notificationEmail' => Permissions::allows($role, 'page.publish') ? $row->notification_email : null,
        ])->all();
    }

    public function save(SiteContext $ctx, array $input): array
    {
        $this->auth->authorize($ctx, 'page.edit');
        $v = Input::validate($input, ['id' => ['nullable', 'uuid'], 'baseVersion' => ['required', 'integer', 'min:0'], 'requestKey' => Input::requestKeyRule()]);
        $definition = self::validateDefinition($input['definition'] ?? null);
        $fingerprint = Fingerprint::of(['kind' => 'form.save', 'id' => $v['id'] ?? null, 'base' => $v['baseVersion'], 'definition' => $definition]);

        return $this->transactions->run(function () use ($ctx, $v, $definition, $fingerprint) {
            $this->auth->authorize($ctx, 'page.edit');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.forms:'.$ctx->siteId]);
            if ($result = $this->replay($ctx, $v['requestKey'], $fingerprint)) {
                return $result;
            }
            $id = $v['id'] ?? Uuid::v7();
            $row = DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->lockForUpdate()->first();
            if (isset($v['id']) && ! $row) {
                throw new NotFoundException('Form');
            }
            if ((int) ($row?->version ?? 0) !== (int) $v['baseVersion']) {
                throw new StaleVersionException((int) $v['baseVersion'], (int) ($row?->version ?? 0));
            }
            $version = (int) $v['baseVersion'] + 1;
            $values = ['name' => $definition['name'], 'draft' => Json::encode($definition), 'version' => $version];
            if ($row) {
                DB::table('site_forms')->where('id', $id)->update($values);
            } else {
                DB::table('site_forms')->insert(['id' => $id, 'site_id' => $ctx->siteId, ...$values]);
            }

            app(AuditLog::class)->forContext($ctx, 'form.draft.save', 'form', $id, ['version' => $version]);

            return $this->record($ctx, $v['requestKey'], $fingerprint, ['id' => $id, 'version' => $version, 'replayed' => false]);
        });
    }

    public function publish(SiteContext $ctx, string $id, array $input): array
    {
        $v = Input::validate($input, ['expectedVersion' => ['required', 'integer', 'min:1'], 'requestKey' => Input::requestKeyRule()]);
        $fp = Fingerprint::of(['kind' => 'form.publish', 'id' => $id, 'version' => $v['expectedVersion']]);
        $result = $this->transactions->run(function () use ($ctx, $id, $v, $fp) {
            $this->auth->authorize($ctx, 'page.publish');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['arkon.forms:'.$ctx->siteId]);
            if ($result = $this->replay($ctx, $v['requestKey'], $fp)) {
                return $result;
            }
            $row = DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->lockForUpdate()->first() ?? throw new NotFoundException('Form');
            if ((int) $row->version !== (int) $v['expectedVersion']) {
                throw new StaleVersionException((int) $v['expectedVersion'], (int) $row->version);
            }
            $epoch = app(PageStore::class)->lockNextEpoch($ctx->siteId);
            $version = (int) ($row->published_version ?? 0) + 1;
            DB::table('site_form_versions')->insert(['site_id' => $ctx->siteId, 'form_id' => $id, 'version' => $version, 'definition' => $row->draft, 'epoch' => $epoch]);
            DB::table('site_forms')->where('id', $id)->update(['published_version' => $version]);
            app(PageRefreshes::class)->enqueue($ctx->siteId, 'form', $id, $version);

            app(AuditLog::class)->forContext($ctx, 'form.publish', 'form', $id, ['version' => $version]);

            return $this->record($ctx, $v['requestKey'], $fp, ['id' => $id, 'publishedVersion' => $version, 'replayed' => false]);
        });
        if (DB::transactionLevel() === 0) {
            app(PageRefreshes::class)->run($ctx->siteId);
        }

        return $result;
    }

    public static function assertReferences(string $siteId, mixed $doc): void
    {
        $ids = self::references($doc);
        $found = DB::table('site_forms')->where('site_id', $siteId)->whereIn('id', $ids)->pluck('id')->all();
        if (array_diff($ids, $found) !== []) {
            throw new ValidationException('A form on this page does not exist in this site.');
        }
    }

    public function notifications(SiteContext $ctx, string $id, mixed $email): void
    {
        $this->auth->authorize($ctx, 'page.publish');
        Input::validate(['email' => $email], ['email' => ['nullable', 'email', 'max:254']]);
        if (! DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->exists()) {
            throw new NotFoundException('Form');
        }
        $this->transactions->run(function () use ($ctx, $id, $email) {
            $this->auth->authorize($ctx, 'page.publish');
            DB::table('site_forms')->where('site_id', $ctx->siteId)->where('id', $id)->update(['notification_email' => $email ?: null]);
            app(AuditLog::class)->forContext($ctx, 'form.notifications', 'form', $id, ['enabled' => (bool) $email]);
        });
    }

    public function submissions(SiteContext $ctx): array
    {
        $this->auth->authorize($ctx, 'page.publish');

        return DB::table('form_submissions')->where('site_id', $ctx->siteId)->orderByDesc('created_at')->limit(100)->get()->map(fn ($r) => [
            'id' => $r->id, 'formId' => $r->form_id, 'createdAt' => $r->created_at, 'notificationStatus' => $r->notification_status,
            'values' => Json::decode(Crypt::decryptString($r->payload)),
        ])->all();
    }

    public static function references(mixed $doc): array
    {
        $ids = [];
        foreach (Json::entries($doc['nodes'] ?? []) as $node) {
            $props = Json::entries($node['props'] ?? []);
            $form = Json::entries($props['form'] ?? []);
            if (($node['type'] ?? '') === 'form' && isset($form['id'])) {
                $ids[] = $form['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    public static function published(string $siteId, mixed $doc, array $components = []): array
    {
        $ids = self::references($doc);
        foreach ($components as $component) {
            $ids = [...$ids, ...self::references($component['document'])];
        }
        $out = [];
        foreach (DB::table('site_forms as f')->join('site_form_versions as v', fn ($j) => $j->on('v.form_id', '=', 'f.id')->on('v.site_id', '=', 'f.site_id')->on('v.version', '=', 'f.published_version'))->where('f.site_id', $siteId)->whereIn('f.id', array_unique($ids))->get(['f.id', 'v.version', 'v.definition']) as $r) {
            $out[$r->id] = ['version' => (int) $r->version, 'definition' => Json::decode($r->definition)];
        }

        return $out;
    }

    private function replay(SiteContext $ctx, string $key, string $fp): ?array
    {
        $r = DB::table('site_resource_requests')->where('site_id', $ctx->siteId)->where('request_key', $key)->first();
        if (! $r) {
            return null;
        }if ($r->fingerprint !== $fp) {
            throw new ConflictException('This request key belongs to a different resource change.');
        }

        return [...Json::decode($r->result), 'replayed' => true];
    }

    private function record(SiteContext $ctx, string $key, string $fp, array $result): array
    {
        DB::table('site_resource_requests')->insert(['site_id' => $ctx->siteId, 'request_key' => $key, 'fingerprint' => $fp, 'result' => Json::encode($result)]);

        return $result;
    }
}
