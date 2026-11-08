<?php

namespace Tests\Feature;

use App\Models\AgentChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrying_the_same_send_returns_the_existing_message_even_at_the_message_limit(): void
    {
        $chat = AgentChat::create(['public_id' => Str::random(40), 'bot' => 'registration', 'status' => 'open']);
        $url = '/agent-chat/'.$chat->public_id.'/messages';
        $data = ['text' => 'Please help', 'request_id' => (string) Str::uuid()];
        config(['agentchat.max_messages' => 1]);
        $first = $this->postJson($url, $data)->assertOk()->json('message');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('message.id', $first['id']);
        $this->assertSame(1, $chat->messages()->count());
        $this->postJson($url, ['text' => 'Different', 'request_id' => $data['request_id']])->assertConflict();
    }

    public function test_distinct_sends_can_intentionally_use_the_same_text_and_legacy_clients_still_work(): void
    {
        $chat = AgentChat::create(['public_id' => Str::random(40), 'bot' => 'registration', 'status' => 'open']);
        $url = '/agent-chat/'.$chat->public_id.'/messages';
        foreach (range(1, 2) as $i) $this->postJson($url, ['text' => 'Yes', 'request_id' => (string) Str::uuid()])->assertOk();
        $this->postJson($url, ['text' => 'Legacy'])->assertOk();
        $this->assertSame(3, $chat->messages()->count());
    }
}
