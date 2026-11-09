# cPanel package

Download `wedworks-cpanel.zip` using GitHub's **Download raw file** button. This package contains the application, matching compiled `public/build` assets, and 77 production Composer dependencies. No npm or Composer commands are needed on cPanel.

## Existing installation: update without Terminal

1. Back up the current application files and database. Record the current release so it can be restored. Preserve the server `.env` and all existing `storage` contents, including uploads and sessions; never upload a local database or credentials.
2. In cPanel File Manager, enable **Show Hidden Files**. Upload and extract the ZIP into a temporary directory outside `public_html`. Confirm `public/.htaccess`, `vendor/autoload.php`, and `public/build/manifest.json` exist. Do not expose the archive or application root publicly.
3. During a maintenance window, release the application files and production `vendor` together. Preserve the server `.env` and existing `storage`; the archive's storage files are empty-directory placeholders only. Retain a backup and old hashed public assets while existing browser sessions reload.
4. Release the new controller, routes, React source, navigation, manifest, and new asset files together. The manifest must reference `assets/app-YNMLU-ta.js` and `assets/app-C6s8cAmj.css`, both present under `public/build`. Remove `public/hot` if present; it points browsers at a development server. Preserve the existing public storage link.
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

The current ZIP replaces the earlier payment-history package. SHA-256: `4b3d551fefa2d6a3991b255bcd217a4c96af1804148fe742fdb7e2ac1dbc9d83`; 8,071,686 bytes. Manifest: `assets/app-YNMLU-ta.js` and `assets/app-C6s8cAmj.css`. Archive CRC, exclusions, changed controller, new sequence helper and referenced assets verified.

Validation: 297 PHP tests / 2,870 assertions; 10 frontend sequence tests; 35 shared protocol tests; 35 plugin tests; Vite build passed. Earlier question/delivery changes had no migrations or Composer dependencies. The subsequent live-chat update below adds one migration. Existing production tables must already be migrated. Preserve server .env, APP_KEY, uploads, public/storage and cPanel-generated PHP settings. Never run migrate:fresh or broad seeders on production.

`question-definitions-20261108.json` is a reference export of 7 registration and 35 website question definitions only. It is not a database backup or an automatic importer. Apply the five added common questions, wording and ordering separately using the admin question editor; preserve existing question keys and submitted-answer snapshots. Do not replace the production database with local data.

The Hermes plugin runs on Windows separately from this application package. Refresh it from the updated tools/hermes-plugin/install.ps1, set its URL and token to production, and restart the full host gateway (a profile restart may retain imported Python modules). Client pages now show the 5-day delivery message and omit internal order.stage. Admin/Hermes production tracking is retained.

Branding update: shared public/chat shell includes a fixed footer reading "Powered by StartupLab Business Center". Desktop and 390px mobile browser layout checks confirmed footer below input, inside viewport, with no horizontal overflow. Vite build and ZIP CRC/manifest/component checks passed.

Mobile layout: registration, invitation and My Website use an edge-to-edge full-height shell below 768px, with 12px content gutters, full-width cards and safe-area padding. Desktop retains a 600px centered rounded card. Browser checks at 393px and 320px confirmed full-height borderless shell, visible input/footer and no horizontal overflow; desktop checked at 1088px. Keyboard behavior on a physical phone remains unverified. Build and matching archive entries verified.

Chat timestamps: assistant and supplier message bubbles display Philippine time with full date in the time-element title. Hermes messages use database created_at (including immediate send and resumed polling); fixed questionnaire times are recorded when displayed/submitted and retained with session drafts. Missing historical times are omitted. No schema migration. Validation: 19 AgentChat tests / 319 assertions; 12 frontend tests; Vite build; browser server timestamp after reload and 393px no-overflow check; updated package CRC/assets checked.

## Active Chats release

Admin-only live Hermes viewer added. **This release now requires `php artisan migrate --force`** for the indexed nullable `agent_chats.last_seen_at` column. Run migration before reopening the updated app, then `php artisan optimize:clear`. No new Composer dependencies, seeders, destructive resets or real-data imports. Back up database/files and use a maintenance window. See docs/admin-live-chats.md for scope, presence lifecycle and verification. New files, changed model/controller/routes, manifest/assets and ZIP integrity verified.

## Website question data upgrade

Manual question entry is now replaced by an opt-in data migration: **`php artisan migrate --path=database/data-migrations --force`**, followed by `php artisan optimize:clear`. This is separate from the Active Chats schema migration. See docs/website-question-data-upgrade.md. Five tests / 225 assertions verify ordering, original-key preservation, conflict rollback, idempotence, manual additions, submitted-record preservation and snapshot safeguards. ZIP updated/verified; JS/CSS build unchanged.

## Chat database timezone correction

Chat message TIMESTAMP values now use MySQL UNIX_TIMESTAMP for their actual instant instead of interpreting database-local wall time as Laravel-local time. Client send/poll, admin live viewer and Hermes transcript share UTC ISO output; the frontend still shows Philippine time. Bulk reads select the epoch in the same query; a just-created message retrieves its database epoch once. Existing and future database-generated chat messages work with UTC or +08:00 DB sessions. No migration, backfill, database timezone change, or frontend rebuild is required for this correction. Deploy code and clear caches. Validation: reproduced8-hour failure, then 27PHPtests/372assertions and2frontendtests passed, including UTCvsManila session tests and API equivalence. Package files and CRC verified.

## Browser handoff recovery

Bot2 resumes its open/review conversation by invitation, including across browsers. Bot1 resumes by encrypted HttpOnly cookie in the same browser, with legacy sessionStorage migration. Stale fixed drafts no longer suppress an available server session; Try AI chat allows explicit retry. Added request timeouts/retries, accepted-answer preservation and reply grace. Completed submissions close their recovery sessions. No new migration or Hermes plugin restart for this release. Pull matching code/assets, clear Laravel caches, reload and test the phone email-app-to-Chrome path. See docs/chat-session-recovery.md. Full314PHPtests passed before finalcompletiondelta; subsequent47registration/chat and36website tests passed;16frontendtests/build/packagechecks passed. Physical phone handoff remains unverified until hosted smoke testing.

## Duplicate chat send fix

Pending sends now synchronously lock the client submit handler and disable the composer. Poll and send responses merge by saved message ID. UUID-scoped cached acknowledgments under a database row lock protect network retries for 24 hours (until cache loss/clear). Legacy requests remain accepted. No schema migration or Hermes restart. Refresh clients after deploying the controller and matching bundle.28PHPtests/378assertions,18frontendtests,build and packageCRC/asset/controller checks passed. See docs/chat-send-protection.md.

## Approved Bot 2 questionnaire — November 9, 2026

This release requires both schema and opt-in data migrations: `php artisan migrate --force`, then `php artisan migrate --path=database/data-migrations --force`, then `php artisan optimize:clear`. Back up first. See [intake rollout notes](../docs/website-intake-v2-release.md). Existing submitted snapshots and business records are preserved. There are 42 definitions, 20 main steps per supplier type. Refresh the installed Mac Hermes protocol and restart its gateway before testing the new guided questions. No AI build or publishing automation is included.

Verified package SHA-256: `4f1134f445ed8febb95e8f259b93617ac73163c3d2211dfbd7dd96b281374a0a`; 8089897 bytes. Matching catalog, migrations, sources, frontend manifest/assets and ZIP integrity verified.

## Full chat recovery — November 9, 2026

Raised the combined message budget from 80 to 500 for the expanded intake. Full chats now preserve collected answers and continue through the fixed questionnaire with a notice, rather than silently rejecting sends. Pull backend/config and matching compiled assets, then clear Laravel caches. No migration or Mac plugin refresh is needed for this fix. See [recovery notes](../docs/chat-capacity-recovery.md).

Current package SHA-256: 187604c4b8afc09f5e8ee0e06050c9791f79bb4083f9463577427623eb1cf9d6; 8090587 bytes. CRC, catalog/migrations and compiled asset matching verified.

## Contact correction safety — November 9, 2026

Public phone validation rejects masked/incomplete numbers. The updated shared Hermes protocol prevents contact replay on unrelated turns, requires actual-value review before registration-contact reuse, and avoids false correction acknowledgments. Guided package inclusions are preserved. See [contact correction notes](../docs/contact-correction-safety.md). Pull matching backend/frontend files and clear caches; no migrations required. Replace the Mac installed `wedworks_protocol.py` and restart its host gateway. Existing malformed contacts must be corrected explicitly, not inferred.

Current package SHA-256: `9ab7b3ac8e85b048fa473dee512e1322e47e24e5291a75437b0225469cb76aaa`; 8091532 bytes. ZIP CRC and source/manifest/asset matching verified.

## Chat input focus appearance — November 9, 2026

Removed the redundant inner rectangular focus outline from the message composer. Its rounded outer focus highlight remains, and other controls retain their focus indicators. Frontend build and matching ZIP assets verified. Pull the matching frontend manifest/assets and reload. No migrations or Hermes restart required for this appearance change.

Current package SHA-256: `4c52a17d80b1280de8afbe3873bec021ac420bf0f8543a5840c9064995cb89bf`; 8091615 bytes.

## Bot 2 wording, silent fallback, and client emails — November 9, 2026

After pulling this release, apply the wording migration with `php artisan migrate --path=database/data-migrations/2026_11_15_000001_polish_website_intake_wording.php --force`, then run `php artisan optimize:clear`. This expects the approved Bot 2 intake upgrade to have already been applied. The migration revises question text, follow-up prompts, and choice labels. Both bots automatically switch to fixed questions when Hermes is unavailable. The invitation and payment receipt use client-facing wording.

Current package SHA-256: `f9c86c15615ce7ef604770cffffe31bb9b30ee0ce4ec1353dc67df888e4a8224`; 8095343 bytes. Matching migration, email views, frontend assets and ZIP integrity verified.
