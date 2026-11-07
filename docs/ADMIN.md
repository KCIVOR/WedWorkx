# Admin area

A private area for the owner to see everyone who registered, how far each supplier has got, and to set the keys (email, HitPay, Hermes, AI) without editing `.env`.

Address: `https://<domain>/admin` (change the path with `ADMIN_PATH` in `.env`, then `php artisan config:cache`).

## Create the admin (once)

There is no sign-up page. On the server (or locally):

```
php artisan admin:create you@example.com --name="Your Name"
```

It asks for a password twice (at least 12 characters, typed hidden). Running it again for the same email **resets that password**. Make one account per person; every account sees everything.

## Signing in

- Wrong email or wrong password gives the same message. After 5 wrong tries in a minute (per email and IP) sign-in is blocked for a minute.
- Idle for 60 minutes (`ADMIN_IDLE_MINUTES`) signs you out.
- Admin pages are never cached and carry `X-Robots-Tag: noindex`. A visitor who is not signed in cannot tell a real supplier address from a fake one: both go to the login.

## The pages

| Page | What it shows |
|---|---|
| **Overview** | Winner slots (n / 10) and waiting-list count, money collected, how many are in each status (click one to filter), and **Needs attention**: money received but no order (late or repeated payments to refund), failed emails, invitations expiring within 2 hours |
| **Suppliers** | Everyone, winners first (slot 1 to 10) then the waiting list. Search (name, business, email, mobile), filters (status, winners or waiting list, business type), 25 per page, **Download CSV** that follows the filters. Columns: spot, supplier, contact, registered, status, answers, payment, website stage, emails |
| **A supplier** | Profile, progress (deadline, answers, payment, `KT10-xxxx` reference, stage 0 to 6, live link), the 8 answers with their questions, uploads, every payment attempt, status history, Hermes stage notes, and each email with **Send again** when the first attempt failed |
| **Questions** | Both chatbots, on two tabs: **Registration chat** (Bot 1, asked when someone scans the QR code) and **Website chat** (Bot 2, asked after they are invited). Add, edit, delete (restorable) and reorder questions. See below |
| **Settings** | Email (SMTP), HitPay, Hermes token, AI wording, Campaign. See below |
| **Activity** | What admins did and when: sign-ins, failed sign-ins, settings saved, exports, resent emails. Never any values |

The invitation link (token) of a supplier is never shown in the admin.

## Questions

The page has two tabs, one per chatbot. Each tab shows how many questions it has and when suppliers see them.

### Registration chat (Bot 1)

The questions asked when someone scans the QR code. They start as the original 7 and are stored in the database.

- **Core questions** (name, email, mobile, business name, business type, location, social link) fill the supplier's own details, so they cannot be deleted, retyped or made optional. You can change their wording, name and hint, and move them in the order.
- **Extra questions you add** (short answer, number or choice, optional or required) are saved on the supplier together with the question's wording at that time. They show on the supplier's page under Profile, and Hermes gets them as `registration_answers`.
- Up to 12 questions in total (`admin.max_registration_questions`). `{name}` in a question is replaced with the supplier's name once they have given it, so keep the name question before any question that uses it.
- Delete hides a question; "Deleted questions" restores it. Answers suppliers already gave are kept. A question that has been answered cannot change type, and a chosen option cannot be removed.
- The CSV export does not include the extra answers (it still has the 7 core details).

### Website chat (Bot 2)

The questions Chatbot 2 asks after a supplier is invited. They start as the original 8 (6 for everyone plus 2 for each business type) and are stored in the database, so you can change them without a developer.

- **Add**: short answer, long answer, number or choice (2 to 12 options). Choose "every business type" or only some. New questions go to the end; use **Up** and **Down** to move them. You can write `{business_name}` in the question and it is replaced with the supplier's business name.
- **Needed for the site** (cannot be deleted, retyped, made optional or limited to some business types; their wording can be edited): Business Description, Services / Packages, Service Areas & Booking, Photos / Logo / Colors, Website Style. Hermes and the chat depend on them.
- **Delete** hides a question (it is not erased). The "Deleted questions" box restores it. Answers suppliers already gave are kept.
- **Suppliers who already answered**: once a supplier confirms, a copy of the questions they saw is saved with their answers. Editing or deleting a question later does not change what their page, the admin and Hermes show. If a question's type has been answered, the type is fixed, and an option a supplier chose cannot be removed. Wording can always change.
- A supplier who confirmed before you added a question is not asked it (and can still pay). Hermes simply has no answer for it.
- At most 20 questions for any one business type (`admin.max_questions`). Two questions that can reach the same business type cannot store their answer in the same place; the page tells you if you try.
- The supplier sees an edit on their next visit to the chat. A supplier in the middle of the chat should reload.
- Hermes gets a `questions` list with each label and answer, so it never has to guess what a key means (see `docs/HERMES_API.md`).

## Settings

Saved values are stored **encrypted** (with `APP_KEY`) in the `settings` table and **win over `.env`**. Remove a saved value and the `.env` value is used again. Each field shows where its value comes from: *Saved here*, *From .env* or *Not set*.

- **Secrets** (SMTP password, HitPay key and salt, Hermes token, AI key) are never sent back to the browser. A blank box means "keep what is saved". Tick "Remove the saved value" to go back to `.env`.
- **Email**: host, port, SSL or TLS, username, password, from address and name. **Send a test email to me** sends to the signed-in admin's address and shows the real error if it fails. Saving a host switches the mailer to SMTP.
- **HitPay**: key, salt, API address (sandbox or live). Shows the webhook address to paste into HitPay. **Check the key** makes one read-only request to HitPay.
- **Who asks the questions**: for each chat (registration, website) choose "The system" (the fixed questions) or "The Hermes agent" (a conversation, see `docs/HERMES_CHAT.md`). The card shows whether Hermes is listening. If Hermes is off or silent the fixed chat takes over without the supplier noticing, and **Activity** records "Hermes unreachable (fixed chat used)".
- **Hermes**: **Create a token** makes a new 64-character token and shows it **once**. The old token stops working at once. Give the new one to the agents. The token cannot be typed in, only generated.
- **AI wording**: on or off, key, model, and a check button.
- **Campaign**: price, invitation hours, grace minutes. **Locked once anyone has registered**, so the deal stays the same for everyone. The slot limit (10) is not editable.
- **Read only**: the environment and whether dev mode is on.

What stays in `.env` only: database details, `APP_KEY`, `APP_ENV`/`APP_DEBUG`/`APP_URL`, session settings, and `DEV_MODE` (so dev mode can never be switched on from a web page in production).

## If something goes wrong

- **Locked out**: run `php artisan admin:create <email>` again to set a new password.
- **Changed `APP_KEY`**: saved secrets can no longer be decrypted and show as *Not set*. Enter them again.
- **A saved value is wrong and email or payments stopped**: clear it on the Settings page (or `DELETE FROM settings WHERE key = '...'` in the database) to fall back to `.env`.

## Limits

- Every admin account has full access (no roles).
- No two-factor sign-in (decision for this build). Use a long password, keep `ADMIN_PATH` unguessable if you like, and keep the server on HTTPS only.
- The HitPay "Check the key" request (`GET /payment-requests`) is not confirmed against the real HitPay API yet. A rejected key shows a clear message; an unexpected answer shows the HTTP code.
- The page shows the data as it was when loaded; reload to refresh.
- Questions: the preview is a list of what a business type sees, not an interactive copy of the chat. Services, Service areas and Photos questions exist once each and cannot be added again.
