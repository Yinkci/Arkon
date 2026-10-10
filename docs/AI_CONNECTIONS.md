# Arkon AI Connections

Arkon now has a shared local-provider architecture for Claude Code and Codex. The CMS remains responsible for authorization, schemas, proposal review, persistence and publishing.

## Existing integration audit

The existing flow was: editor or website brief → durable `ai_proposals` record → local Artisan helper → Claude CLI → structured reply → Arkon compiler → review → explicit application and publication. MCP is the second entry point into the same proposal/action services.

The helper uses revocable Arkon tokens, per-site permissions and database leases. Cancellation, expiry, revocation and loss of edit rights fence late results. The Claude CLI uses native subscription sign-in, isolated temporary working directories, no tools or MCP, stdin for prompts, an environment allow-list, timeouts and process termination. Output was buffered JSON, not a live token stream. Readiness is reported by the local helper; the web server should not claim to detect software installed on another computer.

The hard-coded dependencies were the runner contract, process transport, connection pairing and token files, helper status, queued request provider, MCP attribution and user-facing copy. These were refactored rather than duplicated.

## New architecture

- `AiRunner` is the common check/run contract; `AiProvider` adds provider identity. `ClaudeRunner` remains a compatibility contract for existing fixtures and integrations.
- `ProviderRegistry` declares provider identity, instructions and capabilities, resolves adapters and centralizes token-file selection. Only Claude Code and Codex are implemented.
- `CliProcess` shares direct argument-array execution, stdin, allow-listed environment, output handling, an output size limit, cleanup, timeout and cancellation. Application and API credentials are excluded from children.
- `ClaudeCodeCli` preserves the existing restricted Claude invocation. `CodexCli` verifies the actual executable and ChatGPT login, checks for the flags required for isolated execution, and uses an output schema and ephemeral read-only execution. User config, rules, shell tools, apps and web search are disabled for CMS generation.
- `AiCompletion` carries structured data and optional provider-reported usage. Provider events use a common provider/type shape. Codex JSONL is translated into safe progress events; raw provider traces are not shown in the admin. The UI continues showing durable request stages and heartbeat information rather than a chat transcript. Claude keeps its buffered JSON behavior.
- Each request stores its provider before dispatch. Claims, renewals and result writes remain fenced to that provider and site. No automatic fallback is implemented.
- Selection is based on the current user’s ready helpers on the current site, filtered for the workflow. One is automatic, several require an explicit task choice, and none are unavailable. Exact retries return the accepted request before current availability is checked. Requests never change provider. Interrupted executions fail without automatic model restart.
- Both providers can be paired simultaneously. Pairing one provider revokes only that user's previous helper for the same provider and site. Provider-specific helper tokens are git-ignored; the database retains only token hashes. Provider login credentials remain with the native CLI.

## Settings and setup

Open **Settings → AI Connections** at `/admin/settings/ai-connections`.

Each compact provider row has a status dot and a state-aware primary action. Connect opens a guided Install → Sign in → Pair → Verify drawer; Manage opens connection health, response testing and scoped disconnect. Commands have copy actions (including an insecure-origin fallback) and stay inside setup or collapsed diagnostics. The drawer supports keyboard focus wrapping, Escape and return to its trigger. Only a fresh, ready helper report can show Connected. Installation and authentication checks run on that helper's computer. Check again is free of model requests. Test response queues one explicit small model request and uses provider allowance. Disconnect revokes the Arkon association, stops its leased work and leaves the CLI login installed and signed in.

For Codex, in the Arkon project folder:

```powershell
codex login
php artisan arkon:ai-pair YOUR_ARKON_EMAIL --helper --provider=codex
php artisan arkon:ai-helper --provider=codex
```

Keep the helper running. With only Codex ready it is used automatically. If both helpers are ready, each new task requires a provider choice. Restart an older helper to load the new provider-aware code.

MCP pairing also accepts `--provider=codex`; it prints the native `codex mcp add` registration command. Neither native CLI is installed or logged out automatically.

Advanced executable paths are local operator configuration (`ARKON_CODEX_COMMAND`, existing `ARKON_CLAUDE_COMMAND`), not a web endpoint accepting command strings. Codex shell wrappers are resolved to native package binaries rather than executed through a shell. Tests alone may configure a command array for a fake executable. `ARKON_CODEX_HELPER_TOKEN_FILE` can separate testing or alternative local helper setups.

## Boundaries

CMS generation is structured proposal generation, not unrestricted repository editing. Its working directory is intentionally isolated; the selected site's document, registered components, media and other permitted context are supplied explicitly. Repository-assisted workflows use the separately paired native coding assistant/MCP tools. Selecting a different provider grants no additional CMS permission.

There is no dependency on Herd for this architecture. Windows native execution is verified here; macOS/Linux discovery paths are implemented but have not been exercised on those operating systems in this session. Native CLI changes may require future adapter updates. Codex must support the isolation flags checked by the adapter; it fails visibly rather than falling back to an unsafe invocation.

The existing Claude error codes remain compatible; Codex failures distinguish installation, login/billing mode, usage limit, timeout, cancellation and execution failure. Logs contain provider, request, outcome and duration, not credentials or raw Codex diagnostics. Optional usage reports do not invent money amounts or remaining subscription allowance.

Official references: [Codex non-interactive execution](https://developers.openai.com/codex/noninteractive), [CLI commands and native login](https://developers.openai.com/codex/cli/reference), [configuration controls](https://developers.openai.com/codex/config-reference).

## Verification in this session

The full PHP suite passed: 478 tests and 5,634 assertions. The subsequently added usage/event test and final transport changes passed targeted checks of both native adapters (14 tests, 89 assertions) and provider/connection checks (14 tests, 58 assertions). Frontend type checking, all 384 Vitest tests and the production build passed. Runtime checks confirm the application database role cannot change the schema and no privileged credentials reach runtime.

A real Codex 0.160.0 installation reported ChatGPT authentication and successfully returned the requested structured response through Arkon's adapter. Browser checks use fake providers and isolated databases, so they do not spend subscription allowance.

The separate real Codex repository-edit fixture was attempted, but its apply_patch operation was blocked by the native CLI's read-only sandbox. The fixture remained unchanged; repository modification is therefore not verified. This does not affect the deliberately read-only CMS proposal path. No sandbox bypass was attempted. macOS and Linux execution remain unverified here.

Changes remain local and uncommitted. Existing unrelated content/API work was preserved.
Browser verification also passed: existing editor and whole-website AI flows, desktop/mobile connection settings, and switching Codex → Claude Code with reviewed proposals applied as drafts through each provider. Public pages remained unpublished.

Website, page/post editor, SEO text and image-alt actions use the shared connection policy. AI and SEO share one task choice, cleared after successful submission. Status is refreshed before a new request; automatic submissions carry the displayed provider as a checked hint. Uncertain submissions keep the same provider, payload and request key and can be confirmed even while offline. MCP remains authenticated-connection-based and requires no browser helper.

Saved ai_preferences rows are deprecated and retained only for compatibility/rollback; no runtime service reads or writes them. The default endpoint and UI were removed, and applied migrations are unchanged. Browser helpers are user-owned, including claim and result fencing; another site member’s helper cannot spend their allowance for your request. Native login is rechecked before execution without a model call.
