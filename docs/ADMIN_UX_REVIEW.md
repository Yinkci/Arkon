# Arkon admin UX and information architecture review

9 October 2026 · `C:\Herd\ArkonLaravel`

## Decision

**The Dashboard summarizes. Dedicated screens manage.** The existing editing, draft, review and publishing systems are retained; the admin organization is changed around them. Public rendering and immutable publication inputs are untouched.

## Findings before implementation

| Priority | Problem | Implemented solution |
|---|---|---|
| High | Dashboard duplicated Pages with a full management table, destructive actions and status filters | Five recent content links, whole-site counts, homepage shortcut, concise recorded activity and pending work |
| High | Flat sidebar mixed content, design, AI and site operations | One capability-aware destination registry grouped into Overview, Content, Design, AI and Site management |
| High | Design combined global styles, components and failed refresh operations | Separate Global styles and Reusable components screens; operational update status has its own Performance & updates destination |
| High | Media existed in builder pickers but had no management destination | Dedicated searchable image library and upload action using the existing validated upload pipeline, privacy policy and progress reporting |
| Medium | Metadata and indexing choices were buried in individual builders | Paginated SEO overview distinguishes saved draft settings from published revision settings and links into the same builder |
| Medium | Site identity and domain configuration had no discoverable home | Permission-gated Site settings / General overview; configuration is explicitly read-only until a versioned settings editor exists |
| Medium | Navigation, breadcrumb labels and entry points had separate assumptions | Shared route metadata drives sidebar groups, active state, breadcrumb context and navigation commands; component return links now lead to the component list |
| Medium | Pages exposed creation and destructive row actions permanently | Explicit New page disclosure, title/URL search, status filters, clickable titles and contextual More actions; preview remains available to page viewers |
| Medium | No universal way to find a destination or page | Ctrl/Cmd+K command search with capability-filtered destinations and bounded, authenticated, site-scoped page search |
| Medium | Management headers and visual hierarchy differed between modules | Reusable AdminPageHeader across Pages, Design, Media, SEO/settings, Navigation, Forms, Themes and AI website generation |
| High, found during workflow validation | Website generation exceeded the Windows CLI preflight limit after the catalogue grew | Share identical JSON-schema rules and shorten internal definition names. Expanded constraints are exactly equivalent; no fields, components or validation rules are removed. The measured website schema falls from 29,611 to 27,271 bytes and has regression coverage for command overhead |

Existing buttons, fields, native confirmation dialogs, icons, status marks, date helpers and light/dark tokens were reused. This avoids a second design system or a new UI dependency.

## Navigation and routes

| Area | Destinations | Existing/new URL |
|---|---|---|
| Overview | Dashboard | `/admin` |
| Content | Pages, Media library, Forms & enquiries | `/admin/pages`, `/admin/media`, `/admin/forms` |
| Design | Global styles, Reusable components, Navigation, Themes | `/admin/design`, `/admin/design/components`, `/admin/navigation`, `/admin/themes` |
| AI | Build a website | `/admin/website` |
| Site management | SEO overview, Performance & updates, Site settings | `/admin/seo`, `/admin/performance`, `/admin/settings` |

The existing page builder remains `/admin/editor/{page}` and the reusable-component builder remains `/admin/components/{component}`. There is no second builder. No existing route is removed or renamed. Global styles and components use the existing Design service APIs; their presentation is separated. Existing bookmarks to `/admin/design` now open Global styles, with the component list available in the Design navigation group.

Groups stay expanded because there are few shallow destinations; this makes the available functions discoverable. Desktop users can collapse to labelled icons. Smaller screens use a Menu disclosure that closes on navigation and Escape. The sidebar scrolls independently on shorter desktop screens. The top bar provides location, search and View site; account and colour preference remain in the sidebar rather than being duplicated.

## Dashboard behavior

The overview shows real page/live/draft counts, not invented performance, SEO or security ratings. Recent content and activity are limited to five entries; pending proposals are the current user's proposals. The homepage shortcut recognizes both the saved root path and the currently live root path, so an unpublished URL rename does not bury the live homepage.

A compact setup prompt is shown only while there is no homepage or no published content. It points to pages, navigation and site identity. It is not a permanent seven-step checklist for functions that do not exist.

Publishing failures and pending design updates point to their operational detail screen. Unpublished styles point to Global styles; changed components point to Reusable components; draft page changes point to the Pages filter. The AI entry prepares a reviewed website proposal. Page-specific requests remain in the builder where they already work.

## Capability inventory and honest boundaries

- **Pages:** working builder, draft metadata, history, review, publication, unpublish and retained-history delete. Page duplication, bulk actions, authorship display and a restore-from-trash workflow are not introduced by this review.
- **Media:** managed images and private delivery; a dedicated library now exists. The manager now offers paginated database search/sort, clickable details, title/default alt/caption editing, URL copying and safe archival removal. Placement descriptions remain context-specific and published snapshots remain immutable. See the media review for details.
- **Design:** actual versioned global styles, reusable components, navigation, shared header/footer and theme packages. Patterns/templates already exist in the builder/theme catalogue; a separate template management subsystem does not.
- **AI:** existing page and multi-page proposal workflows plus their review/history views. There is no general agent that can diagnose performance, manage arbitrary settings or create articles. The UI does not promise those actions.
- **SEO:** actual descriptions and indexing preferences, published sitemap and robots routes. The overview is not an SEO score, backlink tool or confirmation of search-engine indexing.
- **Performance:** actual design-update status and retry controls. There is no connected real-user CWV monitor or live site audit scheduler; the screen states “Not measured.” Previous local fixture benchmarks are not presented as the owner's live metrics.
- **Settings/users:** site identity/domains can be reviewed by owners/admins. Accounts and memberships currently use setup/CLI workflows; editable versioned identity, site switching and a user-management UI remain future work.
- **Posts, taxonomies, comments, analytics, plugins/integrations:** no complete management systems exist. They are not added as empty or disabled sidebar items.

## Permissions and accessibility

Navigation is filtered using server-provided capability flags, not a new invented Designer role. AI generation requires page editing; media requires media viewing; site settings requires publishing privileges. Settings also authorizes the request on the server. Search authenticates and authorizes, filters the active site and deleted pages, interprets search text literally and caps results at ten. It never searches another site's pages or returns page documents.

Command search uses a native modal dialog, labelled combobox/listbox selection, Escape, Tab, arrow keys and Enter. Selection scrolls into view. Fetches are debounced and aborted when superseded or closed. Existing native confirmation dialogs still enforce stale-version checks and typed deletion confirmation. Common focus indicators and dark-mode tokens are retained; the shell provides a skip link.

The work does not claim an independent accessibility certification. Manual screen-reader sessions and testing across assistive technology/browser combinations remain useful before public release.

## Validation

| Check | Result |
|---|---|
| Full PHP regression suite after admin implementation | 360 tests, 2,447 assertions passed |
| Targeted suite after final schema transport fix | 19 tests, 122 assertions passed, including exact expanded-rule equivalence, reserved Windows budget, website workflow and renderer compatibility |
| Frontend typecheck and unit/conformance tests | Passed; 335 tests |
| Production asset build | Passed |
| Main browser workflow run | 21 passed; website generation exposed the existing CLI schema-size failure |
| Website browser rerun after its fix | All 3 checks passed, including complete multi-page proposal/apply/publish/contact submission and generation progress/failure reporting |
| Formatting and whitespace | Passed |

The main browser run covered the dashboard/homepage entry, command search, media upload, mobile dark mode, page creation/rename/unpublish/delete, editor permissions, navigation, forms, styles, reusable components, drag/drop, AI token review and theme activation/retry. Earlier runs revealed selectors that needed updating for explicit creation, contextual actions and clickable page titles; corrected scenarios then passed. The final website transport change was covered by the targeted PHP suite and a separate browser rerun, not presented as a new full-suite run.

Visual review inspected a desktop Dashboard capture and a 390-pixel mobile dark-mode Pages capture. The mobile check verifies no horizontal document overflow. Screenshots use isolated fixture data, not the real owner's content.

Real development pages, uploads, accounts and live publications were not changed for validation. Browser fixtures use a separate database and fake Claude Code; no real model requests were made. Earlier uncommitted work is preserved. Nothing is committed or pushed.

Refresh the browser to use the built admin assets. Restart a previously running `php artisan arkon:ai-helper` when convenient to load the website-schema transport fix; the user's running helper was left untouched. The UI redesign itself does not require a Node server or a database migration.

## Next priorities

1. Version site identity/domain settings before adding editable controls; introduce explicit user-management capabilities with account/membership workflows.
2. Scale Pages with server-side pagination/search (Media now has this) and persist filter/sort preferences. The Dashboard payload is bounded, but its counts currently derive from the existing page metadata listing; larger sites should use database aggregation. Design views still obtain both style/component service state, even though only one management surface is shown.
3. Connect asynchronous diagnostics and real field monitoring before adding health scores or actionable audit summaries. Keep “not measured” distinct from “healthy.”
4. Separate form definitions and enquiry management into tabs when submission volume warrants it; add retention/export and delivery retry policies with the corresponding service implementation.
5. Build collection/article semantics before exposing Posts, categories or tags. Add contextual AI capabilities only after the corresponding operation/review system exists.

The destination registry is the extension point for future admin modules: add a real route, a label/group, an icon, capability requirements and active-route aliases in one place. Do not scatter sidebar definitions or expose unfinished modules as working features.


## Media follow-up

The media extension is implemented and reviewed in [MEDIA_UX_REVIEW.md](MEDIA_UX_REVIEW.md). It adds asset management without changing the established admin hierarchy. The additive metadata migration was applied; existing page content and publication history were preserved.
