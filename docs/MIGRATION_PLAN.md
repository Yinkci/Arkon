# Arkon → Laravel migration plan

Reference: `C:\Herd\Arkon` (Next.js), commit `35c3dd5` plus the uncommitted page-management work
(create, draft title/URL, redirects, unpublish, delete). The reference project is read-only.

## What moves where

| Reference (Next.js / TypeScript) | Laravel project |
|---|---|
| `packages/schema` (document, operations + inverses, paths) | PHP `app/Arkon/Schema` (server) **and** TS `resources/js/arkon/schema` (editor). Rules shared through `resources/arkon/*.json`, behaviour pinned by shared conformance fixtures |
| `packages/components` (Zod prop schemas, render, CSS) | Per-version manifests `resources/arkon/components/<type>/v<N>.json` (props, defaults, inline fields, media refs, publish checks) + `v<N>.css`. Render functions in PHP only |
| `packages/renderer` (IR, escaping, editor/production modes) | PHP `app/Arkon/Renderer`. **One renderer**: publish, preview and the editor canvas all use it (the canvas asks the server for editor-mode HTML) |
| `packages/core` services | `app/Arkon/Pages`, `app/Arkon/Media`, `app/Arkon/Sites` service classes; authorization through Laravel Gates backed by the role → permission map; domain exceptions mapped to JSON errors |
| `packages/db` (Drizzle schema, SQL migrations, role bootstrap, grants) | Laravel migrations (staged like the reference history so the upgrade path is tested), `arkon:db-bootstrap`, `arkon:migrate` (owner credentials, grants, data upgrades) |
| Better Auth | Laravel session guard, database sessions, login rate limiter, no registration routes, `arkon:owner-create` CLI |
| Server Actions | JSON endpoints under `/admin/api/...` (CSRF-protected, session-authenticated). Inertia for page navigation and initial props |
| `proxy.ts` + `/site` route handler | Public catch-all route outside the `web` middleware group (no session, no cookies, no Inertia/Vite) serving stored publication HTML |
| React editor (`apps/web/src/editor`) | React + TypeScript under Inertia (`resources/js`), same state model (`state.ts` ported unchanged in behaviour) |
| Vitest integration tests (Postgres) | PHPUnit feature tests against `arkonlaravel_test` (truncated by the owner role, app role for the code under test). Concurrency through real parallel PHP processes |
| Playwright e2e | Playwright against a PHP server bound to `arkonlaravel_e2e`, plus a smoke run through Herd |

## Key decisions

1. **Single renderer in PHP.** The reference rendered the canvas in the browser with the TS renderer. Porting the
   renderer twice would create two implementations of every component that must stay byte-identical. Instead the
   canvas posts its local document to `/admin/api/pages/{id}/canvas` and receives editor-mode HTML from the same
   PHP renderer that publishes. Responses are sequenced (latest wins); inline typing is not echoed back, so caret
   and focus are unaffected.
2. **Validation in both languages, rules in one place.** The editor must validate locally (undo/redo, immediate
   feedback, inverse operations); the server must validate authoritatively. Both interpret the same JSON files
   (document limits, path rules, reserved segments, component prop schemas, publish checks) with small
   interpreters, and both run the same fixture corpus (`tests/conformance/*.json`) in PHPUnit and Vitest.
3. **Historical component versions.** Manifests are immutable per version (`hero/v1.json`); a new version adds a
   file plus a migration. Nodes keep their version, so old revisions remain readable and restorable.
4. **Publication inputs.** Each publication stores its render inputs (site name/lang, media metadata, component
   versions, renderer version) next to the immutable revision it rendered, so it can be audited and reproduced.
5. **Exact JSON object/array fidelity.** PHP decodes `{}` and `[]` identically by default; documents are decoded
   with empty objects preserved and encoded schema-aware so `props: {}` / `seo: {}` never become arrays.
6. **Separate databases and roles.** `arkonlaravel` (dev), `arkonlaravel_test`, `arkonlaravel_e2e`; roles
   `arkonlaravel_owner` (DDL, migrations only, credentials in `.migrate.env`) and `arkonlaravel_app` (runtime,
   append-only history). The existing `arkon*` databases and roles are not touched.

## Order of work

1. Project setup: Inertia + React + TS + Tailwind, PostgreSQL config, `.gitignore`, git init.
2. DB bootstrap/migrate commands, staged migrations, grants, runtime safety checks.
3. Shared rules + PHP schema/operations/validation/renderer + TS counterparts + conformance fixtures.
4. Services: sites, permissions, pages (save/restore/publish/re-render), page management, media, audit, setup CLI.
5. HTTP: login/logout, admin pages, JSON API, preview, media, public route.
6. Editor UI port.
7. Tests: unit, conformance, feature (races, uncertain outcomes, concurrency, isolation, media, URLs, redirects,
   upgrades), Vitest, Playwright e2e.
8. Herd verification, README + architecture notes.

Deferred (as requested): collections, AI, dependency workers, design-token editing, editable site settings.


## Website workflow milestone

Additive migrations 2026_10_14_000001 and 000002 add native forms, encrypted enquiries, reviewed website applications/settings and expiring MCP contexts. New versioned container manifests allow form blocks, and arkon-php-3 records canonical origins. Migrate through arkon:migrate after a verified private backup; previous migrations/manifests remain untouched. Current verification and limits: [WEBSITE_WORKFLOW.md](WEBSITE_WORKFLOW.md).
