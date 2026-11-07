<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceiptMail;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Models\WebsiteAnswer;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        config(['hitpay.api_key' => 'test-key', 'hitpay.salt' => 'test-salt']);
        $this->travelTo(now()->startOfMinute());
    }

    /** A supplier who finished Chatbot 2 and may pay. */
    private function supplier(SupplierStatus $status = SupplierStatus::DetailsSubmitted): Supplier
    {
        $this->n++;
        $s = app(SlotService::class)->register([
            'name' => "Maria Santos $this->n",
            'email' => "s$this->n@example.com",
            'mobile' => '+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Rosal Catering $this->n",
            'business_type' => 'caterer',
            'location' => 'Tagaytay',
        ])['supplier'];
        WebsiteAnswer::create(['supplier_id' => $s->id, 'answers' => ['description' => 'x'], 'submitted_at' => now()]);
        $s->update(['status' => $status]);

        return $s->fresh();
    }

    private string $hitpayId = 'req_1';

    /** Fakes HitPay once; later calls only change which id the next payment request returns. */
    private function fakeHitPay(string $id = 'req_1'): void
    {
        $this->hitpayId = $id;
        if ($this->hitpayFaked ?? false) {
            return;
        }
        $this->hitpayFaked = true;
        Http::fake(['*/payment-requests' => fn () => Http::response(['id' => $this->hitpayId, 'url' => "https://hitpay.test/pay/{$this->hitpayId}", 'status' => 'pending'], 201)]);
    }

    private bool $hitpayFaked = false;

    private function link(Supplier $s, string $id, string $status = 'pending', $startedAt = null): Payment
    {
        $startedAt ??= now();

        return Payment::create([
            'supplier_id' => $s->id, 'hitpay_request_id' => $id, 'amount' => 10000,
            'checkout_url' => "https://hitpay.test/pay/$id", 'status' => $status,
            'started_at' => $startedAt, 'expires_at' => $startedAt->copy()->addMinutes(30),
        ]);
    }

    private function signedForm(array $fields): array
    {
        $d = $fields;
        ksort($d);
        $m = '';
        foreach ($d as $k => $v) {
            $m .= $k.$v;
        }

        return $fields + ['hmac' => hash_hmac('sha256', $m, 'test-salt')];
    }

    private function hook(string $requestId, string $status = 'completed', string $amount = '10000.00')
    {
        return $this->post('/webhooks/hitpay', $this->signedForm([
            'payment_id' => 'pay_'.$requestId, 'payment_request_id' => $requestId, 'phone' => '', 'amount' => $amount,
            'currency' => 'PHP', 'status' => $status, 'reference_number' => 'ref',
        ]));
    }

    // ---------- creating a link ----------

    public function test_pay_creates_a_link_and_moves_the_supplier_to_payment_pending(): void
    {
        $this->fakeHitPay();
        $s = $this->supplier();

        $this->postJson("/invite/{$s->invite_token}/pay")->assertOk()->assertJson(['url' => 'https://hitpay.test/pay/req_1']);

        $p = Payment::sole();
        $this->assertSame(['pending', 'req_1', '10000.00'], [$p->status, $p->hitpay_request_id, (string) $p->amount]);
        $this->assertTrue($p->started_at->equalTo(now()));
        $this->assertTrue($p->expires_at->equalTo(now()->addMinutes(30)));
        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);

        $h = StatusHistory::where('supplier_id', $s->id)->latest('id')->first();
        $this->assertSame(['supplier', SupplierStatus::PaymentPending], [$h->actor, $h->to_status]);

        Http::assertSent(fn ($r) => $r->hasHeader('X-BUSINESS-API-KEY', 'test-key')
            && $r['amount'] === '10000.00' && $r['currency'] === 'PHP'
            && $r['redirect_url'] === url("/invite/{$s->invite_token}/payment/return")
            && $r['webhook'] === url('/webhooks/hitpay'));
    }

    public function test_a_live_link_is_reused_and_an_old_one_is_replaced(): void
    {
        $this->fakeHitPay('req_1');
        $s = $this->supplier();
        $this->postJson("/invite/{$s->invite_token}/pay");
        $this->postJson("/invite/{$s->invite_token}/pay")->assertJson(['url' => 'https://hitpay.test/pay/req_1']);
        Http::assertSentCount(1);

        // 31 minutes later the link has run out; a new one replaces it (rule 5).
        $this->travel(31)->minutes();
        $this->fakeHitPay('req_2');
        $this->postJson("/invite/{$s->invite_token}/pay")->assertJson(['url' => 'https://hitpay.test/pay/req_2']);

        $this->assertSame('expired', Payment::where('hitpay_request_id', 'req_1')->value('status'));
        $this->assertSame('pending', Payment::where('hitpay_request_id', 'req_2')->value('status'));
    }

    public function test_no_link_can_be_created_after_the_deadline(): void
    {
        $this->fakeHitPay();
        $s = $this->supplier();
        $this->travel(24 * 60 + 1)->minutes();

        $this->postJson("/invite/{$s->invite_token}/pay")->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);
    }

    public function test_only_suppliers_who_confirmed_details_can_pay(): void
    {
        $this->fakeHitPay();
        foreach ([SupplierStatus::Invited, SupplierStatus::Expired, SupplierStatus::Paid, SupplierStatus::Building] as $status) {
            $s = $this->supplier($status);
            $this->postJson("/invite/{$s->invite_token}/pay")->assertForbidden();
        }
        $this->post('/invite/unknown/pay')->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
    }

    public function test_hitpay_down_gives_a_friendly_error_and_changes_nothing(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $s = $this->supplier();

        $this->postJson("/invite/{$s->invite_token}/pay")->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'try again'));

        $this->assertSame(0, Payment::count());
        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);
    }

    // ---------- the webhook: signature ----------

    public function test_bad_missing_or_unconfigured_signature_is_rejected_and_changes_nothing(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');
        $fields = ['payment_request_id' => 'req_1', 'amount' => '10000.00', 'status' => 'completed'];

        $this->post('/webhooks/hitpay', $fields + ['hmac' => str_repeat('a', 64)])->assertUnauthorized();
        $this->post('/webhooks/hitpay', $fields)->assertUnauthorized();
        $this->post('/webhooks/hitpay', array_merge($this->signedForm($fields), ['amount' => '1.00']))->assertUnauthorized(); // tampered after signing
        $this->call('POST', '/webhooks/hitpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_HITPAY_SIGNATURE' => 'bad'], json_encode($fields))->assertUnauthorized();

        config(['hitpay.salt' => null]);
        $this->post('/webhooks/hitpay', $this->signedForm($fields))->assertUnauthorized();

        $this->assertSame('pending', Payment::sole()->status);
        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);
        $this->assertSame(0, Order::count());
        Mail::assertNothingSent();
    }

    public function test_json_webhook_with_signature_header_is_accepted(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');
        $raw = json_encode(['payment_request_id' => 'req_1', 'amount' => '10000.00', 'status' => 'completed']);

        $this->call('POST', '/webhooks/hitpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HITPAY_SIGNATURE' => hash_hmac('sha256', $raw, 'test-salt'),
        ], $raw)->assertOk()->assertJson(['result' => 'completed']);

        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
    }

    // ---------- the webhook: success ----------

    public function test_completed_payment_creates_one_order_locks_answers_and_sends_one_receipt(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');

        $this->hook('req_1')->assertOk()->assertJson(['result' => 'completed']);

        $s->refresh();
        $this->assertSame(SupplierStatus::Paid, $s->status);
        $p = Payment::sole();
        $this->assertSame('completed', $p->status);
        $this->assertTrue($p->paid_at->equalTo(now()));
        $this->assertFalse($p->paid_late);
        $this->assertSame('req_1', $p->webhook_payload['payment_request_id']);
        $this->assertArrayNotHasKey('hmac', $p->webhook_payload);

        $o = Order::sole();
        $this->assertMatchesRegularExpression('/^KT10-[A-Z2-9]{4}$/', $o->reference);
        $this->assertSame(0, $o->current_stage);
        $this->assertSame($s->id, $o->supplier_id);
        $this->assertNotNull($s->websiteAnswer->locked_at);

        $h = StatusHistory::where('supplier_id', $s->id)->latest('id')->first();
        $this->assertSame(['hitpay', SupplierStatus::PaymentPending, SupplierStatus::Paid], [$h->actor, $h->from_status, $h->to_status]);

        Mail::assertSent(PaymentReceiptMail::class, 1);
        $this->assertSame(1, EmailLog::where('type', 'receipt')->where('status', 'sent')->count());
    }

    public function test_the_same_webhook_twice_makes_one_order_and_one_email(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');

        $this->hook('req_1')->assertJson(['result' => 'completed']);
        $this->hook('req_1')->assertOk()->assertJson(['result' => 'already_processed']);
        $this->hook('req_1')->assertOk()->assertJson(['result' => 'already_processed']);

        $this->assertSame(1, Order::count());
        Mail::assertSent(PaymentReceiptMail::class, 1);
        $this->assertSame(1, StatusHistory::where('to_status', 'paid')->count());
    }

    public function test_unique_order_references_for_many_payments(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $s = $this->supplier(SupplierStatus::PaymentPending);
            $this->link($s, "req_$i");
            $this->hook("req_$i")->assertJson(['result' => 'completed']);
        }

        $this->assertSame(25, Order::distinct('reference')->count('reference'));
    }

    public function test_money_for_a_replaced_link_is_still_honoured(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_old', 'expired');
        $this->link($s, 'req_new');

        $this->hook('req_old')->assertJson(['result' => 'completed']); // paid on the old link

        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
        $this->assertSame(1, Order::count());
    }

    public function test_a_second_payment_after_paid_makes_no_second_order(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');
        $this->link($s, 'req_2');
        Log::spy();

        $this->hook('req_1');
        $this->hook('req_2')->assertJson(['result' => 'late_rejected']);

        $this->assertSame(1, Order::count());
        Mail::assertSent(PaymentReceiptMail::class, 1);
        Log::shouldHaveReceived('warning')->with('HitPay payment received but not applied', \Mockery::type('array'))->once();
    }

    public function test_amount_mismatch_unknown_request_and_pending_status_are_ignored(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');

        $this->hook('req_1', 'completed', '1.00')->assertOk()->assertJson(['result' => 'ignored']);
        $this->hook('nope')->assertOk()->assertJson(['result' => 'ignored']);
        $this->hook('req_1', 'pending')->assertOk()->assertJson(['result' => 'ignored']);

        $this->assertSame('pending', Payment::sole()->status);
        $this->assertSame(0, Order::count());
    }

    // ---------- grace rule (D6) ----------

    public function test_payment_started_before_the_deadline_and_completed_within_grace_is_accepted_late(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1', 'pending', $s->invite_expires_at->copy()->subMinutes(5));

        $this->travelTo($s->invite_expires_at->copy()->addMinutes(20));
        $this->hook('req_1')->assertJson(['result' => 'completed']);

        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
        $this->assertTrue(Payment::sole()->paid_late);
        $this->assertSame(1, Order::count());
        Mail::assertSent(PaymentReceiptMail::class, 1);
    }

    public function test_payment_completed_after_the_grace_window_is_flagged_in_the_log_only(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1', 'pending', $s->invite_expires_at->copy()->subMinutes(5));
        Log::spy();

        $this->travelTo($s->invite_expires_at->copy()->addMinutes(31));
        $this->hook('req_1')->assertJson(['result' => 'late_rejected']);

        $this->assertSame(0, Order::count());
        $this->assertNotSame(SupplierStatus::Paid, $s->fresh()->status);
        $this->assertTrue(Payment::sole()->paid_late);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('HitPay payment received but not applied', \Mockery::type('array'))->once();
    }

    public function test_a_payment_started_after_the_deadline_never_counts(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->travelTo($s->invite_expires_at->copy()->addMinutes(1));
        $this->link($s, 'req_1'); // should be impossible through the app, but must not be honoured if it exists

        $this->hook('req_1')->assertJson(['result' => 'late_rejected']);

        $this->assertSame(0, Order::count());
    }

    // ---------- failure and retry ----------

    public function test_failed_payment_returns_the_supplier_to_details_submitted_and_retry_works(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');

        $this->hook('req_1', 'failed')->assertJson(['result' => 'failed']);

        $this->assertSame('failed', Payment::sole()->status);
        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);
        Mail::assertSent(PaymentFailedMail::class, 1);
        $h = StatusHistory::where('supplier_id', $s->id)->latest('id')->first();
        $this->assertSame(['hitpay', SupplierStatus::DetailsSubmitted], [$h->actor, $h->to_status]);

        // Try again: a fresh link while the invite is valid.
        $this->fakeHitPay('req_2');
        $this->postJson("/invite/{$s->invite_token}/pay")->assertOk()->assertJson(['url' => 'https://hitpay.test/pay/req_2']);
        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);
        $this->hook('req_2')->assertJson(['result' => 'completed']);
        $this->assertSame(SupplierStatus::Paid, $s->fresh()->status);
    }

    public function test_failed_email_may_be_sent_for_each_failure(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');
        $this->hook('req_1', 'failed');

        $this->fakeHitPay('req_2');
        $this->postJson("/invite/{$s->invite_token}/pay");
        $this->hook('req_2', 'failed');

        Mail::assertSent(PaymentFailedMail::class, 2);
        $this->assertSame(2, EmailLog::where('type', 'failed')->count());
    }

    public function test_retry_is_blocked_after_the_deadline(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');
        $this->hook('req_1', 'failed');

        $this->travel(25)->hours();
        $this->fakeHitPay('req_2');
        $this->postJson("/invite/{$s->invite_token}/pay")->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_failure_for_a_replaced_link_is_ignored(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_old', 'expired');
        $this->link($s, 'req_new');

        $this->hook('req_old', 'failed')->assertJson(['result' => 'ignored']);

        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_links_that_run_out_expire_and_the_supplier_can_retry(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');

        $this->travel(31)->minutes();
        $this->artisan('invites:process')->assertSuccessful();

        $this->assertSame('expired', Payment::sole()->status);
        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);

        $this->fakeHitPay('req_2');
        $this->postJson("/invite/{$s->invite_token}/pay")->assertOk();
    }

    // ---------- the browser return and locking ----------

    public function test_browser_return_never_marks_anything_paid(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');

        $this->get("/invite/{$s->invite_token}/payment/return?status=completed&reference=req_1")
            ->assertRedirect("/invite/{$s->invite_token}/my-website");

        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);
        $this->assertSame('pending', Payment::sole()->status);
        $this->assertSame(0, Order::count());
    }

    public function test_answers_cannot_be_edited_after_payment(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');
        $this->hook('req_1');

        $payload = ['description' => 'Changed description here', 'style' => 'modern', 'extra1' => 'x', 'extra2' => 'y'];
        $this->post("/invite/{$s->invite_token}/confirm", $payload)->assertForbidden();
        $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => \Illuminate\Http\UploadedFile::fake()->image('a.png')])->assertForbidden();

        $this->assertSame('x', $s->fresh()->websiteAnswer->answers['description']);
    }

    public function test_invite_page_shows_the_order_after_payment_and_payment_state_before(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->link($s, 'req_1');

        $this->get('/invite/'.$s->invite_token.'/my-website')->assertInertia(fn ($p) => $p
            ->where('status', 'payment_pending')->where('payment.pending', true)->where('payment.price', 10000)->where('order', null));

        $this->hook('req_1');

        $this->get('/invite/'.$s->invite_token.'/my-website')->assertInertia(fn ($p) => $p
            ->where('status', 'paid')->where('order.reference', Order::sole()->reference)->where('order.amount', 10000));
    }
}
