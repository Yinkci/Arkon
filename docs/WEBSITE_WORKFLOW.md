# Build a website from a brief

Open **Build a website** in the admin menu (`/admin/website`). Describe the business, the pages you want, the style and the information you actually know. Example:

> Create a professional landscaping website with Home, About, Services and Contact pages. Use a shared header, footer and navigation, restrained green branding, and a contact enquiry form. Do not invent our address, phone number, testimonials or prices.

The existing locally paired Claude Code helper must be running and signed in with your subscription. This workflow makes no provider API calls and needs no API key. Restart the helper after updating application code. Alternatively, restart the Arkon MCP connection in Claude Code, ask it to use `arkon_get_website_context` and `arkon_submit_website_proposal`, then review the result on this same admin screen.

## The workspace

The screen is a workspace, not a form. The **AI panel** on the left holds the request, its progress and the current
proposal; its **History** tab lists every request and revision (title, status, time), and choosing one shows it.
Change lists (**View changes**), failure details and **Developer details** (provider, request id, run timing, every
step) are collapsed until asked for. The rest of the screen is the **website canvas**: the real server-rendered page of
the proposal in an isolated frame, at a true device width (Desktop 1440 px, Tablet 820 px, Mobile 390 px) scaled to
fit (**Fit**, or 100/75/50 %), scrolling inside the frame like the live site. The canvas toolbar chooses the page,
the device and the zoom, switches a revision between **Before** and **After**, and holds **Publish website** once a
proposal is applied. Hide the AI panel (‹) or use **Full screen preview** (Esc returns) to
judge the design on its own. On narrow screens the panel opens as a drawer over the canvas.

The AI tab shows one thing at a time. With nothing selected it asks **What would you like to build?**
(**Generate proposal**); with a proposal selected, **Describe a change** (**Request changes**); **New** starts a
separate website request. Below the box is the selected request's state: **Preparing…** with its steps and **Cancel**,
**Proposal ready** / **Changes ready** with what changed ("About Us updated", "1 image generated"), **Apply
proposal** and a closed list of the changes, **Applied as drafts**, or **Couldn't prepare…** with **Retry** and
**Details**. A request that asks for a new image says so in one line ("A new image will be generated", with **Don't
generate**). Where a change applies (this page, one section, the whole website), image generation, the shared
header/navigation/footer, automatic repair and a provider choice (only when more than one is connected) are under
**Advanced options**. The request id, provider, timings and steps are behind the **…** button.

## Review, apply, publish

1. Prepare the proposal. It creates no page drafts yet. Inspect every page in desktop, tablet and mobile previews, the page URLs, shared layout and branding changes.
2. **Apply to drafts** saves the accepted proposal as one transaction. New pages, changes to existing pages, shared components, branding and the contact form either all succeed or all roll back. Nothing goes live.
3. Open the page editor to edit ordinary blocks. Header and footer are linked reusable components under **Design → Components**; edit them there once. Global colors, supported font choices and spacing are under **Design**. Form fields are under **Forms**. The form block's Properties selects the form to use.
4. **Publish website** (canvas toolbar) first checks the website: it prepares every page exactly as publishing would, without making anything live, and shows the result in a dialog. **Blockers** (a shared resource, the branding or a page changed after applying, or a page that cannot be built) stop publishing; **warnings** (placeholder `#` links, missing search descriptions, large images without responsive versions) are listed before **Publish anyway**. Page sizes are under **Page sizes**. Editors, who cannot publish, get **Check readiness** with the same dialog. Inspect real layouts too. Publishing makes the reviewed draft versions live together. It refuses if a resource changed after applying; use a new website proposal or publish the manually revised resources/pages individually.

Only owners/admins can publish and read enquiries. Editors can prepare and apply drafts. Proposals belong to their requester; another site or user cannot inspect or apply them. Exact uncertain retries return their original result; a changed payload with the same key conflicts. Existing page save, lease expiry and helper revocation checks still apply.

Website requests cover at most eight pages, on sites with up to thirty current pages. They preserve unrelated pages. Shared header/footer links are inserted once into each proposed page. Pages omitted from the proposal do not automatically receive the shared layout. Requests are bounded to 250 operations overall, 150 per page. There is no automatic publishing and no external account provisioning.

## How a website request runs

A request runs on your local helper, never in the browser request: the page can be closed or refreshed and the
request keeps its state. It runs in steps, each a short, separate AI call:

1. **Understanding the request** — a plan: which pages to create or change, their sections and SEO, and whether the
   shared header, footer, navigation, form or branding must change. Small context (page outlines only).
2. **Shared header, footer and navigation** (and the form), only when the plan needs them. A new page is added to
   existing navigation without an AI call.
3. **Each page**, side by side (three steps at a time). A new page is built two planned sections per step; an
   existing page is edited in one step with only its own blocks as context.
4. **Checking the proposal** against the builder rules, page by page.

The review screen lists the steps with their state and timing, and when the provider last produced output. Measured
with Codex on a ChatGPT account (October 2026): a basic About page 35–45 s, three pages with navigation, a form and
images about 2 minutes, a six-page dental clinic site about 4 minutes. Arkon watches the model's reply as it is written, not only whether
Codex printed something: a reply that keeps being written is never stopped early, a reply that stops arriving for
60 s is stopped, and a reply that degenerates (the model writing nothing but empty space) is stopped within seconds.
Such a step is tried once more automatically, that step only; a reply that is complete but invalid is not retried.
Each step also has a generous ceiling (2-4 minutes) for replies still legitimately being written. The whole request has a 15-minute safety ceiling. A failed request names the step that
failed; **Resume** continues it and generates only what is missing, never the finished steps again. **Details** shows
every step's outcome and timing. Cancelling stops every running AI process.


With a proposal selected, the AI panel's **Describe a change** box requests changes to it. Describe only the change, and choose where it applies (**Change**: this page only, one of its sections, or the entire website). The
scope chooses *where*, not *what kind of change*: anything you could do in the builder there is allowed.

- **Section**: one top-level block of the previewed page (for example the Contact hero), what is inside it,
  the forms it shows and the reusable components placed in it.
- **This page only**: everything on the page: blocks, title, URL, every SEO setting, the forms on it and its
  components, including the shared header and footer.
- **Entire website**: anything; parts the request does not mention are kept.

Forms are edited the way the form editor edits them: field order, labels, types, required, rows and widths (for example
First name and Last name side by side at half width), placeholders, help text, defaults, choices, number limits, show
conditions, the submit button, confirmation message or redirect, description and whether the form accepts entries.
Field ids are kept, so existing entries stay linked. Forms that use nothing newer keep the original format, which needs
no form script on the page. **Form email notifications are human-only**: AI never changes who receives entries, and a
proposal that tries is refused.

A form, the shared header or footer, or a reusable component can also appear on other pages. The review then lists
**Also changes other pages** with those pages, and **Apply** needs one confirmation. A page URL change is a draft change
like in the builder: after publishing, the old URL redirects to the new one.

There is no need to repeat the original brief or to know block ids. The revision starts from:

- **an unapplied proposal**: that proposal's own result, including pages, forms and generated images that do not exist
  in the database yet. The original proposal is never changed; the revision records it as its parent, and either one
  can be applied (applying one makes the other stale).
- **an applied proposal**: the latest saved drafts, including your manual edits since applying. Pages, sections and
  the form are identified by their ids, so nothing is duplicated.

Arkon enforces the scope on the result, not only in the instructions: a revision that would change another page, an
unselected section, a form or component the target does not show, navigation or branding is refused with what it
would have changed, and nothing is proposed. If the model reports that the request needs a broader scope, the error says so and
**Choose a broader scope** reopens the request; nothing runs again until you send it. Turn on **Compare with starting
point** to see the baseline and the revision side by side; **Changes in this revision** lists the block changes.

Applying is still explicit and version-checked. If a page, the form, navigation, shared layout or branding changed after
the starting point was captured, the proposal shows a conflict and cannot be applied; newer edits are never overwritten
and nothing is regenerated automatically.

## Generated images

If a brief asks for a generated image ("Add a hero with a generated background photograph…"), Arkon asks before
anything runs: **Generate it**, or **Continue without generated images**. Without that choice no run starts. The form
also shows, separately, whether page/website generation and image generation are available, and which connection
does each. Generation is never silently replaced by a library image: if you continue without it, or the model places
no generated image, the review marks the image requirement as **Not met**. Requests without generation use suitable
library images, chosen by their titles, descriptions and alt text (the model does not see pixels).

When generation is chosen, the layout run reserves asset ids for the images and describes each one (subject, visual
direction, background or inline placement, aspect ratio, what must stay visible, alt text). The image-capable helper
then generates each image once, and Arkon imports it through the normal media pipeline: it checks the real file,
applies the upload limits, stores PNG photographs as WebP, creates responsive variants and records provenance
(provider, proposal, the structured request). The asset is private until a published page uses it; the review
preview shows it through signed URLs. Stages shown: **Preparing layout → Generating image → Optimizing image →
Preparing review**, with elapsed time and helper health, never percentages.

If the layout succeeds and an image fails, the layout is kept and the image is marked failed. **Retry image only**
generates just that image (the same request key replays; repeated clicks do not generate twice; a finished image is
never replaced). Interrupted generations fail and are not restarted automatically. You can also **Apply without the
missing image**, which leaves it out of the drafts and keeps the requirement marked as not met.

The generated hero image is an ordinary background image setting: replace it, switch between background and inline
image, cover/contain, focal point (background position), overlay and per-screen settings in the builder. Published
pages use responsive WebP variants with per-screen preloads for the first hero background (the likely LCP image), no
extra JavaScript, and never base64 or external image URLs. See [AI_SETUP.md](AI_SETUP.md#image-generation-codex) for
enabling image generation.

## Native contact forms

Forms support text, email, telephone, textarea, dropdown and checkbox fields, required validation and a configurable confirmation message. Definitions have editable drafts and immutable published versions. Publishing a form refreshes live pages using it; pending refreshes use the existing **Retry now**/`arkon:refresh-pages` flow.

A native HTML form posts without a JavaScript bundle or session cookie. It accepts only the exact published definition referenced by a currently live page on that site's registered host. Declared fields only are stored; validation, a honeypot, body-size bounds, same-origin checks and per-IP/site rate limits provide basic spam protection. Submissions are encrypted using Laravel's `APP_KEY`. Owners/admins can read the latest 100 enquiries under Forms.

Optional email notifications require a real configured mail transport and an owner/admin-selected recipient. The default log/array transport is treated as **unconfigured**, so enquiry contents are not written to application logs. A mail failure does not lose the saved enquiry; its status says failed. This release does not retry mail automatically. It has no conditional logic, attachments, payments, retention/deletion controls, pagination, CAPTCHA integration or CSV export. Validation errors show a fresh form; re-enter the enquiry. It is a contact-form foundation, not full Gravity Forms parity.

## SEO and performance

New publications record canonical URLs using the site's trusted configured/registered origin. `/sitemap.xml` includes live, indexable pages only; `/robots.txt` excludes admin, preview and submission endpoints. Old publications retain their recorded renderer and remain reproducible; republishing opts them into the new output. Multi-domain sites currently use `APP_URL` when it belongs to that site, otherwise the first registered hostname. There is no canonical-domain settings screen or structured-data generator yet.

Header, main, footer and navigation use semantic elements. Form CSS is included only where a form is rendered. Ordinary public pages and forms add no runtime JavaScript; existing explicitly enabled entrance animations retain their small runtime. Responsive media, intrinsic image dimensions and existing LCP protection remain in place.

Readiness checks report HTML/CSS size, script count and image warnings. They cannot guarantee Google scores or field Core Web Vitals. `npm run perf` measures published fixture pages on a throttled mobile browser, including `/perf-contact` with shared components and a form. It resets only the isolated e2e database. Field results need real visitors after deployment.

## Backups and recovery

```powershell
php artisan arkon:backup
php artisan arkon:backup --verify="C:\path\to\backup-directory"
php artisan arkon:backup-restore-test "C:\path\to\backup-directory"
```

Backups go under the git-ignored `storage/app/private/backups`. They contain a consistent PostgreSQL dump, uploaded media, installed immutable theme snapshots and SHA-256 checksums. The folder is restricted to the current Windows account. Environment files, passwords and helper credentials are excluded. Keep the original `APP_KEY` separately in a password manager, and copy backups to a protected separate disk. Database owners are used only by the local command via migration configuration; temporary PostgreSQL credential files are removed on failure too.

The restore drill replaces **only the isolated test database**, restores file references from the backup, reapplies runtime permissions and checks publication reproduction. Never run it while PHPUnit is using that database. It does not restore into development or production. Checksums catch damage; they do not prove a backup is from a trusted source. Backups contain private enquiries and must remain private. Production restore procedures and automated off-site retention are still deployment work.

## Verification of this milestone (8 October 2026)

- PHPUnit: 307 tests, 2,030 assertions passed. Vitest: 306 tests passed. Typecheck, Pint, production build and both dependency audits pass; audits report no advisories.
- Browser coverage includes the complete four-page proposal/apply/publish/contact-submission flow, existing draft/preview/history/publish flows, and a form response lost after commit followed by an exact retry (one saved version). The broad browser run passed 80 checks and skipped nine optional profiles; its one ambiguous Home selector was corrected and that flow passed in the focused rerun. The added form-retry browser check also passed.
- Herd: temporary-account sign-in, website/forms screens, mobile horizontal-overflow check and sessionless SEO routes passed; temporary account removed. No real model requests were made.
- All 28 existing development publications reproduce byte for byte. A private pre-migration backup was verified and restored into the isolated test database: 28 publications checked, zero reproduction failures.
- Contact fixture, three mobile Lighthouse runs: scores 81/100/100, median 100; median LCP 1,082 ms, CLS 0, TBT 63 ms, CSS 2,573 bytes, no JavaScript downloads. Direct throttled browser runs: LCP 628/496/456 ms, CLS 0, no long tasks, slowest synthetic taps 24/24/16 ms. This small fixture has no photographs; these numbers do not predict every generated site or field Core Web Vitals.


### Website generation activity and validation failures

Website requests show an indeterminate activity bar, generation/validation/repair stage, elapsed time and the worker heartbeat. A heartbeat proves the local process is responsive; it is not model token progress or a completion estimate. Failed polling shows a connection warning instead of silently presenting old status as current. A second active website request by the same user is refused, including direct API requests, while exact key retries replay safely.

Automatic website validation repair is off by default. The brief form lets the user explicitly allow at most one repair run, which consumes additional Claude allowance. If allowed, that run receives the precise validation paths/messages and the previous structured output, rather than the generic user error. Interrupted website runs fail instead of silently starting another model run; page-request recovery keeps its existing behaviour.

New failed website requests retain validation issues and up to 2 MiB of structured candidate output in the private request record, fenced by the live worker lease. Candidate output is never returned by the status API or made public; it is for diagnosis. Existing failed requests cannot recover output that the old helper deleted. A successful proposal clears the rejected candidate. All output still passes the normal component, nesting, style, media and save validation; no validation is bypassed to hide failures. The helper receives schemas and context, with repository/file tools disabled. No new real-model request was used to verify this correction.

Restart the local helper after upgrading this code. Migration 2026_10_14_000003 adds activity/diagnostics columns without changing existing requests, drafts or publications. Admin activity UI adds no code to published pages.

The website screen also checks the helper’s website protocol revision. A helper with old code loaded is refused before queueing or using Claude; stop/restart it to receive the progress/validation changes. Page AI remains available under its existing readiness check.

### Navigation and shared website layout

Open **Admin → Navigation** to create menus, reorder items, add one level of dropdowns, and choose page links, custom URLs or section links. Page links store a page reference: they follow its published URL, never an unpublished rename. Unpublished targets resolve to # and show a warning. Menu drafts stay private until explicitly published; publishing refreshes dependent live pages under the site's publication ordering. Editors can save; owners and admins can publish. Published menu history is immutable and its resolved URLs are recorded in publication inputs.

The Navigation screen also links to the site's shared header and footer editors. Its **Prepare missing header and footer** action creates a local, reviewed website proposal, using no Claude request or subscription quota. It preserves existing nonempty shared layouts and page content, fills missing layout, and proposes section anchors for recognised Home/About/Services/Contact sections. Nothing changes until the proposal is applied; publishing remains separate.

The builder has a Navigation block with a menu selector. Sections have an **Anchor** field for destinations such as #services; anchors must be unique, start with a letter, and contain letters, digits or hyphens. Duplicating a section gives its copy a new anchor. Menus use native HTML disclosure controls for mobile and dropdowns, with keyboard support and no public JavaScript. The initial implementation supports one dropdown level and up to 50 menu items; it does not provide a fully custom mega-menu.

Website proposals include editable header, footer and navigation by default. Turn off **Include shared header, navigation and footer** for a standalone page. Existing layouts and menu definitions are preserved unless changes are proposed. Review includes the menu and shared resources before applying; website publication publishes their reviewed drafts together. Restart the local AI helper and MCP server after this upgrade: website generation requires helper protocol 3, so an older helper is rejected before a new run consumes quota.

New buttons default to #, including AI-created buttons with an omitted or empty destination. This is a visible placeholder, allowed to publish with a readiness warning; supply a real destination before launch. Existing stored blank or intentional URLs are not silently rewritten. Component versions and old publications remain immutable.


Navigation verification: 313 frontend tests and typecheck/build passed. The complete PHP run passed 323 tests; the new nested-section fixture was corrected and all 21 navigation/website tests then passed on the final code (324 PHP tests covered overall). The full browser run passed 82 tests; the three affected paths were corrected and passed in focused reruns, including keyboard/mobile menus, website publishing and placeholder buttons. Nine browser cases remain conditionally skipped. A separate temporary-account Herd smoke verified Navigation, mobile layout and the website layout option without changing page/menu drafts or live pages. All 29 existing development publications reproduce unchanged. No real Claude requests were made.
