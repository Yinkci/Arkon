<?php

namespace App\Arkon\Content;

use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;

/**
 * The one answer to "which fields does this thing carry?".
 *
 * An entry carries the fields its content type declares itself, followed by the fields of every
 * field group whose location rules match it. The admin editor, the entry editor, the API and AI
 * all ask here, so none of them can disagree about the schema, and a rule is interpreted in one
 * place only (LocationRules).
 *
 * A site's groups are read once per request and the rules are then evaluated in memory, so
 * opening an entry costs one query however many groups and rules a site has.
 */
final class FieldSchemaResolver
{
    /** @var array<string, list<array<string, mixed>>> site => every group it has */
    private array $groups = [];

    public function __construct(private readonly ContentTypeRegistry $types) {}

    public function forget(?string $siteId = null): void
    {
        if ($siteId === null) {
            $this->groups = [];
        } else {
            unset($this->groups[$siteId]);
        }
        $this->types->forget($siteId);
    }

    /**
     * Every field group on a site, with its fields and rules. The one read everything else uses.
     *
     * @return list<array{id: string, key: string, name: string, description: string, fields: list<array<string, mixed>>, rules: array}>
     */
    public function all(string $siteId): array
    {
        if (isset($this->groups[$siteId])) {
            return $this->groups[$siteId];
        }
        $groups = [];
        foreach (DB::table('field_groups')->where('site_id', $siteId)->orderBy('name')->get() as $row) {
            $groups[] = [
                'id' => $row->id,
                'key' => $row->key,
                'name' => $row->name,
                'description' => $row->description,
                'fields' => Json::toArray(Json::decode($row->fields)),
                'rules' => Json::toArray(Json::decode($row->rules)),
            ];
        }

        return $this->groups[$siteId] = $groups;
    }

    /**
     * The groups that apply to what is being edited, in a stable order.
     *
     * @param  array{kind?: ?string, entryId?: ?string, terms?: list<string>, role?: ?string}  $context
     */
    public function groupsFor(string $siteId, array $context, ?string $exceptGroup = null): array
    {
        return array_values(array_filter(
            $this->all($siteId),
            fn ($group) => $group['id'] !== $exceptGroup && LocationRules::match($group['rules'], $context),
        ));
    }

    /**
     * Everything known about one entry, which is what a rule is evaluated against.
     *
     * @return array{kind: ?string, entryId: string, terms: list<string>, role: ?string}
     */
    public function contextForEntry(string $siteId, string $entryId, ?string $role = null): array
    {
        return $this->contextsForEntries($siteId, [$entryId], $role)[$entryId]
            ?? ['kind' => null, 'entryId' => $entryId, 'terms' => [], 'role' => $role];
    }

    /**
     * The same for several entries at once, in two queries however many there are: a list screen
     * or a draft read must not cost a query per row.
     *
     * @param  list<string>  $entryIds
     * @return array<string, array{kind: ?string, entryId: string, terms: list<string>, role: ?string}>
     */
    public function contextsForEntries(string $siteId, array $entryIds, ?string $role = null): array
    {
        if ($entryIds === []) {
            return [];
        }
        $contexts = [];
        foreach (DB::table('pages')->where('site_id', $siteId)->whereIn('id', $entryIds)->get(['id', 'kind']) as $row) {
            $contexts[$row->id] = ['kind' => $row->kind, 'entryId' => $row->id, 'terms' => [], 'role' => $role];
        }
        foreach (DB::table('page_terms')->where('site_id', $siteId)->whereIn('page_id', $entryIds)->get(['page_id', 'term_id']) as $row) {
            $contexts[$row->page_id]['terms'][] = $row->term_id;
        }

        return $contexts;
    }

    /**
     * Every field something carries, in editor order: the type's own first, then each group's.
     *
     * @return list<array<string, mixed>>
     */
    public function fieldsFor(string $siteId, array $context, ?string $exceptGroup = null): array
    {
        $fields = isset($context['kind']) ? $this->ownFields($siteId, $context['kind']) : [];
        foreach ($this->groupsFor($siteId, $context, $exceptGroup) as $group) {
            foreach ($group['fields'] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The definition values are checked against: every field that applies, flat, as FieldValues
     * expects. Null when this kind carries no fields at all.
     */
    public function definitionFor(string $siteId, array $context): ?array
    {
        $fields = $this->fieldsFor($siteId, $context);
        $kind = $context['kind'] ?? null;
        if ($fields === [] && ! ($kind !== null && $this->types->forSite($siteId)->isDefined($kind))) {
            return null;
        }

        return ['fields' => $fields];
    }

    /**
     * How the entry editor shows them: the type's own fields as one unnamed section, then one
     * section per group. Grouping is for people — the values themselves stay flat.
     *
     * @return list<array{key: ?string, name: ?string, description: string, fields: list<array<string, mixed>>}>
     */
    public function sectionsFor(string $siteId, array $context): array
    {
        $sections = [];
        $own = isset($context['kind']) ? $this->ownFields($siteId, $context['kind']) : [];
        if ($own !== []) {
            $sections[] = ['key' => null, 'name' => null, 'description' => '', 'fields' => $own];
        }
        foreach ($this->groupsFor($siteId, $context) as $group) {
            if ($group['fields'] === []) {
                continue;
            }
            $sections[] = ['key' => $group['key'], 'name' => $group['name'], 'description' => $group['description'], 'fields' => $group['fields']];
        }

        return $sections;
    }

    /** The fields a content type declares directly, without any group. */
    private function ownFields(string $siteId, string $kind): array
    {
        $types = $this->types->forSite($siteId);

        return $types->exists($kind) ? $types->fields($kind) : [];
    }
}
