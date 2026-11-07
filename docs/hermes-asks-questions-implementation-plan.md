# Hermes Asks the Questions Implementation Plan

**Goal:** Suppliers chat with the Hermes agent (conversational, not a fixed list) for both the registration questions (Bot 1) and the website questions (Bot 2), inside the same website chat, while the system stays the rule-checker, slot-keeper and payment owner.
**Root cause:** Today the system itself asks every question: the question list is sent to the browser (`RegistrationController.php:18-24`, `InviteController.php:55-73`) and the browser walks through it (`Register.jsx`, `WebsiteChat.jsx`). Hermes only reads paid orders and reports stages (`routes/api.php:6-11`), and it has no way to talk to a supplier.
**Approach:** Add a message relay (tables + two small APIs): the browser posts the supplier's messages to the system, Hermes fetches them over the existing token API, replies, and sends the answers it collected. The finished answers go to the supplier's existing review card, and the supplier's "Confirm" button calls the existing `/register` and `/invite/{token}/confirm` endpoints, so every current rule still applies. An Admin switch per chat ("System" or "Hermes") and an automatic fallback to the fixed chat cover the case where Hermes is off.
**Tech stack:** Laravel 11.57.1, PHP 8.3, MariaDB, Inertia v1, React 18, Tailwind 3.4 (same as the project). Tests: `php artisan test` (`tests/Feature`).
**Source:** User, in chat: "the questions should be asked by the hermes agent not from the system, we have an ai agent that actually ask questions", with answers: channel = inside the website chat; scope = both chats, with a switch.

## Open questions (resolve before implementing)

| # | Question | Why it matters | Recommended answer |
| --- | --- | --- | --- |
| 1 | How long does a supplier wait for Hermes before being offered the fixed form? | A slow or stuck agent should not lose a sign-up. | 25 seconds after the supplier's message with no reply, show "Continue with the standard form" (the supplier chooses). |
| 2 | When is Hermes counted as "online"? | The chat must not start in Hermes mode if nobody is listening. | Online = Hermes called the API in the last 45 seconds. If offline when the page loads, the fixed chat is used automatically. |
| 3 | Who presses the final button? | Hermes must not create suppliers or confirm answers by itself. | The supplier reviews the answers on the existing review card and presses Confirm (existing endpoints). |
| 4 | How long are chat transcripts kept? | They hold personal details. | Delete 30 days after the chat ends (added to the existing every-minute scheduler job). |
| 5 | Photos and logo | Hermes cannot receive images through text. | Hermes asks, and the chat shows the existing upload buttons. Hermes sees the files in the chat's `uploads` list. |
| 6 | Does the registration outcome (invited vs waiting list) stay hidden from the supplier? | `RegistrationController.php:36` says the chat deliberately does not reveal it. | Yes. Hermes never receives the outcome; the confirmation card stays the same as today. |

---

## Live system validation: 2026-11-07

Read-only. No data changed.

1. The Hermes API is token-protected and rate-limited: `routes/api.php:6-11`, `AuthenticateHermes.php` (constant-time check, empty token rejects everything), `config/hermes.php`. No token is saved yet and `HERMES_API_TOKEN` in `.env` is empty (`curl` to `/api/hermes/orders` returns 401).
2. Hermes only sees paid-or-later orders by rule 11 (`config/hermes.php:11`). Chat endpoints are new and do not widen what Hermes can see of orders.
3. Settings are stored in the `settings` table and applied at boot (`SettingsService::FIELDS`, `apply()`): the two mode switches fit there as non-secret fields.
4. The scheduler runs `invites:process` every minute (`routes/console.php`): the transcript cleanup and Hermes "last seen" need no new cron.
5. UNVERIFIED: how the real Hermes agent calls HTTP (polling interval, tool support). The plan only needs it to poll and POST JSON, as it already does for orders.

## Audit findings

1. **Registration rules live in three places that must be reused, not copied:** `RegisterSupplierRequest::prepareForValidation` and `rules()` (normalizing mobile, email, social link; core rules), `RegistrationQuestions::extraRules()`, and the client checks in `Register.jsx` (`CORE`). Hermes' `answers` endpoint must give the same verdicts as the form, so the normalizing and rules move into one shared class used by both.
2. **Website rules** are already central: `WebsiteQuestions::rules($type)` and `ConfirmWebsiteAnswersRequest` (trim/blank, allowed keys only).
3. **Writers:** the only creator of suppliers is `SlotService::register` (called from `RegistrationController::store`); the only writer of answers is `WebsiteDetailsService::confirm` (`InviteController::confirm`). Hermes does not call either; the supplier's Confirm does. This keeps slot locking, duplicates, emails, status history and snapshots unchanged.
4. **Readers/UI:** `Register.jsx` and `WebsiteChat.jsx` both have a review card with per-question Edit and the answers shape they post. The Hermes chat fills `answers` in the same flat shape, then shows that same card.
5. **Untrusted text:** supplier messages become text read by an AI. They are stored as data, capped in size, and the API docs tell Hermes to treat them as data, never as instructions. Hermes can only call the endpoints below, which cannot change prices, slots, other suppliers or settings.
6. **Prior decisions:** T2 scope item 6 (hide invited vs waitlisted); rule 10 (answers lock after payment, `WebsiteDetailsService::editable`); T9 (AI never decides validation). All kept: validation stays on the server.
7. **Abuse:** the registration chat is public. Starting a chat is rate-limited per IP (like `throttle:register`), each chat has a message cap, and chats expire.

## Scope and constraints

### In scope
- Tables for chats and messages; Admin switch per chat (System/Hermes) and a "Hermes last seen" indicator.
- Browser endpoints (start, send, poll) and the chat component used by both pages when the mode is Hermes and Hermes is online.
- Hermes endpoints (list waiting chats, read a chat with its question list and rules, reply, submit answers for checking, mark ready for review, upload list).
- Shared registration rules; fallback to the fixed chat; transcript cleanup.
- A stand-in `hermes:fake` artisan command (dev mode only) that answers chats from the scripted questions, so the whole flow can be tested without the real agent.
- `docs/HERMES_CHAT.md` with the steps and a ready instruction text for the agent.

### Out of scope: do not change
- Slot logic, duplicate checks, payment, statuses, emails, the build stages and the existing order endpoints.
- The fixed chats (they stay as the default and the fallback).
- The questions themselves and the Admin > Questions page.
- Any channel other than the website chat.

### Non-negotiable safety constraints
- Hermes never creates a supplier, confirms answers or pays. Only the supplier's Confirm does, through the existing endpoints.
- Every Hermes `answers` call is checked with the same rules as the form; an invalid value is refused with a plain-language message.
- A chat endpoint for one chat never returns another chat's data. Chat ids are random 40-character strings.
- The browser never receives Hermes-only fields (the token, other chats, internal notes).
- If Hermes is offline, slow or sends nothing useful, the supplier can always continue with the fixed chat, keeping what they already answered.
- No supplier data leaves the system except to the authenticated Hermes API.

### Contract

**Settings (Admin > Settings):** `chat_mode_registration` and `chat_mode_website`, each `system` (default) or `hermes`.

**Browser (public, throttled):**
- `POST /agent-chat/registration/start` and `POST /invite/{token}/agent-chat/start` (the token's supplier must still be allowed to edit, same check as `WebsiteDetailsService::editable`): returns `{chat_id}` or `{fallback:true}` when the mode is `system` or Hermes is offline.
- `POST /agent-chat/{chat_id}/messages` `{text}` (1 to 1000 characters, 80 messages per chat): stores a supplier message.
- `GET /agent-chat/{chat_id}?after={message_id}`: new messages, `status` (`open | review | done | expired`), `hermes_online`, `widget` (`upload` when Hermes asked for photos), and in `review` the collected `answers`.

**Hermes (token API under `/api/hermes`):**
- `GET /chats?status=waiting`: chats whose last message is from the supplier. Counts as a heartbeat.
- `GET /chats/{chat_id}`: `bot`, transcript, `supplier` context (first name, business name when known), `interview` = every question for this chat (`key, label, text, type, required, options, rules in words`), `collected` answers so far, `uploads`.
- `POST /chats/{chat_id}/messages` `{text, widget?}`: reply shown to the supplier.
- `POST /chats/{chat_id}/answers` `{answers:{key:value}}`: checked with the form rules. Response `{ok, accepted:[keys], errors:{key:message}}`. Accepted values are kept as the chat's draft.
- `POST /chats/{chat_id}/ready`: allowed only when every required question has an accepted answer; moves the chat to `review`.
- `409 not_ready` with the list of missing questions otherwise; `404` for unknown or expired chats; `401` as today.

**Acceptance cases**

| Case | Actor | Input | Required outcome |
| --- | --- | --- | --- |
| Default mode | supplier | mode `system` | start returns `fallback:true`; fixed chat as today |
| Hermes offline | supplier | mode `hermes`, no API call for 45 s | `fallback:true` |
| Happy path, registration | supplier + Hermes | messages, answers, ready, supplier presses Confirm | `/register` creates the supplier through `SlotService`; outcome card unchanged |
| Happy path, website | supplier + Hermes | same | `confirm` stores answers and snapshot; status `details_submitted` |
| Invalid answer | Hermes | bad email / mobile / option | `{ok:false, errors}`; nothing saved |
| Required missing | Hermes | `ready` too early | 409 `not_ready` listing the questions |
| Unknown key | Hermes | answer for a question not in this chat | refused |
| Wrong token | anyone | any `/api/hermes/chats*` | 401 |
| Other chat | browser | another chat's id | 404, no data |
| Too long/too many | browser | 1001 characters or the 81st message | 422, nothing stored |
| Expired invite | supplier | website chat for an expired or paid supplier | refused like the fixed chat (403) |
| Fallback keeps answers | supplier | switches to the fixed chat after some answers | answers already accepted are prefilled |
| Slot safety | two suppliers at once | both confirm | `SlotService` row lock decides, as today |
| Transcript cleanup | scheduler | chat ended 31 days ago | messages deleted |
| Dev stand-in | developer | `hermes:fake` with dev mode on | completes a chat end to end; refuses to run when dev mode is off |

## Files

| File | Change | Responsibility |
| --- | --- | --- |
| `database/migrations/2026_11_10_000001_create_agent_chats_tables.php` | new | `agent_chats`, `agent_chat_messages` |
| `app/Models/AgentChat.php`, `app/Models/AgentChatMessage.php` | new | Models |
| `app/Services/AgentChatService.php` | new | Start, relay, heartbeat, fallback decision, answer checking, ready, cleanup |
| `app/Support/RegistrationInput.php` | new | Shared normalizing and rules for the registration answers |
| `app/Http/Requests/RegisterSupplierRequest.php` | edit | Use `RegistrationInput` |
| `app/Http/Controllers/AgentChatController.php` | new | Browser endpoints |
| `app/Http/Controllers/Api/HermesChatController.php` | new | Hermes endpoints |
| `routes/web.php`, `routes/api.php` | edit | Routes |
| `app/Services/SettingsService.php`, `app/Http/Controllers/Admin/SettingsController.php` | edit | Two switches, "Hermes last seen" |
| `resources/js/Pages/Admin/Settings.jsx` | edit | The switches and the status line |
| `app/Http/Controllers/RegistrationController.php`, `app/Http/Controllers/InviteController.php` | edit | Send `agent` props (mode, online) to the pages |
| `resources/js/Components/AgentChat.jsx` | new | The relay chat (messages, typing, upload widget, "continue with the standard form") |
| `resources/js/Pages/Register.jsx`, `resources/js/Components/WebsiteChat.jsx` | edit | Use `AgentChat` when the mode is Hermes and online; take over its answers into the review card |
| `app/Console/Commands/HermesFake.php` | new | Dev-only stand-in agent |
| `app/Console/Commands/ProcessInvites.php` (or the command behind `invites:process`) | edit | Transcript cleanup |
| `tests/Feature/AgentChatTest.php` | new | All acceptance cases |
| `docs/HERMES_CHAT.md`, `docs/HERMES_API.md`, `docs/ADMIN.md`, `docs/BUILD_LOG.md`, `docs/GO_LIVE_CHECKLIST.md` | new/edit | Instructions and notes |

## Phases

- **Phase 0:** write `AgentChatTest` (it fails: no tables or routes).
- **Phase 1:** migration, models, shared `RegistrationInput` (existing registration tests must still pass unchanged).
- **Phase 2:** `AgentChatService`, switches in Settings, heartbeat.
- **Phase 3:** Hermes endpoints, then browser endpoints; tests go green.
- **Phase 4:** `AgentChat.jsx`, wiring into both pages, the fallback, the review-card hand-over.
- **Phase 5:** `hermes:fake`, then a full click-through in the browser in dev mode for both chats, including fallback and the upload widget.
- **Phase 6:** docs, cleanup job, full suite, `npm run build`.

Phase constraints: no change to existing endpoints' behavior; the fixed chats keep working with the switch on `System`.

## Rollback

1. Set both switches to `System` (no deploy needed): the fixed chats are back at once.
2. If the feature must go entirely, re-deploy the previous code. The two new tables are ignored by old code; do not drop them or edit the applied migration.
3. Suppliers registered or confirmed through Hermes chats are normal records, so nothing is lost.

## Commit

The project folder is not a git repository, so there is nothing to `git add`. The change set is the Files table.

## Self-review

1. **Contradictions:** the goal says Hermes asks, and the safety rules say only the supplier confirms. These do not conflict: Hermes collects, the supplier confirms on the existing card.
2. **Reachability:** with mode `hermes` and Hermes online, both public entry points use the chat; existing suppliers and the fixed chats are untouched. Fallback guarantees a way through when Hermes is offline.
3. **Bypass:** Hermes cannot create suppliers or write answers directly (only drafts inside the chat); the writers remain `SlotService::register` and `WebsiteDetailsService::confirm`, reached only by the supplier's Confirm. Chat ids are unguessable; the website start repeats the `editable` check.
4. **Existence:** every existing file and line cited was read in this session; the new files in the Files table do not exist yet by design. UNVERIFIED: the real Hermes agent's HTTP behavior.
5. **Consistency:** Files table and phases list the same items.
6. **Duplication:** registration normalizing and rules move into one class used by the form request and the Hermes `answers` check, instead of a second copy.
7. **Placeholders:** none.
