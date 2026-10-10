<?php

namespace App\Http\Api;

use App\Arkon\Api\Scopes;
use App\Arkon\Content\ContentReader;
use App\Arkon\Content\ContentTypes;
use App\Arkon\Content\PublicBlocks;
use App\Arkon\Content\Taxonomies;

/**
 * The OpenAPI 3.1 description of /api/v1, built from the same registries as the routes, so a new
 * content type or taxonomy appears in both. `php artisan arkon:openapi` writes it to
 * resources/api/openapi.json (served at /api/v1/openapi.json); the contract tests fail when the
 * file is out of date, a route is undocumented, or a response does not match its schema.
 */
final class OpenApi
{
    public static function build(): array
    {
        $paths = [];
        $schemas = self::schemas();
        $ref = fn (string $name) => ['$ref' => "#/components/schemas/{$name}"];
        $item = fn (string $name) => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $ref($name)]];
        $collection = fn (string $name) => ['type' => 'object', 'required' => ['data', 'meta', 'links'], 'properties' => ['data' => ['type' => 'array', 'items' => $ref($name)], 'meta' => $ref('PaginationMeta'), 'links' => $ref('PaginationLinks')]];
        $ok = fn (array $schema, string $description = 'OK') => ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
        $errors = fn (int ...$codes) => array_combine(array_map('strval', $codes), array_map(fn ($c) => ['$ref' => '#/components/responses/Error'], $codes));
        $param = fn (string $name, string $in, array $schema, string $description, bool $required = false) => ['name' => $name, 'in' => $in, 'required' => $required || $in === 'path', 'description' => $description, 'schema' => $schema];
        $id = $param('id', 'path', ['type' => 'string', 'format' => 'uuid'], 'The id.');
        $pageParams = [$param('page', 'query', ['type' => 'integer', 'minimum' => 1], 'Page number, from 1.'), $param('per_page', 'query', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'Items per page (default 10, at most 100).')];
        $write = fn (string $scope) => [['bearerAuth' => [$scope]]];
        $public = [[], ['bearerAuth' => []]];
        $idempotency = $param('Idempotency-Key', 'header', ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{16,100}$'], 'Makes a retry safe: the same key with the same body returns the first result; with another body it is a 409.');

        $paths['/'] = ['get' => ['operationId' => 'discover', 'tags' => ['Site'], 'summary' => 'API index: version, resources and scopes', 'security' => $public, 'responses' => ['200' => $ok($item('ApiIndex'))]]];
        $paths['/openapi.json'] = ['get' => ['operationId' => 'openapi', 'tags' => ['Site'], 'summary' => 'This document', 'security' => $public, 'responses' => ['200' => ['description' => 'OpenAPI 3.1 document']]]];
        $paths['/site'] = ['get' => ['operationId' => 'getSite', 'tags' => ['Site'], 'summary' => 'Public site details', 'security' => $public, 'responses' => ['200' => $ok($item('Site'))]]];

        foreach (ContentTypes::all() as $kind => $type) {
            $c = '/'.$type['collection'];
            $schema = ucfirst($kind);
            $tag = $type['plural'];
            $filters = [
                $param('status', 'query', ['type' => 'string', 'enum' => ContentReader::STATUSES], 'published (default, anonymous) or, with read:'.$type['collection'].', draft, trash or any.'),
                $param('context', 'query', ['type' => 'string', 'enum' => ['view', 'edit']], 'edit: the draft state of published items (needs read:'.$type['collection'].').'),
                $param('search', 'query', ['type' => 'string', 'maxLength' => 200], 'Search the title'.($type['details'] ? ' and excerpt.' : '.')),
                $param('slug', 'query', ['type' => 'string'], 'The last segment of the URL path.'),
                $param('path', 'query', ['type' => 'string'], 'The exact URL path, e.g. /about.'),
                $param('author', 'query', ['type' => 'string', 'format' => 'uuid'], 'Author (user) id.'),
                $param('after', 'query', ['type' => 'string', 'format' => 'date-time'], 'Published after.'),
                $param('before', 'query', ['type' => 'string', 'format' => 'date-time'], 'Published before.'),
                ...array_map(fn ($t) => $param($t, 'query', ['type' => 'string'], Taxonomies::get($t)['label'].' ids or slugs, comma-separated (any of them).'), $type['taxonomies']),
                $param('sort', 'query', ['type' => 'string', 'enum' => [...ContentReader::SORTS, ...array_map(fn ($s) => '-'.$s, ContentReader::SORTS)]], 'One field; - for descending. Default '.($type['details'] ? '-published_at' : 'path').'.'),
                $param('include', 'query', ['type' => 'string'], 'content and/or seo, comma-separated (single items always include both).'),
                ...$pageParams,
            ];
            $paths[$c] = [
                'get' => ['operationId' => 'list'.$type['plural'], 'tags' => [$tag], 'summary' => "List {$type['plural']}", 'security' => $public, 'parameters' => $filters, 'responses' => ['200' => $ok($collection($schema))] + $errors(400, 401, 403, 429)],
                'post' => ['operationId' => 'create'.$type['label'], 'tags' => [$tag], 'summary' => "Create a {$kind} (a draft unless status is published)", 'security' => $write('write:'.$type['collection']), 'parameters' => [$idempotency],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref($schema.'Write')]]],
                    'responses' => ['201' => $ok($item($schema), 'Created'), '200' => $ok($item($schema), 'Replayed (same Idempotency-Key)')] + $errors(400, 401, 403, 409, 422, 429)],
            ];
            $paths["{$c}/{id}"] = [
                'get' => ['operationId' => 'get'.$type['label'], 'tags' => [$tag], 'summary' => "One {$kind} with its rendered content and SEO", 'security' => $public, 'parameters' => [$id, $param('context', 'query', ['type' => 'string', 'enum' => ['view', 'edit']], 'edit: the draft state.')], 'responses' => ['200' => $ok($item($schema))] + $errors(401, 403, 404)],
                'patch' => ['operationId' => 'update'.$type['label'], 'tags' => [$tag], 'summary' => 'Change fields; status published publishes, draft unpublishes', 'security' => $write('write:'.$type['collection']), 'parameters' => [$id],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref($schema.'Write')]]], 'responses' => ['200' => $ok($item($schema))] + $errors(401, 403, 404, 409, 422, 429)],
                'delete' => ['operationId' => 'trash'.$type['label'], 'tags' => [$tag], 'summary' => 'Move to the Trash; force=true deletes an item in the Trash permanently', 'security' => $write('write:'.$type['collection']),
                    'parameters' => [$id, $param('force', 'query', ['type' => 'string', 'enum' => ['true', 'false']], 'Permanently delete (only from the Trash).')], 'responses' => ['204' => ['description' => 'Done']] + $errors(401, 403, 404, 409)],
            ];
            $paths["{$c}/{id}/restore"] = ['post' => ['operationId' => 'restore'.$type['label'], 'tags' => [$tag], 'summary' => 'Bring it back from the Trash (as a draft)', 'security' => $write('write:'.$type['collection']), 'parameters' => [$id], 'responses' => ['200' => $ok($item($schema))] + $errors(401, 403, 404, 409)]];
            $paths["{$c}/{id}/seo-analysis"] = ['get' => ['operationId' => 'analyze'.$type['label'].'Seo', 'tags' => [$tag, 'SEO'], 'summary' => "Arkon's deterministic SEO report (live, or the draft with context=edit)", 'security' => $write('read:'.$type['collection']),
                'parameters' => [$id, $param('context', 'query', ['type' => 'string', 'enum' => ['view', 'edit']], 'edit: analyze the draft.')], 'responses' => ['200' => $ok($item('SeoAnalysis'))] + $errors(401, 403, 404)]];
        }

        foreach (Taxonomies::all() as $name => $taxonomy) {
            $c = '/'.$taxonomy['collection'];
            $tag = $taxonomy['plural'];
            $paths[$c] = [
                'get' => ['operationId' => 'list'.$taxonomy['plural'], 'tags' => [$tag], 'summary' => "List {$taxonomy['plural']} with published counts", 'security' => $public, 'parameters' => [
                    $param('search', 'query', ['type' => 'string'], 'Search name and slug.'), $param('slug', 'query', ['type' => 'string'], 'Exact slug.'),
                    $param('hide_empty', 'query', ['type' => 'string', 'enum' => ['true', 'false']], 'Only terms used by published items.'),
                    ...($taxonomy['hierarchical'] ? [$param('parent', 'query', ['type' => 'string'], 'Parent id, or none for top level.')] : []),
                    $param('sort', 'query', ['type' => 'string', 'enum' => ['name', '-name', 'slug', '-slug', 'count']], 'Default name.'),
                    $pageParams[0], ['name' => 'per_page', 'in' => 'query', 'required' => false, 'description' => 'Default 50, at most 100.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                ], 'responses' => ['200' => $ok($collection('Term'))] + $errors(400, 429)],
                'post' => ['operationId' => 'create'.$taxonomy['label'], 'tags' => [$tag], 'summary' => "Create a {$name}", 'security' => $write('write:'.$taxonomy['collection']),
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref('TermWrite')]]], 'responses' => ['201' => $ok($item('Term'), 'Created')] + $errors(401, 403, 409, 422)],
            ];
            $paths["{$c}/{id}"] = [
                'get' => ['operationId' => 'get'.$taxonomy['label'], 'tags' => [$tag], 'summary' => "One {$name}", 'security' => $public, 'parameters' => [$id], 'responses' => ['200' => $ok($item('Term'))] + $errors(404)],
                'patch' => ['operationId' => 'update'.$taxonomy['label'], 'tags' => [$tag], 'summary' => "Rename or move a {$name} (owners and admins)", 'security' => $write('write:'.$taxonomy['collection']), 'parameters' => [$id],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref('TermWrite')]]], 'responses' => ['200' => $ok($item('Term'))] + $errors(401, 403, 404, 409, 422)],
                'delete' => ['operationId' => 'delete'.$taxonomy['label'], 'tags' => [$tag], 'summary' => "Delete a {$name} (owners and admins)", 'security' => $write('write:'.$taxonomy['collection']), 'parameters' => [$id], 'responses' => ['204' => ['description' => 'Deleted']] + $errors(401, 403, 404)],
            ];
        }

        $paths['/media'] = [
            'get' => ['operationId' => 'listMedia', 'tags' => ['Media'], 'summary' => 'Images live pages use (anonymous) or the whole library (read:media)', 'security' => $public, 'parameters' => [
                $param('search', 'query', ['type' => 'string'], 'Title, file name or alt text.'), $param('mime_type', 'query', ['type' => 'string'], 'e.g. image/jpeg.'),
                $param('status', 'query', ['type' => 'string', 'enum' => ['library', 'trash']], 'read:media only.'), $param('sort', 'query', ['type' => 'string', 'enum' => ['uploaded_at', '-uploaded_at', 'title', '-title', 'filesize', '-filesize']], 'Default -uploaded_at.'),
                ...$pageParams,
            ], 'responses' => ['200' => $ok($collection('Media'))] + $errors(400, 401, 403)],
            'post' => ['operationId' => 'uploadMedia', 'tags' => ['Media'], 'summary' => 'Upload an image (the same pipeline as the admin: validated, original kept, WebP sizes)', 'security' => $write('write:media'),
                'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary'], 'title' => ['type' => 'string'], 'alt' => ['type' => 'string'], 'caption' => ['type' => 'string']]]]]],
                'responses' => ['201' => $ok($item('Media'), 'Created')] + $errors(401, 403, 413, 422)],
        ];
        $paths['/media/{id}'] = [
            'get' => ['operationId' => 'getMedia', 'tags' => ['Media'], 'summary' => 'One image', 'security' => $public, 'parameters' => [$id], 'responses' => ['200' => $ok($item('Media'))] + $errors(404)],
            'patch' => ['operationId' => 'updateMedia', 'tags' => ['Media'], 'summary' => 'Change title, alt text, caption or description', 'security' => $write('write:media'), 'parameters' => [$id],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref('MediaWrite')]]], 'responses' => ['200' => $ok($item('Media'))] + $errors(401, 403, 404, 409, 422)],
            'delete' => ['operationId' => 'trashMedia', 'tags' => ['Media'], 'summary' => 'Move to the Trash (pages that use it keep working)', 'security' => $write('write:media'), 'parameters' => [$id], 'responses' => ['204' => ['description' => 'Done']] + $errors(401, 403, 404)],
        ];
        $paths['/media/{id}/restore'] = ['post' => ['operationId' => 'restoreMedia', 'tags' => ['Media'], 'summary' => 'Bring an image back from the Trash', 'security' => $write('write:media'), 'parameters' => [$id], 'responses' => ['200' => $ok($item('Media'))] + $errors(401, 403, 404)]];

        $paths['/forms'] = ['get' => ['operationId' => 'listForms', 'tags' => ['Forms'], 'summary' => 'All forms with status (read:forms)', 'security' => $write('read:forms'), 'parameters' => [
            $param('search', 'query', ['type' => 'string'], 'Name.'), $param('status', 'query', ['type' => 'string', 'enum' => ['all', 'active', 'draft', 'inactive', 'trash']], 'Default all.'), $pageParams[0],
        ], 'responses' => ['200' => $ok($collection('FormSummary'))] + $errors(401, 403)]];
        $paths['/forms/{id}'] = ['get' => ['operationId' => 'getForm', 'tags' => ['Forms'], 'summary' => 'The definition of a form a live page uses', 'security' => $public, 'parameters' => [$id], 'responses' => ['200' => $ok($item('Form'))] + $errors(404)]];
        $paths['/forms/{id}/submissions'] = ['post' => ['operationId' => 'submitForm', 'tags' => ['Forms'], 'summary' => 'Send a submission (validated, stored encrypted, rate limited)', 'security' => $public, 'parameters' => [$id, $idempotency],
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $ref('SubmissionWrite')]]],
            'responses' => ['201' => $ok($item('SubmissionResult'), 'Received'), '200' => $ok($item('SubmissionResult'), 'Replayed')] + $errors(404, 409, 410, 422, 429)]];
        $paths['/forms/{id}/entries'] = ['get' => ['operationId' => 'listEntries', 'tags' => ['Forms'], 'summary' => 'Entries (personal data: read:form_entries and a role that may read them)', 'security' => $write('read:form_entries'), 'parameters' => [
            $id, $param('status', 'query', ['type' => 'string', 'enum' => ['inbox', 'unread', 'starred', 'spam', 'trash']], 'Default inbox.'), $param('search', 'query', ['type' => 'string'], 'Whole words or an email address.'),
            $param('after', 'query', ['type' => 'string', 'format' => 'date'], 'From.'), $param('before', 'query', ['type' => 'string', 'format' => 'date'], 'To.'), $param('sort', 'query', ['type' => 'string', 'enum' => ['newest', 'oldest']], 'Default newest.'), $pageParams[0],
        ], 'responses' => ['200' => $ok($collection('Entry'))] + $errors(401, 403, 404)]];
        $paths['/forms/{id}/entries/{entry}'] = ['get' => ['operationId' => 'getEntry', 'tags' => ['Forms'], 'summary' => 'One entry', 'security' => $write('read:form_entries'), 'parameters' => [$id, $param('entry', 'path', ['type' => 'string', 'format' => 'uuid'], 'Entry id.')], 'responses' => ['200' => $ok($item('Entry'))] + $errors(401, 403, 404)]];

        $paths['/navigation'] = ['get' => ['operationId' => 'listMenus', 'tags' => ['Navigation'], 'summary' => 'Published menus as trees', 'security' => $public, 'parameters' => $pageParams, 'responses' => ['200' => $ok($collection('Menu'))]]];
        $paths['/navigation/{menu}'] = ['get' => ['operationId' => 'getMenu', 'tags' => ['Navigation'], 'summary' => 'One published menu, by id or location (main)', 'security' => $public, 'parameters' => [$param('menu', 'path', ['type' => 'string'], 'Menu id, or main.')], 'responses' => ['200' => $ok($item('Menu'))] + $errors(404)]];
        $paths['/users'] = ['get' => ['operationId' => 'listUsers', 'tags' => ['Users'], 'summary' => 'Site members (read:users)', 'security' => $write('read:users'), 'parameters' => $pageParams, 'responses' => ['200' => $ok($collection('User'))] + $errors(401, 403)]];
        $paths['/users/{id}'] = ['get' => ['operationId' => 'getUser', 'tags' => ['Users'], 'summary' => 'An author of published content (anyone) or a member (read:users)', 'security' => $public, 'parameters' => [$id], 'responses' => ['200' => $ok($item('User'))] + $errors(404)]];

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Arkon API', 'version' => '1.0.0', 'description' => "Arkon's public developer API. Guide: docs/api.md. Anonymous requests read published content; a personal access token (Authorization: Bearer) adds the scopes it was given, never more than its user's role allows. Errors: {\"error\": {\"code\", \"message\", \"fields\"?}}."],
            'servers' => [['url' => '/api/v1', 'description' => 'The site the API is reached at']],
            'security' => $public,
            'tags' => array_values(array_map(fn ($t) => ['name' => $t], array_unique([...array_column(ContentTypes::all(), 'plural'), ...array_column(Taxonomies::all(), 'plural'), 'Media', 'Forms', 'Navigation', 'Site', 'Users', 'SEO']))),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A personal access token (arkon_pat_…). Scopes: '.implode(', ', array_keys(Scopes::all())).'.']],
                'responses' => ['Error' => ['description' => 'An error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]]],
                'schemas' => $schemas,
            ],
        ];
    }

    private static function schemas(): array
    {
        $ref = fn (string $name) => ['$ref' => "#/components/schemas/{$name}"];
        $string = ['type' => 'string'];
        $nullable = fn (array $s) => [...$s, 'type' => [$s['type'], 'null']];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $object = fn (array $properties, ?array $required = null) => ['type' => 'object', 'required' => $required ?? array_keys($properties), 'properties' => $properties];

        $schemas = [
            'Error' => $object(['error' => $object(['code' => $string, 'message' => $string, 'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => $string]]], ['code', 'message'])]),
            'PaginationMeta' => $object(['page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'total_pages' => ['type' => 'integer']]),
            'PaginationLinks' => $object(['self' => $string, 'first' => $string, 'prev' => $nullable($string), 'next' => $nullable($string), 'last' => $string]),
            'ApiIndex' => $object(['name' => $string, 'version' => $string, 'base_url' => $string, 'openapi' => $string, 'resources' => ['type' => 'array', 'items' => $string], 'scopes' => ['type' => 'object', 'additionalProperties' => $string]]),
            'Site' => $object(['name' => $string, 'description' => $string, 'url' => $string, 'language' => $string, 'timezone' => $string, 'logo' => ['type' => ['string', 'null']], 'favicon' => ['type' => ['string', 'null']], 'organization' => $object(['name' => $string, 'type' => $string])]),
            'Author' => $object(['id' => $string, 'name' => $string]),
            'TermRef' => $object(['id' => $string, 'name' => $string, 'slug' => $string]),
            'Content' => $object(['rendered' => ['type' => 'string', 'description' => 'HTML of the main content (without the shared header and footer); image URLs are absolute.'], 'css' => ['type' => 'string', 'description' => 'The stylesheet that HTML uses.']]),
            'Seo' => $object(['title' => $string, 'description' => $string, 'canonical' => ['type' => ['string', 'null']], 'robots' => $object(['index' => ['type' => 'boolean'], 'follow' => ['type' => 'boolean']]), 'open_graph' => ['type' => 'object', 'additionalProperties' => $string], 'twitter' => ['type' => 'object', 'additionalProperties' => $string]]),
            'MediaSize' => $object(['url' => $string, 'width' => ['type' => 'integer'], 'height' => ['type' => 'integer'], 'mime_type' => $string, 'filesize' => ['type' => 'integer']]),
            'Media' => $object([
                'id' => $string, 'title' => $string, 'filename' => $string, 'mime_type' => $string, 'width' => ['type' => 'integer'], 'height' => ['type' => 'integer'], 'filesize' => ['type' => 'integer'],
                'alt' => $string, 'caption' => $string, 'url' => $string, 'thumbnail' => ['type' => ['object', 'null'], 'properties' => ['url' => $string, 'width' => ['type' => 'integer'], 'height' => ['type' => 'integer']]],
                'variants' => ['type' => 'object', 'description' => 'Responsive WebP sizes keyed by width.', 'additionalProperties' => $ref('MediaSize')],
                'formats' => $object(['webp' => ['type' => 'boolean'], 'avif' => ['type' => 'boolean']]), 'uploaded_at' => $time,
                'public' => ['type' => 'boolean', 'description' => 'read:media only.'], 'status' => ['type' => 'string', 'enum' => ['library', 'trash'], 'description' => 'read:media only.'],
                'description' => ['type' => 'string', 'description' => 'read:media only.'], 'version' => ['type' => 'integer', 'description' => 'read:media only.'],
            ], ['id', 'title', 'filename', 'mime_type', 'width', 'height', 'filesize', 'alt', 'caption', 'url', 'thumbnail', 'variants', 'formats', 'uploaded_at']),
            'MediaWrite' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['title' => $string, 'alt' => $string, 'caption' => $string, 'description' => $string, 'version' => ['type' => 'integer', 'description' => 'The version you read; a newer one is a 409.']]],
            'Term' => ['type' => 'object', 'required' => ['id', 'taxonomy', 'name', 'slug', 'description', 'count'], 'properties' => ['id' => $string, 'taxonomy' => $string, 'name' => $string, 'slug' => $string, 'description' => $string, 'parent' => ['type' => ['string', 'null'], 'description' => 'Hierarchical taxonomies only.'], 'count' => ['type' => 'integer', 'description' => 'Published items using it.']]],
            'TermWrite' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => 'string', 'maxLength' => 100], 'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(-[a-z0-9]+)*$'], 'description' => ['type' => 'string', 'maxLength' => 1000], 'parent' => ['type' => ['string', 'null']]]],
            'PublicBlock' => ['type' => 'object', 'required' => ['type'], 'description' => 'Public block format, schema version '.PublicBlocks::SCHEMA_VERSION.': heading {text, level 1–4}, paragraph {text}, image {media_id, alt, caption}, button {label, href}.', 'properties' => [
                'type' => ['type' => 'string', 'enum' => PublicBlocks::TYPES], 'text' => $string, 'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 4], 'media_id' => $string, 'alt' => $string, 'caption' => $string, 'label' => $string, 'href' => $string,
            ]],
            'SeoWrite' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['title' => ['type' => 'string', 'maxLength' => 120], 'description' => ['type' => 'string', 'maxLength' => 320], 'focusTopic' => ['type' => 'string', 'maxLength' => 120], 'noindex' => ['type' => 'boolean']]],
            'SeoAnalysis' => $object(['context' => ['type' => 'string', 'enum' => ['view', 'edit']], 'score' => ['type' => 'integer'], 'label' => $string, 'checks' => ['type' => 'array', 'items' => $object(['id' => $string, 'category' => $string, 'status' => $string, 'message' => $string, 'weight' => ['type' => 'integer'], 'earned' => ['type' => 'integer']])], 'categories' => ['type' => ['object', 'array']], 'title' => $string, 'description' => $string, 'canonical' => ['type' => ['string', 'null']], 'robots' => $object(['index' => ['type' => 'boolean'], 'follow' => ['type' => 'boolean']]), 'broken_links' => ['type' => 'array', 'items' => $string]]),
            'FormField' => ['type' => 'object', 'required' => ['id', 'type', 'label', 'required', 'choices'], 'properties' => ['id' => $string, 'type' => $string, 'label' => $string, 'required' => ['type' => 'boolean'], 'description' => $string, 'placeholder' => $string, 'default_value' => ['type' => ['string', 'null']], 'choices' => ['type' => 'array', 'items' => $object(['label' => $string, 'value' => $string])], 'row' => $string, 'width' => ['type' => 'integer'], 'condition' => ['type' => ['object', 'null']]]],
            'Form' => $object(['id' => $string, 'version' => ['type' => 'integer'], 'name' => $string, 'description' => $string, 'submit_label' => $string, 'accepting_submissions' => ['type' => 'boolean'], 'fields' => ['type' => 'array', 'items' => $ref('FormField')], 'submit_url' => $string]),
            'FormSummary' => $object(['id' => $string, 'name' => $string, 'status' => $string, 'version' => ['type' => 'integer'], 'published_version' => ['type' => ['integer', 'null']], 'live_version' => ['type' => ['integer', 'null']], 'has_unpublished_changes' => ['type' => 'boolean'], 'entries' => ['type' => ['integer', 'null']], 'updated_at' => $time]),
            'SubmissionWrite' => $object(['fields' => ['type' => 'object', 'description' => 'Values by field id.']]),
            'SubmissionResult' => $object(['form' => $string, 'form_version' => ['type' => 'integer'], 'received' => ['type' => 'boolean'], 'replayed' => ['type' => 'boolean'], 'confirmation' => ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['type' => 'string', 'enum' => ['message', 'redirect']], 'message' => $string, 'url' => $string]]]),
            'Entry' => $object(['id' => $string, 'created_at' => $time, 'status' => $string, 'read' => ['type' => 'boolean'], 'starred' => ['type' => 'boolean'], 'form_version' => ['type' => 'integer'], 'fields' => ['type' => 'array', 'items' => $object(['id' => $string, 'label' => $string, 'type' => $string, 'value' => $string])]]),
            'MenuItem' => ['type' => 'object', 'required' => ['id', 'label', 'type', 'url', 'target'], 'properties' => ['id' => $string, 'label' => $string, 'type' => ['type' => 'string', 'enum' => ['page', 'url', 'section']], 'url' => ['type' => ['string', 'null'], 'description' => 'Live URL; null while its page is not published.'], 'target' => ['type' => ['object', 'null'], 'properties' => ['type' => $string, 'id' => $string]], 'anchor' => $string, 'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/MenuItem']]]],
            'Menu' => $object(['id' => $string, 'name' => $string, 'location' => ['type' => ['string', 'null']], 'version' => ['type' => 'integer'], 'items' => ['type' => 'array', 'items' => $ref('MenuItem')]]),
            'User' => ['type' => 'object', 'required' => ['id', 'name'], 'properties' => ['id' => $string, 'name' => $string, 'role' => ['type' => 'string', 'description' => 'read:users only.']]],
        ];
        foreach (ContentTypes::all() as $kind => $type) {
            $properties = [
                'id' => $string, 'type' => ['type' => 'string', 'enum' => [$kind]], 'title' => $string, 'slug' => $string, 'path' => $string,
                'link' => ['type' => ['string', 'null'], 'description' => 'Absolute URL while published.'], 'status' => ['type' => 'string', 'enum' => ['published', 'draft', 'trash']],
            ];
            $write = ['title' => ['type' => 'string', 'maxLength' => 120], 'slug' => ['type' => 'string', 'description' => 'URL: '.($type['pathPrefix'] ?: '').'/{slug}.'], 'path' => ['type' => 'string', 'description' => 'Or the full URL path.'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'published']], 'content' => $object(['blocks' => ['type' => 'array', 'items' => $ref('PublicBlock')]]), 'seo' => $ref('SeoWrite'),
                'version' => ['type' => 'integer', 'description' => 'PATCH: the draft version you read; a newer one is a 409.']];
            if ($type['details']) {
                $properties['excerpt'] = $string;
                $properties['featured_media'] = ['oneOf' => [$ref('Media'), ['type' => 'null']]];
                $write['excerpt'] = ['type' => 'string', 'maxLength' => 1000];
                $write['featured_media'] = ['type' => ['string', 'null'], 'description' => 'Image id.'];
            }
            foreach ($type['taxonomies'] as $taxonomy) {
                $properties[Taxonomies::get($taxonomy)['collection']] = ['type' => 'array', 'items' => $ref('TermRef')];
                $write[Taxonomies::get($taxonomy)['collection']] = ['type' => 'array', 'items' => $string, 'description' => Taxonomies::get($taxonomy)['label'].' ids.'];
            }
            $required = array_keys($properties);
            $properties += [
                'author' => ['oneOf' => [$ref('Author'), ['type' => 'null']]], 'published_at' => $time, 'modified_at' => $time,
                'version' => ['type' => 'integer', 'description' => 'Members: the draft version.'], 'has_unpublished_changes' => ['type' => ['boolean', 'null'], 'description' => 'Members: the draft differs from what is live.'],
                'content' => ['oneOf' => [$ref('Content'), ['type' => 'null']]], 'seo' => ['oneOf' => [$ref('Seo'), ['type' => 'null']]],
            ];
            $schemas[ucfirst($kind)] = ['type' => 'object', 'required' => [...$required, 'author', 'published_at', 'modified_at'], 'properties' => $properties];
            $schemas[ucfirst($kind).'Write'] = ['type' => 'object', 'additionalProperties' => false, 'properties' => $write];
        }

        return $schemas;
    }
}
