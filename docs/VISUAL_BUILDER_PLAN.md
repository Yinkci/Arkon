# Visual builder foundation — implementation plan

Goal: describe a site and have Claude (or a person) build it from native, visually editable components, with
published pages that stay fast. This plan covers design controls, layout, responsive editing, tokens, reusable
components with published dependencies, AI and performance. It is implemented in the stages below; status at the end.

## Review: what was missing

| Area | Before | Gap |
|---|---|---|
| Design controls | a few enums per component (`size`, `align`, `stackOn`, `gap`, button `style`) | no shared model; no dimensions, spacing, colours, typography, borders, image fit |
| Layout | page → blocks, Columns → Column (equal widths) | no sections/containers, proportions, alignment, nesting depth policy |
| Responsive | fixed per-component stacking | no per-breakpoint overrides or inheritance |
| Shared styles | fixed CSS variables in the base stylesheet | no editable, versioned tokens |
| Reusable components | none | definitions, linked instances, detach, publish + dependants |
| Dependencies | publication inputs, `prepareRerender`/`commitRerender` (epoch-ordered) | no dependency records, no refresh queue/status |
| Canvas | click to select, inline text | no drag and drop on the canvas |
| Images | original file, width/height, LCP heuristic for the first section | no srcset/variants/modern formats; nested images never prioritised |
| AI catalogue | generated from manifests | could not express any design; instructions said heights/backgrounds don't exist |

## Design decisions

1. **One style model.** A new prop type `style` (PHP `PropSchema` + TS twin) holds
   `{slot: {base?: {prop: value}, tablet?: {…}, mobile?: {…}}}`. Each component version declares its *slots*
   (named parts such as `root`, `media`, `heading`) and which *style groups* each slot allows. Properties, value
   formats, bounds, units, CSS mapping and group membership live once in `resources/arkon/rules.json` (`style`), read by
   PHP (validation + CSS) and TypeScript (validation + inspector). Values are strings (or `{assetId}` for background
   images) validated against an allowlist: lengths with allowed units and bounds, hex colours, enums, unitless numbers,
   ratios, grid fractions, and token references (`@color.primary`). No raw CSS.
2. **Responsive = desktop-first overrides.** `base` applies everywhere; `tablet` (≤ 899px) and `mobile` (≤ 599px)
   override only what they set; mobile inherits tablet, which inherits base. The same two media queries serve canvas
   (iframe width), preview and published pages. Sensible mobile defaults are explicit overrides in `defaultProps`
   (visible and resettable in the inspector), not hidden CSS.
3. **CSS generation.** The renderer turns each styled slot into a deterministic class `ak-s<hash>`; identical
   declarations share one class (deduplicated across the page). Rules are emitted after component CSS, base rules first,
   then one `@media` block per breakpoint. Only the CSS of components used and the token variables referenced are
   emitted. Everything stays inline in the page's `<style>` (no extra requests).
4. **Tokens** are a versioned site resource (draft → published versions, immutable). Fixed slots (colours, fonts as
   system stacks, type scale, spacing, container widths, radii, shadows) with defaults in `rules.json`. Blocks reference
   them as `@group.name` → `var(--ak-t-group-name)`; the published page defines only the variables it uses, with the
   *published* values. Drafts never reach live pages.
5. **Reusable components** are versioned site resources whose content is a fragment document (same nodes and
   validation). Pages use an `instance` block that renders the definition's *published* version. Permitted instance
   overrides: the instance's own outer style (spacing, width, alignment); content changes need the definition or
   *Detach* (copies the published content into the page as ordinary blocks, one undo step). Instances cannot nest.
6. **Published dependencies.** A publication records the token version/values and definition versions it used
   (render inputs, for reproduction) and dependency rows. Publishing tokens or a definition records refresh jobs for
   every live page that depends on it, then processes them: each re-renders the page's *live revision* (never its
   draft) with the newly published resources through `prepareRerender`/`commitRerender` (epoch-ordered; a page
   republished meanwhile is left as is). Status per page: pending / done / skipped / failed, with retry. Synchronous
   processing after commit; no workflow framework.
7. **Component versions.** Changed rendering contracts get new versions with migrations: page v3, hero v2, text v2,
   image v3, button v2, columns v2, column v2; new: section v1, group v1, instance v1, fragment v1 (definition root).
   Old versions, their renderers and CSS stay; old publications reproduce byte for byte; old drafts migrate on open.
8. **Limits.** Nesting depth ≤ 8 (page counts as 1), ≤ 2,000 nodes per document (the existing limit), containers
   ≤ 30 children (page 50), Columns ≤ 6, background images base-only. Validated in both languages.
9. **Accessibility.** DOM order = Layers order = reading order. Visual reordering is limited to a container's
   direction (`row-reverse`, `column-reverse`) per breakpoint; headings keep their level, images need alt text,
   links keep the safe-link policy, move buttons stay alongside drag and drop.
10. **Images.** On upload (and by a backfill command), WebP variants at standard widths are generated with GD and
    stored privately next to the original; delivery checks the parent asset's privacy. New image/hero versions render
    `srcset`/`sizes`, intrinsic `width`/`height` to avoid layout shift, `fetchpriority="high"` + eager loading for the
    first image within the first two top-level blocks only (changed after measuring: a leading heading is common),
    lazy loading otherwise.
11. **AI.** The same catalogue (generated from manifests + the style registry) serves the helper and MCP. Proposals can
    set styles (deep-merged into existing styles in updates, `null` removes), use tokens and insert instances.
    Site-wide token changes are a separate, explicit part of a proposal (`tokenChanges`) shown apart from page changes
    and applied only to the *token draft* by a separate button; definitions are not changed by page prompts.

## Stages

1. Style registry, `style` prop type (PHP + TS), CSS generator, conformance fixtures.
2. New component versions, renderers, migrations, depth/node limits, image variants and priority.
3. Inspector: grouped design controls, breakpoint-aware overrides with inheritance and reset; layout insertion.
4. Canvas drag and drop with placement feedback.
5. Tokens and reusable components: tables, services, editors, dependencies, refresh with status and retry.
6. AI: catalogue/schema/compiler for styles, tokens and instances; both paths; acceptance case.
7. Performance: production build, Lighthouse (mobile, 3 runs × 3 fixtures), fixes.
8. Documentation.

## Status

All stages are implemented (uncommitted, for review). Where the implementation differs from the decisions above:
the AI schema adds blocks one at a time with named references instead of nested trees (the nested schema exceeded the
Claude Code command-line limit); `direction` also sets a flex-basis variable so stacked parts keep their height
(found by the browser tests); AVIF variants were left out (encoding cost on upload). Details: [ARCHITECTURE.md](ARCHITECTURE.md)
("Visual builder foundation"), measurements: [PERFORMANCE.md](PERFORMANCE.md).
