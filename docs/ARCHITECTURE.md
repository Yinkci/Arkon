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
4. **A shared conformance suite.** `tests/Conformance/fixtures.json` holds 103 tricky cases (documents, operations,
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

### Components and the visual builder

| Component | Version | Props (defaults) | Children | Publish checks |
|---|---|---|---|---|
| `page` | 2 | none (SEO lives on the document) | `hero`, `text`, `image`, `button`, `columns`, max 50 | – |
| `hero` | 1 | unchanged | – | heading, image alt |
| `text` | 1 | `text` ("Write something here."), `element` p/h2/h3, `align` start/center | – | not blank |
| `image` | 2 | `image` {assetId, alt} or null, `caption`, `size` full/medium/small | – | has an image; alt text |
| `button` | 1 | `label` ("Learn more"), `href` ("/", type `link`), `style` primary/secondary, `newTab` | – | label; link |
| `columns` | 1 | `stackOn` tablet/mobile (mobile), `gap` small/medium/large (medium) | `column`, min 1, max 4 | – |
| `column` | 1 | none | `text`, `image`, `button`, max 20 | – |

- **page v2** exists only to allow the new children (v1 allowed `hero` only). Its migration is the identity and it
  shares page v1's renderer and (empty) CSS, so v1 publications still reproduce byte for byte and drafts move to
  v2 when opened, like any other version bump. The renderer version did not change: new component styles live in
  each component's own CSS, never in the base stylesheet.
- **image v2** fixes Size inside Columns: v1's CSS (`.ak-column>.ak-image{max-width:none}`) made every size fill the
  column. v2 has the same props (identity migration), the same renderer and markup, and a stylesheet where sizes are
  maximum widths on the page (full = page width, medium 48rem, small 28rem, as in v1) and shares of the column inside
  Columns (100%, 2/3, 2/5). v1 publications keep v1's CSS and reproduce byte for byte; drafts move to v2 when opened.
  The inspector names the actual size for where the image is ("Medium (2/3 of the column)").
- **Nesting is data.** Each manifest's `children` lists allowed types, `max` and (new) `min`. Columns cannot hold
  Columns or a Hero, a Column can only live in Columns, and the last Column cannot be removed. Both validators
  report `childNotAllowed`, `tooManyChildren` and `tooFewChildren`; every structural operation is re-validated on
  the server, so a crafted request cannot create invalid nesting.
- **Safe links.** The `link` prop type accepts only `/path` (not `//`), `#…`, `?…`, `http(s)://host…`, `mailto:` and
  `tel:`, as one ASCII-only regular expression in `rules.json` (`patterns.link`), so PHP and JavaScript agree on
  every character (non-ASCII and invisible characters such as U+00A0 and U+FEFF are rejected; use percent-encoding).
  Backslashes are rejected anywhere: browsers treat `\` as `/` in http(s) URLs, so `/\example.com` would lead
  to another site despite starting with one slash (a policy bypass, not script execution). `%5C` is fine.
  The serializer's URL check still refuses `javascript:` and `//` as a second line of defence. A new-tab button gets
  `rel="noopener noreferrer"`.
- **Tightened rules and recorded content.** `patterns.linkRecorded` keeps the previous, looser link pattern. It is
  accepted only by `validatePinned()` (reproducing a publication, re-rendering a live revision), so publications
  recorded before the change still reproduce byte for byte. Saving and publishing use `validate()` and the current
  pattern.
- **Recovery of drafts stored before a tightening.** A draft that fails `validate()` but passes `validatePinned()`,
  with every issue an unsafe link stored as a string, opens in *recovery* (`PageService::recoveryFor`) instead of
  failing to load: `init.recovery` lists each node, prop and stored value; the first canvas paint and the media list
  use the recorded policy, so the page and its images show as stored. The editor then locks everything else
  (inline editing, inspector, layers, undo, Save, Preview, Publish; status "Needs repair") and shows a repair panel
  with the exact stored values. For each one the user explicitly corrects the link (the new value must pass the
  current rules) or removes the block; "Apply repair" applies all of them as one normal edit, where undo history
  starts, and Save stores it as a new revision through the usual validated save. Nothing is rewritten on load or
  automatically: the stored draft, revisions, publications and the live page stay as they are until the user saves
  and publishes. Drafts invalid in any other way still fail to load (422), and restoring a revision that holds such
  a link is refused, as before.
- **Publish rule `present`** (value is not null) joins `notBlank`, for "Image block has no image".
- **Structure editing** (`resources/js/arkon/editor/structure.ts`) produces only the existing `insertNode`,
  `moveNode` and `removeNode` operations, so saving, batching, retries, history and inverses (undo/redo) work for
  structure exactly as for text. It decides where an added component goes (inside a selected container if allowed,
  else after the selection, else at the end of the page), which drag-and-drop targets are valid (never into its own
  subtree, a container that does not accept it, a full one, or out of a container that would drop below its
  minimum, such as a Columns block's last column), and index adjustment for moves within a parent.
- **Editor UI.** The **Layers** tab has the Add palette, the tree with Move up / Move down / Remove buttons for
  every node (the keyboard and screen-reader path), and HTML5 drag and drop of layers and of palette items that
  shows only valid drop positions. A toolbar above the canvas offers Select parent / Move / Remove for the
  selection. Each component has its own inspector.
- **Unresolved fields.** A Button link is applied to the document only once it is valid, so documents (and saves)
  never contain an unsafe link. Text typed that is not valid yet (`https://`) is an *unresolved field*, held by the
  editor, not the inspector: it survives selection changes; the save status says "1 invalid field not saved"
  instead of "Draft saved"; leaving (reload, closing, in-app links) asks first; and Preview and Publish (button or
  any other path into `publish()`) refuse, select the component and show the field until it is fixed or reverted
  ("Revert link"). Saving valid changes still works. Removing the component or restoring a revision drops it.
  Publish and Preview check again *after* awaiting their save and immediately before sending the publish intent or
  pointing the preview window at the preview (nothing is awaited in between), so input that became unresolved while
  the save was pending also blocks them; the blank preview window is closed.
- **Responsive previews.** Desktop/tablet/mobile previews resize the canvas iframe, so components' real media
  queries apply (Columns stack below 900px or 600px).
- **Editor-only output.** Selection outlines, empty-column hints and the empty-image placeholder are canvas CSS or
  editor-mode markup (`data-ak-*`, `ak-image__empty`); production rendering never emits them, and published pages
  have no scripts.

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

Ported from the reference (§9), with three stricter rules:

- **One write gate per page.** Every writer (save, restore, title/URL, publish, unpublish, delete) takes the draft
  row lock first and only then reads the page row (`PageStore::lockForWrite`). A writer that waited behind a delete
  finds the page deleted (not found) instead of acting on what it read before waiting, and one that waited behind a
  rename records the new title and URL. A delete that waited behind another delete is a no-op. Lock order stays
  draft → path claims and draft → site epoch. (The first port read the page before locking; a publish waiting
  behind a delete could make a deleted page live again.) Public page and media lookups and re-render commits also
  ignore deleted pages, as a second line of defence: deleting removes the live row in the same transaction.
- **Coupled reads come from one snapshot.** What the editor is given together (title and URL, draft document and
  version, live state, history, the first canvas paint) is read in one `REPEATABLE READ, READ ONLY` transaction
  (`PageService::editorInit`, `editorStatus`, `editorState`, `listRevisions`, `renderPreview`, `renderCanvas`). A
  writer that commits in between is entirely visible or not at all, so the editor can never hold old metadata
  with a newer version, and a later title-only change cannot pass the version check while rolling back another
  editor's URL: it gets `STALE_VERSION` instead. Reads take no row locks; writers keep their lock order.
- **A create request key is one create intent.** Pages store an immutable fingerprint of the normalised create
  inputs (`pages.request_fingerprint`). `create` checks the key, takes the path-claim lock, and checks the key
  *again* before checking the URL, so an identical request that waited behind the first is a replay (same page,
  `replayed: true`), not a URL conflict. A retry still means the original inputs after the page is renamed; reusing
  the key with other inputs is a conflict; if the page was deleted since, the retry is refused with "has since been
  deleted" and nothing is created. Pages created before the column existed are backfilled from their first
  revision ("Created page") by a data upgrade; a keyed page without one stays unrecorded and refuses replays.

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
| PHPUnit `LifecycleRaceTest`, `CreateIntentTest`, `ReadConsistencyTest`, `ComponentHistoryTest` | 19 | forced interleavings of delete with waiting publish/save/restore/title/unpublish/delete (both orders), rename with a waiting save, identical and conflicting creates waiting on the path lock; a rename committed in the middle of editor, page-load and preview reads; create-intent replay after rename/delete and its backfill; historical reproduction across component versions |
| PHPUnit `tests/Feature` | pages, requests, page management, media, concurrency, upgrade, runtime safety, HTTP | everything in reference `pages`, `requests`, `page-management`, `media`, `media-access`, `consistency`, `upgrade` and `config` tests, plus the HTTP layer (sign-in, rate limit, no sign-up, JSON envelopes, `{}` fidelity, canvas endpoint, public headers, redirects, preview, media with a real session cookie) |
| Vitest | editor state, operations, conformance, structure, recovery repair, request keys | the reference editor-state tests; TypeScript matches PHP on all 103 fixtures; structure helpers (placement, drop targets, undo/redo of structural changes); request keys without `crypto.randomUUID` |
| PHPUnit `StructuralEditingTest`, `LinkRecoveryTest` | 16 | add/nest/reorder/remove through the save API, the server applying undo inverses, invalid nesting and unsafe links refused, backslash links refused while a publication recorded under the older link policy still reproduces, image v1 publications reproducing while republishing moves to image v2, drafts with one or several stored backslash links (and an image) opening in recovery over HTTP instead of 422, nothing saving or publishing until corrected or removed, the repaired draft saving and publishing normally while the old publication stays live until then and still reproduces, other invalid drafts not opened in recovery, publish of a nested layout as clean semantic HTML with recorded component versions, publish checks of the new components, editor/viewer/outsider permissions, foreign assets, a pre-milestone page v1 publication still reproducing |
| Playwright `e2e/` | 24 | **builder**: palette, layers, move buttons, drag and drop (incl. refused invalid drops, a Columns block's last column, and palette drags), unsafe link refused in the inspector, unresolved link kept across selection, flagged in the status, blocking Preview/Publish and leaving until fixed or reverted (also when it becomes unresolved while the save before Publish or Preview is held: no publish request, no preview navigation), drafts stored with backslash links opening in recovery and returning to normal after an explicit correct/remove repair, image sizes in a column measured in canvas, preview and live page, structural undo/redo, mobile stacking, save/reload/publish clean HTML, structure toolbar, editor role builds but cannot publish; **write flows**, at an insecure origin like Herd's (`http://arkon-e2e.test:8100`, mapped to the PHP server inside Chromium only; asserts `isSecureContext === false` and no `randomUUID`) against `arkonlaravel_e2e`: editor flow with save/publish/upload/restore, typing/undo during slow saves, aborted and lost saves, exact publish retries, Ctrl+S during a slow restore, page create/rename/unpublish/delete, editor role limits |
| Playwright `e2e-herd/` | 1 | **authenticated, non-persisting** smoke test through Herd itself (dev database): sign-in, dashboard, editor canvas, history, member-only preview, clean public responses, insecure-context conditions, and one write request (create with a reserved URL) that generates a request key and is refused before anything is written. It does not save or publish |

- Integration tests use `arkonlaravel_test` as the runtime role; the schema owner only truncates between tests.
  No test wraps work in a transaction, so commits and locks are real.
- **Concurrency is real**: `Tests\Support\Parallel` starts separate PHP processes that call the services at one
  common instant (concurrent saves, duplicate publishes, path races, renames, mixed saves and publishes) and the
  epoch-consistency test holds the epoch lock from a separate connection while publishes queue behind it. Workers
  use a ready barrier, not a startup timer: each boots and connects, reports `READY`, and waits for a go-file
  with the common start instant, so a slow machine (e.g. PHP and browser suites at once) cannot make them late.
- **Forced interleavings** (`Tests\Support\Interleaves`): the worker boots and connects first; the first operation
  then runs in the test process and takes its locks; only then is the worker released, and `PausingTransactions`
  keeps the locks until it sees (via `pg_blocking_pids`) the worker blocked behind it, then commits. The order is
  exact, and the test fails if the worker never waited.
- **Mid-read changes** (`ReadConsistencyTest`): a query listener runs a competing change to completion in a worker
  right after a read has loaded the page row, the worst moment for an inconsistent read.
- **Browser history assertions** use the server's committed revision count as the baseline and wait for the
  editor's asynchronously refreshed list to show it before relying on it.
- **Uncertain outcomes**: `FailingCommitTransactions` runs a transaction completely and then fails to commit.
  In the browser, Playwright delays, aborts, or drops responses after the server committed.
- **Mutation checks done during the port**: rendering before taking the epoch lock fails the consistency tests;
  removing the publication-media backfill fails the upgrade test; letting `save()` run during a restore fails the
  e2e restore test; changing TypeScript string length semantics fails the conformance suite; reading the page
  before the write lock fails the lifecycle race tests; rendering with current component definitions fails the
  history tests; the old `randomUUID` key helper fails the request-key unit test and 12 of the 13 browser write tests;
  the previous read and create code (commit `6e1278c`) fails 8 of the 9 `ReadConsistencyTest`/`CreateIntentTest`
  tests (the remaining one guards against read locks); a history baseline read from the editor's list fails when
  the list's refresh is delayed, while the server-confirmed baseline passes.

## 9. Status

Implemented: everything in the reference foundation and page-management slice (login, CLI accounts, membership
and roles, iframe editor with inline hero editing, inspector, upload, preview, undo/redo, history and restore,
batch saves, safe retries, publish intents, epoch ordering, private media, page create/rename/redirect/unpublish/
delete, clean public HTML), plus publication inputs and versioned component manifests, and the visual builder
(Text, Image, Button, Columns/Column; add, select, edit, remove, reorder by buttons or drag and drop, nesting in
Columns, responsive previews, structural undo/redo).

Deferred: collections and content entries, AI, dependency tables beyond media and the outbox/workers,
design-token editing, editable site settings, autosave, site switcher, member management UI, row-level security.

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
- Recovery covers values a tightened rule explains (today: backslash links). Choices made in the repair panel are not
  kept across a reload (nothing is applied until "Apply repair", so nothing is lost either), and the member preview
  URL of a draft still needing repair answers 400 ("The page could not be rendered") until the repair is saved.
- Unresolved fields exist for Button links only (other fields accept any text up to their limit). They live in the
  open tab: a reload, after the warning, discards them.
- Drag and drop works in the Layers panel, not directly on the canvas (selecting on the canvas works). Touch
  devices use the move buttons, since HTML5 drag and drop has no touch support.
- Links must be ASCII without backslashes (percent-encode other characters). An empty button link renders `href="#"` in drafts;
  publishing requires a link.
- Columns hold Text, Image and Button only (no Hero or nested Columns); at most 4 columns and 20 blocks per column.
- Only the first top-level block's image is loaded eagerly with high priority; nested images are lazy-loaded.
