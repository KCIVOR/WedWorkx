# WedWorks 10-10-10 Build Log

## T1: Project setup, database, design tokens

**Done**
- Laravel 11.57 at the project root, with Inertia (laravel adapter 1.x, React 18 adapter 1.x), React, Tailwind 3.4 and Vite. `docs/` untouched (only this file added).
- `tailwind.config.js` + `resources/css/app.css`: all `:root` tokens from the design system (colors, semantic aliases, fonts, radius, 4px spacing scale, shadows, motion) plus its body/heading/focus-visible rules and reduced-motion block. Fonts load from Google Fonts exactly as the design system does.
- 9 migrations with the exact table and column names from the master prompt, plus `SupplierStatus` enum (8 cases), 8 Eloquent models with the specified relationships, `CampaignSeeder` (via `DatabaseSeeder`).
- `.env.example` (HitPay, mail, AI, `HERMES_API_TOKEN` entries, empty); `bootstrap/app.php` registers the Inertia middleware; `resources/views/app.blade.php`, `resources/js/app.jsx`.
- `/design-check` Inertia page (`resources/js/Pages/DesignCheck.jsx`): header, AI bubble, user bubble, primary pill button, card, status pills.
- `tests/Feature/DatabaseFoundationTest.php` (6 tests); `phpunit.xml` now uses the MySQL database `wedworks_chatbot_test`.

**Verified**
- `php artisan migrate:fresh --seed` succeeds on MySQL (XAMPP MariaDB 10.4): all 9 migrations + seeder.
- Same email, or same mobile, in one campaign is rejected by the database; allowed across campaigns (tests pass).
- `SupplierStatus` has exactly the 8 statuses (test passes).
- `npm run build` succeeds.
- `/design-check` returns 200. Computed styles in the browser: header `rgb(47,91,234)` with 24px bottom radius, AI bubble `rgb(232,238,254)` with 4px top-left tail, user bubble `rgb(47,91,234)` with 4px top-right tail, pill button with `rgba(47,91,234,.28) 0 8px 18px` shadow and 999px radius, DM Sans titles, Inter body (both fonts loaded).
- Hex audit: no hex values in `resources/` or `tailwind.config.js` outside the `:root` block, and every hex in `:root` exists in the design system file.

**Failed or skipped**
- Visual screenshot could not be taken (the app window was hidden, screenshot timed out). Verified by computed styles instead. Please open `/design-check` once to eyeball it.
- `composer audit` reports 5 advisories on `laravel/framework`; none is fixed within the 11.x line (11.57.1 is the latest). Open risk, see Assumptions.

**Assumptions**
- Laravel 11 was required by the master prompt, so it stays on the latest 11.x despite the advisories. Moving to Laravel 12 would be a separate decision for you.
- Tailwind 3.4 (the prompt names `tailwind.config`), React 18, Inertia v1 for compatibility with Vite 6.
- Dates use `dateTime` columns (MariaDB rejects a second non-null `timestamp` without a default).
- `email_logs` "unique(supplier_id, type) except `failed`": MySQL has no partial unique index, so a virtual column `unique_type` (NULL when type is `failed`) carries the unique index. Extra column, invisible to app code.
- Local `.env` uses `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync` and timezone `Asia/Manila` (cPanel has no queue worker).
- Extra indexes on status and foreign keys; `suppliers.invite_token` hidden from serialization.
- Local database `wedworks_chatbot` (and `wedworks_chatbot_test`) created in XAMPP MySQL, user root, no password.
- No `EmailLog` model yet; T3 will add it.
- The old `docs/prompts/T1_project_setup.md` differs from the master prompt (10 tasks, an `app/` folder note). I followed the master prompt.

**Design system deviations**
- Three literals the design system uses inside its component CSS but not in `:root` were named as tokens in `app.css` rather than hard-coded: `--slate-300` (#cbd5e1, outline button/field border), `--wb-online` (#4ade80, header status dot, plus its ring rgba) and `--sh-user` (user bubble shadow). Values are copied from the file.

## T2: Registration chat (Chatbot 1) and slot lock

**Done**
- `/` (QR landing page) now renders Chatbot 1: `resources/js/Pages/Register.jsx`, built on new React components that mirror the design system `wb-*` specs (`resources/js/Components/wb/index.jsx`): shell, curved blue header, AI/user bubbles, typing indicator, chips, quick reply, input bar, cards, review rows, note, alert, pills. The chat-log mask and scrollbar CSS was added to `app.css` verbatim from the design system.
- 7 scripted questions with the exact wording of `docs/chatbot.html`; 12 business types from `App\Support\BusinessTypes` (single source, sent to the page as props).
- Validation in the browser and on the server (`RegisterSupplierRequest`): email format, PH mobile (`09XXXXXXXXX` or `+639XXXXXXXXX`, stored as `+639XXXXXXXXX`), business type in the 12, optional social link must be a valid http(s) URL (a bare `facebook.com/x` gets `https://` added).
- Review card with per-row Edit (jumps to that one question, prefilled, then returns to the review card) and "Finish registration".
- `App\Services\SlotService::register()`: one DB transaction, row lock on the campaign, duplicate check inside the lock, slot 1..limit = `invited` (random 48-char token, `invited_at`, `invite_expires_at = now + invite_expiry_hours` from the campaign row), above limit = `waitlisted` with `waitlist_number`, duplicates consume no slot, `status_history` row (actor `system`).
- Result cards: registered (identical wording for invited and waitlisted) and already registered. Draft answers live in `sessionStorage` until submit and are cleared on success.
- Tests: `RegistrationTest` (22 cases) and `ConcurrentRegistrationTest` (16 real parallel PHP processes).

**Verified**
- 12 registrations give exactly 10 invited and 2 waitlisted, slots 1 to 10, waitlist numbers 1 and 2 (test).
- 16 simultaneous processes (10 slots + 6 waitlist) produced slots exactly 1..10 and waitlist 1..6, no duplicate or gap, `slots_taken` = 16 (test, real MySQL).
- Duplicate email (any case/spacing) or mobile (any format) creates no supplier and uses no slot (tests).
- Invalid email, mobile, business type, name, social link rejected by the server (11 cases).
- In the browser (built app): all 7 steps, inline errors for bad email/mobile, 12 chips, Skip on the social question, Edit Mobile returned to the review card with every other answer kept, submit showed the thank-you card and cleared the draft; DB row and status_history row correct. `npm run build` OK; full suite 29 tests pass (T1 + T2).
- No hex colors in `resources/js`.

**Failed or skipped**
- Not checked in a browser: the "already registered" card and the server-error alert (covered by server tests only). No screenshot (app window hidden); checked via DOM text and computed behaviour.
- The concurrency test passing shows no duplicates here, it cannot prove none on every machine; the lock is the safeguard.

**Assumptions**
- One "city or province" question fills two columns: "Tagaytay City, Cavite" splits at the first comma into city and province; a single value fills both (both columns are required by T1).
- Business type is stored as the key (`photographer`), not the label, so T5 can branch on it.
- No hero/stat intro screen from the prototype: the chat starts straight away with the greeting question (design system has no hero component).
- No Back button; Edit on the review card covers corrections.
- Waitlisted vs invited is not revealed in the response either (the flash carries only `registered` or `already_registered`).
- No rate limit on `/register` yet (T10 covers rate limits).
- Test for parallelism uses `DatabaseMigrations` on `wedworks_chatbot_test`; it takes about 5 seconds.

**Design system deviations**
- The design system's review pattern edits a row inline in the card; T2 scope says Edit jumps to the question in the chat, so I followed the task. All visuals still come from existing components.
- Header avatar (white circle with the rings icon) and the "WedWorks Assistant" label above AI bubbles use the design system's `wb-avatar`/`wb-who` values.

## T3: Emails (invite, waitlist, and the sending system)

**Done**
- `App\Services\EmailService`: sends a Mailable only if no `email_logs` row exists for that supplier and type (type `failed` may repeat). It reserves the log row before sending (so two simultaneous triggers cannot both send), then marks it `sent` or `failed`. A send error is reported to the Laravel log and never reaches the caller. `App\Models\EmailLog` added.
- Mailables `InvitationMail` and `WaitlistMail` with Blade views under `resources/views/emails`, built from small Blade email components (`resources/views/components/email/*`: layout, pill, heading, text, slot-meter, rows, note, button). HTML structure and inline styles are copied from `docs/WedWorks_Email_Templates.html`.
- Invitation: private link `/invite/{token}`, amount ₱10,000 (from the campaign price), deadline = `invite_expires_at` (Asia/Manila), "Continue to Website Chatbot" button. Waiting list: waitlist number, says slots do not reopen (D9).
- `RegistrationController` sends the right email right after the slot transaction commits (not for duplicates).
- Placeholder `GET /invite/{token}` (`InviteController` + `Invite.jsx`): unknown token is a plain 404; a valid token shows name, business, status pill and deadline only (no email or mobile).
- `.env.example`/`.env`: `MAIL_FROM_ADDRESS=campaign@kasalangtagaytay.ph`, `MAIL_FROM_NAME="Kasalang Tagaytay"`; `MAIL_MAILER=log` is the local default.
- Tests: `EmailTest` (11 cases).

**Verified**
- 12 registrations send exactly 10 invitations and 2 waiting-list emails; 12 log rows, all `sent`. Re-triggering sends nothing more; a duplicate registration sends nothing.
- A broken mail server (real SMTP transport to a closed port) leaves the supplier registered, the slot used, and one `email_logs` row `failed`. A `failed` row can be retried and then flips to `sent`; a `sent` row is never re-sent.
- Invitation HTML contains the exact deadline, `₱10,000`, the private link and the royal-blue `#2f5bea` header. Waitlist HTML contains `#1`, the number, and "slots do not reopen", with no invite link.
- Looked at both emails rendered in the browser, desktop and 375px mobile: layout matches the templates and fits the phone width.
- Full suite: 40 tests pass (T1 to T3).

**Failed or skipped**
- Not sent through a real SMTP server (no credentials yet); verified with the log/array mailers and a real failing transport. Real delivery check belongs to T10.
- Email-client rendering (Gmail/Outlook) not tested.

**Assumptions**
- The `registered` ("Registration received") template exists but T3 says no other email types yet, so it is not sent; the invite/waitlist email is the one "check your email" refers to.
- The waiting-list template said "we'll email you an invitation if someone doesn't pay". That contradicts D9, so the wording was rewritten (kind, plain, says slots do not reopen) and the template's "Go to my website" button was removed (waitlisted suppliers have no page).
- Invitation note rewritten for D9 ("Slots are never reopened or handed to someone else") instead of "goes to the next supplier on the waiting list". Package label kept as "Standard website" from the template.
- Footer "My website" and "Privacy" links from the templates were dropped (no such pages yet; the template's links were placeholders at wedworks.example).
- The slot meter in the invitation shows "N of 10 slots taken" using the supplier's own slot number.
- A log row stuck at `sending` (process killed mid-send) would block that email; not handled in the MVP.
- The `/invite/{token}` page is only a status placeholder; T5 replaces it with the real chat.

**Design system deviations**
- None in the app UI (the placeholder page uses the existing card, icon and pill). Emails use the template's own inline colors (e.g. `#2f5bea`, `#fff7e6`), as required by rule 7.

## T4: Invite expiry (scheduler)

**Done**
- `php artisan invites:process` (`App\Console\Commands\ProcessInvites`), registered in `routes/console.php` to run every minute (`withoutOverlapping`).
- `App\Services\InviteExpiryService` holds all invite-window rules: `expireLapsed()`, `sendReminders()`, `isGraceProtected()` (D6), `hasLapsed()`, `effectiveStatus()`, `inviteIsValid()` (rule 6 guard for T5/T6).
  - Suppliers in `invited`, `details_submitted` or `payment_pending` past `invite_expires_at` become `expired` (row-locked and re-checked), `status_history` actor `system`, and the Invitation expired email goes out once.
  - A supplier with a `pending` payment started before the deadline is skipped until `grace_minutes` (30, from the campaign) after it.
  - The Payment reminder email is sent once to unpaid suppliers when the deadline is within 2 hours (`config/campaign.php`, `CAMPAIGN_REMINDER_HOURS`).
- Two new Mailables built from the templates file: `PaymentReminderMail` and `InvitationExpiredMail` (plus email components: amount block, danger pill, outline button, muted text).
- `/invite/{token}` enforces expiry: `effectiveStatus()` shows `expired` as soon as the window is over, even before the scheduler runs, with `canContinue = false`. `Invite.jsx` renders the expired card (existing card, danger icon and pill).
- `docs/SCHEDULER.md` with the cPanel cron line `* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1`.
- Tests: `InviteExpiryTest` (16 cases, time travel).

**Verified**
- Supplier invited 24h+ ago becomes `expired`, gets exactly one expired email, history row written. Not expired at 23h59.
- Running the command repeatedly sends no duplicate emails and writes one history row.
- Payment started before the deadline and still pending: not expired at deadline+10 min, expired at deadline+30 min. A `failed` payment gives no protection.
- Reminder: not at 3h before, sent once at 2h before, never repeated, only to unpaid suppliers (paid/building ignored), never after the deadline.
- Expiry frees no slot (`slots_taken` unchanged) and does not promote or invite the waitlisted.
- Expired supplier (and one whose window just ended before the cron ran) sees the expired state, `canContinue=false`, and `inviteIsValid()` is false, which is the guard T6 must use before creating a payment link.
- Real run: `php artisan schedule:run` expired a supplier and sent a reminder to another (email_logs, status_history and the mail log all correct).
- Looked at the expired page and both new emails in the browser at phone width.
- Full suite: 56 tests pass (T1 to T4).

**Failed or skipped**
- "Cannot create a payment" is proven through the guard (`inviteIsValid`) only: there is no payment code until T6, which must call it.
- The real chat page does not exist until T5, so "cannot open the chat" is shown on the placeholder page (`canContinue=false`).
- The cron job itself was not installed on a cPanel host (T10).

**Assumptions**
- The expired template said the slot "was offered to the next supplier" and "if a slot opens again we'll let you know". Both contradict D9, so the wording was rewritten (slot closed, slots do not reopen). The in-app expired card uses the same wording instead of the prototype's.
- The template's "Go to my website" button on the expired email is kept (outline pill) and points to the supplier's invite link, which shows the expired card.
- Reminder email button "Pay ₱10,000" links to the invite page (the payment page arrives in T6).
- Expiring a supplier leaves their `pending` payment rows untouched (T6 decides what a webhook after expiry does).
- The invite window for the reminder is "within 2 hours of the deadline", so a minute-granularity cron sends it between 2h and 1h59m before.

**Design system deviations**
- None in the app UI (expired card is the existing status card with danger icon and pill). New email pieces reuse the template's inline styles.

## T5: Website-details chat (Chatbot 2) and uploads

**Done**
- `/invite/{token}` is now Chatbot 2 for a valid supplier in `invited` (or `details_submitted` when returning): `InviteController`, `Components/WebsiteChat.jsx`, same shell and components as Chatbot 1, with the invite countdown ("Invite & deadline" pattern: pill, countdown boxes with `tabular-nums`, slot meter, note), corrected with the server time.
- 8 questions = 6 common + 2 by business type, defined once in `App\Support\WebsiteQuestions` (wording, options and all 12 type branches copied from `docs/chatbot.html`) and used by both the chat and the server validation.
- Input types: textarea, services rows (name + price), areas chips + booking, photos/logo/colors (design-system input bar with the attach control), single-choice chips, number (venue guest capacity). Optional questions have Skip.
- Uploads (`UploadController`, `config/uploads.php`): jpg/png/webp only, max 5 MB, 1 logo, 10 photos, all from config. Stored under `storage/app/public/uploads/{supplier_id}/` with random names and `uploads` rows. Count is checked under a row lock. Supplier can remove their own files. `docs/UPLOADS.md` has the `storage:link` instructions and PHP limits.
- Review card with all 8 answers, per-answer Edit (jumps to that one question, others kept) and "Confirm and continue".
- Confirm (`POST /invite/{token}/confirm`, `ConfirmWebsiteAnswersRequest`, `WebsiteDetailsService`): saves `website_answers` (json), `submitted_at`, status `details_submitted`, `status_history` (actor `supplier`). Re-confirming later updates the answers without extra history.
- Locking: edits and uploads are refused (403) when the invite is expired or lapsed, the status is past `details_submitted`, or `locked_at` is set.
- Draft answers live in `sessionStorage` until confirm; a returning supplier sees their saved answers (review card) and uploaded files.
- Tests: `WebsiteChatTest` (18 cases).

**Verified**
- A valid invited supplier answers all 8 questions in the real browser and reaches `details_submitted` (DB row, `website_answers` json and `status_history` checked).
- Per-answer edit in the browser: the review card hides, the question shows with the current choice selected, and all other answers are kept after changing it.
- Server rejects: non-image (pdf, svg, php), file over 5 MB, unknown kind, missing file, an 11th photo and a second logo; a file of exactly 5 MB is accepted; limits follow config.
- Uploads land under `uploads/{supplier_id}/` with matching rows, and are served at `/storage/...` (HTTP 200 image/png). A supplier cannot delete another supplier's file (404).
- Expired or lapsed token: no chat, confirm and upload return 403, nothing saved. Unknown token: 404 on every route. Paid supplier and locked answers: no edits.
- All 12 business types give 6 + 2 questions and accept their own valid extra answers.
- Full suite: 74 tests pass (T1 to T5). No hex colors in `resources/js`.

**Failed or skipped**
- Visual check was by DOM text and screenshots at phone width; the screenshot tool was flaky (window hidden), so the services, areas and media panels were verified by behaviour more than by eye. Please look at them once.
- The hidden `<input type="file">` was driven with scripted files; the native file picker was not used.
- The payment step does not exist yet (T6). After confirm the chat shows a "Your details are saved" card saying payment opens soon.
- Photo thumbnails of a removed supplier folder are not cleaned up on expiry (no rule asks for it).

**Assumptions**
- The "photos, logo, colors" question stores colors in the answers JSON; the files themselves are `uploads` rows (answers hold no file names). The question shows "Add logo" (quick reply) and the paperclip in the input bar for photos.
- Review shows 8 rows (one per question). "Service areas" and "booking" share a row, as do "photos/logo" and "colors".
- Venue "guest capacity" (number) is optional like the prototype; choice questions are required; text questions optional except the description (min 5 characters).
- Service areas start with the supplier's city as one removable chip, as the prototype pre-filled their location.
- Style options are shown as plain chips (the prototype used emoji icons; the design system has none).
- Details stay editable in `details_submitted` until `locked_at` (set in T6 on payment); `payment_pending` is not editable from this page.
- The countdown card copy follows the design system ("You're in. Complete payment in:") but says the invitation expires and the slot closes, not "goes to the next supplier" (D9).
- Uploaded files are publicly reachable by their random URL (needed for Hermes and the built site).

**Design system deviations**
- Composed from existing components (flagged per rule 8): textarea, services rows and areas panel use `wb-textarea`/`wb-input` fields inside the input zone with `wb-btn` buttons (the design system shows these fields only inside review rows); the photo thumbnail tile with a remove button is new, built from `r-sm`, `wb-line`, `sh-sm` and the `x` icon.
- Edit jumps to the question (task scope) rather than inline-in-card, as in T2.

## T6: HitPay payment, webhook, grace rule

**Done**
- `HitPayService`: creates payment requests (`POST {base}/payment-requests` with `X-BUSINESS-API-KEY`, form fields incl. `redirect_url` and `webhook`) and verifies signatures: JSON body + `Hitpay-Signature` header (HMAC-SHA256 of the raw body), or form body with an `hmac` field (sorted key+value, HMAC-SHA256). No salt configured means every webhook is rejected. Config in `config/hitpay.php` and `.env` (`HITPAY_API_KEY`, `HITPAY_SALT`, `HITPAY_BASE_URL`, sandbox by default).
- `PaymentService`: `startPayment` (reuses a live link, otherwise creates a new one and marks older pending links `expired`; 30-minute `expires_at`; supplier `payment_pending`; blocked with 403 unless status is `details_submitted`/`payment_pending`, answers are confirmed and the invite is valid), `handleWebhook`, `expireStaleLinks` (run by `invites:process`).
- `POST /webhooks/hitpay` (CSRF-exempt, throttled): bad signature gives 401 and nothing else. Verified `completed`: payment `completed` + `paid_at` + raw payload stored, grace rule applied (`paid_late`), supplier `paid`, order with unique `KT10-xxxx` reference (`A-Z`, `2-9`, unique index as final guard), answers `locked_at`, `status_history` (actor `hitpay`), receipt email once. `failed`: payment `failed`, supplier back to `details_submitted`, "Payment unsuccessful" email (only while the invite is valid). Idempotent under row locks (supplier first, then payment), and the amount must match.
- Payment UI (design-system cards in `Components/PaymentCards.jsx`): Payment card with `wb-amount`, business, status pill, "Invitation expires in" and "Pay ₱10,000" + "Pay later"; Saved for later; Payment unsuccessful with "Try again"; Payment confirmed. Shown after "Confirm" in Chatbot 2 and on the invite page (`payment_pending` shows "Resume payment" and refreshes every 8 s until the webhook lands; paid suppliers see the confirmation with the order reference).
- `GET /invite/{token}/payment/return` only redirects to the status page; it never changes anything.
- New emails from the templates: `PaymentReceiptMail` (#em-confirmed) and `PaymentFailedMail` (#em-failed), with new email components (icon circle, steps, receipt title row).
- `docs/PAYMENTS.md`: setup, flow and webhook rules. Tests: `PaymentTest` (24 cases).

**Verified**
- Real HTTP run against a local stand-in for HitPay (a small PHP server) and the real app: Pay created a payment row (10000.00, 30 minutes, `payment_pending`) and sent the browser to the checkout URL; the browser return page left the supplier `payment_pending`; a webhook with a bad signature got 401; a correctly signed form webhook (with an empty `phone` field, as HitPay sends) got `completed`; replaying it got `already_processed`. Result: supplier `paid`, one order `KT10-xxxx`, answers locked, one receipt email, history rows `payment_pending` (supplier) and `paid` (hitpay). The open page switched to the confirmation card by itself.
- Tests: bad, missing, tampered or unconfigured signature rejected with nothing changed; JSON + header format accepted; same webhook twice gives one order and one email; 25 payments give 25 distinct references; payment started before the deadline and completed 20 minutes after it is accepted with `paid_late = true`; at 31 minutes it is not applied (no order, no email, log warning); a payment started after the deadline never counts; link creation after the deadline gives 403 and no HitPay call; failed payment returns to `details_submitted`, sends the email, retry works, retry after the deadline is blocked; stale links expire and allow retry; answers and uploads are refused after payment; amount mismatch, unknown request id and `pending` events are ignored; HitPay down gives a friendly 502 and changes nothing.
- Full suite: 98 tests pass (T1 to T6). No hex colors in `resources/js`.

**Failed or skipped**
- **Not tested against the real HitPay sandbox** (no credentials yet). The request fields, the response fields (`id`, `url`) and both webhook formats follow HitPay's public docs and a web search, but the exact webhook field names (`payment_request_id` vs `id`, `status` values) should be confirmed with one sandbox payment before go-live.
- **The 30-minute link expiry is enforced by the app only.** I found no confirmed HitPay parameter for the link's own expiry, so none is sent. If HitPay's link stays open longer, a late payer is still handled correctly (paid inside the grace rules, otherwise logged), but the checkout page itself won't close at 30 minutes. Please check in the sandbox whether HitPay offers an expiry option.
- The real payment methods (GCash etc.) and the "Payment method" row of the receipt depend on the webhook carrying a payment type; the row is shown only when it does.

**Assumptions**
- A payment past the grace window is stored as `completed` with `paid_late = true` but creates no order and leaves the supplier as is ("flagged in the log only"); the owner would refund by hand.
- A completed payment on a replaced (`expired`) link is still honoured, because the money was taken.
- "Pay later" only closes the card in the chat; "Resume payment" uses the link in the invitation email (T8 adds My Website).
- After confirming details, tapping Pay again reuses the live link instead of creating a new one; a fresh link is created once the old one ran out or failed.
- Failed-payment emails are not sent when the invite has already lapsed (the email says the invitation is still valid).
- Laravel's TrimStrings/ConvertEmptyStringsToNull would change signed fields, so form webhooks are verified from the raw body.

**Design system deviations**
- None new: payment, saved, failed and confirmed cards are the design system's own patterns (the "Go to my website"/"View confirmation email" buttons are left out because T8 has not built My Website). New email pieces reuse the template's inline styles.

## T7: Hermes API

**Done**
- Routes under `/api/hermes` (`routes/api.php`, registered in `bootstrap/app.php`), behind two middlewares: a rate limiter and `AuthenticateHermes` (Bearer token compared with `hash_equals` against `HERMES_API_TOKEN`; missing, wrong or malformed token gives 401 JSON; an empty configured token refuses everything; a token in the query string is not accepted). Rate limit is 120 per minute per IP (`HERMES_RATE_LIMIT`), counted before the token check so wrong-token guessing is limited too. `config/hermes.php`.
- `GET /orders?status=paid` (default `paid`; `building` and `delivered` also allowed, anything else 422), `GET /orders/{reference}` (supplier, all answers, uploads with public URLs), `POST /orders/{reference}/stage`, `POST /orders/{reference}/deliver`.
- `HermesOrderService` holds all rules under row locks: only orders whose supplier is `paid`, `building` or `delivered` exist for Hermes (everything else is 404); stage must be the same or the next value (first call must be 1), never backwards or skipping; the first stage moves `paid` to `building`; `deliver` only at stage 6 and only once; nothing after `delivered`. Every stage call writes `hermes_stage_log`, status moves write `status_history` (actor `hermes`). Hermes can write nothing else (extra fields in the body are ignored).
- JSON for every API error, even without an Accept header (`HermesException` with `error` codes, and validation 422s).
- `docs/HERMES_API.md` for the person setting up the agents: auth, errors, each endpoint with example requests and real responses, and the agent loop.
- Tests: `HermesApiTest` (19 cases).

**Verified**
- Without a valid token (none, wrong, one character off, Basic scheme, no scheme, empty Bearer, token in the query string, or no token configured) all four endpoints return 401 and change nothing.
- `?status=paid` returns only the paid order while building, delivered and six kinds of unpaid suppliers are excluded; unpaid statuses as a filter (`invited`, `details_submitted`, `payment_pending`, `expired`, `waitlisted`, `all`, empty) return 422. A reference of an unpaid supplier returns 404, even when a stray order row exists for them.
- Stages 1 to 6 work in order; backwards, skipping, 0, 7, -1, text, 3.5 and a missing stage are rejected; the first stage must be 1; repeating a stage is allowed and logged; a delivered order cannot change stage.
- `deliver` before stage 6 is rejected (three cases); after stage 6 it sets `website_url`, `delivered_at`, status `delivered` and the history row; a bad URL (empty, text, `javascript:`, `ftp:`, no scheme) is 422; a second deliver is 409.
- Rate limit: the 4th request in a minute gets 429, including wrong-token requests.
- Real HTTP run against the dev server with a temporary token: 401 without token, list, show (with a real upload URL), stage 1 (supplier became `building`), skipping to 3 refused, early deliver refused, stages 2 to 6, deliver, unknown reference 404. The responses in the docs are those real responses.
- Full suite: 117 tests pass (T1 to T7).

**Failed or skipped**
- The live email when `deliver` is called comes in T8, as planned (nothing is sent from the API yet).
- Rate limiting is per IP, so several agents behind one IP share the 120 per minute; raise `HERMES_RATE_LIMIT` if needed.
- Not tested from the actual Mac mini or over HTTPS (T10).

**Assumptions**
- Status codes: validation problems are 422, order-state problems are 409, unknown or unpaid orders are 404.
- `GET /orders` defaults to `status=paid` ("orders waiting to be built") and also accepts `building` and `delivered` so agents can resume after a crash; unpaid statuses are refused.
- A repeated stage call (same value) is accepted and logged again so an agent can retry safely.
- The order summary also carries `current_stage` on stage and deliver responses so agents see the new state.
- The supplier's email and mobile are included in the detail response because the build and delivery need contact details; no invite token or payment data is exposed.
- Upload URLs use the public disk and `APP_URL`; no signed URLs.

**Design system deviations**
- None (no UI in this task).

## T8: My Website page and the "Live" email

**Done**
- `/invite/{token}/my-website` (`MyWebsiteController`, `Pages/MyWebsite.jsx`), token-protected, built from design-system cards, pills and status cards:
  - Before payment: `invited` gets "Finish your website details" (link to Chatbot 2); `details_submitted` gets the Payment card (Pay) and a link to review or change answers; `payment_pending` gets "Resume payment" (the live link, or a fresh one); a failed payment gets the "Payment unsuccessful" card with "Try again".
  - After payment: confirmation card with the order reference and amount, a progress card with 0/6 to 6/6 (a 6-segment meter; amber "In progress (n/6)", muted "Waiting to start (0/6)", green "Complete (6/6)"), and the supplier's answers read-only with a note that the details are locked.
  - Delivered: "Your website is live!" with the website address and a primary "Visit my website" button (new tab).
  - Expired: the expired card (also shown as soon as the window is over, before the scheduler runs).
  - A grid of six status cards (Registration, Invitation, Payment, Website information, Production, Delivery) summarises it all.
- The page refreshes itself every 30 seconds (simple polling, `router.reload`), and stops once the order is delivered or the invite expired. No websockets, no new packages.
- "Website live" email (`WebsiteLiveMail`, `emails/live.blade.php`): sent once by `HermesOrderService::deliver` after the delivery commits, through `EmailService` (type `live`, logged, never sent twice, a mail failure does not undo the delivery). Composed from the existing email components (round check mark, heading, rows, status pill, pill button), since the templates file has no live template.
- `/invite/{token}` now sends suppliers in `payment_pending`, `paid`, `building` or `delivered` to My Website (so the chat page no longer needs those states). The HitPay browser return goes straight to My Website. The reminder, payment-failed, receipt and expired emails now link to My Website (the invitation email still opens Chatbot 2).
- Small shared pieces: `lib/summarize.js` (answer text used by the chat and My Website), `ButtonLink`, `StatusCard`, read-only `ReviewRow`, `SlotMeter` for any number of segments.
- Tests: `MyWebsiteTest` (17 cases); four older tests updated for the redirects.

**Verified**
- Each status gives the right props and state: invited, details_submitted, payment_pending, failed payment, expired, lapsed-but-not-yet-expired, paid, building at every stage 0 to 6, delivered.
- Invalid or truncated tokens return 404 and the page body contains nothing about any supplier.
- Paid orders show locked answers (8 rows) and no editing path; email and mobile are not in the page props.
- Live email: one email on delivery with the right URL; a refused delivery (before stage 6) and a second `deliver` send nothing more; a broken mail server leaves the order delivered and the log row `failed`.
- In the real browser (phone width): the paid page showed the order reference, `0/6`, locked answers and the status grid. I changed the order to stage 3 behind the open page and, with no reload (same JS context), it switched to "In progress (3/6)" about 23 seconds later; after setting delivered it showed "Your website is live!", the button link (new tab, `noopener`), "Production Complete (6/6)" and "Delivery Live".
- The live email rendered at phone width matches the template look (blue header, white card, label/value rows, pill button).
- Full suite: 134 tests pass (T1 to T8). No hex colors in `resources/js`.

**Failed or skipped**
- The page is polled every 30 s, so a change can take up to 30 s to show, as the task allows. Polling continues while the tab is in the background.
- The pre-payment states (invited, details_submitted, payment_pending, expired) were checked by tests and props, not by eye in the browser.
- The live email was not sent through a real SMTP server.
- The page does not name the six production steps (no wording was specified); it shows the number only.

**Assumptions**
- The "Website live" email's wording is mine (flagged per rule 8): "Your website is live!", the business name, order reference, website address, the time it went live, and a "Visit my website" button.
- Paid-or-later suppliers always land on My Website; the invitation email's link still opens Chatbot 2 for suppliers who have not paid.
- A supplier who is `details_submitted` can pay straight from My Website without reopening the chat, and can still choose "Review or change my answers".
- Production stage 0 is shown as "Waiting to start (0/6)", not as an error.
- The delivered website link opens in a new tab with `rel="noopener noreferrer"`.

**Design system deviations**
- Composed from existing components (flagged): the status grid uses the design system's `wb-status-card` and pills in a 2-column grid; the progress meter is the `wb-slots` meter drawn with 6 segments instead of 10; the "Visit my website" link uses the `wb-btn primary` style on an anchor. No new colors, fonts or radii.

## T9: AI-worded questions

**Done**
- `App\Services\Ai\AiWordingService::reword($question, $answers, $chatId)`: one method. Takes the scripted question and earlier answers (only the first name and business name are ever used, whatever is passed) and returns a friendly rewording, or the scripted text unchanged on any problem. The system prompt tells the model to keep the same meaning, add nothing, and treat the supplied values as data.
- Swappable provider: `WordingProvider` interface plus `AnthropicProvider` (Messages API, `x-api-key`, `anthropic-version`), bound in `AppServiceProvider`. `config/ai.php` and `.env`: `AI_WORDING_ENABLED` (default false), `AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL` (default `claude-haiku-5-5`), `AI_MAX_TOKENS` (150), `AI_MAX_CALLS` (12 per chat session), `AI_TIMEOUT` (4 s).
- Safety and cost controls: strict `max_tokens` on every call; call cap counted per chat session (visitor + chat, failures included); 4 s timeout; fallback to the scripted question on timeout, error, empty reply, a reply cut off by the token cap, or a reply failing sanity checks (length, ending, links, `@`, long numbers, markdown). Emails and phone numbers are stripped from anything sent; the switch off means no call at all, checked in the service itself.
- Endpoints `POST /ai/reword` (Chatbot 1) and `POST /invite/{token}/ai/reword` (Chatbot 2): accept only a question key (unknown key gives 422, free text is not accepted). The server looks up the scripted text itself; Chatbot 2 takes the names from the database and follows the chat rules (403 when the invite lapsed or details are locked, 404 for an unknown token). Throttled to 40 per minute per IP.
- Chat UIs: both chats keep the typing indicator up while a reworded question is on its way (at least 480 ms, give up after 7 s), then show the reworded text or the scripted one. Reworded text is kept in page memory only. Answers, validation, review cards and data fields are untouched. Chatbot 1's wording now comes from `App\Support\RegistrationQuestions` (copied from the prototype, one source for the chat and the AI).
- `docs/AI_WORDING.md`: setup, controls, what is sent, what is stored, how to swap the provider. Tests: `AiWordingTest` (21 cases).

**Verified**
- With a fake provider: reworded questions are returned; answers still save to the correct fields (full registration and confirm, same DB values).
- Provider null/timeout/exception, empty, cut off by the token cap, too short, too long, with a link, an email, a phone number or markdown: all fall back to the scripted question. The Anthropic provider returns null on 500, empty content, a connection error and a missing key.
- `AI_WORDING_ENABLED=false`: provider called 0 times, endpoint returns the scripted text.
- Caps: the 3rd call with a cap of 2 makes no provider call; a different chat has its own counter; failed calls count; token cap and timeout reach the provider as configured.
- Privacy: only the first name and business name reach the provider (checked for last name, email, mobile in both formats, city, social link); email and phone numbers hidden in a question or business name are removed; the website endpoint ignores names and extra fields sent by the browser; the log has only chat, result and ms; nothing from the AI is stored in any table.
- Real browser run against a stand-in for the Anthropic API: Chatbot 1 showed reworded questions with the first name; the request the stand-in received contained only the question text and `{"first_name":"Maria"}` (typed email, mobile and last name appeared 0 times in the 4 requests); with the provider answering after 6 s the next question appeared after 4.6 s with the scripted text; with it returning 500 the chat carried on with scripted questions and the supplier was saved correctly; Chatbot 2's first question was reworded and its request held only the question, first name and business name.
- Full suite: 155 tests pass (T1 to T9). No hex colors in `resources/js`.

**Failed or skipped**
- **Not run against the real Anthropic API** (no key). The request format follows the public Messages API; please run one real call before go-live. `claude-haiku-5-5` is the default model id (from the current model list); change `AI_MODEL` if your account uses another.
- The quality of real rewordings was not judged (the stand-in only prefixes "Quick question"). The guards bound the damage but cannot judge tone.
- While the AI is on, each question waits for the AI (up to about 4 s) before it appears; with it off nothing changes. If the provider is slow all the time, switch it off.
- Scripted and AI-reworded text are never stored, so after a page reload earlier bubbles show the scripted text.

**Assumptions**
- The AI is off by default (`AI_WORDING_ENABLED=false`) so nothing is sent until you opt in.
- "Per chat session" is per visitor session and per chat (registration, or website for one invite), counted in the PHP session. A visitor who clears cookies gets a fresh counter, which is why the routes are also rate limited per IP.
- Chatbot 1's scripted email question greets with the full name the supplier typed (as in the prototype); only the first name is sent to the AI.
- The very first greeting question is reworded too (it has no personal data).
- Replies that merely look odd are dropped rather than repaired, to keep the AI from changing meaning.

**Design system deviations**
- None (no new UI; the existing typing indicator and bubbles are used).

## T10: cPanel deployment

**Done**
- `docs/DEPLOYMENT.md`: written for someone with no Laravel experience. Server needs (PHP 8.2+, extension list from a real platform check, `upload_max_filesize`/`post_max_size`), building the release on Windows (npm build, a `robocopy` copy without dev files, `composer install --no-dev`, zip), the cPanel folder layout with the app outside the web folder, Option A (domain document root = `wedworks/public`, nothing to edit) and Option B (`public_html` plus the exact `index.php` path fix and a one-off symlink script), creating the database, the production `.env`, the setup commands (`key:generate --force`, `migrate --force`, `db:seed --force` for the campaign only, `storage:link`, `config:cache`, `route:cache`, `view:cache`), the cron line, a no-Terminal route, HitPay live keys and the webhook URL `https://<domain>/webhooks/hitpay`, the Hermes base URL and a safe token handover, a mail test, security checks with commands, an end-to-end staging rehearsal, updating, and a rollback procedure.
- `docs/GO_LIVE_CHECKLIST.md`: the one-page checklist plus the "after launch" list (first 24 hours, weekly, after the campaign) and the ideas deliberately not built.
- `.env.production.example`: production template with `APP_ENV=production`, `APP_DEBUG=false`, HTTPS URL, secure cookies, file sessions/cache, sync queue, live HitPay URL; every secret is empty or `CHANGE`.
- Minimal production changes: `config/cors.php` (CORS closed, no path gets `Access-Control-Allow-*`; the framework default had opened `/api/*`); a `register` rate limit on `POST /register` (30 per minute per IP, `REGISTER_RATE_LIMIT`, the item T2 deferred); `URL::forceScheme('https')` in production; `public/.htaccess` now redirects to HTTPS (not on localhost), blocks hidden files and `.php` files under `/storage`, and sends security headers (nosniff, frame, referrer, permissions, HSTS on TLS); `robots.txt` and a `noindex` meta tag; the `/design-check` proof page is registered outside production only.
- Tests: `ProductionReadinessTest` (9 cases).

**Verified**
- Production mode, `APP_DEBUG=false`, with config, routes, views and events cached (`php artisan about`: Environment production, Debug OFF, all four CACHED): home page 200, `/design-check` 404, an unknown invite is a plain 404 with no stack trace, Hermes API 401 JSON without a token, a full registration through CSRF worked (supplier `invited` slot 1, invitation email logged), a post without CSRF got 419.
- CORS: no `Access-Control-*` header on a preflight or normal requests with a foreign `Origin` (home page, Hermes API).
- `.htaccess` tested in a real Apache (XAMPP's, a temporary instance on another port): http on a real host gives a 301 to https, localhost is exempt, `X-Forwarded-Proto: https` is not redirected; `.env`, `.htaccess`, `.git/config`, `.env.production.example` are 403, `.well-known` is allowed; a real `.php` file (and `.PHP`) under `/storage` is 403 while an image is served; security headers present. The test found the deny rules were placed after the front-controller rule (so they never ran) and that was fixed.
- The release build steps in the guide were run for real: the copy has no `.env`, tests, docs, `node_modules`, uploads, symlink or dev packages, contains `public/build/manifest.json`, and zips to 7.5 MB. The stand-alone release folder then ran the guide's setup commands (`key:generate`, `migrate`, `db:seed`, `storage:link`, `config:cache`, `route:cache`, `view:cache`) and served the site in production mode: home 200, `design-check` 404, Hermes API 200 with the token and 401 without; `db:seed` did not reset the campaign.
- Scheduler: `invites:process` is registered every minute and ran in production mode (`schedule:list`, `schedule:run`, and the command itself).
- Registration limit (429 after the configured count, nothing saved), forced HTTPS links in production, no `env()` outside `config/`, the env template (no real secrets), and the `.htaccess` rule order are covered by tests. Full suite: 164 tests pass (T1 to T10).

**Failed or skipped**
- **Acceptance "a sandbox payment against the staging URL completes end to end" was NOT done.** It needs a public staging site and HitPay sandbox credentials, neither of which exist yet. The rehearsal steps are in DEPLOYMENT.md part 9 and the checklist. The signed-webhook flow itself was proven against a local stand-in in T6.
- **Acceptance "the scheduler runs from cron" was only proven locally** (`schedule:run`), not by a real cPanel cron job.
- Not tried on a real cPanel host: the Option A/B steps, `storage:link` or the symlink script, PHP version switching, the MySQL setup and the SMTP details are written from standard cPanel behaviour and have not been run on your host. Expect small differences (menu names, the PHP binary path).
- HSTS is only sent on a direct TLS connection (Apache `HTTPS=on`), not behind a proxy that only sets `X-Forwarded-Proto`.
- No Content-Security-Policy header (the app uses inline styles and Google Fonts; listed under later ideas).

**Assumptions**
- Production serves HTTPS directly from cPanel's Apache (AutoSSL). If a proxy such as Cloudflare sits in front, `X-Forwarded-Proto` is honoured by the `.htaccess` redirect.
- The registration limit is per IP and generous (30 per minute) because a whole booth may share one Wi-Fi address.
- Laravel 11 can cache closure routes, so `route:cache` would also have worked with the proof page; it is excluded from production anyway as a development aid.
- No packaging script was added: the guide gives the commands, so nothing in the app changed beyond configuration.
- A release zip never contains `.env` or `storage` uploads and logs, so extracting an update over the app folder is safe.

**Design system deviations**
- None (no UI changes).

## T11: End-to-end QA

**Done**
- Ran all 14 scenarios and wrote `docs/QA_REPORT.md` (table with result and evidence, bugs fixed, findings, what was not covered, decisions for the owner). No feature was added.
- Test setup: the app on real Apache with PHP (XAMPP, own port, the project's real `.htaccess`, multi-threaded, `config:cache` on), MySQL, stand-ins for HitPay (webhooks signed with the salt) and the Anthropic API, mail to the log. Browser steps in the built-in browser; the rest by real HTTP with real CSRF sessions.
- **3 genuine bugs found and fixed, one at a time:**
  1. Status cards and photo thumbnails had the wrong corners: the tokens `r-sm`/`r-md` collide with Tailwind's `rounded-r-sm`/`rounded-r-md` (right-side-only utilities). Now `rounded-[var(--r-md)]` / `rounded-[var(--r-sm)]`, verified at all four corners.
  2. On a 375 px phone, status-card titles and pills poked out of their cards by 3 to 21 px. Inner column capped to the card width and `Pill` got a `wrap` option used in those cards.
  3. The "Website live" email scrolled sideways on phones with a long website address. Email value cells now wrap long text.
- Regression tests for the three fixes (`UiRegressionTest`, 3 cases; the radius test was shown to fail when the bug is put back).
- `DEPLOYMENT.md`: added the note to always keep `config:cache` on (Finding A in the report).

**Verified**
- All 14 scenarios pass (see the report for the evidence of each). Highlights: 3 rounds of 24 simultaneous sign-ups gave exactly slots 1 to 10 and waitlist 1 to 14 with no errors (cached config); 5 identical webhooks at once gave 1 order and 1 email; the grace window accepted at +10 and +29 minutes and rejected at +31; the Hermes API refused every bad token, unpaid order, backward stage and early delivery; the AI provider failing in three ways never blocked the chat; the design audit found 0 colors, fonts or shadows outside the design system and no horizontal overflow at 320, 375 and 1280 px; all 7 emails fit at 320, 375 and 800 px.
- Full suite: 167 tests pass (T1 to T11).

**Failed or skipped (see "Not covered" in the report)**
- The sandbox payment on a real staging URL, the real HitPay sandbox and real Anthropic API, a real cPanel host and cron job, real SMTP delivery, email clients (Gmail, Outlook, Apple Mail), load and screen-reader testing.

**Assumptions**
- Deadlines were moved by editing database timestamps because a live server cannot time-travel.
- The first `/pay` and Hermes rate limits tripped on my own test traffic; that is the limiter working, so the tests that hit them were re-run after clearing the cache.
- A 1 px label overflow at 320 px (smaller than common phones) was left alone because it uses the design file's fixed label size.

**Design system deviations**
- None remain: the two corner/overflow differences found were fixes back to the design system's values.

## All tasks T1 to T11 are done
Final summary for the owner is in the closing message of this task.

## Dev mode (added after T11 at the owner's request)

**Done**
- `DEV_MODE` switch (`config/dev.php`, `App\Support\DevMode`). When on, the steps that need outside services are simulated so the whole journey can be clicked through locally. When off (the default) nothing changes. `DevMode::enabled()` is always false when `APP_ENV=production`, whatever the setting says.
- With it on: Pay creates a simulated `dev_…` payment and opens a simulated checkout page (`DevCheckout`) whose buttons run the same `PaymentService::handleWebhook` code a verified HitPay webhook uses (order, lock, emails, retry after a failure); a Dev tools card on My Website advances Hermes stages 1 to 6 and delivers through `HermesOrderService` (same rules), and can send the reminder or expire the invitation through `InviteExpiryService`. An amber DEV MODE tag shows in every header.
- The `/dev/*` routes sit behind `EnsureDevMode` and are a plain 404 when it is off. Only `dev_…` payments can be settled there; a real HitPay payment cannot.
- `docs/DEV_MODE.md`; `DEV_MODE=false` added to `.env.example`, `.env.production.example` and the go-live checklist; `phpunit.xml` forces it off for the existing tests.
- Tests: `DevModeTest` (12 cases).

**Verified**
- Real browser, with no HitPay key and no Hermes token: register, answer 8 questions, Pay opened the simulated checkout, "successful payment" produced `paid`, the order `KT10-WC6U`, the receipt; the Dev tools button took the order through stages 1 to 6 and delivery (history rows, `hermes_stage_log`, live email, live link on My Website).
- Turned off: `/dev/*` returns 404, the banner is gone, and Pay goes back to the real HitPay path (a polite 502 here because no keys are set).
- Production guard (setting true while the app is in production: still off, `/dev` is 404), deadline rules still enforced for simulated payments, a real `req_…` payment cannot be settled on the dev page, advance before payment refused, expire leaves paid suppliers alone, reminder sent once. Full suite: 180 tests pass.

**Failed or skipped**
- The simulated checkout skips the webhook signature check (it calls the handler directly); the signature code stays covered by the real-webhook tests and the T11 QA.

**Assumptions**
- One switch for all simulations, not one per feature. To test the real HitPay sandbox, turn dev mode off.
- Emails are not simulated: they follow your mail settings (log or SMTP).

## T12: Admin area (added after T11 at the owner's request)

**Done**
- Admin sign-in (`/admin/login`, path set by `ADMIN_PATH`), created only from the command line (`php artisan admin:create <email>`, hidden password prompt, at least 12 characters; run again to reset). Wrong email and wrong password give one message; 5 wrong tries a minute per email and IP block sign-in; 60 idle minutes sign out; pages are `no-store` and `noindex`. No two-factor, as the owner decided.
- Overview (slots, waiting list, money collected, counts by status, needs-attention list), Suppliers list (winners first then the waiting list; search; filters; paging; CSV download that follows the filters), a supplier page (profile, progress, the 8 answers with their questions, uploads, payment attempts, status history, Hermes notes, emails with Send again for a failed one), Settings, and an Activity log.
- Settings: email (SMTP) with a test email, HitPay with a key check and the webhook address, Hermes token (create-once, shown once), AI wording with a key check, and Campaign (price, hours, grace; locked after the first registration). Saved values are encrypted, win over `.env`, can be removed to fall back to `.env`, and secrets are never sent back to the browser. `DEV_MODE`, database and `APP_KEY` stay in `.env`.
- New: `settings` and `admin_audit_logs` tables, `SettingsService` (applied at boot, so it works with `config:cache`), `EnsureAdmin` middleware, `AdminTestMail`, `AdminPresenter`, 5 admin controllers, 6 React pages under `Pages/Admin`, `Components/admin/ui.jsx`, `docs/ADMIN.md`.
- Updated: `.env.example` and `.env.production.example` (`ADMIN_PATH`), `docs/DEPLOYMENT.md` and the go-live checklist (create the admin, set the path).
- Tests: `AdminTest` (32 cases).

**Verified**
- Full suite: 212 tests pass (180 earlier + 32).
- Real browser (local, dev mode, 12 demo suppliers): sign-in, Overview numbers matched the data (10 / 10 slots, 2 on the waiting list, ₱20,000, one stray payment flagged, one failed email, one invitation about to expire), the list and the search, a supplier page, the Settings page, and creating a Hermes token: it showed once, worked on `/api/hermes/orders` (200) and a wrong token got 401, and a reload no longer showed it. CSV download answered with the right headers. No horizontal page scroll at 375 and 1280 px on all five pages.
- Demo data, the demo admin and the saved test token were removed afterwards (database reset to 0 suppliers).

**Bugs found and fixed while testing**
- A guest could tell real supplier ids (404) from unused ones (redirect to login), because route-model binding ran before the admin check. The admin check now runs first (priority list); a test covers it.
- `campaigns.slots_taken` counts every registration, waiting list included, so the Overview showed 11 / 10. It now shows the number of winners (capped at the limit).
- Removing a saved setting left the saved value in config for the rest of that request. The `.env` value is restored at once (matters for the Hermes token: the old token stopped working only on the next request).

**Failed or skipped**
- "Send a test email", "Check the key" for HitPay and AI, and "Send again" were covered by tests with fakes only, not run against the real services from the browser (the dev mailer is the owner's real Gmail, so I did not send to fake demo addresses).
- The HitPay key check request (`GET /payment-requests`) is unconfirmed against the real HitPay API.
- Not tested: a real cPanel server, `config:cache` with the admin pages (the settings are applied after config loads, and the config-cache test for `env()` still passes), screen readers.

**Assumptions**
- Every admin account sees everything (no roles). The slot limit is not editable. Price and the other campaign values lock once anyone has registered.
- The admin table and stat tile are not in the design system document; they are composed from its tokens (card, pill, button, alert, field, accent bar) and flagged here.

**Design system deviations**
- None in tokens. Composed (not in the design file): admin top bar nav, data table, stat tile.


---

## T13: Admin-editable website questions

**Built**
- The website (Chatbot 2) questions moved from constants into the `website_questions` table, seeded with the original 30 rows (6 for everyone + 2 for each of the 12 business types, word for word). `App\Support\WebsiteQuestions` keeps `for()` and `rules()` and now reads the table, so the chat, the answer checks, My Website, the admin supplier page and Hermes all use one source.
- Admin > Questions: add (short, long, number, choice), edit, delete (soft, restorable), reorder (Up/Down), choose the business types, preview per business type, "answered by" counts. Rules are in `App\Services\QuestionService`, audited as `question_*`.
- `website_answers.question_snapshot` keeps the questions a supplier saw when they confirmed. The admin page, My Website and Hermes read it, so edits never rewrite history. Answers confirmed before this existed were given a snapshot by migration `2026_11_08_000003_backfill_question_snapshots` (the original questions on a first deploy), so a question added later does not appear on a paid supplier's page. Any answer still without one falls back to the current questions.
- Hermes `GET /orders/{reference}` gains `questions[]` (key, label, type, answer). `answers` is unchanged.
- Chat: supports the short-answer type, blocks empty answers to required questions, and shows Skip only for optional ones.

**Decisions** (from `docs/admin-editable-questions-implementation-plan.md`): five questions are locked (description, services, areas, media, style); services/areas/media exist once; cap 20 per business type; deleting a question that suppliers answered is allowed with a warning; a required question added later is not forced on suppliers who already confirmed.

**Tests**: 20 new in `QuestionAdminTest` (including a golden check that the defaults equal the original questions and rules for all 12 types). Full suite 232 passing.

**Not tested**: a real cPanel deploy of the migration; a supplier editing the question list while another is mid-chat in a real browser (a stale browser draft is handled by field name, not by position).

**Assumptions**: every admin may edit questions (no roles). Preview is a list, not an interactive chat. No drag-and-drop (no new package).

**Design system deviations**: none in tokens. The questions table and form reuse the admin table, panel, select, pill and wb form components.


---

## T13b: Admin-editable registration questions (Bot 1), and the two-tab Questions page

**Built**
- The registration (Chatbot 1) questions moved into the `registration_questions` table, seeded with the original 7 (wording, labels and hints word for word). `App\Support\RegistrationQuestions` now reads the table; `RegistrationController` sends the list to the page and `Register.jsx` builds its steps from it.
- The 7 core questions keep their input rules in the page (they fill the supplier's own fields) and can only be reworded and reordered. Admins can add short-answer, number and choice questions. Their answers are saved in `suppliers.extra_answers` with the label that was asked, shown on the admin supplier page and sent to Hermes as `registration_answers`.
- Admin > Questions is now two tabs, **Registration chat (Bot 1)** and **Website chat (Bot 2)**, each with its purpose, count, list, add/edit form, restore box and rules. The tab is kept in the address (`?bot=website`).
- Rules in `App\Services\RegistrationQuestionService` (core locked, type frozen once answered, chosen options kept, cap 12, row locks). Audited as `registration_question_*`.

**Tests**: 17 new in `RegistrationQuestionAdminTest` plus one in `HermesApiTest`. Full suite passing.

**Not tested**: the registration chat clicked through end to end with an added question in the browser against real AI wording (the AI endpoint accepts the new keys and is covered by a test); the CSV export does not include extra answers.

**Assumptions**: the core questions can be reordered (a question using `{name}` before the name question shows "there"). Long-answer questions are not offered in the registration chat (its input is one line).


---

## T14: Hermes asks the questions (chat relay)

**Built**
- New tables `agent_chats` and `agent_chat_messages`; `AgentChatService` relays supplier messages to Hermes and back. Browser endpoints under `/agent-chat` (start, send, poll, fallback, finish) and Hermes endpoints under `/api/hermes/chats` (list waiting, read, reply, send answers, ready). Details in `docs/HERMES_CHAT.md` and `docs/hermes-asks-questions-implementation-plan.md`.
- Admin > Settings > **Who asks the questions** (registration chat and website chat, each "system" or "hermes") with a "Hermes online/offline" indicator.
- Both chat pages use the relay when the switch is on. If Hermes is not listening (45 s) or silent (25 s) the fixed chat takes over quietly, keeps the collected answers, and the supplier is never told. Each case is written to Activity as `hermes_unreachable` (reason `offline_at_start` or `no_reply`).
- Hermes' answers are checked by the same rules as the fixed forms: registration normalizing and rules moved into `App\Support\RegistrationInput`, website cleaning into `WebsiteQuestions::normalize`, both reused by the form requests.
- The supplier presses Confirm on the existing review card; Hermes never creates a supplier or saves answers. The invited/waiting-list outcome is never given to Hermes.
- Dev only: `php artisan hermes:fake` stand-in agent.

**Decisions from the owner**: 25 s reply wait; 45 s online window; unreachable Hermes is silent for the supplier but logged on the activity page; transcripts are kept (never deleted); photos through the existing upload buttons; outcome hidden.

**Tests**: 16 new in `AgentChatTest`. Full suite passing.

**Not tested**: the real Hermes agent (its HTTP behavior is unknown); many suppliers chatting at once; a real phone. The stand-in agent was run end to end in the browser in dev mode.

**Assumptions**: the supplier presses Confirm; polling every 1.5 s from the browser and 1 to 2 s from Hermes is acceptable load.


---

## T14b: Hermes bridge (`tools/hermes-bridge`)

**Built**
- `hermes_bridge.py` (Python standard library only), `start.bat` and `.env.example`. It polls `GET /api/hermes/chats` every 1.5 s, builds a prompt (rules, the questions with their rules in words, collected answers, the conversation) and runs **one named Hermes profile** with `hermes -p <profile> -z <prompt> -t todo --ignore-rules` (every real tool off). Hermes returns a JSON object (reply, answers, widget, ready); the bridge sends the answers to the system, asks Hermes again once if the system refuses something, then posts the reply. Up to 3 chats in parallel; a reply is dropped and redone if the supplier wrote again meanwhile.
- Setup guide for a non-technical owner: `docs/HERMES_BRIDGE.md`.

**Tested with the real Hermes (v0.22.6) on this computer**, profile `dev-quality` (claude-sonnet-4-5), a simulated supplier and the real website: a natural registration conversation of 11 replies (7 to 14 s each), a bad email refused by the system and re-asked naturally, "I do flowers and styling" mapped to Florist, the optional social link skipped, and the chat reached the review card with correct answers (mobile normalized to +639...). The test never pressed Confirm, so no supplier or email was created. Temporary token, modes and chats were removed afterwards.

**Findings**
- The default free model (`stepfun/step-3.7-flash:free`) took 51 s for one word: far too slow for a live chat. A Claude profile answers in 5 to 14 s.
- While the computer was short of free memory (about 1 GB free of 16), Hermes calls took 2 to 3 minutes and local connections failed at times. After other processes were stopped the same calls took 7 s.
- The owner's default Hermes profile has terminal, file, browser and MCP tools (Zoho, Atlassian) enabled, which is why the bridge turns tools off and a dedicated profile is recommended.

**Not tested**: the website chat (Bot 2) through the bridge with photo uploads; a dedicated `wedworks-chat` profile (not created: it needs the owner's API key); many suppliers at once; the bridge running for hours.


---

## T15: Hermes plugin (Telegram-like channel), Phases 0 to 3

Plan: `docs/hermes-wedworks-plugin-implementation-plan.md`. Guide: `docs/HERMES_PLUGIN.md`.

**Built**
- Website: `GET /api/hermes/chats?wait=N&consumer=NAME&seen=...` (held request up to 20 s, a 30-second single-consumer lease, chats already in progress skipped), the active consumer on the Admin Settings card. Config `agentchat.wait_max` and `agentchat.lease_seconds`. 15 tests in `AgentChatLeaseTest`.
- Local site on Apache (XAMPP), port 8081, so a held request cannot freeze the site (Phase 2).
- Shared chat protocol (`tools/hermes-shared`), the refactored bridge, and the `wedworks` Hermes platform plugin with install and uninstall scripts (`tools/hermes-plugin`).

**Phase 1 spike findings** (on the real Hermes v0.22.6, a throwaway plugin and profile): the plugin loads inside the shared gateway without a restart when installed in the profile's own plugins folder; the WedWorks channel ran with zero tools (`tool_turns=0`); `/new` and `/model` from a supplier were plain text; three chats at once worked; chats were isolated; Hermes' own notices arrive through `send()`.

**Problems met**
- A `set_time_limit(10)` in the new wait code cut the whole test run short ("Maximum execution time of 10 seconds exceeded"); now it only raises a limit that is too short.
- Windows refused many local connections (error 10013) because of reserved TCP port blocks; fixed by restarting the Windows NAT service and rebooting.
- Deleting a profile while the shared gateway served it left a repeating `state.db was replaced underneath the gateway` log error until the gateway restarts (other profiles unaffected).

**Not tested yet**: the plugin running inside the owner's real Hermes with a real supplier chat (Phase 4); the final regression of the bridge; the rollout drills.

## T15 closing note (Phase 4, 2026-11-08)
Real end-to-end tests passed (see the plan's "Phase 4 final results"). Fixed the shared rate-limit counter (named limits `agent-start`, `agent-send`, `agent-poll`, `agent-end`). 282 tests pass. Test data and the plugin install were removed; reinstall with a real token when going live.
