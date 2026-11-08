# Local question sequence — 2026-11-08

Scope: local database and local application source only. No GitHub push, cPanel change, gateway restart, or installed Hermes profile modification.

Bot 1 keeps the contact/business sequence: Name, Email, Mobile, Business Name, Business Type, Location, Social Media (optional).

Bot 2's local sequence for all 12 service types is:

1. Company Description
2. Target Audience
3. Main Website Goal
4. What Makes Your Business Different?
5. Services / Packages
6. First service-specific question
7. Second service-specific question
8. Service Areas & Booking
9. Website Style
10. Style References (Links)
11. Photos, Logo & Colors
12. Search Phrases
13. Additional Information

The local database has 7 registration definitions and 35 website definitions: 11 general and 24 service-specific. Existing keys/answers/snapshots remain unchanged. Before-change question definitions are backed up privately under `C:/Users/Rovick/.codex/backups/wedworks`; the 35-question pre-sequence inventory is `website-questions-before-sequence-20261108-200915.json`. Local question definitions are data and do not deploy through Git automatically.

Root causes addressed: cached numeric draft positions became stale after reordering; partially answered multi-field questions could be skipped on Hermes fallback; review previously required only mandatory answers, permitting untouched optional questions to disappear. Drafts now retain their current question key. Legacy chat drafts can conservatively resume earlier using the old index as an upper bound, but never advance beyond the earliest missing answer. Typed-but-unsubmitted text remains on its current question. Fixed advancement and both Hermes handoff paths return to the earliest gap. Active Hermes sessions no longer write an unrelated empty fixed-chat draft.

Hermes receives ordered questions and explicit next-question context. The server rejects review until each question's fields have accepted answers or explicit optional skip values. Optional questions remain optional, and the supplier still confirms registration/website answers. The model's free-text wording is not deterministically validated for semantic ordering; instructions guide it and server completion guards prevent incomplete review.

Validation: 296 PHP tests / 2,872 assertions; 10 Node sequence tests; 31 shared protocol tests; 35 adapter tests; PHP syntax checks and Vite build. Independent code review approved after two resume edge cases were reproduced and fixed. Browser smoke on local Apache verified a stale review draft resumed at missing Business Name, advanced to Location while retaining a volunteered business type, and stayed on Location after reload. No registration was submitted by the browser check; its synthetic empty Hermes chat was closed.

Deployment is intentionally pending. Applying these source changes to a running Hermes profile requires refreshing the installed shared protocol and restarting that gateway; the active profile may point to cPanel, so this task does not alter it. No real-model end-to-end sequencing run or cPanel rollout is claimed. The existing cPanel ZIP is unchanged and has not been rebuilt for this local-only review.
