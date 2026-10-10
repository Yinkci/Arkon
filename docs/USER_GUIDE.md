# Arkon user guide

How to use the admin and the visual builder. Setup is in the [README](../README.md); AI setup is in
[AI_SETUP.md](AI_SETUP.md).

## Admin

- **Dashboard** (`/admin`): site overview, items that need attention (AI proposals waiting for review, pages with
  unpublished changes, live pages not yet updated after a design change), recent content, quick actions and recent
  activity. The light/dark/system theme switch is at the bottom of the left menu and applies to the builder too.
- **Navigation**: Content (Pages, Media library, Forms), Design (Global styles, Reusable components, Navigation,
  Themes), Build a website, and Site (SEO, live-page updates, settings).
- **Lists** (Pages, Forms, Media library) share one pattern: status tabs with counts, search, a checkbox per row with
  select-all, a bar of bulk actions while something is selected, a **More** menu per row, and a **Trash**. Changes
  show at once with a short confirmation message; nothing needs a browser reload.
- **Trash** (owners and admins): **Move to Trash** asks once (no typing) and takes a live page offline at once,
  freeing its URL. In the Trash, **Restore** brings the same page, form or image back (same address, content, entries
  and history; a restored page is unpublished until you publish it), and **Delete permanently** removes it for good:
  it cannot be restored. Revisions and publications stay in the audit record; a form’s entries are deleted with it
  (the confirmation says how many). A form used on a live page must be removed from that page first. Images in the
  Trash keep working where they are used; there is no permanent deletion for images yet. Nothing empties the Trash
  automatically.
- **Roles**: owner and admin can do everything; editors draft (pages, titles, URLs, forms, media) but cannot change
  what is live; viewers can only look.

## Editing a page

Open **Pages → a page → Edit**. Click text on the canvas and type, or use **Properties**.

- **Saving**: Ctrl+S saves; Ctrl+Z / Ctrl+Shift+Z undo and redo outside text fields. The toolbar says what state
  the draft is in (Draft saved, Unsaved changes, Saving…, invalid fields not saved, Out of date). **Save draft**
  never changes the live page, **Preview** shows the saved draft exactly as it would be published, and **Publish**
  makes it live. **History** lists every revision; **Restore** copies one into the draft. Saving and publishing
  retry safely after network trouble and never apply a change twice.
- **Workspace**: on screens 1536 px and wider the outline (Layers, History, AI) is on the left and Properties on the
  right; the sidebar button next to the back arrow hides the outline. Narrower screens use one sidebar with tabs.
- **What you are editing** is named at the top of Properties as a path (Page › Section › Hero section), with buttons
  for the parts of a block (for a hero: content area, heading, text, buttons, image).
- **Title and URL**: Properties → Page → Title and URL. The live page keeps its old URL until you publish; then the
  old URL redirects (301).

### Blocks and structure

- **Layers → Add**: Section, Group, Hero, Text, Image, Button, Columns, Form, Navigation, Logo, Icon, Slider,
  Back to top, and published reusable components. A block goes after the selection, or inside a selected container.
  **Starting layouts** insert ready-made headers, heroes, service cards, newsletters and footers as ordinary blocks.
- **Columns**: a layout picker (2–6 equal columns, thirds, sidebar …). Selecting a Columns block shows **Number of
  columns**, **Widths** (equal, proportions or your own such as `1fr 2fr 1fr`) and **On smaller screens** (stacked,
  side by side, or 2–5 per row). Removing columns that hold blocks asks whether to move or delete their content.
- **Duplicate**: the toolbar, the copy icon on a Layers row or the canvas, or Ctrl+D. The copy goes right after the
  original; one Ctrl+Z removes it.
- **Delete**: the trash control on the canvas, or Delete/Backspace when the canvas or a Layers row has focus. Blocks
  with content ask first; everything can be undone. The page itself and the last column cannot be deleted.
- **Dragging** works in the page and reusable-component editors: drag the selected block by its **Move** handle,
  any block by the handle on its outline, a Layers row, or a palette item. A line or outline shows where it lands
  and a label names the place; places the nesting rules don't allow say why. Hold near the top or bottom edge to
  scroll. **Esc** cancels; a drop is one undo step. The ↑ ↓ buttons are the keyboard alternative.

### Design and responsive settings

- **Design controls** are named for what they change ("Image height", "Section width"). Lengths are a number plus a
  unit (px, %, rem, em, vh, vw, ch), a keyword such as Auto, or a site token. Each control says whether its value is
  set here, inherited or the default; **Reset** removes it. Invalid values are shown and never applied.
- **Screens**: Desktop / Tablet (899 px and narrower) / Mobile (599 px and narrower). All-screens values apply
  everywhere; tablet and mobile override only what you set there.
- **Backgrounds** can be colours, two-colour gradients or images. There is no arbitrary CSS and no user scripts.
- **Animation** (bottom of Properties): entrance effects (fade, fade up/down/left/right, subtle zoom), on page load or
  when scrolled into view, with duration, delay and easing. The page's main heading and first image always appear
  immediately; visitors who prefer reduced motion see the final state.
- **Links** must start with `/`, `#`, `https://`, `http://`, `mailto:` or `tel:`. A link that isn't valid yet stays
  in the field, is not saved, and blocks Preview and Publish until it is fixed or reverted. New buttons default to
  `#`, which publishes with a readiness warning.

### Sliders

A Slider holds 1–6 slides of ordinary content. Settings cover autoplay (off by default; pauses on focus, hidden tabs
and reduced motion, optionally on hover), the interval (1–60 s), transitions (slide, fade, instant), arrows
(placement, appearance, size), pagination (dots, numbers, bars; alignment and position) and per-screen overrides.
Without JavaScript the first slide stays readable. **Play slideshow** in the inspector previews playback on the
canvas.

## Media

`/admin/media` shows 48 images per page as a compact grid or a list (name, type, dimensions, size, optimization,
upload date); the choice is remembered. The grid loads small WebP thumbnails, never the originals. Search and sort run
on the server. Select images to move them to the Trash together (with one summary of where they are used). Click an
image for its details: preview, title, default alt text and caption, description, file details,
the permanent URL and the generated WebP sizes. Uploads are JPEG, PNG, GIF, WebP or AVIF, up to 5 MiB,
12,000 px per side and 40 million pixels. Smaller WebP copies are made automatically and published pages pick the
right size per screen. Images stay private (404 to visitors) until a published page uses them. **Remove from
library** archives an image without breaking pages that use it.

In the builder, select an image for **Image size**, **Fit and crop** and **Crop position**, choose from the library
or upload, and describe informative images (leave alt text empty for decorative ones).

## Forms

**Forms → New form** creates a form (contact, newsletter and others). Build its fields, set the confirmation
(message or redirect) and optional email notifications, then **Publish** it and place it with a Form block.
Submissions are validated, rate limited, protected by a honeypot and stored encrypted; review them under the form's
**Entries** (export needs the export permission). Newsletter forms collect signups locally; they do not connect to an
email marketing service.

## Design system and reusable components

The **Design** page holds the site's design tokens (colours, fonts, type scale, spacing, widths, radii, shadows) and
reusable components. Changes are drafts until you **Publish** them there; publishing updates every live page that
uses them, and pages that could not be updated are listed with **Retry now**. **Detach** in an instance's properties
copies its blocks into the page.

## Navigation and shared layout

**Navigation** creates menus with one level of dropdowns, linking to pages (which follow the page's published URL),
custom URLs or section anchors (set in a section's **Anchor** field, e.g. `#services`). Menus are drafts until
published. The same screen links to the site's shared header and footer, and **Prepare missing header and footer**
creates a reviewable proposal without using AI.

## SEO

The editor's **SEO** tab (or the score in the toolbar) holds the search title and description, focus topic, social
share text and image, canonical URL, indexing and structured-data type. An explainable score out of 100 updates as
you type; each check says what it measures. **Generate** and **Improve SEO with AI** propose metadata for you to
review; nothing is applied or published without your click. **Site management → SEO** shows the site score (the average of
published pages), issues on live pages ranked by how many points they cost (with the pages affected and whether AI can
draft a fix), draft and live scores for every page with health filters and search, and grouped technical checks
(indexing, sitemap, robots.txt, metadata, structured data, links, canonical URLs). **Fix with AI** opens the page’s SEO
tab at **Improve SEO with AI**; nothing is sent until you click it. Site-wide SEO defaults (title pattern, description, social image, indexing)
are saved and published from **Site SEO defaults** there. Published pages carry canonical and social metadata, and the site serves `sitemap.xml` and
`robots.txt`. Details: [SEO_REVIEW.md](SEO_REVIEW.md).

## Build a website

**Build a website** (`/admin/website`) turns one brief into a multi-page proposal with shared header, footer,
navigation, branding and a contact form. Review the pages, apply them as drafts, then publish explicitly. Readiness
checks list what still needs attention (placeholder links, missing descriptions). See
[WEBSITE_WORKFLOW.md](WEBSITE_WORKFLOW.md).

## Themes

Developers can add components in `themes/mysite` (see [THEMES.md](THEMES.md)); **Appearance → Themes** previews and
activates a theme. Installing a theme never publishes a page.
