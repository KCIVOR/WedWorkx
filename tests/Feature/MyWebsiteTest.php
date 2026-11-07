<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Mail\InvitationExpiredMail;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\PaymentReminderMail;
use App\Mail\WebsiteLiveMail;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Upload;
use App\Models\WebsiteAnswer;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MyWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        config(['hermes.token' => 'tok']);
        $this->travelTo(now()->startOfMinute());
    }

    private function supplier(SupplierStatus $status = SupplierStatus::Invited, int $stage = 0, bool $answers = true): Supplier
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
        $s->update(['status' => $status]);

        if ($answers) {
            WebsiteAnswer::create([
                'supplier_id' => $s->id, 'submitted_at' => now(),
                'locked_at' => in_array($status, [SupplierStatus::Paid, SupplierStatus::Building, SupplierStatus::Delivered], true) ? now() : null,
                'answers' => ['description' => 'Warm Filipino menus.', 'style' => 'romantic', 'extra1' => 'Buffet', 'extra2' => 'Yes, we can accommodate special requests', 'booking' => 'Messenger', 'service_areas' => ['Tagaytay']],
            ]);
        }
        if (in_array($status, [SupplierStatus::Paid, SupplierStatus::Building, SupplierStatus::Delivered], true)) {
            Order::create(['supplier_id' => $s->id, 'reference' => 'KT10-'.str_pad((string) $this->n, 4, 'A', STR_PAD_LEFT), 'current_stage' => $stage, 'paid_at' => now(),
                'website_url' => $status === SupplierStatus::Delivered ? 'https://rosal.example.ph' : null,
                'delivered_at' => $status === SupplierStatus::Delivered ? now() : null]);
            Payment::create(['supplier_id' => $s->id, 'hitpay_request_id' => "req_$this->n", 'amount' => 10000, 'checkout_url' => 'https://x', 'status' => 'completed', 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'paid_at' => now()]);
        }

        return $s->fresh();
    }

    private function page(Supplier $s)
    {
        return $this->get('/invite/'.$s->invite_token.'/my-website');
    }

    // ---------- access ----------

    public function test_an_invalid_token_shows_nothing_about_any_supplier(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);

        $this->get('/invite/not-a-token/my-website')->assertNotFound();
        $this->get('/invite/'.substr($s->invite_token, 0, 10).'/my-website')->assertNotFound();
        $this->get('/invite/'.strtolower($s->invite_token).'X/my-website')->assertNotFound();

        $body = $this->get('/invite/not-a-token/my-website')->getContent();
        $this->assertStringNotContainsString($s->business_name, $body);
        $this->assertStringNotContainsString($s->email, $body);
        $this->assertStringNotContainsString($s->order->reference, $body);
    }

    public function test_payment_and_paid_statuses_on_the_invite_link_go_to_my_website(): void
    {
        foreach ([SupplierStatus::PaymentPending, SupplierStatus::Paid, SupplierStatus::Building, SupplierStatus::Delivered] as $st) {
            $s = $this->supplier($st, $st === SupplierStatus::Delivered ? 6 : 0);
            $this->get('/invite/'.$s->invite_token)->assertRedirect('/invite/'.$s->invite_token.'/my-website');
        }
    }

    // ---------- each status shows the right state ----------

    public function test_invited_supplier_is_told_to_finish_details(): void
    {
        $s = $this->supplier(SupplierStatus::Invited, answers: false);

        $this->page($s)->assertOk()->assertInertia(fn ($p) => $p
            ->component('MyWebsite')->where('status', 'invited')->where('detailsSubmitted', false)
            ->where('payment.canPay', false)->where('order', null)->where('review', null)->where('canEditDetails', true));
    }

    public function test_details_submitted_supplier_can_pay_and_still_edit(): void
    {
        $s = $this->supplier(SupplierStatus::DetailsSubmitted);

        $this->page($s)->assertInertia(fn ($p) => $p
            ->where('status', 'details_submitted')->where('detailsSubmitted', true)->where('payment.canPay', true)
            ->where('payment.pending', false)->where('payment.lastFailed', false)->where('canEditDetails', true)->where('order', null));
    }

    public function test_payment_pending_offers_resume_payment(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);

        $this->page($s)->assertInertia(fn ($p) => $p
            ->where('status', 'payment_pending')->where('payment.pending', true)->where('payment.canPay', true)->where('canEditDetails', false));
    }

    public function test_a_failed_payment_shows_the_retry_state(): void
    {
        $s = $this->supplier(SupplierStatus::DetailsSubmitted);
        Payment::create(['supplier_id' => $s->id, 'hitpay_request_id' => 'req_f', 'amount' => 10000, 'checkout_url' => 'https://x', 'status' => 'failed', 'started_at' => now(), 'expires_at' => now()->addMinutes(30)]);

        $this->page($s)->assertInertia(fn ($p) => $p->where('payment.lastFailed', true)->where('payment.canPay', true));
    }

    public function test_expired_supplier_sees_the_expired_state_and_nothing_else(): void
    {
        $s = $this->supplier(SupplierStatus::Expired);

        $this->page($s)->assertInertia(fn ($p) => $p
            ->where('status', 'expired')->where('payment.canPay', false)->where('canEditDetails', false)->where('order', null)->where('review', null));
    }

    public function test_a_lapsed_invitation_shows_expired_before_the_scheduler_runs(): void
    {
        $s = $this->supplier(SupplierStatus::DetailsSubmitted);
        $this->travel(25)->hours();

        $this->page($s)->assertInertia(fn ($p) => $p->where('status', 'expired')->where('payment.canPay', false));
    }

    public function test_paid_supplier_sees_reference_confirmation_and_locked_answers(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);
        Upload::create(['supplier_id' => $s->id, 'kind' => 'photo', 'path' => 'uploads/1/a.png', 'original_name' => 'a.png', 'mime' => 'image/png', 'size_kb' => 5]);

        $this->page($s)->assertInertia(fn ($p) => $p
            ->where('status', 'paid')
            ->where('order.reference', $s->order->reference)->where('order.amount', 10000)->where('order.stage', 0)->where('order.websiteUrl', null)
            ->has('order.paidOn')
            ->where('canEditDetails', false)
            ->has('review.questions', 8)->where('review.answers.style', 'romantic')->has('review.uploads', 1)
            ->missing('review.email')->missing('order.supplier_id'));
    }

    public function test_progress_is_correct_for_every_stage_from_0_to_6(): void
    {
        foreach (range(0, 6) as $stage) {
            $status = $stage === 0 ? SupplierStatus::Paid : SupplierStatus::Building;
            $s = $this->supplier($status, $stage);

            $this->page($s)->assertInertia(fn ($p) => $p->where('status', $status->value)->where('order.stage', $stage));
        }
    }

    public function test_delivered_supplier_gets_the_live_url(): void
    {
        $s = $this->supplier(SupplierStatus::Delivered, 6);

        $this->page($s)->assertInertia(fn ($p) => $p
            ->where('status', 'delivered')->where('order.stage', 6)->where('order.websiteUrl', 'https://rosal.example.ph')->has('order.deliveredOn'));
    }

    public function test_the_page_follows_changes_made_by_hermes_without_any_other_step(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);
        $ref = $s->order->reference;
        $h = ['Authorization' => 'Bearer tok'];

        $this->page($s)->assertInertia(fn ($p) => $p->where('status', 'paid')->where('order.stage', 0));
        $this->postJson("/api/hermes/orders/$ref/stage", ['stage' => 1], $h)->assertOk();
        $this->page($s)->assertInertia(fn ($p) => $p->where('status', 'building')->where('order.stage', 1));
        foreach (range(2, 6) as $i) {
            $this->postJson("/api/hermes/orders/$ref/stage", ['stage' => $i], $h)->assertOk();
        }
        $this->postJson("/api/hermes/orders/$ref/deliver", ['website_url' => 'https://live.example'], $h)->assertOk();
        $this->page($s)->assertInertia(fn ($p) => $p->where('status', 'delivered')->where('order.websiteUrl', 'https://live.example'));
    }

    // ---------- the "Website live" email ----------

    public function test_live_email_is_sent_once_on_delivery_with_the_right_url(): void
    {
        $s = $this->supplier(SupplierStatus::Building, 6);
        $h = ['Authorization' => 'Bearer tok'];
        $url = "/api/hermes/orders/{$s->order->reference}/deliver";

        $this->postJson($url, ['website_url' => 'https://rosal.example.ph'], $h)->assertOk();
        $this->postJson($url, ['website_url' => 'https://rosal.example.ph'], $h)->assertStatus(409); // second call is refused

        Mail::assertSent(WebsiteLiveMail::class, 1);
        Mail::assertSent(WebsiteLiveMail::class, fn ($m) => $m->hasTo($s->email) && $m->order->website_url === 'https://rosal.example.ph');
        $this->assertSame(1, EmailLog::where('supplier_id', $s->id)->where('type', 'live')->where('status', 'sent')->count());
    }

    public function test_live_email_is_not_sent_when_delivery_is_refused(): void
    {
        $s = $this->supplier(SupplierStatus::Building, 3);

        $this->postJson("/api/hermes/orders/{$s->order->reference}/deliver", ['website_url' => 'https://x.example'], ['Authorization' => 'Bearer tok'])->assertStatus(409);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailLog::where('type', 'live')->count());
    }

    public function test_a_failed_live_email_does_not_undo_the_delivery(): void
    {
        Mail::swap(new \Illuminate\Mail\MailManager($this->app)); // real manager
        config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2]]);
        $s = $this->supplier(SupplierStatus::Building, 6);

        $this->postJson("/api/hermes/orders/{$s->order->reference}/deliver", ['website_url' => 'https://x.example'], ['Authorization' => 'Bearer tok'])->assertOk();

        $this->assertSame(SupplierStatus::Delivered, $s->fresh()->status);
        $this->assertSame('failed', EmailLog::where('type', 'live')->value('status'));
    }

    public function test_live_email_content_uses_the_email_components(): void
    {
        $s = $this->supplier(SupplierStatus::Delivered, 6);
        $html = (new WebsiteLiveMail($s->order))->render();

        $this->assertStringContainsString('Your website is live!', $html);
        $this->assertStringContainsString('href="https://rosal.example.ph"', $html);
        $this->assertStringContainsString($s->order->reference, $html);
        $this->assertStringContainsString('Visit my website', $html);
        $this->assertStringContainsString('bgcolor="#2f5bea"', $html); // royal blue header from the templates
        $this->assertStringContainsString('border-radius:999px', $html); // pill button
    }

    public function test_follow_up_emails_link_to_my_website(): void
    {
        $s = $this->supplier(SupplierStatus::Paid);
        $expected = 'href="'.url('/invite/'.$s->invite_token.'/my-website').'"';

        foreach ([new PaymentReceiptMail($s->order), new PaymentReminderMail($s), new PaymentFailedMail($s), new InvitationExpiredMail($s)] as $mail) {
            $this->assertStringContainsString($expected, $mail->render(), get_class($mail));
        }
    }
}
