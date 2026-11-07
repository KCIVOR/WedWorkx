# Payments (HitPay)

## Setup (sandbox first)

1. In the HitPay **sandbox** dashboard, copy the API key and the webhook **salt** (they differ from live ones).
2. Put them in `.env`:

   ```
   HITPAY_API_KEY=...
   HITPAY_SALT=...
   HITPAY_BASE_URL=https://api.sandbox.hit-pay.com/v1
   ```

   For live, use the live key and salt and `https://api.hit-pay.com/v1`.
3. Register the webhook in the HitPay dashboard (Developers, Webhook Endpoints): URL `https://<domain>/webhooks/hitpay`, event `payment_request.completed` (and the failed event if offered). The app also sends the same URL with every payment request.
4. After any `.env` change on a server that caches config, run `php artisan config:cache`.

## How a payment flows

1. The supplier confirms their details (status `details_submitted`) and taps **Pay**. The app creates a HitPay payment request and stores a `payments` row (`pending`, 30 minutes) and the supplier becomes `payment_pending`. Tapping Pay again reuses the live link; a new link marks an older pending one `expired`.
2. Links can only be created while the invite is valid. After the deadline the answer is 403.
3. HitPay calls the **webhook**. This is the only thing that marks a payment paid. The browser return page only shows status.
4. On a verified `completed` webhook the app: marks the payment `completed`, sets the supplier `paid`, creates the order (`KT10-xxxx`), locks the answers, writes `status_history` (actor `hitpay`) and emails the receipt.
5. On `failed` the supplier goes back to `details_submitted`, gets the "Payment unsuccessful" email and can try again while the invite is valid.
6. A link that runs out (30 minutes) is marked `expired` by `invites:process` and the supplier goes back to `details_submitted`.

## Webhook rules

- The signature is checked with the salt. A bad or missing signature gets 401 and nothing changes. With no salt configured, every webhook is rejected.
- Two HitPay formats are accepted: a JSON body with a `Hitpay-Signature` header (HMAC-SHA256 of the raw body), and the older form body with an `hmac` field (HMAC-SHA256 of the other fields, sorted by key, written as key+value).
- It is idempotent: the same webhook twice changes nothing the second time.
- The amount in the webhook must match the payment, otherwise it is ignored.
- Grace rule (D6): a payment started before the deadline may complete up to 30 minutes after it (`paid_late = true`). Past that, or if it was somehow started after the deadline, the payment is recorded as completed but no order is created. This is written to the log as `HitPay payment received but not applied` so the owner can refund by hand. There is no refund system.
- Money that arrives on a replaced link is still honoured. A second payment for an already paid supplier creates no second order and is logged the same way.

## Testing locally without HitPay

Point `HITPAY_BASE_URL` at a stand-in server, and sign a webhook with your salt: sort the fields by key, join them as `keyvalue`, and take `hash_hmac('sha256', message, salt)` as `hmac`.
