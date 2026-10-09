# Arkon (Laravel)

A CMS where pages are a structured document, edited visually in a sandboxed canvas and published as
clean HTML with small conditional enhancements for animations and sliders. This is the Laravel port of the Next.js reference project (`C:\Herd\Arkon`).

- Backend: Laravel 13, PostgreSQL. Admin and editor: React + TypeScript through Inertia.
- Served by Laravel Herd at **http://arkonlaravel.test**. No Node server at runtime.
- Architecture, decisions and status: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Migration plan: [docs/MIGRATION_PLAN.md](docs/MIGRATION_PLAN.md).
- Admin and builder UI conventions (tokens, type roles, components, workspace): [docs/ADMIN_DESIGN_SYSTEM.md](docs/ADMIN_DESIGN_SYSTEM.md).

## Requirements

- Laravel Herd with PHP 8.4 (needs `pdo_pgsql`, which Herd's PHP has). Use Herd's `php`: in PowerShell `php` resolves to it.
- PostgreSQL 16+ (developed on 17, port 5432) and its superuser password, for the one-time bootstrap.
- Node.js 22+ (to build the admin assets and run the browser tests).

## First-time setup

```powershell
cd C:\Herd\ArkonLaravel
composer setup        # composer + npm install, .env and .migrate.env from the examples, APP_KEY, asset build
```

1. **Credentials.** Put strong random values in `DB_PASSWORD` (`.env`) and `MIGRATION_DB_PASSWORD` (`.migrate.env`).
   `.env` is the runtime config; `.migrate.env` holds the schema-owner login and is read only by migration
   tooling and the test harness. Both are git-ignored.

2. **Roles and databases** (once per machine). The superuser password is used for this one command and never stored:

   ```powershell
   $s = Read-Host "postgres superuser password" -AsSecureString
   $env:PG_SUPERUSER_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))
   php artisan arkon:db-bootstrap
   Remove-Item Env:PG_SUPERUSER_PASSWORD
   ```

   It creates the roles `arkonlaravel_owner` and `arkonlaravel_app` and the databases `arkonlaravel`,
   `arkonlaravel_test` and `arkonlaravel_e2e`. Other databases and roles (including the old `arkon*` ones) are not touched.

3. **Schema and demo site.**

   ```powershell
   php artisan arkon:migrate      # migrations + grants + data upgrades, as the schema owner
   php artisan arkon:seed         # demo site for arkonlaravel.test with an unpublished home page
   ```

## Sign in

There is no sign-up page. Create your owner account from the command line; the generated password is printed **once**:

```powershell
php artisan arkon:owner-create --email=you@example.com --name="Your Name"
```

Then open **http://arkonlaravel.test/admin** and sign in. More accounts:
`php artisan arkon:member-create --email=... --name=... --role=editor` (roles: owner, admin, editor, viewer).
Five wrong passwords lock the address for a minute.

## Using it

- `http://arkonlaravel.test/admin`: dashboard. `http://arkonlaravel.test/`: the public home page (404 until published).
- **Dashboard**: your pages with draft/live status, **Continue editing** (the page edited last), **Needs attention**
  (AI proposals waiting for review, pages with unpublished changes, live pages not yet updated after a design change,
  unpublished design drafts) and **Recent activity** (publishing, restores, design changes from the audit log). The
  light/dark/system theme switch is at the bottom of the left menu and applies to the builder too.
- **Pages → Home → Edit.** Click the heading or text in the canvas and type, or use **Properties**. Ctrl+S saves,
  Ctrl+Z / Ctrl+Shift+Z undo and redo outside text fields. The toolbar shows the draft's state in words (Draft saved,
  Unsaved changes, Saving…, Save not confirmed, invalid fields not saved, Out of date, Previewing AI proposal) and
  whether the page is live; **Preview**, **Save draft** and **Publish** are separate buttons.
- **Workspace.** On screens 1536 px and wider the builder shows the outline (Layers, History, AI) on the left and
  Properties on the right, beside the canvas; the sidebar button next to the back arrow hides or shows the outline
  (remembered). Narrower screens use one sidebar with Properties / Layers / History / AI tabs.
- **Layers** tab: **+ Section / Group / Hero / Text / Image / Button / Columns** adds a block after the selection
  (or inside a selected container); a published **reusable component** can be added the same way. Or use the ↑ ↓ ✕
  buttons (the toolbar above the properties does the same, plus **Select parent** and **Make reusable**). Sections
  are full-width bands, groups arrange blocks in a stack, row or grid, columns get widths such as 1fr 2fr.
  Undo/redo covers everything.
- **Duplicate** (page and component editor): **Duplicate** in the toolbar above the properties, the copy icon on a
  Layers row or next to the selected block's Move handle on the canvas, or **Ctrl+D** (⌘D) when a block is selected and
  you are not typing in a field. The copy (with everything inside it, its design and animation settings, images, and a
  reusable component's link) goes right after the original and is selected; one Ctrl+Z removes it. If it can't be
  copied there (a full container, Columns already at 6 columns, the page's size limit, or a link in it that isn't
  valid yet) nothing changes and the editor says why.
- **Delete**: select a block on the canvas and click the trash control next to Duplicate and Move. It says exactly
  what goes ("Delete button", "Delete section and its contents"). With only a hero's image selected it says
  "Remove image" and keeps the hero. **Delete** or **Backspace** does the same when the canvas (not while typing
  text), a Layers row or the block's toolbar has focus. Blocks with content inside ask first (Cancel changes
  nothing); everything else goes at once, with **Undo** in the message. One Ctrl+Z brings it back; nothing is saved
  or published until you choose to. The page itself and the last column of a Columns block can't be deleted.
- **Columns**: **+ Columns** first shows a layout picker (2–6 equal columns, one third/two thirds, sidebar, wide
  middle …); one click creates the columns themselves with those widths. Selecting a Columns block shows
  **Number of columns** (1–6: adding keeps all content; removing columns that hold blocks asks whether to move them
  to the last remaining column or delete them, and Cancel changes nothing), **Widths** (equal or proportions, or your
  own such as `1fr 2fr 1fr`) and **On smaller screens** (tablets and phones: stacked, side by side, or 2–5 per row;
  phones stack by default). Proportions always give one width per column (typing `1fr 2fr 1fr` for two columns is
  refused with a message). Widths follow their columns when you reorder, delete or duplicate a column; where they
  can't (a column moved in from another block, new empty columns) they go back to equal, and the editor says so.
  Empty columns show **Add block** on the canvas.
- **Animation** (Properties of any block, at the bottom): **Entrance** None / Fade in / Fade up, down, left, right /
  Subtle zoom in; **Plays** on page load or once when scrolled into view (a block already on screen when the page
  opens plays then); **Duration**, **Delay**, **Easing**; **Don't animate on phones**. Next to the effect a status
  says what visitors will really see: plays, off on this screen, reduced motion, or **protected content**. Choosing or
  changing a setting previews it on the canvas as soon as the canvas has updated; **Preview animation** plays it again
  (the canvas otherwise shows the final state; clicking, typing, selecting or dragging stops a preview). The whole
  block animates as one. The page's main heading and the image that loads first (and any block around them) always
  appear immediately: their status names the heading or image, the effect can't be chosen, and a section or column
  that already had an entrance offers **Move this entrance to the other blocks inside** (only when some block inside can
  play it: never the heading, the image or a block holding them; blocks with their own entrance keep it). Keyboard
  focus inside an entrance shows it, and every animated block around it, at once. Visitors who prefer reduced
  motion see the final state; without JavaScript entrances play with the page and end shown.
- **Dragging** (the same in the page editor and the reusable-component editor):
  - *What can be dragged:* the selected block by its **Move …** handle above it on the canvas (it names exactly what
    moves, e.g. "Move Hero section": a hero's image moves with its hero), any block you point at by the small handle
    on its outline, a row in **Layers** (anywhere on the row with a mouse; by its grip ⠿ with a finger or pen), and a
    palette item (to add a block where you drop it). A press without movement is still a click.
  - *Where it lands:* a line shows the gap it goes into (between two blocks, first or last in a container, also
    in the space and padding between blocks), an outline shows an empty container it goes into, and a label near
    the pointer says "After Text: Our services · in Section". Side-by-side and reversed layouts are followed as you
    see them. In Layers, at the end of a group, moving the pointer left or right chooses the level. Places the
    nesting rules don't allow say why ("Not allowed here: Section can’t go in a group") and dropping there does
    nothing.
  - *Long pages:* hold the pointer near the top or bottom of the canvas (or of the Layers list) and it keeps
    scrolling, faster nearer the edge, until you move away; the place follows the scrolled content.
  - *Cancel:* **Esc**, releasing outside the canvas and Layers, or switching to another window. A drop is one undo
    step (Ctrl+Z); dragging changes only the draft (Save draft and Publish stay separate).
  - Keyboard: the ↑ ↓ buttons in Layers and the toolbar move the selected block.
- **What you are editing** is always named at the top of Properties: a path (Page › Section › Hero section), the
  part being edited (for a hero: Hero section, Content area, Heading, Supporting text, Buttons, Image) and buttons to
  switch parts. Clicking the image on the canvas opens the image's own controls; clicking the hero's background opens
  the section's. The canvas outlines the component and highlights the active part with its name and size.
- **Design controls** are named for the part they change ("Image height", "Section width"). Lengths are a number
  plus a unit (px, %, rem, em, vh, vw, ch), a keyword such as Auto, or a site token. Each control says whether its
  value is set here, inherited ("From all screens: 500px") or the default. **Desktop / Tablet / Mobile** above the
  canvas (and **Design for** in Properties) choose the screen you edit: values on all screens apply everywhere;
  tablet and mobile override only what you set there and are marked "Mobile override". **Reset** removes a value.
  Common settings come first; **More design settings** holds the rest (layout, spacing, typography, background,
  border, effects), each setting in one place only. Invalid values are shown and never applied.
- Button links must start with `/`, `#`, `https://`, `http://`, `mailto:` or `tel:`, and contain no spaces,
  backslashes or accented characters (percent-encode them); anything else is refused. A link that isn't valid yet
  stays in the field and is not applied: the status says "invalid field not saved", and Preview and Publish ask
  you to fix it or click **Revert link** first.
- A draft saved before backslash links were refused opens with **This draft needs repair**: it lists each stored
  link exactly; correct it or remove its block, click **Apply repair**, then save. Until then nothing else can be
  edited, saved or published, and the live page stays as it is.
- Images: select the image (on the canvas, or **Edit image** on a hero) for **Image size** (width, height, aspect
  ratio), **Fit and crop** (cover crops only when the box has a height or aspect ratio) and **Crop position**, next to
  the image itself (library with names and thumbnails, Upload, alternative text). An image block's **Block size** is
  separate. **Loading** chooses automatic (the first image near the top loads first), early, or when scrolled to. Uploads get
  smaller WebP copies automatically (the original is kept); published pages pick the right size per screen.
- **Design** page (left menu): the site's **design tokens** (colours, fonts, type scale, spacing, widths, radii,
  shadows) and **reusable components**. Token and component changes are drafts until you **Publish** them there;
  publishing updates every live page that uses them (re-rendered from what is published on each page, never its
  draft), and pages that could not be updated are listed with **Retry now**. A component is edited on its own page
  (same canvas and controls); on pages it shows the published version. **Detach** (in an instance's properties)
  copies its blocks into the page instead.
- **Upload** in the image's controls, then describe informative images; leave alternative text empty for decorative images. Uploads stay private
  (404 to visitors) until a published page uses them; the canvas shows them through short-lived signed URLs.
- **Save draft** never changes the live page. **Preview** shows the saved draft exactly as it would be published.
  **Publish** makes it live. **History** lists every revision; **Restore** copies one into the draft.
- **Pages → New page** creates an unpublished draft. Change title and URL in the editor under
  **Properties → Page** (the first item of the path) **→ Title and URL**; the live page keeps its old URL until you publish, then the old URL redirects (301).
- **Unpublish** and **Delete** (owners and admins) are on the Pages list and ask for confirmation. History is kept.
- Network trouble while saving or publishing keeps your changes; saving or publishing again retries the same
  request safely and never applies it twice.

## AI page proposals (Claude Code, your subscription)

Describe a page and Claude proposes changes built from Arkon's own blocks and design settings: sections, groups,
columns, heroes, text, images, buttons and reusable components, with sizes, spacing, colours, typography,
per-screen layout and entrance animations, and it can duplicate existing blocks (for example "Put the hero image on
the right, 500px tall with cover cropping, and stack it below the text on mobile", "Duplicate the Book now button
and call the copy Call us", "Add five equal columns to the Our work section" or "Make the Our work section fade up
when it scrolls into view"). You preview the proposal in the editor, then **Apply to draft** or **Discard**. Applied content
is ordinary, editable blocks and settings. Nothing is ever published by the AI: publishing stays the **Publish**
button. Site-wide colour or font changes are listed separately and only reach the token draft when you click
**Apply to the token draft** (publish them on the Design page).

Claude runs as **Claude Code under your own Claude subscription** (Pro/Max/Team) on this computer. There is no API key,
no API billing, and Arkon never reads or stores your Claude login. Two ways to prompt, same proposals:

| Where you type | How it reaches Arkon | Needs |
|---|---|---|
| Claude Code in VS Code (chat) | Arkon's MCP server (`php artisan arkon:mcp`), started by Claude Code | one-time MCP registration |
| Arkon's editor, **AI** tab | the local helper (`php artisan arkon:ai-helper`) runs the Claude Code CLI | the helper running in a terminal |

### One-time setup (Windows, PowerShell, in `C:\Herd\ArkonLaravel`)

1. **Claude Code** must be installed and signed in with your Claude account (not an API key):

   ```powershell
   claude --version          # 2.1.259 or later
   claude auth status --text # should say you are logged in with your Claude account (claude.ai)
   ```

   If `claude` is not found: install it with `irm https://claude.ai/install.ps1 | iex` (or use the VS Code extension's
   bundled copy, which the helper also finds). If you are signed in with an API key or Console billing, run
   `claude auth logout` and then `claude auth login` with your Claude account; the helper refuses API billing mode.

2. **VS Code: register Arkon's MCP server.** Pair it with your Arkon account (it prints a token once, inside the
   exact command to run):

   ```powershell
   php artisan arkon:ai-pair you@example.com --mcp
   ```

   Run the `claude mcp add arkon --scope user -e ARKON_MCP_TOKEN=… -- "…\php.exe" "C:\Herd\ArkonLaravel\artisan" arkon:mcp`
   line it prints, then restart Claude Code in VS Code (or run `/mcp` there to check that `arkon` is connected).

3. **AI panel: pair the helper** (the token is saved to `storage/app/private/ai-helper.token`, git-ignored):

   ```powershell
   php artisan arkon:ai-pair you@example.com --helper
   ```

`--site=<id>` is needed only if your account belongs to several sites. List connections with
`php artisan arkon:ai-connections`; revoke one with `php artisan arkon:ai-revoke <id>` (its token stops working at once; a request a revoked
helper was running goes back to the queue and its late answer is ignored).

### Prompt in VS Code

Ask Claude Code, for example: *Use Arkon to build a homepage for a landscaping business, with a hero, services, about
section and contact button, on the Home page.* It reads the page and the component catalogue through Arkon's tools,
submits a proposal, and tells you it is waiting for review. Arkon validates it; the draft is not changed. Then open
**http://arkonlaravel.test/admin** → **Pages** → the page → **Edit** → **AI** tab → **Waiting for your review** →
**Review** → **Apply to draft** (or **Discard**). The MCP tools can list pages, read a page and the proposal format,
submit a proposal and read its status; they cannot apply, publish, run commands or read files.

### Prompt in the editor (the helper must be running)

1. Start the helper and leave the window open while you use the AI panel (Ctrl+C stops it):

   ```powershell
   php artisan arkon:ai-helper
   ```

   It prints e.g. `Claude Code 2.1.292, signed in with a Claude Pro subscription.` The AI tab shows the same line
   with a green dot once it is connected.
2. Open **http://arkonlaravel.test/admin** → **Pages** → **Home** → **Edit** → **AI** tab and enter, for example:
   *Build a homepage for a landscaping business, with a hero, services, about section and contact button.*
3. **Generate proposal**: the request is **queued**, then **running** while Claude Code works (usually 10–60 s; you can
   **Cancel**). The proposal then opens: the canvas shows the proposed page, and the panel lists what will change,
   what blocks publishing (e.g. "Button needs a link") and notes from Claude. Nothing has changed yet.
4. **Apply to draft**: one edit, saved as a revision marked "AI" in **History**; **Undo** reverts it in one step.
   Or **Discard**.
5. Follow up, e.g. *Shorten the headline and add a services section.* Each request works on the current saved draft.
6. Set the contact button's **Link** (Claude leaves it empty unless you give one), then **Preview** and **Publish**.

The helper runs `claude -p` with no tools (it can only return proposal data), no MCP servers, no project settings or
hooks, in an empty temporary folder, with the prompt on stdin and an environment without Arkon's database settings
or any API key. What Claude is told: the site name, the page title and URL, the page's blocks and their text, the
ids/alt/sizes of images already on the page, the component catalogue and your request.

### Troubleshooting

| The AI tab / helper says | Do this |
|---|---|
| "The local Claude Code helper is not connected / not running" | Run `php artisan arkon:ai-helper` in a terminal and keep it open. |
| "Not paired yet (or the token was revoked)" (helper window) | `php artisan arkon:ai-pair you@example.com --helper` (the running helper picks it up). |
| "Claude Code was not found" | Install Claude Code, or set `ARKON_CLAUDE_COMMAND=C:\path\to\claude.exe` in `.env`. |
| "Claude Code … is too old" | `claude update` (or update the VS Code extension). |
| "Claude Code is not signed in" | `claude auth login` with your Claude account, then restart the helper. |
| "signed in with "api_key" (API or Console billing)" | `claude auth logout`, then `claude auth login` with your Claude account. |
| "Your Claude subscription's usage limit has been reached" | Wait until it resets (Claude Code's own limit; Arkon cannot see your remaining allowance). |
| "did not finish within 240 seconds" | Ask for less at once, or raise `ARKON_AI_RUN_TIMEOUT`. |
| "The draft changed while this request was waiting" | Ask again (proposals are always based on the saved draft). |
| VS Code: `arkon` tools missing / "not paired or was revoked" | Pair again with `--mcp` and re-run the printed `claude mcp add` (after `claude mcp remove arkon`). |

Arkon limits how often requests start (5 per user per minute, 100 per site per day, 2 queued or running per site;
see `.env.example`). Claude Code enforces your subscription's own usage limits; Arkon does not know your remaining
allowance and does not track money.

## Checks

```powershell
composer check                 # Pint (format check) + PHPUnit on arkonlaravel_test (migrates it first)
npm run check                  # TypeScript typecheck + Vitest (editor state, PHP/TS conformance)
npm run build                  # admin/editor assets for Herd
npm run test:e2e               # Playwright write flows: PHP server on :8100 with arkonlaravel_e2e, opened at the
                               # insecure origin http://arkon-e2e.test:8100 (like Herd; mapped inside Chromium);
                               # AI: the helper runs a fake Claude Code CLI (e2e/fake-claude.mjs); MCP over stdio
$env:HERD_EMAIL="..."; $env:HERD_PASSWORD="..."; npm run test:herd   # smoke test through Herd: signs in,
                               # looks around, sends one request the server refuses; never saves, publishes or asks the AI
# Run the two Playwright suites one after the other, not in parallel (they share test-results/).
php artisan arkon:reproduce-publication <id>   # re-render a publication from its recorded inputs and compare
php artisan arkon:refresh-pages [--retry-failed]  # update live pages waiting for published tokens/components
php artisan arkon:media-variants                # make responsive WebP sizes for images uploaded earlier
npm run build; npm run perf                     # Lighthouse (mobile) ×3 on three fixture pages and their animated
                                                # variants in the e2e database: see docs/PERFORMANCE.md (needs
                                                # Playwright's Chromium; PERF_PAGES=/perf-hero,... for fewer)
```

`package.json` overrides `shell-quote` to 1.11.0 for `concurrently` only (10.0.5 pins 1.9.0, affected by
GHSA-pqg4-j6r4-53mv). `concurrently` is what `composer dev` / `php artisan dev` runs on Windows; remove the
override once a `concurrently` release depends on a fixed version.

First Playwright run only: `npx playwright install chromium`. The e2e setup migrates and empties `arkonlaravel_e2e`
(schema owner from `.migrate.env`), then seeds its own accounts. Tests never use the dev database.

## Database roles

| Role | Used for | Configured in |
|---|---|---|
| `arkonlaravel_owner` | `arkon:migrate` and test-database resets only | `.migrate.env` |
| `arkonlaravel_app` | the app and the tests. No DDL; read/insert-only on revisions, publications, publication media and the audit log | `.env` |
| `postgres` | `arkon:db-bootstrap` only | the shell, for that one command |

The app answers 503 if `MIGRATION_DB_*` or `PG_SUPERUSER_*` variables are in its environment or if its role can
change the schema (`php artisan arkon:check-runtime` shows why). `php artisan migrate` is refused in favour of
`php artisan arkon:migrate`.

## Layout

```text
app/Arkon/Schema        document shape, structure, operations + inverses, URL paths
app/Arkon/Components    versioned component registry (manifests), prop validation, renderers per version
app/Arkon/Style         the shared styling model: validation, CSS generation, design tokens
app/Arkon/Design        published tokens and reusable components, dependencies and live-page refreshes
app/Arkon/Renderer      escaped IR → HTML, production and editor modes (the only renderer)
app/Arkon/Pages         drafts, revisions, publishing, page management
app/Arkon/Media         uploads, delivery policy, signed URLs
app/Arkon/Ai            proposal schema/compiler/ledger, Claude Code CLI runner, helper, connections, MCP server
app/Arkon/Database      migration config, grants, runtime safety, test databases
themes/mysite           site theme source; open only this folder in VS Code
resources/arkon         shared rules + component manifests (read by PHP and TypeScript)
resources/js            Inertia pages, editor (resources/js/editor), editor-side rules and style twins (resources/js/arkon)
routes/web.php          admin, editor API, preview, sign-in      routes/public.php   public pages and media
tests/                  PHPUnit (Unit, Feature, Conformance)     e2e/, e2e-herd/     Playwright
```


### Developer theme workspace

Open only `C:\Herd\ArkonLaravel\themes\mysite` in VS Code to create editable components without opening core source. The example Testimonial declares its fields, managed photo and design parts. Run `.\arkon.cmd validate` from that folder, then open Appearance → Themes in the dashboard to preview and activate the local theme. Refresh from files installs validated updates. Restart your existing MCP/helper processes after installation to refresh their catalogue. Package installation never publishes a page.

See [docs/THEMES.md](docs/THEMES.md) for the contract, immutable versions, deployment requirements and current limits (per-site activation is available; inheritance and global theme skins are still planned). The theme source is in `themes/mysite` and is included in the same Git repository as Arkon. Its wrappers locate the containing application automatically; PHP defaults to Herd and can be overridden with `ARKON_PHP_BINARY`.


## Build a complete website

Open **Build a website** at `/admin/website`: one brief → multi-page review → apply drafts → explicit publish. Shared header/footer/navigation, global branding, native contact forms, SEO routes, readiness checks and local backup/restore commands are available. Both the subscription-based Claude Code helper and MCP can submit website proposals. Restart the helper/MCP after updating. See [the website workflow guide](docs/WEBSITE_WORKFLOW.md) for the walkthrough and limits.


### Website generation activity and validation failures

Website requests show an indeterminate activity bar, generation/validation/repair stage, elapsed time and the worker heartbeat. A heartbeat proves the local process is responsive; it is not model token progress or a completion estimate. Failed polling shows a connection warning instead of silently presenting old status as current. A second active website request by the same user is refused, including direct API requests, while exact key retries replay safely.

Automatic website validation repair is off by default. The brief form lets the user explicitly allow at most one repair run, which consumes additional Claude allowance. If allowed, that run receives the precise validation paths/messages and the previous structured output, rather than the generic user error. Interrupted website runs fail instead of silently starting another model run; page-request recovery keeps its existing behaviour.

New failed website requests retain validation issues and up to 2 MiB of structured candidate output in the private request record, fenced by the live worker lease. Candidate output is never returned by the status API or made public; it is for diagnosis. Existing failed requests cannot recover output that the old helper deleted. A successful proposal clears the rejected candidate. All output still passes the normal component, nesting, style, media and save validation; no validation is bypassed to hide failures. The helper receives schemas and context, with repository/file tools disabled. No new real-model request was used to verify this correction.

Restart the local helper after upgrading this code. Migration 2026_10_14_000003 adds activity/diagnostics columns without changing existing requests, drafts or publications. Admin activity UI adds no code to published pages.

### Navigation and shared website layout

Open **Admin → Navigation** to create menus, reorder items, add one level of dropdowns, and choose page links, custom URLs or section links. Page links store a page reference: they follow its published URL, never an unpublished rename. Unpublished targets resolve to # and show a warning. Menu drafts stay private until explicitly published; publishing refreshes dependent live pages under the site's publication ordering. Editors can save; owners and admins can publish. Published menu history is immutable and its resolved URLs are recorded in publication inputs.

The Navigation screen also links to the site's shared header and footer editors. Its **Prepare missing header and footer** action creates a local, reviewed website proposal, using no Claude request or subscription quota. It preserves existing nonempty shared layouts and page content, fills missing layout, and proposes section anchors for recognised Home/About/Services/Contact sections. Nothing changes until the proposal is applied; publishing remains separate.

The builder has a Navigation block with a menu selector. Sections have an **Anchor** field for destinations such as #services; anchors must be unique, start with a letter, and contain letters, digits or hyphens. Duplicating a section gives its copy a new anchor. Menus use native HTML disclosure controls for mobile and dropdowns, with keyboard support and no public JavaScript. The initial implementation supports one dropdown level and up to 50 menu items; it does not provide a fully custom mega-menu.

Website proposals include editable header, footer and navigation by default. Turn off **Include shared header, navigation and footer** for a standalone page. Existing layouts and menu definitions are preserved unless changes are proposed. Review includes the menu and shared resources before applying; website publication publishes their reviewed drafts together. Restart the local AI helper and MCP server after this upgrade: website generation requires helper protocol 3, so an older helper is rejected before a new run consumes quota.

New buttons default to #, including AI-created buttons with an omitted or empty destination. This is a visible placeholder, allowed to publish with a readiness warning; supply a real destination before launch. Existing stored blank or intentional URLs are not silently rewritten. Component versions and old publications remain immutable.

## Professional website layouts (9 October 2026)

The builder now includes linked managed logos, curated inline SVG icons/social links, editable sliders and a back-to-top link. In **Layers → Starting layouts**, insert an agency header, hero slider, image introduction, service cards, newsletter or footer. These are ordinary blocks: edit every field, move/remove/duplicate children, undo and save normally. Repeated insertions get fresh node IDs and section anchors. Select the appropriate published menu, images and form after inserting; replace demo copy and contact details before launch.

Use the inspector to choose a directional two-colour gradient, image crop/position, responsive sizing, inherited typography, hover/focus colours/shadows or a sticky header. Gradients accept only a bounded direction and two hexadecimal colours; sticky positioning and stacking order are bounded. There is no arbitrary CSS, remote font import or user script execution. The Inter font choice is served locally under its included SIL Open Font License, with a Latin subset and a full-character fallback. System fonts remain the default and require no font download.

A Slider contains 1–6 Slide blocks. Slides hold ordinary editable content, but cannot contain another slider or a reusable instance. In the editor all slides remain visible for editing; the public page shows one slide with arrows, dots, keyboard and touch navigation. Autoplay is off by default. If enabled, it pauses on hover, focus, a hidden tab and reduced motion. The tallest slide reserves height to avoid a layout jump; inactive slides are inert and their background images load when shown. Without JavaScript the first slide remains readable. The back-to-top link also works without JavaScript.

The conditional, integrity-checked components-1 runtime is approximately 4.9 KB uncompressed, independent of React/Inertia and allowed by an exact CSP path. Pages without its widgets download none of it. Renderer arkon-php-4 retains support for older renderer versions and records its inputs; older component files and published HTML are unchanged. New versions are page 6, section 5, group 5, column 6, fragment 4, button 5 and form 2. Logos/icons/sliders/slides/back-to-top start at version 1.

Renderer 4 uses managed responsive background variants and viewport-specific preloads for the first main-content background. Header logos do not consume the main image priority. Backgrounds still need suitable image dimensions and compressed assets; these mechanisms cannot guarantee field Core Web Vitals for arbitrary content. See docs/PERFORMANCE.md for measured local results.

**Newsletter:** create an email-only form using **Forms → + Newsletter form**, save and publish its definition, then select it in a Form block. The inline layout, submit label and notice are editable. Submissions use the existing validation, honeypot, rate limiting, encrypted entry storage and site permissions. This collects signups locally; it does not send newsletters or connect an email marketing provider. Do not tell visitors they are subscribed to an external list unless that integration exists.

Both Claude Code paths receive the new component/style catalogue and layout guidance. Form IDs are validated against the published forms in the supplied site context, independently of media IDs; unknown form references are rejected before review. The sandboxed helper receives explicit schema/context rather than unrestricted repository access. No real model request is used by the regression tests. Restart your existing helper and MCP server to refresh their cached catalogue; nothing is automatically published.

The reference demonstration is an isolated test fixture (/perf-reference), with generated placeholder imagery. It is not added to the development/live site. The starting layouts are available immediately in the real builder after refreshing the admin page.

### Slider editing and playback

Select the Slider block (or select its parent from a Slide) to configure automatic advancement, the interval, numbers/dots/bars/no pagination, previous/next arrows, control alignment and light/dark controls. Dots use an elongated active marker. The canvas shows one slide, following the same layout and pagination settings as preview; use its controls or select any slide/child in Layers to edit it. The active slide survives inspector redraws. Autoplay runs in preview/live only, pauses for hover/focus/hidden tabs/reduced motion, and has a pause/resume control. With visible navigation disabled, a keyboard-focusable next-slide control remains available. Old publications keep slider v1 unchanged.

Slider v3 offers 1–60 second intervals, labeled in seconds. Hover pause is optional (off for new sliders); keyboard focus, hidden tabs and reduced motion still pause public autoplay. Use Play slideshow on the canvas for temporary playback; editing/selection stops it. Pressing Resume autoplay on the public pause control resumes even while that control retains focus. Earlier slider versions keep their pinned runtime and upgrade with hover pause enabled.

Slider v4 adds Slide, Fade and Instant transitions, with transition durations from 200 to 1000 ms. New sliders use a 500 ms horizontal slide. Show autoplay pause button on website controls whether the public playback button is visible; it is hidden by default, remains available to screen readers, and appears on keyboard focus. Arrows and pagination remain visible according to their settings. The canvas Play slideshow test uses the chosen transition and stops motion before editing. Reduced-motion visitors get instant transitions and no automatic advancement. Existing drafts retain their previous presentation until these settings are changed; existing publications retain their original renderer and runtime.

Slider v5 adds independent Arrow placement: Grouped or Left/right edges. Edge arrows remain vertically centered while pagination can be hidden or aligned separately along the bottom. The same CSS works in the canvas, preview and publication, without adding JavaScript. Earlier drafts upgrade to Grouped, and older published output remains unchanged.

Slider v6 gives pagination independent Left/Center/Right alignment and Top/Bottom position. Canvas playback commands now live in the inspector and do not add a Play button to rendered content. Their version-checked preview path supports page and component editors, stops on editing/redraw/drag and respects reduced motion. Published versions remain immutable.

Slider v7 exposes arrow appearance (default/plain/outlined/filled), shape, button and icon size, gap, horizontal and vertical offsets, and grouped Top/Middle/Bottom positioning. Select the Arrow buttons part for custom background, colour, border and hover styles. Hit targets stay at least 44px; all placement and appearance use CSS, with the same output across canvas/preview/publication and no extra runtime. Old component versions stay immutable.

The slider inspector groups Slides, Playback, Transition, Arrows, Arrow appearance/colors, Pagination and Slider design. Irrelevant controls are hidden without clearing their saved values. Horizontal and Vertical position are shown together for grouped arrows. Custom arrow colours/background/borders and hover settings edit the existing validated arrows style slot directly; whole-slider styling always targets root.

Slider v8 supports validated tablet (899px and narrower) and mobile (599px and narrower) overrides for playback, transition, arrows, appearance and pagination. All screens / desktop is the base; tablet inherits base, mobile inherits tablet. Unset or inherit values follow the larger screen; Reset removes the override. Canvas and public output resolve the same settings on viewport changes; reduced motion, focus and hidden-tab autoplay protections remain. Prior component versions and components-3 runtime are unchanged and still reproduce old publications. The new components-4 runtime is loaded only by pages using slider v8. Refresh the editor and restart the AI helper/MCP to use the expanded catalogue; existing live pages change only after explicit publication.


Responsive block controls now use the selected screen consistently: All screens is the base, tablet (899px and narrower) inherits it, and mobile (599px and narrower) inherits tablet. Reset removes an override rather than writing a guessed default. Column proportions and custom widths follow this screen; the number of columns remains shared. Default mobile stacking is an explicit, removable setting. Button v6 supports screen-specific appearance and size, Section v6 supports content-width presets, and Form v3 supports stacked/inline layout (with the existing mobile stacking stored explicitly). Image dimensions, fit and crop use the existing screen-specific media style controls; the image asset, alt text, text, links and block structure remain shared. Override badges include responsive presets.

These visual changes render through CSS and add no public JavaScript. Standalone Image v5 estimates download slots from per-screen column proportions, nested columns and supported explicit image widths; estimates are conservative and do not model arbitrary custom layouts or every container width. Intrinsic dimensions, responsive sources and image loading priority remain. Old component files remain immutable, so saved publications reproduce with their original versions; editing upgrades drafts in memory. Refresh the editor and restart the AI helper/MCP for the expanded catalogue. No performance score is guaranteed by these controls.


Background images use the same thumbnail library/upload picker as image and Hero fields, in page and component editors. Upload progress measures transferred bytes; at 100% the control separately reports server validation/variant preparation. Failures appear beside the picker and allow a manual retry (unconfirmed network outcomes advise checking the library first). Uploads remain private until a live publication uses them. Completing an upload after switching blocks/parts or changing its target does not apply it to a stale selection; the asset remains in the site library. Background image selection still applies to all screens, as labelled; Reset/removal uses the existing style contract. No public renderer, schema or upload permissions change.


### Production audit and performance checks

See [docs/PRODUCTION_AUDIT.md](docs/PRODUCTION_AUDIT.md) for the production architecture review, fixes, measured fixtures and remaining release work. Renderer 5 adds basic social metadata on new publications; old publications remain unchanged until republished. Public images stream after the same access checks.

`npm run perf` benchmarks isolated published fixtures with production app settings and reports Lighthouse performance/accessibility/SEO plus document DOM metrics. It resets only the e2e database. Do not run it alongside Playwright, which uses that same database, or run two integration suites against the PHPUnit database at once. Keep `PERF_OUT` inside the project (for example `storage/perf-audit`). Results are lab evidence, not a hosting or real-user CWV guarantee.

For an existing offline Lighthouse installation, PERF_LIGHTHOUSE_CLI may point to its cli/index.js. The harness validates the package is exactly version 12.8.2 and invokes Node directly. This avoids a Windows npx shell failure without changing benchmark versions. The fixture cache store is array-backed; these runs do not validate persistent production caching.


### Admin navigation

The Dashboard provides a site overview, recent content and pending work. Manage Pages, Media and Forms under Content; Global styles, Reusable components, Navigation and Themes under Design. Build a website is the AI proposal workflow. Site management contains SEO metadata review, live-page update status and read-only site identity/domains. Missing product modules are not shown as disabled navigation items.

Use Search or Ctrl/Cmd+K to find a page or section. Pages offers New page, title/URL search, status filters and More actions. Existing admin URLs still work. See [docs/ADMIN_UX_REVIEW.md](docs/ADMIN_UX_REVIEW.md) for the review and limitations. Refresh your browser for the built UI; restart an already running AI helper to pick up the compact website-schema transport. No schema migration is needed for this admin milestone.


## Media library

Open `/admin/media` to upload and manage images. Click an image for its preview, editable title, default alt text/caption, editorial description, file information and permanent URL. Save details explicitly. Unsaved details must be saved or discarded before changing images or closing; an unconfirmed save must be retried to confirm its original result. Search runs on the database and includes names, filenames, alt text and captions, with 36 images per page and five sorting choices.

Library titles never rename files. Alt text/captions supply defaults when an image is selected in the builder; existing placements keep their saved context-specific descriptions and change only through editing and publishing. Hero v5, Image v6 and Logo v2 allow decorative `alt=""`. Older versions remain registered. Reload open editors to refresh their initial media defaults; restart a running AI helper/MCP process to discover new component versions.

Copy URL uses the permanent original asset address, with no signed preview token. Private assets still require authorized access until used by a live page. WebP sizes are chosen automatically; the details view reports actual recorded optimization information without claiming AVIF or background processing.

Owners/admins can **Remove from library** after confirmation. This archives discovery, retaining files and derivatives needed by pages and history; it does not destroy published images. Replace-under-the-same-ID, AI descriptions and physical-file cleanup require additional versioned/reviewable workflows and are not exposed as working buttons. See `docs/MEDIA_UX_REVIEW.md` for the implementation, reference semantics and validation.

### Media upload limits

The image limit is **5 MiB (5,242,880 bytes), inclusive**, defined once in `resources/arkon/media.json`. PHP and TypeScript read the same policy. The serving PHP process publishes its effective cap in the admin HTML; a lower runtime cap is shown before upload. Binary uploads use multipart FormData, never Base64.

For Herd, set `upload_max_filesize=8M` and `post_max_size=10M` in the serving PHP version's php.ini, then run `herd restart php`. This gives multipart overhead room without raising Arkon's file limit. Proxy request limits must also be at least 10 MiB; Arkon cannot discover every external proxy limit. Run `php artisan arkon:check-media` for CLI diagnostics, then verify the serving process (CLI and web PHP can differ).

Images are limited to 12,000 pixels per dimension and 40 million total pixels. Optimization also checks estimated decoded/encoder memory before decoding. An optimization failure keeps the uploaded original, logs the actual processing error, and reports a warning; it never becomes a size error. `php artisan arkon:media-variants` retries missing copies.

Serving PHP must use `display_errors=Off` and `log_errors=On`: PHP can emit a POST-size warning before Laravel runs. Printing that warning corrupts the API JSON envelope; logging retains diagnostics. The browser harness applies these flags explicitly.

Image details also lists **Optimized WebP links**: every generated size shows dimensions, bytes, its permanent URL, and Copy/Open controls. Original PNG/JPEG links remain available. Variant links share the original asset's private-until-published access rules; copying a link does not make a private image public. The builder continues to select responsive sizes automatically.
