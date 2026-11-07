# Dev mode

Dev mode lets you click through the **whole journey on your own computer** without HitPay, without the Hermes agents and without waiting for the scheduler. Turn it off and the real services are used again.

## Turn it on or off

In `.env`:

```
DEV_MODE=true     # on
DEV_MODE=false    # off (the default; also what happens if the line is missing)
```

Then reload the settings: `php artisan config:clear` (on a server that caches settings, `php artisan config:cache`).

When it is on, every page header shows an amber **DEV MODE** tag, so nobody mistakes it for the real thing.

## It cannot be on in production

Dev mode is **always off when `APP_ENV=production`**, whatever `DEV_MODE` says. A forgotten `DEV_MODE=true` on the live server does nothing: the `/dev` pages return 404 and Pay uses the real HitPay. The production `.env` template and the go-live checklist both say `DEV_MODE=false` anyway.

## What changes when it is on

| Step | Dev mode on | Dev mode off |
|---|---|---|
| **Pay ₱10,000** | No call to HitPay. The button opens a **simulated checkout** page with "Simulate a successful payment" and "Simulate a failed payment". | The real HitPay payment page |
| **After the simulated payment** | Runs the same code as a verified HitPay webhook: order and `KT10-xxxx` reference, answers locked, history, receipt email (or the failed-payment email, then retry). | Only HitPay's signed webhook does this |
| **Hermes progress** | My Website shows a **Dev tools** card: "Advance production to stage 1…6", then "Deliver the website". Same rules as the Hermes API (one step at a time, delivery only at stage 6, the live email once). | Only the Hermes agents, through the API |
| **Scheduler** | Dev tools: "Send the 2-hour reminder now" and "Expire this invitation now". | The cron job runs `invites:process` |
| Everything else | Unchanged: registration, the slot lock, uploads, My Website, the emails (they still go out through your mail settings), the deadline rules. | Unchanged |

Things dev mode does **not** do: it does not skip the deadline (no payment link after the deadline), it does not let anyone settle a real HitPay payment (only simulated `dev_…` payments can be settled on the dev page), and it does not change the Hermes API, the HitPay webhook or the scheduler themselves.

## A quick run

1. `DEV_MODE=true`, `php artisan config:clear`, and `php artisan migrate:fresh --seed` for a clean start.
2. Open http://localhost:8000, register (use an address you can read if your mail is set to a real SMTP).
3. Follow the invitation link, answer the questions, confirm, press **Pay**.
4. On the simulated checkout press **Simulate a successful payment**. You land on My Website, "Payment confirmed".
5. In **Dev tools** press the production button seven times (stages 1 to 6, then delivery). The page shows the progress and, at the end, the live link, and the "Website live" email is sent.

## Switching to the real thing

Turn dev mode off and fill in the real settings: HitPay key, salt and URL (see [PAYMENTS.md](PAYMENTS.md)), the Hermes token (see [HERMES_API.md](HERMES_API.md)) and the cron job (see [SCHEDULER.md](SCHEDULER.md)).
