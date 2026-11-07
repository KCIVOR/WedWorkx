<?php

namespace Tests\Feature;

use App\Models\AgentChat;
use App\Models\AgentChatMessage;
use App\Models\User;
use App\Services\AgentChatService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Hermes plugin holds one request open ("wait") instead of checking again and again, and only one consumer
 * (the plugin or the bridge) may answer chats at a time (a short lease).
 *
 * Timing here is real time: the wait loop must use a real clock, not the frozen test clock.
 */
class AgentChatLeaseTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hermes-test-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        Cache::flush();
        RateLimiter::clear('hermes');
        config(['hermes.token' => self::TOKEN, 'agentchat.wait_max' => 20, 'agentchat.lease_seconds' => 30]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    /** A chat that is waiting for Hermes (a new chat to greet). */
    private function waitingChat(): string
    {
        return AgentChat::create([
            'public_id' => Str::random(40), 'bot' => 'registration', 'status' => 'open', 'waiting_since' => now(),
        ])->public_id;
    }

    /** Runs a request and returns [response, seconds it took]. */
    private function timed(string $url, array $headers): array
    {
        $t = microtime(true);
        $response = $this->getJson($url, $headers);

        return [$response, microtime(true) - $t];
    }

    // ---------- wait ----------

    public function test_wait_returns_at_once_when_a_chat_is_waiting(): void
    {
        $id = $this->waitingChat();

        [$r, $seconds] = $this->timed('/api/hermes/chats?wait=5&consumer=wedworks-plugin', $this->auth());

        $r->assertOk();
        $this->assertSame($id, $r->json('data.0.chat_id'));
        $this->assertLessThan(1.5, $seconds);
    }

    public function test_wait_holds_the_request_when_nothing_is_waiting_and_then_returns_an_empty_list(): void
    {
        [$r, $seconds] = $this->timed('/api/hermes/chats?wait=2&consumer=wedworks-plugin', $this->auth());

        $r->assertOk()->assertJsonPath('data', []);
        $this->assertGreaterThanOrEqual(1.8, $seconds);
        $this->assertLessThan(4.0, $seconds);
        $this->assertTrue(app(AgentChatService::class)->hermesOnline()); // being connected counts as listening
    }

    public function test_the_wait_is_capped_by_the_server(): void
    {
        config(['agentchat.wait_max' => 1]);

        [$r, $seconds] = $this->timed('/api/hermes/chats?wait=20&consumer=wedworks-plugin', $this->auth());

        $r->assertOk()->assertJsonPath('data', []);
        $this->assertGreaterThanOrEqual(0.8, $seconds);
        $this->assertLessThan(3.0, $seconds);
    }

    public function test_the_wait_must_be_a_whole_number_between_zero_and_twenty(): void
    {
        foreach (['-1', 'abc', '2.5', '21'] as $bad) {
            $this->getJson("/api/hermes/chats?wait=$bad", $this->auth())->assertStatus(422);
        }
        $this->getJson('/api/hermes/chats?wait=0', $this->auth())->assertOk();
    }

    // ---------- chats the plugin is already working on ("seen") ----------

    private function say(string $publicId, string $role = 'supplier'): void
    {
        AgentChatMessage::create(['agent_chat_id' => AgentChat::where('public_id', $publicId)->value('id'), 'role' => $role, 'body' => 'hi']);
    }

    public function test_a_chat_the_caller_is_already_working_on_does_not_end_the_wait(): void
    {
        $id = $this->waitingChat(); // no messages yet: Hermes still has to greet

        [$r, $seconds] = $this->timed("/api/hermes/chats?wait=1&consumer=wedworks-plugin&seen=$id:0", $this->auth());

        $r->assertOk()->assertJsonPath('data', []);
        $this->assertGreaterThanOrEqual(0.8, $seconds); // held, because the only waiting chat is already being handled
    }

    public function test_a_chat_with_a_new_message_since_it_was_seen_is_returned_at_once(): void
    {
        $id = $this->waitingChat();
        $this->say($id); // the supplier wrote after the plugin saw 0 messages

        [$r, $seconds] = $this->timed("/api/hermes/chats?wait=5&consumer=wedworks-plugin&seen=$id:0", $this->auth());

        $this->assertSame($id, $r->json('data.0.chat_id'));
        $this->assertLessThan(1.5, $seconds);
    }

    public function test_seen_hides_only_the_chats_it_names(): void
    {
        $seen = $this->waitingChat();
        $other = $this->waitingChat();

        $r = $this->getJson("/api/hermes/chats?wait=0&consumer=wedworks-plugin&seen=$seen:0", $this->auth())->assertOk();

        $this->assertSame([$other], array_column($r->json('data'), 'chat_id'));
    }

    public function test_seen_must_be_a_list_of_chat_id_and_count_pairs(): void
    {
        foreach (['abc', 'abc:1', str_repeat('a', 40), str_repeat('a', 40).':x', str_repeat('a', 40).':1,', str_repeat(str_repeat('a', 40).':1,', 60).str_repeat('a', 40).':1'] as $bad) {
            $this->getJson('/api/hermes/chats?seen='.rawurlencode($bad), $this->auth())->assertStatus(422);
        }
        $this->getJson('/api/hermes/chats?seen='.str_repeat('a', 40).':1,'.str_repeat('b', 40).':12', $this->auth())->assertOk();
    }

    // A chat that arrives while a request is already being held cannot be tested here (one test process, one request at a
    // time). It is covered in Phase 4 (end to end against the real Hermes, with the site on Apache).

    // ---------- consumer and lease ----------

    public function test_a_second_consumer_gets_nothing_while_the_lease_is_held_and_is_not_counted_as_listening(): void
    {
        $this->waitingChat();
        $first = $this->getJson('/api/hermes/chats?consumer=wedworks-plugin', $this->auth())->assertOk()->assertJsonMissingPath('held_by');
        $this->assertCount(1, $first->json('data'));
        $seen = app(AgentChatService::class)->lastSeen();

        $this->travel(10)->seconds();
        [$second, $seconds] = $this->timed('/api/hermes/chats?wait=5&consumer=bridge', $this->auth());

        $second->assertOk()->assertJson(['data' => [], 'held_by' => 'wedworks-plugin']);
        $this->assertLessThan(1.5, $seconds); // a held-out caller is not made to wait
        $this->assertSame($seen, app(AgentChatService::class)->lastSeen()); // it did not refresh the heartbeat
    }

    public function test_the_lease_passes_to_the_other_consumer_after_it_expires(): void
    {
        $this->getJson('/api/hermes/chats?consumer=wedworks-plugin', $this->auth())->assertOk();

        $this->travel(31)->seconds();
        $this->waitingChat();
        $takeover = $this->getJson('/api/hermes/chats?consumer=bridge', $this->auth())->assertOk()->assertJsonMissingPath('held_by');

        $this->assertCount(1, $takeover->json('data'));
        $this->getJson('/api/hermes/chats?consumer=wedworks-plugin', $this->auth())
            ->assertOk()->assertJson(['data' => [], 'held_by' => 'bridge']);
    }

    public function test_the_holder_keeps_the_lease_by_asking_again(): void
    {
        $this->getJson('/api/hermes/chats?consumer=wedworks-plugin', $this->auth())->assertOk();
        $this->travel(20)->seconds();
        $this->getJson('/api/hermes/chats?consumer=wedworks-plugin', $this->auth())->assertOk();
        $this->travel(20)->seconds(); // 40 s after the first call, 20 s after the second

        $this->getJson('/api/hermes/chats?consumer=bridge', $this->auth())->assertOk()->assertJson(['held_by' => 'wedworks-plugin']);
    }

    public function test_callers_without_a_consumer_name_work_as_legacy(): void
    {
        $this->waitingChat();

        $this->getJson('/api/hermes/chats', $this->auth())->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('held_by');
        $this->getJson('/api/hermes/chats', $this->auth())->assertOk()->assertJsonCount(1, 'data'); // the same legacy caller again
        $this->getJson('/api/hermes/chats?consumer=bridge', $this->auth())->assertOk()->assertJson(['data' => [], 'held_by' => 'legacy']);
    }

    public function test_the_consumer_name_must_be_a_short_plain_word(): void
    {
        foreach (['a b', 'A', 'x@y', str_repeat('a', 31), '..'] as $bad) {
            $this->getJson('/api/hermes/chats?consumer='.rawurlencode($bad), $this->auth())->assertStatus(422);
        }
        $this->getJson('/api/hermes/chats?consumer=wedworks-plugin_2', $this->auth())->assertOk();
    }

    public function test_a_wrong_token_is_refused_at_once_and_never_held(): void
    {
        [$r, $seconds] = $this->timed('/api/hermes/chats?wait=5&consumer=wedworks-plugin', ['Authorization' => 'Bearer wrong']);

        $r->assertUnauthorized();
        $this->assertLessThan(1.5, $seconds);
        $this->assertNull(app(AgentChatService::class)->leaseHolder()); // a refused caller takes nothing
    }

    // ---------- the admin ----------

    public function test_the_admin_card_shows_which_consumer_is_active(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.com', 'password' => 'a-long-test-password-1']);

        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($p) => $p->where('agent.consumer', null));

        $this->getJson('/api/hermes/chats?consumer=wedworks-plugin', $this->auth())->assertOk();

        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($p) => $p
            ->where('agent.online', true)->where('agent.consumer', 'wedworks-plugin'));
    }
}
