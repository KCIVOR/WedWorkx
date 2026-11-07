# WedWorks 10-10-10: Go-live checklist (one page)

Details for each line are in [DEPLOYMENT.md](DEPLOYMENT.md). Tick every box before the QR code goes on display.

## Server
- [ ] PHP 8.2 or newer chosen; extensions ticked (`curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `dom`, ...)
- [ ] `upload_max_filesize = 8M`, `post_max_size = 12M`
- [ ] HTTPS works (AutoSSL) and `http://` jumps to `https://`
- [ ] App folder is **outside** the web folder (or the domain's document root is `wedworks/public`)
- [ ] `storage/` and `bootstrap/cache/` are writable

## Settings (`.env` on the server)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`
- [ ] `APP_KEY` created once (`php artisan key:generate --force`)
- [ ] Database name, user, password filled in
- [ ] SMTP filled in, and a test email arrived (not in spam)
- [ ] HitPay **live** key, salt and `HITPAY_BASE_URL=https://api.hit-pay.com/v1`
- [ ] `HERMES_API_TOKEN` set (long random) and handed to the Mac mini safely
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `DEV_MODE=false` (or absent). It is ignored in production anyway, but the live `.env` must not mention `true`
- [ ] `php artisan config:cache` run after the last change to `.env`

## Setup commands (once)
- [ ] `php artisan migrate --force`
- [ ] `php artisan db:seed --force` (campaign only: 10 slots, 24 h, 30 min grace, ₱10,000)
- [ ] `php artisan storage:link` (Option B: link made in `public_html`)
- [ ] `php artisan admin:create <email>` for each person who may see the admin (password 12+ characters)
- [ ] `php artisan config:cache`, `route:cache`, `view:cache`
- [ ] `public/build` is on the server (the site has styling)

## Scheduler
- [ ] cPanel cron job every minute: `cd /home/USER/wedworks && php artisan schedule:run >> /dev/null 2>&1`
- [ ] `php artisan invites:process` runs without errors

## Connections
- [ ] HitPay webhook registered: `https://<domain>/webhooks/hitpay` (event `payment_request.completed`)
- [ ] Hermes check works: `curl -H "Authorization: Bearer $TOKEN" https://<domain>/api/hermes/orders` returns `{"data":[]}`

## Admin area ([ADMIN.md](ADMIN.md))
- [ ] Signed in at `https://<domain>/admin` (or your `ADMIN_PATH`); Overview shows 0 / 10 slots
- [ ] Settings: test email arrived, HitPay "Check the key" says accepted, Hermes token created and handed over (shown once)
- [ ] Campaign values are right (price, hours, grace). They lock after the first registration
- [ ] Without signing in, `/admin/suppliers` goes to the login page

## Security checks
- [ ] `/invite/not-real` shows a plain "Not Found" (no code, no stack trace)
- [ ] `https://<domain>/.env` is 403 or 404
- [ ] No `Access-Control-Allow-Origin` header on any response
- [ ] A forged webhook returns 401
- [ ] Sandbox keys and test tokens are not on the live server

## Dress rehearsal (staging, HitPay sandbox)
- [ ] Register, receive the invitation, answer all the questions with uploads, confirm
- [ ] Open Admin > Questions and check the wording, order and who each question is asked to
- [ ] If Hermes will run the conversations: set up the bridge (`docs/HERMES_BRIDGE.md`) with a fast model, then: set "Who asks the questions" in Admin > Settings, start the agent, confirm it shows "Hermes online", run one registration and one website chat, then stop the agent and confirm a visitor still gets the fixed chat (and an entry on Activity)
- [ ] Pay with the sandbox, My Website shows "Payment confirmed" by itself, receipt email arrives
- [ ] Hermes stages 1 to 6 and deliver work; live email arrives; My Website shows 6/6 and the link
- [ ] Expiry works from cron (expired status and email)
- [ ] Staging data removed; live `.env` has the live values

## Launch day
- [ ] Database backup taken
- [ ] Campaign row correct: `slot_limit` 10, `slots_taken` 0
- [ ] Open the QR link on a phone over mobile data and register once, then delete that test supplier
- [ ] Previous release zip kept for rollback

---

# After launch

**First 24 hours**
- Watch `wedworks/storage/logs/` for errors, and check the inbox of the sending email account for bounces.
- Search the log for `HitPay payment received but not applied`. Each one is money that needs a manual refund (a late or double payment).
- Check HitPay's dashboard against the `payments` table: every completed payment should have an order.
- Confirm the cron job still runs (invitations expire, reminders go out).

**Every week**
- Download a database backup (cPanel Backup) and keep a copy off the server.
- Look at disk use of `storage/app/public/uploads` (photos).
- Check mail deliverability (cPanel Email Deliverability).

**When the campaign ends**
- Rotate `HERMES_API_TOKEN` if an agent changes hands, and remove it from machines no longer used.
- Keep the database and uploads for as long as the delivered websites need them.

**Ideas that were deliberately not built (for later)**
- Waitlist promotion (decision D9 says spots never reopen)
- Extra reminder emails
- Refund handling in the app (today: log line and a manual refund in HitPay)
- A Content-Security-Policy header and a stricter session setup
- Per-account (not per-IP) rate limits; a nicer "payment is taking a while" notice
