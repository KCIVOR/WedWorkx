<?php

namespace Tests\Feature;

use App\Models\AgentChat;
use App\Models\Supplier;
use App\Services\AgentChatService;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        config(['agentchat.mode.registration' => 'hermes', 'agentchat.mode.website' => 'hermes']);
        $this->travelTo(now()->startOfSecond());
        app(AgentChatService::class)->heartbeat();
    }

    private function supplier(): Supplier
    {
        return app(SlotService::class)->register(['name' => 'Resume Preview', 'email' => 'resume@example.test',
            'mobile' => '+639171234567', 'business_name' => 'Resume Studio', 'business_type' => 'cake', 'location' => 'Tagaytay'])['supplier'];
    }

    public function test_website_invitation_resumes_same_chat_without_browser_storage_even_when_agent_temporarily_offline(): void
    {
        $supplier = $this->supplier();
        $url = '/invite/'.$supplier->invite_token.'/agent-chat/start';
        $id = $this->postJson($url)->assertOk()->json('chat_id');
        $chat = AgentChat::where('public_id', $id)->firstOrFail();
        $chat->update(['answers' => ['description' => 'Existing answer']]);
        $chat->messages()->create(['role' => 'supplier', 'body' => 'Existing message']);
        $this->travel(60)->seconds();
        $this->postJson($url)->assertOk()->assertJsonPath('chat_id', $id);
        $this->assertSame(1, AgentChat::count());
        $this->assertSame(['description' => 'Existing answer'], $chat->fresh()->answers);
        $this->getJson('/agent-chat/'.$id)->assertJsonPath('messages.0.text', 'Existing message');
        $this->get('/invite/'.$supplier->invite_token)->assertInertia(fn ($p) => $p->where('chat.agent.resume', true));
    }

    public function test_registration_cookie_resumes_only_the_correct_bot_and_unexpired_open_or_review_chat(): void
    {
        $id = $this->postJson('/agent-chat/registration/start')->assertOk()->assertCookie('wedworks_registration_chat')->json('chat_id');
        $this->withCredentials()->withCookie('wedworks_registration_chat', $id)->postJson('/agent-chat/registration/start')->assertJsonPath('chat_id', $id);
        $this->assertSame(1, AgentChat::count());
        $this->get('/')->assertInertia(fn ($p) => $p->where('agent.resume', true));
        AgentChat::where('public_id', $id)->update(['status' => 'review']);
        $this->postJson('/agent-chat/registration/start')->assertJsonPath('chat_id', $id);
        AgentChat::where('public_id', $id)->update(['status' => 'done']);
        $new = $this->postJson('/agent-chat/registration/start')->assertOk()->json('chat_id');
        $this->assertNotSame($id, $new);
    }

    public function test_resume_does_not_bypass_system_mode_or_locked_website_details(): void
    {
        $supplier = $this->supplier();
        $url = '/invite/'.$supplier->invite_token.'/agent-chat/start';
        $this->postJson($url)->assertOk();
        config(['agentchat.mode.website' => 'system']);
        $this->postJson($url)->assertExactJson(['fallback' => true]);
        $supplier->update(['status' => 'paid']);
        $this->postJson($url)->assertForbidden();
    }

    public function test_reconnect_does_not_overwrite_a_reply_that_arrived_after_the_session_was_looked_up(): void
    {
        $service = app(AgentChatService::class);
        $chat = $service->start('registration', null);
        $stale = $chat->fresh();
        $this->travel(60)->seconds();
        $service->hermesMessage($chat, 'Hello again', null);
        $resumed = $service->reconnect($stale);
        $this->assertNull($resumed->waiting_since);
        $this->assertSame(1, $resumed->messages()->count());
    }

    public function test_confirmed_registration_closes_cookie_session_and_clears_recovery_cookie(): void
    {
        $id = $this->postJson('/agent-chat/registration/start')->assertOk()->json('chat_id');
        $this->withCookie('wedworks_registration_chat', $id)->post('/register', [
            'name' => 'New Registration', 'email' => 'new@example.test', 'mobile' => '09171234567',
            'business_name' => 'New Studio', 'business_type' => 'cake', 'location' => 'Tagaytay', 'social_link' => '',
        ])->assertRedirect('/');
        $this->assertSame('done', AgentChat::where('public_id', $id)->firstOrFail()->status);
    }
}
