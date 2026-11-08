<?php

namespace App\Services;

use App\Models\AgentChat;
use App\Models\AgentChatMessage;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Support\BusinessTypes;
use App\Support\RegistrationInput;
use App\Support\RegistrationQuestions;
use App\Support\WebsiteQuestions;
use App\Support\WebsiteIntake;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The relay between a supplier's chat and the Hermes agent. The system stays the rule-checker: Hermes sends
 * answers, they are checked with the same rules as the fixed forms, and only the supplier's own Confirm
 * creates a supplier or saves answers.
 */
class AgentChatService
{
    public const REGISTRATION_COOKIE = 'wedworks_registration_chat';

    public function resumable(string $bot, ?Supplier $supplier = null, ?string $browserId = null): ?AgentChat
    {
        if ($this->mode($bot) !== 'hermes') return null;
        $query = AgentChat::where('bot', $bot)->whereIn('status', ['open', 'review'])
            ->where('created_at', '>=', now()->subHours((int) config('agentchat.expire_hours')));
        if ($bot === 'website' && $supplier) {
            $query->where('supplier_id', $supplier->id);
        } elseif ($bot === 'registration' && is_string($browserId) && preg_match('/^[A-Za-z0-9]{40}$/', $browserId)) {
            $query->whereNull('supplier_id')->where('public_id', $browserId);
        } else {
            return null;
        }
        return $query->latest('id')->first();
    }

    public function reconnect(AgentChat $chat): AgentChat
    {
        return DB::transaction(function () use ($chat) {
            // Re-read while locked so a reply arriving during the handoff cannot be overwritten by stale state.
            $chat = AgentChat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            // Give a genuinely returning browser a fresh reply window, not an immediate timeout from the old browser.
            if ($chat->status === 'open' && $chat->waiting_since
                && (! $chat->last_seen_at || $chat->last_seen_at->lt(now()->subSeconds(30)))) {
                $chat->waiting_since = now();
            }
            $chat->last_seen_at = now();
            $chat->save();
            return $chat;
        });
    }
    private const SEEN_KEY = 'hermes.chat.last_seen';

    private const LEASE_KEY = 'hermes.chat.consumer';

    // ---------------------------------------------------------------- Hermes presence

    /** Hermes calls this whenever it asks for waiting chats: it is listening. */
    public function heartbeat(): void
    {
        Cache::put(self::SEEN_KEY, now()->timestamp, 86400);
    }

    public function lastSeen(): ?int
    {
        $t = Cache::get(self::SEEN_KEY);

        return $t ? (int) $t : null;
    }

    public function hermesOnline(): bool
    {
        $seen = $this->lastSeen();

        return $seen !== null && (now()->timestamp - $seen) <= (int) config('agentchat.online_window');
    }

    /**
     * One consumer answers chats at a time. Returns null when this consumer holds (or has just taken) the lease,
     * or the other holder's name when someone else has it. The holder keeps it by asking again within the lease time.
     */
    public function takeLease(string $consumer): ?string
    {
        $holder = $this->leaseHolder();
        $window = (int) config('agentchat.lease_seconds');

        if ($holder && $holder['name'] !== $consumer && (now()->timestamp - $holder['at']) <= $window) {
            return $holder['name'];
        }

        Cache::put(self::LEASE_KEY, ['name' => $consumer, 'at' => now()->timestamp], 86400);

        return null;
    }

    /** @return array{name:string,at:int}|null the consumer that asked last, if any (it may have expired) */
    public function leaseHolder(): ?array
    {
        $holder = Cache::get(self::LEASE_KEY);

        return is_array($holder) && isset($holder['name'], $holder['at']) ? $holder : null;
    }

    public function mode(string $bot): string
    {
        return config("agentchat.mode.$bot") === 'hermes' ? 'hermes' : 'system';
    }

    // ---------------------------------------------------------------- chats

    /**
     * Opens a chat, or returns null when the fixed chat should be used (mode "system", or Hermes is not listening).
     * An unreachable Hermes is written to the activity page; the supplier is not told.
     */
    public function start(string $bot, ?Supplier $supplier): ?AgentChat
    {
        if ($this->mode($bot) !== 'hermes') {
            return null;
        }
        if (! $this->hermesOnline()) {
            $this->logUnreachable($bot, 'offline_at_start');

            return null;
        }

        return AgentChat::create([
            'public_id' => Str::random(40),
            'bot' => $bot,
            'supplier_id' => $supplier?->id,
            'status' => 'open',
            'last_seen_at' => now(),
            'waiting_since' => now(), // Hermes is expected to greet first
        ]);
    }

    public function expired(AgentChat $chat): bool
    {
        return $chat->created_at->lt(now()->subHours((int) config('agentchat.expire_hours')));
    }

    public function waitingSeconds(AgentChat $chat): int
    {
        return $chat->waiting_since ? max(0, (int) $chat->waiting_since->diffInSeconds(now())) : 0;
    }

    public function supplierMessage(AgentChat $chat, string $text): AgentChatMessage
    {
        $message = $chat->messages()->create(['role' => 'supplier', 'body' => $text]);
        $chat->update(['status' => 'open', 'waiting_since' => now(), 'widget' => null]);

        return $message;
    }

    public function hermesMessage(AgentChat $chat, string $text, ?string $widget): AgentChatMessage
    {
        $message = $chat->messages()->create(['role' => 'hermes', 'body' => $text]);
        $chat->update(['waiting_since' => null, 'widget' => $widget]);

        return $message;
    }

    /**
     * The fixed chat quietly takes over, after Hermes stayed silent for the whole waiting time. Returns the answers
     * Hermes had already collected, or null if the chat was not really stuck. Logged on the activity page.
     */
    public function fallback(AgentChat $chat): ?array
    {
        if ($chat->status === 'fallback') {
            return $chat->answers ?? [];
        }
        if (in_array($chat->status, ['done'], true) || $this->waitingSeconds($chat) < (int) config('agentchat.reply_timeout')) {
            return null;
        }

        $chat->update(['status' => 'fallback']);
        $this->logUnreachable($chat->bot, 'no_reply');

        return $chat->answers ?? [];
    }

    public function logUnreachable(string $bot, string $reason): void
    {
        AuditLog::record('hermes_unreachable', ['bot' => $bot, 'reason' => $reason]);
    }

    // ---------------------------------------------------------------- the interview Hermes runs

    /** The questions for this chat, as plain data with the rules in words. */
    public function questions(AgentChat $chat): array
    {
        if ($chat->bot === 'registration') {
            $name = ($chat->answers['name'] ?? null) ? explode(' ', $chat->answers['name'])[0] : null;
            $questions = collect(RegistrationQuestions::for())->map(fn ($q) => [
                'key' => $q['key'], 'label' => $q['label'], 'type' => $q['core'] ? $q['key'] : $q['type'],
                'text' => str_replace('{name}', $name ?? 'there', $q['text']),
                'required' => ! $q['optional'], 'fields' => [$q['key']],
                'options' => $q['key'] === 'business_type'
                    ? collect(BusinessTypes::ALL)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()
                    : $q['options'],
            ])->all();
        } else {
            $s = $chat->supplier;
            $questions = collect(WebsiteQuestions::forAnswer($s->websiteAnswer?->submitted_at ? $s->websiteAnswer : null, $s))->map(fn ($q) => [
                'key' => $q['key'], 'label' => $q['label'], 'type' => $q['type'], 'text' => $q['text'],
                'required' => ! $q['optional'], 'fields' => $q['fields'], 'options' => $q['options'] ?? null,
                'intake' => $q['intake'] ?? null,
            ])->all();
        }

        return array_map(fn ($q) => $q + ['rules' => $this->describe($q)], $questions);
    }

    /** The checks in words, so the agent can ask for the right thing and explain a refusal. */
    private function describe(array $q): string
    {
        if (! empty($q['intake'])) return 'Ask one visible intake field at a time, in field order. Conditions use accepted plus incoming answers. Every visible optional field requires an explicit null or [] skip. Never infer permissions or apply defaults. Uploads must be actual owned photo IDs. Later/help/review statuses create follow-up work, never authorize building or publication.';
        $need = $q['required'] ? 'Required.' : 'Optional (the supplier may skip).';

        $what = match ($q['key']) {
            'name' => 'Full name, at least 2 characters.',
            'email' => 'A valid email address.',
            'mobile' => 'A Philippine mobile number: 09XXXXXXXXX or +639XXXXXXXXX (the system stores it as +639...).',
            'business_name' => 'Business name, at least 2 characters.',
            'business_type' => 'One of the options. Send the option\'s value.',
            'location' => 'City and/or province, for example "Tagaytay City, Cavite".',
            'social_link' => 'A Facebook, Instagram or website link (https is added if missing).',
            'description' => 'At least 5 characters, up to 1000.',
            default => match ($q['type']) {
                'services' => 'Send `services` as a list of {"name": "...", "price": "..."} (up to 20; price is free text and optional).',
                'areas' => 'Send `service_areas` as a list of towns/cities (up to 20) and `booking` as text on how couples book (up to 200 characters).',
                'media' => 'Send `colors` as text (up to 120 characters). Photos and the logo are uploaded by the supplier: send a reply with widget "upload" to show the upload buttons.',
                'choice' => 'One of the options. Send the option\'s value.',
                'number' => 'A whole number.',
                'text' => 'Short text, up to 200 characters.',
                default => 'Text, up to 1000 characters.',
            },
        };

        return "$need $what";
    }

    /** The form rules for this chat's bot. */
    private function rules(AgentChat $chat, ?array $answers = null): array
    {
        return $chat->bot === 'registration'
            ? RegistrationInput::rules()
            : WebsiteQuestions::rules($chat->supplier->business_type, $answers ?? $chat->answers ?? [], $chat->supplier, $this->questions($chat));
    }

    private function messages(AgentChat $chat): array
    {
        return $chat->bot === 'registration' ? RegistrationInput::messages() : [];
    }

    /** The answer keys that belong to this chat. */
    private function allowedKeys(AgentChat $chat): array
    {
        return collect($this->questions($chat))->pluck('fields')->flatten()->unique()->values()->all();
    }

    /**
     * Checks answers from Hermes with the same rules as the fixed form. Valid ones are kept as the chat's draft.
     *
     * @return array{accepted:list<string>,errors:array<string,string>}
     */
    public function checkAnswers(AgentChat $chat, array $input): array
    {
        $allowed = $this->allowedKeys($chat);
        $errors = [];
        foreach (array_diff(array_keys($input), $allowed) as $unknown) {
            $errors[$unknown] = 'This is not a question in this chat.';
        }

        $input = array_intersect_key($input, array_flip($allowed));
        // Cleaned values replace what was sent; anything the cleaner leaves alone (such as the business type) is kept as sent.
        $clean = array_merge($input, $chat->bot === 'registration'
            ? RegistrationInput::normalize($input)
            : WebsiteQuestions::normalize($input, $this->questionsForNormalize($chat), true));

        // Check only the keys that were sent (including nested ones such as services.*.name).
        $rules = collect($this->rules($chat, array_merge($chat->answers ?? [], $clean)))->filter(fn ($r, $k) => array_key_exists(Str::before($k, '.'), $clean))->all();
        $validator = Validator::make($clean, $rules, $this->messages($chat));
        foreach ($validator->errors()->messages() as $key => $msgs) {
            $errors[Str::before($key, '.')] = $msgs[0];
        }

        $accepted = array_values(array_diff(array_keys($clean), array_keys($errors)));
        if ($accepted) {
            $chat->update(['answers' => array_merge($chat->answers ?? [], array_intersect_key($clean, array_flip($accepted)))]);
        }

        return ['accepted' => $accepted, 'errors' => $errors];
    }

    /** @return list<array<string,mixed>> the questions, in the shape the normalizer expects */
    private function questionsForNormalize(AgentChat $chat): array
    {
        return $this->questions($chat);
    }

    /** Questions still needing an answer or an explicit optional skip, in configured order. */
    public function unansweredQuestions(AgentChat $chat): array
    {
        $answers = $chat->answers ?? [];
        $validator = Validator::make($answers, $this->rules($chat), $this->messages($chat));
        $invalid = array_unique(array_map(fn ($key) => Str::before($key, '.'), array_keys($validator->errors()->messages())));

        return array_values(array_filter($this->questions($chat), function ($question) use ($answers, $invalid) {
            foreach (! empty($question['intake']) ? array_column(WebsiteIntake::activeFields($question, $answers), 'key') : $question['fields'] as $field) {
                if (! array_key_exists($field, $answers) || in_array($field, $invalid, true)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Review follows the full interview. Optional questions can be explicitly skipped, not silently omitted.
     *
     * @return array{ok:bool,missing:list<array{key:string,label:string}>}
     */
    public function ready(AgentChat $chat): array
    {
        $missing = array_map(fn ($question) => ['key' => $question['key'], 'label' => $question['label']], $this->unansweredQuestions($chat));
        if ($missing) {
            return ['ok' => false, 'missing' => $missing];
        }

        $chat->update(['status' => 'review']);

        return ['ok' => true, 'missing' => []];
    }
}
