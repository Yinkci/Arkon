# Arkon API (v1)

A REST API for building on a site's content: a headless blog or website, a mobile app, an import or an
integration. The machine-readable reference is the OpenAPI document at `/api/v1/openapi.json`
(source: `resources/api/openapi.json`, generated from the code by `php artisan arkon:openapi`).

## Basics

| | |
|---|---|
| Base URL | `https://<your site>/api/v1`. The API answers at the site's own domains, like its pages; the host selects the site. |
| Format | JSON in, JSON out (`Content-Type: application/json`); media uploads are `multipart/form-data`. |
| Versioning | The version is in the path. Within `v1` changes are additive only (new fields, endpoints, filters). Removing or renaming a field, or changing its meaning, needs `/api/v2`, and `v1` keeps working alongside it. A field to be removed is first marked deprecated in the OpenAPI document. |
| Sessions | None: no cookies, no CSRF. Requests are anonymous or carry a token. |
| CORS | Allowed from any origin (no credentials), so a frontend on another domain can call it directly. |

## Authentication and scopes

Anonymous requests read **published** content: live pages and posts, their categories and tags, the
images live pages use, published navigation, the definition of forms on live pages, and authors of
published content. They can also send form submissions.

Everything else needs a **personal access token**, sent as a header:

```bash
curl -H "Authorization: Bearer arkon_pat_..." "https://example.com/api/v1/posts?status=draft"
```

Create tokens in the admin under **Site management → Developer API**, or on the command line:

```bash
php artisan arkon:api-token create you@example.com --name="Blog frontend" --scopes=read:posts --expires=2027-01-31
php artisan arkon:api-token list you@example.com
php artisan arkon:api-token revoke you@example.com <token id>
```

A token is shown **once** (only a hash is stored). It belongs to one member on one site and stops working at once
when it is revoked, expires, or its member leaves the site. A token for site A is refused at site B.

| Scope | Allows |
|---|---|
| `read:pages`, `read:posts` | drafts, the Trash and the draft state of published items (`status`, `context=edit`), SEO analysis |
| `write:pages`, `write:posts` | create, change, publish, unpublish, move to the Trash, restore, delete from the Trash |
| `write:categories`, `write:tags` | create, rename and delete terms |
| `read:media` / `write:media` | the whole library and its Trash / upload, edit details, move to the Trash, restore |
| `read:forms` | all forms and their status |
| `read:form_entries` | form entries (personal data) |
| `read:users` | the site's members |

**A scope never grants more than the member's role.** Every request is also authorized against the member's current
role: an editor's token with `write:posts` can create and change drafts but gets `403 forbidden` when publishing;
reading form entries needs an owner or admin; renaming or deleting terms needs an owner or admin.

## Responses

```json
{ "data": { "id": "…", "title": "…" } }
```

Collections:

```json
{
  "data": [ … ],
  "meta":  { "page": 2, "per_page": 20, "total": 143, "total_pages": 8 },
  "links": { "self": "…", "first": "…", "prev": "…", "next": "…", "last": "…" }
}
```

Times are ISO 8601 in UTC; ids are UUIDs; URLs are absolute.

## Pagination, filtering, sorting, search

- `page` (from 1) and `per_page` (default 10, at most 100; categories and tags default to 50).
- `sort`: one field, `-` for descending: `sort=-published_at` (the default for posts), `sort=title`.
  Allowed fields are listed per endpoint; anything else is `400 invalid_parameter`.
- Posts: `search` (title and excerpt), `slug`, `path`, `author` (user id), `category` and `tag` (ids or slugs,
  comma-separated: any of them), `after` / `before` (published date), `status` (with a token).
- `include=content,seo` adds rendered content and SEO to collection items (single items always have both).
  Collections leave them out by default to stay small.

## Errors

Every error has one shape and a stable `code`:

```json
{ "error": { "code": "validation_error", "message": "A title is required.", "fields": { "title": ["A title is required."] } } }
```

| Status | `code` | When |
|---|---|---|
| 400 | `invalid_parameter`, `invalid_body` | a bad query parameter, header or body |
| 401 | `unauthenticated`, `invalid_token` | a token is needed / invalid, expired, revoked or for another site |
| 403 | `insufficient_scope` (with `required_scope`), `forbidden` | the token lacks the scope / the member's role does not allow it |
| 404 | `not_found`, `site_not_found` | no such item (or not visible to you), or no site at this address |
| 405 | `method_not_allowed` | |
| 409 | `conflict`, `version_conflict` (with `current_version`) | a duplicate slug or idempotency key, an item not in the Trash / the item changed since you read it |
| 410 | `gone` | a form that stopped accepting submissions |
| 413 | `payload_too_large` | |
| 422 | `validation_error` (with `fields`) | the content is invalid |
| 429 | `rate_limited` (with `Retry-After`) | too many requests |
| 500 | `internal_error` | never with details |

## Caching and rate limits

Anonymous reads carry an `ETag` and `Cache-Control: public, max-age=0, must-revalidate`: send `If-None-Match` and you
get `304` while nothing changed. Token requests are `private, no-store`.

Per minute (configurable in `config/arkon.php`, `api.limits`, or `ARKON_API_LIMIT_*`): 600 per IP before
authentication, 120 anonymous reads per IP, 600 requests per token, 60 writes per token, 10 form submissions per IP
(the forms service adds its own per-form and per-site limits).

## Pages and posts

Pages and posts are the same kind of item (a post is a page of type `post`, with an excerpt, a featured image,
categories and tags). Both have the same endpoints:

```
GET    /pages            GET    /posts
GET    /pages/{id}       GET    /posts/{id}
POST   /pages            POST   /posts
PATCH  /pages/{id}       PATCH  /posts/{id}
DELETE /pages/{id}       DELETE /posts/{id}            (?force=true deletes an item already in the Trash)
POST   /pages/{id}/restore                              POST /posts/{id}/restore
GET    /pages/{id}/seo-analysis                         GET  /posts/{id}/seo-analysis
```

A post:

```json
{
  "id": "01a1…", "type": "post", "title": "Improving Laravel performance", "slug": "improving-laravel-performance",
  "path": "/blog/improving-laravel-performance", "link": "https://example.com/blog/improving-laravel-performance",
  "status": "published", "excerpt": "Practical steps…",
  "featured_media": { "id": "…", "url": "…", "alt": "…", "width": 1600, "height": 900, "variants": { "640": { "url": "…", "width": 640, … } }, … },
  "categories": [ { "id": "…", "name": "Engineering", "slug": "engineering" } ],
  "tags": [ { "id": "…", "name": "Laravel", "slug": "laravel" } ],
  "author": { "id": "…", "name": "Ada" },
  "published_at": "2026-10-10T08:00:00.000Z", "modified_at": "2026-10-11T09:30:00.000Z",
  "content": { "rendered": "<section …>…</section>", "css": "…" },
  "seo": { "title": "…", "description": "…", "canonical": "…", "robots": { "index": true, "follow": true }, "open_graph": { … }, "twitter": { … } }
}
```

- **What anonymous clients see is what is live**: title, URL, excerpt, terms and content come from the published
  version. A draft change shows only after it is published. With a token and `context=edit`, you get the draft state,
  its `version` and `has_unpublished_changes`.
- **`content.rendered`** is the HTML of the page's main area as visitors get it (without the site's shared header and
  footer), with absolute image URLs; `content.css` is the stylesheet it uses. The builder's internal document is never
  exposed: it is an implementation detail and may change.
- **`seo`** is read from the published page's head, so it is exactly what search engines see.
- **Statuses**: `published`, `draft` (never or no longer published), `trash`. Scheduled and private items do not
  exist in Arkon yet.

### Writing content

Write content in the **public block format** (schema version 1). Each block becomes an ordinary, editable builder block:

```json
{
  "title": "Improving Laravel performance",
  "slug": "improving-laravel-performance",
  "status": "draft",
  "excerpt": "Practical steps to make a Laravel application faster.",
  "categories": ["<category id>"],
  "tags": ["<tag id>"],
  "featured_media": "<image id>",
  "seo": { "title": "Improving Laravel performance", "description": "Caching, queries and queues…" },
  "content": { "blocks": [
    { "type": "paragraph", "text": "Slow pages cost users." },
    { "type": "heading", "level": 2, "text": "Cache configuration" },
    { "type": "image", "media_id": "<image id>", "alt": "Server racks", "caption": "" },
    { "type": "button", "label": "Read the docs", "href": "https://laravel.com/docs" }
  ] }
}
```

- Block types: `heading` (`text`, `level` 1–4), `paragraph` (plain `text`), `image` (`media_id` from the library,
  `alt`, `caption`), `button` (`label`, safe `href`: `/path`, `https://…`, `mailto:`, `tel:`, `#anchor`).
  HTML and other types are refused (`422`). Each level-2 heading starts a new section; the title becomes the main
  heading unless the first block is a level-1 heading. For designed layouts, edit the item in the builder.
- `PATCH` changes only the fields you send. `content` replaces the content (keeping the shared header and footer
  and the SEO settings); `status: "published"` publishes, `status: "draft"` takes it offline. Send `version` (from
  your last read) to be told, with `409 version_conflict`, if someone changed the item meanwhile.
- Send an `Idempotency-Key` header on `POST`: a retry with the same key and body returns the first result
  (`200`) instead of creating a duplicate; the same key with another body is a `409`.
- Every write goes through the same rules as the admin: URL uniqueness, validation, revisions (visible and restorable
  in the builder's History), publish checks and the audit log.

## Categories and tags

```
GET    /categories   GET /categories/{id}   POST /categories   PATCH /categories/{id}   DELETE /categories/{id}
GET    /tags         GET /tags/{id}         POST /tags         PATCH /tags/{id}         DELETE /tags/{id}
```

`{ "id", "taxonomy", "name", "slug", "description", "parent" (categories), "count" }`, where `count` is the number of
published items. Filters: `search`, `slug`, `hide_empty=true`, `parent=<id|none>` (categories); sort by `name`,
`slug` or `count`.

## Media

```
GET /media   GET /media/{id}   POST /media   PATCH /media/{id}   DELETE /media/{id}   POST /media/{id}/restore
```

Anonymous clients see the images live pages use (images are private until then). Each image has `url` (the original),
`width`, `height`, `mime_type`, `filesize`, `alt`, `caption`, `thumbnail` and `variants`: the responsive WebP sizes
keyed by width, ready for `srcset`. AVIF is not generated (`formats.avif` is `false`). Storage paths are never exposed.

Uploads use the admin's pipeline: type checked from the bytes, size and dimension limits, the original kept, WebP
sizes generated:

```bash
curl -H "Authorization: Bearer $TOKEN" -F "file=@cover.jpg" -F "alt=Server racks" https://example.com/api/v1/media
```

With a token, `public` says whether the URLs already work for visitors. Images you have not published yet are only
viewable by signed-in members.

## Forms

```
GET  /forms                       (read:forms)
GET  /forms/{id}                  the definition: fields, labels, choices, submit label
POST /forms/{id}/submissions      {"fields": {"email": "…", "message": "…"}}
GET  /forms/{id}/entries          (read:form_entries)
GET  /forms/{id}/entries/{entry}  (read:form_entries)
```

A form is public, as on the website, once a live page uses it; until then these return `404`. Submissions go through
the same rules as the website's forms: validation against the published definition, encrypted storage, an optional
`Idempotency-Key`, rate limits and notifications. Notification recipients are never exposed.

## Navigation, site and users

```
GET /navigation            published menus as trees
GET /navigation/{id|main}  one menu, or the site's main menu
GET /site                  name, description, URL, language, timezone
GET /users/{id}            an author of published content ({id, name}); any member with read:users
GET /users                 the site's members (read:users)
```

Menu items: `{ "id", "label", "type": "page|url|section", "url", "target": {"type", "id"} | null, "children": [] }`.
A page link's `url` is where the page is live now, or `null` while it is not published.

## Examples

```js
// The latest posts, with images and categories, for a blog archive.
const res = await fetch('https://example.com/api/v1/posts?per_page=10&page=1');
const { data: posts, meta, links } = await res.json();

// One post by slug, with its rendered content and SEO for the <head>.
const one = await fetch('https://example.com/api/v1/posts?slug=improving-laravel-performance&include=content,seo');
const [post] = (await one.json()).data;
document.title = post.seo.title;

// A draft, with a token (server side only: never ship tokens to browsers).
const draft = await fetch('https://example.com/api/v1/posts?status=draft', {
  headers: { Authorization: `Bearer ${process.env.ARKON_TOKEN}` },
});

// Create a draft post.
await fetch('https://example.com/api/v1/posts', {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.ARKON_TOKEN}`, 'Content-Type': 'application/json', 'Idempotency-Key': crypto.randomUUID() },
  body: JSON.stringify({ title: 'Hello', content: { blocks: [{ type: 'paragraph', text: 'First post.' }] } }),
});
```

```bash
curl "https://example.com/api/v1/posts?category=engineering&sort=-published_at&per_page=5"
curl "https://example.com/api/v1/categories?hide_empty=true"
curl -X PATCH -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
     -d '{"status":"published","version":3}' https://example.com/api/v1/posts/<id>
```

## Not in v1 yet

- Webhooks, OAuth (personal access tokens only), scheduled and private statuses, custom author changes.
- The structured `content.blocks` **output** (blocks are input only; read `content.rendered`).
- Writing navigation menus, forms, site settings or design through the API (the admin and AI proposals do these).
- Signed URLs for unpublished images to token clients.
- Forms that accept API submissions without being placed on a live page.
- Triggering AI generation through the API (see [ai-architecture.md](ai-architecture.md)).
