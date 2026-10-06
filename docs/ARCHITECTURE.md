# Arkon on Laravel: architecture

This is the Laravel port of the Next.js reference project (`C:\Herd\Arkon`, see its `docs/ARCHITECTURE.md`).
The domain model, the operation language, the save/publish protocol and the security model are unchanged; this
document covers what is different and how the port keeps its guarantees. Plan: [MIGRATION_PLAN.md](MIGRATION_PLAN.md).

## 1. Shape of the system

```text
 Browser (admin)                         Laravel (Herd)                              PostgreSQL
 ───────────────                         ──────────────                              ──────────
 Inertia pages (React/TS) ──GET──▶ routes/web.php (session, CSRF, Inertia) ──▶ App\Arkon\Pages, Media ──▶ arkonlaravel
 Editor: local document,  ──JSON─▶ /admin/api/... (same group, JSON errors)        │  authorize → validate →
   TS validation, undo                                                             │  lock → write → audit
 Canvas iframe ◀──postMessage── editor ◀── editor-mode HTML ◀── PageRenderer ◀────┘
                                                                     ▲
 Visitor ──GET /path──▶ routes/public.php (no session, no cookies) ──┘ stored publication HTML (no rendering)
         ──GET /media──▶ MediaController (reads the session cookie only when needed, never sets one)
```

- `routes/web.php`: sign-in, admin pages, the editor's JSON API, preview. `web` middleware group (session, CSRF).
- `routes/public.php`: loaded last and outside the `web` group. `/media/{file}` and a catch-all page route whose
  pattern excludes the reserved first segments (`admin`, `login`, `logout`, `api`, `preview`, `media`, `build`),
  the same list page URLs are validated against. Public responses carry no `Set-Cookie`, no scripts, no Vite or
  Inertia assets, and `Content-Security-Policy: script-src 'none'`.
- Services take a `SiteContext` (site + user) and authorize first, inside their transaction. Ids of other sites
  (and malformed ids) are "not found", never "forbidden".

## 2. One renderer, in PHP (ADR-L1)

The reference rendered the canvas in the browser with the TypeScript renderer and published with the same code.
Porting the renderer twice would give every component two implementations that must stay byte-identical. Instead
`App\Arkon\Renderer\PageRenderer` is the only renderer:

- **Publish** renders production HTML under the epoch lock and stores it with the publication.
- **Preview** renders the saved draft in production mode for members.
- **Canvas**: the editor posts its local, unsaved document to `POST /admin/api/pages/{id}/canvas` and receives
  editor-mode body + CSS (`data-ak-*` annotations, signed image URLs). The first paint comes with the page props.
  One request is in flight at a time; if the document changed meanwhile (any edit, including canvas typing) the
  response is discarded and the latest document is rendered instead, so the canvas never shows an older state.
  Inline typing is never echoed back, so caret and focus are unaffected.

Production output is byte-identical to the reference project's for the same document (`RendererTest`).

## 3. Validation in two languages, rules in one place (ADR-L2)

The editor validates every change locally (undo/redo, inverses, immediate feedback) and the server validates
every save authoritatively. Both must agree exactly, so:

1. **Shared data, not shared prose.** `resources/arkon/rules.json` holds the limits, regular expressions,
   reserved URL segments, SEO fields and every error message. Each component version has a manifest
   (`resources/arkon/components/hero/v1.json`: props, defaults, inline fields, media references, publish checks)
   and a stylesheet. PHP (`App\Arkon\Support\Rules`, `Components\PropSchema`) and TypeScript
   (`resources/js/arkon/rules.ts`, `components/props.ts`) interpret the same files with small twin interpreters.
2. **Identical semantics where languages differ.** String lengths are UTF-16 code units (JavaScript's `length`,
   what `maxlength` counts) on both sides, trimming uses JavaScript's whitespace set, regexes use end-only `$`,
   and integers accept `1.0` on both sides.
3. **JSON fidelity.** PHP normally decodes `{}` and `[]` alike. Request bodies and stored documents are decoded
   with `Support\Json::decode`, which keeps empty objects as `stdClass`, and operations keep that form, so
   `props: {}` and `seo: {}` are never turned into lists and `[]` sent where an object belongs is rejected exactly
   as TypeScript rejects it.
4. **A shared conformance suite.** `tests/Conformance/fixtures.json` holds 82 tricky cases (documents, operations,
   URL paths) with the PHP results; `php tests/Conformance/build.php` regenerates it. PHPUnit fails if PHP's
   behaviour changes; Vitest (`tests/Conformance/conformance.test.ts`) fails unless TypeScript produces exactly the
   same issues, documents, inverses and messages. Verified by mutation: counting code points instead of UTF-16
   units in TypeScript alone fails the suite.

To change a rule: edit the JSON, run `php tests/Conformance/build.php`, review the fixture diff, run both suites.

## 4. Versioned components, publication inputs and reproduction

- **Component versions are immutable.** A props change adds `<type>/v<N+1>.json` (+ `.css`), a renderer registered
  as `type@N+1`, and a migration `type@N` → props of N+1 in `ComponentRegistry::default()`. Old manifests,
  renderers and stylesheets stay registered.
- **Rendering is version-faithful.** `PageRenderer` renders every node with its *own* version's manifest, renderer
  class and CSS. Which versions a document may contain is a validation policy (`DocumentValidator`):
  `validate()` (editing and new publications) requires current versions; `validatePinned()` (reproduction and
  background re-renders) checks each node against its own immutable version. The TypeScript twin uses the same
  per-node definitions, so both languages report out-of-date nodes identically.
- **Editing migrates forward, history is never rewritten.** Drafts and restored revisions are migrated to current
  versions in memory when opened; the next save stores the migrated document as a new revision.
- **A publication's revision is exactly what was rendered.** If publishing renders a draft that had to be migrated
  in memory (its checkpoint revision is stored at older versions), the migrated document is first stored as its own
  revision ("Published with components upgraded to current versions") and the publication points at that one.
- **Publication inputs.** Each publication stores `render_inputs`: renderer version (`PageRenderer::VERSION`),
  the `type@version` of every component used, page title and path, site name and language, and each image's
  metadata. `PageService::reproducePublication()` (and `php artisan arkon:reproduce-publication <id>`) renders the
  revision again with pinned versions and those inputs only, and compares with the stored HTML:
  - `reproduced` with `matches`: byte-for-byte comparison result;
  - `legacy`: `render_inputs` is NULL (published before inputs were recorded, e.g. upgraded data). Not reproducible;
    the stored HTML is the authoritative copy and is never regenerated by guesswork;
  - `unavailable`: the recorded renderer version or a recorded component version is no longer registered, or the
    inputs do not match the revision.
- **Background re-renders keep the published versions.** `prepareRerender` renders the live revision pinned (no
  migration), with the site's current data; a dependency change never silently upgrades published content.
- **Renderer versions.** Any change to output for the same inputs (serializer, head, base stylesheet) needs a new
  `PageRenderer::VERSION`, with the previous behaviour kept selectable so recorded publications still reproduce.
- `ComponentHistoryTest` publishes with hero v1, registers a hypothetical hero v2 (renamed prop, different markup
  and CSS), and proves: the v1 publication reproduces byte for byte (current-version rendering would fail, migrated
  rendering differs); editing and publishing move forward to v2 with the migrated document stored; a re-render
  keeps v1 markup; legacy and unavailable inputs are reported. It fails if the renderer uses current definitions.
## 5. Data model

Same tables as the reference, with Laravel's plural names: `sites`, `site_domains`, `site_members`, `pages`,
`page_drafts`, `page_revisions`, `publications`, `live_pages`, `publication_media`, `redirects`, `media_assets`,
`audit_logs`, `data_upgrades`, plus `users` and `sessions`. Every link between tenant tables is a composite foreign
key including `site_id`. UUIDv7 ids (`Str::uuid7()`).

The migrations follow the reference project's schema history (foundation → request keys and publication media →
data-upgrade bookkeeping → page management), then add `render_inputs`. That keeps a real upgrade path, and
`UpgradeTest` builds a database at the foundation schema with legacy data, upgrades it and proves that live images
stay public, draft-only images stay private, a corrupt cross-site reference links nothing, and the backfill is
recorded once and safe to re-run. The test fails if the backfill is removed.

## 6. Drafts, saves, publishing, page management

Ported from the reference (§9), with one stricter rule:

- **One write gate per page.** Every writer (save, restore, title/URL, publish, unpublish, delete) takes the draft
  row lock first and only then reads the page row (`PageStore::lockForWrite`). A writer that waited behind a delete
  finds the page deleted (not found) instead of acting on what it read before waiting, and one that waited behind a
  rename records the new title and URL. A delete that waited behind another delete is a no-op. Lock order stays
  draft → path claims and draft → site epoch. (The first port read the page before locking; a publish waiting
  behind a delete could make a deleted page live again.) Public page and media lookups and re-render commits also
  ignore deleted pages, as a second line of defence: deleting removes the live row in the same transaction.

- **Saves** carry `baseVersion` and a client `saveKey`; the draft row stores the key and a SHA-256 fingerprint of
  the canonical request. A replay with the same key returns the original result; a different request with that
  key is a conflict; anything else on an old version is `STALE_VERSION` (409).
- **Editor batches** (`resources/js/arkon/editor/state.ts`, unchanged): an in-flight batch is immutable, edits
  made during a save stay pending, an uncertain save is resent with the same key, a rejected batch goes back in
  front of pending edits, one `save()` entry point that does nothing during a restore or title/URL change.
- **Publishing**: replay check, draft lock, replay check again, version check, then the **epoch lock before
  rendering** (`UPDATE sites SET publish_epoch = publish_epoch + 1 … RETURNING`), revision reuse, render, insert
  publication + media, forward-only `live_pages` upsert, redirects, audit. Lock order is always draft → site.
- **Page management**: per-site advisory lock for path claims, draft title/URL changes as revisions, page-targeted
  redirects resolved to the current live path, unpublish checked against the publication the user saw, soft
  delete checked against the draft version. Re-render primitive (`prepareRerender` in a REPEATABLE READ, READ ONLY
  snapshot, `commitRerender` forward-only) is implemented and tested; no job runner calls it yet.

## 7. Security

- **Sign-in**: Laravel session guard, database sessions (`arkon_session`, HttpOnly, SameSite=Lax; set
  `SESSION_SECURE_COOKIE=true` behind HTTPS), session regenerated on sign-in, 5 attempts per email+IP per minute
  plus 30 requests per minute per IP on the endpoint, same-site `next` redirects only. No registration route;
  accounts come from `arkon:owner-create` / `arkon:member-create` (generated password printed once).
- **CSRF**: Laravel's `PreventRequestForgery` (same-origin `Sec-Fetch-Site` or the XSRF token, which the editor sends).
- **Authorization**: `App\Arkon\Sites\Authorizer` in every service (role → permissions in `Sites\Permissions`).
  The same map is registered as Gate abilities (`Gate::allows('page.publish', $siteId)`) for UI hints.
- **Database roles and configuration**: owner/app split, append-only history, `data_upgrades` and `migrations`
  invisible to the runtime role, grants reapplied on every `arkon:migrate`. `.migrate.env` is parsed into a
  connection config, never into the environment. `EnsureSafeRuntime` refuses to serve (503) with privileged
  variables present or an over-privileged role (checked once per 5 minutes, cached). `php artisan migrate`,
  `db:wipe` and `schema:dump` are refused outside `arkon:migrate`.
- **Media**: private until a live publication on the requesting host's site uses it; members see private files;
  the sandboxed canvas uses 2-hour signed URLs (key derived from `APP_KEY`). Uploads: 5 MB, type from magic bytes,
  server-generated names, `nosniff` and a sandbox CSP. The media route reads the session cookie only when the
  anonymous checks fail and never starts a session, so public image responses carry no cookies.
- **Request keys** (save batches, publish intents, creates, title/URL changes) are 128 bits from
  `crypto.getRandomValues`, as 32 hex characters. `crypto.randomUUID` is not used: it only exists in secure
  contexts, and Herd serves `http://arkonlaravel.test`, which is not one.
- **Escaping**: components return IR; the serializer escapes text and attributes and refuses event handlers,
  `script`/`style`/`iframe`, and non-http(s)/relative URLs.

## 8. Tests

| Suite | Count | What it proves |
|---|---|---|
| PHPUnit `tests/Unit` | renderer, conformance, component versions | exact production markup, escaping, editor annotations; PHP side of the conformance fixtures; inverses restore documents; version migration |
| PHPUnit `LifecycleRaceTest`, `ComponentHistoryTest` | 9 | forced interleavings of delete with waiting publish/save/restore/title/unpublish/delete (both orders) and rename with a waiting save; historical reproduction across component versions |
| PHPUnit `tests/Feature` | pages, requests, page management, media, concurrency, upgrade, runtime safety, HTTP | everything in reference `pages`, `requests`, `page-management`, `media`, `media-access`, `consistency`, `upgrade` and `config` tests, plus the HTTP layer (sign-in, rate limit, no sign-up, JSON envelopes, `{}` fidelity, canvas endpoint, public headers, redirects, preview, media with a real session cookie) |
| Vitest | editor state, operations, conformance, request keys | the reference editor-state tests; TypeScript matches PHP on all 82 fixtures; request keys without `crypto.randomUUID` |
| Playwright `e2e/` | 14 | **write flows**, at an insecure origin like Herd's (`http://arkon-e2e.test:8100`, mapped to the PHP server inside Chromium only; asserts `isSecureContext === false` and no `randomUUID`) against `arkonlaravel_e2e`: editor flow with save/publish/upload/restore, typing/undo during slow saves, aborted and lost saves, exact publish retries, Ctrl+S during a slow restore, page create/rename/unpublish/delete, editor role limits |
| Playwright `e2e-herd/` | 1 | **authenticated, non-persisting** smoke test through Herd itself (dev database): sign-in, dashboard, editor canvas, history, member-only preview, clean public responses, insecure-context conditions, and one write request (create with a reserved URL) that generates a request key and is refused before anything is written. It does not save or publish |

- Integration tests use `arkonlaravel_test` as the runtime role; the schema owner only truncates between tests.
  No test wraps work in a transaction, so commits and locks are real.
- **Concurrency is real**: `Tests\Support\Parallel` starts separate PHP processes that call the services at the
  same instant (concurrent saves, duplicate publishes, path races, renames, mixed saves and publishes) and the
  epoch-consistency test holds the epoch lock from a separate connection while publishes queue behind it.
- **Forced interleavings**: `PausingTransactions` runs the first operation in the test process and holds its locks
  until it sees (via `pg_blocking_pids`) the worker process blocked behind it, then commits; the order is exact,
  and the test fails if the interleaving did not happen.
- **Uncertain outcomes**: `FailingCommitTransactions` runs a transaction completely and then fails to commit.
  In the browser, Playwright delays, aborts, or drops responses after the server committed.
- **Mutation checks done during the port**: rendering before taking the epoch lock fails the consistency tests;
  removing the publication-media backfill fails the upgrade test; letting `save()` run during a restore fails the
  e2e restore test; changing TypeScript string length semantics fails the conformance suite; reading the page
  before the write lock fails the lifecycle race tests; rendering with current component definitions fails the
  history tests; the old `randomUUID` key helper fails the request-key unit test and 12 of the 13 browser write tests.

## 9. Status

Implemented: everything in the reference foundation and page-management slice (login, CLI accounts, membership
and roles, iframe editor with inline hero editing, inspector, upload, preview, undo/redo, history and restore,
batch saves, safe retries, publish intents, epoch ordering, private media, page create/rename/redirect/unpublish/
delete, clean public HTML), plus publication inputs and versioned component manifests.

Deferred, as in the reference: collections and content entries, AI, dependency tables beyond media and the
outbox/workers, design-token editing, editable site settings, autosave, drag and drop, site switcher, member
management UI, row-level security.

### Known limitations

- Canvas updates after inspector edits, undo and restore need a request to the server (~tens of ms locally). If
  canvas typing coincides with an in-flight render, the canvas is re-rendered with the latest content and the
  caret may jump once.
- One editor per page: a second editor gets a conflict and must reload. An unconfirmed save survives only in the
  open tab.
- Site name and language are read at publish time and are not a versioned resource yet (reference limitation).
- The admin works on the user's first site membership.
- Write flows are tested in the browser on PHP's built-in server with the e2e database at an insecure `.test`
  origin, not through Herd's nginx (Herd serves only the dev database). Through Herd, coverage is the
  non-persisting smoke test above. The login rate limit shows on the form (Inertia), not as an HTTP 429 page.
- Publications made before render inputs were recorded (only possible with upgraded legacy data) cannot be
  reproduced; their stored HTML is authoritative.
- Published images can stay in browser/CDN caches after removal (reference tradeoff).
- There is no importer from the reference project's database; the two run side by side on separate databases.
