<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Models\AgentChat;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WebsiteAnswer;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The Hermes agent asks the questions through a relay in the website chat. */
class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hermes-test-token-123';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        Cache::flush();
        RateLimiter::clear('hermes');
        config(['hermes.token' => self::TOKEN, 'agentchat.mode.registration' => 'hermes', 'agentchat.mode.website' => 'hermes']);
        $this->travelTo(now()->startOfMinute());
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    /** Hermes asks for waiting chats: that is what makes it "listening". */
    private function listen(): array
    {
        return $this->getJson('/api/hermes/chats', $this->auth())->assertOk()->json('data');
    }

    private function supplier(string $type = 'photographer'): Supplier
    {
        $this->n++;

        return app(SlotService::class)->register([
            'name' => "Maria Santos $this->n", 'email' => "s$this->n@example.com", 'mobile' => '+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Rosal Studio $this->n", 'business_type' => $type, 'location' => 'Tagaytay City, Cavite',
        ])['supplier']->fresh();
    }

    private function startRegistration(): string
    {
        $this->listen();

        return $this->postJson('/agent-chat/registration/start')->assertOk()->assertJson(['fallback' => false])->json('chat_id');
    }

    private function startWebsite(Supplier $s): string
    {
        $this->listen();

        return $this->postJson("/invite/{$s->invite_token}/agent-chat/start")->assertOk()->assertJson(['fallback' => false])->json('chat_id');
    }

    private function coreAnswers(array $o = []): array
    {
        return $o + [
            'name' => 'Maria Santos', 'email' => 'Maria@Example.com', 'mobile' => '0917 123 4567', 'business_name' => 'Rosal Studio',
            'business_type' => 'photographer', 'location' => 'Tagaytay City, Cavite',
            'social_link' => '',
        ];
    }

    // ---------- starting: the fixed chat takes over without the supplier noticing ----------

    public function test_the_system_mode_uses_the_fixed_chat_and_logs_nothing(): void
    {
        config(['agentchat.mode.registration' => 'system']);

        $this->postJson('/agent-chat/registration/start')->assertOk()->assertExactJson(['fallback' => true]);

        $this->assertSame(0, AgentChat::count());
        $this->assertSame(0, AuditLog::where('action', 'hermes_unreachable')->count());
    }

    public function test_an_unreachable_hermes_switches_to_the_fixed_chat_and_is_written_to_the_activity_page(): void
    {
        $this->postJson('/agent-chat/registration/start')->assertOk()->assertExactJson(['fallback' => true]); // Hermes never listened

        $log = AuditLog::where('action', 'hermes_unreachable')->sole();
        $this->assertSame(['bot' => 'registration', 'reason' => 'offline_at_start'], $log->detail);
        $this->assertNull($log->user_id);
        $this->assertSame(0, AgentChat::count());

        $this->listen();
        $this->travel(46)->seconds(); // it stopped listening
        $this->postJson('/agent-chat/registration/start')->assertExactJson(['fallback' => true]);
        $this->assertSame(2, AuditLog::where('action', 'hermes_unreachable')->count());
    }

    public function test_the_registration_page_tells_the_browser_only_whether_to_try_hermes(): void
    {
        $this->get('/')->assertInertia(fn ($p) => $p->where('agent.enabled', true)->missing('agent.online'));
        config(['agentchat.mode.registration' => 'system']);
        $this->get('/')->assertInertia(fn ($p) => $p->where('agent.enabled', false));
    }

    // ---------- registration through Hermes ----------

    public function test_hermes_must_offer_optional_questions_before_review_and_can_skip_them(): void
    {
        $id = $this->startRegistration();
        $answers = $this->coreAnswers();
        unset($answers['social_link']);
        $this->postJson("/api/hermes/chats/$id/answers", ['answers' => $answers], $this->auth())->assertOk();
        $this->getJson("/api/hermes/chats/$id", $this->auth())->assertJsonPath('data.next_question.key', 'social_link');
        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())
            ->assertStatus(409)->assertJsonPath('missing.0.key', 'social_link');
        $this->postJson("/api/hermes/chats/$id/answers", ['answers' => ['social_link' => '']], $this->auth())->assertOk();
        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertOk();
        $this->getJson("/api/hermes/chats/$id", $this->auth())->assertJsonPath('data.next_question', null);
    }

    public function test_website_review_waits_for_optional_and_partial_questions_in_order(): void
    {
        $s = $this->supplier();
        $id = $this->startWebsite($s);
        $answers = [
            'description' => 'A company description.', 'style' => 'modern',
            'extra1' => 'Full-day coverage', 'extra2' => 'Both photos and videos',
            'service_areas' => ['Cavite'],
        ];
        $this->postJson("/api/hermes/chats/$id/answers", ['answers' => $answers], $this->auth())->assertOk();
        $result = $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertStatus(409)->json();
        $this->assertSame(['services', 'areas', 'media', 'additional'], array_column($result['missing'], 'key'));
        $this->getJson("/api/hermes/chats/$id", $this->auth())->assertJsonPath('data.next_question.key', 'services');
        $this->postJson("/api/hermes/chats/$id/answers", ['answers' => [
            'services' => [], 'booking' => '', 'colors' => '', 'additional' => '',
        ]], $this->auth())->assertOk();
        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertOk();
    }

    public function test_hermes_runs_the_registration_and_the_supplier_confirms_it(): void
    {
        $id = $this->startRegistration();
        $this->assertCount(1, collect($this->listen())->where('chat_id', $id)); // waiting for Hermes to greet

        $this->postJson("/api/hermes/chats/$id/messages", ['text' => 'Hi! May I know your name?'], $this->auth())->assertOk();
        $this->assertSame([], $this->listen()); // nothing waits for Hermes now

        $this->postJson("/agent-chat/$id/messages", ['text' => 'Maria Santos'])->assertOk()->assertJsonPath('message.role', 'supplier');
        $this->assertCount(1, $this->listen());

        $detail = $this->getJson("/api/hermes/chats/$id", $this->auth())->assertOk()->json('data');
        $this->assertSame('registration', $detail['bot']);
        $this->assertSame(['name', 'email', 'mobile', 'business_name', 'business_type', 'location', 'social_link'], array_column($detail['questions'], 'key'));
        $this->assertStringContainsString('Philippine mobile', $detail['questions'][2]['rules']);
        $this->assertCount(12, $detail['questions'][4]['options']);
        $this->assertSame(['Maria Santos'], [$detail['transcript'][1]['text']]);

        $r = $this->postJson("/api/hermes/chats/$id/answers", ['answers' => $this->coreAnswers(['email' => 'not-an-email'])], $this->auth())->assertOk()->json();
        $this->assertFalse($r['ok']);
        $this->assertSame(['email'], array_keys($r['errors']));
        $this->assertNotContains('email', $r['accepted']);

        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertStatus(409)->assertJsonPath('error', 'not_ready')->assertJsonPath('missing.0.key', 'email');

        $ok = $this->postJson("/api/hermes/chats/$id/answers", ['answers' => ['email' => 'Maria@Example.com']], $this->auth())->assertOk()->json();
        $this->assertTrue($ok['ok']);
        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertOk()->assertJsonPath('data.status', 'review');

        // The browser gets the collected answers, cleaned the same way as the form.
        $poll = $this->getJson("/agent-chat/$id")->assertOk()->assertJsonPath('status', 'review')->json();
        $this->assertSame('maria@example.com', $poll['answers']['email']);
        $this->assertSame('+639171234567', $poll['answers']['mobile']);
        $this->assertSame(0, Supplier::count()); // Hermes did not create anything

        // The supplier presses Confirm: the normal sign-up creates the supplier.
        $this->post('/register', $poll['answers'])->assertSessionHasNoErrors();
        $s = Supplier::sole();
        $this->assertSame(SupplierStatus::Invited, $s->status);
        $this->assertSame('+639171234567', $s->mobile);

        $this->postJson("/agent-chat/$id/finish")->assertOk();
        $this->assertSame('done', AgentChat::sole()->status);
        $this->postJson("/api/hermes/chats/$id/messages", ['text' => 'late'], $this->auth())->assertNotFound(); // ended chats are closed to Hermes
    }

    public function test_a_question_that_is_not_in_the_chat_is_refused(): void
    {
        $id = $this->startRegistration();

        $r = $this->postJson("/api/hermes/chats/$id/answers", ['answers' => ['status' => 'paid', 'name' => 'Maria']], $this->auth())->assertOk()->json();

        $this->assertFalse($r['ok']);
        $this->assertSame(['status'], array_keys($r['errors']));
        $this->assertSame(['name'], $r['accepted']);
        $this->assertSame(['name' => 'Maria'], AgentChat::sole()->answers);
    }

    public function test_admin_added_registration_questions_are_part_of_the_chat(): void
    {
        $this->signedInAdmin()->post('/admin/questions/registration', ['label' => 'Heard', 'text' => 'How did you hear about us?', 'type' => 'text', 'required' => true]);
        $key = \App\Models\RegistrationQuestion::orderByDesc('id')->value('key');
        $id = $this->startRegistration();

        $detail = $this->getJson("/api/hermes/chats/$id", $this->auth())->json('data');
        $this->assertSame($key, collect($detail['questions'])->last()['key']);

        $this->postJson("/api/hermes/chats/$id/answers", ['answers' => $this->coreAnswers()], $this->auth());
        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertStatus(409)->assertJsonPath('missing.0.key', $key);
        $this->postJson("/api/hermes/chats/$id/answers", ['answers' => [$key => 'A friend']], $this->auth())->assertJsonPath('ok', true);
        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertOk();
    }

    // ---------- the website chat through Hermes ----------

    public function test_hermes_collects_the_website_answers_and_the_supplier_confirms_them(): void
    {
        $s = $this->supplier();
        $id = $this->startWebsite($s);

        $detail = $this->getJson("/api/hermes/chats/$id", $this->auth())->assertOk()->json('data');
        $this->assertSame('website', $detail['bot']);
        $this->assertSame('Maria', $detail['supplier']['first_name']);
        $this->assertSame('Photographer / Videographer', $detail['supplier']['business_type_label']);
        $this->assertSame(['description', 'services', 'areas', 'media', 'style', 'additional', 'extra1', 'extra2'], array_column($detail['questions'], 'key'));
        $this->assertStringContainsString('Rosal Studio 1', $detail['questions'][0]['text']);

        $answers = [
            'description' => 'Candid, warm wedding photography.', 'services' => [['name' => 'Full day', 'price' => '25k'], ['name' => '', 'price' => '']],
            'service_areas' => ['Tagaytay', ' Silang '], 'booking' => 'Messenger', 'colors' => 'Pink', 'style' => 'romantic', 'additional' => '',
            'extra1' => 'Full-day coverage', 'extra2' => 'Both photos and videos',
        ];
        $r = $this->postJson("/api/hermes/chats/$id/answers", ['answers' => $answers], $this->auth())->assertOk()->json();
        $this->assertTrue($r['ok']);
        $this->assertSame([['name' => 'Full day', 'price' => '25k']], AgentChat::sole()->answers['services']);
        $this->assertSame(['Tagaytay', 'Silang'], AgentChat::sole()->answers['service_areas']);

        $bad = $this->postJson("/api/hermes/chats/$id/answers", ['answers' => ['style' => 'neon', 'extra1' => 'Not an option']], $this->auth())->json();
        $this->assertFalse($bad['ok']);
        $this->assertEqualsCanonicalizing(['style', 'extra1'], array_keys($bad['errors']));

        $this->postJson("/api/hermes/chats/$id/ready", [], $this->auth())->assertOk();
        $poll = $this->getJson("/agent-chat/$id")->assertJsonPath('status', 'review')->json();

        $this->post("/invite/{$s->invite_token}/confirm", $poll['answers'])->assertSessionHasNoErrors();
        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);
        $this->assertSame('romantic', WebsiteAnswer::sole()->answers['style']);
        $this->assertNotNull(WebsiteAnswer::sole()->question_snapshot);
    }

    public function test_hermes_can_ask_for_the_upload_buttons_only_in_the_website_chat(): void
    {
        $s = $this->supplier();
        $site = $this->startWebsite($s);
        $reg = $this->startRegistration();

        $this->postJson("/api/hermes/chats/$site/messages", ['text' => 'Please add your photos.', 'widget' => 'upload'], $this->auth())->assertOk();
        $this->getJson("/agent-chat/$site")->assertJsonPath('widget', 'upload');
        $this->postJson("/agent-chat/$site/messages", ['text' => 'Done'])->assertOk();
        $this->getJson("/agent-chat/$site")->assertJsonPath('widget', null);

        $this->postJson("/api/hermes/chats/$reg/messages", ['text' => 'Photos?', 'widget' => 'upload'], $this->auth())->assertStatus(422);
    }

    public function test_the_website_chat_follows_the_same_rules_as_the_fixed_chat(): void
    {
        $s = $this->supplier();
        $this->listen();
        $this->postJson('/invite/nope/agent-chat/start')->assertNotFound();

        $s->update(['status' => SupplierStatus::Expired]);
        $this->postJson("/invite/{$s->invite_token}/agent-chat/start")->assertForbidden();

        $paid = $this->supplier();
        $id = $this->startWebsite($paid);
        $paid->update(['status' => SupplierStatus::Paid]);
        $this->postJson("/agent-chat/$id/messages", ['text' => 'hi'])->assertForbidden();
        $this->getJson("/agent-chat/$id")->assertForbidden();
    }

    // ---------- going quiet: the fixed chat quietly takes over ----------

    public function test_when_hermes_goes_quiet_the_chat_falls_back_keeping_what_was_collected_and_it_is_logged(): void
    {
        $id = $this->startRegistration();
        $this->postJson("/api/hermes/chats/$id/messages", ['text' => 'Hi!'], $this->auth());
        $this->postJson("/api/hermes/chats/$id/answers", ['answers' => ['name' => 'Maria Santos']], $this->auth());
        $this->postJson("/agent-chat/$id/messages", ['text' => 'maria@example.com']);

        $this->travel(20)->seconds();
        $early = $this->postJson("/agent-chat/$id/fallback")->assertOk()->json();
        $this->assertFalse($early['fallback']);
        $this->assertSame(0, AuditLog::where('action', 'hermes_unreachable')->count());
        $this->getJson("/agent-chat/$id")->assertJsonPath('status', 'open')->assertJsonPath('awaiting', true);

        $this->travel(6)->seconds(); // 26 seconds with no reply
        $this->getJson("/agent-chat/$id")->assertJsonPath('waiting_seconds', 26)->assertJsonPath('timeout', 25);
        $late = $this->postJson("/agent-chat/$id/fallback")->assertOk()->json();

        $this->assertTrue($late['fallback']);
        $this->assertSame(['name' => 'Maria Santos'], $late['answers']);
        $this->assertSame(['bot' => 'registration', 'reason' => 'no_reply'], AuditLog::where('action', 'hermes_unreachable')->sole()->detail);

        // Asking again does not log again; the chat is closed to Hermes.
        $this->postJson("/agent-chat/$id/fallback")->assertJsonPath('fallback', true);
        $this->assertSame(1, AuditLog::where('action', 'hermes_unreachable')->count());
        $this->postJson("/api/hermes/chats/$id/messages", ['text' => 'sorry'], $this->auth())->assertNotFound();
        $this->postJson("/agent-chat/$id/messages", ['text' => 'hello?'])->assertStatus(409);
    }

    public function test_a_reply_resets_the_waiting_time(): void
    {
        $id = $this->startRegistration();
        $this->travel(24)->seconds();
        $this->postJson("/api/hermes/chats/$id/messages", ['text' => 'Hi!'], $this->auth());
        $this->travel(10)->seconds();

        $this->getJson("/agent-chat/$id")->assertJsonPath('waiting_seconds', 0)->assertJsonPath('awaiting', false);
        $this->postJson("/agent-chat/$id/fallback")->assertJsonPath('fallback', false);
    }

    // ---------- limits and access ----------

    public function test_message_limits_and_unknown_chats(): void
    {
        $id = $this->startRegistration();

        $this->postJson("/agent-chat/$id/messages", ['text' => str_repeat('x', 1001)])->assertStatus(422);
        $this->postJson("/agent-chat/$id/messages", ['text' => '   '])->assertStatus(422);

        config(['agentchat.max_messages' => 2]);
        $this->postJson("/agent-chat/$id/messages", ['text' => 'one'])->assertOk();
        $this->postJson("/agent-chat/$id/messages", ['text' => 'two'])->assertOk();
        $this->postJson("/agent-chat/$id/messages", ['text' => 'three'])->assertStatus(422)->assertJsonPath('error', 'chat_full');

        $this->getJson('/agent-chat/'.str_repeat('a', 40))->assertNotFound();
        $this->postJson('/agent-chat/nope/messages', ['text' => 'hi'])->assertNotFound();
        $this->getJson('/api/hermes/chats/nope', $this->auth())->assertNotFound();
    }

    public function test_every_hermes_chat_endpoint_needs_the_token(): void
    {
        $id = $this->startRegistration();

        foreach ([['GET', '/api/hermes/chats'], ['GET', "/api/hermes/chats/$id"], ['POST', "/api/hermes/chats/$id/messages"], ['POST', "/api/hermes/chats/$id/answers"], ['POST', "/api/hermes/chats/$id/ready"]] as [$verb, $path]) {
            $this->json($verb, $path, ['text' => 'x', 'answers' => []])->assertUnauthorized();
            $this->json($verb, $path, ['text' => 'x', 'answers' => []], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        }
    }

    public function test_the_browser_never_receives_hermes_only_details(): void
    {
        $id = $this->startRegistration();

        $json = $this->getJson("/agent-chat/$id")->assertOk()->json();

        $this->assertEqualsCanonicalizing(['status', 'widget', 'awaiting', 'waiting_seconds', 'timeout', 'messages', 'answers'], array_keys($json));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($json));
    }

    public function test_polling_one_chat_never_uses_up_the_allowance_for_starting_or_for_other_chats(): void
    {
        $a = $this->startRegistration();
        $b = $this->startRegistration();

        for ($i = 0; $i < 70; $i++) { // far more polls than the start limit; a busy chat page does this in under two minutes
            $this->getJson("/agent-chat/$a")->assertOk();
        }

        $this->postJson('/agent-chat/registration/start')->assertOk()->assertJson(['fallback' => false]);
        $this->getJson("/agent-chat/$b")->assertOk(); // another chat is not affected either
    }

    // ---------- the admin ----------

    private function signedInAdmin(): static
    {
        return $this->actingAs(User::factory()->create(['email' => 'boss@example.com', 'password' => 'a-long-test-password-1']));
    }

    public function test_the_admin_can_switch_who_asks_and_sees_whether_hermes_is_listening(): void
    {
        config(['agentchat.mode.registration' => 'system', 'agentchat.mode.website' => 'system']);

        $this->signedInAdmin()->get('/admin/settings')->assertInertia(fn ($p) => $p
            ->where('fields.chat_mode_website.value', 'system')->where('agent.online', false)->where('agent.seconds_ago', null)->where('agent.timeout', 25));

        $this->post('/admin/settings/chat', ['chat_mode_registration' => 'hermes', 'chat_mode_website' => 'hermes'])->assertSessionHasNoErrors();
        $this->assertSame('hermes', config('agentchat.mode.website'));
        $this->post('/admin/settings/chat', ['chat_mode_registration' => 'robot'])->assertSessionHasErrors('chat_mode_registration');

        $this->listen();
        $this->get('/admin/settings')->assertInertia(fn ($p) => $p->where('agent.online', true)->where('agent.seconds_ago', 0));
    }

    public function test_unreachable_events_show_on_the_activity_page(): void
    {
        $this->postJson('/agent-chat/registration/start'); // Hermes offline

        $this->signedInAdmin()->get('/admin/activity')->assertInertia(fn ($p) => $p->where('logs.data.0.action', 'hermes_unreachable')->where('logs.data.0.detail.reason', 'offline_at_start'));
    }
}
