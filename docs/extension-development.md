# Extending Arkon

Arkon has no plugin framework yet. What follows are the extension points that exist today. Each is a registry, so a
new type does not mean editing a central switch. Register from a service provider's `register()` or `boot()` (before
routes load).

## Content types

```php
use App\Arkon\Content\ContentTypes;

ContentTypes::register('event', [
    'label' => 'Event', 'plural' => 'Events', 'collection' => 'events',
    'pathPrefix' => '/events', 'details' => true, 'taxonomies' => ['category'],
]);
```

The type is stored as `pages.kind` (no migration needed) and immediately gets:

- the public API routes `/api/v1/events…`, documented in the OpenAPI document after `php artisan arkon:openapi`;
- the scopes `read:events` and `write:events`;
- `arkon_list_content` with `type: event`, and a place in `arkon_get_capabilities`.

An admin list screen needs a route (see `/admin/posts` in `routes/web.php`). Fields beyond the post details
(excerpt, featured image) are not supported yet.

## Taxonomies

```php
use App\Arkon\Content\Taxonomies;

Taxonomies::register('topic', ['label' => 'Topic', 'plural' => 'Topics', 'collection' => 'topics', 'hierarchical' => false]);
```

Add it to a content type's `taxonomies`. You get `/api/v1/topics`, the `write:topics` scope and `topic=` filters.

## AI actions

```php
use App\Arkon\Ai\Actions\Action;
use App\Arkon\Ai\Actions\ActionRegistry;

app(ActionRegistry::class)->register(new Action(
    name: 'acme_list_events',
    description: 'List upcoming events: id, title, date.',
    input: ['type' => 'object', 'additionalProperties' => false, 'properties' => ['limit' => ['type' => 'integer']]],
    handler: fn ($ctx, array $input) => app(EventService::class)->upcoming($ctx, $input['limit'] ?? 10),
    readOnly: true,
    permission: 'page.view',
    area: 'events',
));
```

Handlers receive the member's context (via `ai`) and must go through domain services that authorize it. Return
structured data. Never give AI raw SQL, file or shell access.

## Builder components

Components are versioned manifests plus renderers (see [ARCHITECTURE.md](ARCHITECTURE.md) §4) or theme packages
([THEMES.md](THEMES.md)). The AI catalogue and capability map read the same registry.
