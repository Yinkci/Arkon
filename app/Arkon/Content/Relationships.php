<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * References from one entry to others: a property's agent, an article's related services, a
 * course's instructor.
 *
 * A reference is a typed pointer, not a copy. The entry's own values hold `{"id": "…"}` and
 * nothing else about the target, so renaming an agent renames it everywhere at once and no
 * screen can show a stale copy. What the target is called is read when something needs to show
 * it (summaries()), and the values of the entry it points at are read when a page binds through
 * it (expand()).
 *
 * Every reference is also projected into content_field_index with ref_id set, which is what
 * makes "properties whose agent is Jane" and "what uses this entry" ordinary indexed queries
 * rather than a scan over JSON.
 *
 * That index is also why a relationship is kept in one direction only. "Agent → Properties" is
 * the same reference read the other way round (usedBy(), and the ref filter in ContentReader),
 * so a reverse view can be offered later without a second copy of the relationship to keep in
 * step with the first.
 */
final class Relationships
{
    /**
     * How far a binding or a serialization follows references: one step.
     *
     * A page may show its property's agent's name. It may not show the agent's agency's owner's
     * phone: cycles in a content graph are legitimate and following them is not, so the limit is
     * on the traversal rather than on what authors may describe.
     */
    public const DEPTH = 1;

    public function __construct(
        private readonly FieldSchemaResolver $schema,
        private readonly ContentTypeRegistry $types,
    ) {}

    /**
     * Every relationship field in a set of fields, with the ids its value holds.
     *
     * Fields inside a group or a repeater row count too: a reference is a reference wherever it
     * was declared, and integrity is not something a nesting level should be able to escape.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return list<array{key: string, label: string, target: ?string, ids: list<string>}>
     */
    public static function refsIn(array $fields, array $values, string $where = ''): array
    {
        $out = [];
        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? null;
            $label = ($where === '' ? '' : $where.': ').$field['label'];
            if ($field['type'] === 'relationship') {
                $out[] = [
                    'key' => $field['key'],
                    'label' => $label,
                    'target' => $field['settings']['targetType'] ?? null,
                    'ids' => self::ids($value),
                ];

                continue;
            }
            if (! is_array($value)) {
                continue;
            }
            // A content section holds the fields of its own layout, so each block is read
            // against the layout it names.
            if ($field['type'] === 'flexible') {
                foreach (array_values(array_filter($value, 'is_array')) as $index => $block) {
                    $fields = Flexible::layoutFields($field, (string) ($block['_layout'] ?? ''));
                    foreach (self::refsIn($fields, $block, $field['label'].' section '.($index + 1)) as $nested) {
                        $out[] = $nested;
                    }
                }

                continue;
            }
            if (($field['fields'] ?? []) === []) {
                continue;
            }
            // A group holds one set of values; a repeater holds a list of them.
            $rows = $field['type'] === 'repeater' ? array_values(array_filter($value, 'is_array')) : [$value];
            foreach ($rows as $index => $row) {
                $inside = $field['type'] === 'repeater' ? $field['label'].' row '.($index + 1) : $field['label'];
                foreach (self::refsIn($field['fields'], $row, $inside) as $nested) {
                    $out[] = $nested;
                }
            }
        }

        return $out;
    }

    /**
     * The ids one relationship value holds, whether it keeps one reference or several.
     *
     * @return list<string>
     */
    public static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $list = array_is_list($value) ? $value : [$value];
        $ids = [];
        foreach ($list as $one) {
            $id = is_array($one) ? ($one['id'] ?? null) : null;
            if (is_string($id) && Uuid::isValid($id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Refuses a field definition whose relationship does not say what it relates to, or names a
     * content type this site does not have.
     *
     * A relationship with no target would accept anything and mean nothing, so it is settled
     * when the field is defined rather than when somebody tries to use it. A type may relate to
     * itself — a service's related services — which is why the key being defined counts as known
     * even before it exists.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    public function assertTargets(string $siteId, array $fields, ?string $allowKey = null): void
    {
        $types = $this->types->forSite($siteId);
        foreach ($fields as $field) {
            foreach ($field['fields'] ?? [] as $child) {
                $this->assertTargets($siteId, [$child], $allowKey);
            }
            if ($field['type'] !== 'relationship') {
                continue;
            }
            $target = $field['settings']['targetType'] ?? null;
            if (! is_string($target) || $target === '') {
                throw FieldSchema::problem($field['label'], $field['key'], 'settings', 'no_target', $field['label'].': choose the kind of content it relates to.');
            }
            if ($target !== $allowKey && ! $types->exists($target)) {
                throw FieldSchema::problem($field['label'], $field['key'], 'settings', 'unknown_target', $field['label'].": \"{$target}\" is not a kind of content this site has.");
            }
        }
    }

    /**
     * Refuses values whose references do not point at something real: a target that does not
     * exist on this site, or one of another content type than the field asked for.
     *
     * A target in the Trash is accepted, because trashing is reversible and losing every
     * reference to an entry somebody trashed by mistake would be worse than showing nothing for
     * it until it comes back. What a trashed target does to a page is decided when the page
     * renders, not here.
     *
     * One query however many references the entry holds.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $values
     */
    public function assertValid(string $siteId, array $definition, array $values): void
    {
        $refs = self::refsIn($definition['fields'] ?? [], $values);
        $ids = array_values(array_unique(array_merge([], ...array_column($refs, 'ids'))));
        if ($ids === []) {
            return;
        }
        $kinds = DB::table('pages')->where('site_id', $siteId)->whereIn('id', $ids)->whereNull('purged_at')
            ->pluck('kind', 'id')->all();
        $issues = [];
        foreach ($refs as $ref) {
            foreach ($ref['ids'] as $id) {
                $kind = $kinds[$id] ?? null;
                if ($kind === null) {
                    $issues[] = ['path' => 'fields.'.$ref['key'], 'fieldKey' => $ref['key'], 'message' => $ref['label'].': that item no longer exists.'];
                } elseif ($ref['target'] !== null && $kind !== $ref['target']) {
                    $issues[] = ['path' => 'fields.'.$ref['key'], 'fieldKey' => $ref['key'], 'message' => $ref['label'].' takes '.$this->label($siteId, $ref['target']).', and that item is not one.'];
                }
            }
        }
        if ($issues !== []) {
            throw new ValidationException(implode(' ', array_column($issues, 'message')), $issues);
        }
    }

    /**
     * What each of these entries is called, for a chip in the editor, a picker or the API.
     *
     * Deliberately shallow: an entry's own field values are not included, so serializing a
     * property's agent cannot pull in the agent's own references and the one after that.
     *
     * @param  list<string>  $ids
     * @return array<string, array{id: string, type: string, typeLabel: string, title: string, path: string, status: string}>
     */
    public function summaries(string $siteId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, Uuid::isValid(...))));
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('pages as pg')
            ->leftJoin('live_pages as l', 'l.page_id', '=', 'pg.id')
            ->where('pg.site_id', $siteId)->whereIn('pg.id', $ids)->whereNull('pg.purged_at')
            ->get(['pg.id', 'pg.kind', 'pg.title', 'pg.path', 'pg.deleted_at', 'l.path as live_path', 'l.page_id as live_id']);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->id] = [
                'id' => $row->id,
                'type' => $row->kind,
                'typeLabel' => $this->label($siteId, $row->kind),
                'title' => $row->title,
                'path' => $row->live_path ?? $row->path,
                'status' => match (true) {
                    $row->deleted_at !== null => 'trash',
                    $row->live_id === null => 'draft',
                    default => 'published',
                },
            ];
        }

        return $out;
    }

    /**
     * The same values with every relationship replaced by what its targets are, for anything
     * that shows or serializes an entry. A single relationship becomes one object (or nothing at
     * all), a multiple one a list in its stored order.
     *
     * `$public` is what a visitor may see: a target in the Trash, or one that has gone, is left
     * out, so a published page never shows an entry somebody withdrew. A target that is only a
     * draft does resolve — an author who points at it has chosen it, and a Team Member usually
     * has no page of its own to publish. The admin passes false and gets every reference with
     * its status, which is how it can say "Trashed".
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function resolve(string $siteId, array $definition, array $values, bool $public = false): array
    {
        $fields = $definition['fields'] ?? [];
        $refs = self::refsIn($fields, $values);
        if ($refs === []) {
            return $values;
        }
        $summaries = $this->summaries($siteId, array_merge([], ...array_column($refs, 'ids')));
        if ($public) {
            $summaries = array_filter($summaries, fn ($one) => $one['status'] !== 'trash');
        }

        return self::replace($fields, $values, $summaries);
    }

    /**
     * Relationship values swapped for their summaries, wherever they are declared.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<string, array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    private static function replace(array $fields, array $values, array $summaries): array
    {
        foreach ($fields as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $values)) {
                continue;
            }
            if ($field['type'] === 'relationship') {
                $found = array_values(array_filter(array_map(fn ($id) => $summaries[$id] ?? null, self::ids($values[$key]))));
                $values[$key] = ($field['settings']['multiple'] ?? false) ? $found : ($found[0] ?? null);
                if ($values[$key] === null || $values[$key] === []) {
                    unset($values[$key]);
                }

                continue;
            }
            if (! is_array($values[$key])) {
                continue;
            }
            if ($field['type'] === 'flexible') {
                $values[$key] = array_map(
                    fn ($block) => is_array($block)
                        ? self::replace(Flexible::layoutFields($field, (string) ($block['_layout'] ?? '')), $block, $summaries)
                        : $block,
                    $values[$key],
                );

                continue;
            }
            if (($field['fields'] ?? []) === []) {
                continue;
            }
            $values[$key] = $field['type'] === 'repeater'
                ? array_map(fn ($row) => is_array($row) ? self::replace($field['fields'], $row, $summaries) : $row, $values[$key])
                : self::replace($field['fields'], $values[$key], $summaries);
        }

        return $values;
    }

    /**
     * What a page binding through a relationship can read: the target's own values, one step
     * only (self::DEPTH).
     *
     * The target's relationships are not expanded in turn, so a cycle — an agent whose featured
     * property is this one — resolves and stops instead of running away.
     *
     * Trashed and deleted targets are left out, exactly as resolve() leaves them out, so the
     * page falls back to what the block already held.
     *
     * @param  list<array<string, mixed>>  $fields  the fields the entry carries
     * @param  array<string, mixed>  $values  the entry's own values
     * @return array<string, mixed> the values, with single relationships expanded in place
     */
    public function expand(string $siteId, array $fields, array $values, bool $live = false): array
    {
        $single = [];
        foreach ($fields as $field) {
            if ($field['type'] !== 'relationship' || ($field['settings']['multiple'] ?? false)) {
                continue;
            }
            $id = self::ids($values[$field['key']] ?? null)[0] ?? null;
            if ($id !== null) {
                $single[$field['key']] = $id;
            }
        }
        if ($single === []) {
            return $values;
        }
        $targets = $this->valuesOf($siteId, array_values(array_unique($single)), $live);
        foreach ($single as $key => $id) {
            if (isset($targets[$id])) {
                $values[$key] = $targets[$id];
            } else {
                // The target is in the Trash or gone: binding through it resolves to nothing,
                // which leaves the bound property showing the fallback it already had.
                unset($values[$key]);
            }
        }

        return $values;
    }

    /**
     * The draft values of the given entries, with their own references left as references and
     * private fields left out: an entry's private field is not for a public page, and following
     * a relationship must not be a way around that.
     *
     * Trashed and purged targets are left out here. `$live` is what a published page reads: a
     * target that is live gives the values it went live with, not its unpublished edits.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function valuesOf(string $siteId, array $ids, bool $live = false): array
    {
        $rows = DB::table('page_drafts as d')
            ->join('pages as pg', 'pg.id', '=', 'd.page_id')
            ->where('d.site_id', $siteId)->whereIn('d.page_id', $ids)
            ->whereNull('pg.purged_at')->whereNull('pg.deleted_at')
            ->get(['d.page_id', 'd.fields', 'pg.kind', 'pg.title', 'pg.path']);
        $liveValues = $live ? app(EntryFields::class)->liveValues($siteId, $ids) : [];
        $contexts = $this->schema->contextsForEntries($siteId, $ids);
        $out = [];
        foreach ($rows as $row) {
            $values = $liveValues[$row->page_id] ?? Json::toArray(Json::decode($row->fields ?? '{}'));
            $fields = $this->schema->fieldsFor($siteId, $contexts[$row->page_id] ?? ['kind' => $row->kind]);
            $public = array_values(array_filter($fields, fn ($f) => ! ($f['settings']['private'] ?? false)));
            $out[$row->page_id] = [
                // The target's title comes along, because "Agent → Name" is most often the
                // entry's own title rather than a field somebody had to add for it.
                'title' => $row->title,
                'path' => $row->path,
                ...FieldValues::prune($public, is_array($values) ? $values : []),
            ];
        }

        return $out;
    }

    /**
     * What refers to this entry, counted by the field that does it: "Used by 14 Properties as
     * Agent" before somebody deletes it.
     *
     * One indexed query over content_field_index (ref_id), so this is cheap enough to show on a
     * delete confirmation.
     *
     * @return list<array{kind: string, label: string, fieldKey: string, fieldLabel: string, count: int}>
     */
    public function usedBy(string $siteId, string $pageId): array
    {
        return $this->usedByAny($siteId, [$pageId]);
    }

    /**
     * The same for several entries at once, which is what a confirmation for a bulk delete needs:
     * one query, and an entry that refers to another in the same selection is not counted.
     *
     * @param  list<string>  $pageIds
     * @return list<array{kind: string, label: string, singular: string, fieldKey: string, fieldLabel: string, count: int}>
     */
    public function usedByAny(string $siteId, array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter($pageIds, Uuid::isValid(...))));
        if ($pageIds === []) {
            return [];
        }
        $rows = DB::table('content_field_index as i')
            ->join('pages as pg', 'pg.id', '=', 'i.page_id')
            ->where('i.site_id', $siteId)->whereIn('i.ref_id', $pageIds)
            ->whereNull('pg.purged_at')->whereNull('pg.deleted_at')
            ->whereNotIn('pg.id', $pageIds)
            ->groupBy('pg.kind', 'i.field_key')
            ->orderBy('pg.kind')->orderBy('i.field_key')
            ->get(['pg.kind', 'i.field_key', DB::raw('count(distinct i.page_id) as uses')]);
        $out = [];
        foreach ($rows as $row) {
            $fields = array_column($this->schema->fieldsFor($siteId, ['kind' => $row->kind]), null, 'key');
            $field = $fields[$row->field_key] ?? null;
            // A media field also keeps a ref_id; only a relationship is a dependency on an entry,
            // directly or inside a group, repeater or section (recorded under the outer field).
            if (! in_array($field['type'] ?? null, ['relationship', 'group', 'repeater', 'flexible'], true)) {
                continue;
            }
            $type = $this->types->forSite($siteId)->all()[$row->kind] ?? [];
            $out[] = [
                'kind' => $row->kind,
                'label' => $type['plural'] ?? $row->kind,
                'singular' => $type['label'] ?? $row->kind,
                'fieldKey' => $row->field_key,
                'fieldLabel' => $field['label'],
                'count' => (int) $row->uses,
            ];
        }

        return $out;
    }

    /** "Used by 14 Properties as Agent." — one sentence per kind and field, for a person. */
    public static function describeUses(array $uses): array
    {
        return array_map(
            fn ($use) => 'Used by '.$use['count'].' '.($use['count'] === 1 ? ($use['singular'] ?? $use['label']) : $use['label']).' as '.$use['fieldLabel'].'.',
            $uses,
        );
    }

    /**
     * Takes this entry out of every reference to it, after it has been deleted permanently.
     *
     * A reference to something that no longer exists is the one broken state worth preventing
     * eagerly: the entry cannot come back, so nothing is lost by tidying, and leaving it would
     * mean every reader had to cope with an id that resolves to nothing forever. Revisions are
     * append-only and keep what they recorded, as they do for everything else.
     *
     * Drafts are taken in id order, so two deletions running at once cannot wait on each other.
     *
     * @return list<string> the entries that were changed
     */
    public function forget(string $siteId, string $pageId): array
    {
        if (! Uuid::isValid($pageId)) {
            return [];
        }
        $pageIds = DB::table('content_field_index')->where('site_id', $siteId)->where('ref_id', $pageId)
            ->where('page_id', '!=', $pageId)->distinct()->orderBy('page_id')->pluck('page_id')->all();
        $changed = [];
        foreach ($pageIds as $id) {
            $draft = DB::table('page_drafts')->where('site_id', $siteId)->where('page_id', $id)->lockForUpdate()->first();
            if ($draft === null) {
                continue;
            }
            $values = Json::toArray(Json::decode($draft->fields ?? '{}'));
            $definition = $this->schema->definitionFor($siteId, $this->schema->contextForEntry($siteId, $id));
            if ($definition === null || ! is_array($values)) {
                continue;
            }
            $without = self::without($definition['fields'], $values, $pageId);
            if ($without === $values) {
                continue;
            }
            DB::table('page_drafts')->where('page_id', $id)->update(['fields' => Json::encode((object) $without)]);
            app(EntryFields::class)->reindexPage($siteId, $id);
            $changed[] = $id;
        }

        return $changed;
    }

    /**
     * The same values without one id, wherever a relationship holds it.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function without(array $fields, array $values, string $id): array
    {
        foreach ($fields as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $values)) {
                continue;
            }
            if ($field['type'] === 'relationship') {
                $kept = array_values(array_filter(self::ids($values[$key]), fn ($one) => $one !== $id));
                if ($kept === []) {
                    unset($values[$key]);
                } else {
                    $value = array_map(fn ($one) => ['id' => $one], $kept);
                    $values[$key] = ($field['settings']['multiple'] ?? false) ? $value : $value[0];
                }

                continue;
            }
            if (! is_array($values[$key])) {
                continue;
            }
            if ($field['type'] === 'flexible') {
                $values[$key] = array_map(
                    fn ($block) => is_array($block)
                        ? self::without(Flexible::layoutFields($field, (string) ($block['_layout'] ?? '')), $block, $id)
                        : $block,
                    $values[$key],
                );

                continue;
            }
            if (($field['fields'] ?? []) === []) {
                continue;
            }
            $values[$key] = $field['type'] === 'repeater'
                ? array_map(fn ($row) => is_array($row) ? self::without($field['fields'], $row, $id) : $row, $values[$key])
                : self::without($field['fields'], $values[$key], $id);
        }

        return $values;
    }

    /**
     * Entries of one kind to choose from, searched in the database rather than in the browser: a
     * site with ten thousand services must not send ten thousand options to a dropdown.
     *
     * @return list<array{id: string, type: string, typeLabel: string, title: string, path: string, status: string}>
     */
    public function search(string $siteId, string $kind, string $term = '', int $limit = 20, array $except = []): array
    {
        $q = DB::table('pages as pg')
            ->leftJoin('live_pages as l', 'l.page_id', '=', 'pg.id')
            ->where('pg.site_id', $siteId)->where('pg.kind', $kind)
            ->whereNull('pg.purged_at')->whereNull('pg.deleted_at');
        if (trim($term) !== '') {
            $like = '%'.addcslashes(trim($term), '\\%_').'%';
            $q->where(fn ($w) => $w->where('pg.title', 'ilike', $like)->orWhere('pg.path', 'ilike', $like));
        }
        $except = array_values(array_filter($except, Uuid::isValid(...)));
        if ($except !== []) {
            $q->whereNotIn('pg.id', $except);
        }
        $rows = $q->orderBy('pg.title')->orderBy('pg.id')->limit(max(1, min($limit, 50)))
            ->get(['pg.id', 'pg.kind', 'pg.title', 'pg.path', 'l.path as live_path', 'l.page_id as live_id']);

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'type' => $row->kind,
            'typeLabel' => $this->label($siteId, $row->kind),
            'title' => $row->title,
            'path' => $row->live_path ?? $row->path,
            'status' => $row->live_id === null ? 'draft' : 'published',
        ])->all();
    }

    /** What one content type is called, for a message a person reads. */
    private function label(string $siteId, string $kind): string
    {
        return $this->types->forSite($siteId)->all()[$kind]['label'] ?? $kind;
    }
}
