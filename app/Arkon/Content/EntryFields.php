<?php

namespace App\Arkon\Content;

use App\Arkon\Audit\AuditLog;
use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The field values of an entry whose type defines fields. Like the title and the details, the
 * values belong to the draft (page_drafts.fields), so they take part in the existing draft,
 * revision, publish and Trash pipeline instead of a second one beside it.
 *
 * Every save also rewrites that entry's rows in content_field_index, which is how lists filter
 * and sort without reading JSON. The index is derived: if it were ever lost, saving the entries
 * again would rebuild it, and nothing reads it as the truth.
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
        $decoded = Json::decode($json ?? '{}');

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Draft values by page id, with the fields the type no longer defines left out, so a removed
     * field disappears from every reader at once even though its values are still stored.
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
            $defined = array_column($this->schema->fieldsFor($siteId, $context), 'key');
            $out[$row->page_id] = array_intersect_key(self::map($row->fields), array_flip($defined));
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
     * Values come from the draft, because that is where an entry's values live: publishing
     * versions the document, and a bound page renders the values as they are now. `$asVisitor`
     * is the difference a reader makes: a reference to an entry in the Trash is left out, as it
     * is on a page, while the admin sees it with its status so it can say "Trashed".
     *
     * @param  list<string>  $pageIds
     * @return array<string, array<string, mixed>>
     */
    public function publicValues(string $siteId, array $pageIds, bool $asVisitor): array
    {
        if ($pageIds === []) {
            return [];
        }
        $rows = DB::table('page_drafts')->where('site_id', $siteId)->whereIn('page_id', $pageIds)->get(['page_id', 'fields']);
        $contexts = $this->schema->contextsForEntries($siteId, $pageIds);
        $out = [];
        foreach ($rows as $row) {
            $fields = $this->schema->fieldsFor($siteId, $contexts[$row->page_id] ?? []);
            $shown = array_values(array_filter($fields, fn ($field) => ! ($field['settings']['private'] ?? false)));
            $values = array_intersect_key(self::map($row->fields), array_flip(array_column($shown, 'key')));
            // Content sections are shaped first, because the envelope needs each block's id,
            // and Arkon's other bookkeeping is taken out afterwards.
            $out[$row->page_id] = FieldValues::withoutInternals(Flexible::forApi(
                $shown,
                $this->relationships->resolve($siteId, ['fields' => $shown], $values, public: $asVisitor),
            ));
        }

        return $out;
    }

    /**
     * Replaces the given values on a draft. Fields left out keep what they had, so a caller that
     * knows about one field cannot wipe the others.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the draft's values afterwards
     */
    public function update(SiteContext $ctx, string $pageId, array $values): array
    {
        return $this->tx->run(function () use ($ctx, $pageId, $values) {
            $this->auth->authorize($ctx, 'page.edit');
            [$page] = $this->store->lockForWrite($ctx->siteId, $pageId);
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
        $this->auth->authorize($ctx, 'page.edit');
        $definition = $this->schema->definitionFor($ctx->siteId, $this->schema->contextForEntry($ctx->siteId, $page->id));
        if ($definition === null) {
            throw new ValidationException('This kind of content has no fields.');
        }
        $current = self::map(DB::table('page_drafts')->where('page_id', $page->id)->value('fields'));
        $merged = [...$current, ...FieldValues::validate($definition, $values)];
        // A field given as null is being cleared, not set to nothing in particular.
        foreach ($values as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($merged[$key]);
            }
        }
        $stored = FieldValues::validate($definition, $merged);
        // A section of a kind the field no longer offers is kept, but never added or changed.
        Flexible::assertDeprecatedUnchanged($definition['fields'], $current, $stored);
        // Shape first, then what the shape points at: a reference has to name something that
        // exists on this site and is of the type the field asked for.
        $this->relationships->assertValid($ctx->siteId, $definition, $stored);
        DB::table('page_drafts')->where('page_id', $page->id)->update(['fields' => Json::encode($stored), 'updated_at' => DB::raw('now()')]);
        $this->reindex($ctx->siteId, $page->id, $definition, $stored);
        $this->audit->forContext($ctx, 'content.fields.save', 'page', $page->id, ['fields' => array_keys($values)]);
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
            $this->auth->authorize($ctx, 'page.edit');
            [$page] = $this->store->lockForWrite($ctx->siteId, $pageId);
            $definition = $this->schema->definitionFor($ctx->siteId, $this->schema->contextForEntry($ctx->siteId, $page->id));
            $key = is_string($operation['field'] ?? null) ? $operation['field'] : '';
            $field = Flexible::fieldsIn($definition['fields'] ?? [])[$key] ?? null;
            if ($field === null) {
                throw new ValidationException('This entry has no content sections called "'.$key.'".');
            }
            $current = self::map(DB::table('page_drafts')->where('page_id', $page->id)->value('fields'));
            $this->updateLocked($ctx, $page, [$key => Flexible::apply($field, $current[$key] ?? [], $operation)]);

            return $this->drafts($ctx->siteId, [$page->id])[$page->id] ?? [];
        });
    }

    /**
     * Rewrites one entry's rows in the query index. A value marked unique collides here rather
     * than in a later read, because the database holds the rule.
     */
    public function reindex(string $siteId, string $pageId, array $definition, array $values): void
    {
        DB::table('content_field_index')->where('page_id', $pageId)->delete();
        $rows = FieldValues::index($definition, $values);
        if ($rows === []) {
            return;
        }
        try {
            DB::table('content_field_index')->insert(array_map(fn ($row) => [...$row, 'site_id' => $siteId, 'page_id' => $pageId], $rows));
        } catch (UniqueConstraintViolationException) {
            $unique = array_column(array_filter($definition['fields'], fn ($f) => $f['settings']['unique'] ?? false), 'label', 'key');
            throw new ValidationException(
                count($unique) === 1
                    ? reset($unique).' must be different for every entry, and another entry already uses this one.'
                    : 'One of '.implode(', ', $unique).' must be different for every entry, and another entry already uses this value.'
            );
        }
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
        $values = self::map(DB::table('page_drafts')->where('page_id', $pageId)->value('fields'));

        return FieldValues::validate($definition, $values, publishing: true);
    }
}
