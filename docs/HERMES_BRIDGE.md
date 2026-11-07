# Connecting Hermes (the bridge)

> The bridge is the tested connection and the fallback. The Hermes plugin (`docs/HERMES_PLUGIN.md`) is the faster, Telegram-like way once it has been tried end to end. Only one of them answers chats at a time: if the plugin is running, the bridge waits quietly and takes over about 30 seconds after the plugin stops. Use `WEDWORKS_URL=http://127.0.0.1:8081` (Apache) when you run either of them.

Hermes is an app that thinks and writes. WedWorks is a website. The **bridge** is a small program that sits between them on your computer: it checks WedWorks every second or two for suppliers waiting for a reply, asks **one Hermes profile** to write the reply, and sends it back. It lives in `tools/hermes-bridge/`.

```
Supplier's browser  <->  WedWorks website  <->  bridge (this computer)  <->  your Hermes profile
```

## What you need

- WedWorks running (`http://localhost:8000` on this computer).
- Hermes installed (you have it) and **a profile for this job** (below).
- Python 3 (you have it). Nothing else to install.

## One-time setup

### 1. Make a Hermes profile just for WedWorks

A profile is a separate agent with its own model and settings. Use a new one so your other agents (and their Zoho, Atlassian and other connections) are never involved.

In a terminal:

```
hermes profile create wedworks-chat
hermes -p wedworks-chat model
```

The second command lets you pick the model. Choose a **fast Claude model** (a Haiku or Sonnet class model from Anthropic). It needs your Anthropic API key. Free models were far too slow in testing: a reply must arrive within 25 seconds or the website switches to the fixed questions.

You do not need to give this profile any instructions or tools. The bridge sends the full instructions with every message, and it runs the profile with every tool off.

### 2. Create the token

In the admin: **Settings > Hermes agents > Create a token**. Copy it (it is shown once).

### 3. Fill in the bridge settings

1. In the folder `tools\hermes-bridge`, copy `.env.example` to a new file named `.env`.
2. Open `.env` and fill in:
   - `WEDWORKS_HERMES_TOKEN=` the token from step 2.
   - `HERMES_PROFILE=wedworks-chat` (the name from step 1). **Only this profile is ever used.**
   - `WEDWORKS_URL=http://localhost:8000` (leave as is on this computer).
3. Keep the `.env` file private: it holds the token.

### 4. Start it

Double-click `tools\hermes-bridge\start.bat`. A window opens and says "WedWorks bridge started". Leave it open while suppliers chat. Close it to stop.

### 5. Switch the chats on

**Admin > Settings > Who asks the questions**: choose "The Hermes agent" for the registration chat and/or the website chat, then Save. The card should say **Hermes online**.

### 6. Try it yourself first

Open the registration page in a private window and chat like a supplier. Stop before pressing "Finish registration" if you do not want a real test registration and email.

## How it stays safe

- Hermes is started with every tool off, so nothing a supplier types can make it run commands, read files, browse, or use your connections.
- Hermes never sees the token. It only returns text; the bridge decides what is sent.
- The website checks every answer again with its own rules (valid email, valid mobile, real options), and the supplier presses Confirm at the end.
- If Hermes does not answer within 25 seconds, the website quietly switches to the fixed questions. Nothing is shown to the supplier, and it is written on **Admin > Activity**.

## Good to know

- **Cost and speed:** each reply is one Hermes call. In testing, replies took 7 to 14 seconds with a Claude profile. Each call also leaves a short session in that profile's history.
- **Memory:** the computer needs some free memory. When it was short of memory during testing, Hermes took minutes instead of seconds. Close heavy programs while suppliers are chatting.
- **If the bridge window closes or the computer sleeps,** suppliers get the fixed questions. Nothing breaks.
- **Several suppliers at once:** the bridge answers up to 3 chats in parallel (`MAX_PARALLEL` in `.env`).
- **See what is said:** set `SHOW_MESSAGES=1` in `.env` to print the conversations in the bridge window (off by default for privacy).

## Building the websites (the other half)

The bridge only runs the conversations. Building the site after payment is a separate job for your Hermes: it should read paid orders from `GET /api/hermes/orders`, build the site, report stages 1 to 6 and deliver (see `docs/HERMES_API.md`). A scheduled Hermes job (`hermes cron create`) can do this every few minutes, using a profile that has the tools it needs.

## If something goes wrong

| What you see | What to do |
|---|---|
| "No token" | Fill in `.env` (step 3) |
| "The system refused the token (401)" | Create a new token in the admin and update `.env` |
| "Could not find Hermes" | Add `HERMES_EXE=` with the full path to `hermes.exe` in `.env` |
| The card says "Hermes offline" | The bridge is not running, or it cannot reach the website |
| "Hermes took longer than 40s" | The model is too slow or the computer is short of memory: use a faster model, close programs |
| "Hermes did not return usable JSON" | The model ignored the format: use a stronger model |
