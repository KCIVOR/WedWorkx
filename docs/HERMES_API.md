# Hermes API

A private, token-protected REST API for the Hermes agents (on the Mac mini). Hermes never connects to the database and never receives database credentials. It can only read paid orders and write the production stage and the delivery.

Base URL: `https://<domain>/api/hermes`

## Authentication

Every request needs the shared token as a Bearer header:

```
Authorization: Bearer <HERMES_API_TOKEN>
```

- The token is `HERMES_API_TOKEN` in the server's `.env`. Create a long random one, for example `php -r "echo bin2hex(random_bytes(32));"`, and give the same value to the agents. Never put it in a URL.
- A missing, wrong or malformed token gets **401** `{"error":"unauthorized"}`. If the server has no token set, every request is refused.
- Requests are limited to 120 per minute per IP (`HERMES_RATE_LIMIT`), counted before the token is checked. Over the limit gets **429**.
- Always send `Accept: application/json` (errors are JSON either way).

## What Hermes can see

Only orders whose supplier is `paid`, `building` or `delivered`. Unpaid suppliers never appear, and their references return 404.

## Errors

| Status | `error` | Meaning |
|---|---|---|
| 401 | `unauthorized` | Missing or invalid token |
| 404 | `order_not_found` | Unknown reference, or the order is not paid |
| 409 | `stage_backwards` | Stage lower than the current one |
| 409 | `stage_skipped` | Stage more than one above the current one |
| 409 | `not_ready_to_deliver` | `deliver` called before stage 6 |
| 409 | `already_delivered` | The order is already delivered |
| 422 | (Laravel validation body) | Bad input: stage not 1 to 6, bad URL, bad `status` filter |
| 429 | | Rate limit |

---

> Hermes can also run the supplier conversations (registration and website questions). That is a separate set of endpoints under `/chats`, described in `docs/HERMES_CHAT.md`.

## `GET /orders?status=paid`

Orders waiting to be built. `status` can be `paid` (default), `building` or `delivered`. Any other value gets 422.

```
curl -H "Authorization: Bearer $TOKEN" "https://<domain>/api/hermes/orders?status=paid"
```

```json
{
  "data": [
    {
      "reference": "KT10-H7QK",
      "business_name": "Rosal Catering",
      "business_type": "caterer",
      "status": "paid",
      "paid_at": "2026-11-06T15:17:11+08:00"
    }
  ]
}
```

Oldest payment first.

## `GET /orders/{reference}`

Everything needed to build the website: supplier, all answers, and the uploaded files.

```
curl -H "Authorization: Bearer $TOKEN" https://<domain>/api/hermes/orders/KT10-H7QK
```

```json
{
  "data": {
    "reference": "KT10-H7QK",
    "business_name": "Rosal Catering",
    "business_type": "caterer",
    "status": "paid",
    "paid_at": "2026-11-06T15:17:11+08:00",
    "current_stage": 0,
    "website_url": null,
    "delivered_at": null,
    "supplier": {
      "name": "Maria Santos",
      "email": "maria@rosalcatering.ph",
      "mobile": "+639171234567",
      "business_name": "Rosal Catering",
      "business_type": "caterer",
      "city": "Tagaytay City",
      "province": "Cavite",
      "social_link": "https://facebook.com/rosalcatering"
    },
    "answers": {
      "description": "Home-style Filipino menus for garden weddings.",
      "services": [{ "name": "Buffet", "price": "P650/head" }],
      "service_areas": ["Tagaytay", "Silang"],
      "booking": "Facebook Messenger",
      "colors": "Blush pink and gold",
      "style": "romantic",
      "additional": null,
      "extra1": "Buffet, plated meals",
      "extra2": "Yes, we can accommodate special requests"
    },
    "registration_answers": [
      { "key": "q_1a2b3c4d", "label": "How did you hear about us?", "answer": "A friend" }
    ],
    "questions": [
      { "key": "description", "label": "Business Description", "type": "textarea", "answer": "Home-style Filipino menus for garden weddings." },
      { "key": "services", "label": "Services / Packages", "type": "services", "answer": "Buffet (P650/head)" },
      { "key": "style", "label": "Website Style", "type": "choice", "answer": "Romantic & Soft" }
    ],
    "uploads": [
      { "kind": "logo", "name": "logo.png", "mime": "image/png", "size_kb": 14,
        "url": "https://<domain>/storage/uploads/1/Qm3xLogo.png" }
    ]
  }
}
```

- `answers` keys: `description`, `services`, `service_areas`, `booking`, `colors`, `style` (`elegant`, `modern`, `romantic`, `classic`, `colorful`, `surprise`), `additional`, and `extra1`/`extra2`, the two questions specific to the business type. The owner can add questions in the admin (Questions page); their answers appear under a key that starts with `q_`.
- `registration_answers` holds the answers to extra questions the owner added to the registration chat (empty when there are none). Each keeps the wording that was asked.
- `questions` lists every question this supplier was asked, in the order they saw it, with its label and the answer as plain text (`null` when skipped). It is the saved copy from when they confirmed, so later edits in the admin do not change it. Read it to know what a key such as `extra1` or `q_3fa91c2e` means. The list above is shortened; the real one has every question.
- `uploads[].url` is a public link to the file (it needs `php artisan storage:link` and a correct `APP_URL` on the server). `kind` is `logo` or `photo`.

## `POST /orders/{reference}/stage`

Reports progress. Body:

```json
{ "stage": 1, "note": "Design brief reviewed" }
```

- `stage`: whole number 1 to 6. `note`: optional text.
- Allowed: the same stage as now (a repeat is logged) or exactly the next one. The first call must be `1`. Never backwards, never skipping.
- The first stage moves the supplier from `paid` to `building`.
- Every call writes a row in `hermes_stage_log`.

```
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"stage":1,"note":"Design brief reviewed"}' https://<domain>/api/hermes/orders/KT10-H7QK/stage
```

```json
{ "data": { "reference": "KT10-H7QK", "business_name": "Rosal Catering", "business_type": "caterer",
            "status": "building", "paid_at": "2026-11-06T15:17:11+08:00", "current_stage": 1 } }
```

Skipping ahead:

```json
{ "error": "stage_skipped", "message": "Stages go one at a time (current stage is 1, the next is 2, you sent 3)." }
```

## `POST /orders/{reference}/deliver`

Marks the website live. Only allowed when the stage is 6, and only once. Body:

```json
{ "website_url": "https://rosal.example.ph" }
```

`website_url` must be a valid `http` or `https` URL. This sets `delivered_at` and `website_url`, moves the supplier to `delivered` and writes a `status_history` row (actor `hermes`).

```json
{ "data": { "reference": "KT10-H7QK", "business_name": "Rosal Catering", "business_type": "caterer",
            "status": "delivered", "paid_at": "2026-11-06T15:17:11+08:00", "current_stage": 6,
            "website_url": "https://rosal.example.ph", "delivered_at": "2026-11-06T15:17:18+08:00" } }
```

Before stage 6:

```json
{ "error": "not_ready_to_deliver", "message": "Delivery is only allowed at stage 6 (current stage is 1)." }
```

## Typical agent loop

1. `GET /orders?status=paid` to find new orders.
2. `GET /orders/{reference}` to read the answers and download the files.
3. After each of the six build stages, `POST /orders/{reference}/stage` with `1` to `6`.
4. When the site is live, `POST /orders/{reference}/deliver` with its URL.

All writes are idempotent-safe: repeating a stage is allowed, and a second `deliver` is refused with 409.
