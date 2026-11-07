<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Mail\InvitationExpiredMail;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\PaymentReminderMail;
use App\Mail\WebsiteLiveMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\WebsiteAnswer;
use App\Services\SlotService;
use App\Support\DevMode;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DevModeTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        Http::fake();
        $this->travelTo(now()->startOfMinute());
    }

    private function supplier(SupplierStatus $status = SupplierStatus::DetailsSubmitted): Supplier
    {
        $this->n++;
        $s = app(SlotService::class)->register([
            'name' => "Dev Person $this->n", 'email' => "dev$this->n@example.com", 'mobile' => '+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Dev Biz $this->n", 'business_type' => 'caterer', 'location' => 'Tagaytay',
        ])['supplier'];
        if ($status !== SupplierStatus::Invited) {
            WebsiteAnswer::create(['supplier_id' => $s->id, 'answers' => ['description' => 'x'], 'submitted_at' => now()]);
        }
        $s->update(['status' => $status]);

        return $s->fresh();
    }

    private function on(): void
    {
        config(['dev.enabled' => true]);
    }

    /** Pay in dev mode and return the simulated request id. */
    private function pay(Supplier $s): string
    {
        $url = $this->postJson("/invite/{$s->invite_token}/pay")->assertOk()->json('url');

        return basename($url);
    }

    // ---------- the switch ----------

    public function test_off_by_default_every_dev_route_is_a_404_and_the_banner_is_off(): void
    {
        $s = $this->supplier();

        $this->assertFalse(DevMode::enabled());
        $this->get('/dev/checkout/dev_abc')->assertNotFound();
        $this->post('/dev/checkout/dev_abc/completed')->assertNotFound();
        foreach (['advance', 'expire', 'remind'] as $action) {
            $this->post("/dev/invite/{$s->invite_token}/$action")->assertNotFound();
        }
        $this->get('/')->assertInertia(fn ($p) => $p->where('dev', false));
    }

    public function test_it_can_never_be_on_in_production_even_if_the_setting_says_true(): void
    {
        config(['dev.enabled' => true]);
        $this->assertTrue(DevMode::enabled());

        $this->app['config']->set('app.env', 'production');
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertTrue(config('dev.enabled'));
        $this->assertFalse(DevMode::enabled());
        $s = $this->supplier();
        // Production enforces CSRF even in tests; skip it so this test checks the dev-mode guard itself.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->post("/dev/invite/{$s->invite_token}/advance")->assertNotFound();
        $this->post('/dev/checkout/dev_abc/completed')->assertNotFound();
        $this->get('/dev/checkout/dev_abc')->assertNotFound();
    }

    public function test_when_on_the_pages_know_it(): void
    {
        $this->on();

        $this->get('/')->assertInertia(fn ($p) => $p->where('dev', true));
    }

    // ---------- simulated payment ----------

    public function test_pay_in_dev_mode_makes_a_simulated_link_and_never_calls_hitpay(): void
    {
        $this->on();
        config(['hitpay.api_key' => null]); // no HitPay configured at all
        $s = $this->supplier();

        $id = $this->pay($s);

        Http::assertNothingSent();
        $this->assertStringStartsWith('dev_', $id);
        $p = Payment::sole();
        $this->assertSame(['pending', $id, '10000.00'], [$p->status, $p->hitpay_request_id, (string) $p->amount]);
        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);
        $this->get("/dev/checkout/$id")->assertOk()->assertInertia(fn ($pg) => $pg
            ->component('DevCheckout')->where('status', 'pending')->where('amount', 10000)->where('businessName', $s->business_name));
    }

    public function test_simulated_success_goes_through_the_real_webhook_rules(): void
    {
        $this->on();
        $s = $this->supplier();
        $id = $this->pay($s);

        $this->post("/dev/checkout/$id/completed")->assertRedirect("/invite/{$s->invite_token}/my-website");

        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
        $o = Order::sole();
        $this->assertMatchesRegularExpression('/^KT10-[A-Z2-9]{4}$/', $o->reference);
        $this->assertNotNull($s->websiteAnswer->fresh()->locked_at);
        $this->assertTrue(Payment::sole()->webhook_payload['dev_simulated']);
        Mail::assertSent(PaymentReceiptMail::class, 1);

        // pressing it again changes nothing (idempotent like the real webhook)
        $this->post("/dev/checkout/$id/completed");
        $this->assertSame(1, Order::count());
        Mail::assertSent(PaymentReceiptMail::class, 1);
    }

    public function test_simulated_failure_sends_the_failed_email_and_allows_a_retry(): void
    {
        $this->on();
        $s = $this->supplier();
        $id = $this->pay($s);

        $this->post("/dev/checkout/$id/failed")->assertRedirect();

        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);
        Mail::assertSent(PaymentFailedMail::class, 1);
        $id2 = $this->pay($s);
        $this->assertNotSame($id, $id2);
        $this->post("/dev/checkout/$id2/completed");
        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
    }

    public function test_a_real_hitpay_payment_can_never_be_settled_from_the_dev_page(): void
    {
        $this->on();
        $s = $this->supplier(SupplierStatus::PaymentPending);
        Payment::create(['supplier_id' => $s->id, 'hitpay_request_id' => 'req_real_123', 'amount' => 10000, 'checkout_url' => 'https://x',
            'status' => 'pending', 'started_at' => now(), 'expires_at' => now()->addMinutes(30)]);

        $this->get('/dev/checkout/req_real_123')->assertNotFound();
        $this->post('/dev/checkout/req_real_123/completed')->assertNotFound();

        $this->assertSame('pending', Payment::sole()->status);
        $this->assertSame(0, Order::count());
    }

    public function test_the_deadline_rules_still_apply_to_simulated_payments(): void
    {
        $this->on();
        $s = $this->supplier();
        $this->travel(25)->hours();

        $this->postJson("/invite/{$s->invite_token}/pay")->assertForbidden(); // no link after the deadline, even in dev mode
        $this->assertSame(0, Payment::count());
    }

    // ---------- Hermes stand-in ----------

    public function test_advance_walks_through_stages_one_to_six_then_delivers(): void
    {
        $this->on();
        $s = $this->supplier();
        $this->post("/dev/checkout/{$this->pay($s)}/completed");

        foreach (range(1, 6) as $stage) {
            $this->post("/dev/invite/{$s->invite_token}/advance")->assertRedirect();
            $this->assertSame($stage, Order::sole()->current_stage);
        }
        $this->assertSame(SupplierStatus::Building, $s->fresh()->status);
        Mail::assertNotSent(WebsiteLiveMail::class);

        $this->post("/dev/invite/{$s->invite_token}/advance")->assertRedirect();

        $this->assertSame(SupplierStatus::Delivered, $s->fresh()->status);
        $this->assertSame('https://dev.example/'.strtolower(Order::sole()->reference), Order::sole()->website_url);
        Mail::assertSent(WebsiteLiveMail::class, 1);

        $this->post("/dev/invite/{$s->invite_token}/advance")->assertSessionHas('dev_message'); // already delivered: refused, not repeated
        Mail::assertSent(WebsiteLiveMail::class, 1);
    }

    public function test_advance_before_payment_is_refused(): void
    {
        $this->on();
        $s = $this->supplier();

        $this->post("/dev/invite/{$s->invite_token}/advance")->assertStatus(409);
        $this->assertSame(0, Order::count());
    }

    // ---------- scheduler stand-ins ----------

    public function test_expire_now_expires_an_unpaid_supplier_with_the_email_and_leaves_paid_ones_alone(): void
    {
        $this->on();
        $unpaid = $this->supplier();
        $paid = $this->supplier(SupplierStatus::Paid);
        Order::create(['supplier_id' => $paid->id, 'reference' => 'KT10-DEVP', 'current_stage' => 0, 'paid_at' => now()]);

        $this->post("/dev/invite/{$unpaid->invite_token}/expire")->assertRedirect();
        $this->post("/dev/invite/{$paid->invite_token}/expire")->assertRedirect();

        $this->assertSame(SupplierStatus::Expired, $unpaid->fresh()->status);
        $this->assertSame(SupplierStatus::Paid, $paid->fresh()->status);
        Mail::assertSent(InvitationExpiredMail::class, 1);
    }

    public function test_remind_now_sends_the_reminder_once(): void
    {
        $this->on();
        $s = $this->supplier(SupplierStatus::Invited);

        $this->post("/dev/invite/{$s->invite_token}/remind")->assertRedirect();
        $this->post("/dev/invite/{$s->invite_token}/remind")->assertRedirect();

        Mail::assertSent(PaymentReminderMail::class, 1);
        $this->assertSame(SupplierStatus::Invited, $s->fresh()->status);
    }

    public function test_the_real_paths_are_untouched_when_it_is_off(): void
    {
        // Off: Pay talks to HitPay (here: not configured, so a friendly 502 and nothing created).
        config(['hitpay.api_key' => null]);
        $s = $this->supplier();

        $this->postJson("/invite/{$s->invite_token}/pay")->assertStatus(502);

        $this->assertSame(0, Payment::count());
        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);
    }
}
