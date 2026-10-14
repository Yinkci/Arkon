<?php

namespace App\Arkon\Content;

use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Defining a site's content types: the rules for creating, changing, disabling and removing a
 * type. Only definitions live here — entries are pages and belong to the page services — so
 * this stays the one place that decides what a type may be called and what it may hold.
 *
 * Two rules shape everything else. A type's key never changes, because routes, templates, the
 * API and AI all name it; and a change to the fields raises the schema version, so anything
 * holding an older definition can tell.
 */
final class ContentTypeService
{
    public function __construct(
        private readonly Authorizer $auth,
        private readonly Transactions $tx,
        private readonly ContentTypeRegistry $registry,
        private readonly Relationships $relationships,
    ) {}

    /** Every type the site has, built-in and defined, each with how many entries it holds. */
    public function browse(SiteContext $ctx): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $counts = DB::table('pages')->where('site_id', $ctx->siteId)->whereNull('deleted_at')
            ->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');
        $types = [];
        foreach ($this->registry->forSite($ctx->siteId)->all() as $key => $type) {
            $types[] = [
                'key' => $key,
                'label' => $type['label'],
                'plural' => $type['plural'],
                'description' => $type['description'] ?? '',
                'icon' => $type['icon'] ?? ($key === 'post' ? 'pencil' : 'page'),
                'defined' => $type['defined'] ?? false,
                'active' => $type['active'] ?? true,
                'fields' => count($type['fields'] ?? []),
                'entries' => (int) ($counts[$key] ?? 0),
                'schemaVersion' => $type['schemaVersion'] ?? null,
            ];
        }

        return $types;
    }

    /** One defined type, as the editor needs it. */
    public function show(SiteContext $ctx, string $key): array
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        $row = $this->row($ctx->siteId, $key);

        return [
            'key' => $row->key,
            'schemaVersion' => (int) $row->schema_version,
            'active' => (bool) $row->active,
            'entries' => $this->entryCount($ctx->siteId, $key),
            ...Json::toArray(Json::decode($row->definition)),
        ];
    }

    /**
     * Defines a new type. The key is taken from the input, checked once, and then fixed: it is
     * what the API, templates and AI will use from here on.
     */
    public function create(SiteContext $ctx, array $input): array
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        $key = is_string($input['key'] ?? null) ? trim($input['key']) : '';
        $definition = TypeDefinition::validate($input);
        // A type may relate to itself, so its own key counts as known before it exists.
        $this->relationships->assertTargets($ctx->siteId, $definition['fields'], allowKey: $key);

        return $this->tx->run(function () use ($ctx, $key, $definition) {
            $this->assertKeyAvailable($ctx->siteId, $key);
            $limit = FieldTypes::limit('typesPerSite');
            if (DB::table('content_types')->where('site_id', $ctx->siteId)->count() >= $limit) {
                throw new ValidationException("A site may define at most {$limit} content types.");
            }
            $id = Uuid::v7();
            DB::table('content_types')->insert([
                'id' => $id,
                'site_id' => $ctx->siteId,
                'key' => $key,
                'definition' => Json::encode($definition),
                'schema_version' => 1,
                'active' => true,
                'created_by' => $ctx->userId,
            ]);
            $this->registry->forget($ctx->siteId);

            return ['id' => $id, 'key' => $key, 'schemaVersion' => 1, 'active' => true, 'entries' => 0, ...$definition];
        });
    }

    /**
     * Changes a type. Renaming what people read is free; changing the fields is not, so the
     * schema version rises and the caller is told which fields would lose their values.
     */
    public function update(SiteContext $ctx, string $key, array $input): array
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        if (isset($input['key']) && $input['key'] !== $key) {
            throw new ValidationException('A content type keeps the key it was created with, because its API, templates and links all use it.');
        }
        $definition = TypeDefinition::validate($input);
        $this->relationships->assertTargets($ctx->siteId, $definition['fields'], allowKey: $key);

        return $this->tx->run(function () use ($ctx, $key, $definition, $input) {
            $row = $this->row($ctx->siteId, $key, lock: true);
            $current = Json::toArray(Json::decode($row->definition));
            $expected = $input['expectedVersion'] ?? null;
            if ($expected !== null && (int) $expected !== (int) $row->schema_version) {
                throw new ConflictException('This content type changed somewhere else. Reload before saving again.');
            }
            FieldChanges::assertTypesCompatible($current['fields'], $definition['fields']);
            // A layout that is no longer offered: the sections using it are kept, but they stop
            // being rendered, so the count of entries holding them is worth saying out loud.
            $layouts = FieldChanges::removedLayouts($current['fields'], $definition['fields']);
            if ($layouts !== [] && ! ($input['confirmRemovals'] ?? false)) {
                $flexible = app(Flexible::class);
                $counts = array_map(
                    fn ($one) => '“'.$one['layoutLabel'].'” is used by '.$flexible->entriesUsingLayout($ctx->siteId, $one['fieldKey'], $one['layout']).' entries',
                    $layouts,
                );
                throw new ValidationException(
                    'Removing '.implode(', ', $counts).'. Their content is kept but stops being shown. Confirm to go ahead.',
                    array_map(fn ($one) => ['path' => 'fields.'.$one['fieldKey'].'.layouts', 'fieldKey' => $one['fieldKey'], 'code' => 'layout_removed', 'message' => 'Layout would be removed'], $layouts),
                );
            }
            $removed = FieldChanges::removed($current['fields'], $definition['fields']);
            if ($removed !== [] && ! ($input['confirmRemovals'] ?? false)) {
                // A content list that filters or orders by a field being removed is named here,
                // because the page keeps working but stops showing what it was set up to show.
                $pages = app(Collections::class)->pagesUsingFields($ctx->siteId, $key, array_column($removed, 'key'));
                throw new ValidationException(
                    'Removing '.implode(', ', array_column($removed, 'label')).' would leave the values stored for it unreachable.'
                        .($pages === [] ? '' : ' A content list on '.implode(', ', $pages).' uses it.').' Confirm to go ahead.',
                    array_map(fn ($f) => ['path' => 'fields.'.$f['key'], 'fieldKey' => $f['key'], 'code' => 'field_removed', 'message' => 'Field would be removed'], $removed),
                );
            }
            $changed = self::canonical($current['fields']) !== self::canonical($definition['fields']);
            $version = (int) $row->schema_version + ($changed ? 1 : 0);
            DB::table('content_types')->where('id', $row->id)->update([
                'definition' => Json::encode($definition),
                'schema_version' => $version,
                'updated_at' => now(),
            ]);
            if ($removed !== []) {
                // The entries keep their JSON, so a mistaken removal can still be undone by
                // putting the field back; only the query index drops, because nothing may
                // filter on a field the type no longer has.
                DB::table('content_field_index')->where('site_id', $ctx->siteId)
                    ->whereIn('field_key', array_column($removed, 'key'))->delete();
            }
            $this->registry->forget($ctx->siteId);

            return ['key' => $key, 'schemaVersion' => $version, 'active' => (bool) $row->active, 'entries' => $this->entryCount($ctx->siteId, $key), ...$definition];
        });
    }

    /** Turns a type off without touching its entries, which is the safe alternative to deleting. */
    public function setActive(SiteContext $ctx, string $key, bool $active): array
    {
        $this->auth->authorize($ctx, 'contentType.manage');

        return $this->tx->run(function () use ($ctx, $key, $active) {
            $row = $this->row($ctx->siteId, $key, lock: true);
            DB::table('content_types')->where('id', $row->id)->update(['active' => $active, 'updated_at' => now()]);
            $this->registry->forget($ctx->siteId);

            return ['key' => $key, 'active' => $active];
        });
    }

    /**
     * Removes a definition. Refused while entries exist: deleting a type would orphan them, and
     * turning the type off keeps them readable, so the caller is sent there instead.
     */
    public function delete(SiteContext $ctx, string $key): void
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        $this->tx->run(function () use ($ctx, $key) {
            $row = $this->row($ctx->siteId, $key, lock: true);
            $entries = $this->entryCount($ctx->siteId, $key, includeTrashed: true);
            if ($entries > 0) {
                throw new ValidationException(
                    "This content type still holds {$entries} ".($entries === 1 ? 'entry' : 'entries').'. Delete them first, or turn the type off to keep them.'
                );
            }
            DB::table('content_types')->where('id', $row->id)->delete();
            $this->registry->forget($ctx->siteId);
        });
    }

    /**
     * A comparable form of a definition. Postgres stores jsonb with its own key order, so a
     * definition read back is never byte-identical to the one written; sorting the keys of every
     * object first means only a real change to the fields counts as a change.
     */
    private static function canonical(mixed $value): string
    {
        $sort = function (mixed $node) use (&$sort): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $node = array_map($sort, $node);
            if (! array_is_list($node)) {
                ksort($node);
            }

            return $node;
        };

        return Json::encode($sort($value));
    }

    private function assertKeyAvailable(string $siteId, string $key): void
    {
        if (! preg_match('/'.FieldTypes::pattern('typeKey').'/D', $key)) {
            throw new ValidationException('The key must start with a letter and use only lowercase letters, numbers and underscores.');
        }
        if (in_array($key, FieldTypes::reservedTypeKeys(), true)) {
            throw new ValidationException("\"{$key}\" is a name Arkon already uses. Choose another key.");
        }
        if ($this->registry->forSite($siteId)->exists($key)) {
            throw new ValidationException("This site already has a content type with the key \"{$key}\".");
        }
    }

    private function entryCount(string $siteId, string $key, bool $includeTrashed = false): int
    {
        $query = DB::table('pages')->where('site_id', $siteId)->where('kind', $key);
        if (! $includeTrashed) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function row(string $siteId, string $key, bool $lock = false): object
    {
        $query = DB::table('content_types')->where('site_id', $siteId)->where('key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new NotFoundException('Content type');
    }
}
