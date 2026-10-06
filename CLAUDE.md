# Arkon (Laravel)

Read docs/ARCHITECTURE.md before changing behaviour.

- Use Herd's PHP 8.4 (`C:\Users\jacob\.config\herd\bin\php84\php.exe`); the other PHP on PATH lacks the required version.
- Schema changes: add a migration, then `php artisan arkon:migrate` (plain `migrate` is refused). Never edit an applied migration.
- Validation rules live in `resources/arkon/*.json` and are interpreted by PHP and TypeScript twins. After changing either side run
  `php tests/Conformance/build.php`, review `tests/Conformance/fixtures.json`, then `composer test` and `npm test`.
- Components are versioned and immutable: add `vN+1.json`, a renderer and a migration instead of editing a released version.
- Never put `.migrate.env` values or superuser credentials into `.env` or the app's environment.
