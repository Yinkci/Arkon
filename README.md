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
- **Pages → Home → Edit.** Click the heading or text in the canvas and type, or use **Properties**. Ctrl+S saves,
  Ctrl+Z / Ctrl+Shift+Z undo and redo outside text fields.
- **Upload image** in Properties, then add alternative text (publishing is blocked without it). Uploads stay private
  (404 to visitors) until a published page uses them; the canvas shows them through short-lived signed URLs.
- **Save draft** never changes the live page. **Preview** shows the saved draft exactly as it would be published.
  **Publish** makes it live. **History** lists every revision; **Restore** copies one into the draft.
- **Pages → New page** creates an unpublished draft. Change title and URL in the editor under
  **Properties → ← Page settings**; the live page keeps its old URL until you publish, then the old URL redirects (301).
- **Unpublish** and **Delete** (owners and admins) are on the Pages list and ask for confirmation. History is kept.
- Network trouble while saving or publishing keeps your changes; saving or publishing again retries the same
  request safely and never applies it twice.

## Checks

```powershell
composer check                 # Pint (format check) + PHPUnit on arkonlaravel_test (migrates it first)
npm run check                  # TypeScript typecheck + Vitest (editor state, PHP/TS conformance)
npm run build                  # admin/editor assets for Herd
npm run test:e2e               # Playwright write flows: PHP server on :8100 with arkonlaravel_e2e, opened at the
                               # insecure origin http://arkon-e2e.test:8100 (like Herd; mapped inside Chromium)
$env:HERD_EMAIL="..."; $env:HERD_PASSWORD="..."; npm run test:herd   # smoke test through Herd: signs in,
                               # looks around, sends one request the server refuses; never saves or publishes
php artisan arkon:reproduce-publication <id>   # re-render a publication from its recorded inputs and compare
```

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
app/Arkon/Renderer      escaped IR → HTML, production and editor modes (the only renderer)
app/Arkon/Pages         drafts, revisions, publishing, page management
app/Arkon/Media         uploads, delivery policy, signed URLs
app/Arkon/Database      migration config, grants, runtime safety, test databases
resources/arkon         shared rules + component manifests (read by PHP and TypeScript)
resources/js            Inertia pages, editor (resources/js/editor), editor-side rules (resources/js/arkon)
routes/web.php          admin, editor API, preview, sign-in      routes/public.php   public pages and media
tests/                  PHPUnit (Unit, Feature, Conformance)     e2e/, e2e-herd/     Playwright
```
