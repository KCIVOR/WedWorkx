<?php

namespace App\Http\Controllers;

use App\Models\AgentChat;
use App\Models\Supplier;
use App\Services\AgentChatService;
use App\Services\WebsiteDetailsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The supplier's side of a chat the Hermes agent runs. The browser only sends the supplier's own words and reads
 * Hermes' replies. Nothing here tells the supplier when Hermes is unreachable: the page quietly switches to the fixed chat.
 */
class AgentChatController extends Controller
{
    public function __construct(private AgentChatService $chats, private WebsiteDetailsService $details)
    {
    }

    public function startRegistration(): JsonResponse
    {
        return $this->started($this->chats->start('registration', null));
    }

    public function startWebsite(string $token): JsonResponse
    {
        $supplier = Supplier::where('invite_token', $token)->firstOrFail();
        $this->details->authorizeEdit($supplier); // same rule as the fixed chat

        return $this->started($this->chats->start('website', $supplier));
    }

    public function send(string $id, Request $request): JsonResponse
    {
        $chat = $this->find($id);
        $data = $request->validate(['text' => ['required', 'string', 'max:'.config('agentchat.max_length')]]);
        $text = trim($data['text']);
        abort_if($text === '', 422, 'Please type a message.');

        if ($chat->messages()->count() >= (int) config('agentchat.max_messages')) {
            return response()->json(['error' => 'chat_full', 'message' => 'This chat is full.'], 422);
        }

        $message = $this->chats->supplierMessage($chat, $text);

        return response()->json(['message' => $this->shape($message)]);
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
            'answers' => in_array($chat->status, ['review', 'done', 'fallback'], true) ? ($chat->answers ?? (object) []) : null,
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
            ? response()->json(['fallback' => false, 'chat_id' => $chat->public_id, 'timeout' => (int) config('agentchat.reply_timeout')])
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
