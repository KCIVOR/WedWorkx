# Contact correction safety — November 9, 2026

The supplied production transcript showed repeated `public_phone` submissions on unrelated turns, including masked and truncated values, accompanied by false correction acknowledgments. The public-phone field had only generic text validation. Supplied service inclusions were also silently dropped during guided normalization.

Implemented protections:

- Server and guided input validate complete public numbers. Valid Philippine mobile/landline input can be normalized to +63; international numbers require a country code. Masked, truncated and nonnumeric values are rejected. Optional contacts can still be explicitly skipped. This is format validation, not verification of ownership or reachability.
- The shared plugin protocol accepts contact updates only when supported by the latest supplier text, or an explicit yes after the assistant displays the actual registration value in reuse mode. Unrelated contact replay is refused and retried. A valid full number in the latest message takes priority over model masking.
- Repeated phone correction claims without an accompanying supported update are retried. If the final retry still contains an unsupported contact update or rejected answer, the reply asks the current question rather than claiming success. This is a bounded guard plus prompt guidance; full end-to-end live model behavior still needs a fresh smoke test.
- Instructions require exact choice values, one visible field at a time, one acknowledgment per correction, proper list formats and self-correction of formatting mistakes. Public contact context uses the supplier's actual city/province fields.
- Guided service inclusions are retained in optional `details` (up to 2000 characters per package), validated and shown in review/admin summaries. Existing name/price payloads remain supported.

No schema/data migration is needed. Pull matching app/frontend files and clear Laravel caches. Replace the installed Mac plugin's `wedworks_protocol.py` with `tools/hermes-shared/wedworks_protocol.py`, then restart its host gateway so the imported module is reloaded. cPanel alone cannot update the Mac plugin. Preserve the persona, URL and token.

Existing bad numbers are not guessed or bulk-rewritten. Before confirming the affected brief, explicitly enter the correct complete public number and verify the final review. A fresh invitation is recommended for the regression smoke test. Already submitted/paid client data must not be silently altered.
