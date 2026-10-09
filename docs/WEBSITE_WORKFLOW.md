# Build a website from a brief

Open **Build a website** in the admin menu (`/admin/website`). Describe the business, the pages you want, the style and the information you actually know. Example:

> Create a professional landscaping website with Home, About, Services and Contact pages. Use a shared header, footer and navigation, restrained green branding, and a contact enquiry form. Do not invent our address, phone number, testimonials or prices.

The existing locally paired Claude Code helper must be running and signed in with your subscription. This workflow makes no provider API calls and needs no API key. Restart the helper after updating application code. Alternatively, restart the Arkon MCP connection in Claude Code, ask it to use `arkon_get_website_context` and `arkon_submit_website_proposal`, then review the result on this same admin screen.

## Review, apply, publish

1. Prepare the proposal. It creates no page drafts yet. Inspect every page in desktop, tablet and mobile previews, the page URLs, shared layout and branding changes.
2. **Apply all website drafts** saves the accepted proposal as one transaction. New pages, changes to existing pages, shared components, branding and the contact form either all succeed or all roll back. Nothing goes live.
3. Open the page editor to edit ordinary blocks. Header and footer are linked reusable components under **Design → Components**; edit them there once. Global colors, supported font choices and spacing are under **Design**. Form fields are under **Forms**. The form block's Properties selects the form to use.
4. **Check publishing readiness** reports missing publishing requirements and output-size warnings. Inspect real layouts too. **Publish website** requires a separate confirmation and publishes the reviewed draft versions together. It refuses if a resource changed after applying; use a new website proposal or publish the manually revised resources/pages individually.

Only owners/admins can publish and read enquiries. Editors can prepare and apply drafts. Proposals belong to their requester; another site or user cannot inspect or apply them. Exact uncertain retries return their original result; a changed payload with the same key conflicts. Existing page save, lease expiry and helper revocation checks still apply.

Website requests cover at most eight pages, on sites with up to thirty current pages. They preserve unrelated pages. Shared header/footer links are inserted once into each proposed page. Pages omitted from the proposal do not automatically receive the shared layout. Requests are bounded to 250 operations overall, 150 per page. There is no automatic publishing and no external account provisioning.

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
