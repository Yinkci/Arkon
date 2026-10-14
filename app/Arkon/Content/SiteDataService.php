<?php

namespace App\Arkon\Content;

use App\Arkon\Database\Transactions;
use App\Arkon\Errors\ConflictException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * The values that belong to the website itself: its phone number, its address, the hours it
 * opens. Defined once and used everywhere, instead of being retyped onto every page.
 *
 * Which fields exist here is not decided here — a field group says so, with a rule that puts it
 * on site data — so this is the same field engine, the same validation and the same storage as
 * an entry's values. What differs is only who owns them: the site, not an entry.
 *
 * Site data shows on every page, so a change is versioned and kept: the previous values go to
 * site_data_revisions before the new ones land.
 */
final class SiteDataService
{
    /** The context a rule is evaluated against when asking what site data carries. */
    public const CONTEXT = ['scope' => 'site'];

    public function __construct(
        private readonly Authorizer $auth,
        private readonly Transactions $tx,
        private readonly FieldSchemaResolver $schema,
    ) {}

    /** What site data holds, with the fields that shape it, for the admin screen. */
    public function show(SiteContext $ctx): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $row = $this->row($ctx->siteId);

        return [
            'version' => (int) $row->version,
            'values' => (object) $this->readable($ctx->siteId, Json::toArray(Json::decode($row->values))),
            'sections' => $this->schema->sectionsFor($ctx->siteId, self::CONTEXT),
            'updatedAt' => $row->updated_at,
        ];
    }

    /**
     * Replaces the given values. Fields left out keep what they had, so a caller that knows about
     * one field cannot wipe the rest; a field given as empty is cleared.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(SiteContext $ctx, array $values, ?int $expectedVersion = null): array
    {
        // Changing what the whole website says is not ordinary content editing.
        $this->auth->authorize($ctx, 'page.publish');
        $values = Json::toArray($values);

        return $this->tx->run(function () use ($ctx, $values, $expectedVersion) {
            $row = $this->row($ctx->siteId, lock: true);
            if ($expectedVersion !== null && $expectedVersion !== (int) $row->version) {
                throw new ConflictException('Site data changed somewhere else. Reload before saving again.');
            }
            $definition = $this->definition($ctx->siteId);
            if ($definition === null) {
                throw new ValidationException('No field group puts any fields on site data yet.');
            }
            $current = Json::toArray(Json::decode($row->values));
            $merged = [...$current, ...FieldValues::validate($definition, $values)];
            foreach ($values as $key => $value) {
                if ($value === null || $value === '' || $value === []) {
                    unset($merged[$key]);
                }
            }
            $stored = FieldValues::validate($definition, $merged);

            // What it said before is kept, because this shows on every page of the website.
            DB::table('site_data_revisions')->insert([
                'id' => Uuid::v7(),
                'site_id' => $ctx->siteId,
                'version' => (int) $row->version,
                'values' => Json::encode($current),
                'author_id' => $ctx->userId,
            ]);
            DB::table('site_data')->where('site_id', $ctx->siteId)->update([
                'values' => Json::encode($stored),
                'version' => (int) $row->version + 1,
                'updated_by' => $ctx->userId,
                'updated_at' => now(),
            ]);

            return $this->show($ctx);
        });
    }

    /** What site data said before, newest first, so a change can be seen and undone. */
    public function history(SiteContext $ctx, int $limit = 20): array
    {
        $this->auth->authorize($ctx, 'page.view');

        return DB::table('site_data_revisions as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.author_id')
            ->where('r.site_id', $ctx->siteId)
            ->orderByDesc('r.created_at')
            ->limit($limit)
            ->get(['r.id', 'r.version', 'r.created_at', 'u.name as author'])
            ->map(fn ($row) => ['id' => $row->id, 'version' => (int) $row->version, 'at' => $row->created_at, 'author' => $row->author])
            ->all();
    }

    /** Puts back what an earlier version said, as a new version rather than by rewriting history. */
    public function restore(SiteContext $ctx, string $revisionId): array
    {
        $this->auth->authorize($ctx, 'page.publish');
        // Checked before it reaches the database: an id that is not one is a bad request, not a
        // failed query.
        if (! Uuid::isValid($revisionId)) {
            throw new ValidationException('That version of the site data no longer exists.');
        }
        $revision = DB::table('site_data_revisions')->where('site_id', $ctx->siteId)->where('id', $revisionId)->first()
            ?? throw new ValidationException('That version of the site data no longer exists.');

        return $this->update($ctx, Json::toArray(Json::decode($revision->values)));
    }

    /**
     * The values a visitor, the public API or AI may see: everything except the fields a
     * definition marks private. Read without a site context because the renderer needs them for
     * anyone, signed in or not.
     *
     * @return array<string, mixed>
     */
    public function publicValues(string $siteId): array
    {
        $row = DB::table('site_data')->where('site_id', $siteId)->first(['values']);
        $values = $row ? Json::toArray(Json::decode($row->values)) : [];

        return FieldValues::withoutInternals(array_intersect_key($values, array_flip($this->keys($siteId, private: false))));
    }

    /** Everything site data carries, for the admin, where private fields are the point. */
    private function readable(string $siteId, array $values): array
    {
        return array_intersect_key($values, array_flip($this->keys($siteId, private: true)));
    }

    /**
     * The field keys site data carries. `private: false` leaves out the fields marked private, so
     * nothing public can accidentally include one.
     *
     * @return list<string>
     */
    private function keys(string $siteId, bool $private): array
    {
        $fields = $this->schema->fieldsFor($siteId, self::CONTEXT);
        if (! $private) {
            $fields = array_filter($fields, fn ($field) => ! ($field['settings']['private'] ?? false));
        }

        return array_column($fields, 'key');
    }

    private function definition(string $siteId): ?array
    {
        $fields = $this->schema->fieldsFor($siteId, self::CONTEXT);

        return $fields === [] ? null : ['fields' => $fields];
    }

    /** Site data exists for every site; the row is made the first time anybody looks. */
    private function row(string $siteId, bool $lock = false): object
    {
        $query = fn () => DB::table('site_data')->where('site_id', $siteId)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $row = $query();
        if ($row === null) {
            DB::table('site_data')->insertOrIgnore(['site_id' => $siteId, 'values' => '{}', 'version' => 1]);
            $row = $query();
        }

        return $row;
    }
}
