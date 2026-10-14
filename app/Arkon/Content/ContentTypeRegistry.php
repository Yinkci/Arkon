<?php

namespace App\Arkon\Content;

use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a site's content types, built-in and defined, into one registry (SiteTypes).
 *
 * Definitions are read once per site per request: a page list, the editor and the API all ask
 * for the same registry in one request, and none of them should pay for a second query. The
 * memo is per instance, so nothing survives into another request or another site.
 */
final class ContentTypeRegistry
{
    /** @var array<string, SiteTypes> */
    private array $memo = [];

    public function forSite(string $siteId): SiteTypes
    {
        return $this->memo[$siteId] ??= new SiteTypes($siteId, [...ContentTypes::all(), ...$this->defined($siteId)]);
    }

    /** Forgets what was read, after a definition changes. */
    public function forget(?string $siteId = null): void
    {
        if ($siteId === null) {
            $this->memo = [];
        } else {
            unset($this->memo[$siteId]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function defined(string $siteId): array
    {
        $rows = DB::table('content_types')->where('site_id', $siteId)->orderBy('key')->get();
        $types = [];
        foreach ($rows as $row) {
            $d = Json::decode($row->definition);
            $types[$row->key] = [
                'label' => $d['singular'],
                'plural' => $d['name'],
                // What the API calls this type: its own plural, in URL form.
                'collection' => self::collection($d['name'], $row->key),
                'pathPrefix' => $d['pathPrefix'],
                'details' => $d['details'],
                'taxonomies' => $d['taxonomies'],
                'description' => $d['description'],
                'icon' => $d['icon'],
                'inMenu' => $d['inMenu'],
                'menuOrder' => $d['menuOrder'],
                'fields' => $d['fields'],
                'schemaVersion' => (int) $row->schema_version,
                'active' => (bool) $row->active,
                'defined' => true,
                'id' => $row->id,
            ];
        }

        return $types;
    }

    /** A URL-safe collection name, falling back to the key when the plural has nothing usable. */
    private static function collection(string $plural, string $key): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $plural), '-'));

        return $slug === '' ? str_replace('_', '-', $key) : $slug;
    }
}
