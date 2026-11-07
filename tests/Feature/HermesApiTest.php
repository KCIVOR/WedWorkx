<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Models\HermesStageLog;
use App\Models\Order;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Models\Upload;
use App\Models\WebsiteAnswer;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class HermesApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hermes-test-token-123';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        config(['hermes.token' => self::TOKEN]);
        RateLimiter::clear('hermes');
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    /** A supplier in the given status; an order exists from `paid` onwards. */
    private function supplier(SupplierStatus $status = SupplierStatus::Paid, int $stage = 0): Supplier
    {
        $this->n++;
        $s = app(SlotService::class)->register([
            'name' => "Maria Santos $this->n",
            'email' => "s$this->n@example.com",
            'mobile' => '+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Rosal Catering $this->n",
            'business_type' => 'caterer',
            'location' => 'Tagaytay City, Cavite',
        ])['supplier'];
        $s->update(['status' => $status]);
        WebsiteAnswer::create(['supplier_id' => $s->id, 'answers' => ['description' => "About $this->n", 'style' => 'romantic'], 'submitted_at' => now()]);

        if (in_array($status, [SupplierStatus::Paid, SupplierStatus::Building, SupplierStatus::Delivered], true)) {
            Order::create(['supplier_id' => $s->id, 'reference' => 'KT10-'.str_pad((string) $this->n, 4, 'A', STR_PAD_LEFT), 'current_stage' => $stage, 'paid_at' => now()]);
        }

        return $s->fresh('order');
    }

    private function ref(Supplier $s): string
    {
        return $s->order->reference;
    }

    // ---------- authentication ----------

    public function test_every_endpoint_rejects_missing_wrong_or_malformed_tokens(): void
    {
        $s = $this->supplier();
        $ref = $this->ref($s);
        $calls = [
            ['GET', '/api/hermes/orders'],
            ['GET', "/api/hermes/orders/$ref"],
            ['POST', "/api/hermes/orders/$ref/stage", ['stage' => 1]],
            ['POST', "/api/hermes/orders/$ref/deliver", ['website_url' => 'https://x.example']],
        ];
        $headersList = [
            [],
            ['Authorization' => 'Bearer wrong-token'],
            ['Authorization' => 'Bearer '.self::TOKEN.'x'],
            ['Authorization' => 'Basic '.base64_encode(self::TOKEN)],
            ['Authorization' => self::TOKEN],
            ['Authorization' => 'Bearer '],
        ];

        foreach ($calls as $call) {
            [$method, $url] = $call;
            $body = $call[2] ?? [];
            foreach ($headersList as $headers) {
                $this->json($method, $url, $body, $headers)->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
            }
        }

        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
        $this->assertSame(0, HermesStageLog::count());
    }

    public function test_nothing_gets_in_when_no_token_is_configured(): void
    {
        config(['hermes.token' => null]);
        $this->getJson('/api/hermes/orders', ['Authorization' => 'Bearer anything'])->assertUnauthorized();
        $this->getJson('/api/hermes/orders', ['Authorization' => 'Bearer '])->assertUnauthorized();
    }

    public function test_the_token_in_a_query_string_is_not_accepted(): void
    {
        $this->getJson('/api/hermes/orders?token='.self::TOKEN)->assertUnauthorized();
    }

    public function test_the_api_is_rate_limited_even_for_wrong_tokens(): void
    {
        config(['hermes.rate_limit' => 3]);
        RateLimiter::clear('hermes');
        // The limiter reads the config when it first runs, so register it again with the small limit.
        RateLimiter::for('hermes', fn ($r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(3)->by($r->ip()));

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/hermes/orders', $this->auth())->assertOk();
        }
        $this->getJson('/api/hermes/orders', $this->auth())->assertStatus(429);
        $this->getJson('/api/hermes/orders', ['Authorization' => 'Bearer wrong'])->assertStatus(429);
    }

    // ---------- list ----------

    public function test_list_returns_only_paid_orders_and_never_unpaid_suppliers(): void
    {
        $paid = $this->supplier(SupplierStatus::Paid);
        $this->supplier(SupplierStatus::Building, 2);
        $this->supplier(SupplierStatus::Delivered, 6);
        foreach ([SupplierStatus::Invited, SupplierStatus::DetailsSubmitted, SupplierStatus::PaymentPending, SupplierStatus::Expired] as $unpaid) {
            $this->supplier($unpaid);
        }

        $res = $this->getJson('/api/hermes/orders?status=paid', $this->auth())->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame([
            'reference' => $this->ref($paid), 'business_name' => $paid->business_name, 'business_type' => 'caterer',
            'status' => 'paid', 'paid_at' => $paid->order->paid_at->toIso8601String(),
        ], $res->json('data.0'));
        $this->assertArrayNotHasKey('email', $res->json('data.0'));

        // Default is the same as status=paid.
        $this->assertCount(1, $this->getJson('/api/hermes/orders', $this->auth())->json('data'));
        $this->assertCount(1, $this->getJson('/api/hermes/orders?status=building', $this->auth())->json('data'));
        $this->assertCount(1, $this->getJson('/api/hermes/orders?status=delivered', $this->auth())->json('data'));
    }

    public function test_list_refuses_statuses_that_would_expose_unpaid_suppliers(): void
    {
        $this->supplier(SupplierStatus::Invited);

        foreach (['invited', 'details_submitted', 'payment_pending', 'expired', 'waitlisted', 'all', ''] as $status) {
            $this->getJson('/api/hermes/orders?status='.$status, $this->auth())->assertStatus(422);
        }
    }

    // ---------- show ----------

    public function test_show_returns_full_details_with_file_urls(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);
        Upload::create(['supplier_id' => $s->id, 'kind' => 'logo', 'path' => "uploads/{$s->id}/abc.png", 'original_name' => 'logo.png', 'mime' => 'image/png', 'size_kb' => 12]);

        $d = $this->getJson('/api/hermes/orders/'.$this->ref($s), $this->auth())->assertOk()->json('data');

        $this->assertSame($this->ref($s), $d['reference']);
        $this->assertSame(['name', 'email', 'mobile', 'business_name', 'business_type', 'city', 'province', 'social_link'], array_keys($d['supplier']));
        $this->assertSame('+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT), $d['supplier']['mobile']);
        $this->assertSame('Tagaytay City', $d['supplier']['city']);
        $this->assertSame('romantic', $d['answers']['style']);
        // The same answers with their questions, in the order the supplier saw them.
        $this->assertSame(['key' => 'description', 'label' => 'Business Description', 'type' => 'textarea', 'answer' => "About $this->n"], $d['questions'][0]);
        $this->assertSame('Romantic & Soft', collect($d['questions'])->firstWhere('key', 'style')['answer']);
        $this->assertNull(collect($d['questions'])->firstWhere('key', 'services')['answer']);
        $this->assertSame(0, $d['current_stage']);
        $this->assertSame('logo', $d['uploads'][0]['kind']);
        $this->assertStringStartsWith('http', $d['uploads'][0]['url']);
        $this->assertStringEndsWith("/storage/uploads/{$s->id}/abc.png", $d['uploads'][0]['url']);
        $this->assertArrayNotHasKey('invite_token', $d['supplier']);
    }

    public function test_show_includes_answers_to_the_extra_registration_questions(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);
        $s->update(['extra_answers' => [['key' => 'q_1a2b3c4d', 'label' => 'Heard about us', 'type' => 'text', 'value' => 'A friend', 'answer' => 'A friend']]]);

        $d = $this->getJson('/api/hermes/orders/'.$this->ref($s), $this->auth())->assertOk()->json('data');

        $this->assertSame([['key' => 'q_1a2b3c4d', 'label' => 'Heard about us', 'answer' => 'A friend']], $d['registration_answers']);
        $this->assertSame(['name', 'email', 'mobile', 'business_name', 'business_type', 'city', 'province', 'social_link'], array_keys($d['supplier'])); // unchanged
    }

    public function test_unpaid_or_unknown_references_are_404(): void
    {
        $unpaid = $this->supplier(SupplierStatus::PaymentPending);
        // Even a stray order row on an unpaid supplier must stay invisible.
        Order::create(['supplier_id' => $unpaid->id, 'reference' => 'KT10-STRAY', 'current_stage' => 0, 'paid_at' => now()]);

        $this->getJson('/api/hermes/orders/KT10-STRAY', $this->auth())->assertNotFound()->assertJsonPath('error', 'order_not_found');
        $this->getJson('/api/hermes/orders/KT10-NOPE', $this->auth())->assertNotFound();
        $this->postJson('/api/hermes/orders/KT10-STRAY/stage', ['stage' => 1], $this->auth())->assertNotFound();
        $this->postJson('/api/hermes/orders/KT10-STRAY/deliver', ['website_url' => 'https://x.example'], $this->auth())->assertNotFound();

        $this->assertSame(SupplierStatus::PaymentPending, $unpaid->fresh()->status);
        $this->assertSame(0, HermesStageLog::count());
    }

    // ---------- stage ----------

    public function test_stages_one_to_six_work_in_order_and_the_first_moves_to_building(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);
        $ref = $this->ref($s);

        for ($stage = 1; $stage <= 6; $stage++) {
            $this->postJson("/api/hermes/orders/$ref/stage", ['stage' => $stage, 'note' => "done $stage"], $this->auth())
                ->assertOk()->assertJsonPath('data.current_stage', $stage)->assertJsonPath('data.status', 'building');
        }

        $this->assertSame(6, $s->order->fresh()->current_stage);
        $this->assertSame(SupplierStatus::Building, $s->fresh()->status);
        $this->assertSame([1, 2, 3, 4, 5, 6], HermesStageLog::orderBy('id')->pluck('stage')->all());
        $this->assertSame('done 3', HermesStageLog::where('stage', 3)->value('note'));

        $moves = StatusHistory::where('supplier_id', $s->id)->where('actor', 'hermes')->get();
        $this->assertCount(1, $moves); // only paid -> building, written once
        $this->assertSame([SupplierStatus::Paid, SupplierStatus::Building], [$moves[0]->from_status, $moves[0]->to_status]);
    }

    public function test_repeating_the_same_stage_is_allowed_and_logged(): void
    {
        $s = $this->supplier(SupplierStatus::Building, 2);

        $this->postJson('/api/hermes/orders/'.$this->ref($s).'/stage', ['stage' => 2, 'note' => 'retry'], $this->auth())->assertOk();

        $this->assertSame(2, $s->order->fresh()->current_stage);
        $this->assertSame(1, HermesStageLog::where('stage', 2)->count());
        $this->assertSame(0, StatusHistory::where('supplier_id', $s->id)->where('actor', 'hermes')->count());
    }

    public function test_backwards_skipping_and_out_of_range_stages_are_rejected(): void
    {
        $s = $this->supplier(SupplierStatus::Building, 3);
        $url = '/api/hermes/orders/'.$this->ref($s).'/stage';

        $this->postJson($url, ['stage' => 2], $this->auth())->assertStatus(409)->assertJsonPath('error', 'stage_backwards');
        $this->postJson($url, ['stage' => 1], $this->auth())->assertStatus(409)->assertJsonPath('error', 'stage_backwards');
        $this->postJson($url, ['stage' => 5], $this->auth())->assertStatus(409)->assertJsonPath('error', 'stage_skipped');
        $this->postJson($url, ['stage' => 7], $this->auth())->assertStatus(422);
        $this->postJson($url, ['stage' => 0], $this->auth())->assertStatus(422);
        $this->postJson($url, ['stage' => -1], $this->auth())->assertStatus(422);
        $this->postJson($url, ['stage' => 'two'], $this->auth())->assertStatus(422);
        $this->postJson($url, ['stage' => 3.5], $this->auth())->assertStatus(422);
        $this->postJson($url, [], $this->auth())->assertStatus(422);

        $this->assertSame(3, $s->order->fresh()->current_stage);
        $this->assertSame(0, HermesStageLog::count());
    }

    public function test_the_first_stage_must_be_stage_one(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);

        $this->postJson('/api/hermes/orders/'.$this->ref($s).'/stage', ['stage' => 2], $this->auth())->assertStatus(409)->assertJsonPath('error', 'stage_skipped');

        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
    }

    public function test_hermes_cannot_write_anything_but_stage_and_delivery_data(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);

        $this->postJson('/api/hermes/orders/'.$this->ref($s).'/stage', [
            'stage' => 1, 'status' => 'delivered', 'supplier_id' => 999, 'email' => 'evil@example.com', 'paid_at' => '2000-01-01', 'reference' => 'HACK',
        ], $this->auth())->assertOk();

        $s->refresh();
        $this->assertSame(SupplierStatus::Building, $s->status);
        $this->assertSame($this->n.'@example.com', explode('s', $s->email, 2)[1]);
        $this->assertNotSame('HACK', $s->order->reference);
        $this->assertTrue($s->order->paid_at->isToday());
    }

    // ---------- deliver ----------

    public function test_deliver_before_stage_six_is_rejected(): void
    {
        foreach ([[SupplierStatus::Paid, 0], [SupplierStatus::Building, 3], [SupplierStatus::Building, 5]] as [$status, $stage]) {
            $s = $this->supplier($status, $stage);
            $this->postJson('/api/hermes/orders/'.$this->ref($s).'/deliver', ['website_url' => 'https://site.example'], $this->auth())
                ->assertStatus(409)->assertJsonPath('error', 'not_ready_to_deliver');
            $this->assertSame($status, $s->fresh()->status);
            $this->assertNull($s->order->fresh()->website_url);
        }
    }

    public function test_deliver_after_stage_six_marks_the_supplier_delivered(): void
    {
        $s = $this->supplier(SupplierStatus::Building, 6);

        $this->postJson('/api/hermes/orders/'.$this->ref($s).'/deliver', ['website_url' => 'https://rosal.example.ph'], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.website_url', 'https://rosal.example.ph');

        $o = $s->order->fresh();
        $this->assertSame('https://rosal.example.ph', $o->website_url);
        $this->assertNotNull($o->delivered_at);
        $this->assertSame(SupplierStatus::Delivered, $s->fresh()->status);

        $h = StatusHistory::where('supplier_id', $s->id)->latest('id')->first();
        $this->assertSame(['hermes', SupplierStatus::Building, SupplierStatus::Delivered], [$h->actor, $h->from_status, $h->to_status]);
    }

    public function test_full_journey_stage_by_stage_then_deliver(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);
        $ref = $this->ref($s);

        for ($i = 1; $i <= 6; $i++) {
            $this->postJson("/api/hermes/orders/$ref/stage", ['stage' => $i], $this->auth())->assertOk();
        }
        $this->postJson("/api/hermes/orders/$ref/deliver", ['website_url' => 'http://site.example'], $this->auth())->assertOk();

        $this->assertSame(SupplierStatus::Delivered, $s->fresh()->status);
        $this->assertSame(
            ['paid', 'building', 'delivered'],
            StatusHistory::where('supplier_id', $s->id)->orderBy('id')->get()->map(fn ($h) => $h->to_status->value)->slice(-2)->prepend('paid')->values()->all(),
        );
    }

    public function test_deliver_validates_the_url_and_works_only_once(): void
    {
        $s = $this->supplier(SupplierStatus::Building, 6);
        $url = '/api/hermes/orders/'.$this->ref($s).'/deliver';

        foreach (['', 'not a url', 'javascript:alert(1)', 'ftp://x.example', 'rosal.example'] as $bad) {
            $this->postJson($url, ['website_url' => $bad], $this->auth())->assertStatus(422);
        }
        $this->assertSame(SupplierStatus::Building, $s->fresh()->status);

        $this->postJson($url, ['website_url' => 'https://a.example'], $this->auth())->assertOk();
        $this->postJson($url, ['website_url' => 'https://b.example'], $this->auth())->assertStatus(409)->assertJsonPath('error', 'already_delivered');
        $this->assertSame('https://a.example', $s->order->fresh()->website_url);
    }

    public function test_a_delivered_order_cannot_change_stage(): void
    {
        $s = $this->supplier(SupplierStatus::Delivered, 6);

        $this->postJson('/api/hermes/orders/'.$this->ref($s).'/stage', ['stage' => 6], $this->auth())->assertStatus(409)->assertJsonPath('error', 'already_delivered');
    }

    public function test_errors_are_json_even_without_an_accept_header(): void
    {
        $this->get('/api/hermes/orders/KT10-NOPE', $this->auth())->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->post('/api/hermes/orders/KT10-NOPE/stage', ['stage' => 99], $this->auth())->assertHeader('Content-Type', 'application/json');
    }
}
