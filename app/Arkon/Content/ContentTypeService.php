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
            $this->assertDistinctFromOthers($ctx->siteId, $key, $definition);
            // A field group may already apply here (a rule naming this key, or "anything but…").
            $this->assertNoGroupClash($ctx->siteId, $key, $definition['fields']);
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
            $this->assertDistinctFromOthers($ctx->siteId, $key, $definition, $current);
            $this->assertNoGroupClash($ctx->siteId, $key, $definition['fields']);
            $confirmed = (bool) ($input['confirmRemovals'] ?? false);
            // A layout that is no longer offered: the sections using it are kept, but they stop
            // being rendered, so the count of entries holding them is worth saying out loud.
            $layouts = FieldChanges::removedLayouts($current['fields'], $definition['fields']);
            if ($layouts !== [] && ! $confirmed) {
                $flexible = app(Flexible::class);
                $counts = array_map(function ($one) use ($flexible, $ctx) {
                    $n = $flexible->entriesUsingLayout($ctx->siteId, $one['fieldKey'], $one['layout']);

                    return '“'.$one['layoutLabel'].'” (used by '.$n.' '.($n === 1 ? 'entry' : 'entries').')';
                }, $layouts);
                throw new ValidationException(
                    'Removing the section kind '.implode(', ', $counts).'. Sections of that kind are kept but stop being shown. Confirm to go ahead.',
                    array_map(fn ($one) => ['path' => 'fields.'.$one['fieldKey'].'.layouts', 'fieldKey' => $one['fieldKey'], 'code' => 'layout_removed', 'message' => 'Layout would be removed'], $layouts),
                );
            }
            $removed = FieldChanges::removed($current['fields'], $definition['fields']);
            $nested = FieldChanges::removedNested($current['fields'], $definition['fields']);
            if (($removed !== [] || $nested !== []) && ! $confirmed) {
                // A content list that filters or orders by a field being removed is named here,
                // because the page keeps working but stops showing what it was set up to show.
                $pages = app(Collections::class)->pagesUsingFields($ctx->siteId, $key, array_column($removed, 'key'));
                throw new ValidationException(
                    'Removing '.implode(', ', [...array_column($removed, 'label'), ...array_column($nested, 'label')]).' would leave the values stored for it unreachable.'
                        .($pages === [] ? '' : ' A content list on '.implode(', ', $pages).' uses it.').' Confirm to go ahead.',
                    [
                        ...array_map(fn ($f) => ['path' => 'fields.'.$f['key'], 'fieldKey' => $f['key'], 'code' => 'field_removed', 'message' => 'Field would be removed'], $removed),
                        ...array_map(fn ($f) => ['path' => 'fields.'.$f['key'], 'fieldKey' => $f['fieldKey'], 'code' => 'field_removed', 'message' => 'Field would be removed'], $nested),
                    ],
                );
            }
            // Fields that keep their key but would no longer hold what entries stored in them.
            app(EntryFields::class)->assertValuesFit($ctx->siteId, [$key], FieldChanges::reshaped($current['fields'], $definition['fields']));
            $changed = self::canonical($current['fields']) !== self::canonical($definition['fields']);
            $version = (int) $row->schema_version + ($changed ? 1 : 0);
            DB::table('content_types')->where('id', $row->id)->update([
                'definition' => Json::encode($definition),
                'schema_version' => $version,
                'updated_at' => now(),
            ]);
            $this->registry->forget($ctx->siteId);
            if ($changed) {
                // The entries keep their JSON, so a mistaken removal can still be undone by
                // putting the field back; the query index is rebuilt for this type's entries
                // only, so nothing filters on a field the type no longer has and no other type's
                // rows are touched.
                app(EntryFields::class)->reindexKinds($ctx->siteId, [$key]);
            }

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
            // What else names this type and would break, or quietly change meaning if the key
            // were ever used again: relationship fields pointing here, field group rules, lists.
            $uses = $this->dependants($ctx->siteId, $key);
            if ($uses !== []) {
                throw new ValidationException('This content type is still used: '.implode('; ', $uses).'. Change those first, or turn the type off instead.');
            }
            DB::table('content_types')->where('id', $row->id)->delete();
            $this->registry->forget($ctx->siteId);
        });
    }

    /**
     * Everything outside its own entries that names a content type.
     *
     * @return list<string> one sentence per kind of use
     */
    private function dependants(string $siteId, string $key): array
    {
        $uses = [];
        $targets = function (array $fields) use ($key, &$targets): bool {
            foreach ($fields as $field) {
                if (($field['type'] ?? null) === 'relationship' && ($field['settings']['targetType'] ?? null) === $key) {
                    return true;
                }
                if ($targets($field['fields'] ?? []) || array_filter($field['layouts'] ?? [], fn ($l) => $targets($l['fields'] ?? [])) !== []) {
                    return true;
                }
            }

            return false;
        };
        $relating = [];
        foreach ($this->registry->forSite($siteId)->defined() as $other => $type) {
            if ($other !== $key && $targets($type['fields'] ?? [])) {
                $relating[] = $type['plural'];
            }
        }
        $groups = app(FieldSchemaResolver::class)->all($siteId);
        foreach ($groups as $group) {
            if ($targets($group['fields'])) {
                $relating[] = 'the field group “'.$group['name'].'”';
            }
        }
        if ($relating !== []) {
            $uses[] = 'related content fields on '.implode(', ', $relating).' point at it';
        }
        $ruled = array_values(array_map(fn ($g) => '“'.$g['name'].'”', array_filter($groups, fn ($g) => in_array($key, LocationRules::referenced($g['rules'])['content_type'] ?? [], true))));
        if ($ruled !== []) {
            $uses[] = 'the location rules of '.implode(', ', $ruled).' name it';
        }
        $lists = app(Collections::class)->pagesListing($siteId, $key);
        if ($lists !== []) {
            $uses[] = 'content lists on '.implode(', ', $lists).' show it';
        }

        return $uses;
    }

    /**
     * Two types the admin menu, the API and the relationship picker would show under the same
     * name, or whose new entries would share one URL prefix, are refused: nobody could tell
     * them apart. Only a change is checked, so types that already clash can still be saved.
     *
     * @param  array<string, mixed>|null  $before  the stored definition, when updating
     */
    private function assertDistinctFromOthers(string $siteId, string $key, array $definition, ?array $before = null): void
    {
        $norm = fn (?string $s) => mb_strtolower(trim((string) $s));
        foreach ($this->registry->forSite($siteId)->all() as $other => $type) {
            if ($other === $key) {
                continue;
            }
            foreach ([['name', 'plural', 'plural name'], ['singular', 'label', 'singular name']] as [$mine, $theirs, $what]) {
                $changed = $before === null || $norm($before[$mine] ?? '') !== $norm($definition[$mine]);
                if ($changed && $norm($definition[$mine]) === $norm($type[$theirs] ?? '')) {
                    throw new ValidationException("“{$definition[$mine]}” is already the {$what} of another content type ({$other}). Choose a name people can tell apart.");
                }
            }
            $changed = $before === null || ($before['pathPrefix'] ?? '') !== $definition['pathPrefix'];
            if ($changed && $definition['pathPrefix'] !== '' && $definition['pathPrefix'] === ($type['pathPrefix'] ?? '')) {
                throw new ValidationException("The URL prefix {$definition['pathPrefix']} is already used by {$type['plural']}. Give this type its own prefix.");
            }
        }
    }

    /**
     * A type's own fields and the fields its groups add share one namespace on every entry, so
     * a type may not declare a key a group already puts on it (the group would win silently).
     */
    private function assertNoGroupClash(string $siteId, string $key, array $fields): void
    {
        $fromGroups = [];
        foreach (app(FieldSchemaResolver::class)->groupsFor($siteId, ['kind' => $key]) as $group) {
            foreach ($group['fields'] as $field) {
                $fromGroups[$field['key']] = $group['name'];
            }
        }
        foreach ($fields as $field) {
            if (isset($fromGroups[$field['key']])) {
                throw FieldSchema::problem($field['label'], $field['key'], 'key', 'duplicate_key',
                    "\"{$field['key']}\" is already a field this type gets from the field group “{$fromGroups[$field['key']]}”. Give this field another key.");
            }
        }
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
