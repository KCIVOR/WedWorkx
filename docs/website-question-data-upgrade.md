# Production website question data upgrade

This opt-in migration replaces the manual admin question rollout for the approved November 8 brief. It is deliberately outside the default schema-migration directory; run it explicitly:

```sh
php artisan migrate --path=database/data-migrations --force
php artisan optimize:clear
```

Back up production database and files first. Pull this release using a maintenance window, preserving the server .env, storage, public storage link and cPanel PHP settings. Do not use migrate:fresh, seeders or a local database import. Normal `migrate --force` alone does not run this data upgrade.

The upgrade rewords description/additional, creates five shared textarea questions (Target Audience and Main Website Goal required; Differentiation, Style References and Search Phrases optional), and applies the approved sequence. Baseline 30 definitions become 35; each business type sees 13. Registration questions are untouched. Existing service-specific questions retain their types, options, scopes and relative order. Existing custom questions remain before Additional Information.

Manual additions with an exact case-insensitive common label are reused with their own original key. Ambiguous duplicates, incompatible types, deleted matches, missing core common questions, missing/empty submitted snapshots or a resulting per-type cap above 20 cause a full transaction rollback. Resolve the reported conflict in admin before retrying; do not delete submitted answers. The migration never changes suppliers, payments, orders, uploads, answers or saved snapshots. Previously submitted clients retain their original question snapshot; new/in-progress clients see the updated definitions. A draft may therefore need to answer new required questions.

The migration ledger makes the command one-time. Running the up method repeatedly also makes no further changes. Automatic down is intentionally blocked because clients may already have answered the new keys; restore/review definitions using the pre-release backup if reverting.

Validation: 5 feature tests / 225 assertions, covering baseline ordering for all 12 business types, unchanged old keys/types/options/scopes/flags, required/optional settings, repeated execution after time advances, manual-key reuse, transactional conflict rollback, the actual artisan --path command, byte-identical submitted answers/snapshots and supplier records, and null/empty/JSON-null snapshot guards. PHP syntax and archive integrity checks passed. Production execution is pending.
