<?php

namespace App\Arkon\Content;

use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\NotFoundException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Field groups: naming them, deciding what fields they hold, and where those fields apply.
 *
 * A group is schema only. Its rules decide which entries carry its fields; nothing is copied, so
 * editing a group changes everything it reaches at once — which is the point, and the reason
 * this service says who that is before letting a change destroy anything.
 *
 * The key never changes after creation, like a content type's: the API, templates and AI name a
 * group by it.
 */
final class FieldGroupService
{
    public function __construct(
        private readonly Authorizer $auth,
        private readonly Transactions $tx,
        private readonly ContentTypeRegistry $types,
        private readonly FieldSchemaResolver $schema,
        private readonly Relationships $relationships,
    ) {}

    /** Every group this site has, with its field count and where it applies, in words. */
    public function browse(SiteContext $ctx): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $names = $this->namer($ctx->siteId);
        $out = [];
        foreach ($this->schema->all($ctx->siteId) as $group) {
            $out[] = [
                'id' => $group['id'],
                'key' => $group['key'],
                'name' => $group['name'],
                'description' => $group['description'],
                'fields' => count($group['fields']),
                'appliesTo' => LocationRules::describe($group['rules'], $names),
                'placed' => ($group['rules']['groups'] ?? []) !== [],
            ];
        }

        return $out;
    }

    /** One group, as its editor needs it. */
    public function show(SiteContext $ctx, string $key): array
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        $row = $this->row($ctx->siteId, $key);
        $rules = Json::toArray(Json::decode($row->rules));

        return [
            'id' => $row->id,
            'key' => $row->key,
            'name' => $row->name,
            'description' => $row->description,
            'fields' => Json::toArray(Json::decode($row->fields)),
            'schemaVersion' => (int) $row->schema_version,
            'rules' => $rules,
            'appliesTo' => LocationRules::describe($rules, $this->namer($ctx->siteId)),
            'missingTargets' => $this->missingTargets($ctx->siteId, $rules),
            'entries' => $this->entryCount($ctx->siteId, $rules),
        ];
    }

    public function create(SiteContext $ctx, array $input): array
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        $input = Json::toArray($input);
        $key = is_string($input['key'] ?? null) ? trim($input['key']) : '';
        $definition = $this->definition($ctx->siteId, $input);
        $rules = LocationRules::validate($input['rules'] ?? ['groups' => []]);

        return $this->tx->run(function () use ($ctx, $key, $definition, $rules) {
            $this->assertKeyAvailable($ctx->siteId, $key);
            $id = Uuid::v7();
            DB::table('field_groups')->insert([
                'id' => $id,
                'site_id' => $ctx->siteId,
                'key' => $key,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'fields' => Json::encode($definition['fields']),
                'rules' => Json::encode($rules),
                'schema_version' => 1,
                'created_by' => $ctx->userId,
            ]);
            $this->schema->forget($ctx->siteId);
            $this->assertNoKeyClash($ctx, $id, $definition['fields'], $rules);
            // Entries reached may already hold values under these keys (a group put back after it
            // was deleted): they become visible again, so they become queryable again too.
            app(EntryFields::class)->assertValuesFit($ctx->siteId, $this->kindsReached($ctx->siteId, $rules), $definition['fields']);
            app(EntryFields::class)->reindexKinds($ctx->siteId, $this->kindsReached($ctx->siteId, $rules));

            return $this->show($ctx, $key);
        });
    }

    /**
     * Changes a group. Its fields are shared, so removing one would strand what every entry
     * reached by its rules stored in it: that needs confirming, as it does on a content type.
     */
    public function update(SiteContext $ctx, string $key, array $input): array
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        $input = Json::toArray($input);
        if (isset($input['key']) && $input['key'] !== $key) {
            throw new ValidationException('A field group keeps the key it was created with, because its API, templates and rules all use it.');
        }
        $definition = $this->definition($ctx->siteId, $input);

        return $this->tx->run(function () use ($ctx, $key, $definition, $input) {
            $row = $this->row($ctx->siteId, $key, lock: true);
            $current = Json::toArray(Json::decode($row->fields));
            $rules = array_key_exists('rules', $input)
                ? LocationRules::validate($input['rules'])
                : Json::toArray(Json::decode($row->rules));
            $expected = $input['expectedVersion'] ?? null;
            if ($expected !== null && (int) $expected !== (int) $row->schema_version) {
                throw new ConflictException('This field group changed somewhere else. Reload before saving again.');
            }
            $before = Json::toArray(Json::decode($row->rules));
            FieldChanges::assertTypesCompatible($current, $definition['fields']);
            $confirmed = (bool) ($input['confirmRemovals'] ?? false);
            $removed = [...FieldChanges::removed($current, $definition['fields']), ...FieldChanges::removedNested($current, $definition['fields'])];
            if ($removed !== [] && ! $confirmed) {
                throw new ValidationException(
                    'Removing '.implode(', ', array_column($removed, 'label')).' would leave the values stored for it unreachable on '
                        .LocationRules::describe($rules, $this->namer($ctx->siteId)).'. Confirm to go ahead.',
                    array_map(fn ($f) => ['path' => 'fields.'.$f['key'], 'fieldKey' => $f['fieldKey'] ?? $f['key'], 'code' => 'field_removed', 'message' => 'Field would be removed'], $removed),
                );
            }
            // New rules that take these fields off entries (or off site data) that had them: their
            // values stay stored, but nobody sees them until the fields apply there again.
            $losing = $this->losing($ctx->siteId, $before, $rules, $current);
            if ($losing !== null && ! $confirmed) {
                throw new ValidationException(
                    'With these rules, '.$losing.' would no longer show '.$definition['name'].'. What they stored is kept and comes back if the fields apply there again. Confirm to go ahead.',
                    [['path' => 'rules', 'code' => 'rules_narrowed', 'message' => 'Fields would stop applying somewhere they did']],
                );
            }
            $kinds = array_values(array_unique([...$this->kindsReached($ctx->siteId, $before), ...$this->kindsReached($ctx->siteId, $rules)]));
            $reshaped = FieldChanges::reshaped($current, $definition['fields']);
            app(EntryFields::class)->assertValuesFit($ctx->siteId, $this->kindsReached($ctx->siteId, $rules), $reshaped);
            if (LocationRules::match($rules, SiteDataService::CONTEXT)) {
                $this->assertSiteDataFits($ctx->siteId, $reshaped);
            }
            $changed = Json::encode($this->canonical($current)) !== Json::encode($this->canonical($definition['fields']));
            DB::table('field_groups')->where('id', $row->id)->update([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'fields' => Json::encode($definition['fields']),
                'rules' => Json::encode($rules),
                'schema_version' => (int) $row->schema_version + ($changed ? 1 : 0),
                'updated_at' => now(),
            ]);
            $this->schema->forget($ctx->siteId);
            $this->assertNoKeyClash($ctx, $row->id, $definition['fields'], $rules);
            if ($changed || Json::encode($this->canonical($before)) !== Json::encode($this->canonical($rules))) {
                // Only the entries these fields reached before or reach now are re-projected, so
                // nothing filters on a field that left them and no other type's rows change.
                app(EntryFields::class)->reindexKinds($ctx->siteId, $kinds);
            }

            return $this->show($ctx, $key);
        });
    }

    /**
     * Removes a group. The entries keep whatever they stored, because a value belongs to the
     * entry and not to the group; what goes is the schema and its rules.
     */
    public function delete(SiteContext $ctx, string $key, bool $confirmed = false): void
    {
        $this->auth->authorize($ctx, 'contentType.manage');
        $this->tx->run(function () use ($ctx, $key, $confirmed) {
            $row = $this->row($ctx->siteId, $key, lock: true);
            $rules = Json::toArray(Json::decode($row->rules));
            if (($rules['groups'] ?? []) !== [] && ! $confirmed) {
                throw new ValidationException(
                    'This field group applies to '.LocationRules::describe($rules, $this->namer($ctx->siteId))
                        .'. Deleting it takes its fields off them. Confirm to go ahead.'
                );
            }
            DB::table('field_groups')->where('id', $row->id)->delete();
            $this->schema->forget($ctx->siteId);
            // The values stay with the entries; what goes is the index of fields they no longer carry.
            app(EntryFields::class)->reindexKinds($ctx->siteId, $this->kindsReached($ctx->siteId, $rules));
        });
    }

    /**
     * Where fields that applied under the old rules would stop applying under the new ones, in
     * words, or null when nothing loses them. Entries are checked one by one against both sets
     * of rules (a rule about an entry or a term narrows within a type); site data likewise.
     */
    private function losing(string $siteId, array $before, array $after, array $fields): ?string
    {
        if ($fields === [] || Json::encode($this->canonical($before)) === Json::encode($this->canonical($after))) {
            return null;
        }
        $parts = [];
        $count = 0;
        $titles = [];
        $kinds = $this->kindsReached($siteId, $before);
        if ($kinds !== []) {
            DB::table('pages')->where('site_id', $siteId)->whereIn('kind', $kinds)->whereNull('purged_at')
                ->orderBy('id')->select(['id', 'title'])->chunkById(500, function ($pages) use ($siteId, $before, $after, &$count, &$titles) {
                    $contexts = $this->schema->contextsForEntries($siteId, $pages->pluck('id')->all());
                    foreach ($pages as $page) {
                        $context = $contexts[$page->id] ?? null;
                        if ($context !== null && LocationRules::match($before, $context) && ! LocationRules::match($after, $context)) {
                            $count++;
                            $titles[] = $page->title;
                        }
                    }
                });
        }
        if ($count > 0) {
            $parts[] = $count.' '.($count === 1 ? 'entry' : 'entries').' ('.implode(', ', array_map(fn ($t) => '“'.$t.'”', array_slice($titles, 0, 3))).($count > 3 ? ', …' : '').')';
        }
        if (LocationRules::match($before, SiteDataService::CONTEXT) && ! LocationRules::match($after, SiteDataService::CONTEXT)) {
            $parts[] = 'site data';
        }

        return $parts === [] ? null : implode(' and ', $parts);
    }

    /** The site data equivalent of EntryFields::assertValuesFit(). */
    private function assertSiteDataFits(string $siteId, array $fields): void
    {
        $values = Json::toArray(Json::decode(DB::table('site_data')->where('site_id', $siteId)->value('values') ?? '{}')) ?: [];
        foreach ($fields as $field) {
            try {
                FieldValues::one($field, FieldValues::prune([$field], $values)[$field['key']] ?? null);
            } catch (ValidationException) {
                throw new ValidationException(
                    "{$field['label']}: site data holds a value it could no longer keep. Change or clear it on the Site data screen first, or add a new field instead of changing this one.",
                    [['path' => 'fields.'.$field['key'], 'fieldKey' => $field['key'], 'code' => 'values_do_not_fit', 'message' => 'Stored values would no longer fit']],
                );
            }
        }
    }

    /**
     * A target's fields share one namespace, because that is what an entry stores and what the
     * API returns. Two fields with one key would make the second unreachable, so a clash is
     * refused while the rules are being set rather than found later by whoever edits an entry.
     *
     * Checked against the content types the rules can reach: those are the schemas a key has to
     * be unique within.
     */
    private function assertNoKeyClash(SiteContext $ctx, string $groupId, array $fields, array $rules): void
    {
        $mine = array_column($fields, 'key');
        // Site data is a target of its own: its keys must be distinct among the groups on it,
        // and have nothing to do with the fields of any content type.
        if (LocationRules::match($rules, SiteDataService::CONTEXT)) {
            foreach ($this->schema->fieldsFor($ctx->siteId, SiteDataService::CONTEXT, exceptGroup: $groupId) as $field) {
                if (in_array($field['key'], $mine, true)) {
                    throw new ValidationException(
                        "\"{$field['key']}\" is already a field on site data. Rename it in one place before these fields apply there.",
                        [['path' => 'rules', 'fieldKey' => $field['key'], 'code' => 'duplicate_key', 'message' => 'Field key already used on the target']],
                    );
                }
            }
        }
        foreach ($this->kindsReached($ctx->siteId, $rules) as $kind) {
            foreach ($this->schema->fieldsFor($ctx->siteId, ['kind' => $kind], exceptGroup: $groupId) as $field) {
                if (in_array($field['key'], $mine, true)) {
                    $label = $this->types->forSite($ctx->siteId)->exists($kind) ? $this->types->forSite($ctx->siteId)->get($kind)['plural'] : $kind;
                    throw new ValidationException(
                        "\"{$field['key']}\" is already a field on {$label}. Rename it in one place before these fields apply there.",
                        [['path' => 'rules', 'fieldKey' => $field['key'], 'code' => 'duplicate_key', 'message' => 'Field key already used on the target']],
                    );
                }
            }
        }
    }

    /**
     * The content types a set of rules can put fields on. A rule naming a type reaches it; a rule
     * about an entry reaches that entry's type; rules about terms or roles narrow which entries
     * are reached but not which types, so those are checked against every type the site has.
     *
     * @return list<string>
     */
    private function kindsReached(string $siteId, array $rules): array
    {
        $types = $this->types->forSite($siteId);
        $kinds = [];
        foreach ($rules['groups'] ?? [] as $set) {
            $named = [];
            $narrowed = false;
            // "Where is site data" puts these fields on the site, not on any entry.
            $siteOnly = array_filter($set['rules'], fn ($rule) => $rule['attribute'] === 'scope'
                && (($rule['operator'] === 'is' && $rule['value'] === 'site') || ($rule['operator'] === 'is_not' && $rule['value'] === 'entry'))) !== [];
            if ($siteOnly) {
                continue;
            }
            foreach ($set['rules'] as $rule) {
                if ($rule['attribute'] === 'content_type' && $rule['operator'] === 'is') {
                    $named[] = $rule['value'];
                } elseif ($rule['attribute'] === 'entry' && $rule['operator'] === 'is') {
                    $kind = DB::table('pages')->where('site_id', $siteId)->where('id', $rule['value'])->value('kind');
                    if (is_string($kind)) {
                        $named[] = $kind;
                    }
                    $narrowed = true;
                } else {
                    $narrowed = true;
                }
            }
            // Nothing named a type, so the rule group can land on any of them.
            $kinds = [...$kinds, ...($named !== [] ? $named : ($narrowed ? array_keys($types->all()) : []))];
        }

        return array_values(array_unique($kinds));
    }

    /**
     * Rule values whose target no longer exists. The rule is kept — removing it would quietly
     * change where fields appear — and the editor shows it as missing so it can be repaired.
     *
     * @return list<array{attribute: string, value: string}>
     */
    private function missingTargets(string $siteId, array $rules): array
    {
        $names = $this->namer($siteId);
        $missing = [];
        foreach (LocationRules::referenced($rules) as $attribute => $values) {
            foreach ($values as $value) {
                if ($names($attribute, $value) === null) {
                    $missing[] = ['attribute' => $attribute, 'value' => $value];
                }
            }
        }

        return $missing;
    }

    /**
     * Turns a stored rule value into what a person calls it, or null when it is gone. Reads are
     * cached for the call, so describing many groups does not repeat the same lookups.
     */
    private function namer(string $siteId): callable
    {
        $types = $this->types->forSite($siteId);
        $entries = null;
        $terms = null;

        return function (string $attribute, string $value) use ($siteId, $types, &$entries, &$terms): ?string {
            switch ($attribute) {
                case 'content_type':
                    return $types->exists($value) ? $types->get($value)['plural'] : null;
                case 'entry':
                    $entries ??= DB::table('pages')->where('site_id', $siteId)->whereNull('deleted_at')->pluck('title', 'id')->all();

                    return $entries[$value] ?? null;
                case 'taxonomy_term':
                    $terms ??= DB::table('terms')->where('site_id', $siteId)->pluck('name', 'id')->all();

                    return $terms[$value] ?? null;
                case 'scope':
                    return LocationRules::SCOPES[$value] ?? null;
                case 'user_role':
                    return in_array($value, Permissions::ROLES, true) ? ucfirst($value) : null;
                default:
                    return null;
            }
        };
    }

    /** How many entries a change to this group would touch. */
    private function entryCount(string $siteId, array $rules): int
    {
        $kinds = $this->kindsReached($siteId, $rules);

        return $kinds === [] ? 0 : (int) DB::table('pages')->where('site_id', $siteId)
            ->whereIn('kind', $kinds)->whereNull('deleted_at')->whereNull('purged_at')->count();
    }

    /** The parts of the input this service owns, checked by the shared field engine. */
    private function definition(string $siteId, array $input): array
    {
        $limits = FieldTypes::registry()['limits'];
        $d = Input::validate($input, [
            'name' => 'required|string|max:'.$limits['name'],
            'description' => 'present|nullable|string|max:'.$limits['description'],
            'fields' => 'present|array|max:'.$limits['fields'],
        ]);

        $fields = FieldSchema::fields($input['fields']);
        $this->relationships->assertTargets($siteId, $fields);

        return ['name' => trim($d['name']), 'description' => $d['description'] ?? '', 'fields' => $fields];
    }

    /** Key order in jsonb is Postgres's, so only a real change to the fields counts as one. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map($this->canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function assertKeyAvailable(string $siteId, string $key): void
    {
        if (! preg_match('/'.FieldTypes::pattern('typeKey').'/D', $key)) {
            throw new ValidationException('The key must start with a letter and use only lowercase letters, numbers and underscores.');
        }
        if (DB::table('field_groups')->where('site_id', $siteId)->where('key', $key)->exists()) {
            throw new ValidationException("This site already has a field group with the key \"{$key}\".");
        }
    }

    private function row(string $siteId, string $key, bool $lock = false): object
    {
        $query = DB::table('field_groups')->where('site_id', $siteId)->where('key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new NotFoundException('Field group');
    }
}
