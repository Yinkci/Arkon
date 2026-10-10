# Development

Setup is in the [README](../README.md). Before changing behaviour read [ARCHITECTURE.md](ARCHITECTURE.md); admin UI
follows [ADMIN_DESIGN_SYSTEM.md](ADMIN_DESIGN_SYSTEM.md).

## Checks

```powershell
composer check                 # Pint (format check) + PHPUnit on arkonlaravel_test
npm run check                  # TypeScript typecheck + Vitest (editor state, PHP/TS conformance)
npm run build                  # admin and editor assets
npm run test:e2e               # Playwright: PHP server on :8100 with arkonlaravel_e2e, fake Claude Code CLI
$env:HERD_EMAIL="..."; $env:HERD_PASSWORD="..."; npm run test:herd   # read-only smoke test through Herd
```

- First Playwright run only: `npx playwright install chromium`. The e2e setup migrates and empties
  `arkonlaravel_e2e`; tests never use the development database.
- Run the integration suites one at a time: PHPUnit, Playwright and `npm run perf` each reset a shared test
  database.
- Validation rules live in `resources/arkon/*.json` and are interpreted by PHP and TypeScript twins. After changing
  either side run `php tests/Conformance/build.php`, review `tests/Conformance/fixtures.json`, then both suites.
- Components are versioned and immutable: add `vN+1.json`, a renderer and a migration instead of editing a released
  version.
- `npm run perf` runs Lighthouse on isolated fixture pages in the e2e database; see [PERFORMANCE.md](PERFORMANCE.md).

## Useful commands

```powershell
php artisan arkon:member-create --email=... --name=... --role=editor   # roles: owner, admin, editor, viewer
php artisan arkon:check-runtime        # why the app would refuse to serve (privileged settings or role)
php artisan arkon:check-media          # does this PHP process allow the 5 MiB upload limit
php artisan arkon:media-variants       # make missing responsive WebP sizes
php artisan arkon:refresh-pages        # update live pages waiting for published tokens/components
php artisan arkon:reproduce-publication <id>   # re-render a publication and compare byte for byte
php artisan arkon:backup               # PostgreSQL, media and theme snapshots, with checksums
```

## Database roles

| Role | Used for | Configured in |
|---|---|---|
| `arkonlaravel_owner` | `arkon:migrate` and test-database resets only | `.migrate.env` |
| `arkonlaravel_app` | the app and the tests; no DDL, insert-only history tables | `.env` |
| `postgres` | `arkon:db-bootstrap` only | the shell, for that one command |

The app answers 503 if `MIGRATION_DB_*` or `PG_SUPERUSER_*` variables are in its environment or its role can change
the schema. Schema changes go through a new migration and `php artisan arkon:migrate`; plain `migrate` is refused.

## Upload limits on Herd

Arkon's image limit is 5 MiB (`resources/arkon/media.json`). PHP must accept slightly more for multipart overhead:
set `upload_max_filesize=8M` and `post_max_size=10M` in the serving PHP's php.ini, then `herd restart php`. Keep
`display_errors=Off` and `log_errors=On` so PHP warnings never corrupt API responses.

## Project layout

```text
app/Arkon/Schema        document shape, operations and inverses, URL paths
app/Arkon/Components    versioned component registry, prop validation, renderers per version
app/Arkon/Style         styling model: validation, CSS generation, design tokens
app/Arkon/Design        published tokens and reusable components, live-page refreshes
app/Arkon/Renderer      escaped IR → HTML (the only renderer)
app/Arkon/Pages         drafts, revisions, publishing, page management
app/Arkon/Media         uploads, delivery policy, signed URLs, WebP variants
app/Arkon/Forms         form definitions, entries, notifications
app/Arkon/Ai            proposal schema and compiler, Claude Code runner, helper, MCP server
app/Arkon/Database      migration config, grants, runtime safety
resources/arkon         shared rules and component manifests (read by PHP and TypeScript)
resources/js            Inertia pages, editor (resources/js/editor), TypeScript rule twins (resources/js/arkon)
themes/mysite           developer theme source (see THEMES.md)
routes/web.php          admin, editor API, preview, sign-in     routes/public.php   public pages and media
tests/                  PHPUnit (Unit, Feature, Conformance)    e2e/                Playwright (e2e/herd: Herd smoke test)
```

## Local storage

Everything under `storage/` except committed `.gitignore` files is local: uploads (`storage/app/media`), backups
(`storage/app/private/backups`), logs, review screenshots (`storage/screenshots`), benchmark reports
(`storage/perf*`), Playwright output and the signed-in test session (`storage/playwright`), the PHPUnit cache
(`storage/framework/testing/phpunit`) and test scratch space (`storage/testing`). Screenshots, benchmark reports and test scratch space
can be deleted at any time and are recreated by the commands that write them. Inertia DevTools recording is off; set
`INERTIA_DEVTOOLS_ENABLED=true` in `.env` to record requests to `storage/inertia-devtools`.

## Dependencies

`package.json` overrides `shell-quote` to 1.11.0 for `concurrently` (10.0.5 pins 1.9.0, affected by
GHSA-pqg4-j6r4-53mv). Remove the override once a `concurrently` release depends on a fixed version.
