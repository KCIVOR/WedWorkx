# cPanel package

Download `wedworks-cpanel.zip` using GitHub's **Download raw file** button. This package contains the application, matching compiled `public/build` assets, and 77 production Composer dependencies. No npm or Composer commands are needed on cPanel.

## Existing installation: update without Terminal

1. Back up the current application files and database. Record the current release so it can be restored. Preserve the server `.env` and all existing `storage` contents, including uploads and sessions; never upload a local database or credentials.
2. In cPanel File Manager, enable **Show Hidden Files**. Upload and extract the ZIP into a temporary directory outside `public_html`. Confirm `public/.htaccess`, `vendor/autoload.php`, and `public/build/manifest.json` exist. Do not expose the archive or application root publicly.
3. During a maintenance window, release the application files and production `vendor` together. Preserve the server `.env` and existing `storage`; the archive's storage files are empty-directory placeholders only. Retain a backup and old hashed public assets while existing browser sessions reload.
4. Release the new controller, routes, React source, navigation, manifest, and new asset files together. The manifest must reference `assets/app-D_szopW1.js` and `assets/app-4_74B2nk.css`, both present under `public/build`. Remove `public/hot` if present; it points browsers at a development server. Preserve the existing public storage link.
5. Keep the domain document root at `/home/YOURUSER/wedworks/public` (substitute the actual app path). Confirm writable Laravel storage/cache directories. Never upload Windows-generated config, route, or compiled-view caches. Package discovery files `bootstrap/cache/packages.php` and `services.php` are included.
6. If the server has stale route/config/view caches, have hosting support clear them or use a controlled **one-time** cPanel Cron Job running the host's verified PHP binary and `artisan optimize:clear` in the actual application directory. Send output to a private log outside the document root, inspect success, then remove the cron job and temporary log. Use the PHP binary selected for this domain. Do not add a public cache-clearing URL. Preserve the existing scheduler cron.
7. Sign out and confirm Payment History redirects to login; sign in and verify the page, filters, pagination, and supplier link against existing hosted rows. Compare read-only payment counts/statuses before and after. No migration, seed, backfill, test database, or live payment is required for this feature.

The package excludes `.env`, local databases, uploads, logs, sessions, development dependencies, tests, docs, tools, nested deployment archives, and machine-specific Laravel caches. Hidden env examples and `public/.htaccess`, production package discovery, and empty storage directory placeholders are included.

## Earlier payment-history package verification — 2026-11-08 (superseded)

- ZIP: **8,055,594 bytes**, **6,327 files** (**7,243 entries**, including directories).
- SHA-256: `eb6baffb7076d6b577beb324ff2bb4abb789019f2f57f683a4a7c7e46098a6f8`.
- ZIP permissions verified: Unix metadata (create_system 3), regular files 0644 and directories 0755; directory DOS flags preserved. Content and timestamps unchanged by metadata normalization.
- ZIP CRC/integrity, forward-slash paths, required new source, manifest/assets, hidden files, placeholders, and exclusions verified locally. The ZIP contains only the new build hashes; existing hosted old hashes may be retained during rollout.
- Fresh staging install: `composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist` passed (77 packages); package discovery and fresh `php artisan route:list --name=admin.payments --no-ansi` passed.
- `composer check-platform-reqs --no-dev` passed on Windows PHP **8.3.29**. Root and production locked dependencies require PHP **8.2+**; confirm the selected host PHP and required extensions satisfy the lock before rollout. Linux/cPanel execution, hosted schema/grants, and production smoke remain **UNVERIFIED**.

## New installation

Extract outside `public_html`, for example `/home/YOURUSER/wedworks`, with the document root at `/home/YOURUSER/wedworks/public`. A new installation still needs a server-specific `.env` with a unique APP_KEY, database tables and initial campaign/admin records, writable storage, public storage link, and scheduler cron. Prepare these separately through cPanel File Manager, phpMyAdmin, Cron Jobs, or hosting support. Do not copy development data or credentials.

See repository `docs/DEPLOYMENT.md` for server requirements and layout. Roll back by restoring the preceding matching application/assets/vendor release, retaining server `.env`, storage, and all business records; clear applicable server caches using the same hosting procedure.

## Question sequence and client delivery release — 2026-11-08

The current ZIP replaces the earlier payment-history package. SHA-256: `90f785170aeb80fd2c3f45ea21e7390f145e7bee1f3d937e722807be85e35c61`; 8,056,717 bytes. Manifest: `assets/app-D_szopW1.js` and `assets/app-4_74B2nk.css`. Archive CRC, exclusions, changed controller, new sequence helper and referenced assets verified.

Validation: 297 PHP tests / 2,870 assertions; 10 frontend sequence tests; 35 shared protocol tests; 35 plugin tests; Vite build passed. No new migrations or Composer dependencies. Existing production tables must already be migrated. Preserve server .env, APP_KEY, uploads, public/storage and cPanel-generated PHP settings. Never run migrate:fresh or broad seeders on production.

`question-definitions-20261108.json` is a reference export of 7 registration and 35 website question definitions only. It is not a database backup or an automatic importer. Apply the five added common questions, wording and ordering separately using the admin question editor; preserve existing question keys and submitted-answer snapshots. Do not replace the production database with local data.

The Hermes plugin runs on Windows separately from this application package. Refresh it from the updated tools/hermes-plugin/install.ps1, set its URL and token to production, and restart the full host gateway (a profile restart may retain imported Python modules). Client pages now show the 5-day delivery message and omit internal order.stage. Admin/Hermes production tracking is retained.
