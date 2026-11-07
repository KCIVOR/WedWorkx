# AI-worded questions

Both chats can show a friendlier rewording of each scripted question. The AI only changes the wording. Which field an answer goes to, validation, the order of questions, the review cards and the stored data are all unchanged, and the scripted question is always the fallback.

## Switch it on

In `.env`:

```
AI_WORDING_ENABLED=true
AI_PROVIDER=anthropic
AI_API_KEY=<your key>
AI_MODEL=claude-haiku-5-5
AI_MAX_TOKENS=150
AI_MAX_CALLS=12
AI_TIMEOUT=4
```

Then, on a server that caches config, `php artisan config:cache`.

`AI_WORDING_ENABLED=false` (the default) turns it off completely: no request is ever sent and the chats use the scripted text.

## Controls

| Setting | Default | What it does |
|---|---|---|
| `AI_MAX_TOKENS` | 150 | Hard cap on the length of each reply. A reply cut off by the cap is thrown away. |
| `AI_MAX_CALLS` | 12 | Calls per chat session (per visitor, per chat). Chatbot 1 has 7 questions and Chatbot 2 has 8. Failed calls count, so a broken provider cannot be hammered. |
| `AI_TIMEOUT` | 4 s | Slower than this, or any error, empty reply or odd reply: the scripted question is shown. |

The routes are also rate limited (40 per minute per IP).

## What is sent to the AI provider

Only the scripted question text, the supplier's **first name** and **business name**. Never an email address, mobile number, last name, city, social link, answer or payment detail. As an extra guard, anything that looks like an email address or phone number is removed from the question and the two names before sending.

The browser only says which question it wants (a key). The server looks up the scripted text itself, and for Chatbot 2 takes the names from the database, so the endpoints cannot be used as a general AI proxy.

The names are typed by suppliers, so they are passed to the model as data (inside a JSON block the prompt says to ignore as instructions), shortened to 60 characters, and the reply is checked: it must be 15 to about 2x the original length (at most 700 characters), end like a sentence, and contain no link, `@`, long number or markdown. Otherwise the scripted question is used.

## What is stored or logged

Nothing from the AI is stored. Reworded text lives in the browser page only and is gone on reload. The log gets one line per call with only `chat` (`registration` or `website`), `result` (`ok` or `fallback:<reason>`) and the time taken.

## Using a different AI provider

`AiWordingService` depends only on the `App\Services\Ai\WordingProvider` interface (one method, `complete`). Add a class that implements it and return it for your `AI_PROVIDER` name in `AppServiceProvider::register()`.
