<?php

namespace App\Arkon\Content;

use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Content lists: turning a block's query into entries, once per render.
 *
 * A content list stores one item template and a query — never copies of the entries. This is
 * the only place a query written in the Builder becomes a database query, and it never trusts
 * what the query says: the source must be a content type this site has, every filter and sort
 * must name a field that type declares, and the operator must suit that field's type. Anything
 * else is reported as a problem with the block and the list renders its empty state, because a
 * filter that is quietly dropped shows more than the author asked for.
 *
 * What comes back is, for each block, the entries' own binding contexts — the same shape a page
 * binds against — so the item template is rendered by the ordinary renderer with "the current
 * item" in place of "this entry", and the canvas, the preview and the published page all show
 * the same thing.
 */
final class Collections
{
    /** Operators each kind of indexed value can be filtered with. */
    private const OPERATORS = [
        'text' => ['is', 'is_not', 'contains'],
        'number' => ['is', 'is_not', 'gt', 'lt', 'between'],
        'boolean' => ['is_true', 'is_false'],
        'date' => ['is', 'before', 'after', 'between'],
        'ref' => ['is', 'is_not'],
    ];

    /**
     * What one field can be filtered by. A chosen value is either the one or not, so "contains"
     * is not offered for it; a picture is not something to filter a list by at all.
     *
     * @return list<string>
     */
    private static function operatorsFor(array $field): array
    {
        if (in_array($field['type'], ['media', 'gallery'], true)) {
            return [];
        }
        if (in_array($field['type'], ['select', 'multi_select', 'color', 'time'], true)) {
            return ['is', 'is_not'];
        }

        return self::OPERATORS[FieldTypes::index($field['type'])] ?? [];
    }

    /** Whether a list can be put in order by this field: one scalar value, comparable. */
    private static function sortableBy(array $field): bool
    {
        return ! in_array($field['type'], ['media', 'gallery', 'multi_select'], true)
            && in_array(FieldTypes::index($field['type']), ['text', 'number', 'date'], true);
    }

    /** Which column of content_field_index holds a field of each indexed kind. */
    private const COLUMNS = ['text' => 'text_value', 'number' => 'num_value', 'boolean' => 'bool_value', 'date' => 'date_value', 'ref' => 'ref_id'];

    /** How many entries one page may show in total, however many lists it holds. */
    public const MAX_ITEMS_PER_PAGE = 96;

    public function __construct(
        private readonly ContentReader $reader,
        private readonly ContentTypeRegistry $types,
        private readonly FieldSchemaResolver $schema,
        private readonly EntryFields $fields,
        private readonly Relationships $relationships,
        private readonly SiteDataService $site,
    ) {}

    /**
     * The content lists in a document, as node id => the query it holds.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function inDocument(mixed $document): array
    {
        $out = [];
        foreach (Json::entries(is_array($document) ? ($document['nodes'] ?? []) : []) as $id => $node) {
            if (($node['type'] ?? null) === 'collection') {
                $out[(string) $id] = Json::toArray(Json::entries($node['props'] ?? [])['query'] ?? []);
            }
        }

        return $out;
    }

    /**
     * Every content list in a document, resolved: the entries to render, what is wrong with the
     * query if anything, and the media its items will need.
     *
     * One query for the entries of each list, then two reads for all their values however many
     * entries there are — never one per item.
     *
     * @param  ?string  $pageId  the entry being rendered, for a list of what it relates to
     * @return array<string, array{items: list<array<string, mixed>>, total: int, issues: list<string>, sample: bool, mediaIds: list<string>}>
     */
    public function resolve(string $siteId, mixed $document, ?string $pageId = null, bool $editor = false): array
    {
        $lists = self::inDocument($document);
        if ($lists === []) {
            return [];
        }
        $siteValues = $this->site->publicValues($siteId);
        $budget = self::MAX_ITEMS_PER_PAGE;
        $out = [];
        foreach ($lists as $nodeId => $query) {
            $resolved = $this->one($siteId, $query, $pageId, $editor, $budget, $siteValues);
            $budget -= count($resolved['items']);
            $out[$nodeId] = [...$resolved, 'mediaIds' => self::mediaIn($document, $nodeId, $resolved['items'])];
        }

        return $out;
    }

    /**
     * The media the items of one list will ask for: what the bindings inside that list resolve
     * to, for each entry. The renderer builds its picture of the library from what a document
     * names, and these pictures are named by the entries rather than by the blocks.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private static function mediaIn(mixed $document, string $nodeId, array $items): array
    {
        $inside = self::subtree($document, $nodeId);
        $ids = [];
        foreach (Bindings::inDocument($document) as $binding) {
            if (! in_array($binding['nodeId'], $inside, true)) {
                continue;
            }
            foreach ($items as $item) {
                $value = Bindings::value($binding, $item);
                if (is_array($value) && is_string($value['id'] ?? null)) {
                    $ids[] = $value['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The nodes of one list's item template.
     *
     * @return list<string>
     */
    private static function subtree(mixed $document, string $nodeId): array
    {
        $nodes = Json::entries(is_array($document) ? ($document['nodes'] ?? []) : []);
        $out = [];
        $walk = function (string $id) use (&$walk, &$out, $nodes): void {
            if (in_array($id, $out, true) || ! isset($nodes[$id])) {
                return;
            }
            $out[] = $id;
            foreach ($nodes[$id]['children'] ?? [] as $child) {
                $walk((string) $child);
            }
        };
        $walk($nodeId);

        return $out;
    }

    /**
     * What a content list can be pointed at, as the editor needs it: the kinds of content this
     * site has, which of their fields can be filtered or sorted on and how, and the
     * relationships the entry being edited could follow.
     *
     * Built from the same schema the query is checked against, so the editor cannot offer a
     * filter the server would refuse.
     *
     * @return array{types: list<array<string, mixed>>, related: list<array{key: string, label: string, targetLabel: string}>}
     */
    public function sources(string $siteId, ?string $pageId = null): array
    {
        $types = [];
        foreach ($this->types->forSite($siteId)->all() as $kind => $type) {
            $fields = [];
            foreach ($this->schema->fieldsFor($siteId, ['kind' => $kind]) as $field) {
                if ($field['settings']['private'] ?? false) {
                    continue;
                }
                $operators = self::operatorsFor($field);
                if ($operators === [] && ! self::sortableBy($field)) {
                    continue;
                }
                $fields[] = [
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'operators' => $operators,
                    'sortable' => self::sortableBy($field),
                    'choices' => array_values(array_map(
                        fn ($choice) => ['value' => $choice['value'], 'label' => $choice['label']],
                        $field['choices'] ?? [],
                    )),
                ];
            }
            $types[] = [
                'key' => $kind,
                'label' => $type['label'],
                'plural' => $type['plural'],
                // What a block inside the item template can bind to: the entry's own title and
                // URL, then its fields, offered exactly as the page's own fields are.
                'bindable' => BindingSources::offer([
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'settings' => []],
                    ['key' => 'url', 'label' => 'Link to it', 'type' => 'url', 'settings' => []],
                    ...($type['details'] ?? false ? [['key' => 'excerpt', 'label' => 'Excerpt', 'type' => 'textarea', 'settings' => []]] : []),
                    ...$this->bindableFields($siteId, $kind),
                ]),
                'taxonomies' => array_values(array_map(
                    fn ($taxonomy) => ['key' => $taxonomy, 'label' => Taxonomies::get($taxonomy)['label']],
                    $type['taxonomies'] ?? [],
                )),
                'fields' => $fields,
            ];
        }
        $related = [];
        foreach ($pageId === null ? [] : $this->schema->fieldsFor($siteId, $this->schema->contextForEntry($siteId, $pageId)) as $field) {
            if ($field['type'] !== 'relationship') {
                continue;
            }
            $target = (string) ($field['settings']['targetType'] ?? '');
            $related[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'targetLabel' => $this->types->forSite($siteId)->exists($target) ? $this->types->forSite($siteId)->all()[$target]['plural'] : $target,
            ];
        }

        return ['types' => $types, 'related' => $related];
    }

    /**
     * The pages whose content lists depend on one of these fields, by name: before a field is
     * removed, somebody should know that a list filters or orders by it (§26). A list that
     * loses its field keeps working — it reports the problem and shows its empty state — but
     * that is a repair, and this is the warning that avoids it.
     *
     * The candidates are narrowed in the database by the field key appearing anywhere in the
     * draft, then each candidate's lists are read properly, so a key that only appears as a
     * binding is not mistaken for a query.
     *
     * @param  list<string>  $fieldKeys
     * @return list<string> page titles, at most ten
     */
    public function pagesUsingFields(string $siteId, string $kind, array $fieldKeys): array
    {
        $fieldKeys = array_values(array_filter($fieldKeys, 'is_string'));
        if ($fieldKeys === []) {
            return [];
        }
        $rows = DB::table('page_drafts as d')->join('pages as p', 'p.id', '=', 'd.page_id')
            ->where('d.site_id', $siteId)->whereNull('p.deleted_at')
            ->whereRaw("strpos(d.document::text, '\"collection\"') > 0")
            ->limit(200)->get(['p.title', 'd.document']);
        $titles = [];
        foreach ($rows as $row) {
            foreach (self::inDocument(Json::toArray(Json::decode($row->document))) as $query) {
                $source = is_string($query['source'] ?? null) ? $query['source'] : '';
                if ($source !== $kind && ! str_starts_with($source, 'related:')) {
                    continue;
                }
                $used = [
                    ...array_column(Json::toArray($query['filters'] ?? []) ?: [], 'field'),
                    ...(is_string($query['sort'] ?? null) && str_contains($query['sort'], 'field:')
                        ? [substr($query['sort'], strpos($query['sort'], 'field:') + 6)]
                        : []),
                ];
                if (array_intersect($fieldKeys, $used) !== []) {
                    $titles[$row->title] = true;
                }
            }
        }

        return array_slice(array_keys($titles), 0, 10);
    }

    /**
     * The fields of one kind, with each single relationship presented as the fields of what it
     * relates to — the same one-step traversal a page gets (Relationships::DEPTH).
     *
     * @return list<array<string, mixed>>
     */
    private function bindableFields(string $siteId, string $kind): array
    {
        $out = [];
        foreach ($this->schema->fieldsFor($siteId, ['kind' => $kind]) as $field) {
            if ($field['type'] !== 'relationship' || ($field['settings']['multiple'] ?? false)) {
                $out[] = $field;

                continue;
            }
            $target = (string) ($field['settings']['targetType'] ?? '');
            if (! $this->types->forSite($siteId)->exists($target)) {
                continue;
            }
            $inside = [['key' => 'title', 'label' => 'Title', 'type' => 'text', 'settings' => []]];
            foreach ($this->schema->fieldsFor($siteId, ['kind' => $target]) as $child) {
                if (! ($child['settings']['private'] ?? false) && $child['type'] !== 'relationship') {
                    $inside[] = $child;
                }
            }
            $out[] = [...$field, 'fields' => $inside];
        }

        return $out;
    }

    /** One list: what it asks for, checked against the schema, then read. */
    private function one(string $siteId, array $query, ?string $pageId, bool $editor, int $budget, array $siteValues): array
    {
        $empty = ['items' => [], 'total' => 0, 'issues' => [], 'sample' => false];
        $source = is_string($query['source'] ?? null) ? $query['source'] : '';
        if ($source === '') {
            return $empty;
        }
        [$kind, $chosen, $issue] = $this->source($siteId, $source, $pageId);
        if ($issue !== null) {
            return [...$empty, 'issues' => [$issue]];
        }
        $fields = array_column($this->schema->fieldsFor($siteId, ['kind' => $kind]), null, 'key');
        [$filters, $terms, $refs, $issues] = $this->filters($query['filters'] ?? [], $fields);
        [$sort, $fieldSort, $sortIssue] = $this->sort($query['sort'] ?? '-published_at', $fields, $chosen !== null);
        $issues = [...$issues, ...($sortIssue === null ? [] : [$sortIssue])];
        if ($issues !== []) {
            return [...$empty, 'issues' => $issues];
        }
        $limit = max(1, min((int) ($query['limit'] ?? 6), max(1, $budget)));
        if ($chosen === []) {
            return $editor ? $this->withSample($siteId, $kind, $siteValues, $empty) : $empty;
        }

        $filter = array_filter([
            'status' => 'published',
            'ids' => $chosen,
            'terms' => $terms ?: null,
            'refs' => $refs ?: null,
            'fields' => $filters ?: null,
            'fieldSort' => $fieldSort,
        ], fn ($value) => $value !== null);
        // Visitors see published entries, so the canvas and the preview do too: a list that
        // looks full in the editor and empty to a visitor would be a worse surprise than an
        // empty one here.
        // A chosen order is applied after reading, so the whole chosen set is read and then cut
        // to the limit: taking the first six alphabetically and then reordering them would not
        // be the first six somebody chose.
        $read = $chosen === null ? $limit : min(count($chosen), self::MAX_ITEMS_PER_PAGE);
        $result = $this->reader->page($this->reader->query($siteId, $kind, 'live', $filter), $sort, 1, $read);
        $rows = $result['rows'];
        if ($chosen !== null) {
            $order = array_flip($chosen);
            usort($rows, fn ($a, $b) => ($order[$a['id']] ?? PHP_INT_MAX) <=> ($order[$b['id']] ?? PHP_INT_MAX));
            $rows = array_slice($rows, 0, $limit);
        }
        $items = $this->items($siteId, $rows, $siteValues);

        return $items === [] && $editor
            ? $this->withSample($siteId, $kind, $siteValues, [...$empty, 'total' => $result['total']])
            : ['items' => $items, 'total' => $result['total'], 'issues' => [], 'sample' => false];
    }

    /**
     * What the editor shows when a list has nothing to show yet: one entry of that kind, draft
     * or not, so the item template can be designed before anything is published. Marked as a
     * sample, and never used outside the editor — a visitor gets the empty state instead.
     */
    private function withSample(string $siteId, string $kind, array $siteValues, array $empty): array
    {
        $result = $this->reader->page($this->reader->query($siteId, $kind, 'draft', ['status' => 'any']), 'title', 1, 1);
        $items = $this->items($siteId, $result['rows'], $siteValues);

        return [...$empty, 'items' => $items, 'sample' => $items !== []];
    }

    /**
     * What a list reads from: a content type, or what the entry being rendered relates to.
     *
     * @return array{0: string, 1: ?list<string>, 2: ?string} [kind, chosen ids or null, problem]
     */
    private function source(string $siteId, string $source, ?string $pageId): array
    {
        if (! str_starts_with($source, 'related:')) {
            return $this->types->forSite($siteId)->exists($source)
                ? [$source, null, null]
                : ['', null, 'This content list shows a kind of content this site no longer has.'];
        }
        $key = substr($source, strlen('related:'));
        $fields = $pageId === null ? [] : array_column($this->schema->fieldsFor($siteId, $this->schema->contextForEntry($siteId, $pageId)), null, 'key');
        $field = $fields[$key] ?? null;
        if (($field['type'] ?? null) !== 'relationship') {
            return ['', null, 'This content list follows a relationship this entry no longer has.'];
        }
        $values = $pageId === null ? [] : ($this->fields->drafts($siteId, [$pageId])[$pageId] ?? []);
        $target = (string) ($field['settings']['targetType'] ?? '');
        if (! $this->types->forSite($siteId)->exists($target)) {
            return ['', null, 'This content list follows a relationship to a kind of content this site no longer has.'];
        }

        return [$target, Relationships::ids($values[$key] ?? null), null];
    }

    /**
     * The filters, split by what answers them: taxonomy terms, references, or the entries' own
     * values. A filter naming a field the type does not have, or an operator the field cannot
     * answer, is a problem with the block rather than something to ignore.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>, 2: array<string, list<string>>, 3: list<string>}
     */
    private function filters(mixed $filters, array $fields): array
    {
        $own = [];
        $terms = [];
        $refs = [];
        $issues = [];
        foreach (Json::toArray($filters) ?: [] as $filter) {
            if (! is_array($filter) || ($filter['field'] ?? '') === '') {
                continue;
            }
            $key = (string) $filter['field'];
            $operator = (string) ($filter['operator'] ?? 'is');
            $value = (string) ($filter['value'] ?? '');
            if ($operator === 'has_term') {
                if (! Uuid::isValid($value)) {
                    $issues[] = 'This content list filters by a category that no longer exists.';

                    continue;
                }
                $terms[$key][] = $value;

                continue;
            }
            $field = $fields[$key] ?? null;
            if ($field === null) {
                $issues[] = 'This content list filters by “'.$key.'”, which is not a field this content has any more.';

                continue;
            }
            $indexed = FieldTypes::index($field['type']);
            if (! in_array($operator, self::operatorsFor($field), true)) {
                $issues[] = 'This content list filters “'.$field['label'].'” in a way that kind of field cannot answer.';

                continue;
            }
            if ($indexed === 'ref') {
                Uuid::isValid($value) ? $refs[$key][] = $value : $issues[] = 'This content list filters “'.$field['label'].'” by an item that no longer exists.';

                continue;
            }
            $own[] = [
                'field' => $key,
                'column' => self::COLUMNS[$indexed],
                'operator' => $operator,
                'value' => match ($operator) {
                    'is_true' => true,
                    'is_false' => false,
                    default => $indexed === 'number' ? (float) $value : $value,
                },
                'value2' => $indexed === 'number' ? (float) ($filter['value2'] ?? 0) : (string) ($filter['value2'] ?? ''),
                // A toggle is matched on its own column, so "is_true" is an equality test.
                ...in_array($operator, ['is_true', 'is_false'], true) ? ['operator' => 'is'] : [],
            ];
        }

        return [$own, $terms, $refs, $issues];
    }

    /**
     * What to order by. The usual four are the reader's own; anything else must be one of the
     * type's own scalar fields, which the reader joins once for the purpose.
     *
     * @return array{0: string, 1: ?array{field: string, column: string}, 2: ?string}
     */
    private function sort(mixed $sort, array $fields, bool $related): array
    {
        $sort = is_string($sort) ? $sort : '-published_at';
        $descending = str_starts_with($sort, '-');
        $name = ltrim($sort, '-');
        if ($name === 'chosen') {
            // The order somebody put them in. Only a relationship has one; everything else
            // falls back to the newest first.
            return [$related ? 'title' : '-published_at', null, null];
        }
        if (in_array($name, ContentReader::SORTS, true)) {
            return [$sort, null, null];
        }
        if (! str_starts_with($name, 'field:')) {
            return ['-published_at', null, 'This content list is sorted by something Arkon does not know.'];
        }
        $key = substr($name, strlen('field:'));
        $field = $fields[$key] ?? null;
        if ($field === null) {
            return ['-published_at', null, 'This content list is sorted by “'.$key.'”, which is not a field this content has any more.'];
        }
        if (! self::sortableBy($field)) {
            return ['-published_at', null, 'This content list is sorted by “'.$field['label'].'”, which is not something that can be put in order.'];
        }

        return [($descending ? '-' : '').'field', ['field' => $key, 'column' => self::COLUMNS[FieldTypes::index($field['type'])]], null];
    }

    /**
     * The entries as the item template will read them: each one's own values, with its title,
     * URL and one level of its relationships, in the shape bindings already understand.
     *
     * @param  list<array<string, mixed>>  $rows  reader rows
     * @return list<array<string, mixed>> one binding context per entry
     */
    private function items(string $siteId, array $rows, array $siteValues): array
    {
        $ids = array_column($rows, 'id');
        if ($ids === []) {
            return [];
        }
        $values = $this->fields->drafts($siteId, $ids);
        $items = [];
        foreach ($rows as $row) {
            $own = $values[$row['id']] ?? [];
            $fields = $this->schema->fieldsFor($siteId, ['kind' => $row['kind']]);
            $public = array_column(array_filter($fields, fn ($f) => ! ($f['settings']['private'] ?? false)), 'key');
            $entry = [
                // What every entry has, whatever fields its type declares: a title, the URL it
                // is published at, and its excerpt where the type keeps one.
                'title' => $row['title'],
                'path' => $row['livePath'] ?? $row['path'],
                'url' => $row['livePath'] ?? $row['path'],
                ...($row['excerpt'] === '' ? [] : ['excerpt' => $row['excerpt']]),
                ...array_intersect_key($own, array_flip($public)),
            ];
            $items[] = Bindings::context($this->relationships->expand($siteId, $fields, $entry), $siteValues);
        }

        return $items;
    }
}
