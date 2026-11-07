# WedWorks 10-10-10 Chatbot: MASTER BUILD PROMPT

You are the Senior AI Developer for this project. Read this whole file first. Then build the system **one task at a time**, in order, exactly as written. This file is the approved scope. Do not change it, and do not add features that are not in it.

---

## 0. HOW TO RUN THIS FILE (the working protocol)

1. Work on **one task only** (T1, then T2, and so on). Never start the next task by yourself.
2. Before each task: re-read section 1 (Project), section 2 (Design System Rule), section 3 (Global rules) and the task itself.
3. Do only what the task's **Scope** says. Do not rebuild, refactor, or restyle parts from earlier tasks unless the task says so.
4. After each task, run its **Acceptance Criteria** and report back in this format:
   - **Done:** what was built (files created or changed).
   - **Verified:** which acceptance criteria passed and how you checked.
   - **Failed or skipped:** anything not working, said plainly.
   - **Assumptions:** anything you decided on your own.
   - **Design system deviations:** any UI value you could not take from the design system (should be none).
5. Append the same report to `docs/BUILD_LOG.md` (create it in T1). This keeps context short for the next task.
6. **Stop and wait** for the user to reply "approved" before starting the next task.
7. If something is unclear and it affects the result, ask one short question. If it does not, choose the simplest option, state it under Assumptions, and continue.
8. If a task breaks something, **roll back that task** instead of patching for a long time.
9. Keep prompts and replies short. Do not repeat this file back.

---

## 1. PROJECT

**What we are building:** A QR-triggered supplier onboarding system for the **WedWorks 10-10-10 Campaign at Kasalang Tagaytay**. A wedding supplier scans a QR code at the booth, registers through a chatbot, and the **first 10 suppliers** are invited. The rest are waitlisted. An invited supplier answers website questions in a second chatbot and pays **₱10,000** through HitPay. Paid orders are picked up by **Hermes AI agents** (running on the owner's Mac mini), which build the supplier's website in 6 stages. The supplier follows progress on a "My Website" page.

**Stack (fixed):**
- Laravel 11, PHP 8.2+, MySQL
- Inertia + React + Tailwind CSS (built with Vite)
- Hosting: **cPanel shared hosting** (no always-on worker, no Node on the server; build assets locally, then upload)
- Payments: HitPay (sandbox first)
- Email: Laravel Mail over SMTP
- AI wording: one LLM API behind a single service class, with a scripted fallback
- Hermes connection: a **token-protected REST API** (decision D7). Hermes does **not** connect to the database directly.

**Existing files in `docs/` (read-only, never modify):**
- `docs/WedWorks_Chatbot_Design_System.html`: the design system (see section 2)
- `docs/WedWorks_Email_Templates.html`: the 8 email templates
- `docs/chatbot.html`: the prototype. Use it for the **exact question wording, the 12 business types, and the flow behavior**
- `docs/WedWorks_User_Journey_Flowchart.html`: the supplier journey

### Locked decisions
| ID | Decision |
|---|---|
| D1 | A slot is assigned when Chatbot 1 is submitted. Submissions 1 to 10 are invited. 11 and later are waitlisted. |
| D2 | No admin UI. Hermes agents run the backend side. |
| D3 | (Replaced by D7.) |
| D4 | Laravel + MySQL + cPanel. |
| D5 | Everything is real: HitPay, emails, AI-worded questions, photo uploads. |
| D6 | **Late payment is honored.** If a payment was started before the invite deadline, it counts even if it lands up to the grace period (30 minutes) after. A payment started after the deadline is blocked. |
| D7 | Hermes connects through a private, token-protected API. |
| D8 | Frontend is React + Tailwind through Inertia. |
| D9 | Waitlisted suppliers are **never promoted**. Spots never reopen. |

### Supplier statuses (exactly these eight)
`waitlisted`, `invited`, `expired`, `details_submitted`, `payment_pending`, `paid`, `building`, `delivered`

Allowed transitions:
- `invited` to `details_submitted` to `payment_pending` to `paid` to `building` to `delivered`
- `payment_pending` back to `details_submitted` when a payment link fails or expires (then retry)
- `invited`, `details_submitted` or `payment_pending` to `expired` when the window closes with no payment started in time
- `waitlisted` and `expired` are dead ends

### Business rules (must be enforced in code)
1. The slot counter is incremented in a **database transaction with a row lock**, so two simultaneous submissions can never take the same slot.
2. Email and mobile are unique per campaign. A duplicate sees "already registered" and gets no new slot.
3. A slot is never freed or re-assigned. No waitlist promotion.
4. The invite expires `invite_expiry_hours` (24) after the invite is sent. Read it from the `campaigns` table.
5. A HitPay link lasts 30 minutes. A new link replaces the old one.
6. A payment link can only be created while the invite is valid.
7. Payment is confirmed **only by the HitPay webhook**, verified by signature. Never trust the browser redirect.
8. The webhook is **idempotent**. A duplicate call must not double-process an order.
9. **Grace rule (D6):** if the payment record's `started_at` is before `invite_expires_at`, a completion up to `grace_minutes` after the deadline is accepted and `paid_late` is set to true. If `started_at` is after the deadline, no link is created in the first place.
10. After `paid`, the supplier's answers are locked and cannot be edited.
11. Hermes only sees orders whose status is `paid` or later.
12. Only Hermes writes `building`, the stage number (1 to 6), and `delivered`.
13. Every status change writes a `status_history` row.
14. Every email send is logged in `email_logs`. Never send the same email type to the same supplier twice.

---

## 2. DESIGN SYSTEM RULE (STRICT, APPLIES TO EVERY TASK)

The file `docs/WedWorks_Chatbot_Design_System.html` ("Bold") is the **single source of truth for every UI element**. This is a hard rule:

1. **Read that file first**, before writing any UI or styling code in any task. Do not work from memory or from generic defaults.
2. **Use only its tokens.** No hex color, font, radius, shadow, spacing value, or animation timing may be invented. If a value is missing from the design system, stop and ask. Do not improvise.
3. **Reuse its component specs exactly.** Chat components use the `wb-*` names and values in the file: shell, header, message bubbles, avatars, quick replies, chips, typing indicator, input bar, cards, review rows (with edit), payment rows, amount, form fields, notes, alerts, pills, status cards, countdown, slot meter, toast. Rebuild them as React components that match the file's CSS values one to one.
4. **Status colors** (green, amber, red) are only for state. Never use them for decoration.
5. **Mobile first**, desktop ready. Light theme only. Message text is never below 14px. Use `tabular-nums` for times, amounts and countdowns.
6. **Respect `prefers-reduced-motion`**, as the file does.
7. **Emails** must reuse the HTML structure and styles in `docs/WedWorks_Email_Templates.html` (royal blue header, white card, pill buttons, label/value rows). Do not design new email layouts.
8. If a screen needs something the design system does not show, **compose it from existing components** and flag it in your report. Do not invent a new style.

Any output that breaks this rule is rejected and must be redone.

---

## 3. GLOBAL RULES

- Do exactly what the task says. Nothing more.
- Do not modify anything inside `docs/` (except creating `docs/BUILD_LOG.md`).
- Do not add packages beyond what the task needs. Prefer Laravel's built-in features.
- No hard-coded secrets. Everything goes in `.env` (and an empty entry in `.env.example`).
- Validate all input on the server. Never rely only on the browser.
- Use Laravel Form Requests for validation and Eloquent for data. No raw SQL with user input.
- Philippine mobile numbers: accept `09XXXXXXXXX` and `+639XXXXXXXXX`, store in one normalized form.
- Keep every task small, readable and testable. Add feature tests for the rules a task enforces.
- cPanel limits: no queue daemon. Use the Laravel scheduler driven by a cron job every minute, and synchronous or `queue:work --stop-when-empty` style sending.
- **Not in the MVP (do not build):** admin screens, waitlist promotion, any feature not listed in a task.

---

## TASK T1: PROJECT SETUP, DATABASE, DESIGN TOKENS

**Context:** Fresh build. Folder currently holds only `docs/`. This is T1 of 11.

**Objective:** Create the project skeleton, database and design tokens. Nothing else.

**Scope:**
1. Install Laravel 11 with Inertia (React) and Tailwind via Vite at the project root. Keep `docs/` untouched.
2. **Tailwind theme from the design system.** Read the design system file and map its `:root` tokens into `tailwind.config` and a base CSS file:
   - Colors: all blue, navy, slate, green, amber and red values, and the semantic aliases (`wb-accent`, `wb-accent-hover`, `wb-canvas`, `wb-surface`, `wb-text`, `wb-heading`, `wb-muted`, `wb-line`, `wb-ai-bg`, `wb-ai-line`, `wb-user-bg`).
   - Fonts: DM Sans (display), Inter (body), JetBrains Mono (mono), loaded from Google Fonts as the file does.
   - Radius: `r-sm`, `r-field`, `r-md`, `r-card`, `r-shell`, `r-bubble`, `r-tail`, `r-pill`.
   - Spacing: the 4px scale. Shadows: `sh-sm`, `sh-md`, `sh-lg`, `sh-blue`. Motion: the easing and three durations.
   - Base styles from the file's body, heading and focus-visible rules, and the reduced-motion block.
3. **Migrations** (exact names):
   - `campaigns`: id, name, slot_limit, slots_taken, invite_expiry_hours, grace_minutes, price, timestamps.
   - `suppliers`: id, campaign_id, slot_number (nullable), waitlist_number (nullable), name, email, mobile, business_name, business_type, city, province, social_link (nullable), status, invite_token (unique, nullable), invited_at (nullable), invite_expires_at (nullable), timestamps. Unique(campaign_id, email) and unique(campaign_id, mobile).
   - `website_answers`: id, supplier_id (unique), answers (json), submitted_at (nullable), locked_at (nullable), timestamps.
   - `uploads`: id, supplier_id, kind (`logo` or `photo`), path, original_name, mime, size_kb, timestamps.
   - `payments`: id, supplier_id, hitpay_request_id (unique), amount, checkout_url, status (`pending, completed, failed, expired`), started_at, expires_at, paid_at (nullable), paid_late (boolean, default false), webhook_payload (json, nullable), timestamps.
   - `orders`: id, supplier_id (unique), reference (unique, `KT10-xxxx`), current_stage (0 to 6, default 0), website_url (nullable), paid_at, delivered_at (nullable), timestamps.
   - `status_history`: id, supplier_id, from_status (nullable), to_status, actor (`supplier, system, hitpay, hermes`), created_at.
   - `email_logs`: id, supplier_id, type (`registered, invite, reminder, receipt, failed, updated, waitlist, expired, live`), sent_at, status. Unique(supplier_id, type) except `failed` (a supplier may get more than one failed-payment email).
   - `hermes_stage_log`: id, order_id, stage, note (nullable), created_at.
4. **Enum:** `SupplierStatus` with exactly the eight statuses.
5. **Models** with relationships: Campaign has many Suppliers. Supplier has one WebsiteAnswer, many Uploads, many Payments, one Order, many StatusHistory. Order has many HermesStageLog.
6. **Seeder:** one campaign "Kasalang Tagaytay 10-10-10", slot_limit 10, slots_taken 0, invite_expiry_hours 24, grace_minutes 30, price 10000.
7. **Config:** `.env.example` entries (empty) for HitPay, mail, the AI provider, and `HERMES_API_TOKEN`.
8. **Proof page:** one Inertia page at `/design-check` rendering a few components (header, AI bubble, user bubble, primary pill button, card, status pill) using only the new tokens.
9. Create `docs/BUILD_LOG.md` and log T1.

**Constraints:** No chat, slot logic, emails, payment, API or real screens. No invented style values. Exact table and column names.

**Deliverable:** Working project, migrations, models, enum, seeder, Tailwind theme, `/design-check` page, build log.

**Acceptance Criteria:**
- `php artisan migrate --seed` succeeds on a clean MySQL database.
- Two suppliers with the same email (or mobile) in the same campaign are rejected by the database.
- `SupplierStatus` has exactly the eight statuses.
- `npm run build` succeeds.
- `/design-check` visually matches the design system: royal blue `#2F5BEA` header with the 24px curve, pale blue AI bubble with the 4px tail, solid blue user bubble, pill button with the soft blue shadow, DM Sans titles, Inter body.
- A search of the code finds no hex color, font or radius that is not in the design system file.

---

## TASK T2: REGISTRATION CHAT (CHATBOT 1) AND SLOT LOCK

**Context:** T1 is done. This is T2 of 11. Re-read the design system.

**Objective:** Build Chatbot 1 and the slot check.

**Scope:**
1. **Chat UI (React, Inertia page at `/`, which is the QR landing page)** built from the design system `wb-*` components: shell, blue header with "WedWorks Assistant", AI and user bubbles, typing indicator, input bar, quick replies and chips where the prototype uses them.
2. **7 questions, scripted (no AI yet).** Use the exact wording and the 12 business types from `docs/chatbot.html`: name, email, mobile, business name, business type, city/province, social link (optional).
3. **Validation** in the browser and again on the server: email format, Philippine mobile format and length, business type must be one of the 12, social link must be a valid URL if given.
4. **Review card** (design system "Review & edit" pattern): all 7 answers, each with an Edit action that jumps to that one question and returns to the review card. A "Submit" action.
5. **`SlotService`** called on submit:
   - Inside one DB transaction with a **row lock** on the campaign row, increment `slots_taken`.
   - Slot 1 to `slot_limit`: create the supplier as `invited`, set `slot_number`, a random `invite_token`, `invited_at`, and `invite_expires_at = now + invite_expiry_hours`.
   - Slot above the limit: create as `waitlisted` with a `waitlist_number`, no token.
   - Duplicate email or mobile in the campaign: do not create a supplier and do not use a slot. Return an "already registered" result.
   - Write a `status_history` row (actor `system`).
6. **Result cards** after submit, using the design system status and confirmation cards:
   - Invited: "Thank you, check your email."
   - Waitlisted: the same "Thank you, check your email" wording as invited (do not reveal the difference in the chat), per the current prototype behavior.
   - Already registered: a clear message.
7. Draft answers live in the browser session until submit. No extra table.

**Constraints:** No emails yet (T3). No AI wording yet (T9). No changes to T1 migrations. Slot logic only in `SlotService`.

**Deliverable:** The chat page, `SlotService`, Form Request, result cards, and feature tests.

**Acceptance Criteria:**
- Submitting 12 test registrations produces exactly 10 `invited` and 2 `waitlisted`, with slots 1 to 10 and waitlist numbers 1 and 2.
- Two simultaneous submissions never produce a duplicate or skipped slot (test with parallel requests).
- A duplicate email or mobile creates no supplier and consumes no slot.
- Invalid email, mobile or business type is rejected by the server.
- Edit on one answer returns to the review card with all other answers kept.
- UI matches the design system with no invented values.

---

## TASK T3: EMAILS (INVITE, WAITLIST, AND THE SENDING SYSTEM)

**Context:** T1 and T2 done. This is T3 of 11. Re-read the email templates file.

**Objective:** Build the email system and send the first two emails.

**Scope:**
1. A reusable `EmailService` that sends a Mailable only if no `email_logs` row exists for that supplier and type (except `failed`). It logs `sent` or `failed` with a timestamp. A failed send must not crash the request.
2. Mailables built from `docs/WedWorks_Email_Templates.html` (reuse the exact HTML structure, header, card, button and row styles):
   - **Invitation:** private link `/invite/{token}`, the ₱10,000 website package, the deadline, a "Continue to Website Chatbot" button.
   - **Waiting list:** says the supplier is on the list with their waitlist number, and that spots do not reopen (decision D9). Use plain, kind wording.
3. Send the right email automatically right after `SlotService` assigns the status (invited or waitlisted).
4. Placeholder routes `/invite/{token}` that only check the token and show the supplier's status. The real chat comes in T5.
5. Mail config through `.env`. Provide a log-based mailer for local testing.

**Constraints:** Do not send any other email type yet. Do not change slot logic. Do not design a new email layout.

**Deliverable:** `EmailService`, the two Mailables, hook after slot assignment, token route, tests.

**Acceptance Criteria:**
- Each supplier receives exactly one email of the correct type. Re-triggering does not send a second one.
- A failed send is logged as `failed` and does not break registration.
- The invitation email shows the correct deadline (`invite_expires_at`) and a working private link.
- The emails match the template structure and styles. Mobile layout works.

---

## TASK T4: INVITE EXPIRY (SCHEDULER)

**Context:** T1 to T3 done. This is T4 of 11.

**Objective:** Expire invites after 24 hours, and send the reminder and expired emails.

**Scope:**
1. An Artisan command `invites:process` registered in the Laravel scheduler to run **every minute**.
2. For suppliers in `invited`, `details_submitted` or `payment_pending` whose `invite_expires_at` has passed:
   - Skip a supplier who has a payment with `started_at` before the deadline that is still `pending` and within the grace window (they may still pay, per D6).
   - Otherwise set status `expired`, write `status_history` (actor `system`), and send the **Invitation expired** email (template exists).
3. **Payment reminder:** about 2 hours before `invite_expires_at`, send the **Payment reminder** email once (template exists) to suppliers who have not paid.
4. Enforce expiry everywhere a supplier uses their token: an `expired` supplier sees the expired card (design system status card) and cannot continue.
5. Add the cPanel cron line to the docs: `* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1`.

**Constraints:** Do not change email templates. Do not free or reuse slots. No waitlist promotion.

**Deliverable:** The command, scheduler entry, expired card, tests, cron documentation.

**Acceptance Criteria:**
- Time-travel tests: a supplier invited 24h+ ago becomes `expired` and gets one expired email.
- A supplier with a payment started before the deadline and still pending is not expired until the grace window ends.
- The reminder is sent once, about 2 hours before the deadline, only to unpaid suppliers.
- An expired supplier cannot open the chat or create a payment.
- Running the command twice does not send duplicate emails.

---

## TASK T5: WEBSITE-DETAILS CHAT (CHATBOT 2) AND UPLOADS

**Context:** T1 to T4 done. This is T5 of 11. Re-read the design system.

**Objective:** Build Chatbot 2, the review card and uploads.

**Scope:**
1. Chat page at `/invite/{token}` for a valid, non-expired supplier in `invited` (or `details_submitted` if returning). Same shell and components as Chatbot 1. Show the invite countdown using the design system "Invite & deadline" pattern (`tabular-nums`).
2. **8 questions = 6 common + 2 by business type.** Use the exact wording, options and the business-type branches from `docs/chatbot.html` (what makes you special, services and prices, areas served and booking, logo/photos/colors, website style, anything else, plus the two type-specific questions).
3. **Uploads:** logo and photos, using the design system attach control in the input bar.
   - Allow images only (jpg, png, webp). Set sane limits: max 5 MB per file, 1 logo and up to 10 photos. Put the limits in config.
   - Store in `storage/app/public/uploads/{supplier_id}` and create `uploads` rows. Run `storage:link` instructions in the docs.
   - Validate type, size and count on the server. Reject anything else.
4. **Review card** with all 8 answers and **per-answer Edit** (reuse the Chatbot 1 review component, not a restart-all flow). A "Confirm" action.
5. On confirm: save to `website_answers` (json), set `submitted_at`, set status `details_submitted`, write `status_history` (actor `supplier`), and go to the payment step (T6).
6. Draft answers persist in the browser session until confirm. If the supplier returns, show their saved answers.

**Constraints:** No AI wording yet (T9). No payment yet (T6). Do not allow edits after `locked_at` is set. Do not touch emails.

**Deliverable:** Chat page, upload endpoints, review card, tests.

**Acceptance Criteria:**
- A valid invited supplier completes all 8 answers and reaches `details_submitted`.
- Per-answer edit keeps the other answers.
- A non-image or oversized file is rejected by the server. An 11th photo is rejected.
- An expired or invalid token cannot open the chat.
- Uploads are stored under the supplier's folder with matching `uploads` rows.
- UI matches the design system with no invented values.

---

## TASK T6: HITPAY PAYMENT, WEBHOOK, GRACE RULE

**Context:** T1 to T5 done. This is T6 of 11. Re-read the design system payment pattern. **Use HitPay sandbox.**

**Objective:** Real payment flow with a safe webhook.

**Scope:**
1. **Payment page** using the design system Payment card: amount (`wb-amount`), business name, time left on the invitation, a "Pay ₱10,000" primary button, and a "Pay later" ghost option.
2. **`HitPayService`:** create a payment request for the campaign price, store a `payments` row (`pending`, `started_at`, `expires_at = started_at + 30 minutes`, `checkout_url`, `hitpay_request_id`), and set the supplier to `payment_pending`. A new link marks any older pending link as `expired` (rule 5).
3. A link can be created **only while the invite is valid** (rule 6). Otherwise show the expired card.
4. **Webhook `POST /webhooks/hitpay`:**
   - Verify the HitPay signature. Reject invalid ones with 401 and do nothing else.
   - **Idempotent** on `hitpay_request_id`. A repeated call must not process twice.
   - On success: mark the payment `completed` with `paid_at`; apply the **grace rule** (rule 9) and set `paid_late` if it landed after the deadline; set supplier `paid`; create the `orders` row with a unique `KT10-xxxx` reference; set `locked_at` on the supplier's answers; write `status_history` (actor `hitpay`); send the **Payment receipt** email (template exists).
   - On failure or cancel: mark the payment `failed`, move the supplier back to `details_submitted`, send the **Payment unsuccessful** email.
5. **Retry:** "Try Again" creates a fresh link, only while the invite is valid.
6. **Pay later:** supplier can return to "My Website" (T8 will finish the page) and use "Resume Payment" until the invite window closes.
7. The browser return page from HitPay only shows status. It never marks anything paid.
8. Store the raw webhook payload in `payments.webhook_payload`.

**Constraints:** Never trust the redirect. Never mark paid without a verified webhook. Do not change earlier tasks except to link the flow. No refund system (a late payment past the grace window is flagged in the log only).

**Deliverable:** `HitPayService`, payment page, webhook controller, tests with signed sample payloads.

**Acceptance Criteria:**
- A sandbox payment moves the supplier to `paid`, creates one order with a unique reference, locks answers, and sends one receipt email.
- A webhook with a bad signature is rejected and changes nothing.
- Sending the same webhook twice produces one order and one email.
- A payment started before the deadline and completed within 30 minutes after is accepted with `paid_late = true`.
- A payment link cannot be created after the deadline.
- A failed payment returns the supplier to `details_submitted`, and retry works while the invite is valid.
- Answers cannot be edited after payment.

---

## TASK T7: HERMES API

**Context:** T1 to T6 done. This is T7 of 11.

**Objective:** Give the Hermes agents a safe, narrow API.

**Scope:**
1. Routes under `/api/hermes`, protected by a **Bearer token** middleware that checks `HERMES_API_TOKEN` (constant-time comparison). Missing or wrong token returns 401. Add a rate limit.
2. Endpoints:
   - `GET /api/hermes/orders?status=paid`: list orders waiting to be built (reference, business name, business type, status, paid_at).
   - `GET /api/hermes/orders/{reference}`: full details: supplier info, all website answers, and signed or public URLs for the uploaded files.
   - `POST /api/hermes/orders/{reference}/stage`: body `{ "stage": 1-6, "note": "optional" }`. Sets `orders.current_stage`, writes `hermes_stage_log`, and moves the supplier to `building` on the first stage.
   - `POST /api/hermes/orders/{reference}/deliver`: body `{ "website_url": "https://..." }`. Sets `delivered_at`, `website_url`, status `delivered`, and `status_history` (actor `hermes`).
3. **Rules:** Hermes can only touch orders that are `paid` or later (rule 11). `stage` can only move forward (the same or the next value, never backwards), range 1 to 6. `deliver` is only allowed when stage is 6. Invalid calls return clear 4xx JSON errors.
4. Write `docs/HERMES_API.md` explaining each endpoint with example requests and responses, for the person setting up the agents.

**Constraints:** Hermes never receives database credentials. No endpoint exposes unpaid suppliers. Hermes can only write stage and delivery data.

**Deliverable:** Middleware, controllers, API docs, tests.

**Acceptance Criteria:**
- Without a valid token every endpoint returns 401.
- `GET ...?status=paid` returns only paid orders. An unpaid supplier's reference returns 404.
- Stage updates 1 to 6 work in order. Moving backwards or above 6 is rejected.
- `deliver` before stage 6 is rejected. After stage 6 it marks the supplier `delivered`.
- Every change writes `hermes_stage_log` or `status_history` as specified.

---

## TASK T8: MY WEBSITE PAGE AND THE "LIVE" EMAIL

**Context:** T1 to T7 done. This is T8 of 11. Re-read the design system.

**Objective:** Let the supplier follow their order, and notify them when the site is live.

**Scope:**
1. **My Website page** at `/invite/{token}/my-website` (token-protected), built from design system cards and pills:
   - Before payment: current status and the "Resume Payment" option if still allowed.
   - After payment: the order reference, a confirmation summary, and a status card showing progress **1/6 to 6/6** (use the design system pills, amber for in progress and green for done).
   - When delivered: the live website URL as a primary button.
   - If expired: the expired card.
2. The page refreshes its status automatically (simple polling every 30 seconds is fine, no websockets).
3. **"Website live" email:** when `deliver` is called (T7), send it once via `EmailService`. The templates file has no "live" template, so **compose it from the existing email components** (header, card, label/value rows, pill button) without inventing styles, and flag it in your report.
4. Answers shown are read-only after payment, with a note that details are locked.

**Constraints:** No edit after payment. No new colors, fonts or radii. No websockets or extra packages.

**Deliverable:** My Website page, polling, live email, tests.

**Acceptance Criteria:**
- Each status shows the correct card and wording.
- Progress shows correctly for stages 0 to 6 and updates without a manual reload.
- The live email is sent once on delivery with the correct URL.
- Visiting with an invalid token shows nothing about any supplier.
- UI matches the design system with no invented values.

---

## TASK T9: AI-WORDED QUESTIONS

**Context:** T1 to T8 done. This is T9 of 11.

**Objective:** Add AI-reworded questions without changing how the data is collected.

**Scope:**
1. An `AiWordingService` with a single method that takes the scripted question text, the supplier's earlier answers (only the first name and business name), and returns a friendly rewording.
2. The provider and key come from `.env` (`AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL`). Default to the Anthropic Messages API with a small, fast model, but keep the service swappable.
3. **Safety and cost controls:**
   - Strict token cap per call and a cap on calls per chat session (put both in config).
   - A short timeout. On timeout, error or empty reply, **fall back to the scripted question automatically**.
   - The AI only rewrites wording. It never changes which field the answer maps to, never decides validation, and never asks extra questions.
   - Do not send emails, mobile numbers, or any sensitive data to the AI provider.
4. Apply it to both chatbots. Show the design system typing indicator while waiting.
5. A config switch `AI_WORDING_ENABLED=false` turns it off completely and uses scripted text.

**Constraints:** Do not change validation, slot logic, review cards, or data fields. Do not store AI responses beyond the displayed text.

**Deliverable:** `AiWordingService`, integration into both chats, config, tests with a fake provider.

**Acceptance Criteria:**
- With the provider mocked, the chat shows reworded questions and the answers still save to the correct fields.
- With the provider failing or slow, the scripted question appears and the flow is unaffected.
- With `AI_WORDING_ENABLED=false`, no AI call is made.
- Token and call caps are enforced.
- Logs show no email or mobile number sent to the provider.

---

## TASK T10: CPANEL DEPLOYMENT

**Context:** T1 to T9 done. This is T10 of 11.

**Objective:** Prepare the project for production on cPanel and write the checklist.

**Scope:**
1. Production build: `npm run build`, and document how to upload the compiled `public/build` assets (Node is not available on the server).
2. Document the cPanel folder layout: Laravel outside `public_html`, the `public/` contents (or a symlink) in the web root, and the `index.php` path fix.
3. Write `docs/DEPLOYMENT.md` with:
   - `.env` production values (never committed): app URL, DB, mail, HitPay live keys and webhook salt, AI keys, `HERMES_API_TOKEN`.
   - `php artisan key:generate`, `migrate --force`, `db:seed` (campaign only), `storage:link`, `config:cache`, `route:cache`.
   - The cron job line for the scheduler (every minute).
   - The HitPay webhook URL to register: `https://<domain>/webhooks/hitpay`.
   - The Hermes API base URL and token handover for the Mac mini agents.
   - PHP version and extensions needed, upload size limits (`upload_max_filesize`, `post_max_size`) to fit the photo limits.
   - A rollback procedure.
4. Security checks: `APP_DEBUG=false`, HTTPS enforced, `.env` and `storage` not web-accessible, CORS closed, rate limits on registration and the Hermes API.
5. Provide a one-page **go-live checklist** and a **post-launch** list.

**Constraints:** Do not change application logic. Do not commit secrets.

**Deliverable:** `docs/DEPLOYMENT.md`, a go-live checklist, and any minimal config changes for production.

**Acceptance Criteria:**
- A person with no Laravel experience can follow `DEPLOYMENT.md` start to finish.
- A production-mode run (`APP_DEBUG=false`) works with cached config and routes.
- The scheduler runs from cron and the invite command executes.
- A sandbox payment against the staging URL completes end to end.

---

## TASK T11: END-TO-END QA

**Context:** T1 to T10 done. This is T11 of 11. This task changes no features.

**Objective:** Verify the whole journey and report.

**Scope:** Run and report each scenario, with a pass or fail and evidence:
1. Happy path: QR to register to invite email to details to pay to paid to Hermes stages 1 to 6 to delivered to live email.
2. 12 registrations: 10 invited, 2 waitlisted with the right emails.
3. Simultaneous registrations at slot 10: no duplicate slot.
4. Duplicate email and duplicate mobile.
5. Invite expiry at 24h, the 2-hour reminder, and the expired email.
6. Payment started before the deadline and completed within the grace window (accepted, `paid_late`).
7. Payment link attempt after the deadline (blocked).
8. Failed payment, retry, and pay later.
9. Duplicate and forged webhooks.
10. Edit after payment (blocked).
11. Hermes API: bad token, unpaid order, backward stage, early deliver.
12. AI provider down (fallback works).
13. Mobile and desktop layout against the design system.
14. Emails render on mobile and desktop.

**Constraints:** Fix only genuine bugs, one at a time, and list each fix. Do not add features.

**Deliverable:** `docs/QA_REPORT.md` with the table of scenarios, results, bugs found and fixed, and anything left open.

**Acceptance Criteria:** All scenarios pass, or each failure is listed with its cause and a decision for the owner.

---

## WHEN ALL TASKS ARE DONE

Reply with a final summary: what is live, what is simulated or missing, open risks, and a short list of post-launch improvements (not built): waitlist promotion, admin view, extra email reminders, refund handling.
