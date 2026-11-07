# Deploying WedWorks to cPanel

This guide takes you from the project folder on your computer to a live site on cPanel shared hosting. You do not need to know Laravel. Follow the parts in order, and copy the commands exactly.

Words used here:
- **The app folder** is where the program lives on the server, for example `/home/YOURUSER/wedworks`. It is **outside** the web folder, so nobody can download your `.env` or your code.
- **The web folder** is what the internet can see (`public_html`, or the site's own folder).
- **Terminal** is cPanel's command box (cPanel home, "Terminal"), or SSH if your host gives you that. A few steps need it. See "If you have no Terminal" at the end of part 4.

Print the one-page [go-live checklist](GO_LIVE_CHECKLIST.md) and tick it off as you go.

---

## 1. What the server needs

In cPanel open **MultiPHP Manager** and choose **PHP 8.2 or newer** (8.3 is what the app was built and tested on) for the domain.

In **Select PHP Version → Extensions**, make sure these are ticked: `ctype`, `curl`, `dom` (also called `xml`), `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`.

In **MultiPHP INI Editor** set these, so the photo limits fit (each photo is at most 5 MB and is uploaded one at a time):

```
upload_max_filesize = 8M
post_max_size       = 12M
memory_limit        = 256M
max_execution_time  = 60
```

You also need: a MySQL database, an email account for sending (SMTP), HTTPS (cPanel "SSL/TLS Status" → run AutoSSL), and a domain or subdomain, for example `10-10-10.yourdomain.com`.

---

## 2. Build the release on your computer

Node.js does not run on the server, so the website's CSS and JavaScript are built on your computer and uploaded.

Open PowerShell in the folder that contains `wedworks_chatbot`, then:

```powershell
# 1) Build the CSS and JavaScript (creates public\build)
cd wedworks_chatbot
npm ci
npm run build
php artisan optimize:clear
cd ..

# 2) Copy the project to a clean release folder, without development files, secrets or uploads
$src = (Resolve-Path wedworks_chatbot).Path
robocopy $src wedworks_release /MIR /XJ /XD node_modules vendor .git tests docs "$src\public\storage" "$src\storage\app\public\uploads" /XF .env *.log *.sqlite cloudflared.exe README.md /NFL /NDL /NJH /NJS

# 3) Install only the production PHP packages in the release folder
cd wedworks_release
composer install --no-dev --optimize-autoloader --no-interaction
cd ..

# 4) Zip it
Compress-Archive -Path wedworks_release\* -DestinationPath wedworks_release.zip -Force
```

(`robocopy` prints a message and exit code up to 7 when it worked. That is normal.)

Check that `wedworks_release\public\build\manifest.json` exists. Without it the site shows no styling.

Never put these in the zip: `.env`, `node_modules`, `.git`, `tests`, any uploaded photos, any log file.

---

## 3. Put the files on the server

### Layout

```
/home/YOURUSER/
├── wedworks/                  ← the app folder (everything from the zip)
│   ├── app/  bootstrap/  config/  database/  routes/  vendor/ ...
│   ├── .env                   ← created in part 4, never uploaded in the zip
│   ├── storage/               ← logs, sessions, uploaded photos (not reachable from the web)
│   └── public/                ← the only part the web should see
└── public_html/               ← the web folder
```

1. In cPanel **File Manager**, go to `/home/YOURUSER/`, upload `wedworks_release.zip`, then right-click it and **Extract** into a new folder called `wedworks`. Delete the zip afterwards.
2. Make sure `wedworks/storage` and `wedworks/bootstrap/cache` can be written to (File Manager → right-click → Change Permissions → `755`; use `775` if the site later complains it cannot write).

### Point the website at the `public` folder (choose A or B)

**Option A, recommended (nothing to edit).** Create the site on its own subdomain or addon domain. In cPanel **Domains** (or Subdomains), set that domain's **Document Root** to `wedworks/public`. Done.

**Option B (the site must live in `public_html`).** Copy everything inside `wedworks/public/` (including the hidden `.htaccess`, the `build` folder, `index.php`, `robots.txt`, `favicon.ico`) into `public_html/`. Then open `public_html/index.php` and change the two paths that point to the app, so they read:

```php
if (file_exists($maintenance = __DIR__.'/../wedworks/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../wedworks/vendor/autoload.php';

$app = require_once __DIR__.'/../wedworks/bootstrap/app.php';
```

Every time you update the site later, copy `public/build` (and `index.php` only if it changed) into `public_html` again.

---

## 4. First-time setup on the server

### Create the database

cPanel → **MySQL Databases**: create a database, create a user with a strong password, and add the user to the database with **All Privileges**. cPanel adds a prefix, so the real names look like `youruser_wedworks`. Write down the database name, user and password.

### Create the `.env` file

In File Manager, open `wedworks/`, copy `.env.production.example` to a new file named `.env`, and fill in every value marked `CHANGE`. This file holds all your secrets. It is never committed anywhere and never leaves the server.

| Setting | What to put |
|---|---|
| `APP_URL` | `https://your-domain` (with https, no trailing slash) |
| `APP_DEBUG` | `false`. **Never `true` on the live site.** |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from the step above |
| `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_PORT`, `MAIL_SCHEME` | cPanel → **Email Accounts** → Connect Devices shows the SMTP details (usually port 465 with `smtps`) |
| `HITPAY_API_KEY`, `HITPAY_SALT` | HitPay **live** key and salt. The sandbox ones do not work on the live site. |
| `HITPAY_BASE_URL` | `https://api.hit-pay.com/v1` (live). For staging use `https://api.sandbox.hit-pay.com/v1` |
| `HERMES_API_TOKEN` | a long random token, see part 6 |
| `AI_*` | leave `AI_WORDING_ENABLED=false` unless you want the optional friendlier wording ([AI_WORDING.md](AI_WORDING.md)) |

### Run the setup commands

Open cPanel **Terminal** (or SSH) and run these one at a time. `cd` first, and check `php -v` shows 8.2 or newer. If it shows an older version, use your PHP 8 path in place of `php`, for example `/opt/cpanel/ea-php83/root/usr/bin/php`.

```bash
cd ~/wedworks
php -v

php artisan key:generate --force      # creates APP_KEY once. Never run it again later.
php artisan migrate --force           # creates the database tables
php artisan db:seed --force           # creates the campaign row only (safe to run twice)
php artisan storage:link              # makes uploaded photos viewable
php artisan admin:create you@example.com --name="Your Name"   # the admin login (asks for a password); see ADMIN.md

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Notes:
- **`key:generate` once only.** Changing `APP_KEY` later logs everyone out and breaks stored encrypted data.
- After **any** change to `.env`, run `php artisan config:cache` again, or the change is ignored. If you change `ADMIN_PATH`, also run `php artisan route:cache`.
- The admin area ([ADMIN.md](ADMIN.md)) is at `https://your-domain/admin`. Keys saved on its Settings page are stored encrypted and win over `.env`, so after the first sign-in you can enter SMTP, HitPay, Hermes and AI keys there instead of editing the file.
- **Always keep `config:cache` on in production.** It is also a safeguard: without it the site reads `.env` on every request, and on a server where PHP runs as a *threaded* Apache module, heavy simultaneous use (many sign-ups in the same second) can occasionally make a request see no settings and fail with a 500. With the config cached this never happened in testing (3 rounds of 24 simultaneous sign-ups, no errors). Most cPanel hosts do not use that setup, but the cached config removes the question.
- `db:seed` only creates the campaign "Kasalang Tagaytay 10-10-10" (10 slots, 24 hour invites, 30 minute grace, ₱10,000). Running it again never resets the slots already taken.
- **Option B only:** `storage:link` makes a link inside `wedworks/public`, which is not your web folder. Make the link where the web can see it. Create a file `public_html/mklink.php` containing the line below (use your real username), open it once in the browser, then **delete the file**:
  ```php
  <?php symlink('/home/YOURUSER/wedworks/storage/app/public', '/home/YOURUSER/public_html/storage'); echo 'done';
  ```

### Set up the cron job (needed for invite expiry and reminders)

cPanel → **Cron Jobs** → add a job, **Once Per Minute** (`* * * * *`), with this command (your real username and PHP path):

```
cd /home/YOURUSER/wedworks && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Details and how to check it are in [SCHEDULER.md](SCHEDULER.md). Without this job, invitations never expire and reminder emails never go out.

### If you have no Terminal

Some hosts switch Terminal off. You can run the same commands from your own computer against the server's database:
1. cPanel → **Remote MySQL**: add your computer's IP address.
2. On your computer, in the project folder, make a temporary file `.env.deploy` with the live database name, user and password and `DB_HOST=<your server's hostname>`, and run `php artisan migrate --force` and `php artisan db:seed --force` with those settings.
3. Create `APP_KEY` on your computer with `php artisan key:generate --show` and paste it into the server's `.env`.
4. Do not use `config:cache` or `route:cache` from your computer (they bake in your computer's paths). The site works without them, just a little slower.
5. Delete `.env.deploy` and remove your IP from Remote MySQL afterwards.

---

## 5. Connect HitPay (payments)

1. Make sure `HITPAY_API_KEY`, `HITPAY_SALT` and `HITPAY_BASE_URL` in the server's `.env` are the **live** values, then run `php artisan config:cache`.
2. In the HitPay dashboard open Developers → **Webhook Endpoints** and add this address, for the event `payment_request.completed` (and the failed-payment event if it is offered):

   ```
   https://<your-domain>/webhooks/hitpay
   ```
3. Details of how a payment flows, and what to do about a late payment, are in [PAYMENTS.md](PAYMENTS.md).

HitPay needs to reach your site from the internet, so a staging site must be on a real public address (a free subdomain works).

---

## 6. Connect the Hermes agents (Mac mini)

- **Base URL:** `https://<your-domain>/api/hermes`
- **Token:** make a long random one on your computer, with `php -r "echo bin2hex(random_bytes(32));"`, put it in the server's `.env` as `HERMES_API_TOKEN`, run `php artisan config:cache`, and give the **same** value to the agents.
- **Hand it over safely:** use a password manager or hand it over in person. Do not email it or paste it in a chat. The agents keep it in their own private settings and send it as `Authorization: Bearer <token>`.
- **Check it works** (from the Mac mini):

  ```bash
  curl -s -H "Authorization: Bearer $TOKEN" "https://<your-domain>/api/hermes/orders?status=paid"
  ```
  A list (even an empty one, `{"data":[]}`) means it works. `{"error":"unauthorized"}` means the token is wrong or `config:cache` was not run after setting it.
- The person who sets up the agents needs [HERMES_API.md](HERMES_API.md). It never gives Hermes any database access.

---

## 7. Send a test email

```bash
cd ~/wedworks
php artisan tinker --execute="Mail::raw('WedWorks test', fn (\$m) => \$m->to('you@example.com')->subject('WedWorks test'));"
```

Check that it arrives, and not in spam. For better delivery open cPanel **Email Deliverability** and fix anything it flags (SPF and DKIM). Registration still works if an email fails (the failure is logged), but a supplier who never gets the invitation cannot continue.

---

## 8. Security checks (do these before announcing the site)

| Check | How |
|---|---|
| Debug is off | `.env` has `APP_DEBUG=false`. Open `https://<domain>/invite/not-real`: you must see a plain "Not Found" page, never code or a stack trace. |
| HTTPS only | Open `http://<domain>`: it must jump to `https://`. (The app also forces `https` links, and the cookie is secure with `SESSION_SECURE_COOKIE=true`.) |
| `.env` and code not downloadable | `curl -I https://<domain>/.env` must be 403 or 404. It is safe because the app folder is outside the web folder. |
| `storage` not browsable | `https://<domain>/storage/` must not list files. Only the photos under `storage/uploads/...` are reachable, and `.php` files there are blocked. |
| CORS closed | No `Access-Control-Allow-Origin` header is ever sent: `curl -i -H "Origin: https://evil.example" https://<domain>/api/hermes/orders` shows none. |
| Rate limits are on | Registration 30 per minute per IP (`REGISTER_RATE_LIMIT`), Hermes API 120 per minute (`HERMES_RATE_LIMIT`), HitPay webhook 120, "Pay" 20, uploads 60, AI wording 40 (all per minute per IP). |
| Search engines | Pages carry `noindex`, and `robots.txt` blocks `/invite/`, `/api/` and `/webhooks/`. |
| The webhook is signed | A forged call returns 401: `curl -s -X POST https://<domain>/webhooks/hitpay -d "status=completed"` → `{"message":"Invalid signature."}` |
| Secrets | `.env` permission `600` or `640`. The sandbox HitPay keys and any test token are not on the live server. |

---

## 9. Test it end to end on staging first

Before the live launch, do the whole journey once on a staging copy (same steps, but a staging subdomain, a separate empty database, `HITPAY_*` set to the **sandbox**, and `HITPAY_BASE_URL=https://api.sandbox.hit-pay.com/v1`):

1. Scan or open the staging address, register with a real email you can read. You get the invitation email.
2. Follow the link, answer all the questions, upload a logo and photos, confirm.
3. Press **Pay ₱10,000**, pay with HitPay's sandbox test method, and come back. **My Website** must change to "Payment confirmed" by itself, and the receipt email arrives.
4. With the Hermes token, run the calls in [HERMES_API.md](HERMES_API.md): list, stages 1 to 6, deliver. My Website shows the progress and the live link, and the "Your website is live!" email arrives.
5. Check the cron job: register another test supplier, and in the staging database set that supplier's `invite_expires_at` to the past, then wait a minute: the status becomes `expired` and the expired email arrives.
6. Empty the staging database or delete the staging site. Then repeat the settings for live (live HitPay keys, live domain) in the **live** `.env` and run `php artisan config:cache`.

---

## 10. Updating the site later

1. **Back up the database first:** cPanel → **Backup** → download a MySQL backup (or phpMyAdmin → Export).
2. Keep the previous release zip so you can go back.
3. Put the site in maintenance mode: `php artisan down` (in `~/wedworks`).
4. Build a new release (part 2), upload and extract it over `wedworks`. **Do not overwrite `.env` or the `storage` folder** (the release zip does not contain them).
5. Run:
   ```bash
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan up
   ```
   (Option B: also copy the new `public/build` into `public_html`.)
6. Open the site, register nothing, just look at the home page and a test invite link.
7. The release that added **Admin > Questions** has a migration that creates the questions table and fills in the 30 original questions. It must run (step 5) before the site comes back up, because the chat reads the questions from the database. Afterwards open Admin > Questions and check the list.

## 11. Rolling back

If an update goes wrong:

1. `php artisan down`
2. Extract the **previous** release zip over `wedworks` again (not `.env`, not `storage`). Option B: also put the previous `public/build` back into `public_html`.
3. If the bad release added database changes, you have two choices:
   - If you have not taken real sign-ups or payments since the update, restore the database backup from step 1 of part 10 (cPanel → phpMyAdmin → Import).
   - If you have, ask a developer to run `php artisan migrate:rollback --step=N` (N is the number of new migrations). Restoring the old backup would lose the new sign-ups and payments.
4. `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`, then `php artisan up`.

If the site is down and you cannot work out why, look at the newest file in `wedworks/storage/logs/` first. It names the problem.

---

## Where things are

| Need | Where |
|---|---|
| Errors and warnings | `wedworks/storage/logs/laravel-DATE.log` (14 days kept) |
| Payment received but not applied (late payment, double payment) | search the log for `HitPay payment received but not applied`, then refund by hand in HitPay |
| Uploaded photos | `wedworks/storage/app/public/uploads/{supplier id}/` |
| Cron and scheduler | [SCHEDULER.md](SCHEDULER.md) |
| Payments | [PAYMENTS.md](PAYMENTS.md) |
| Hermes API | [HERMES_API.md](HERMES_API.md) |
| Uploads | [UPLOADS.md](UPLOADS.md) |
| Optional AI wording | [AI_WORDING.md](AI_WORDING.md) |
