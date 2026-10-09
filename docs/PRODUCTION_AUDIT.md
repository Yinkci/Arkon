# Arkon production architecture audit

Audit date: 9 October 2026. Repository: `C:\Herd\ArkonLaravel`.

## Conclusion

Arkon already has the right public rendering foundation. It stores complete HTML at publication time, serves it without React hydration, starts no visitor session, includes only used component CSS, and conditionally loads small slider and animation runtimes. A rewrite would add risk without addressing a demonstrated performance problem.

The highest-value issues found were unnecessary editor dependency construction on public requests, crawler access to public assets, incomplete HTTP revalidation, unnecessarily buffered image delivery, missing output diagnostics, basic social metadata, nested sticky-header metadata leakage, and keyboard focus during slider navigation with hidden arrows. These have been addressed. This is an architectural review and local validation; it is not a certification of production hosting, security, accessibility or field Core Web Vitals.

## Scope and architecture

Inventory covered application services/controllers, routing/bootstrap/configuration, database migrations, component manifests and all renderer generations, style rules, editor state/bridge/drag controls, theme packages, public runtimes, performance tooling and regression suites. The initial inventory contained 554 text source/config/test files and approximately 64,154 lines. Generated dependencies, credentials, uploads and build artifacts are excluded from the source audit. Counts describe coverage of the inventory, not a claim that every line received manual review.

The important paths were reviewed directly:

1. React/Inertia admin keeps an editable schema, responsive props/styles, immutable save batches, undo history and selection state.
2. PHP and TypeScript validate shared rules and conformance fixtures. Services authorize site membership and validate referenced assets/resources.
3. Publication takes the site epoch lock, records immutable revision/resource inputs, renders with the pinned component definitions, and advances the live pointer transactionally.
4. `PageRenderer` builds escaped element IR, gathers used CSS/resources, produces semantic HTML and head metadata, and records the render inputs.
5. `PublicPageController` resolves the host and live path through the new dependency-free `PublicPages` store and returns stored HTML. It does not construct the editing catalogue, traverse the builder schema, instantiate React or render the page on every visit. SEO origin lookups use the same thin store.
6. The browser downloads only managed images/fonts and conditional progressive enhancements. The editor bridge, drag geometry and controls stay in the admin canvas.

## Findings and priorities

No verified **critical** defect was found in these public rendering paths. The following high-priority defects were verified in code and covered by regressions.

| Priority | Finding and location | Impact | Resolution |
|---|---|---|---|
| High | `PublicPageController` injected `PageService`, whose constructor transitively built the catalogue/validator/renderer; SEO origin lookup also resolved the heavy `PageStore` | Avoidable schema/CSS/theme loading on visitor and SEO requests despite serving stored HTML | Centralize live-page, redirect and origin lookups in dependency-free `PublicPages`; existing editing APIs delegate; regression makes editing dependencies throw and verifies all public/SEO routes still work |
| High | `SeoController::robots` blocked the entire `/_arkon` namespace, including public scripts | Crawlers could not render sliders/animations as visitors do | Block only `/_arkon/forms/`; public enhancement resources remain crawlable |
| High | `components-4.js` focused the first arrow even when responsive presentation hid it, then made the previous slide inert | Keyboard focus could be lost during slide navigation | New `components-5.js` selects an available persistent control, or focuses the carousel root; old runtime remains immutable |
| High | `MediaController` read each entire image into a PHP string | Avoidable worker memory and copying under concurrent image traffic | Authorized `BinaryFileResponse`; file streaming, HEAD/range handling and public validators; private delivery remains no-store |
| Medium | Public pages recognized only one exact strong ETag string | Weak/list/wildcard validators unnecessarily returned full responses | Use the framework's conditional-request handling after resolving live state |
| Medium | Renderer reports lacked actual depth/fan-out/heading diagnostics | Schema limits alone did not describe expanded components or generated markup | Shared `OutputDiagnostics`, exposed through renderer reports and website readiness warnings |
| Medium | Nested sticky headers retained the internal `data-ak-sticky-class` annotation | Published markup violated the editor/public boundary | Renderer 5 recursively removes reserved editor attributes after layout helpers consume them; older renderer output and editor annotations remain intact |
| Medium | Social preview metadata absent | Shares lacked consistent title/description/canonical identity | Renderer version 5 adds escaped Open Graph and Twitter summary metadata from recorded inputs |
| Medium | Benchmark output cleanup accepted an arbitrary environment-provided directory | Developer tooling could delete unintended files or pass unsafe path text to a Windows shell | Restrict output to named storage report directories, reject traversal/link escapes and bound benchmark page paths before cleanup or database reset |

### Important remaining work

| Priority | Gap | Recommended solution |
|---|---|---|
| High before production scale | No CDN HTML caching/purge contract; every HTML visit revalidates against the application | Keep current immediate publication visibility. Introduce a durable epoch-aware purge/outbox and prove rename, delete, unpublish and resource refresh invalidation before giving CDN HTML a positive TTL. Do not add stale-serving caching by default |
| High before public launch | No field CWV evidence or production load test | Deploy to staging with real compression, OPcache, HTTPS and configured database/cache. Measure representative journeys and real-user LCP/CLS/INP; measure concurrent image requests and warm/cold TTFB |
| High for mature SEO | No structured-data model, social-image override, breadcrumb model or explicit canonical-domain settings | Add versioned, typed site/page SEO resources. Generate only content-supported WebSite/WebPage and confirmed organization details; enable Article/Product/etc. only when their data models exist. Ship another renderer version for changed output |
| Medium | Primary origin falls back to the first registered hostname when APP_URL does not belong to a site | Store a versioned explicit primary origin. Define aliases/HTTPS redirects without silently changing old publication inputs |
| Medium | Sitemap reads all live entries into a collection; no sitemap index/sharding | Use bounded, consistently ordered batches and an index at the protocol limit. Exclude noindex from published revisions; never read draft SEO. Keep lastmod tied to real publication changes |
| Medium | Background candidates use screen-wide bounds rather than precise rendered container width/DPR | Extend the existing image sizing model cautiously. Validate crops and responsive density visually, compare transferred bytes and LCP; avoid preloading multiple unnecessary assets |
| Medium | Variant generation is synchronous and can decode up to 40 million pixels | Gate decoding against configured worker memory and move expensive variants to a durable worker when deployment supports one. Preserve original/private access and make fallback/failed generation visible |
| Medium | Manual styling can create low contrast, awkward reading order or excessive motion | Add targeted diagnostics and accessibility checks; do not ban professional layouts or automatically rewrite heading levels |
| Medium | Existing media remains potentially available in browser/CDN caches after withdrawal because public files are immutable for a year | Document the contract clearly. If hard withdrawal is required, change cache policy or introduce controlled delivery rather than promising deletion of already cached bytes |
| Medium | Forms are a foundation: synchronous mail, no delivery retry/retention policy, errors require re-entry | Add durable notification jobs, retention/export controls and value-preserving validation. These concern enquiries rather than the page's initial rendering |
| Low | Versioned PHP/JSON/CSS generations and nested renderer feature checks are accumulating | Keep compatibility tests and organize capabilities explicitly when the next renderer version is added. Do not consolidate by editing old versions |
| Low | Navigation renders separate CSS-switched desktop/mobile lists | Accept at present: no JS and hidden presentation is excluded from the accessibility tree. Consider one-list presentation only with verified keyboard/mobile behavior |

The CMS does not yet have a complete article/collection publishing model. A nested content page was benchmarked; it should not be described as evidence of a finished blog platform. Third-party analytics/video/map embeds are not currently a built-in subsystem. Do not add a generic script injector; future embeds need typed, consent-aware, interaction-loaded adapters and separate budgets.

Authentication remains session-based, rate-limited and without public signup. Site services authorize membership; related records and publication dependencies use site-scoped relationships. Save batches, retry keys, immutable revisions, publish ordering and proposal review remain intact. AI runs through the paired Claude Code workflow, with a restricted environment and lease/revocation checks; the audit made no real model requests. Theme packages use declared fields and validated templates rather than arbitrary executable PHP. The existing real-database/concurrency suites provide regression coverage; this review does not replace adversarial security testing.

## Clean DOM rules

One builder node does **not** require one extra wrapper. Reusable fragment roots are already logical: when embedded, their children are expanded without rendering the fragment's standalone `main` root. Text and button blocks emit their actual heading/paragraph/link rather than a builder wrapper. Sections implement content width with padding rather than adding an inner container. Empty hero paragraphs and empty production captions are omitted.

| Component | Expected output policy |
|---|---|
| Text | One semantic heading or paragraph; size belongs to styles, not heading level |
| Button | One anchor for navigation; native button for a real action |
| Section | One chosen semantic section/container; no automatic inner wrapper |
| Group | One element when flex/grid/background/border/positioning establishes a real box |
| Columns | Grid parent plus column boxes required for layout and styling |
| Image | Image and optional figure/caption association, dimensions and responsive sources |
| Reusable fragment | No fragment root when embedded |
| Instance | Currently a styleable boundary; conditional flattening needs a versioned layout-equivalence proof |
| Shared page landmarks | Header/footer outside one main; site root retained where root styles apply |
| Slider/form | Native controls, labels and layout boxes only as needed by actual behavior |

Do not flatten a box based on tag name alone. It may affect grid/flex sizing, stacking, clipping, inherited selectors, responsive overrides, anchors or focus. No blanket `display:contents` optimization was added. The measured reference is small enough that aggressive wrapper deletion is unjustified.

The common heading/hero minimal-markup regression tests remain in place. Editor selection, drag controls, drop targets and placeholders must never ship in production. Public attributes such as slider state, responsive presentation and accessibility references are functional data, not leaked editor metadata.

## CSS, JavaScript, images and fonts

CSS consists of the base rules, used component-version sheets, deduplicated declaration-hash classes, and only referenced token variables. Responsive declarations follow desktop → tablet → mobile inheritance. Inline page CSS avoids an extra blocking stylesheet request; extracting it should be considered only if large multi-page sites demonstrate a meaningful shared-cache benefit.

Static pages load **zero public JavaScript**. Sliders/back-to-top use a dependency-free deferred runtime; entrances use a separately versioned runtime. Exact asset CSP and SRI are preserved. No React, Inertia, Vite admin bundle, third-party runtime, hydration or analytics call was observed on the measured public pages.

Managed images reserve width/height, expose responsive WebP variants and sizes, lazy-load below-fold images, and protect the probable LCP from entrance effects. Background images use bounded screen candidates and selective preload. Auto-priority is a heuristic; the content position and crop still need inspection for unusual designs. AVIF generation is intentionally absent; adding it requires measured encoding/storage benefits, not a format checklist.

System fonts are the default. Optional Inter is local and uses font-display:swap with a Latin subset. No external font-provider request is necessary. Variant generation retries and font loading should remain explicit; do not preload every image/font or strip useful features to inflate a score.

## Performance budgets and diagnostics

`OutputDiagnostics` measures actual rendered body IR, including expanded reusable components: element count, element depth, child fan-out, h1 count and uncompressed HTML/CSS bytes. It warns about heading gaps, placeholder links and missing descriptions. It changes neither HTML nor render inputs and never blocks publishing automatically.

Current review budgets are 800 body elements, depth 12 measured from the rendered body root, 60 children at one node, 150,000 HTML bytes and 30,000 CSS bytes. These are advisory ceilings with room above the fixtures, not proof of performance. HTML/CSS thresholds centralize the existing readiness budgets. Website readiness supplies the same canonical/social head metadata as publication and reads site identity once per review, so byte budgets describe the actual output without repeated identical queries. The richer fixture has roughly 200 document elements and 27 KB CSS, so CSS should receive attention before simply raising that budget. Full document depth includes html/body and differs from the IR depth.

Public JS policy is stricter than a blanket size allowance: zero for static blocks; a review target of at most 20 KB uncompressed for first-party enhancements. Future third-party requests require explicit review. Image budgets depend on actual viewport, density and content; diagnose transferred bytes and oversized candidates rather than imposing one universal per-file limit.

## Measurements

Benchmarks use isolated test fixtures, production application settings, PHP's built-in server, Lighthouse 12.8.2 mobile simulated throttling and three runs per page. The accompanying browser pass uses a 412×823 touch viewport, 4× CPU slowdown and slow mobile networking. These are local lab results, not production load tests or real-user INP.

| Fixture | Performance before → after | Median LCP before → after | CLS after | A11y / SEO | Elements / depth | Public scripts |
|---|---|---|---|---|---|---|
| /perf-contact | 100 → 100 | 1073 → 984 ms | 0 | 100 / 92 | 69 / 13 | 0 |
| /perf-hero | 100 → 100 | 1168 → 1174 ms | 0 | 100 / 92 | 27 / 8 | 0 |
| /perf-images | 99 → 99 | 1930 → 2167 ms | 0 | 100 / 92 | 45 / 7 | 0 |
| /perf-nested | 100 → 100 | 937 → 917 ms | 0 | 95 / 92 | 87 / 9 | 0 |
| /perf-reference | — → 99 | — → 2056 ms | 0 | 100 / 92 | 203 / 13 | 1 |

The reference was added to the extended after run; no like-for-like Lighthouse before measurement was captured for it. SEO scores are 92 because fixture pages have no meta description. The nested fixture scores 95 for accessibility because its heading order skips a level. These are useful diagnostics, not claims that real authored pages necessarily have those defects. Readiness now reports both conditions.

After-run inline CSS is 2.7–8.1 KB for static fixtures and 27.35 KB for the reference. Its one public interaction script is 9,578 uncompressed bytes. The full-document element counts include six new social metadata elements in the head; no content wrapper was added. All measured pages have zero reserved editor attributes. Browser scripted tap latency is a lab observation, not a real-user INP measurement.

The benchmark uses an array cache store and database sessions in an isolated fixture environment. It does not establish persistent-cache behavior, CDN invalidation, throughput or production PHP-FPM capacity. Full raw results remain under storage/perf-audit-before and storage/perf-audit-release.

The static fixtures have no public scripts, no editor attributes and zero CLS in the measured runs. The reference has one conditional interaction script. Added social tags increase head bytes slightly; they do not add content wrappers or blocking resources. DOM depth and sizes must be compared using the same definition.

Do not attribute small before/after timing differences to the fixes: process startup, local contention and PHP's development server affect these measurements. Streaming and validation benefits are established by delivery behavior and regression tests; field performance still requires deployment measurements.

## Compatibility and validation

| Verification | Result |
|---|---|
| Final full PHPUnit suite after all code changes | 355 tests, 2,334 assertions passed; sequential run, isolated test database |
| Frontend unit/conformance suite | 333 tests passed |
| TypeScript | Passed |
| Production asset build | Passed |
| Representative Playwright suite | 26 tests passed: editor, reference, motion, forms, navigation and new hidden-control slider focus cases |
| Latest production-boundary/renderer/compatibility regressions | 25 tests, 118 assertions passed; included again in the final full suite |
| PHP formatting; JS/browser test formatting; performance harness syntax; diff whitespace | Passed |
| Read-only development publication reproduction, final check | All 47 stored publications reproduced byte for byte |
| Production fixture benchmarks | Five pages, three Lighthouse runs each, matching browser DOM/interaction pass |

Regression coverage includes live/draft SEO separation, public and private media delivery, authorization before validators, unpublish behavior, conditional requests, absence of editor dependencies on public routes, escaped social metadata, pinned renderer/runtime reproduction, nested sticky-header annotation removal, slider focus with hidden controls, and advisory output diagnostics. The broad browser run preceded the final thin-store and annotation-boundary additions; those additions were validated by the final full HTTP/renderer suite. No field INP, production concurrency or live hosting availability result is claimed.

Renderer 5 adds social metadata, strips reserved editor annotations at the production boundary and selects components-5 for current slider output. Renderer 4 retains components-4, and renderers 1–3 keep their existing behavior. No component manifest, applied migration, immutable publication or real development page was rewritten. Existing public pages receive new metadata/runtime only when published again.

The first broad PHP run overlapped a second integration run against the same test database, causing invalid fixture/permission failures. Those results were discarded. The verification run was sequential. The new asset-integrity test also caught a Windows newline/hash mismatch; the recorded hash was corrected against the actual file bytes before final browser verification. Neither issue involved development data.

Nothing was committed or pushed during this audit. Earlier uncommitted work was preserved. No real model calls were made, no helper was restarted, and no development content was published for benchmarking.

## Recommended next milestone

Use these diagnostics and the full editable reference site as a release gate. Next implement versioned SEO/site identity (primary origin, social image and factual structured data), then production staging with compression/cache configuration, purge-safe CDN delivery and measured load/field performance. Expand blog/collection semantics separately. Continue adding builder features only when their public HTML/CSS/JS and keyboard behavior meet the same rules.

Reference guidance: [Google on crawlable JavaScript resources](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics), [Core Web Vitals and field measurement](https://web.dev/articles/vitals). A good lab score does not establish the 75th-percentile real-user CWV result.
