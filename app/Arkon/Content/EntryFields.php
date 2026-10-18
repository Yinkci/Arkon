<?php

namespace App\Arkon\Content;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The field values of an entry whose type defines fields. Like the title and the details, the
 * values belong to the draft (page_drafts.fields), and every save records a revision holding
 * them, so they version, publish and restore through the existing pipeline: what goes live is
 * the published revision's values, never whatever the draft says now.
 *
 * What an entry stores and what its schema defines today can differ — a field was removed, a
 * group no longer applies, a role rule hides a field from this editor. Stored values a schema
 * no longer reaches are kept untouched (putting the field back brings them back) and are
 * neither shown, checked nor indexed; only values the schema defines are.
 *
 * Every save also rewrites that entry's rows in content_field_index, which is how lists filter
 * and sort without reading JSON. The index is derived: reindexPage() and reindexKinds() rebuild
 * it from the stored values, and nothing reads it as the truth.
 */
final class EntryFields
{
    public function __construct(
        private readonly Authorizer $auth,
        private readonly Transactions $tx,
        private readonly PageStore $store,
        private readonly AuditLog $audit,
        private readonly ContentTypeRegistry $types,
        private readonly FieldSchemaResolver $schema,
        private readonly Relationships $relationships,
    ) {}

    /** Stored values as a map. An entry that has none is {} in JSON, which decodes to an object. */
    private static function map(?string $json): array
    {
        $decoded = Json::toArray(Json::decode($json ?? '{}'));

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Draft values by page id, as the fields that apply today read them: a removed field (or one
     * removed from inside a group or repeater) disappears from every reader at once even though
     * its values are still stored.
     *
     * @param  list<string>  $pageIds
     * @return array<string, array<string, mixed>>
     */
    public function drafts(string $siteId, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }
        $rows = DB::table('page_drafts as d')->join('pages as p', 'p.id', '=', 'd.page_id')
            ->where('d.site_id', $siteId)->whereIn('d.page_id', $pageIds)->get(['d.page_id', 'd.fields', 'p.kind']);
        // One context for each entry, in two queries however many there are: a rule may ask about
        // the entry itself or its terms, so the kind alone is not enough to say what it carries.
        $contexts = $this->schema->contextsForEntries($siteId, $pageIds);
        $out = [];
        foreach ($rows as $row) {
            $context = $contexts[$row->page_id] ?? ['kind' => $row->kind];
            $out[$row->page_id] = FieldValues::prune($this->schema->fieldsFor($siteId, $context), self::map($row->fields));
        }

        return $out;
    }

    /**
     * The values each entry went live with: its live revision's. An entry that is not live, or
     * whose live revision was written before values were recorded, gives its draft's instead —
     * the first because a draft-only entry (a team member without a page of its own) is still
     * something a published page may point at, the second because it is what such a page showed
     * when it was published. Raw stored maps; readers prune them to their fields.
     *
     * @param  list<string>  $pageIds
     * @return array<string, array<string, mixed>>
     */
    public function liveValues(string $siteId, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }
        $rows = DB::table('page_drafts as d')
            ->leftJoin('live_pages as l', 'l.page_id', '=', 'd.page_id')
            ->leftJoin('publications as pub', 'pub.id', '=', 'l.publication_id')
            ->leftJoin('page_revisions as r', 'r.id', '=', 'pub.revision_id')
            ->where('d.site_id', $siteId)->whereIn('d.page_id', $pageIds)
            ->get(['d.page_id', 'd.fields as draft', 'r.fields as live', 'r.fields_recorded']);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->page_id] = self::map($row->fields_recorded ? $row->live : $row->draft);
        }

        return $out;
    }

    /**
     * The field values of entries as a reader outside the admin may see them: fields the
     * definition marks private are left out, Arkon's own bookkeeping is taken out, and every
     * relationship is replaced by what it points at — an object with that entry's id, type,
     * title and path, and nothing deeper, so a chain of references cannot recurse (one level,
     * Relationships::DEPTH).
     *
     * `$asVisitor` is the difference a reader makes: a visitor reads what went live (the live
     * revision's values) and a reference to an entry in the Trash is left out, as it is on a
     * page; a member reads the draft, sees every reference with its status, and so can say
     * "Trashed".
     *
     * @param  list<string>  $pageIds
     * @return array<string, array<string, mixed>>
     */
    public function publicValues(string $siteId, array $pageIds, bool $asVisitor): array
    {
        if ($pageIds === []) {
            return [];
        }
        $stored = $asVisitor
            ? $this->liveValues($siteId, $pageIds)
            : array_map(self::map(...), DB::table('page_drafts')->where('site_id', $siteId)->whereIn('page_id', $pageIds)->pluck('fields', 'page_id')->all());
        $contexts = $this->schema->contextsForEntries($siteId, $pageIds);
        $out = [];
        foreach ($stored as $pageId => $values) {
            $fields = $this->schema->fieldsFor($siteId, $contexts[$pageId] ?? []);
            $shown = array_values(array_filter($fields, fn ($field) => ! ($field['settings']['private'] ?? false)));
            // Content sections are shaped first, because the envelope needs each block's id,
            // and Arkon's other bookkeeping is taken out afterwards.
            $out[$pageId] = FieldValues::withoutInternals(Flexible::forApi(
                $shown,
                $this->relationships->resolve($siteId, ['fields' => $shown], FieldValues::prune($shown, $values), public: $asVisitor),
            ));
        }

        return $out;
    }

    /**
     * Replaces the given values on a draft. Fields left out keep what they had, so a caller that
     * knows about one field cannot wipe the others.
     *
     * `$expected` is what the caller last saw for the fields it is changing, by key. If any of
     * them has changed since — another tab, the API, AI — nothing is saved and the caller is
     * told to reload, rather than quietly overwriting somebody else's edit.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $expected
     * @return array<string, mixed> the draft's values afterwards
     */
    public function update(SiteContext $ctx, string $pageId, array $values, ?array $expected = null): array
    {
        return $this->tx->run(function () use ($ctx, $pageId, $values, $expected) {
            $this->auth->authorize($ctx, 'page.edit');
            [$page] = $this->store->lockForWrite($ctx->siteId, $pageId);
            if ($expected !== null) {
                $this->assertUnchanged($ctx->siteId, $pageId, Json::toArray($expected) ?: []);
            }
            $this->updateLocked($ctx, $page, $values);

            return $this->drafts($ctx->siteId, [$pageId])[$pageId] ?? [];
        });
    }

    /** The part of update() for callers that already hold the page's write lock. */
    public function updateLocked(SiteContext $ctx, object $page, array $values): void
    {
        // Values arrive in raw form like definitions do, where an empty JSON object is a
        // stdClass; a value is a map or a scalar, never a document, so it becomes internal form
        // here, once, for every caller (FieldValues then decides what each type accepts).
        $values = Json::toArray($values);
        if ($values === []) {
            return;
        }
        $role = $this->auth->authorize($ctx, 'page.edit');
        // Two schemas: what this person may change (a role rule can hide fields from them), and
        // what the entry carries whoever is editing, which is what its stored values are kept,
        // checked and indexed against — so one person's save never drops what another may edit.
        $mine = $this->schema->definitionFor($ctx->siteId, $this->schema->contextForEntry($ctx->siteId, $page->id, $role));
        $full = $this->schema->definitionFor($ctx->siteId, $this->schema->contextForEntry($ctx->siteId, $page->id));
        if ($mine === null || $full === null) {
            throw new ValidationException('This kind of content has no fields.');
        }
        $given = FieldValues::validate($mine, $values);
        $stored = self::map(DB::table('page_drafts')->where('page_id', $page->id)->value('fields'));
        $current = FieldValues::prune($full['fields'], $stored);
        // Values no field reaches any more are kept as they are, beside the ones that apply.
        $hidden = array_diff_key($stored, $current);
        $merged = [...$current, ...$given];
        // A field given as null is being cleared, not set to nothing in particular.
        foreach ($values as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($merged[$key]);
            }
        }
        $next = FieldValues::validate($full, $merged);
        // A section of a kind the field no longer offers is kept, but never added or changed.
        Flexible::assertDeprecatedUnchanged($full['fields'], $current, $next);
        // Shape first, then what the shape points at: a reference has to name something that
        // exists on this site and is of the type the field asked for, and a picture has to be in
        // this site's library. Only what this save adds is checked against the library, so an
        // image archived later does not stop anybody saving the entry that still shows it.
        $this->relationships->assertValid($ctx->siteId, $full, $next);
        $this->assertMedia($ctx->siteId, array_diff(FieldValues::mediaIds($full['fields'], $next), FieldValues::mediaIds($full['fields'], $current)));
        $kept = [...$hidden, ...$next];
        if (Json::canonical($kept) === Json::canonical($stored)) {
            return; // Nothing changed: no revision, no new checkpoint.
        }
        DB::table('page_drafts')->where('page_id', $page->id)->update(['fields' => Json::encode((object) $kept), 'updated_by' => $ctx->userId, 'updated_at' => DB::raw('now()')]);
        $this->reindex($ctx->siteId, $page->id, $full, $next, $page->kind);
        // A revision, like any other save: the history shows the change, Restore can bring the
        // values back, and the entry reads as having unpublished changes until it is published.
        $draft = DB::table('page_drafts')->where('page_id', $page->id)->first(['document', 'version']);
        $labels = array_column($full['fields'], 'label', 'key');
        $names = array_map(fn ($key) => $labels[$key] ?? $key, array_keys($values));
        $revision = $this->store->insertRevision($ctx, $page->id, $this->store->document($draft->document), $page->title, $page->path,
            mb_substr('Changed '.implode(', ', $names), 0, 200), $kept);
        DB::table('page_drafts')->where('page_id', $page->id)->update(['checkpoint_revision_id' => $revision['id'], 'checkpoint_version' => $draft->version]);
        $this->audit->forContext($ctx, 'content.fields.save', 'page', $page->id, ['fields' => array_keys($values), 'revision' => $revision['number']]);
    }

    /**
     * One change to one content-sections field: insert, move, update, remove or duplicate a
     * block. The field's whole value is then saved the ordinary way, so an operation can never
     * store anything a straight save would have refused.
     *
     * Small changes rather than a new array each time: that is what makes an edit from the
     * admin, from the API or from AI reviewable, and a revision able to say which section
     * changed.
     *
     * @param  array<string, mixed>  $operation  {field, op, id?, index?, layout?, set?}
     * @return array<string, mixed> the entry's values afterwards
     */
    public function section(SiteContext $ctx, string $pageId, array $operation): array
    {
        return $this->tx->run(function () use ($ctx, $pageId, $operation) {
            $role = $this->auth->authorize($ctx, 'page.edit');
            [$page] = $this->store->lockForWrite($ctx->siteId, $pageId);
            $definition = $this->schema->definitionFor($ctx->siteId, $this->schema->contextForEntry($ctx->siteId, $page->id, $role));
            $key = is_string($operation['field'] ?? null) ? $operation['field'] : '';
            $field = Flexible::fieldsIn($definition['fields'] ?? [])[$key] ?? null;
            if ($field === null) {
                throw new ValidationException('This entry has no content sections called "'.$key.'".');
            }
            $current = FieldValues::prune([$field], self::map(DB::table('page_drafts')->where('page_id', $page->id)->value('fields')));
            $this->updateLocked($ctx, $page, [$key => Flexible::apply($field, $current[$key] ?? [], $operation)]);

            return $this->drafts($ctx->siteId, [$page->id])[$page->id] ?? [];
        });
    }

    /**
     * Rewrites one entry's rows in the query index. A value marked unique collides here rather
     * than in a later read, because the database holds the rule — among entries of one type.
     */
    public function reindex(string $siteId, string $pageId, array $definition, array $values, ?string $kind = null): void
    {
        $kind ??= (string) DB::table('pages')->where('id', $pageId)->value('kind');
        DB::table('content_field_index')->where('page_id', $pageId)->delete();
        // Only values their field can hold are projected: one stored before a field changed shape
        // is not something a list can filter on, and must not stop the rest being indexed.
        $values = FieldValues::prune($definition['fields'], $values);
        foreach ($definition['fields'] as $field) {
            try {
                FieldValues::one($field, $values[$field['key']] ?? null);
            } catch (ValidationException) {
                unset($values[$field['key']]);
            }
        }
        $rows = FieldValues::index($definition, $values);
        if ($rows === []) {
            return;
        }
        try {
            DB::table('content_field_index')->insert(array_map(fn ($row) => [...$row, 'site_id' => $siteId, 'page_id' => $pageId, 'kind' => $kind], $rows));
        } catch (UniqueConstraintViolationException) {
            $unique = array_column(array_filter($definition['fields'], fn ($f) => $f['settings']['unique'] ?? false), 'label', 'key');
            $plural = $this->types->forSite($siteId)->exists($kind) ? $this->types->forSite($siteId)->get($kind)['plural'] : $kind;
            throw new ValidationException(
                count($unique) === 1
                    ? reset($unique)." must be different for every one of the {$plural}, and another already uses this one."
                    : 'One of '.implode(', ', $unique)." must be different for every one of the {$plural}, and another already uses this value."
            );
        }
    }

    /** Rebuilds one entry's index rows from what it stores and the fields that apply today. */
    public function reindexPage(string $siteId, string $pageId): void
    {
        $row = DB::table('page_drafts as d')->join('pages as p', 'p.id', '=', 'd.page_id')
            ->where('d.site_id', $siteId)->where('d.page_id', $pageId)->first(['d.fields', 'p.kind', 'p.purged_at']);
        if ($row === null || $row->purged_at !== null) {
            DB::table('content_field_index')->where('page_id', $pageId)->delete();

            return;
        }
        $definition = $this->schema->definitionFor($siteId, $this->schema->contextForEntry($siteId, $pageId)) ?? ['fields' => []];
        $this->reindex($siteId, $pageId, $definition, self::map($row->fields), $row->kind);
    }

    /**
     * Rebuilds the index for every entry of these kinds, after a schema change moved fields on
     * or off them. Work proportional to the entries affected, in batches, so the rows of other
     * types are never touched and nothing stale is left behind.
     *
     * @param  list<string>  $kinds
     */
    public function reindexKinds(string $siteId, array $kinds): int
    {
        if ($kinds === []) {
            return 0;
        }
        $this->schema->forget($siteId);
        $count = 0;
        DB::table('pages')->where('site_id', $siteId)->whereIn('kind', array_values(array_unique($kinds)))->whereNull('purged_at')
            ->orderBy('id')->select('id')->chunkById(500, function ($pages) use ($siteId, &$count) {
                foreach ($pages as $page) {
                    $this->reindexPage($siteId, $page->id);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * What a revision's values would put back, against today's fields: every value they hold
     * that the field can still hold, and the current value wherever it cannot (reported by key,
     * so the restore can say so). Values for fields that no longer apply are restored as kept,
     * hidden values, as they were.
     *
     * @param  array<string, mixed>  $values  the revision's stored values
     * @return array{values: array<string, mixed>, kept: list<string>}
     */
    public function restorable(string $siteId, string $pageId, array $values): array
    {
        $definition = $this->schema->definitionFor($siteId, $this->schema->contextForEntry($siteId, $pageId));
        if ($definition === null) {
            return ['values' => $values, 'kept' => []];
        }
        $current = self::map(DB::table('page_drafts')->where('page_id', $pageId)->value('fields'));
        $out = array_diff_key($values, array_flip(array_column($definition['fields'], 'key')));
        $kept = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['key'];
            $old = FieldValues::prune([$field], $values)[$key] ?? null;
            try {
                $value = FieldValues::one($field, $old);
            } catch (ValidationException) {
                $kept[] = $key;
                $value = $current[$key] ?? null;
            }
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return ['values' => $out, 'kept' => $kept];
    }

    /**
     * The values a new entry of this kind starts with: each field's declared default. Fields that
     * only some entries carry (rules about one entry or its terms) cannot apply yet, so only the
     * kind decides here.
     *
     * @return array<string, mixed>
     */
    public function defaults(string $siteId, string $kind): array
    {
        $definition = $this->schema->definitionFor($siteId, ['kind' => $kind]);

        return $definition === null ? [] : FieldValues::validate($definition, [], creating: true);
    }

    /**
     * What publishing records about an entry's fields, read under the page's write lock. The
     * rules that only apply to live content are applied here, so a draft may stay incomplete
     * while what goes live is whole.
     *
     * @return array<string, mixed>
     */
    public function snapshot(SiteContext $ctx, string $pageId, string $kind): array
    {
        $definition = $this->schema->definitionFor($ctx->siteId, $this->schema->contextForEntry($ctx->siteId, $pageId));
        if ($definition === null) {
            return [];
        }
        $values = FieldValues::prune($definition['fields'], self::map(DB::table('page_drafts')->where('page_id', $pageId)->value('fields')));

        return FieldValues::validate($definition, $values, publishing: true);
    }

    /**
     * Refuses a schema change that would leave entries holding values their fields can no longer
     * hold — text in what is now a number, a choice that was taken away, a reference to a kind
     * the field no longer points at. Such an entry could not be saved or published again until
     * somebody found and cleared the value by hand, so the change waits until they have.
     *
     * @param  list<string>  $kinds  the content types the changed fields apply to
     * @param  list<array<string, mixed>>  $fields  the new definitions of the changed fields
     */
    public function assertValuesFit(string $siteId, array $kinds, array $fields): void
    {
        if ($kinds === [] || $fields === []) {
            return;
        }
        $failures = [];
        $refs = [];
        DB::table('pages as p')->join('page_drafts as d', 'd.page_id', '=', 'p.id')
            ->where('p.site_id', $siteId)->whereIn('p.kind', $kinds)->whereNull('p.purged_at')
            ->orderBy('p.id')->select(['p.id', 'p.title', 'd.fields'])
            ->chunkById(500, function ($rows) use ($fields, &$failures, &$refs) {
                foreach ($rows as $row) {
                    $stored = self::map($row->fields);
                    foreach ($fields as $field) {
                        $value = FieldValues::prune([$field], $stored)[$field['key']] ?? null;
                        try {
                            FieldValues::one($field, $value);
                            foreach (Relationships::refsIn([$field], [$field['key'] => $value]) as $ref) {
                                foreach ($ref['ids'] as $id) {
                                    $refs[] = [$id, $ref['target'], $field, $row->title];
                                }
                            }
                        } catch (ValidationException) {
                            $failures[$field['key']][] = $row->title;
                        }
                    }
                }
            }, 'p.id', 'id');
        if ($refs !== []) {
            $kindsById = DB::table('pages')->where('site_id', $siteId)->whereIn('id', array_values(array_unique(array_column($refs, 0))))->pluck('kind', 'id')->all();
            foreach ($refs as [$id, $target, $field, $title]) {
                if ($target !== null && isset($kindsById[$id]) && $kindsById[$id] !== $target) {
                    $failures[$field['key']][] = $title;
                }
            }
        }
        if ($failures === []) {
            return;
        }
        $labels = array_column($fields, 'label', 'key');
        $sentences = [];
        foreach ($failures as $key => $titles) {
            $titles = array_values(array_unique($titles));
            $sentences[] = ($labels[$key] ?? $key).': '.count($titles).' '.(count($titles) === 1 ? 'entry holds a value' : 'entries hold values')
                .' it could no longer keep ('.implode(', ', array_map(fn ($t) => '“'.$t.'”', array_slice($titles, 0, 3))).(count($titles) > 3 ? ', …' : '').').';
        }
        throw new ValidationException(
            implode(' ', $sentences).' Change or clear those values first, or add a new field instead of changing this one.',
            array_map(fn ($key) => ['path' => 'fields.'.$key, 'fieldKey' => $key, 'code' => 'values_do_not_fit', 'message' => 'Stored values would no longer fit'], array_keys($failures)),
        );
    }

    /** Refuses a save when a field it changes was changed by somebody else since it was read. */
    private function assertUnchanged(string $siteId, string $pageId, array $expected): void
    {
        $now = $this->drafts($siteId, [$pageId])[$pageId] ?? [];
        foreach ($expected as $key => $value) {
            if (Json::canonical($now[$key] ?? null) !== Json::canonical(($value === '' || $value === []) ? null : $value)) {
                throw new ConflictException('Someone else changed these fields since you opened them. Reload to see their changes before saving yours.');
            }
        }
    }

    /** @param  array<int, string>  $ids */
    private function assertMedia(string $siteId, array $ids): void
    {
        $ids = array_values($ids);
        if ($ids === []) {
            return;
        }
        $found = DB::table('media_assets')->where('site_id', $siteId)->whereIn('id', $ids)->whereNull('archived_at')->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw new ValidationException('A picture or file chosen here is not in this site\'s media library.');
        }
    }
}
