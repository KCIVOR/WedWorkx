# Approved Bot 2 intake — local implementation

Approved and applied locally November 9, 2026. There are 18 common main steps and two service-specific steps per supplier: 20 steps for each of the 12 business types, 42 definitions overall. A step may contain several conversational follow-ups.

The Windows `wedworks-chat` profile was verified to target the local app and received the updated protocol file with a private backup. Restart/reload that Hermes profile before starting a fresh local conversation so its process loads the new instructions. Its settings and the Mac/live installation were unchanged.

The update adds public-contact approval, pricing display, brand voice, asset decisions, social proof, page/feature scope, policies and optional domain/platform background. Missing materials can be honestly marked pending. Registration contacts are copied only when explicitly selected, and publication requires permission. Bot 1 remains unchanged.

The fixed questionnaire and Hermes API share the same conditional field definitions. The admin Questions page shows the follow-ups. Guided schemas are managed by the catalog; the editor supports wording changes but does not redesign those schemas.

Existing submitted answer snapshots remain on their original question set, including unpaid clients editing an already submitted brief. New and unsubmitted clients use the updated questions. The local upgrade left suppliers, answers, payments, orders and uploads unchanged; private backups are outside the repository.

## Future cPanel rollout

This has not been applied to production. After backing up production and pulling a release containing this change, run the normal schema migration and the explicit data upgrade from the project directory:

```sh
php artisan migrate --force
php artisan migrate --path=database/data-migrations --force
php artisan optimize:clear
```

Use the hosting account's PHP 8.2 binary if `php` points elsewhere. The data migration requires `database/data/website-intake-v2.json`; include it in any deployment package. It aborts on ambiguous/incompatible rows, missing submitted snapshots or more than 20 questions per business type, rather than removing custom questions. Restore from backup for a deliberate rollback; the data migration does not have an automatic destructive reversal.

Check custom style choices before upgrading: runtime guided choices come from the approved catalog. Local choices matched the catalog. Reconcile production custom choices first if they differ.

The Mac's installed Hermes plugin must also receive the updated `tools/hermes-shared/wedworks_protocol.py` as `wedworks_protocol.py` inside its installed `wedworks` plugin directory, then reload/restart that profile's gateway. A cPanel pull alone does not update the Mac plugin. Keep the existing URL/token configuration.

No planner, template selection, AI build, approval screen or publishing automation is added by this intake change. Those remain checklist tasks.

## Verification

- PHP suite: 325 tests, 3,377 assertions passed.
- Frontend suite: 24 tests passed.
- Shared Hermes protocol: 37 tests passed; plugin adapter: 35 tests passed.
- Production frontend build passed.
- Browser check passed for sequential follow-ups, explicit skipping, typed subfield recovery after reload and the conditional goal/help branches. The inspected mobile view had no horizontal overflow.
- All 12 business types validated with 20 steps; migration rollback on exceeding the cap, submitted snapshots, conditional answers, contact permission and upload ownership covered by tests.
