# AI setup (Claude Code, your subscription)

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

## One-time setup (Windows, PowerShell, in `C:\Herd\ArkonLaravel`)

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

## Prompt in VS Code

Ask Claude Code, for example: *Use Arkon to build a homepage for a landscaping business, with a hero, services, about
section and contact button, on the Home page.* It reads the page and the component catalogue through Arkon's tools,
submits a proposal, and tells you it is waiting for review. Arkon validates it; the draft is not changed. Then open
**http://arkonlaravel.test/admin** → **Pages** → the page → **Edit** → **AI** tab → **Waiting for your review** →
**Review** → **Apply to draft** (or **Discard**). The MCP tools can list pages, read a page and the proposal format,
submit a proposal and read its status; they cannot apply, publish, run commands or read files.

## Prompt in the editor (the helper must be running)

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

## Image generation (Codex)

Claude Code cannot generate images. The Codex CLI (signed in with your ChatGPT account) has a built-in image
generation feature, and Arkon can use it through the Codex helper (`php artisan arkon:ai-helper --provider=codex`).
It is **off by default**, for one reason: `codex exec --json` does not report where an image is saved. Arkon reads the
file Codex itself writes to `%USERPROFILE%\.codex\generated_images\<thread id>\` (only the folder of its own run,
only files written during that run). That location is not a documented interface and has changed in some Codex
releases, so it must be verified on your computer with one real generation before it is relied on.

What stays the same with images on: the run is still `codex exec` with `--sandbox read-only`, no user config or rules,
no session files, and shell, patching, apps, skills and web search off. Only `features.image_generation` is turned
on. The model gets no file access. No API key or separate billing is used; image generation counts against your
ChatGPT plan's Codex limits.

To enable it, after approving one test generation:

1. Add `ARKON_CODEX_IMAGE_GENERATION=true` to `.env`.
2. Restart the Codex helper (Ctrl+C, then `php artisan arkon:ai-helper --provider=codex`).
3. **AI Connections** shows *Image generation: Available* for Codex. If Codex finishes without saving an image file,
   the image fails with "no generated image file was found" and nothing else happens; turn the setting off again.

## Timing, limits and diagnostics

Each AI call of a website request is one short step with its own limit (`config/arkon.php` → `ai.stage_timeouts`,
`ai.stage_retries`, `ai.page_section_chunk`, `ai.website_ceiling_seconds`). Every call writes an `AI provider run`
entry to `storage/logs/laravel.log`: step, outcome, seconds, time to first output, longest silence, token usage and a
timeline of provider events (no prompt text). Set `ARKON_AI_PROVIDER_DEBUG=1` in the helper's environment to let
Codex log its network activity to that entry as well; leave it off normally.

Codex is run with bounded connection retries (`features.unbounded_connection_retries=false`) and asked for compact
JSON. `codex exec --json` prints nothing while the model writes its reply, so Arkon also runs Codex with a trace of
its model stream (`RUST_LOG=tungstenite::protocol=trace`): Arkon only counts and times those frames, to tell a reply
being written from a stopped one (`ai.stream_idle_seconds`) and to stop a runaway reply (`ai.runaway_whitespace`).
The frames contain the prompt and the reply; they live in a temporary file deleted after the run and are never
logged — the `AI provider run` entry records only counts and timings under `stream`.

## Claude Code on Windows: the command-line limit

Claude Code receives the reply format on its command line, and Windows allows 32,767 characters with every double
quote escaped (about one extra character per quote). Arkon measures that exactly. When the full format does not fit,
Claude gets a looser guide for the largest block types (their properties become a plain object; the instructions still
list every property) and Arkon validates every reply exactly as before. Codex reads the format from a file and always
gets the full format.

## Troubleshooting

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
| "This helper is running older Arkon code. Restart it" | Stop the helper (Ctrl+C) and start it again; revisions and image jobs need the current code. |
| "Image generation through Codex is turned off in Arkon" | See [Image generation (Codex)](#image-generation-codex). |
| "No connection can generate images" | Start the Codex helper with image generation enabled, or continue without generated images. |
| VS Code: `arkon` tools missing / "not paired or was revoked" | Pair again with `--mcp` and re-run the printed `claude mcp add` (after `claude mcp remove arkon`). |

Arkon limits how often requests start (5 per user per minute, 100 per site per day, 2 queued or running per site;
see `.env.example`). Claude Code enforces your subscription's own usage limits; Arkon does not know your remaining
allowance and does not track money.
