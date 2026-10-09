# Admin design system

The dashboard, admin screens and both builders share one small system. Published sites never load
any of it (`resources/css/app.css` is admin-only).

## Why this exists

The October 2026 audit found the admin drifting apart:

- Tokens existed, but screens built after the first round (Navigation, Forms, Build a website,
  newer inspector bodies) skipped `Components/ui.tsx`. They had their own input strings,
  38–40 px buttons, `text-white` / `text-red-600` / `text-amber-700`, and unstyled checkboxes
  and progress bars.
- 112 arbitrary text sizes (`text-[11px]`, `text-[0.8125rem]`, `text-[10px]`), five radius
  sizes, and stray `shadow` / `shadow-xl`.
- Six different page-container recipes, so titles started at four different x positions.
- Cobalt was used for everything (primary buttons, links, active nav, selection), so nothing
  stood out.

The fix is the system below. Use it instead of one-off classes.

## Identity

Precise, calm, technical. **Ink** (the Arkon mark's colour) carries the single primary action of
a view. **Cobalt** (`accent`) marks selection, focus, links and the current place, and nothing
else. Neutrals are slightly warm. Hierarchy comes from type, spacing and surface, not from
nested boxes. The crop-mark motif (login card, selected part on the canvas) is the one
decorative signature.

## Tokens (`resources/css/app.css`)

**Dark-neutral scale** (the only dark tones; no pure black, no one-off near-blacks): `dark-1` #1f1f1f (dark-mode canvas, wells) · `dark-2` #262626 (dark-mode panels, light-mode text) · `dark-3` #2d2d2d (base: navigation rail, primary actions) · `dark-4` #363636 (hover, elevated) · `dark-5` #404040 (active, pressed, rail dividers) · `dark-6` #4a4a4a (strong edges in dark mode). Shadows, `--ak-hover` and the dialog `scrim` use the same neutral (`--ak-dark-rgb`). Tailwind's default shadow scale is removed and `black` maps to `dark-1`, so stray `shadow-xl` or `bg-black` cannot reintroduce pure black.

| Role | Utilities |
|---|---|
| Surfaces | `bg-canvas` (frame, canvas stage), `bg-surface` (the sheet, panels), `bg-raised` (quiet wells), `bg-sunken` (segmented tracks, chips), `bg-hover` (every hover tint) |
| Lines | `border-line` (dividers), `border-line-strong` (control borders, dashed empty states) |
| Text | `text-fg`, `text-muted` (secondary), `text-faint` (tertiary, icons at rest) |
| Actions | `bg-ink` / `text-ink-fg` (primary: dark-3, hover dark-5, pressed back to dark-3; inverted to light in dark mode), `accent` family (selection, focus, links) |
| States | `live`, `changed`, `danger`, `ai`, `site` (each with `-soft`), `draft`; always with an icon or words |
| Navigation rail | `bg-nav` (#2d2d2d, both themes), solid steps `bg-nav-raised` / `bg-nav-hover` / `bg-nav-active`, `border-nav-line` (inside), `border-nav-edge` (against the workspace); text `text-nav-fg` (active, 13.2:1), `text-nav-text` (labels, 9.5:1), `text-nav-muted` (icons, secondary, 6.0:1), `text-nav-faint` (group headings only, 4.7:1); `nav-accent` #9db0ff for the active bar and icon (5.0:1 on the active row) |
| Elevation | `shadow-hairline` (controls), `shadow-raise` (selected segment, active nav item, floating toolbars), `shadow-pop` (menus, dialogs, drags), `shadow-ring` (focus halo) |

**Type scale:** `3xs` 10 · `2xs` 11 · `xs` 12 · `ui` 13 · `sm` 14 · `base` 15 · `lg` 17 · `xl` 19 · `2xl` 22 px.
**Type roles:** `t-page` (screen title), `t-section`, `t-title` (rows, cards, panel headers),
`t-body`, `t-meta` (secondary lines), `t-label`, `t-eyebrow` (small caps group headings), `t-num`
(tabular figures).

**Radius:** `rounded-sm` 4 (chips, kbd) · `rounded-md` 6 (controls) · `rounded-lg` 8 (panels,
menus, cards) · `rounded-xl` 10 (the sheet, dialogs). No `rounded-full` except dots and avatars.

**Spacing:** Tailwind's 4 px grid. Page sections are 32–40 px apart, groups 16–24 px, a label and
its control 6 px, controls in a group 12 px.

**Density:** type roles, `Button` `md`, `.ui-input` and labels read density variables (`--ak-d-*`,
`--ak-control-*`). The builder uses the default dense scale (13 px body, 32 px controls). Admin screens render
inside `.ak-comfortable` (set by `AdminLayout`): 14 px body, 13 px meta and labels, 18 px section titles, 28 px page
titles, 36 px controls. Change the scale in `app.css`, never per component. Builder panels go one step denser
(`.ui-dense`: 28 px inputs). Icons are 16 px in controls, 18 px in navigation and quick actions.

## Components (`resources/js/Components/ui.tsx`)

- `Button`, `ButtonLink`, `IconButton`, `buttonClass()`: variants `primary` (ink), `secondary`,
  `ghost`, `danger`, `quiet-danger`. Use `buttonClass()` on a raw `<button>` or `<a>` that
  can't be the component.
- `PageShell` (or the `ak-page` class) plus `AdminPageHeader`: every admin screen. Titles align across screens and
  actions align with the title.
- `SectionHeader`, `Panel`, `EmptyState`, `Notice`, `StatusPill`, `Segmented`, `Skeleton`,
  `Spinner`, `Avatar`, `Kbd`.
- Scroll areas: `ak-scroll` (thin inset thumb, no track or arrows, stable gutter; clearer on hover and drag), `ak-scroll-dark` on the rail (and automatically in the dark theme), `ak-scroll-fade` for a 12 px edge fade. Used by the navigation rail, builder panels, the command palette and the media dialog; the page itself keeps the browser scrollbar. The rail scrolls only its navigation: identity and account stay fixed, and the current destination is scrolled into view.
- Form classes: `.ui-input`, `.ui-field` (a label that wraps its control), `.ui-label`,
  `.ui-hint`, `.ui-check`, `.ui-link`, `.ui-activity` (indeterminate progress).

## Admin shell (`Components/AdminLayout.tsx`)

- A graphite rail (darker than the workspace in both themes): site identity at the top, Dashboard pinned,
  then collapsible groups (remembered; the group holding the current screen stays open), then account,
  theme and collapse at the bottom. Collapsed mode shows icons with tooltips.
- Active item: lighter rail surface, a 3 px `nav-accent` bar, an accent icon and bright text. Hover: a faint
  surface and brighter text, 100 ms. No filled pills.
- The workspace is the light canvas with a sticky 64 px header (breadcrumb, search, View site). Content sits in
  white panels only where it is a distinct object; panels share one heading row (`PanelHeading` in the dashboard).

## Builder (`resources/js/editor/chrome.tsx`)

- `EditorToolbar`: identity (back, title, path, live state) on the left; viewport and undo/redo
  centred over the canvas; save state, View live, Preview, Save draft and Publish on the right.
- `EditorWorkspace`: at ≥ 1536 px, Outline (Layers, History, AI) on the left, the canvas, and
  Properties always on the right. The outline can be hidden and the choice is remembered.
  Narrower screens keep one tabbed sidebar, because the desktop canvas never drops below 960 px.
- Design properties are **property rows**: the name (bold with a cobalt dot when set here,
  plus an override badge) beside the control, and Reset as an icon after it. See `StyleControl`
  and `QuickChoice`. Content fields keep the label-above form layout.
- Canvas: hover is a thin cobalt line, selection a 2 px outline with a name and size tag, and the
  active part gets crop-mark corners. Duplicate, Delete and Move float beside the selection.

## Checklist for a new screen

1. `PageShell` + `AdminPageHeader`. No custom container.
2. Only `Button`/`buttonClass`, `.ui-input`/`.ui-field`, and the components above. No hex
   values, no `text-[…px]`, no `rounded-full` buttons.
3. One ink button per view. Cobalt only for selection, links and focus.
4. Group with spacing and a hairline before reaching for a bordered box. Never put a panel
   inside a panel.
5. Check light, dark, 1280 px and 390 px: `$env:SCREENSHOTS='label'; npx playwright test e2e/screenshots.spec.ts`.
