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
  the same list page URLs are validated against. Public responses carry no `Set-Cookie`, no Vite or Inertia assets,
  and only conditional public enhancement scripts: a page with entrance animations loads the versioned animation runtime
  (`/_arkon/motion-3.js` for new output, on any page with an entrance; `motion-1.js` and `motion-2.js` stay, on pages
  with "when scrolled into view" entrances only, for publications that recorded them), a static file pinned by
  `integrity`. Its policy is `script-src 'none'`, or exactly that file's URL on this origin for such pages (see
  "Entrance animations"). Slider/back-to-top pages additionally load integrity-pinned components-1.js; its exact URL is added to the same policy.
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
4. **A shared conformance suite.** `tests/Conformance/fixtures.json` holds 143 tricky cases (documents, operations,
   URL paths, design-token sets; 30 of them exercise the styling model) with the PHP results; `php tests/Conformance/build.php` regenerates it. PHPUnit fails if PHP's
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
- `ComponentHistoryTest` publishes with the current hero (v2), registers a hypothetical hero v3 (renamed prop,
  different markup and CSS), and proves: the v2 publication reproduces byte for byte (current-version rendering
  would fail, migrated rendering differs); editing and publishing move forward to v3 with the migrated document
  stored; a re-render keeps v2 markup; legacy and unavailable inputs are reported. It fails if the renderer uses
  current definitions.
- **Development builds and compatibility records.** Four development publications (2026-10-07 12:00–12:01) were
  made by arkon-php-2 before hero v2's parts used the `--ak-basis` flex basis and before direction settings wrote
  it. Their recorded inputs say `arkon-php-2`, which today renders the later behaviour. Instead of changing those
  immutable rows, `PageRenderer::COMPAT_BUILDS` keeps that build selectable (`arkon-php-2-pre-basis`: frozen
  `resources/arkon/compat/hero-v2-pre-basis.css`, no basis from direction settings), for reproduction only. The
  append-only table `publication_render_compat` names the build per publication; `arkon:record-render-compat <id>
  <build>` writes a row only if the recorded version does **not** reproduce the publication and the named build does,
  byte for byte. Reproduction itself stays read-only and uses the recorded build when a row exists. All nine
  development publications reproduce (`RenderCompatTest` covers both behaviours, refusals and append-only grants).
  Later behaviour changes get new component or renderer versions, never edits to a released one.
- **Renderer arkon-php-2** (current) adds design tokens, generated style classes and reusable components to the
  output. `arkon-php-1` (base stylesheet with fixed values, no token variables, no style classes) stays selectable,
  so every publication recorded with it still reproduces byte for byte; it records no shared-resource dependencies.

### Components and the visual builder

Current versions (older versions stay registered, see below and §4):

| Component | Version | Props (besides `style`) | Style slots | Children | Publish checks |
|---|---|---|---|---|---|
| `page` | 5 | none (SEO lives on the document) | root: background, typography | section, hero, text, image, button, columns, group, instance, form, navigation; max 50 | – |
| `section` | 4 | `element` section/div/header/footer/aside, `contentWidth` narrow/default/wide/full; `anchor` (unique section destination) | root: layout, spacing, size, background, border, effects, typography, motion | hero, text, image, button, group, columns, instance, form, navigation; max 30 | – |
| `group` | 4 | `element` div/section/article/aside | root: layout, spacing, size, placement, background, border, effects, motion | text, image, button, group, columns, instance, form, navigation; max 30 | – |
| `hero` | 5 | `heading`, `headingLevel` h1/h2, `text`, `image` {assetId, alt} | root (+ motion), content (text column), heading, text, actions, media | button, max 4 (shown under the text) | heading; descriptive or decorative image alt |
| `text` | 3 | `text`, `element` p/h1/h2/h3/h4 | root: typography, spacing, size, placement, background, border, effects, motion | – | not blank |
| `image` | 6 | `image`, `caption`, `loading` auto/eager/lazy | root (block, + motion), media (the image: size, fit, crop, border, shadow), caption | – | has an image; descriptive or decorative alt |
| `button` | 4 | `label`, `href` (safe link), `variant` primary/secondary/text, `size` small/medium/large, `newTab` | root (alignment, spacing, motion), button | – | label; safe link (# is a placeholder) |
| `columns` | 3 | none | root: layout (column count or proportions), spacing, size, …, motion | column, min 1, max 6 | – |
| `column` | 5 | none | root: layout, spacing, size, …, motion | text, image, button, group, columns, instance, form, navigation; max 30 | – |
| `instance` | 2 | `componentId` (a reusable component of the site) | root: spacing, size, placement, motion | – | component published |
| `fragment` | 3 | none: the root of a reusable component's document | – | section, hero, text, image, button, columns, group, form, navigation; max 30 | – |

Migrations into the current versions (old drafts open at them; publications keep theirs): page 1→2→3→4→5 and
hero 1→2 unchanged props (hero gains an empty children list); text 1→2 `align: center` → `textAlign`;
image 1→2→3 `size` medium/small → `maxWidth` 48rem/28rem; button 1→2 `style` → `variant`; columns 1→2
`stackOn` → `columns: "1"` on that screen, `gap` small/large → `@space.md`/`@space.xl`. The entrance-animation
versions (section 2, group 2, hero 4, text 3, image 4, button 3, columns 3, column 3, instance 2) only add the
`motion` group to the root slot: identity migrations, the same renderer classes and stylesheets (copied, so each
version keeps its own file), so publications of the earlier versions reproduce byte for byte.

- **page v2** exists only to allow the new children (v1 allowed `hero` only). Its migration is the identity and it
  shares page v1's renderer and (empty) CSS, so v1 publications still reproduce byte for byte and drafts move to
  v2 when opened, like any other version bump. The renderer version did not change: new component styles live in
  each component's own CSS, never in the base stylesheet.
- **image v2** fixes Size inside Columns: v1's CSS (`.ak-column>.ak-image{max-width:none}`) made every size fill the
  column. v2 has the same props (identity migration), the same renderer and markup, and a stylesheet where sizes are
  maximum widths on the page (full = page width, medium 48rem, small 28rem, as in v1) and shares of the column inside
  Columns (100%, 2/3, 2/5). v1 publications keep v1's CSS and reproduce byte for byte; drafts move to v2 when opened.
  Image v3 replaces the size presets with design settings (the migration keeps medium/small as max widths).
- **Nesting is data.** Each manifest's `children` lists allowed types, `max` and `min` (current rules in the table
  above): a Column can only live in Columns, sections only at the top level, and the last Column cannot be removed. Both validators
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
  every node (the keyboard and screen-reader path), and dragging of rows and palette items (see "Dragging"). A toolbar above the canvas offers Select parent / Move / Remove for the
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
- **Responsive previews.** Desktop/tablet/mobile resize the canvas iframe, so the page's real media queries apply
  (899 px and 599 px, the same as published pages), and select the screen the design controls edit.
- **Editor-only output.** Selection outlines, empty-column hints and "Add block" targets, the empty-image placeholder
  and animation replay are canvas CSS, editor overlays or editor-mode markup (`data-ak-*`, `ak-image__empty`,
  `ak-replay`); production rendering never emits them. Published pages load only the conditional animation and slider/back-to-top runtimes described below.

### Visual builder foundation: one styling model, layout, shared resources

**One styling model (`style` prop).** Every current component version has a `style` prop:
`{slot: {base?, tablet?, mobile?}}`, each a map of property → value. The manifest declares the component's *slots*
(`root`, `media`, `heading`, …) and which property *groups* each accepts; the properties themselves (42: display,
direction, wrap, column tracks, justify/align, gap, width/min/max, height/min/max, aspect ratio, padding and margin
per side, align-self, font family/size/weight, line height, letter spacing, text align, text colour, background
colour/image/overlay/size/position, border width/style/colour, corner radius, shadow, object fit and crop position)
are defined once in `rules.json` (`style`): CSS property, value kind, units and bounds, keywords, which token groups
they accept, base-only. `App\Arkon\Style\StyleSchema` and `resources/js/arkon/style/schema.ts` validate against it
(conformance fixtures cover every kind and every refusal). Values are strings in an allowlisted format: lengths
in permitted units within bounds (`500px`, `2.5rem`, `60ch`, `80vh`), hex colours, enum names, unitless numbers,
ratios (`16/9`), column counts or 2–6 fraction tracks (`1fr 2fr`), font and shadow presets, token references
(`@color.primary`), and `{assetId}` for background images (site-owned, checked on save like any image). Raw CSS,
`calc()`, `!important`, semicolons or unknown properties are refused in both languages.

**CSS generation.** `App\Arkon\Style\StyleSheet` (PHP only, used by the one renderer) turns each styled slot into a
class `ak-s<hash>` of its declarations: values are re-validated and mapped from the registry (enums → CSS values,
tokens → `var(--ak-t-group-name)`, columns → `minmax(0,…)` tracks, background image → `url("/media/…")` from the
media map), so content never reaches CSS unchecked. Identical styles share one rule. Rules are emitted after the
component stylesheets: all-screens rules, then one `@media (max-width:899px)` block, then one `@media
(max-width:599px)` block, so a smaller screen inherits a larger one unless it sets its own value. Component
stylesheets keep their defaults at zero or single-class specificity (`:where()`), so a generated class always wins.
A `direction` setting also sets `--ak-basis` (0% side by side, auto when stacked) so stacked parts keep a set height.
**Hero v3** (current) marks its content area and image as *sized flex items*: a width set on them also writes
`flex:0 1 auto` (`auto` gives the equal share back), so "Image width" works side by side; in v2 the equal flex share
silently ignored it. v2 output is unchanged (`StyleRenderingTest`). In the editor (never in published HTML) each
part is marked `data-ak-part` so the canvas can select and highlight it.
The page's `<style>` holds the base stylesheet, the CSS of the component versions used and the generated rules; the
`:root` block defines only the token variables that CSS actually uses. Nothing is inline on elements.

**Responsive editing.** Desktop / tablet / mobile in the editor set both the canvas width (≥ 960 px, 820 px, 390 px:
inside the same two media queries the published page uses) and the screen the inspector edits (all screens,
tablet overrides, mobile overrides). Each control shows its own value at that screen in bold, or the inherited
value ("From all screens: 500px"), or "Default"; Reset removes that screen's value (one undo step), and a part can be
reset for a screen at once. Invalid typed values show an error and are never applied. Sensible mobile defaults are
explicit, visible values in the defaults (Hero stacks below 900 px, Columns stack on phones), not hidden CSS.
`resources/js/arkon/style/edit.ts` (unit-tested) holds inheritance and immutable set/reset.

**Layout.** Sections are full-width bands (backgrounds reach the edges) whose content stays within a content width,
without an inner wrapper (the inline padding is computed from the width). Groups are flex or grid containers (stack,
row, wrap, grid with column tracks), Columns are grids with adjustable proportions per screen. Top-level blocks sit
in the centred content column (`.ak-flow`); nested blocks fill their container. Nesting is limited to 8 levels (the
page counts) and 2,000 nodes per document, containers to 30 children (the page 50), Columns to 6 columns.
Accessibility: the DOM order is the Layers order and the reading order; which side something appears on changes only
through `direction` (row-reverse, column-reverse), never by reordering content; headings keep their level (Text
offers h1–h4 as semantics, size is a design setting), informative images use alternative text; current Hero/Image/Logo versions also support decorative empty alt, links keep the safe-link
policy, and keyboard users have Move up/down, Select parent and Layers.

**Dragging.** One lifecycle for every drag source (canvas Move handles, Layers rows, palette items) in both editors:
`resources/js/editor/drag/controller.ts` (`DragController`, one per editor, created by `DragProvider`), the pure
placement model `resources/js/arkon/editor/placement.ts`, and two drop zones (the canvas in `Canvas.tsx`, the
outline in `LayersPanel.tsx`). No library: the needs (sandboxed iframe geometry, document-index slots, revalidation
against the editor's document) are specific, and the whole thing is a few hundred lines.

- *Lifecycle.* Pointer events with capture from the press (mouse, pen and touch; handles and Layers grips are
  `touch-action: none`, so a finger drags them instead of scrolling). A press becomes a drag after 4 px (8 px for
  touch); a press without movement runs the source's own action (selecting the row) and a click on the canvas handle
  does nothing. Escape (also when the canvas iframe has focus: the bridge forwards it), window blur (forwarded from
  the iframe too, ignored when focus only moved into the editor), a hidden tab, `pointercancel`, lost capture,
  unmount, and losing edit rights or a lock (conflict, restore, AI preview, recovery: `setEnabled(false)`) cancel.
  The overlay, preview, cursor class and scheduled work are removed in one place (`cleanup`).
- *One frame loop.* While dragging, one `requestAnimationFrame` loop: automatic scrolling for each zone, then — only
  when the pointer, the scroll position or the geometry changed — the destination is resolved, then indicators and
  the pointer-following preview are positioned by writing styles directly. React state changes only when the
  destination changes (a label, a status pill). Pointer moves never cause canvas renders. Bridge feedback messages are sent only when the destination changes.
- *Geometry, not hit tests.* At the start of a drag the canvas asks the bridge to `measure`: one snapshot of every
  block (rectangle in page coordinates, so scrolling never invalidates it) with each container's real layout (axis
  and reversed order from flex-direction, wrapping rows and multi-track grids, content box). While the drag lasts the
  bridge sends a new snapshot when layout changes (a ResizeObserver; each element observed once per render) and after
  a render. Snapshots carry the drag's session id, a generation (an older one never replaces a newer one) and the
  render token they were taken after. Scrolling is driven by the editor (`scroll-to` with a sequence number; the
  bridge confirms with `scrolled`), so the editor always knows the scroll position its geometry needs.
- *Placement.* `resolveSlot` takes the deepest container around the point that accepts the block, then the gap
  among its children nearest the point (so space and padding between blocks are slots too), mapping visual
  positions to document order for reversed rows and columns and for wrapped rows/grids. Within 14 px of a
  container's own edge the slot beside it in its parent wins (between two sections never needs precision); a 6 px
  hysteresis keeps the slot when the pointer wobbles across a boundary. Validity is `placeAt` (the same nesting
  rules, capacity limits, minimum children and cycle prevention as `dropPlacement`, which now uses it), with refusals
  in words. Layers uses `resolveTreeSlot` (rows; at the end of a group the pointer's indent chooses the level).
- *Release.* The destination is resolved again from the release coordinates, synchronously, from the current
  geometry: an earlier destination is never committed because a reply is pending. Only when the canvas geometry is
  not current (the canvas is re-rendering after a document change) does the release wait, showing "Updating the
  canvas…", for at most 600 ms, then cancel with a notice. The editor's commit checks the document version recorded
  at the start (a change during the drag cancels with "The page changed while you were dragging"), checks the
  placement again with `placeAt` on the current document, and applies exactly one `moveNode` or `insertNode` (one
  undo step, the normal save path; nothing is saved or published by dragging). Cancelled, refused and no-op drags
  change nothing.
- *Stale canvas.* After a drop (or any change from outside the canvas) the editor marks the render pending until the
  renderer's new HTML is shown; canvas handles are disabled meanwhile ("Updating the canvas…"), and drops on the
  canvas wait for the new geometry as above. Both editors render "latest wins": an older render response never
  replaces newer content (the component editor gained this guard in this milestone).
- *Automatic scrolling.* Within 56 px of a zone's visible edge (the iframe clipped by the stage, or the sidebar),
  speed rises with the square of the distance into the band, up to 1,400 px/s at or past the edge, by elapsed time
  (frames capped at 50 ms), clamped to the scroll range; it keeps going while the pointer is still. The canvas scrolls
  vertically in the iframe and sideways in the stage when the desktop canvas is wider than the editor; Layers scrolls
  its sidebar. Scrolling ends with the drag.
- *Feedback.* A pointer-following rendered component preview (script-disabled sandbox), inert Layers/palette previews, the source dimmed in place, an insertion
  line or container outline with its label on the canvas and in Layers, and a status pill (destination, refusal
  reason, "Esc cancels"). Hover shows a small handle on any block's outline; the selected block's handle names
  exactly what moves. All of it is admin-only (editor bundle and the sandboxed bridge); the sandbox has no
  same-origin access, and published pages contain none of it.

**Duplicate.** `resources/js/arkon/editor/duplicate.ts` (`duplicateBlock`, pure) copies a block and its subtree
(`copySubtree`: `structuredClone` of every node, fresh ids, child lists remapped; props, per-screen styles, animation
settings, image `assetId`s and an instance's `componentId` are kept as they are, so a copied instance stays linked
and no reusable component is created) and inserts it right after the original: one `insertNode` (plus the Columns
block's widths for a column), dispatched as one undo step through the editor's normal `structure` path, so saves,
batches, retries, history and locks apply unchanged and no endpoint is needed. Refused with the reason, before
anything changes: the root, a parent that is full or would break the nesting rules (`placeAt`), Columns at 6, more
than 200 nodes in one insert (`limits.insertNodes`), more than 2,000 on the page, or an unresolved field (a link not
applied yet) inside the subtree, which the editor then shows: duplicating would otherwise copy the older valid
value. Entry points (both editors, `editor/blockActions.ts`): the toolbar above the properties, a Layers row button,
a control next to the canvas Move handle, and Ctrl/Cmd+D when a block is selected and focus is not in a text field
(inputs, textareas, selects, inline canvas editing keep the key; the canvas bridge forwards it only when not editing).

**The Columns width contract.** A Columns block's fraction widths give one width per Column, on every screen
(`rules.json` `style.oneWidthPerChild`; `DocumentValidator::widthIssues` and its TypeScript twin, covered by the
conformance fixtures): `1fr 2fr` needs exactly two columns. Counts stay free (`1` to stack on phones, `2` per row,
even more than there are columns), and Group grids are not concerned. It is editing policy only: `validatePinned`
(reproduction, re-renders) does not apply it, so publications made before it reproduce byte for byte. A draft stored
before the rule that breaks it opens in **recovery** (like backslash links): the stored widths are listed per screen,
the user resets that screen to equal widths or removes the block, and nothing is rewritten on load. (Reusable
component drafts have no recovery mode: one that breaks the rule fails to open ("The component is not valid", with the issue). The
development database had none.)
- *Editor.* Every structural edit goes through `withColumnLayouts` (both editors' `structure`, which drops, Layers,
  the toolbar, duplicate and delete use): for each Columns block whose columns it changed, `reconcileColumns` adds one
  `updateProps` to the same undo step. Only the screens whose `columns` value the edit itself changes are left as
  set; any other style change in the same edit (gap, padding, background, animation …) does not count as widths. Widths follow their columns
  (reorder, remove, duplicate); a column moved in from another block, or new empty columns, reset that screen to
  equal, with a notice. The inspector's custom widths are checked with the same rule before anything is applied.
- *AI.* The compiler applies the whole reply first, then reconciles every Columns block whose columns changed
  (`App\Arkon\Components\ColumnLayout::changes`, the PHP twin; copied columns take their original's width). A
  screen counts as set by the reply only if the reply has a `columns` setting for it (a value, or null to reset):
  changing the gap, padding, background or animation never exempts the widths, and setting phones to stack never
  exempts base or tablet widths. Both languages pass the same reconciliation cases
  (`tests/Conformance/column-layouts.json`). Each reconciliation is one more operation in the reviewed proposal, with a description
  ("Columns widths on all screens went back to equal: 2 widths no longer fit 3 columns"), so the reviewed and
  applied operations are identical. Intermediate counts are never judged: only the final document is validated, so
  adding columns and setting final widths in one reply works, and widths that don't fit the final columns are refused
  with the rule (one repair run on the helper path, a tool error over MCP).

**Deleting the selection.** One decision (`resources/js/arkon/editor/remove.ts`, `removalOf`) and one action
(`useBlockActions().remove`) for every entry point: the canvas control next to Duplicate and Move (trash icon, named
for what it deletes, with a tooltip), the toolbar above the properties, the Layers row button, and Delete/Backspace
when focus is in the selection interface (`data-selection-scope`: the canvas stage, Layers, the toolbar; in the canvas
iframe the bridge sends it only when no text is being edited). Text fields, inline editing, dialogs and the rest of the
page keep their keys.
- *What goes.* The block and its subtree through `removeNode` (a column through `removeColumn`, so its width goes
  too), as one undo step through the normal path. With only a hero's image selected, the image is cleared
  (`updateProps image: null`) and labelled "Remove image": the hero stays. Labels name the block ("Delete button",
  "Delete hero section and its contents"). Refused with the reason: the root, a Columns block's last column.
- *Asking.* Blocks with content inside (empty Column slots don't count) open a confirmation naming the block and the
  number of blocks inside; Cancel or Escape changes nothing, and the deletion is re-checked against the document when
  confirmed. Other blocks go at once, with an **Undo delete** action in the notice. It is bound to the history entry
  the deletion made (entries carry a stable id, kept by coalescing, undo and redo; `canUndoEntry`), not to a
  position in the history: it works only while that entry is exactly what Undo would undo, and both editors drop the
  action as soon as it isn't (after another edit, an undo, a branch, a restore or the 200-entry cap). The same holds
  for "Remove image".
- *Afterwards.* The next block is selected (else the previous one, else the parent). A drag in progress that moves or
  targets a removed block is cancelled, an animation replay of it is dropped, and unresolved input inside it goes with
  it (as for any removal). Nothing is saved or published by deleting; permissions, recovery, conflicts, restores and AI
  previews hide the canvas control and make the key do nothing.

**Column layouts.** `resources/js/arkon/editor/columns.ts` is the one place where Columns change shape: the picker
presets (`createColumns`: the Column nodes and the base `columns` widths together), `setColumnCount` (more: empty
columns appended; fewer: the blocks of the removed columns move, in order, to the end of the last column kept, or are
deleted only when the user chooses so in the confirmation; refused when the target column would exceed its 30
blocks), `duplicateColumn` and `removeColumn`/`removeOps` (every Remove of a column). Each returns structural
operations plus the reconciled `style` of the Columns block (`reconcileColumns`, by a mapping from new to old
columns): proportions follow their columns when every new column came from an old one (duplicate, remove);
otherwise a screen whose proportions no longer fit goes back to equal and the panel says which; an "all equal"
count follows the new number; a smaller count (wrapping, or `1` for stacking) stays while it fits. The structural
count is global; tablet and mobile layouts only set `columns` on that screen (stacked by default on phones) and
never add or remove columns. The AI compiler's duplicate uses the same reconciliation (`App\Arkon\Ai\Duplicates`,
tested against the same cases). Empty columns get an editor-only **Add block** target on the canvas (the bridge
reports their rectangles; the menu offers what a column accepts).

**Entrance animations.** Stored as ordinary, validated design settings in the root slot of the current block
versions (group `motion` in `rules.json`): `animation` (none, fade, fade-up/-down/-left/-right, zoom; per screen,
so `mobile: none` turns it off on phones), and for all screens only `animationTrigger` (load, view),
`animationDuration` (150–4000 ms) and `animationDelay` (0–2000 ms) as a new `time` kind (`^[0-9]{1,4}ms$`, checked
against bounds), and `animationEasing` (four named curves). PHP and TypeScript validate them with the same registry
(conformance fixtures cover every preset, bound, unit, base-only rule and refusal); there is no field for CSS,
keyframes, selectors or script, and the enum values map to fixed keyframe names.
- *Rendering.* `StyleSheet::motionClassFor` writes the motion settings as their own class (`ak-m<hash>`:
  `animation-name`, duration, delay, timing function per screen), kept out of the slot's ordinary class so the
  renderer can leave it off. `PageRenderer` adds `ak-anim` (plus `ak-reveal` for "view") and that class to the
  block's root element, and appends the runtime's CSS (`App\Arkon\Renderer\Motion`): six keyframes of opacity and
  transform only, defaults through `:where(.ak-anim)`, `body{overflow-x:clip}` so sideways entrances never scroll
  the page, and `prefers-reduced-motion` and print rules that remove animations. Pages without animations get none of
  this (their output is unchanged).
- *Never the likely LCP (protected content).* A block is not animated when it is, or holds, an image that got
  `fetchpriority="high"` (`RenderContext::imageAttributes` reports it) or the page's first h1 (a text h1 or a hero
  with an h1 heading): a container around it would hide or delay it too. The report lists every such block of the
  page with the reason and the node responsible (`report.motion.protected`: `{reason: image | heading, cause}`, and
  `suppressed` for the ones that have settings); the canvas response carries `protected`, so the inspector says it
  before any effect is chosen. This policy is unchanged from motion-1 (it never changed output). A reusable
  component's own canvas does not apply it (it depends on the page).
- *Every entrance starts with the page (motion-2, and motion-3, current).* The generated class plays the entrance in CSS from the
  first paint, for "on page load" and "when scrolled into view" alike, so content on screen at load plays its
  entrance (motion-1 showed it without one), no script is needed for that, and without JavaScript (off, blocked,
  failed, no IntersectionObserver or `getAnimations`) every entrance plays with the page and ends shown. Delay plus
  duration is at most 6 s, so nothing stays hidden.
- *The runtime.* motion-3 loads the deferred `public/_arkon/motion-3.js` (2.5 KB, sha384 `integrity` checked by
  tests and the browser) on every page with an entrance; motion-1 and motion-2 loaded theirs only for "when scrolled
  into view". It does nothing under reduced motion. Otherwise, once, it looks at the `.ak-reveal` blocks. Those on screen are left alone (their entrance is already playing). Below the fold, a block
  whose entrance has not finished, so nobody has seen it, is held back (`.ak-anim.ak-wait`: no animation, opacity 0),
  and one shared IntersectionObserver lets each play once, from the start, when it comes into view. A finished
  entrance (a runtime that ran late) or a block with no animation on this screen (a phone override) is never held
  back: painted content is never hidden again. Reduced motion and printing show the final state
  (`animation:none; opacity:1`). The observer is disconnected when nothing is held back any more. No timers,
  polling, scroll handlers, frame loops or per-frame layout reads (one `getBoundingClientRect` and `getAnimations`
  per block, once).
- *Keyboard focus (motion-3).* Focus anywhere inside an animated block shows the focused content and every animated
  block around it at once, whatever the trigger, whether the block is on screen or held back, during its delay or
  while it plays. CSS does it without a script: `.ak-anim:focus-within{opacity:1!important}` (an `!important`
  declaration wins over a running animation without restarting it, so focus leaving doesn't replay anything). The
  runtime makes it last: its `focusin` listener (capture, kept for the visit, it only walks the focused element's
  ancestors) adds `.ak-shown` (animation ended, opacity 1) to each of those blocks and releases held-back ones, so
  content seen once stays shown after focus moves on; content focused before the script ran is handled at start.
  That is why motion-3 loads its runtime on load-only pages too. motion-2 revealed only held-back blocks, so a link
  focused inside an on-screen entrance stayed invisible during its delay (kept as it was for its publications).
- *Script policy.* Pages without an entrance (and, from motion-1 and motion-2, load-only pages): `script-src
  'none'`. A page whose stored HTML contains the runtime's
  exact tag (and whose `render_inputs.motion` names it): `script-src <origin>/_arkon/motion-N.js` of that runtime,
  never `'self'`, `'unsafe-inline'` or `'unsafe-eval'`. The member preview uses the same rule. Editor assets, Inertia,
  React and third-party scripts stay forbidden; components still cannot emit `<script>`.
- *Reproduction.* Publications with animations record `render_inputs.motion` (the CSS and the script together).
  `motion-1` (paused reveal classes, `motion-1.js`, its CSS) and `motion-2` (its CSS, `motion-2.js`, script on
  "view" pages only) stay registered byte for byte, so publications that recorded them reproduce exactly (the
  development database's About Us publications among them) and are served with their own script policy; new output
  records `motion-3`. A runtime file never changes: a fix ships as the next version. Reproduction refuses a missing
  or unknown runtime ("unavailable").
- *Editor.* The canvas gets the editor variant of the CSS: `.ak-anim:not(.ak-replay)` has no animation, so editing,
  inline typing, server redraws and drag geometry always see the final state. The Animation section always edits the
  root slot ("animates the whole block") and shows the **effective status** next to the effect: plays (on load, or
  when it comes into view: on load if already on screen), off on this screen (a tablet or phone override), reduced
  motion (the user's own system setting: previews show the final state), or **protected content**, naming the image
  or heading responsible with a link to it. The effect of a protected block can't be chosen (stored settings are
  kept and shown; "Remove the stored entrance" deletes them only when asked). For a protected container with a stored
  entrance, **Move this entrance to the other blocks inside** (`animateInsteadTargets`, `animateInsteadOps`) gives
  the same settings to the blocks that can play it and removes the container's, in one undo step and without
  wrappers. Targets are judged against the page's whole protection map from the last render, not only the cause the
  status names: never a protected block (the image that loads first, the main heading, any block holding either, a
  reusable component holding one), protected containers are looked into (a reusable component is not), and blocks
  with their own entrance keep it. When nothing eligible is left, no move is offered.
- *Previews.* Choosing or changing an eligible setting (effect, trigger, duration, delay, easing, phones) previews
  the block once the canvas shows the render of exactly that document version (`replay {nodeId, seq, version}`):
  a newer change asks again (latest wins, also with slow renders), any other edit (typing, structural edits),
  selecting another block, a drag or a read-only state drops it, and the bridge stops a running preview on a
  pointer press, key press, selection change, render or drag measurement. **Preview animation** plays it again.
  Nothing is previewed when it would not play on this screen, under reduced motion, or for protected content. Both
  editors share this.

**Design tokens.** Fixed slots (`rules.json` `tokens`): 8 colours, 2 fonts (system stacks: sans, serif, mono,
rounded, plus locally served Inter), 6 type sizes, 6 spaces, 3 container widths, 4 radii, 3 shadows. `site_token_sets` holds the
site's editable draft (optimistic version, idempotent save key); publishing (`TokenService::publish`, publishers
only, idempotent per key) stores an immutable `site_token_versions` row and takes the site's epoch lock. Pages,
previews and the canvas always render with the *published* version (`DesignResources`), so a draft can never reach a
live page. The legacy CSS variables (`--ak-color-primary`, …) are aliases of token variables in the arkon-php-2 base
stylesheet, so every component follows the site's tokens. Publications record `tokens: {version, values}`.

**Reusable components.** `reusable_components` holds a named fragment document (same nodes, props and validation as a
page, root type `fragment`, no instances inside) with a draft and a version; `reusable_component_versions` holds
immutable published versions. A page places one with an `instance` block, which renders the component's latest
*published* version (unpublished: a placeholder in the editor, and publishing the page is blocked). Override rule:
an instance may set its own placement (spacing, size, alignment); the content belongs to the component. *Detach*
replaces the instance with a copy of the published blocks (one undoable edit; the page then owns them). "Make
reusable" turns a selected block into a component (published as v1 when the user may publish) and leaves a linked
instance in its place. The component editor reuses the canvas, layers, inspector and undo model.

**Published dependencies and refresh.** Every publication rendered with arkon-php-2 records `publication_dependencies`
(token version; each component id and version), and its `render_inputs` record the same, so
`reproducePublication` renders with the recorded token values and the recorded (immutable) component versions.
Publishing tokens or a component, in its transaction and under the epoch lock, inserts one `page_refreshes` row per
live page whose *live* publication depends on it. `PageRefreshes::run` (after the publish commits, bounded; also
`arkon:refresh-pages` and Retry on the Design page) claims rows with `FOR UPDATE SKIP LOCKED` and re-renders each page's
live revision (never its draft) with what is published at that moment through `prepareRerender`/`commitRerender`: one
REPEATABLE READ snapshot, an idempotency key per page and epoch, and a live-pointer update only to a newer epoch, so a
page published meanwhile is never rolled back and retries never publish twice. Status: pending, done, skipped (no
longer live or deleted) or failed (error kept; retried up to 3 times automatically, then on request). Pages published
before tokens existed (arkon-php-1) depend on nothing and are never refreshed: they stay exactly as published until
someone publishes them again. Draft-only media of a component becomes public only when a live page uses it.

**Images.** On upload (and `arkon:media-variants` for older images) GD makes WebP copies at 320–1920 px below the
original's width (and at its width, when that is small enough), kept only if smaller than the original; GIFs and
very large images are left alone; the original is never changed. Variants (`media_variants`, append-only) are
delivered under exactly the original's policy (`resolveAccess` maps a variant key to its asset), so private images
stay private in every size, and the canvas gets signed URLs for each. Image and Hero render `srcset` + `sizes`
(estimated from the block's share of the content width), intrinsic `width`/`height` (no layout shift), and
`fetchpriority="high"` without lazy loading for the first image within the first two top-level blocks only; every
other image is `loading="lazy"`; Image's `loading` prop can force either. AVIF is not generated (encoding cost).

## 5. Data model

Same tables as the reference, with Laravel's plural names: `sites`, `site_domains`, `site_members`, `pages`,
`page_drafts`, `page_revisions`, `publications`, `live_pages`, `publication_media`, `redirects`, `media_assets`,
`audit_logs`, `data_upgrades`, plus `users` and `sessions`, and (Laravel only) `ai_proposals`, `ai_connections`,
`site_token_sets`, `site_token_versions`, `reusable_components`, `reusable_component_versions`,
`publication_dependencies`, `page_refreshes` and `media_variants` (the version, dependency and variant tables are
append-only for the runtime role). Every link between tenant tables is a composite foreign
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

### AI page proposals (ADR-L3): Claude Code on the user's subscription

A prompt never edits the page. It produces a **proposal**: a list of the editor's own operations (`insertNode`,
`updateProps`, `moveNode`, `removeNode`) based on one saved draft version, which the user previews in the editor and
then applies or discards. Applying is a normal save; publishing stays the separate Publish action.

Claude runs as **Claude Code under the user's own Claude subscription** on their computer. There is no API key, no
paid API path and no Anthropic SDK; Arkon never reads, stores or exports Claude's login. Two entry points, one backend:

```
 VS Code (Claude Code chat)                         Arkon editor, AI tab
          │ MCP over stdio                                  │ POST …/ai/requests (answers at once: queued)
  php artisan arkon:mcp (token: user+site)                  ▼
          │ arkon_submit_proposal                   ai_proposals (queued) ◄── poll GET …/ai/requests[/id]
          ▼                                                 │ claimed with a lease
  ProposalService::submit ──┐                php artisan arkon:ai-helper (token: site) ── claude -p (CLI)
                            ▼                                 │
             ProposalCompiler (registry schema, validation)  ◄┘ ProposalService::execute
                            ▼
             ai_proposals (proposed | empty) ── preview ── Apply (save with proposalId) | Discard
```

- **Requesting, executing, validating and reviewing are separate.** `ProposalService::request` only records a panel
  request (`queued`); `claimNext`/`execute` run it in the helper; `submit` records an MCP proposal; `list`/`status`/
  `cancel`/`discard` serve the editor. Both entry points go through `ProposalCompiler` against the draft version the
  proposal claims, and produce the same rows, previews, change descriptions and apply path.
- **The contract comes from the registry.** `ProposalSchema` builds the JSON schema and the catalogue text from the
  current component manifests (types, versions, props, enums, limits, defaults, nesting, style slots) and the style
  registry (every design property with its accepted values and ranges, the token slots); `ProposalPrompt` adds the
  rules and the page context (site name, page title/path, blocks, ids/alt/size of images already on the page, the
  site's published tokens and published reusable components). Both entry points get exactly this.
- **Builder capabilities in proposals.** A `duplicate {id, ref}` change copies a block exactly like the editor
  (`Duplicates`: fresh ids, everything kept, a copied column's width copied too); its `ref` names the copy for later
  updates, and a block added or copied in the same proposal is described as "new". Columns are a `columns` block
  with one `column` per column (the instructions say so, with proportions as the `columns` setting and phones
  stacking by default). Entrance animations are the `motion` settings of the catalogue (bounds included), with
  guidance to use "view" further down and never animate the first block, main heading or first image.
- **Design in proposals.** A style prop is a flat list `{slot, screen, property, value}` in the schema; the compiler
  merges it into the block's defaults (new blocks) or its current style (updates), so a follow-up changes one setting
  and keeps the others (`null` removes one), then validates it exactly like a save. Blocks are added one at a time;
  an add may name its new block (`ref`) so later adds go inside it (`parent: "new:<ref>"`): the schema stays flat
  (about 12 KB, within the Claude Code command line) whatever the nesting depth. Instances may only use published
  components that were listed. Site-wide token changes are a separate `tokenChanges` list: shown apart in the AI
  panel, never part of "Apply to draft", applied once by "Apply to the token draft" (one transaction with the proposal's applied marker, under the proposal row lock; only if the token draft is still the version the proposal was made against; an exact retry returns the original result; audited) and published only from
  the Design page.
- **Untrusted output.** Whatever Claude returns (CLI `structured_output` or an MCP argument) is compiled: new ids,
  current versions and defaults, each change applied in order to the base draft, then the same validation as a save
  (types, props, nesting, link policy), only images already on the page, plain text, at most 40 changes. A helper run
  gets one repair run with the problems listed; an MCP submission returns the problems as a tool error and records
  nothing. Publish checks become warnings. "What will change" is described by the server from the operations.
- **Applying.** The editor dispatches the operations as one undo step and saves them as one batch with the proposal
  id; inside the save transaction (after the version check) the proposal must be the user's own, `proposed`, based on
  that version, with an identical operations fingerprint (`STALE_PROPOSAL` otherwise; the editor then stops instead of
  saving them as a plain edit). The revision gets `source = 'ai'` and "AI: <summary>".
- **Durable requests, no long web requests.** `ai_proposals` is the request record: `queued → running → proposed |
  empty | failed | cancelled`, then `applied | discarded`. The panel polls (1.5 s while active, 5 s otherwise).
- **Request keys and atomic creation.** A per-site advisory lock is taken *before* the request-key lookup, so identical
  concurrent requests replay the same row (previously: lookup outside the lock → PostgreSQL 23505). A key is bound to
  its page, prompt and base version (and for MCP the proposal itself) by `request_fingerprint`; the same key with a
  changed payload is refused (`CONFLICT`). A retried request (lost acknowledgement) never queues a second run.
  An MCP submission is first authorised and matched against an existing key, *before* any current-draft check: an
  exact retry returns the original proposal id with its current status (proposed, applied, discarded…) even after
  manual edits or after it was applied, without a new row or a second application. Only new submissions are
  validated and compiled against the current draft; the lookup is repeated under the site lock when inserting.
- **Leases and recovery.** A helper claims the oldest queued request of its site with one
  `UPDATE … WHERE id = (SELECT … FOR UPDATE SKIP LOCKED)`, only while its connection is unrevoked and its user may
  edit the site; it gets a fresh lease token and a 45 s lease that it renews while Claude works. Renewing and admitting
  a result or failure are each one `UPDATE` that requires, against the database clock: this lease token, status
  `running`, `lease_expires_at > now()`, an unrevoked connection whose user may still edit, and a requester who may
  still edit. So a cancelled, superseded, expired (even before `recover()` has run), taken-over, revoked or
  de-authorised run can neither revive its lease nor land a late result, even if the runner ignores the request to
  stop; the runner itself stops at its next check (every 2 s) because the renewal fails. Revoking a connection
  fences its running requests in the same transaction (lease cleared, back in the queue). An expired lease
  (`lease_expires_at <= now()`) is recovered: back in the queue while attempts remain (the next claim gets a new
  token), failing after 2 (`AI_INTERRUPTED`). Requests nobody picks up within 5 minutes fail (`AI_EXPIRED`). A newer
  panel request for the same page supersedes the user's waiting one. Cancel stops the running CLI process. The
  requester always stays the proposal's owner.
- **Limits.** Requests per user per minute, per site per day, and queued+running per site; a run timeout (240 s); one
  repair run; two attempts. These bound what Arkon starts. Claude Code enforces the subscription's own usage limits;
  Arkon does not know the remaining allowance and makes no monetary claims. (The token budget of the removed API
  path is gone; `input_tokens`/`output_tokens` columns stay for the old rows.)
- **The helper and the CLI** (`AiHelper`, `ClaudeCodeCli`). Started by the user (`php artisan arkon:ai-helper`) under
  their Windows account; it reports readiness (Claude Code version, auth mode, plan; never account details or tokens)
  to `ai_connections.status`, which the panel shows. It refuses Claude Code older than 2.1.259 and any login other
  than `authMethod: claude.ai` (`claude auth status --json`), so API-key/Console billing is never used. A run is
  `claude -p --output-format json --json-schema <schema> --system-prompt-file <file> --tools "" --restricted
  --strict-mcp-config --disallowedTools "mcp__*" --permission-mode dontAsk --permission-prompts none
  --no-session-persistence --disable-slash-commands --max-turns 4` (verified against the CLI reference and
  2.1.289/2.1.292; not `--bare`, which never reads the subscription login). The process is started directly with
  `proc_open` and an argument array (no `cmd.exe`: Symfony Process's Windows quoting corrupted the JSON schema), with
  the prompt on stdin from a file, an empty temporary working directory, and an allow-listed environment (PATH, user
  profile and temp folders, `CLAUDE_CONFIG_DIR`; no `DB_*`, `APP_KEY`, `ANTHROPIC_*`, OAuth tokens or `ARKON_*`).
  Claude Code's failure text is mapped to `CLAUDE_MISSING | _OUTDATED | _NOT_LOGGED_IN | _BILLING_MODE | _LIMIT |
  _TIMEOUT | _FAILED`.
- **The MCP server** (`Mcp\McpServer`, `php artisan arkon:mcp`): newline-delimited JSON-RPC 2.0 over stdio
  (`initialize`, `ping`, `tools/list`, `tools/call`; protocol versions 2024-11-05 … 2025-11-25). Tools:
  `arkon_list_pages`, `arkon_get_page`, `arkon_get_proposal_format`, `arkon_submit_proposal`,
  `arkon_get_proposal_status`. No SQL, shell, file, apply or publish tools. Domain errors are tool errors the model
  can act on.
- **Connections** (`ai_connections`, `AiConnections`): revocable tokens of kind `helper` or `mcp`, each bound to one
  user and one site, created by `php artisan arkon:ai-pair` (shown once; only a SHA-256 hash is stored), revoked with
  `arkon:ai-revoke`. The token is the only identity: tools take no user or site ids, every call is authorised again
  against the user's current role (`page.view` to read, `page.edit` to submit), and a revoked token or a removed
  membership stops working immediately. Revoking a helper also fences the request it is running (back in the
  queue for another helper; its late result is rejected). The helper serves only its own site's requests.
- **Permissions:** `page.edit` (owners, admins, editors) to ask, submit, apply or discard; viewers get 403, other sites
  404; a request can only be seen, applied or discarded by its creator.

### Trash

Pages, forms and images share one Trash model built on their existing soft-delete columns (`pages.deleted_at`,
`site_forms.archived_at`, `media_assets.archived_at`); no extra soft-delete layer. Moving to the Trash goes through the
same service methods as before (a page goes offline and frees its URL; a form on a live page is refused; an image keeps
working where it is used). Restore brings back the same id with everything attached; a page is refused while another
page uses its URL. **Delete permanently** sets `purged_at` (migration `2026_10_19_000001_trash`): the item leaves the
Trash for good, while append-only history (revisions, publications, form versions, media variants) stays, so old
publications still reproduce. A form's entries are deleted with it; images have no permanent deletion yet. Nothing
expires automatically. `POST /admin/api/{pages,forms,media}/bulk` (`Support\Bulk`) applies one action to up to 100 items,
each through the single-item method in its own transaction, reporting `done` and `failed` per item; a missing permission
fails the whole request.

Database sessions run in UTC (`config/database.php`, the same zone as `app.timezone`), so timestamps PHP writes without
an offset are stored as written. Rows written before this setting on a server in another zone keep that offset.

## 7. Security

- **Sign-in**: Laravel session guard, database sessions (`arkon_session`, HttpOnly, SameSite=Lax; set
  `SESSION_SECURE_COOKIE=true` behind HTTPS), session regenerated on sign-in, 5 attempts per email+IP per minute
  plus 30 requests per minute per IP on the endpoint, same-site `next` redirects only. No registration route;
  accounts come from `arkon:owner-create` / `arkon:member-create` (generated password printed once).
- **CSRF**: Laravel's `PreventRequestForgery` (same-origin `Sec-Fetch-Site` or the XSRF token, which the editor sends).
- **Framing**: every `web` response (sign-in, admin, editor, API, previews) carries `X-Frame-Options: SAMEORIGIN`,
  `nosniff` and `Referrer-Policy: same-origin` (`AdminSecurityHeaders`), so another site cannot frame the admin to
  trick a member into clicking Publish or Delete. The editor's own frames are same-origin (`srcdoc` or previews).
  Public pages set `frame-ancestors 'self'` in their own policy.
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
- **AI**: there are no AI credentials in Arkon. Claude Code uses the user's own login, which Arkon never reads; Arkon's
  helper and MCP tokens are revocable, stored as hashes, and grant one user on one site. The Claude Code child process
  gets no database settings or keys and no tools. AI output is data, never code: it can only become the editor's
  operations on registered components and passes the same validation as a human save, so it cannot add HTML, scripts,
  unknown components or unsafe links, and it never applies or publishes on its own.

## 8. Tests

| Suite | Count | What it proves |
|---|---|---|
| PHPUnit `tests/Unit` | renderer, conformance, component versions | exact production markup, escaping, editor annotations; PHP side of the conformance fixtures; inverses restore documents; version migration |
| PHPUnit `LifecycleRaceTest`, `CreateIntentTest`, `ReadConsistencyTest`, `ComponentHistoryTest` | 19 | forced interleavings of delete with waiting publish/save/restore/title/unpublish/delete (both orders), rename with a waiting save, identical and conflicting creates waiting on the path lock; a rename committed in the middle of editor, page-load and preview reads; create-intent replay after rename/delete and its backfill; historical reproduction across component versions |
| PHPUnit `tests/Feature` | pages, requests, page management, media, concurrency, upgrade, runtime safety, HTTP | everything in reference `pages`, `requests`, `page-management`, `media`, `media-access`, `consistency`, `upgrade` and `config` tests, plus the HTTP layer (sign-in, rate limit, no sign-up, JSON envelopes, `{}` fidelity, canvas endpoint, public headers, redirects, preview, media with a real session cookie) |
| PHPUnit `StyleRenderingTest`, `DesignResourcesTest`, `AiDesignTest` | 9 + 13 + 7 | generated classes, deduplication and media-query order; unsafe and out-of-range values refused on save; canvas, preview and live CSS identical; background images private until live and foreign ones refused; WebP variants (sizes, smaller than the original, original kept), `srcset`/`sizes`, private variants with signed canvas URLs and public once live; LCP priority; sections/groups/proportions without wrappers; arkon-php-1 output unchanged; token drafts private, publishing refreshes the live revision (not the draft), validation/versioning/idempotency/permissions, pre-token publications never refreshed, reproduction with recorded tokens and component versions; instances render published versions only, unpublished and foreign components refused, components validated like pages (no nested instances), component images public with the page; failed refreshes recorded, retried and never publishing twice, a refresh never replacing a newer publication, unpublished pages skipped; AI: the acceptance request becomes settings that save, reload, edit and publish (helper and MCP), follow-ups merge settings, invalid settings refused with the broken rule, nested layouts with named new blocks, token changes separate and draft-only, instances limited to published listed components |
| Vitest `placement` | 16 | drag placement: gaps and padding are slots between neighbours, first/last, no-op, hysteresis; container padding vs. edge band; empty containers; reparenting; refusals in words (nesting, itself, Columns minimum, full group); side by side, row-reverse, column-reverse, wrapping rows; Layers rows with level chosen by indent; container names in labels; automatic scroll speed and steps (stationary pointer keeps scrolling, bounded) |
| Vitest `duplicate`, `columns` | 8 + 8 | nested duplication with fresh ids and identical props/styles/animations/images, independence of copies (no shared objects), linked instances kept, one undo/redo step, a column copied with its width, refusals (page, full group, Columns at 6, insert size, unresolved field inside but not elsewhere); creating 3/4/5 equal columns and proportions as real Column nodes; more columns keep content; fewer move blocks in order or delete only when asked; capacity refusal; proportions reset with the notice; reconciliation per screen (counts, wrapping, stacking, tracks following duplicated or removed columns) |
| PHPUnit `MotionRenderingTest`, `AiBuilderTest` | 7 + 7 | runtime file = pinned integrity, one observer, no timers/polling/eval; pages without entrances script-free with `script-src 'none'` (load-only pages too before motion-3); viewport pages load exactly the runtime with a policy naming only its URL (live and member preview); no-JS, reduced-motion and print rules; keyframes animate only opacity/transform; phones override; LCP blocks (first h1, priority image, their containers) left off and reported to the canvas; editor CSS final state; inputs record `motion-1`; reproduction byte for byte, unknown/missing runtime unavailable; pages without animations unchanged; invalid settings and released versions refused on save; PHP column reconciliation. AI (fake runner and MCP): catalogue offers duplicate/motion and drops the blanket "no animations"; duplicating a button (ref + update), five equal columns, fade-up in view; review without change, apply as one AI revision, undo, explicit publish, stale proposal refused, out-of-range animation refused with the rule (helper repair run and MCP tool error) |
| Playwright `e2e/builder-features.spec.ts` | 8 | duplicate from the toolbar, Layers, canvas control and Ctrl/Cmd+D (editor and canvas focus; never while typing), undo/redo, save/reload with fresh ids; unresolved link blocks it; Columns at 6 refuses; layout picker creates 5 columns and a measured wide-middle layout, one undo step; count selector keeps content, cancel/move/delete for populated columns, widths reset notice, Add block in an empty column, phones stacked and side by side, publish; animation inspector (target named, default trigger, replay starts and a click stops it, phones off, reload), the hero's h1 suppressed with the reason, live page: runtime allowed by CSP with no violations, below-the-fold section paused then played once when scrolled to (not again), one observer created and disconnected, keyboard focus reveals at once, JavaScript disabled and reduced motion show everything, phones not animated; load-only pages allow only the runtime (motion-3); the component editor (duplicate, picker, animation); the AI panel with fake Claude Code: duplicate + five columns + fade-up previewed, applied, one undo, redo, explicit publish |
| PHPUnit `ColumnLayoutTest`, Vitest `remove`, `columns` (normalizer), `RecoveryPanel` | 7 + 5 + 3 + 1 | saves refuse fraction widths that don't match the columns on any screen and a third column under "1fr 2fr"; counts and Group grids stay free; AI (helper and MCP): a column added without widths gets a reviewed reconciliation operation, explicit final widths in the same reply are kept, widths fitting only an intermediate count are refused with the rule, moves between Columns blocks and reorders/removals keep widths with their columns; an older draft breaking the rule opens in recovery, untouched until the explicit repair saves; historical documents still render pinned. Editor: deletion labels, confirmation for containers with content (empty columns are not content), next/previous/parent selection, one undo/redo step, a column's width removed with it, last column/root refused, hero image removed without the hero; moves between Columns and reorders reconciled in the same step, edits that set widths left alone; the width repair in recovery |
| Vitest `state` (history identity), `columns` (explicit widths, shared cases), PHPUnit `ColumnLayoutTest` (added) | 2 + 3 + 3 | an "Undo delete" entry is never another edit: other edits, coalesced typing, undo/redo, a branch after undo, the 200-entry cap and a restore; a gap, padding, background or animation change together with an add/remove/move still reconciles the widths (AI and editor); an explicit phone layout doesn't exempt base and tablet; valid explicit final proportions and an explicit reset are kept; PHP and TypeScript agree on `tests/Conformance/column-layouts.json` |
| Playwright `e2e/motion.spec.ts`, Vitest `motion`, PHPUnit `MotionRenderingTest` (updated) | 10 + 6 + 9 | canvas previews measured by animation events and progress: an eligible effect previews after its render and ends in the final state; Preview animation plays again; rapid changes with 700 ms renders preview only the latest, once; selecting another block or typing cancels; mobile override ("Off on phones") and reduced motion shown and never previewed; protected image, heading and the section holding the image named with their cause, effect locked, the section's stored entrance moved to the other blocks (button, column) without touching the image; the component editor previews too. Live: the h1 never animates; an on-screen intro plays its entrance at load (start and end events); a below-the-fold section is held back, plays once in view and never again; CLS 0; keyboard focus shows it at once; JavaScript disabled, reduced motion and phone override show everything. Both runtimes pinned by integrity; motion-2 CSS; every protected block reported with its cause; a motion-1 publication still reproduces byte for byte and keeps motion-1's script policy. Focus (motion-3): Tab into nested on-screen entrances (load and view, 2 s delay, nothing held back) during the delay and while running: the focused link and every block around it fully visible, still visible after focus moves on, Enter activates the link; reduced motion and no JavaScript too; a motion-2 load-only publication still reproduces byte for byte with no script. Moving an entrance: a section holding the h1, the priority image and two buttons offers only the button without its own entrance, every stored entrance afterwards really animates on the canvas and the published page (h1 and image never), one undo and redo; nested protected containers, a protected reusable component and no eligible block (no move offered) |
| Playwright `e2e/delete.spec.ts` | 10 | a button deleted from the canvas without opening Layers (named control, tooltip, notice with Undo, redo, no save until asked, save and reload); in both editors, the notice's Undo delete never undoes a later edit (checked on the inspector field and the saved draft); a section with content asks, Cancel and Escape change nothing, the same from the Layers row, one undo; Delete/Backspace edit text while typing (canvas and inspector) and delete only from the canvas or a Layers row; a column deleted with its width, the last column refused (control disabled with the reason, key explained), nothing to delete for the page; hero image removed vs the whole hero; invalid custom widths refused before applying; a column dragged to another Columns block (both valid, the reset explained, one undo); recovery for older inconsistent widths (no delete control, keys do nothing, stored draft untouched, explicit repair saves); the component editor |
| Playwright `e2e/drag.spec.ts` | 12 | quick drags released on arrival (never waiting for an indicator): a quick release with geometry replies delayed 400 ms and out of order lands where released (the old code put it at the previous target); holding still at the bottom edge keeps scrolling to the end and drops there (the old code stopped after 24 px); gaps, padding, empty group, reparenting with three undo steps; row-reverse; an image block between columns; palette to canvas; explained refusals; Escape (also with focus in the canvas), window blur, release outside the window, late replies after a cancel, click without drag; a change during the drag (undo) and a lock (stale save) cancel; Layers rows to the canvas and Layers autoscroll; the component editor; touch on the Move handle. Each checks document order, one undo step per drop, nothing undoable after cancels, and no save or publish request |
| Vitest | editor state, operations, conformance, structure, responsive style editing, recovery repair, AI proposal guards (stale/unsaved/unfinished, one undo step, tagged save batch), request keys | the reference editor-state tests; TypeScript matches PHP on all 143 fixtures; structure helpers (placement, drop targets, undo/redo of structural changes); request keys without `crypto.randomUUID` |
| PHPUnit `StructuralEditingTest`, `LinkRecoveryTest` | 16 | add/nest/reorder/remove through the save API, the server applying undo inverses, invalid nesting and unsafe links refused, backslash links refused while a publication recorded under the older link policy still reproduces, image v1 publications reproducing while republishing moves to image v2, drafts with one or several stored backslash links (and an image) opening in recovery over HTTP instead of 422, nothing saving or publishing until corrected or removed, the repaired draft saving and publishing normally while the old publication stays live until then and still reproduces, other invalid drafts not opened in recovery, publish of a nested layout as clean semantic HTML with recorded component versions, publish checks of the new components, editor/viewer/outsider permissions, foreign assets, a pre-milestone page v1 publication still reproducing |
| PHPUnit `AiProposalTest`, `McpServerTest`, `AiCommandsTest`, `ClaudeCodeCliTest` | 42 + 8 + 4 + 7 | panel path with a scripted fake runner: queued → helper → validated proposal that changes nothing, apply as one AI revision, undo, explicit publish, follow-up, one repair run, 12 kinds of invalid output, empty answers, images on the page only; request keys (replay, changed prompt/version/page refused), **real concurrent identical requests replay (the old lookup-before-lock order fails with 23505)**, one helper per request, lease expiry recovery and the late result refused, retry limit, queue expiry, cancel while running (late result never lands), revocation while idle and while running (fenced, back in the queue; a runner that ignores the stop still cannot store its result; the requester stays the owner), the helper's or requester's edit rights lost mid-run, expired leases rejected before recovery, after takeover and exactly at the expiry instant (database clock), supersede, discard, stale drafts and proposals, helper readiness/offline/not-ready, Claude Code failure codes, frequency and concurrency limits, permissions and site isolation, HTTP; MCP protocol, the five tools only, site scoping, submission without draft change → review in the editor → apply, validation and stale/changed-key refusal, exact retries after a manual edit and after applying (original id and current status, no new row or application), token identity and immediate revocation, a real stdio process; pairing/revoke commands and `arkon:ai-helper --once` with the real CLI runner and a fake `claude`; the CLI runner against a fake executable: subscription-only readiness, the exact flags (no `--bare`), the prompt byte-for-byte on stdin, the real schema intact (failed through `cmd.exe`), no secrets in the child environment, empty working directory, limit/login/garbage/timeout/cancel |
| Playwright `e2e/design.spec.ts` | 6 | the design acceptance request through the AI panel (fake CLI): proposal with the settings and a preview, apply, reload, the settings shown and edited in the inspector per screen (undo), the hero measured in the canvas at desktop and mobile and on the live page at 1280 and 390 px; design controls with mobile overrides, inheritance, reset and undo, invalid values refused; canvas drag and drop with placement feedback (Move handle and palette drag); token draft private, publishing refreshes the live page; a reusable component made from a block, edited and published on its own page, updating the live page; AI token changes shown apart and only reaching the token draft |
| Playwright `e2e/` (other specs) | 29 | **AI** (global setup pairs and starts the real helper with a fake Claude Code CLI, `e2e/fake-claude.mjs`): connection shown, prompt → queued/running → preview → apply → undo/redo → follow-up → explicit publish, the CLI run with the locked-down flags and a clean environment, cancel while running, edits made meanwhile never replaced, discard, subscription limit, invalid output, unsupported request, editor role, and the VS Code path: `arkon:mcp` driven over stdio → proposal waits in the editor → review → apply; **builder**: palette, layers, move buttons, drag and drop (incl. refused invalid drops, a Columns block's last column, and palette drags), unsafe link refused in the inspector, unresolved link kept across selection, flagged in the status, blocking Preview/Publish and leaving until fixed or reverted (also when it becomes unresolved while the save before Publish or Preview is held: no publish request, no preview navigation), drafts stored with backslash links opening in recovery and returning to normal after an explicit correct/remove repair, image sizes in a column measured in canvas, preview and live page, structural undo/redo, mobile stacking, save/reload/publish clean HTML, structure toolbar, editor role builds but cannot publish; **write flows**, at an insecure origin like Herd's (`http://arkon-e2e.test:8100`, mapped to the PHP server inside Chromium only; asserts `isSecureContext === false` and no `randomUUID`) against `arkonlaravel_e2e`: editor flow with save/publish/upload/restore, typing/undo during slow saves, aborted and lost saves, exact publish retries, Ctrl+S during a slow restore, page create/rename/unpublish/delete, editor role limits |
| PHPUnit `AiTokenApplyTest`, `RenderCompatTest` | 5 + 5 | AI token changes: an interruption after the token write rolls back both writes and the retry applies once; an exact retry after later token edits returns the original result and keeps the edits; a change is never replayed over token edits made after the proposal (stale); real concurrent duplicates apply once; a demoted requester and other editors are refused, live pages unchanged until tokens are published (three of these fail against the previous two-transaction code). Render compatibility: post-fix hero v2 publications reproduce from their recorded version; in-development ones only with a compat record naming the build, recorded only on an exact match, append-only, never used for new output |
| Vitest `saveCoordinator`, `parts`, `length` | 9 + 3 + 3 | one save coordinator for pages and components against a fake server with the save-key contract: response lost after commit (rename only) confirmed by resending the same request, lost before the server, server error, rename and edits during an in-flight save kept for the next batch, a later rename not changing an uncertain request, repeated shortcuts sharing one request, save then publish, retry then later edits, definitive refusal; part names and contextual labels; number-and-unit lengths validated by the shared rules |
| Playwright `e2e/components.spec.ts`, `e2e/inspector.spec.ts` | 4 + 2 | component editor: an invalid link (already there, or typed while a save is held) blocks publishing and is brought into view until reverted or corrected; a rename-only save whose response is lost after commit is confirmed by saving again (never "changed elsewhere"), and a later rename is saved next; an unsaved rename alone guards leaving (all four fail against the previous component editor). Inspector: clicking the hero image targets the image; image height 500 px with cover; image width without changing the section; section width separately; mobile override and reset; undo/redo; reload; canvas = preview = published page at 1440 and 390 px; every hero part named, inline text editing kept |
| Playwright `e2e/screenshots.spec.ts`, `e2e/responsiveness.spec.ts` | opt-in | review screenshots (light, dark, laptop/tablet/phone widths with every toolbar action in view; `SCREENSHOTS=<label>`) and editor interaction timings on a ~200-block page (`EDITOR_PERF=1`, see PERFORMANCE.md) |
| Playwright `e2e/herd/` | 1 | **authenticated, non-persisting** smoke test through Herd itself (dev database): sign-in, dashboard, editor canvas, history, member-only preview, clean public responses, insecure-context conditions, and one write request (create with a reserved URL) that generates a request key and is refused before anything is written. It does not save or publish |

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
and roles, iframe editor with inline editing, inspector, upload, preview, undo/redo, history and restore, batch
saves, safe retries, publish intents, epoch ordering, private media, page create/rename/redirect/unpublish/delete,
clean public HTML), publication inputs and versioned components, the visual builder (sections, groups, hero, text,
image, button, columns; add, select, edit, remove, reorder and nest by buttons, Layers drag and drop or directly on the
canvas; structural undo/redo; duplicate; column layouts with a picker, a count selector and per-screen layouts), entrance
animations (CSS presets, a versioned viewport runtime only where needed), the shared styling model with responsive overrides, design tokens and reusable
components with versioned publishing and dependent-page refreshes, responsive image variants, and the AI workflow
(VS Code via MCP and the editor's AI panel via the local helper, on the user's Claude subscription) with the full
design catalogue. Performance evidence: [PERFORMANCE.md](PERFORMANCE.md).

Deferred: collections and content entries, a general outbox/workers (refreshes run
after publishing and from `arkon:refresh-pages`), editable site settings, autosave, site switcher, member
management UI, row-level security, remote font imports, AVIF variants, AI changes to reusable components.

### Admin interface

One visual system for every screen (`resources/css/app.css` tokens, light and dark; `Components/ui.tsx` buttons,
fields, segmented controls, status pills, notices, empty states; `Components/Icon.tsx` hand-drawn icons, no icon
dependency). States always have an icon and words. The editors share `editor/chrome.tsx` (status pill, viewport,
tabs, notices) and `editor/session.ts` (activity, conflict, unresolved fields, leave guard); saving goes through
`arkon/editor/saveCoordinator.ts` for both pages and reusable components: one immutable request per batch
(operations, base version, key, and for components the name), resent unchanged after an uncertain outcome, shared by
every trigger. The inspector's model of parts lives in `arkon/editor/parts.ts`. Admin CSS and JavaScript never reach
published pages.

### Known limitations

- AI: the automated suites use fakes (a scripted runner, a fake `claude` executable, MCP over stdio); real
  Claude Code (2.1.292, subscription login) was verified manually for both entry points during development; real output quality is not
  tested automatically. One page per request. The panel needs the helper running on the same computer as Claude Code
  (one helper per site). Claude can only use images already on the page, can propose bounded SEO text and selected image alt descriptions with review, but cannot change page title/URL or site
  settings through the page proposal. Buttons without a destination use the placeholder `#`. Subscription usage limits are Claude Code's;
  Arkon cannot show the remaining allowance. The page context goes through stdin, but the JSON schema must be a
  command-line argument; large schemas are semantically compacted first. A run whose command line would exceed ~32 KB is
  refused. The schema does not enforce which property a slot accepts (the compiler does, with one repair run).
  Page prompts cannot edit reusable components or publish tokens; they can propose token changes for the draft.
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
- Refreshing live pages after publishing tokens or a component runs in that request (up to 50 pages or 20 s); the
  rest wait with status on the Design page until **Retry now** or `php artisan arkon:refresh-pages`. There is no
  background worker.
- Detaching a reusable component instance copies its blocks but drops the instance's own spacing and size settings.
- AI can propose page changes and token changes for the token draft; it cannot edit reusable component definitions.
- A hero that uses a CSS background image is not given fetch priority (only `<img>` elements are prioritised).
- Unresolved fields exist for Button links only (other fields accept any text up to their limit). They live in the
  open tab: a reload, after the warning, discards them.
- Dragging on touch screens works with the canvas handles and the Layers grips; palette items are tapped to add on
  touch (dragging them from the palette needs a mouse or pen). Long-press dragging is not implemented.
- A drop resolves against the canvas geometry; while the canvas re-renders after a change (typically tens of
  milliseconds locally) handles are disabled and a release waits up to 600 ms, then cancels with a notice.
- Placement follows flex and grid layout as rendered, left-to-right; right-to-left text direction and dense grid
  placement are not modelled. Elements positioned out of flow by custom CSS can't occur (there is none).
- The desktop canvas is at least 960 px wide; in a narrower editor the stage scrolls sideways (also while dragging).
- Links must be ASCII without backslashes (percent-encode other characters). An empty button link renders `href="#"` in drafts;
  publishing requires a link.
- Containers cannot hold sections; columns hold no hero; at most 6 columns, 30 blocks per container, 8 levels.
- Only one image gets high priority: the first within the first two top-level blocks (or one marked "Load early").
  Renderer 4 preloads a managed first-content background with responsive viewport variants; older renderers retain their previous behaviour.
- `sizes` is an estimate from the layout (full width below 900 px, the block's share of 72rem above); a block set to
  a fixed narrow width may fetch a larger variant than it needs.
- Design values are bounded: no arbitrary CSS, transforms, unbounded positioning, remote custom fonts or arbitrary hover
  rules; bounded gradients, sticky headers, local Inter and hover/focus presets are supported; animations are the entrance presets only (whole blocks, opacity and transform). Tokens have fixed slots
  (values are editable, slots are not added by users).
- Entrance animations: since motion-2 a "when scrolled into view" block on screen at load plays its entrance with the
  page, and since motion-3 keyboard focus shows any entrance around it at once (motion-1 and motion-2 publications
  keep their recorded behaviour until republished); without JavaScript, focus shows a block only while it stays
  there (CSS), so an entrance still in its delay can hide again when focus moves on before it ends; a below-the-fold block whose
  entrance finished before the runtime ran (a very slow script) simply shows when scrolled to; right-to-left pages are not mirrored (fade left/right are physical
  directions); nested text is not animated separately (the whole block is); without IntersectionObserver every
  entrance plays with the page, below the fold too. `body{overflow-x:clip}` is part of the animation CSS, so a page with animations never
  scrolls sideways. In the reusable-component editor, LCP protection depends on the page a component is placed on
  and is not shown there.
- Duplicating a block copies what is applied; an unresolved link inside it must be fixed or reverted first.
- Columns widths: a column moved in from another Columns block (or new empty columns) can't carry its proportion, so
  that screen goes back to equal widths (explained); reusable component drafts that break the one-width-per-column
  rule have no recovery mode (none exist in the development data). Deleting with Delete/Backspace needs focus in the
  canvas, Layers or the block toolbar; the hero image is the only part with its own "Remove" (other parts delete
  their whole block, named as such).
- Columns: dragging Columns from the palette creates two equal columns (the picker is on click); the canvas "Add
  block" menu offers text, image, button and group (the others are in Properties and Layers).
- Instances cannot override content (only placement); changing content means editing the component or detaching.
  Detaching drops the instance's own placement settings.
- Page refreshes run in the request that publishes a resource (up to 50 pages or 20 s); the rest stay pending and
  show on the Design page until `arkon:refresh-pages` or Retry runs them (there is no background worker yet).
- The canvas, preview and live page use the same breakpoints, but the editor's desktop canvas is at least 960 px
  wide; very wide layouts are previewed at the editor's width.


## Developer theme components (first milestone)

Folders under `themes/` can declare editable components using the constrained manifest/template/CSS contract in [THEMES.md](THEMES.md). `arkon:theme validate` and `install` compile trusted local packages into immutable snapshots. The server publishes their manifests to the admin, so the palette, generic inspector and existing AI catalogue discover them without rebuilding frontend assets. Rendering uses the same element/media/style pipeline and old publications retain their pinned component versions. Installing never changes pages or moves a live pointer.

Immutable component registration is application-wide for history; Appearance → Themes now selects custom component availability per site. Parent inheritance and site-wide theme skins remain unimplemented. Package schema changes require a new type; consecutive versions support presentation changes only. Snapshots under `storage/app/theme-components` must accompany deployments and backups. No author PHP/JavaScript is executed. See the guide for exact limits and the theme-only VS Code workspace workflow.

### Per-site theme selection

`site_theme_sets` holds a versioned draft choice and a composite live pointer to immutable `site_theme_versions`; `site_theme_requests` is the append-only request ledger. Activation/publishing require page.publish (owners/admins), site membership and an expected version. Exact retries authorize before lookup and return their original result even after later changes; changed payloads conflict. Publish holds the site epoch lock and records the selection; page rendering records its published version as audit metadata without changing HTML.

Per-site advisory locks serialize selection changes with component availability checks inside page/reusable-component saves. All immutable component definitions remain registered; palette and AI schema/catalogue filter by active site types, keeping existing inactive document types editable. The AI compiler and server saves enforce availability. Preview uses the single PHP renderer with default sample content, no writes, scripts or page media. The sources are fixed beneath themes/, not supplied web paths. Public requests continue serving stored HTML and do not read theme selections.


## Complete website workflow

The website scope captures page, shared-component, token, form and settings versions. A lease-fenced helper result or authenticated MCP submission compiles through the existing native operations. Applying uses one transaction, expected versions, site/path locks and immutable application records. Whole-site publishing locks page drafts before resources and the site epoch, publishes reviewed resources and pages atomically, and runs bounded refreshes after commit. Request ledgers make exact retries safe. Forms use immutable versioned definitions recorded in render inputs, composite site foreign keys, encrypted submissions and a sessionless live-reference-gated endpoint. New container manifest versions admit form blocks; old manifests and renderer output remain pinned. `arkon-php-3` adds canonical URLs and semantic page layout; renderer versions 1/2 remain reproducible. New additive migrations `2026_10_14_000001` and `000002` create the workflow tables and expiring MCP contexts. See [WEBSITE_WORKFLOW.md](WEBSITE_WORKFLOW.md) for scope, security, performance and recovery limits. Earlier single-page-only AI limitations apply only to the page-editor flow; website proposals can edit their shared header/footer drafts.


### Website generation activity and failure diagnostics

Website workers record generation/validation/optional repair stages and heartbeats under the same valid lease as completion. The admin shows indeterminate activity, elapsed time, stale-heartbeat and failed-poll warnings, without inventing a percentage. Website requests opt out of automatic model repairs by default; the requester may explicitly allow one. Expired or disconnected website runs fail rather than requeue another model run, while page requests keep their bounded recovery. Future invalid website outputs retain up to 2 MiB of structured output privately plus bounded validation issues; status endpoints expose issues, never raw candidates or lease tokens. Old deleted outputs cannot be reconstructed. Website instructions include the shared block/design guidance and the actual nested update envelope, and opt-in repairs receive exact rule errors. Published rendering is unchanged.

### Navigation and shared website layout

Open **Admin → Navigation** to create menus, reorder items, add one level of dropdowns, and choose page links, custom URLs or section links. Page links store a page reference: they follow its published URL, never an unpublished rename. Unpublished targets resolve to # and show a warning. Menu drafts stay private until explicitly published; publishing refreshes dependent live pages under the site's publication ordering. Editors can save; owners and admins can publish. Published menu history is immutable and its resolved URLs are recorded in publication inputs.

The Navigation screen also links to the site's shared header and footer editors. Its **Prepare missing header and footer** action creates a local, reviewed website proposal, using no Claude request or subscription quota. It preserves existing nonempty shared layouts and page content, fills missing layout, and proposes section anchors for recognised Home/About/Services/Contact sections. Nothing changes until the proposal is applied; publishing remains separate.

The builder has a Navigation block with a menu selector. Sections have an **Anchor** field for destinations such as #services; anchors must be unique, start with a letter, and contain letters, digits, hyphens or underscores. Duplicating a section gives its copy a new anchor. Menus use native HTML disclosure controls for mobile and dropdowns, with keyboard support and no public JavaScript. The initial implementation supports one dropdown level and up to 50 menu items; it does not provide a fully custom mega-menu.

Website proposals include editable header, footer and navigation by default. Turn off **Include shared header, navigation and footer** for a standalone page. Existing layouts and menu definitions are preserved unless changes are proposed. Review includes the menu and shared resources before applying; website publication publishes their reviewed drafts together. Restart the local AI helper and MCP server after this upgrade: website generation requires helper protocol 3, so an older helper is rejected before a new run consumes quota.

New buttons default to #, including AI-created buttons with an omitted or empty destination. This is a visible placeholder, allowed to publish with a readiness warning; supply a real destination before launch. Existing stored blank or intentional URLs are not silently rewritten. Component versions and old publications remain immutable.

## Professional website layouts (9 October 2026)

The builder now includes linked managed logos, curated inline SVG icons/social links, editable sliders and a back-to-top link. In **Layers → Starting layouts**, insert an agency header, hero slider, image introduction, service cards, newsletter or footer. These are ordinary blocks: edit every field, move/remove/duplicate children, undo and save normally. Repeated insertions get fresh node IDs and section anchors. Select the appropriate published menu, images and form after inserting; replace demo copy and contact details before launch.

Use the inspector to choose a directional two-colour gradient, image crop/position, responsive sizing, inherited typography, hover/focus colours/shadows or a sticky header. Gradients accept only a bounded direction and two hexadecimal colours; sticky positioning and stacking order are bounded. There is no arbitrary CSS, remote font import or user script execution. The Inter font choice is served locally under its included SIL Open Font License, with a Latin subset and a full-character fallback. System fonts remain the default and require no font download.

A Slider contains 1–6 Slide blocks. Slides hold ordinary editable content, but cannot contain another slider or a reusable instance. Slider v2 shows one slide in the editor, with clickable controls; selecting a slide or its child in Layers reveals it. The selected slide survives redraws and the canvas never autoplays while editing. Version 1 remains reproducible. Pagination is selectable (numbers, dots with an elongated active marker, bars, or none), arrows are optional, and controls support alignment and light/dark colors; the public page shows one slide with arrows, dots, keyboard and touch navigation. Autoplay is off by default. If enabled, it pauses on hover, focus, a hidden tab and reduced motion. The tallest slide reserves height to avoid a layout jump; inactive slides are inert and their background images load when shown. Without JavaScript the first slide remains readable. The back-to-top link also works without JavaScript.

The conditional, integrity-checked components-1 runtime is approximately 4.9 KB uncompressed, independent of React/Inertia and allowed by an exact CSP path. Pages without its widgets download none of it. Renderer arkon-php-4 retains support for older renderer versions and records its inputs; older component files and published HTML are unchanged. New versions are page 6, section 5, group 5, column 6, fragment 4, button 5 and form 2. Logos/icons/sliders/slides/back-to-top start at version 1.

Renderer 4 uses managed responsive background variants and viewport-specific preloads for the first main-content background. Header logos do not consume the main image priority. Backgrounds still need suitable image dimensions and compressed assets; these mechanisms cannot guarantee field Core Web Vitals for arbitrary content. See docs/PERFORMANCE.md for measured local results.

**Newsletter:** create an email-only form using **Forms → New form → Newsletter**, save and publish its definition, then select it in a Form block. The inline layout, submit label and notice are editable. Submissions use the existing validation, honeypot, rate limiting, encrypted entry storage and site permissions. This collects signups locally; it does not send newsletters or connect an email marketing provider. Do not tell visitors they are subscribed to an external list unless that integration exists.

Both Claude Code paths receive the new component/style catalogue and layout guidance. Form IDs are validated against the published forms in the supplied site context, independently of media IDs; unknown form references are rejected before review. The sandboxed helper receives explicit schema/context rather than unrestricted repository access. No real model request is used by the regression tests. Restart your existing helper and MCP server to refresh their cached catalogue; nothing is automatically published.

The reference demonstration is an isolated test fixture (/perf-reference), with generated placeholder imagery. It is not added to the development/live site. The starting layouts are available immediately in the real builder after refreshing the admin page.

### Verification of the professional builder milestone

- PHP: the full 330-test sweep passed 329 and caught one invalid UUID in the newly added unknown-form test. After correcting that fixture and adding two renderer tests, the affected eight tests passed: all 332 PHP tests are covered by the sweep and rerun.
- TypeScript/Vitest: 325 passed; typecheck and production build passed. Pint passed and the changed frontend/test files were formatted.
- Browser: 89 checks passed across the full sweep and targeted reruns; nine optional profiling checks were skipped. The reruns cover the compact palette, visible drag targets, deterministic drop position, page management and all four reference checks (including real published autoplay). Browser tests use only fake Claude.
- Herd: read-only sign-in/navigation/mobile smoke passed; its temporary account was removed. No existing draft or live page was changed.
- All 29 existing development publications reproduce byte for byte; runtime database privileges remain restricted.
- No real Claude request, commit, push or publication of development content was performed.

Performance: default-font reference scored 98/100 mobile (median LCP 2.159 s, CLS 0); optional local Inter scored 96/100 (LCP 2.531 s, CLS 0.005). These are isolated local lab results with generated placeholder images. See PERFORMANCE.md.

Slider v3 accepts every whole-second interval from 1–60 seconds. New sliders continue playing under the mouse by default; hover pause is optional. Upgrading earlier sliders preserves their hover-pause behavior. The editor offers explicit Play slideshow / Pause slideshow controls; selecting, editing, dragging or redrawing stops this temporary playback without changing drafts. Focus, hidden tabs and reduced-motion preferences stop public autoplay; pressing Resume explicitly resumes even if that persistent control still has focus. The versioned components-2 runtime is selected only for slider v3; earlier publications retain components-1 and reproduce unchanged.

Slider v4 adds slide/fade/instant transitions and a bounded duration. The components-3 runtime animates only transform or opacity, keeps the tallest-slide grid reservation, cancels interrupted transitions and skips motion for reduced-motion users. Playback buttons are visually suppressed by default on the website but remain available to screen readers and keyboard focus; Show autoplay pause button restores the visible control. Editor test-play controls stay in the editor only. Earlier component versions and runtimes remain pinned.

Slider v5 adds independent Arrow placement: Grouped or Left/right edges. Edge arrows remain vertically centered while pagination can be hidden or aligned separately along the bottom. The same CSS works in the canvas, preview and publication, without adding JavaScript. Earlier drafts upgrade to Grouped, and older published output remains unchanged.

Slider v6 gives pagination independent Left/Center/Right alignment and Top/Bottom position. Canvas playback commands now live in the inspector and do not add a Play button to rendered content. Their version-checked preview path supports page and component editors, stops on editing/redraw/drag and respects reduced motion. Published versions remain immutable.

Slider v7 exposes arrow appearance (default/plain/outlined/filled), shape, button and icon size, gap, horizontal and vertical offsets, and grouped Top/Middle/Bottom positioning. Select the Arrow buttons part for custom background, colour, border and hover styles. Hit targets stay at least 44px; all placement and appearance use CSS, with the same output across canvas/preview/publication and no extra runtime. Old component versions stay immutable.

The slider inspector groups Slides, Playback, Transition, Arrows, Arrow appearance/colors, Pagination and Slider design. Irrelevant controls are hidden without clearing their saved values. Horizontal and Vertical position are shown together for grouped arrows. Custom arrow colours/background/borders and hover settings edit the existing validated arrows style slot directly; whole-slider styling always targets root.

Slider v8 supports validated tablet (899px and narrower) and mobile (599px and narrower) overrides for playback, transition, arrows, appearance and pagination. All screens / desktop is the base; tablet inherits base, mobile inherits tablet. Unset or inherit values follow the larger screen; Reset removes the override. Canvas and public output resolve the same settings on viewport changes; reduced motion, focus and hidden-tab autoplay protections remain. Prior component versions and components-3 runtime are unchanged and still reproduce old publications. The new components-4 runtime is loaded only by pages using slider v8. Refresh the editor and restart the AI helper/MCP to use the expanded catalogue; existing live pages change only after explicit publication.


Responsive block controls now use the selected screen consistently: All screens is the base, tablet (899px and narrower) inherits it, and mobile (599px and narrower) inherits tablet. Reset removes an override rather than writing a guessed default. Column proportions and custom widths follow this screen; the number of columns remains shared. Default mobile stacking is an explicit, removable setting. Button v6 supports screen-specific appearance and size, Section v6 supports content-width presets, and Form v3 supports stacked/inline layout (with the existing mobile stacking stored explicitly). Image dimensions, fit and crop use the existing screen-specific media style controls; the image asset, alt text, text, links and block structure remain shared. Override badges include responsive presets.

These visual changes render through CSS and add no public JavaScript. Standalone Image v5 estimates download slots from per-screen column proportions, nested columns and supported explicit image widths; estimates are conservative and do not model arbitrary custom layouts or every container width. Intrinsic dimensions, responsive sources and image loading priority remain. Old component files remain immutable, so saved publications reproduce with their original versions; editing upgrades drafts in memory. Refresh the editor and restart the AI helper/MCP for the expanded catalogue. No performance score is guaranteed by these controls.

Verification for responsive block controls: 345 PHPUnit tests (2273 assertions), 329 Vitest tests and typecheck/build passed. Browser checks cover saving and publishing Columns/Button/Image overrides with JavaScript disabled, Section/Form inheritance and text-button padding, the existing builder/design flows, and the slider override regression. Herd smoke verified the new inspector without saving; all 45 development publications reproduce byte for byte. No real model requests were made.


Background images use the same thumbnail library/upload picker as image and Hero fields, in page and component editors. Upload progress measures transferred bytes; at 100% the control separately reports server validation/variant preparation. Failures appear beside the picker and allow a manual retry (unconfirmed network outcomes advise checking the library first). Uploads remain private until a live publication uses them. Completing an upload after switching blocks/parts or changing its target does not apply it to a stale selection; the asset remains in the site library. Background image selection still applies to all screens, as labelled; Reset/removal uses the existing style contract. No public renderer, schema or upload permissions change.

Background-upload verification: typecheck and 333 Vitest tests passed; 24 media/style PHP tests passed; 10 background-upload/design browser checks passed, with the existing editor upload flow also verified. Herd smoke confirms the background picker is available without changing development content. All 45 development publications reproduce unchanged; the public renderer was not modified.


## Production output audit: renderer 5

See [PRODUCTION_AUDIT.md](PRODUCTION_AUDIT.md) for findings, measurements and remaining production work.

- Public pages remain stored HTML: no React/Inertia hydration, session, admin bundle or schema rendering on the visitor request. Public/SEO delivery resolves only the thin PublicPages store, avoiding transitive catalogue, validator, theme and renderer construction. A regression binds editing dependencies to throw and still exercises public delivery.
- A component may require no additional wrapper: embedded fragment roots are logical. Containers require a real layout/style/semantic reason. Never flatten by tag name alone or use blanket `display:contents`.
- Editor selection/drag/resize/placeholder code stays outside public output. Renderer 5 removes the reserved data-ak- attributes recursively after layout helpers finish, including nested sticky-header annotations; editor and historical renderer output stay unchanged. Functional slider data and accessibility references are allowed.
- Renderer `arkon-php-5` adds escaped Open Graph/Twitter summary tags from recorded title, description, site name and canonical origin. It selects immutable `components-5.js` for slider v8, whose keyboard navigation transfers focus to an available control or carousel root before making old content inert. Renderer 4 still selects components-4; old component/runtime bytes are unchanged.
- `OutputDiagnostics` inspects rendered body IR after shared components expand. It reports element count, depth, child fan-out, h1 count and HTML/CSS bytes, with advisory heading/link/description/budget warnings. Website readiness uses this shared report with publication-equivalent canonical/social head metadata and resolves site identity once; diagnostics do not alter output or publication inputs.
- Current review budgets: body elements 800, body depth 12, children 60, HTML 150,000 bytes, CSS 30,000 bytes. They are warnings, not publication blockers; full document DOM metrics also include head/html/body.
- Public page validators use framework weak/list/wildcard handling after resolving the current live row. No positive HTML cache TTL is introduced without durable publication-aware invalidation.
- Authorized images use BinaryFileResponse streaming. Private/member/signed delivery is private no-store, with no conditional validator; public immutable files have key-based ETags. Access is rechecked before returning files or 304s. Public images may remain in existing caches after withdrawal under the established one-year cache contract.
- robots.txt allows the public enhancement namespace and blocks the form submission subpath. SEO resources continue to use published state, never draft SEO.
- `npm run perf` now records actual document DOM size/depth/fan-out and editor-attribute count, alongside Lighthouse performance, accessibility and SEO audits. Local lab results do not establish field CWV or production load capacity.


## Admin information architecture

See [ADMIN_UX_REVIEW.md](ADMIN_UX_REVIEW.md) for the capability audit, route map, validation and remaining work.

- The Dashboard summarizes; dedicated screens manage. It sends at most five recent pages and recorded activity, whole-site counts, homepage links and pending review/update actions. It does not render the Pages management table or claim unmeasured health scores.
- The shared adminNavigation registry drives grouped navigation, permission visibility, active states, location labels and command destinations. Existing URLs stay supported. Content holds Pages, Media and Forms; Design holds Global styles, Reusable components, Navigation and Themes; AI holds the current website proposal workflow; Site management holds SEO, Performance/update status and Settings.
- Global styles and reusable components reuse existing versioned services on separated screens. Component return links point to /admin/design/components. Settings is an owner/admin read-only view until identity/domain settings are versioned; it authorizes on the server as well as filtering navigation.
- Command search uses a native dialog and keyboard-accessible combobox. Page lookup authorizes page.view, scopes the current site, excludes deleted pages, treats search literally, bounds text to 120 characters and results to ten, and returns metadata only. New modules add real routes/capabilities to the registry; absent modules do not get fake or disabled destinations.
- The Media screen reuses existing private-until-published upload/delivery rules and upload progress. The manager now queries 36 images per page with database search/sort, editable metadata and a native details dialog. Alternative text remains per placement, with library defaults for new selections. Pages uses explicit creation disclosure, filters/search and contextual actions with the original safe save/publish/delete services.
- Site SEO reads draft and published revision metadata separately, in pages of 50. Performance describes actual update status; field CWV remains Not measured without a connected monitoring pipeline. No expensive renderer or Lighthouse audit runs on Dashboard requests.
- Shared AdminPageHeader and existing UI tokens/components keep all management pages consistent in light/dark mode. The immersive page/component builders stay the same core editors; this is not a new builder implementation. Public output and immutable renderer/component versions are unchanged.
- Website structured-output schemas pass through SchemaCompactor: duplicate rules use shared references and internal definition names are shortened. User-facing fields and expanded constraints are identical (regression tested). This preserves the Windows CLI command guard as the catalogue grows, without changing validation or allowing model tools.


### Media asset metadata and library discovery

Migration `2026_10_16_000001_media_metadata` adds title, default alt/caption, editorial description, metadata_version and archived_at. `media_metadata_saves` is append-only for the runtime role. MediaLibrary performs site authorization, literal database search, stable sorting and bounded pages. Details expose canonical URLs, original facts, derivative previews and actual recorded size counts; no provider credentials or migration credentials enter the response.

Metadata updates use an asset row lock, optimistic version and canonical request fingerprint. An exact retry returns its immutable receipt, including after a newer metadata edit; a changed payload conflicts. Text limits use UTF-16 units, matching native controls and component fields. They never mutate file keys, rendered publication inputs or page descriptions.

Archiving requires the existing owner/admin page.delete capability. It hides future discovery but retains mediaMap/delivery behavior and all immutable files. Usage summaries are bounded advisory scans; safe removal does not depend on an exhaustive reference graph. Historical/live images therefore do not break under concurrent edits or cache reuse. Physical erasure, restoration UI and versioned file replacement remain separate future lifecycle work.

Hero v5, Image v6 and Logo v2 remove the blanket nonblank-alt publication requirement and preserve their predecessors' renderer/CSS. Old versions remain intact. Builder selection copies reviewable defaults into placement fields; already saved usage descriptions are retained and live output changes only by explicit publication. A future bulk default update must respect local overrides and use draft proposals, not mutate immutable HTML.

The Media manager and simple builder picker share upload and delivery services. The picker queries paginated server results and passes selected asset defaults into the normal undoable editor operation; management actions remain on the dedicated Media screen. Public pages load no additional admin assets or metadata scripts.

Verification: 366 server tests passed (2484 assertions); final metadata length/retry normalization and admin checks passed 9 tests (149 assertions). Typecheck/335 frontend tests and production build passed. The final combined media/admin/background-upload browser run passed 11 tests. All 47 development publications reproduce byte for byte in a read-only transaction. See `docs/MEDIA_UX_REVIEW.md`.

### Upload size policy and runtime headroom

`resources/arkon/media.json` is the shared PHP/TypeScript source for the inclusive 5 MiB byte limit, 12,000px dimension cap, 40MP total cap and 1 MiB multipart reserve. UploadPolicy reads serving PHP's upload_max_filesize and post_max_size and embeds the conservative effective file limit into admin HTML. Frontend file.size and server filesystem/data byte counts independently enforce the policy. We do not use Laravel's KB max rule for this upload. PHP limits should be 8M/10M; proxies need at least 10 MiB request capacity. Unknown external proxy limits cannot be auto-detected.

PHP upload failures, body 413s, corrupt/unsupported images, dimension failures, storage failures and optimization failures have distinct handling. Optimization failures preserve originals, log processing details, and return optimizationWarning to both library and builder uploaders. Memory estimates guard GD decoding/encoding; GIFs intentionally retain their animation without WebP conversion. The upload order remains authorization → source byte/MIME/dimension validation → immutable original storage → variant processing.

Serving PHP must use `display_errors=Off` and `log_errors=On`: PHP can emit a POST-size warning before Laravel runs. Printing that warning corrupts the API JSON envelope; logging retains diagnostics. The browser harness applies these flags explicitly.


## Forms workspace redesign

Forms has a dedicated library and per-form Build, Settings, Confirmations, Notifications and Entries workspace. Schema version 2 adds stable field identities, visual rows/columns, conditional fields, confirmations and multiple notifications. Existing schema-1 definitions and encrypted entries remain supported. Migration `2026_10_17_000001_forms_management` and the idempotent entry-index data upgrade preserve existing data. Form component version 4 and renderer versions 6/7 render modern definitions without rewriting old page revisions. Renderer 6 keeps immutable `forms-1.js`; renderer 7 uses `forms-2.js` for immutable public submission retries. Legacy renderer output remains reproducible.

See [FORMS_REDESIGN.md](FORMS_REDESIGN.md) for capabilities, permissions, native submission fallback, mail configuration, security and deferred features. Entry search matches whole words/full email addresses through keyed tokens. Notifications are synchronous best effort, not a durable delivery queue. Run normal migrations after updating; do not reset existing forms or entries.


### Forms drag feedback

Forms retains the shared pointer DragController lifecycle and adds Forms-specific content-space row geometry, insertion hysteresis, inert field previews and 160ms placement feedback. Measurements precede feedback transforms; scroll offsets are resolved in content coordinates. Auto-scroll uses pixels per second with an edge ramp. Pointer movement never saves or mutates field identity. Resize invalidates the drag. Reduced-motion skips placement animation. See [FORMS_DRAG_REVIEW.md](FORMS_DRAG_REVIEW.md) for the audit and verification.


### Page Builder drag feedback

Page and component editors share the Forms pointer controller and preview-offset/placement-animation primitives. Canvas previews preserve inherited typography and remain sandboxed; Layers and palette previews are inert. Temporary neighbour displacement is removed while measuring collision geometry, and placement animation respects reduced motion. Drops retain the existing structural operation, identity, version checks and one undo step. See [PAGE_BUILDER_DRAG_REVIEW.md](PAGE_BUILDER_DRAG_REVIEW.md).


### Native SEO workflow (renderer 8)

Page SEO remains inside the document and ordinary reversible operations. `SeoAnalysis` performs deterministic local HTML checks; the editor exposes a dedicated SEO tab and score. `SeoDashboard` keeps cached published analyses separate from current draft analyses. Global `site_seo_sets` drafts and immutable `site_seo_versions` snapshots publish through the per-site epoch and existing live-revision refresh queue. Renderer 8 records defaults and identity in render inputs; versions 1–7 stay unchanged. New heads include bounded escaped inert JSON-LD, explicit robots, social overrides and canonical overrides. Sitemap inclusion follows stored published robots/canonical values. Public visitors do not load SEO editor code or trigger analysis.

SEO AI text and selected-image alt actions are compiled to the existing operations and checked against their declared scope on the server. Consecutive SEO changes are coalesced to match the editor’s exact save batch. No automatic publishing, indexing changes, slug changes or design/content rewriting. See [SEO_REVIEW.md](SEO_REVIEW.md) for weights, walkthrough, tests and remaining limits.
