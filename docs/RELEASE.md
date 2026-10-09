# Release package

A release contains what is needed to install and run Arkon: the application, production Composer dependencies and
the built admin assets. Tests, browser tests, scripts and tool configs are marked `export-ignore` in
`.gitattributes`, so `git archive` leaves them out. `node_modules` is never shipped: Node is needed only to build the
assets.

```powershell
npm ci; npm run build                                   # public/build (not committed)
git archive --format=tar --output=arkon.tar HEAD        # source without development files
mkdir release; tar -xf arkon.tar -C release
Copy-Item -Recurse public\build release\public\build
cd release; composer install --no-dev --optimize-autoloader
```

Measured on 10 October 2026: about 31 MB unpacked (27 MB of it production Composer packages, 0.9 MB built assets)
and 9 MB as a zip. The package boots (`php artisan list`) without development packages.

Installing a release follows the README from step 2 (credentials, database bootstrap, `arkon:migrate`, an owner
account). Use HTTPS in production and set `SESSION_SECURE_COOKIE=true`, `APP_ENV=production` and `APP_DEBUG=false`.
