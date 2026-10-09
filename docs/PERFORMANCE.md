# Performance of published pages

Published pages are static HTML stored at publish time: one document with one inline `<style>` (base styles, the
CSS of the component versions used, the generated style rules, and only the design-token variables that CSS uses),
no external stylesheets and system fonts by default. Choosing Inter adds a locally served font (Latin subset with full-character fallback). Public JavaScript is conditional: the versioned ~2.5 KB animation runtime and/or the ~4.9 KB slider/back-to-top runtime, both deferred and pinned by integrity. Older publications retain their recorded runtime versions. Images are responsive WebP variants with
`srcset`/`sizes`, intrinsic `width`/`height`, `fetchpriority="high"` for the likely LCP image only and lazy loading
for the rest. The editor, React, Inertia, the canvas bridge, drag and drop and the AI panel are admin-only assets and
never appear in public HTML (asserted by PHPUnit and Playwright: no `/build/`, no `data-ak-`, and no `<script` except
the exact runtime tags on pages that need them).

## Lab measurements (Lighthouse)

`npm run build && npm run perf` (`scripts/perf.mjs`) resets the isolated e2e database, publishes three fixture pages
with `php artisan arkon:perf-fixtures` (photo-like JPEGs uploaded through the normal upload path, so variants are
generated as in production), serves the app with production settings and runs Lighthouse three times per page.
Raw reports: `storage/perf/reports/` (git-ignored).

Conditions (measured 2026-10-07; re-measured the same day after hero v3, see below):

| | |
|---|---|
| Tool | Lighthouse 12.8.2 (via `npx`, not a project dependency), Playwright's Chromium 153, headless |
| Device | Lighthouse mobile preset: Moto G Power emulation, 412 × 823, DPR 1.75 |
| Throttling | simulated: RTT 150 ms, 1.6 Mbit/s down, 750 kbit/s up, CPU ×4 (host benchmark index ≈ 2200–2500) |
| Server | PHP 8.4 built-in server (`php -S`), `APP_ENV=production`, `APP_DEBUG=false`, PostgreSQL 17, same Windows machine; one warm-up request per page; no HTTP compression (the built-in server has none) |
| Runs | 3 per page; medians below |

| Page | What it is | Score (runs) | LCP | CLS | TBT | FCP | Speed Index | Transferred | Images | HTML | Inline CSS | Requests | Scripts |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `/perf-hero` | Hero with a 2400 × 1600 photo on the right (500 px tall, cover; stacked on phones), two buttons, text | **100** (100/100/100) | 1.13 s | 0 | 0 ms | 0.76 s | 0.84 s | 24.0 KiB | 17.9 KiB | 6.0 KiB | 3.7 KB | 3 | 0 |
| `/perf-images` | Heading, two rows of three photos in Columns (4:3, cover, captions), one wide photo | **100** (100/100/99) | 1.81 s | 0 | 0 ms | 1.05 s | 1.08 s | 117.3 KiB | 108.8 KiB | 8.3 KiB | 2.7 KB | 8 | 0 |
| `/perf-nested` | Three sections with backgrounds, Columns 1fr 2fr 1fr, cards of nested groups (7 levels deep), 9 buttons | **100** (100/100/100) | 0.87 s | 0 | 0 ms | 0.71 s | 0.71 s | 9.4 KiB | 0 | 9.3 KiB | 4.8 KB | 2 | 0 |

LCP elements: the hero photo (960 w WebP variant, `fetchpriority="high"`), the first gallery photo (960 w WebP,
`fetchpriority="high"`) and the `<h1>` of the nested page. The JPEG originals (1600–2400 px, 0.21–0.47 MB) are never
downloaded by these pages. All three are well inside the targets (Performance ≥ 90, LCP ≤ 2.5 s, CLS ≤ 0.1).

### Bottlenecks found and what was done

- **LCP image lazy-loaded (fixed).** The first measurement of `/perf-images` showed its LCP element, the first gallery
  photo, with `loading="lazy"`: only images in the first top-level block were prioritised, and that page starts with a
  heading. The rule is now "the first image within the first two top-level blocks" (regression test
  `StyleRenderingTest::test_an_image_right_after_a_leading_heading_is_still_fetched_early`); Lighthouse's
  "LCP image was lazily loaded" and "prioritize LCP image" audits pass. The simulated LCP stayed in the same range
  (1.69 s → 1.81 s, within run-to-run variance), because the lab already loaded that image early through the preload
  scanner; on real networks with competing images, priority matters more.
- **A stacked hero ignored its image height (fixed, found by the browser tests).** In a column layout, a percentage
  flex basis falls back to content size and ignored `height: 500px`; direction settings now set `--ak-basis`.
- **Not compressed (environment).** "Enable text compression" estimates 4–7 KiB of savings per page: PHP's built-in server sends
  HTML uncompressed. In production, enable gzip or Brotli for `text/html` in the web server (nginx/Herd do this with
  standard configuration). Media is already compressed (WebP) and cached `public, max-age=31536000, immutable` once
  public.
- **Variant step (accepted).** "Properly size images" estimates ~15 KiB on `/perf-images`: a 412 px phone at DPR 1.75
  needs ~720 px and gets the 960 w variant. Adding 480/800 px steps would trade more files per upload for this saving.
- No render-blocking resources, no third-party requests, no layout shifts, no main-thread work to speak of (no
  scripts). DOM sizes 13, 31 and 73 elements.

These are lab numbers on one machine with simulated throttling. **They do not prove production Core Web Vitals**,
which depend on real devices, networks, hosting, caching and content.

### Re-measurement after hero v3 (admin redesign milestone)

New heroes render as hero v3 (a set width of the image or content area becomes its size). The fixtures use it, so
the three pages were measured again, same conditions, 3 runs each (medians):

| Page | Score (runs) | LCP | CLS | TBT | FCP | Transferred | Inline CSS | Requests | Scripts |
|---|---|---|---|---|---|---|---|---|---|
| `/perf-hero` | **100** (100/100/100) | 1.11 s | 0 | 0 ms | 0.74 s | 24.0 KiB | 3.7 KB | 3 | 0 |
| `/perf-images` | **100** (100/99/100) | 1.72 s | 0 | 0 ms | 0.97 s | 117.3 KiB | 2.7 KB | 8 | 0 |
| `/perf-nested` | **100** (100/100/100) | 0.88 s | 0 | 0 ms | 0.72 s | 9.4 KiB | 4.8 KB | 2 | 0 |

The hero's LCP element is the hero v3 image (960 w WebP, `fetchpriority="high"`). The admin redesign adds nothing
to public pages: no admin CSS, scripts, fonts or editor attributes (`data-ak-part` is editor-only, asserted by PHPUnit).

### Entrance animations off and on (duplicate, columns and animations milestone)

`npm run perf` now publishes each fixture twice: as before, and as `…-motion` with fade-up entrances. On
`/perf-hero-motion` the hero (its h1 and image) and the text below are animated. On `/perf-images-motion` the h1
heading, both gallery rows and the wide photo are animated. On `/perf-nested-motion` every section and card is
animated. "When scrolled into view" is used except for blocks near the top, which use "on page load". The renderer
leaves animations off the blocks holding the likely LCP: the hero, the h1 heading, the first gallery row with its
priority image, and the first section with its h1. Same conditions as above (measured 2026-10-07), 3 runs each,
medians; the LCP of every run is shown:

| Page | Score (runs) | LCP median (runs) | CLS | TBT (runs) | FCP | Transferred | HTML | Inline CSS | Requests | Scripts (bytes) |
|---|---|---|---|---|---|---|---|---|---|---|
| `/perf-hero` | **100** (100/100/100) | 1.17 s (1.17/1.29/1.17) | 0 | 9 ms (9/61/2) | 0.80 s | 24.0 KiB | 6.0 KiB | 3,745 B | 3 | 0 |
| `/perf-hero-motion` | **100** (100/100/100) | 1.21 s (1.30/1.21/1.18) | 0 | 0 ms | 0.77 s | 26.9 KiB | 7.0 KiB | 4,592 B | 4 | 1 (1.8 KB) |
| `/perf-images` | **99** (99/99/100) | 1.96 s (2.16/1.96/1.85) | 0 | 0 ms | 1.17 s | 117.3 KiB | 8.3 KiB | 2,735 B | 8 | 0 |
| `/perf-images-motion` | **100** (100/100/100) | 1.70 s (1.70/1.70/1.75) | 0 | 0 ms | 0.96 s | 120.1 KiB | 9.4 KiB | 3,582 B | 9 | 1 (1.8 KB) |
| `/perf-nested` | **100** (100/100/100) | 0.89 s (0.88/0.95/0.89) | 0 | 0 ms | 0.72 s | 9.4 KiB | 9.3 KiB | 4,767 B | 2 | 0 |
| `/perf-nested-motion` | **100** (100/100/100) | 0.94 s (0.90/0.94/0.99) | 0 | 0 ms | 0.79 s | 12.6 KiB | 10.6 KiB | 5,614 B | 3 | 1 (1.8 KB) |

- **Cost of animations.** About 850 bytes of inline CSS, shared by all the animated blocks of a page (six keyframes
  and the trigger rules, plus one small class per distinct setting). Pages with "when scrolled into view" blocks also
  make one more request: the deferred runtime, 1.6 KB, 1.8 KB transferred uncompressed. On these pages that is 2.8 to
  3.2 KiB more transferred in all. Pages that only animate "on page load" load no script (asserted by tests).
- **LCP, CLS, TBT.** No regression beyond run-to-run variance. The LCP elements are the same with and without
  animations: the hero photo, the first gallery photo and the nested page's h1. The protection worked: none of them
  is animated, and Lighthouse's LCP element has no animation class. The animated runs' LCP medians are 0.04 s
  slower, 0.26 s faster and 0.05 s slower. They are within or near the spread of single runs: `/perf-hero` itself
  ranged 1.17–1.29 s, `/perf-images` 1.85–2.16 s. These differences are noise, not an improvement or a cost. CLS
  stayed 0, because entrances use only opacity and transform (no layout shift). TBT was 0 on all animated runs. The
  9 ms median (one 61 ms run) on the script-free `/perf-hero` is lab noise.
- **What lab numbers do not show.** Lighthouse loads the page without scrolling, so it never runs the viewport
  reveals. Their main-thread cost is one IntersectionObserver callback per block, measured only by the browser tests,
  not timed. INP needs real interactions and is not measured here. Lab results do not establish field INP, and do not
  guarantee every page scores 100: a page that animates many large blocks "on page load" delays when they appear by
  the chosen duration and delay, by design.

### Entrances that play on screen (motion-2), before and after (2026-10-08)

Before any code change, the harness gained a fourth page and a browser check, and a baseline was recorded on the
unchanged code. The same production server setup, Chromium and pages were then measured again after the change.
`PERF_OUT=storage/perf-baseline` (before) and `storage/perf-after`, both with
`PERF_PAGES=/perf-nested,/perf-hero-motion,/perf-nested-motion,/perf-intro-motion`; raw reports are in those folders
(git-ignored).

- **Pages.**
  - `/perf-nested`: no animations.
  - `/perf-hero-motion`: an image-heavy hero whose photo is the LCP (protected), with text below that animates.
  - `/perf-nested-motion`: several entrances, most below the fold.
  - `/perf-intro-motion`: eligible content on screen at load (an intro and three cards) under a protected h1, plus
    three sections further down.
- **Lighthouse.** The same as above: mobile preset, simulated throttling, 3 runs per page, medians.
- **Browser check** (`scripts/perf.mjs`, Playwright with the same Chromium and server, 3 runs per page):
  - A 412 × 823 touch phone with 4× CPU slowdown, 150 ms latency, 1.6 Mbit/s down and 750 kbit/s up.
  - It records LCP and its element, and whether that element is inside an animated block.
  - It records CLS at load and including scrolling to the end, and long tasks.
  - It records the slowest of five taps on text (Event Timing; a synthetic interaction, **not field INP**).
  - It counts entrances that finished at load and while scrolling (by `animationend`).
  - It counts animated blocks still not fully shown on screen 3 s after load.

| Page | | Lighthouse score (runs) | LH LCP | LH TBT | Browser LCP median (runs) | LCP element in an animated block | CLS (load / with scroll) | Slowest tap (runs) | Entrances ended at load / scrolling | Hidden in view at 3 s | Script bytes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `/perf-nested` | before | 100 (100/100/100) | 0.97 s | 9 ms | 600 ms (600/348/700) | no (h1) | 0 / 0 | 32/40/112 ms | 0 / 0 | 0 | 0 |
| | after | 100 (100/100/100) | 0.97 s | 4 ms | 412 ms (428/360/412) | no (h1) | 0 / 0 | 32/24/24 ms | 0 / 0 | 0 | 0 |
| `/perf-hero-motion` | before | 100 (100/100/100) | 1.32 s | 22 ms | 904 ms (1188/904/876) | no (hero photo) | 0 / 0 | 32/24/40 ms | **0** / 0 | 0 | 1,828 |
| | after | 100 (100/100/100) | 1.19 s | 0 ms | 552 ms (636/552/524) | no (hero photo) | 0 / 0 | 16/16/32 ms | **2** / 0 | 0 | 2,317 |
| `/perf-nested-motion` | before | 100 (100/100/100) | 1.02 s | 9 ms | 496 ms (752/480/496) | no (h1) | 0 / 0 | 16/16/24 ms | **0** / 7 | 0 | 1,828 |
| | after | 100 (100/100/100) | 0.95 s | 0 ms | 376 ms (460/364/376) | no (h1) | 0 / 0 | 32/24/16 ms | **4** / 7 | 0 | 2,317 |
| `/perf-intro-motion` | before | 100 (100/99/100) | 1.02 s | 0 ms | 404 ms (404/348/412) | no (h1) | 0 / 0 | 32/32/32 ms | **0** / 2 | 0 | 1,828 |
| | after | 100 (100/100/100) | 0.99 s | 0 ms | 392 ms (416/392/368) | no (h1) | 0 / 0 | 24/32/16 ms | **5** / 2 | 0 | 2,317 |

- **What changed for visitors.**
  - Content on screen at load now plays its entrance: 0 → 2, 4 and 5 finished entrances at load. With motion-1 it was
    shown without one.
  - Below-the-fold entrances still play once when scrolled to (7 and 2, unchanged).
  - No animated block is left hidden in view.
- **Critical content.** The LCP element (the hero photo, or the h1) is never inside an animated block, before or
  after; protection is unchanged.
- **No regression found.** LCP is within run-to-run variation; no page got slower. The browser-check LCP of the
  unchanged, animation-free `/perf-nested` moved from 600 to 412 ms between sessions, so differences of that size are
  machine noise, not effects of the change. CLS is 0 everywhere, including while entrances play during scrolling
  (opacity and transform only). TBT is 0–22 ms, with at most one long task per run.
- **Cost.**
  - The runtime is about 490 bytes larger: 2,317 bytes transferred instead of 1,828, uncompressed.
  - The animation CSS is 34 bytes smaller.
  - The runtime runs once: one `getBoundingClientRect` and `getAnimations` per "view" block, then one
    IntersectionObserver.
- **Editor** (`EDITOR_PERF=1`, ~200 blocks, 1440 × 900, unthrottled):

  | Interaction | Before | After (3 runs) |
  |---|---|---|
  | Changing animation settings (3 effects and a duration, each now with a preview) | 56 ms | 32 / 32 / 32 ms |
  | Dragging a block | 40 ms | 48 / 32 / 40 ms |
  | Drag preview p95 | 25.5 ms | 25.0 ms |
  | Frames while dragging, p95 | 16.7 ms | 16.7 ms |

- **What this does not show.** These are lab results on one machine. They don't establish field LCP, CLS or INP: a
  synthetic tap is not INP, and Lighthouse never scrolls, so it doesn't see "view" entrances. A page that sets long
  delays or durations on content on screen at load shows that content later by design.

### Keyboard focus fix (motion-3), recheck (2026-10-08)

motion-3 changes public output in two ways: a `:focus-within` rule (+12 bytes of CSS) and a runtime that is 329 bytes
larger (2,646 bytes transferred instead of 2,317, uncompressed), now also loaded on pages whose entrances are all "on
page load". The harness was run again on the same machine (`PERF_OUT=storage/perf-motion3`, all seven fixture pages,
same profiles as above); the motion-2 column is the "after" run of the previous section.

| Page | Lighthouse score, motion-2 → motion-3 | LH LCP | LH TBT | Browser LCP median (motion-3 runs) | CLS (load / scroll) | Long tasks | Slowest tap | Entrances ended at load / scrolling | Hidden in view at 3 s |
|---|---|---|---|---|---|---|---|---|---|
| `/perf-nested` (no animations) | 100 → 100 | 0.97 → 0.88 s | 4 → 0 ms | 412 → 360 ms (356/368/360) | 0 / 0 | 0 | 16–32 ms | 0 / 0 | 0 |
| `/perf-hero-motion` | 100 → 100 | 1.19 → 1.16 s | 0 → 0 ms | 552 → 548 ms (548/548/524) | 0 / 0 | 0 | 16 ms | 2 / 0 | 0 |
| `/perf-nested-motion` | 100 → 100 | 0.95 → 0.95 s | 0 → 0 ms | 376 → 388 ms (408/384/388) | 0 / 0 | 0 | 24–32 ms | 4 / 7 | 0 |
| `/perf-intro-motion` | 100 → 100 | 0.99 → 0.94 s | 0 → 0 ms | 392 → 344 ms (344/344/360) | 0 / 0 | 0 | 16–32 ms | 5 / 2 | 0 |

- The other fixture pages (`/perf-hero`, `/perf-images`, `/perf-images-motion`) scored 99–100 with CLS 0 and no long
  tasks. Their LCP element is the priority image or h1 and is never inside an animated block.
- Differences are within the run-to-run variation seen before (the unchanged `/perf-nested` moved by 50 ms again).
  Entrances and held-back blocks behave as with motion-2.
- **Not covered:** every fixture page with animations already has a "when scrolled into view" block, so all of them
  loaded a runtime before. The new cost on a page whose entrances are all "on page load" is one more deferred
  2.6 KB request after parsing; it was not measured separately. As before, these are lab results on one machine, not
  field LCP, CLS or INP.

## Verifying Core Web Vitals in production

Targets at the 75th percentile of real page loads, mobile and desktop separately: LCP ≤ 2.5 s, INP ≤ 200 ms,
CLS ≤ 0.1. INP needs real interactions and cannot be measured by Lighthouse (TBT is only a lab proxy and is not
reported as INP here). Arkon adds no tracking or analytics scripts to public pages, so field data comes from outside:

1. After deployment with real traffic, check the **Chrome UX Report** field data for the origin and key URLs, through
   PageSpeed Insights ("Discover what your real users are experiencing") or the CrUX API/BigQuery; it reports p75 LCP,
   INP and CLS for phone and desktop separately. Low-traffic pages may not have URL-level data; use origin-level data.
2. Google Search Console's Core Web Vitals report groups URLs that fail on mobile or desktop.
3. If first-party field data is required later, it would be an explicit, opt-in addition (a small `web-vitals`
   reporter sending to an Arkon endpoint), reviewed for privacy and its own performance cost; it is deliberately not
   part of this milestone.
4. Re-run `npm run perf` after changes to components, the renderer or image handling, and compare with the table above
   under the same conditions.

## Editor responsiveness

`EDITOR_PERF=1 npx playwright test e2e/responsiveness.spec.ts` opens a page of about 200 blocks (a hero, 12
sections with headings, text and three columns each) in the builder at 1440 × 900 and reports interaction durations
from the browser's Event Timing API (the same source as INP; only interactions of 16 ms or more are recorded).
Measured 2026-10-07 on the same machine (unthrottled):

| Interaction | Slowest |
|---|---|
| Opening the editor until the canvas shows the page | 675 ms |
| Typing in the inspector (29 keys) | 48 ms (median 24 ms) |
| Typing on the canvas (18 keys) | under 16 ms |
| Selecting blocks and parts | 40 ms |
| Switching screen size | 80 ms |
| Switching to Layers (lists every block) | 80 ms |
| Dragging a block with the Move handle | 24 ms |

Canvas updates after inspector edits are a server render (one request, coalesced while typing); the canvas
shows the latest document once it returns.

## Dragging

`EDITOR_PERF=1 npx playwright test e2e/drag-profile.spec.ts` drags a block over the same ~200-block page and holds it
at the bottom edge. Pointer events are generated in the page at 125 per second (a real mouse's rate; Playwright's own
mouse delivers far fewer); latencies come from the browser (event timestamp to the frame or DOM change that shows the
result). Headless Chromium 153, 1440 × 900, same machine, three runs (and one with DevTools' 4× CPU slowdown):

| Measure | Runs 1 / 2 / 3 | 4× CPU slowdown |
|---|---|---|
| Pointer → preview on screen, p95 | 31.7 / 24.7 / 32.1 ms (max 44 ms) | 51.2 ms (max 81 ms) |
| Pointer → destination label changed, p95 | 16.1 / 9.1 / 15.5 ms | 16.6 ms |
| Frame interval while moving, p95 | 16.8 / 16.7 / 16.8 ms | 33.4 ms |
| Long tasks (> 50 ms) | 0 / 0 / 0 | 0 |
| Messages editor ↔ canvas while moving | 0 / 0 / 0 per second | 0 |
| Stationary edge scrolling | 1,016 / 1,000 / 1,024 px/s, frames p95 16.8 ms | 1,308 px/s, frames p95 66.6 ms |
| Messages editor ↔ canvas while scrolling | about 63 per second each way (one per frame) | about 45 |

Before this milestone, holding the pointer still at the edge scrolled 24 px and stopped, and every pointer move sent a
hit request to the canvas and waited for its reply. The first profile of the new code also found the bridge
re-measuring every block each frame (a ResizeObserver re-armed on every measurement) and sending selection rectangles
during drags; both were fixed before these measurements.


## Website/contact foundation (8 October 2026)

The new /perf-contact fixture uses linked shared header/footer components and a native three-field form. Run it with PERF_PAGES=/perf-contact and PERF_OUT=storage/perf-website. On the existing mobile profile, three Lighthouse scores were 81, 100, 100 (median 100); median LCP 1,082 ms, CLS 0, TBT 63 ms. Output used 2,573 bytes CSS, approximately 4.7 KB HTML and zero script downloads. The direct throttled browser reported LCP 628/496/456 ms, CLS 0 during load and scrolling, zero long tasks and slowest synthetic taps 24/24/16 ms. This is a small, image-free contact fixture on the local PHP server; it is not field data or a promise for arbitrary AI-generated layouts. Header/footer landmarks are excluded from new renderer image-priority indexing, so a header logo does not steal automatic priority from the first main-content image.

## Native navigation verification (8 October 2026)

The /perf-contact fixture now includes native desktop/mobile navigation and a dropdown in its shared header. Three mobile Lighthouse runs scored 100/100/100: median LCP 979 ms, CLS 0, TBT 8 ms, generated CSS 3,483 bytes, approximately 6.3 KB HTML and zero script downloads. Direct throttled Chromium measured LCP 740/1232/528 ms, CLS 0 during load and scroll, and synthetic taps 24/24/16 ms. Reports are stored in storage/perf-navigation. This image-free local fixture measures the new navigation path; field Core Web Vitals and photographic generated sites still need their own measurements.

## Reference website builder (9 October 2026)

Three mobile Lighthouse 12.8.2 runs per page, production settings on the local PHP server, 4× CPU slowdown and simulated mobile network throttling. No other test suite ran during this final measurement. Reports and direct browser observations are in storage/perf-reference-final; regenerate with PERF_PAGES=/perf-reference,/perf-reference-inter,/perf-contact and PERF_OUT=storage/perf-reference-final.

| Fixture | Scores (three runs) | Median score | Median LCP | CLS | Median TBT | Transfer | Public scripts |
|---|---|---:|---:|---:|---:|---:|---:|
| Reference layout, default system fonts | 99 / 98 / 97 | 98 | 2,159 ms | 0 | 109 ms | 88.3 KB | 1, 4,861 bytes |
| Same layout, locally served Inter | 97 / 93 / 96 | 96 | 2,531 ms | 0.005 | 0 ms | 161.3 KB | 1, 4,861 bytes |
| Plain contact page | 100 / 100 / 100 | 100 | 918 ms | 0 | 0 ms | 6.9 KB | 0 |

The reference includes a sticky logo/navigation header, two-slide hero, managed background images with overlays, service cards, a real newsletter form and footer/back-to-top. Imagery is generated placeholder data, not large photographic production assets. The widget runtime is conditional; React/Inertia/editor code is absent from public HTML.

Direct throttled Chromium (three runs) measured reference LCP 700/776/588 ms, CLS 0 during load and scrolling, zero long tasks, and slowest synthetic taps 48/16/16 ms. The Inter variant measured LCP 652/628/696 ms and CLS 0.005; contact measured 424/392/400 ms and CLS 0. Synthetic taps are not field INP. Browser observations and simulated Lighthouse LCP use different methods and must not be treated as interchangeable.

The system-font layout meets the local LCP/CLS targets. Optional Inter increases transfer and its median simulated LCP is just above 2.5 seconds. Prefer system fonts for the fastest first visit; choosing a web font has a measurable cost even when served locally. Font-display swap, reserved slide height, responsive backgrounds and initial-image preloads limit regressions but do not guarantee field Core Web Vitals. Measure final photos, content and deployment with real-user data.
