# WedWorks chatbot: context handoff (2026-11-08)

Paste this file (or point Codex at it) at the start of the new session.

## Project
WedWorks 10-10-10 Campaign chatbot for Kasalang Tagaytay. Laravel 11.57, PHP 8.3, MariaDB (XAMPP), Inertia v1 + React, Tailwind with the "Bold" `wb-*` design tokens. Owner is non-technical: explain in plain language, never ask them to paste secrets in chat.

Two chats: Bot 1 = supplier registration, Bot 2 = "my website" questions (invite link). Tasks T1 to T15 are done (see `docs/BUILD_LOG.md`).

## State right now
- Everything built and verified. `php artisan test`: 282 passed. Tests refuse to run unless the DB is `wedworks_chatbot_test` (guard in `tests/TestCase.php`). Never run `config:cache` (it makes tests hit the dev DB).
- Dev DB was wiped earlier: the owner must run `php artisan admin:create`.
- Hermes plugin is **uninstalled**, token cleared, both chat modes = "system" (fixed chat). Test data deleted, slots_taken = 0.
- Site for plugin work runs on **Apache** at `http://127.0.0.1:8081` (vhost `C:\xampp\apache\conf\extra\wedworks.conf`, service `Apache2.4`, restart needs an admin shell). `php artisan serve` handles one request at a time on Windows, so it must not be used while a held-request consumer runs.

## What was built this stretch
1. **Editable questions in admin** (Admin > Questions, two tabs, add/edit/delete/reorder in a modal): `website_questions` and `registration_questions` tables (soft deletes, locked system questions, snapshots in `website_answers.question_snapshot`, `suppliers.extra_answers`). Key files: `app/Support/WebsiteQuestions.php`, `RegistrationQuestions.php`, `RegistrationInput.php`, `app/Services/QuestionService.php`, `RegistrationQuestionService.php`, `resources/js/Pages/Admin/Questions.jsx`.
2. **Hermes asks the questions inside the website chat**, per bot switch in Admin > Settings "Who asks the questions" (system | hermes). Browser relays only; Hermes replies with JSON plan `{reply, answers, widget, ready}`; the system re-validates; supplier confirms on the review card. If Hermes is unreachable the chat silently falls back to the fixed chat (supplier never told) and Admin > Activity logs `hermes_unreachable`. Transcripts are never deleted. Files: `config/agentchat.php`, `app/Services/AgentChatService.php`, `app/Http/Controllers/AgentChatController.php`, `Api/HermesChatController.php`, `resources/js/lib/agentChat.js`, `Register.jsx`, `WebsiteChat.jsx`.
   - Timings: Hermes counted online for 45 s after last request; reply timeout 25 s; held request up to 20 s; single-consumer lease 30 s.
   - Fallback is triggered by the **browser** (`POST /agent-chat/{id}/fallback` when `waiting_seconds >= timeout`), not by the server.
3. **Native Hermes platform plugin** (like Telegram, no polling): `tools/hermes-plugin/wedworks/{adapter.py,plugin.yaml}`, shared protocol `tools/hermes-shared/wedworks_protocol.py`, installers `install.ps1/.bat`, `uninstall.ps1`. Profile name `wedworks-chat` (copy of `dev-quality`, plain SOUL.md, no tools for the WedWorks channel). Older fallback connector: `tools/hermes-bridge/`.
4. **Rate-limit fix** (this session): `AppServiceProvider` named limits `agent-start`, `agent-send`, `agent-poll`, `agent-end`; routes in `routes/web.php` lines 31 to 36 use them; regression test in `AgentChatTest.php`.

## Phase 4 results (real Hermes end to end)
Median reply about 5.4 to 6.1 s, p95 about 7.5 s. Three parallel chats isolated. Two quick messages get one combined reply. Slash commands and injection stay plain text. Idle load about 13 requests per 5 minutes. Not run live: failure mid-chat, bridge/plugin handover, upload widget (covered by automated tests). Details: `docs/hermes-wedworks-plugin-implementation-plan.md` ("Phase 4 final results").

## Gotchas learned
- The running Hermes gateway reads a profile's token and plugin code once. After any token or plugin-file change, restart the gateway (kill the `hermes_cli.main gateway run` python process, then `schtasks /run /tn Hermes_Gateway`; takes about 90 s to reconnect). Setting `platforms.wedworks.enabled false` does NOT stop an already-running plugin.
- Hermes only counts a chat consumer as online while it keeps calling `/api/hermes/chats`; any probe with a different `consumer` name steals the 30 s lease.
- Laravel: `bootstrap/app.php` calls `Env::disablePutenv()` (threaded Apache race). Unnamed `throttle:N,M` shares one counter per IP across routes: always use named limiters.
- Windows excluded-port error 10013: `net stop winnat; net start winnat` (admin) or reboot.
- If `npm run dev` leaves `public/hot`, delete it or Apache pages look unstyled.
- PowerShell 5.1 has no `&&`.

## To go live with Hermes
1. Owner: `php artisan admin:create`.
2. Admin > Settings > Hermes agents > create a token (private).
3. `tools\hermes-plugin\install.bat wedworks-chat`, then restart the gateway.
4. Admin > Settings: set "Who asks the questions" to Hermes; card should say "Hermes online via wedworks-plugin".
5. Real host (cPanel): `AGENT_CHAT_WAIT_MAX` 20 or lower; `WEDWORKS_URL` = the live address. See `docs/GO_LIVE_CHECKLIST.md`, `docs/DEPLOYMENT.md`.

## Docs map
`docs/ADMIN.md`, `HERMES_API.md`, `HERMES_CHAT.md`, `HERMES_BRIDGE.md`, `HERMES_PLUGIN.md` (full plugin guide), `BUILD_LOG.md`, `GO_LIVE_CHECKLIST.md`, `DEPLOYMENT.md`, `hermes-wedworks-plugin-implementation-plan.md`.

## Open items (optional)
- Live drill of failure mid-chat and bridge/plugin handover.
- Real-token install and owner acceptance test.
- Full transcript of this session: `C:\Users\Rovick\.claude\projects\C--Users-Rovick-Desktop-wedworks-chatbot\2bbe8a68-6c0b-4452-b751-b5e31dd89a72.jsonl`
