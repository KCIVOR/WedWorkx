<?php

namespace Tests\Feature;

use App\Models\AgentChat;
use App\Models\AgentChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatTimestampTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_generated_message_time_is_the_same_instant_in_utc_and_manila_sessions(): void
    {
        $zone = DB::selectOne('SELECT @@session.time_zone AS zone')->zone;
        try {
            DB::statement("SET time_zone = '+00:00'");
            $chat = AgentChat::create(['public_id' => Str::random(40), 'bot' => 'registration', 'status' => 'open', 'last_seen_at' => now()]);
            $message = $chat->messages()->create(['role' => 'supplier', 'body' => 'Hello']);
            $expected = (int) DB::table('agent_chat_messages')->where('id', $message->id)->value(DB::raw('UNIX_TIMESTAMP(created_at)'));
            $utc = AgentChatMessage::withActualTime()->findOrFail($message->id);
            $this->assertSame($expected, Carbon::parse($utc->sentAtIso())->timestamp);
            $this->assertSame($expected, Carbon::parse($message->sentAtIso())->timestamp); // immediate insert has no selected alias
            DB::statement("SET time_zone = '+08:00'");
            $manila = AgentChatMessage::withActualTime()->findOrFail($message->id);
            $this->assertNotSame($utc->getRawOriginal('created_at'), $manila->getRawOriginal('created_at'));
            $this->assertSame($utc->sentAtIso(), $manila->sentAtIso());
        } finally {
            DB::statement('SET time_zone = ?', [$zone]);
        }
    }

    public function test_supplier_admin_and_hermes_receive_the_actual_database_instant(): void
    {
        $zone = DB::selectOne('SELECT @@session.time_zone AS zone')->zone;
        try {
            DB::statement("SET time_zone = '+00:00'");
            config(['app.timezone' => 'Asia/Manila', 'hermes.token' => 'timestamp-test']);
            $chat = AgentChat::create(['public_id' => Str::random(40), 'bot' => 'registration', 'status' => 'open', 'last_seen_at' => now()]);
            $sent = $this->postJson('/agent-chat/'.$chat->public_id.'/messages', ['text' => 'Hello'])->assertOk()->json('message');
            $expected = (int) DB::table('agent_chat_messages')->where('id', $sent['id'])->value(DB::raw('UNIX_TIMESTAMP(created_at)'));
            $this->assertSame($expected, Carbon::parse($sent['at'])->timestamp);
            $poll = $this->getJson('/agent-chat/'.$chat->public_id)->assertOk()->json('messages.0.at');
            $this->assertSame($sent['at'], $poll);
            $hermes = $this->getJson('/api/hermes/chats/'.$chat->public_id, ['Authorization' => 'Bearer timestamp-test'])->assertOk()->json('data.transcript.0.at');
            $this->assertSame($sent['at'], $hermes);
            $this->actingAs(User::factory()->create());
            $admin = $this->getJson('/admin/chats/feed?selected='.$chat->id)->assertOk()->json('conversation.messages.0.at');
            $this->assertSame($sent['at'], $admin);
        } finally {
            DB::statement('SET time_zone = ?', [$zone]);
        }
    }
}
