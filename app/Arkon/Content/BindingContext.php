<?php

namespace App\Arkon\Content;

use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * The content a document's bindings draw on while it renders: the values of the entry being
 * rendered, and the site's own values.
 *
 * Gathered once per render and handed to the renderer whole, so a page of twenty bound blocks
 * reads the same two things a page of one does.
 */
final class BindingContext
{
    public function __construct(
        private readonly FieldSchemaResolver $schema,
        private readonly SiteDataService $site,
        private readonly ContentTypeRegistry $types,
        private readonly Relationships $relationships,
    ) {}

    /**
     * What a page being edited can bind to: its own draft values, and the site's. Site values
     * include the private ones, because the admin is where private values are meant to be seen.
     */
    public function draft(string $siteId, ?string $pageId): array
    {
        return Bindings::context(
            $pageId === null ? [] : $this->through($siteId, $pageId, $this->draftValues($siteId, $pageId)),
            $this->siteValues($siteId, private: true),
        );
    }

    /**
     * What a page being published can bind to. The entry's values come from the revision going
     * live rather than from the draft, so a page shows what was published and not what somebody
     * has since typed; private site values never reach a public page.
     */
    public function published(string $siteId, ?string $pageId, mixed $revisionFields = null): array
    {
        $entry = $revisionFields !== null ? Json::toArray(Json::decode(is_string($revisionFields) ? $revisionFields : Json::encode((object) $revisionFields)))
            : ($pageId === null ? [] : $this->draftValues($siteId, $pageId));
        $entry = is_array($entry) ? $entry : [];
        if ($pageId !== null) {
            // Only what the entry's fields still define, and never a private one: marking a field
            // private keeps it off public pages even where a block was bound to it earlier.
            $public = array_values(array_filter($this->ownFields($siteId, $pageId), fn ($field) => ! ($field['settings']['private'] ?? false)));
            $entry = FieldValues::prune($public, $entry);
        }

        return Bindings::context(
            $pageId === null ? $entry : $this->through($siteId, $pageId, $entry, live: true),
            $this->siteValues($siteId, private: false),
        );
    }

    /**
     * The same values with what each single relationship points at read in place of the
     * reference, so a page can bind to "Agent → Name" and get the agent's name. A published page
     * reads what the related entry went live with (`$live`); the editor reads its draft.
     *
     * One step only (Relationships::DEPTH): the related entry's own references stay references,
     * so a cycle in the content graph renders and stops.
     */
    private function through(string $siteId, string $pageId, array $values, bool $live = false): array
    {
        return $this->relationships->expand($siteId, $this->ownFields($siteId, $pageId), $values, $live);
    }

    /**
     * The fields this page's entry carries, for showing what can be bound and what is broken.
     *
     * A relationship is presented as the fields of what it relates to, because that is what a
     * property can actually take: "Agent → Name", never "Agent". Everything that reads this —
     * the editor's list of sources, the check for bindings that point at nothing — then treats a
     * relationship exactly as it treats a group, one level deep and no further.
     */
    public function entryFields(string $siteId, ?string $pageId): array
    {
        if ($pageId === null) {
            return [];
        }
        $fields = $this->ownFields($siteId, $pageId);

        return array_map(fn ($field) => $field['type'] === 'relationship' && ! ($field['settings']['multiple'] ?? false)
            ? [...$field, 'fields' => $this->targetFields($siteId, $field['settings']['targetType'] ?? null)]
            : $field, $fields);
    }

    /** The fields of the entry itself, as its type and the groups reaching it declare them. */
    private function ownFields(string $siteId, ?string $pageId): array
    {
        // An id that is not one belongs to nothing, as in draftValues(): a sample entry in the
        // component preview has no page at all.
        if ($pageId === null || ! Uuid::isValid($pageId)) {
            return [];
        }

        return $this->schema->fieldsFor($siteId, $this->schema->contextForEntry($siteId, $pageId));
    }

    /**
     * What can be read through a relationship to this kind: its title, which is what most
     * templates want, and then its own public fields. A private field is not offered, and a
     * relationship on the target is not followed — one step is the limit.
     *
     * @return list<array<string, mixed>>
     */
    private function targetFields(string $siteId, ?string $kind): array
    {
        if ($kind === null || $kind === '' || ! $this->types->forSite($siteId)->exists($kind)) {
            return [];
        }
        $fields = [['key' => 'title', 'label' => 'Title', 'type' => 'text', 'settings' => []]];
        foreach ($this->schema->fieldsFor($siteId, ['kind' => $kind]) as $field) {
            if (($field['settings']['private'] ?? false) || $field['type'] === 'relationship') {
                continue;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /** The fields site data carries. */
    public function siteFields(string $siteId): array
    {
        return $this->schema->fieldsFor($siteId, SiteDataService::CONTEXT);
    }

    /**
     * The kinds of content that carry any fields at all, with what each is called. Used where a
     * choice of entry is offered: an entry of a kind with no fields would show nothing.
     *
     * @return array<string, string>
     */
    public function kindsWithFields(string $siteId): array
    {
        $kinds = [];
        foreach ($this->types->forSite($siteId)->all() as $kind => $type) {
            if ($this->schema->fieldsFor($siteId, ['kind' => $kind]) !== []) {
                $kinds[$kind] = $type['plural'];
            }
        }

        return $kinds;
    }

    private function draftValues(string $siteId, string $pageId): array
    {
        // An id that is not one belongs to nothing: answered here rather than by the database,
        // which would refuse the comparison instead of the request.
        if (! Uuid::isValid($pageId)) {
            return [];
        }
        $fields = DB::table('page_drafts')->where('site_id', $siteId)->where('page_id', $pageId)->value('fields');
        $values = Json::toArray(Json::decode($fields ?? '{}'));

        return is_array($values) ? FieldValues::prune($this->ownFields($siteId, $pageId), $values) : [];
    }

    private function siteValues(string $siteId, bool $private): array
    {
        if (! $private) {
            return $this->site->publicValues($siteId);
        }
        $row = DB::table('site_data')->where('site_id', $siteId)->value('values');
        $values = Json::toArray(Json::decode($row ?? '{}'));

        return is_array($values) ? $values : [];
    }
}
