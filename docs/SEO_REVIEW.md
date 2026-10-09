# Arkon SEO review and implementation

Reviewed and implemented on 10 October 2026. Changes are local and uncommitted.

## What the audit found

Page metadata already lived in the versioned page document: title, description and noindex. The PHP renderer produced title, canonical and basic social tags; public sitemap/robots routes used published state. SEO controls were buried in the Properties inspector, the site overview mostly displayed metadata, and AI could not propose SEO metadata. There was no explainable live analysis, social override workflow or versioned global SEO-default resource.

The implementation extends those existing systems. It does not add a second page metadata record, an external crawler, another AI provider or a separate publishing path.

## How to use it

1. Open any page and select **SEO** in the editor, or click its toolbar SEO score.
2. Search contains the preview, title, description, optional focus topic and page purpose. Social contains separate share text/image overrides. Advanced contains canonical, inherited indexing choices and structured-data type.
3. Changes update deterministic checks after a short pause. Save draft keeps them private. Publish changes the live page.
4. **Generate** requests one field; **Improve SEO with AI** prepares metadata proposals and advisory notes. Review every field and the calculated before/proposed scores before applying. Apply uses normal draft saving and undo/history. It does not publish.
5. Image-description suggestions use existing library descriptions and page context. They cannot see image pixels. Use library alt text directly when suitable; review any AI suggestion.
6. Open **Site management → SEO** for separate draft/live scores, published score distribution, per-page priorities and site health. **Review / AI fix** opens the page SEO tab; an AI request still requires an explicit click.
7. Site SEO defaults provide a title pattern, description, social image, indexing defaults and optional real organization/person identity. Save defaults first, then explicitly publish. Live revisions are refreshed; newer page drafts are not published by that refresh.

Restart the existing Claude Code helper and MCP process after updating code, so they load the new proposal schema. No real Claude requests were used for automated verification.

PHP’s native DOM extension (`ext-dom`) is declared in Composer for HTML inspection. This is a platform requirement, not a new third-party package or a Herd dependency.

## Explainable score

The total is the sum of passed checks, out of 100. Each check exposes its category, earned points, possible points, status and explanation.

| Category | Checks | Points |
|---|---|---:|
| Metadata | Title present; description present | 18 + 12 |
| Structure | One primary heading; no skipped heading levels | 15 + 10 |
| Technical | Valid canonical; mobile viewport | 15 + 5 |
| Images | Every rendered image declares an alt attribute | 10 |
| Links | No unresolved site-local routes or placeholder destinations | 10 |
| Social | Open Graph and Twitter/X titles present | 5 |

Excellent is 85–100, Good 70–84, Needs improvement 50–69, Needs attention below 50. These labels describe these limited checks; a high score is not a content-quality, accessibility, ranking or performance guarantee.

Title/description length, focus-topic relevance, useful internal linking, intentional noindex and schema observations are additional guidance. They do not reward repetitions, artificial word counts, stuffing or needless schema. Contact and landing pages receive no article word-count penalty. Schema Auto considers page purpose.

Search/social previews are illustrative. Search engines can choose different titles and snippets. The title/description length hints are approximate display guidance, not Google limits. See Google's [title-link guidance](https://developers.google.com/search/docs/appearance/title-link) and [snippet guidance](https://developers.google.com/search/docs/appearance/snippet).

## Safety and architecture

- Page SEO remains part of immutable revisions, reversible operations and version-checked saving. Publishing is explicit and keeps existing retry/ordering safeguards.
- The dedicated AI metadata action permits text metadata only. Server-side scope enforcement rejects content/design changes, URL changes, canonical or robots changes and changes to a different field. Alt requests may only change the selected existing image's description, preserving its asset and other properties. Internal-link notes receive actual site-local live destinations, never invented destination lists or another site's documents.
- Manual canonical values use shared PHP/TypeScript validation. Partially typed invalid values remain visible and block publishing until corrected or reverted; they never silently replace the last valid draft value.
- Empty text/image overrides inherit published defaults. Explicit false indexing overrides remain distinct from inheritance. Global defaults are separate versioned resources with immutable published snapshots and site-scoped permissions.
- Renderer `arkon-php-8` records defaults, organization identity and media in publication inputs. Released renderer versions 1–7 remain available for reproduction. Existing publication HTML is not rewritten by merely opening SEO or applying the migration.
- Social-image previews use transient signed capabilities on the registered public site. The original public share URL remains unmodified, and a draft image is still private without authorization or its expiring token. Core decorative images can retain empty alt; custom theme fields continue to follow their own publish rules.
- New publications can contain inert `application/ld+json` data. This is not executable JavaScript, React hydration or an admin bundle. Existing CSP restrictions on executable scripts remain intact. The JSON is escaped and schema types are bounded; unsupported factual claims are not generated.
- Sitemap inclusion reads the stored published head, excludes noindex pages and non-self-canonical pages, and does not inspect unsaved drafts. Canonical and robots choices are user-controlled; see Google's [canonical guidance](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls) and [robots directives](https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag).
- Checks inspect locally rendered/stored HTML, with debouncing and cached dashboard analyses. They make no crawler, Lighthouse or model requests. The new editor/dashboard code is not delivered with public pages. Public rendering remains stored HTML; actual field Core Web Vitals are not measured by this SEO score.
- Site defaults publishing queues the established live-page refresh system, with per-site epoch ordering. Owners/admins may change defaults; ordinary editors can review them and change authorized page drafts.
- Windows Claude Code schema arguments are compacted when large, retaining equivalent rules and preflighting the process command length before a run starts.

## Honest limits and next improvements

- Bulk AI suggestions remain future work. Current requests operate on one page with review; nothing mass-rewrites the site.
- Basic schema types are WebPage, AboutPage, ContactPage, Article and BlogPosting, plus Auto/None. Product, LocalBusiness and FAQ require a typed factual model before offering them. JSON/type checks are not a rich-result eligibility validator.
- Alt presence cannot determine whether an empty description is appropriately decorative. AI metadata grounding is instructed and scoped, but accuracy still requires human review. Automated tests use fake model output; they do not establish real-model writing quality.
- Internal route checks do not crawl external links, check fragment IDs or fetch server status codes. Redirects are resolved from Arkon's live site records. Search indexing and actual Google results require external verification.
- Site title/canonical/description checks inspect actual outputs. The published average is unweighted by traffic and is separate from the draft scores. The dashboard handles draft analysis in pages of 50; very large sites would benefit from background cached reporting.
- Global defaults save/publish retries are retained while the screen remains open. Reloading an uncertain request still requires checking state; durable intent storage across reloads is a follow-up.
- Theme/editor content-type defaults and richer content recommendations can grow from this foundation without adding another metadata store.

## Verification

| Check | Result |
|---|---|
| Full PHPUnit regression run | 409 passed; 2,713 assertions |
| Final signed-preview / SEO workflow checks | 6 passed; 32 assertions |
| TypeScript and Vitest | Typecheck clean; 384 passed across 27 files |
| Final browser SEO, recovery, editor and theme suite | 14 passed; fake Claude only |
| Final SEO UI rerun after private-preview refinement | 4 passed, including authentication setup |
| Production build | Passed |
| PHP formatting, local diff whitespace, Composer manifest/lock validation | Passed |
| Existing development publications | All 49 reproduce byte for byte |
| Runtime database access | Restricted app role; no privileged credentials |

The browser runs used isolated databases and PHP’s local test server. Desktop and phone screenshots were inspected; this was not a real-model quality evaluation or a field CWV measurement.

No development page was automatically published. No commit or push was made.
