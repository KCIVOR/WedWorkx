<?php

namespace App\Http\Controllers;

use App\Models\AgentChat;
use App\Models\Supplier;
use App\Services\AgentChatService;
use App\Services\WebsiteDetailsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * The supplier's side of a chat the Hermes agent runs. The browser only sends the supplier's own words and reads
 * Hermes' replies. Nothing here tells the supplier when Hermes is unreachable: the page quietly switches to the fixed chat.
 */
class AgentChatController extends Controller
{
    public function __construct(private AgentChatService $chats, private WebsiteDetailsService $details)
    {
    }

    public function startRegistration(Request $request): JsonResponse
    {
        $request->validate(['resume_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{40}$/']]);
        $existing = $this->chats->resumable('registration', browserId: $request->cookie(AgentChatService::REGISTRATION_COOKIE));
        $existing ??= $this->chats->resumable('registration', browserId: $request->input('resume_id'));
        $chat = $existing ? $this->chats->reconnect($existing) : $this->chats->start('registration', null);
        $response = $this->started($chat);
        return $chat ? $response->cookie(AgentChatService::REGISTRATION_COOKIE, $chat->public_id,
            (int) config('agentchat.expire_hours') * 60, '/', null, $request->isSecure(), true, false, 'lax') : $response;
    }

    public function startWebsite(string $token): JsonResponse
    {
        $supplier = Supplier::where('invite_token', $token)->firstOrFail();
        $this->details->authorizeEdit($supplier); // same rule as the fixed chat

        return DB::transaction(function () use ($supplier) {
            // Serialize browser handoffs for the same invitation so two simultaneous starts cannot create duplicate chats.
            $supplier = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $this->details->authorizeEdit($supplier);
            $existing = $this->chats->resumable('website', $supplier);
            return $this->started($existing ? $this->chats->reconnect($existing) : $this->chats->start('website', $supplier));
        });
    }

    public function send(string $id, Request $request): JsonResponse
    {
        $chat = $this->find($id);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.config('agentchat.max_length')],
            'request_id' => ['nullable', 'uuid'],
        ]);
        $text = trim($data['text']);
        abort_if($text === '', 422, 'Please type a message.');

        return DB::transaction(function () use ($chat, $text, $data) {
            $chat = AgentChat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($chat->status, ['done', 'fallback'], true) || $this->chats->expired($chat), 409, 'This chat has ended.');
            $key = empty($data['request_id']) ? null : 'chat.send.'.hash('sha256', $chat->public_id.':'.$data['request_id']);
            if ($key && ($previousId = Cache::get($key))) {
                $previous = $chat->messages()->withActualTime()->whereKey($previousId)->first();
                if ($previous) {
                    abort_if($previous->body !== $text, 409, 'This send identifier was already used for another message.');
                    return response()->json(['message' => $this->shape($previous)]);
                }
            }
            if ($chat->messages()->count() >= (int) config('agentchat.max_messages')) {
                return response()->json(['error' => 'chat_full', 'message' => 'This chat is full.'], 422);
            }
            $message = $this->chats->supplierMessage($chat, $text);
            if ($key) Cache::put($key, $message->id, 86400);
            return response()->json(['message' => $this->shape($message)]);
        });
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $chat = $this->find($id, closed: true);
        if ($chat->status === 'open' && ! $this->chats->expired($chat)
            && (! $chat->last_seen_at || $chat->last_seen_at->lt(now()->subSeconds(20)))) {
            $chat->update(['last_seen_at' => now()]);
        }
        $after = (int) $request->query('after', 0);

        return response()->json([
            'status' => $chat->status,
            'widget' => $chat->widget,
            'awaiting' => $chat->waiting_since !== null, // true while the supplier is waiting for Hermes
            'waiting_seconds' => $this->chats->waitingSeconds($chat),
            'timeout' => (int) config('agentchat.reply_timeout'),
            'messages' => $chat->messages()->withActualTime()->where('id', '>', $after)->get()->map(fn ($m) => $this->shape($m))->values(),
            'answers' => $chat->answers ?? (object) [],
        ]);
    }

    /** The supplier waited the whole time for Hermes: the page switches to the fixed chat and keeps what was collected. */
    public function fallback(string $id): JsonResponse
    {
        $chat = $this->find($id, closed: true);
        $answers = $this->chats->fallback($chat);

        return response()->json(['fallback' => $answers !== null, 'answers' => $answers ?? (object) []]);
    }

    /** The supplier confirmed on the review card (the real save happened through the normal endpoint). */
    public function finish(string $id): JsonResponse
    {
        $chat = $this->find($id, closed: true);
        if ($chat->status !== 'fallback') {
            $chat->update(['status' => 'done', 'waiting_since' => null]);
        }

        return response()->json(['ok' => true]);
    }

    private function started(?AgentChat $chat): JsonResponse
    {
        return $chat
            ? response()->json(['fallback' => false, 'chat_id' => $chat->public_id, 'answers' => $chat->answers ?? (object) [], 'timeout' => (int) config('agentchat.reply_timeout')])
            : response()->json(['fallback' => true]);
    }

    /** An unknown id is a plain 404. A website chat follows the same edit rules as the fixed chat. */
    private function find(string $id, bool $closed = false): AgentChat
    {
        $chat = AgentChat::where('public_id', $id)->firstOrFail();

        if ($chat->bot === 'website') {
            $this->details->authorizeEdit($chat->supplier);
        }
        if (! $closed) {
            abort_if(in_array($chat->status, ['done', 'fallback'], true) || $this->chats->expired($chat), 409, 'This chat has ended.');
        }

        return $chat;
    }

    private function shape($m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'text' => $m->body, 'at' => $m->sentAtIso()];
    }
}
