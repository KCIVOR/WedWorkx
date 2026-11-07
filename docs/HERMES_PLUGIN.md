# The WedWorks plugin for Hermes

The plugin makes WedWorks a channel of your Hermes agent, like Telegram is. Hermes keeps one request open to your website; when a supplier writes in the chat, the website answers that request at once, Hermes writes the reply, and the reply goes back. No checking again and again.

```
Supplier's browser  <->  WedWorks website (Apache)  <->  the plugin inside Hermes  <->  your wedworks-chat profile
```

> **Status:** the plugin is built and passes its tests and Hermes' own validator. It has **not yet been run end to end with your real Hermes** (that is Phase 4 of `docs/hermes-wedworks-plugin-implementation-plan.md`). The bridge (`docs/HERMES_BRIDGE.md`) is still the tested connection until then.

## What you need

1. WedWorks running through Apache at `http://127.0.0.1:8081` (see "Apache" below). `php artisan serve` must not be used while the plugin runs.
2. A Hermes token: Admin > Settings > Hermes agents > Create a token. Keep it private.
3. A Hermes profile for this job, named `wedworks-chat` (below).

## One-time setup

### 1. Make the profile (a separate agent just for suppliers)

In a terminal:

```
hermes profile create wedworks-chat --no-skills
hermes -p wedworks-chat model
```

The second command lets you choose the model. Pick a **fast Claude model** (it needs your Anthropic key, typed by you in that terminal). Free models were far too slow in testing: a reply must arrive within 25 seconds or the website quietly uses the fixed questions.

Then open `%LOCALAPPDATA%\hermes\profiles\wedworks-chat\SOUL.md` and make it plain, for example: `You are a plain, polite assistant. Follow the instructions given in each conversation.` (A profile copied from a developer agent would carry that persona into supplier chats.)

### 2. Install the plugin into that profile

```
tools\hermes-plugin\install.bat wedworks-chat
```

It asks for the token (hidden) and then:
- copies the plugin into the profile's own plugins folder,
- saves `WEDWORKS_URL`, `WEDWORKS_HERMES_TOKEN` and `WEDWORKS_ALLOW_ALL_USERS` in the profile's `.env`,
- enables the plugin for that profile only and gives the WedWorks channel **no tools**,
- waits for your running Hermes gateway to pick it up (no restart) and shows the result.

The last lines should say there are no tools enabled for the WedWorks channel and that the platform is WedWorks.

### 3. Switch the chats on

Admin > Settings > "Who asks the questions": choose "The Hermes agent" for the registration chat and/or the website chat, then Save. The card should say **Hermes online via wedworks-plugin** within a few seconds.

### 4. Try it yourself first

Open `http://127.0.0.1:8081` in a private window and chat like a supplier. Stop before "Finish registration" if you do not want a real test registration and email.

## How it behaves

- **One held request.** The plugin asks `GET /api/hermes/chats?wait=20`. If nothing is waiting, the website holds the request for 20 seconds and then answers "nothing"; the plugin asks again. A message that arrives during the hold ends it at once.
- **Chats in progress are not repeated.** The plugin tells the website which chats it is already working on (`seen`), so the held request does not end immediately for them. A new message in such a chat ends it.
- **Every chat is its own Hermes session** (chat type "dm", the WedWorks chat id as the chat and user id), so suppliers never share a conversation or memory.
- **No tools, and no commands from suppliers.** The WedWorks channel has an empty tool list, and a supplier's words (even `/new` or `/model x`) are wrapped as plain conversation.
- **Only a valid JSON plan is acted on.** Hermes' own notices ("No home channel is set", "Redirected current run") also pass through the channel; the plugin ignores them. The plugin sends only the plan's reply, answers, upload widget and ready flag.
- **The system re-checks everything.** Answers are validated by the website's own rules; a refusal is passed back to Hermes once in a follow-up turn (marked as not from the supplier); the supplier confirms at the end.
- **If the supplier writes again while Hermes is answering,** the older reply is dropped and the newest message is answered.
- **One consumer at a time.** If the bridge is running too, whichever asked last within 30 seconds answers; the other waits quietly. Stop the bridge once the plugin works.
- **If the plugin or Hermes is not there,** the website quietly uses the fixed chat after 45 seconds (from the start) or 25 seconds without a reply (mid-chat), and writes it on Admin > Activity.

## Settings (the profile's `.env`)

| Key | Meaning |
|---|---|
| `WEDWORKS_URL` | The website, `http://127.0.0.1:8081` here. The plugin adds `/api/hermes`. |
| `WEDWORKS_HERMES_TOKEN` | The Hermes token. Never written to config files or logs. |
| `WEDWORKS_WAIT` | Seconds one request is held, 1 to 20 (default 20). |
| `WEDWORKS_ALLOW_ALL_USERS` | `true`: every WedWorks chat may talk to the agent. This is safe because the plugin opens no port and only handles chats it fetched from your website with the token. |

## Changing the token or the plugin files: restart the Hermes gateway

Found in the Phase 4 tests: Hermes reads a profile's settings and the plugin's code **once**, when the plugin first loads. If you later install again with a **new token**, or update the plugin files, the running gateway keeps using the old token and the old code, and the plugin logs `the system refused the token (401)` every 30 seconds. After changing either, restart the Hermes gateway (stop and start the `Hermes_Gateway` scheduled task, or restart Hermes from its app). A first-time install does not need a restart. Restarting the gateway briefly interrupts all of your Hermes agents.

## Undo

```
powershell -File tools\hermes-plugin\uninstall.ps1 -Profile wedworks-chat
```

It disables the plugin, removes the settings from the profile's `.env` and deletes the plugin folder. Switch "Who asks the questions" back to "The system" in the admin at any time, with no deploy.

## If something goes wrong

| What you see | What to do |
|---|---|
| Admin card says "Hermes offline" | The plugin is not connected: check the gateway log (`hermes logs gateway`) for `wedworks`, and that the profile `.env` has the URL and token |
| `The system refused the token (401)` in the log | Create a new token in the admin and run `install.bat wedworks-chat` again |
| The log says `cannot reach the system` | The website is not running on Apache at `127.0.0.1:8081` |
| Replies take more than 25 seconds | The profile's model is too slow, or the computer is short of memory: use a faster model |
| Everyone gets the fixed chat | The admin switch is on "The system", or Hermes is not listening (see the first row) |
| Chat start answers 429 (too many requests) | Fixed 2026-11-08: each chat route now has its own rate limit (`agent-start`, `agent-send`, `agent-poll`, `agent-end` in `AppServiceProvider`), so polling no longer uses up the allowance for starting chats |

## Apache (why the site must run on Apache for the plugin)

The plugin keeps one request open for up to 20 seconds. `php artisan serve` handles **one request at a time on Windows**, so a held request would freeze the whole site (including the supplier chat). Apache gives every request its own thread.

### The setup on this computer (done 2026-11-08)

- `C:\xampp\apache\conf\extra\wedworks.conf` serves this project's `public` folder on **port 8081**.
- `httpd.conf` has one added line: `Include conf/extra/wedworks.conf` (after the vhosts include). The original is saved as `C:\xampp\apache\conf\httpd.conf.bak-before-wedworks`.
- Open the site at **http://localhost:8081**. `php artisan serve` on port 8000 still works for everyday development, but **do not use it while the plugin is running**.
- `APP_URL` in `.env` was **not** changed. It is only used for links the system writes (emails, uploads), so it can stay `http://localhost:8000` while developing. Change it to the real address when the site goes live.
- If `npm run dev` leaves a `public/hot` file behind after it stops, delete it, or pages served by Apache point at a Vite dev server that is not running and look unstyled.

Checked: the home page, `/admin/login`, `/up` and `/api/hermes/chats` answer through Apache (about 0.5 s). A Hermes request held open for 20 seconds did not slow the site: while it was held, pages still loaded in about 0.7 s.

### To apply a change to the Apache setup

Edit `wedworks.conf`, test it with `C:\xampp\apache\bin\httpd.exe -t` (it should say "Syntax OK"), then restart the service in an **administrator** PowerShell: `Restart-Service Apache2.4` (or Stop and Start Apache in the XAMPP Control Panel).

### To undo it

Delete `C:\xampp\apache\conf\extra\wedworks.conf` and the `Include conf/extra/wedworks.conf` line (with its comment line above it) in `httpd.conf`, or copy `httpd.conf.bak-before-wedworks` back over `httpd.conf`, then restart Apache.

### On the real host (cPanel)

Nothing to do: shared hosting already runs PHP with several workers, so one held request per Hermes connection is fine. Keep `AGENT_CHAT_WAIT_MAX` at 20 or lower, because shared hosts often limit a request to 30 seconds. The plugin must run on a computer that stays on and can reach the host's address; use that address as `WEDWORKS_URL`.

## For developers

- Plugin source: `tools/hermes-plugin/wedworks/` (`adapter.py`, `plugin.yaml`). The chat protocol (instructions, reading the reply, applying it) is shared with the bridge in `tools/hermes-shared/wedworks_protocol.py`; `install.ps1` copies it next to the adapter.
- Tests: `python -m unittest discover -s tools/hermes-shared` (29) and `python -m unittest discover -s tools/hermes-plugin` (30, using stand-ins for Hermes' classes), plus `php artisan test --filter=AgentChatLeaseTest` for the website side. `hermes plugins validate tools\hermes-plugin\wedworks` runs Hermes' own checks.
