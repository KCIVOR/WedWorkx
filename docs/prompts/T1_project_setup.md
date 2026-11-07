# WedWorks Chatbot: Build Prompt T1 (Project setup, database, design tokens)

Paste everything below the line into your AI coding tool. One task only. Do not add extra features.

---

## DESIGN SYSTEM RULE (applies to ALL tasks, not just this one)

The file `docs/WedWorks_Chatbot_Design_System.html` ("Bold") is the **single source of truth for every UI element**. This is a strict rule:

1. **Read that file first, before writing any UI or styling code.** Do not work from memory or from generic defaults.
2. **Use only its tokens.** No hex color, font, radius, shadow, spacing value or animation timing may be invented. If a value is not in the design system, stop and ask instead of improvising.
3. **Reuse its component specs exactly.** Chat components use the `wb-*` names and values in the file (shell, header, message bubbles, quick replies, chips, input bar, cards, review rows, payment rows, notes, alerts, pills, status cards, countdown, slot meter, toast). Rebuild them as React components that match the file's CSS values one to one.
4. **Status colors (green, amber, red) are only for state**, never decoration.
5. **Mobile first**, desktop ready. Light theme only. Message text never below 14px. Use `tabular-nums` for times, amounts and countdowns.
6. **Respect `prefers-reduced-motion`** as the file does.
7. **Emails** must reuse the HTML structure and styles in `docs/WedWorks_Email_Templates.html` (royal blue header, white card, pill buttons, same label/value rows). Do not design new email layouts.
8. If a new screen needs something the design system does not show, compose it from existing components and flag it in your report. Do not invent a new style.

Any output that breaks this rule is rejected and must be redone.

---

## Context
We are building the WedWorks 10-10-10 Campaign supplier chatbot for Kasalang Tagaytay. Stack: Laravel 11 (PHP 8.2+), MySQL, Inertia + React, Tailwind CSS, deployed on cPanel shared hosting. The folder `C:\Users\Rovick\Desktop\wedworks_chatbot` currently holds only prototype docs (`docs/chatbot.html`, the design system, the email templates, the journey flowchart). This is a fresh build. Current phase: **T1 of 10**.

## Objective
Create the project skeleton, the database, and the design tokens. Nothing else.

## Scope
1. **Project:** Install Laravel 11 with Inertia (React) and Tailwind via Vite. Use the folder `app/` at the project root (create it if needed, keep `docs/` untouched).
2. **Tailwind theme from the design system.** Read the design system file and map its `:root` tokens into `tailwind.config` and a base CSS file:
   - Colors: all blue, navy, slate, green, amber and red values, plus the semantic aliases (`wb-accent`, `wb-accent-hover`, `wb-canvas`, `wb-surface`, `wb-text`, `wb-heading`, `wb-muted`, `wb-line`, `wb-ai-bg`, `wb-ai-line`, `wb-user-bg`).
   - Fonts: DM Sans (display), Inter (body), JetBrains Mono (mono), loaded from Google Fonts as the file does.
   - Radius: `r-sm`, `r-field`, `r-md`, `r-card`, `r-shell`, `r-bubble`, `r-tail`, `r-pill`.
   - Spacing: the 4px scale.
   - Shadows: `sh-sm`, `sh-md`, `sh-lg`, `sh-blue`.
   - Motion: the easing and the three durations.
   - Base styles from the file's body, heading and focus-visible rules, and the reduced-motion block.
3. **Migrations** (use the Phase 3 data model exactly):
   - `campaigns`: id, name, slot_limit, slots_taken, invite_expiry_hours, grace_minutes, price, timestamps.
   - `suppliers`: id, campaign_id, slot_number (nullable), waitlist_number (nullable), name, email, mobile, business_name, business_type, city, province, social_link (nullable), status, invite_token (unique, nullable), invited_at, invite_expires_at, timestamps. Unique(campaign_id, email) and unique(campaign_id, mobile).
   - `website_answers`: id, supplier_id (unique), answers (json), submitted_at, locked_at, timestamps.
   - `uploads`: id, supplier_id, kind (`logo` or `photo`), path, original_name, mime, size_kb, timestamps.
   - `payments`: id, supplier_id, hitpay_request_id (unique), amount, checkout_url, status (`pending, completed, failed, expired`), started_at, expires_at, paid_at (nullable), paid_late (boolean, default false), webhook_payload (json, nullable), timestamps.
   - `orders`: id, supplier_id (unique), reference (unique, format `KT10-xxxx`), current_stage (0 to 6, default 0), website_url (nullable), paid_at, delivered_at (nullable), timestamps.
   - `status_history`: id, supplier_id, from_status (nullable), to_status, actor (`supplier, system, hitpay, hermes`), created_at.
   - `email_logs`: id, supplier_id, type (`registered, invite, reminder, receipt, failed, updated, waitlist, expired, live`), sent_at, status.
   - `hermes_stage_log`: id, order_id, stage, note (nullable), created_at.
4. **Enums:** a `SupplierStatus` PHP enum with exactly: `waitlisted, invited, expired, details_submitted, payment_pending, paid, building, delivered`.
5. **Models** with relationships: Campaign has many Suppliers. Supplier has one WebsiteAnswer, many Uploads, many Payments, one Order, many StatusHistory. Order has many HermesStageLog.
6. **Seeder:** one campaign named "Kasalang Tagaytay 10-10-10", slot_limit 10, slots_taken 0, invite_expiry_hours 24, grace_minutes 30, price 10000.
7. **Config:** read expiry and grace from the campaign row, not hard-coded. Put `.env.example` entries for HitPay, mail, and the Hermes API token with empty values.
8. **One proof page:** a single Inertia page at `/design-check` that renders a handful of components using only the new Tailwind tokens (a header, an AI bubble, a user bubble, a primary pill button, a card, a status pill). Its only job is to prove the tokens match the design system file visually.

## Constraints
- **Do not** build the chat, slot logic, emails, payment, API, or any real screen. Those are later tasks.
- **Do not** change anything inside `docs/`.
- **Do not** add packages beyond Laravel, Inertia, React, Tailwind, Vite and their standard dependencies.
- **Do not** invent any style value. Every color, size and radius must come from the design system file.
- Use the exact table and column names above.

## Deliverable
- A working Laravel project with the migrations, models, enum, seeder and Tailwind theme.
- The `/design-check` page.
- A short report listing: files created, any design-system value you could not map, and any assumption you made.

## Acceptance Criteria
- `php artisan migrate --seed` succeeds on a clean MySQL database.
- Inserting two suppliers with the same email (or mobile) in the same campaign is rejected by the database.
- The `SupplierStatus` enum has exactly the eight statuses.
- `npm run build` succeeds.
- The `/design-check` page visually matches the design system: royal blue `#2F5BEA` header with the 24px curve, pale blue AI bubble with the 4px tail, solid blue user bubble, pill button with the soft blue shadow, DM Sans titles and Inter body.
- A search of the code finds no hex color, font or radius that is not in the design system file.
