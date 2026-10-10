# Arkon

A content management system and visual website builder for fast, clean websites. Pages are structured documents
edited in a visual builder and published as plain HTML, with optional AI help through Claude Code.

## What is Arkon?

Arkon combines page building, content management, media, forms, SEO and navigation in one Laravel application.
Editors work on drafts in a sandboxed visual canvas; nothing changes on the public site until they publish.
Published pages are stored as finished HTML and served without a JavaScript framework, sessions or cookies, so
visitors get small, fast pages.

AI assistance is optional. It runs through Claude Code on your own Claude subscription, and its output is always a
proposal you review: AI never publishes.

## Features

- **Visual page builder**: sections, columns, groups, heroes, text, images, buttons, sliders, forms and navigation,
  with drag and drop, undo/redo, per-screen design settings and entrance animations.
- **Drafts and publishing**: draft, preview and publish steps; full revision history with restore; redirects
  when a page URL changes.
- **Design system**: site-wide design tokens and reusable components, published once and applied to every page.
- **Media library**: image uploads checked by content, automatic responsive WebP sizes, alt text and captions, and
  images that stay private until a published page uses them.
- **Forms**: a form builder with validation, spam protection, rate limiting, encrypted entries, email notifications
  and CSV export.
- **SEO**: per-page metadata with an explainable score, social metadata, canonical URLs, sitemap and robots.txt.
- **Navigation**: menus with dropdowns and a shared header and footer.
- **AI (optional)**: page and whole-website proposals from a brief, and SEO suggestions, all reviewed before use.
- **Users and roles**: owner, admin, editor and viewer.
- **Developer themes**: custom components in a separate theme folder.

## Requirements

- PHP 8.3+ with `pdo_pgsql`, `dom` and GD with WebP support (Laravel Herd's PHP 8.4 has these)
- Composer 2
- PostgreSQL 16+, with superuser access once to create Arkon's roles and databases
- Node.js 22+ and npm, to build the admin assets and run browser tests
- Optional: [Claude Code](https://claude.com/claude-code) signed in with a Claude subscription, for AI features

Development uses [Laravel Herd](https://herd.laravel.com) on Windows, which serves the project at
`http://arkonlaravel.test`. The commands below are PowerShell.

## Setup

1. **Install dependencies and create the config files**

   ```powershell
   composer setup
   ```

   This installs Composer and npm packages, creates `.env` and `.migrate.env` from their examples, generates
   `APP_KEY` and builds the admin assets.

2. **Set database passwords.** Put strong random values in `DB_PASSWORD` (`.env`, the app's restricted role) and
   `MIGRATION_DB_PASSWORD` (`.migrate.env`, the schema owner, read only by migration tooling and tests). Never put
   `.migrate.env` values or superuser credentials into `.env`.

3. **Create the roles and databases** (once per machine). The superuser password is used for this one command and
   never stored:

   ```powershell
   $s = Read-Host "postgres superuser password" -AsSecureString
   $env:PG_SUPERUSER_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))
   php artisan arkon:db-bootstrap
   Remove-Item Env:PG_SUPERUSER_PASSWORD
   ```

4. **Create the schema and the site**

   ```powershell
   php artisan arkon:migrate      # migrations and grants, as the schema owner (plain `migrate` is refused)
   php artisan arkon:seed         # a site for the hosts in ARKON_SEED_HOSTS, with an unpublished home page
   ```

   `ARKON_SEED_HOSTS` and `APP_URL` in `.env` must match the address you open (default `arkonlaravel.test`).

5. **Create your account.** There is no public sign-up; the generated password is printed once:

   ```powershell
   php artisan arkon:owner-create --email=you@example.com --name="Your Name"
   ```

6. **Sign in** at `http://arkonlaravel.test/admin`. The public site is at `http://arkonlaravel.test/` (404 until the
   home page is published).

To use AI features, follow [docs/AI_SETUP.md](docs/AI_SETUP.md).

## Development

```powershell
npm run dev        # Vite with hot reload for the admin and builder
npm run build      # production assets
composer check     # PHP format check and PHPUnit
npm run check      # TypeScript typecheck and Vitest
npm run test:e2e   # Playwright browser tests (separate e2e database)
```

More in [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md): test databases, useful commands, project layout and local
storage.

## Project structure

Arkon follows Laravel's standard layout. The folders you will work in most:

```text
app/Arkon/   Arkon's core: pages, components, renderer, media, forms, SEO, AI
resources/   admin and builder (React/TypeScript), component manifests and shared rules
routes/      admin and editor routes (web.php), public site and media (public.php)
themes/      developer theme source (custom components)
tests/       PHPUnit tests            e2e/   Playwright browser tests
docs/        user guide, architecture and development notes
```

`vendor/`, `node_modules/`, `public/build/` and `storage/` (uploads, logs, test output) are installed or generated
locally and are not committed.

## Documentation

- [User guide](docs/USER_GUIDE.md): using the admin and the builder
- [AI setup](docs/AI_SETUP.md): Claude Code helper and MCP server
- [Architecture](docs/ARCHITECTURE.md): design, data model and security
- [Website workflow](docs/WEBSITE_WORKFLOW.md), [Themes](docs/THEMES.md), [Performance](docs/PERFORMANCE.md)
- [Release package](docs/RELEASE.md)

## License

MIT, as declared in `composer.json`.
