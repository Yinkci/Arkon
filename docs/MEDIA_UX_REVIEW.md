# Arkon media management review and implementation

The existing admin redesign already supplies grouped navigation, a dashboard overview, dedicated management screens, capability-aware links and command search. The second brief exposed a remaining gap: Media was a passive grid of the most recent 200 uploads, without an asset-management workflow.

## Implemented

- Click or press Enter on an image to open a native, keyboard-accessible details dialog. Move to nearby images in the current results page, close with Escape, and return focus to the original tile. Unsaved edits must be saved or discarded before closing or navigating.
- Edit Title, Alt text, Default caption and Description, with explicit Save details. Filename, immutable storage key and canonical URL remain unchanged. Viewers inspect; owners/admins/editors edit metadata using the existing media upload permission. Only owners/admins remove assets.
- Metadata saves have optimistic version checks, row locking, immutable save receipts and stable retry keys. A lost response leaves the exact save intent available for retry. Fields stay locked during an unconfirmed save; a delayed details read cannot overwrite typing.
- Search the database by title, original filename, alt text or caption. Sort newest, oldest, name, largest or smallest. The library retrieves 36 items per page; it no longer stops at the latest 200. Queries and detail requests remain site-scoped.
- Copy the permanent original asset URL with immediate feedback, including a fallback on Herd's HTTP origin. The UI never copies the canvas's temporary signed URL. Copying a URL does not make a private image public.
- Use a responsive WebP derivative for previews where available. Show original dimensions, MIME type, bytes and upload date, plus recorded responsive-size count and preview bytes. If no smaller sizes are recorded, say so. No invented processing state, compression percentage or AVIF output is shown.
- Removal is explicitly **Remove from library**, with confirmation and a bounded usage summary for live pages, page drafts and reusable component drafts. It archives discovery rather than destroying immutable files. Existing page references, published images and historical revisions keep working. Archived images disappear from ordinary library search and recent-upload choices.
- The lightweight builder picker now has server-backed search and pagination. Selecting an image carries its library defaults into the normal undoable edit. It does not embed the full manager inside the inspector.

## Alternative text and publication semantics

Alt text depends on an image's purpose in a particular page. The same file can be informative in one placement and decorative in another. Therefore, the library stores a default; each placement retains its own description. Default captions work the same way for ordinary Image blocks. Description is an editorial library note, not automatically rendered content.

New Hero v5, Image v6 and Logo v2 permit intentionally empty alternative text. They keep the same renderer markup and stylesheet as their predecessors. Old manifests, renderers, revisions and publications remain available; identity migrations upgrade editing documents in memory. Custom theme components continue to follow their declared rules.

Editing library defaults does not silently modify existing draft descriptions or published HTML. Select the image again to use its current defaults, review the placement, save and publish through the existing workflow. Editors already open when library metadata changes should reload to refresh their initial catalogue.

Arkon deliberately stores immutable public HTML. Moving alt-text delivery into client-side requests would add public complexity and undermine accessibility and publication reproducibility. If a future feature updates descriptions across existing placements, it should prepare a reviewable multi-page draft proposal and publish explicit approved changes, preserving local overrides and recorded historical inputs.

## Evaluated and deferred

- **Replacing a file under a stable media ID:** requires versioned file records and versioned URLs, pinned publication inputs and a reviewed update of affected pages. Old files and derivatives cannot be cleared while history still references them. The current safe workflow is to upload another asset, replace the chosen image in the builder, and publish. No misleading Replace button was added.
- **AI alt-text suggestions:** require a real, scoped image-reading workflow in the paired Claude Code integration. The existing proposal flow should not pretend to infer an image from its filename. A future suggestion must be reviewable, editable and validated before saving; it must not require an unsolicited separately billed API.
- **Bulk actions, list view, video/document filters and automatic optimization retries:** were considered. The current image-only workflow has no durable optimization-job state or supported non-image library. No fake filters, success statuses or empty management controls were introduced. Existing `arkon:media-variants` remains the recovery command for missing variants.
- **Physical deletion and retention:** archive currently retains files indefinitely to protect pages, history and immutable cached URLs. A future storage cleanup needs an explicit retention policy and complete historical-reference analysis.
- Nearby-image navigation stays within the current results page. Usage summaries are bounded to 20 displayed names per category and are advisory, not a complete cross-resource reference index. Removal safety does not depend on those counts because files remain retained.

## Validation

- Complete server suite: **366 tests, 2,484 assertions passed**.
- Focused media, publishing, rendering, history and recovery suite: **79 tests, 467 assertions passed**.
- Final metadata input normalization/UTF-16 length guard and admin permission checks: **9 tests, 149 assertions passed** after the full suite.
- Frontend typecheck and **335 unit/conformance tests passed**; production build passed.
- Browser coverage includes metadata editing and persistence, literal database search, safe removal, canonical URL copying on HTTP, a delayed detail read, exact retry after a committed response is lost, keyboard navigation, narrow layouts, dark mode, and the existing page/component background-upload workflows. The final combined run passed **11 browser tests**, including authentication setup.
- Read-only verification: **all 47 development publications reproduce byte for byte**. No development pages, accounts or running real Claude helper were changed. No real model calls were made.

The additive metadata migration was applied using Arkon's migration command, without resetting development data. Existing accumulated work is preserved. Nothing is committed or pushed.

Open **http://arkonlaravel.test/admin/media**, then click an image. Refresh the browser for the built admin assets. A previously running AI helper/MCP process needs a restart to discover the new component versions.
