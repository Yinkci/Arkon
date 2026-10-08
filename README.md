# Arkon (Laravel)

A CMS where pages are a structured document, edited visually in a sandboxed canvas and published as
clean, script-free HTML. This is the Laravel port of the Next.js reference project (`C:\Herd\Arkon`).

- Backend: Laravel 13, PostgreSQL. Admin and editor: React + TypeScript through Inertia.
- Served by Laravel Herd at **http://arkonlaravel.test**. No Node server at runtime.
- Architecture, decisions and status: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Migration plan: [docs/MIGRATION_PLAN.md](docs/MIGRATION_PLAN.md).

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
- **Upload** in the image's controls, then add alternative text (publishing is blocked without it). Uploads stay private
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
