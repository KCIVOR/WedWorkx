<?php

namespace Tests\Feature;

use App\Models\AgentChat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminLiveChatTest extends TestCase
{
    use RefreshDatabase;

    private function chat(array $attributes = []): AgentChat
    {
        $chat = new AgentChat;
        $chat->forceFill($attributes + ['public_id' => Str::random(40), 'bot' => 'registration', 'status' => 'open', 'last_seen_at' => now()])->save();
        return $chat;
    }

    public function test_guests_cannot_view_live_sessions(): void
    {
        $this->get('/admin/chats')->assertRedirect('/admin/login');
        $this->getJson('/admin/chats/feed')->assertUnauthorized();
    }

    public function test_only_open_recent_browser_sessions_are_listed_without_public_chat_secrets(): void
    {
        $live = $this->chat(['answers' => ['name' => 'Live visitor']]);
        $this->chat(['status' => 'done']);
        $this->chat(['status' => 'review']);
        $this->chat(['status' => 'fallback']);
        $this->chat(['last_seen_at' => now()->subSeconds(91)]);
        $this->chat(['created_at' => now()->subHours(25)]);
        $this->actingAs(User::factory()->create());
        $r = $this->getJson('/admin/chats/feed')->assertOk()->assertJsonCount(1, 'sessions')->assertJsonPath('sessions.0.id', $live->id);
        $this->assertStringNotContainsString($live->public_id, $r->getContent());
        $this->assertArrayNotHasKey('answers', $r->json('sessions.0'));
    }

    public function test_live_view_updates_without_changing_messages_answers_or_waiting_state(): void
    {
        $chat = $this->chat(['waiting_since' => now(), 'answers' => ['name' => 'Maria']]);
        $chat->messages()->create(['role' => 'supplier', 'body' => '<script>hello</script>']);
        $this->actingAs(User::factory()->create());
        $before = $chat->fresh()->getAttributes();
        $url = '/admin/chats/feed?selected='.$chat->id;
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'conversation.messages')->assertJsonPath('conversation.waiting', true)
            ->assertJsonPath('conversation.messages.0.text', '<script>hello</script>')->assertJsonStructure(['conversation' => ['messages' => [['at']]]]);
        $chat->messages()->create(['role' => 'hermes', 'body' => 'Hello Maria']);
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'conversation.messages');
        $this->assertSame($before, $chat->fresh()->getAttributes());
        $chat->update(['status' => 'done']);
        $this->getJson($url)->assertJsonCount(0, 'sessions')->assertJsonPath('conversation', null);
    }

    public function test_browser_poll_records_presence_but_admin_poll_does_not_keep_an_abandoned_chat_open(): void
    {
        $chat = $this->chat(['last_seen_at' => now()->subSeconds(80)]);
        $this->getJson('/agent-chat/'.$chat->public_id)->assertOk();
        $this->assertSame(now()->timestamp, $chat->fresh()->last_seen_at->timestamp);
        $this->actingAs(User::factory()->create());
        $this->travel(91)->seconds();
        $this->getJson('/admin/chats/feed?selected='.$chat->id)->assertJsonCount(0, 'sessions')->assertJsonPath('conversation', null);
    }

    public function test_supplier_filter_also_prevents_an_unrelated_selected_conversation(): void
    {
        $chat = $this->chat();
        $this->actingAs(User::factory()->create());
        $this->getJson('/admin/chats/feed?supplier=999&selected='.$chat->id)
            ->assertOk()->assertJsonCount(0, 'sessions')->assertJsonPath('conversation', null);
        $this->getJson('/admin/chats/feed?selected=invalid')->assertUnprocessable();
    }

    public function test_admin_page_renders_the_live_view_with_no_transcript_in_initial_props(): void
    {
        $chat = $this->chat();
        $chat->messages()->create(['role' => 'supplier', 'body' => 'Private transcript']);
        $this->actingAs(User::factory()->create());
        $this->get('/admin/chats')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Admin/LiveChats')->where('supplierFilter', null)->missing('messages')->missing('sessions'));
    }
}
