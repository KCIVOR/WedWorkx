<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LiveChatController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate(['supplier' => ['nullable', 'integer', 'min:1']]);
        return Inertia::render('Admin/LiveChats', ['supplierFilter' => isset($data['supplier']) ? (int) $data['supplier'] : null]);
    }

    public function feed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selected' => ['nullable', 'integer', 'min:1'],
            'supplier' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = AgentChat::query()->where('status', 'open')
            ->where('last_seen_at', '>=', now()->subSeconds(90))
            ->where('created_at', '>=', now()->subHours((int) config('agentchat.expire_hours')))
            ->where(fn ($q) => $q->where('bot', 'registration')->orWhereHas('supplier'));
        if (!empty($data['supplier'])) $query->where('supplier_id', $data['supplier']);
        $sessions = (clone $query)->with('supplier:id,name,business_name')->orderByDesc('last_seen_at')->limit(50)->get();
        $selected = empty($data['selected']) ? null : (clone $query)->with('supplier:id,name,business_name')->find($data['selected']);
        $conversation = null;
        if ($selected) {
            $conversation = $this->summary($selected) + [
                'messages' => $selected->messages()->limit(200)->get()->map(fn ($m) => [
                    'id' => $m->id, 'role' => $m->role, 'text' => $m->body, 'at' => $m->created_at->toIso8601String(),
                ])->values(),
            ];
        }
        return response()->json([
            'sessions' => $sessions->map(fn ($c) => $this->summary($c))->values(),
            'conversation' => $conversation,
            'checkedAt' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function summary(AgentChat $chat): array
    {
        return [
            'id' => $chat->id,
            'bot' => $chat->bot,
            'name' => $chat->supplier?->name ?? ($chat->answers['name'] ?? 'Registration visitor #'.$chat->id),
            'business' => $chat->supplier?->business_name ?? ($chat->answers['business_name'] ?? null),
            'supplierId' => $chat->supplier_id,
            'waiting' => $chat->waiting_since !== null,
            'lastSeen' => $chat->last_seen_at?->toIso8601String(),
        ];
    }
}
