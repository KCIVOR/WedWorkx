<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Mail\InvitationExpiredMail;
use App\Mail\PaymentReminderMail;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\Payment;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Services\InviteExpiryService;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InviteExpiryTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        $this->travelTo(now()->startOfMinute());
    }

    private function supplier(?SupplierStatus $status = null): Supplier
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
        if ($status) {
            $s->update(['status' => $status]);
        }

        return $s->fresh();
    }

    private function payment(Supplier $s, string $status, $startedAt): Payment
    {
        return Payment::create([
            'supplier_id' => $s->id,
            'hitpay_request_id' => 'req_'.uniqid(),
            'amount' => 10000,
            'checkout_url' => 'https://example.test/pay',
            'status' => $status,
            'started_at' => $startedAt,
            'expires_at' => $startedAt->copy()->addMinutes(30),
        ]);
    }

    private function run24hLater(int $minutes = 0): void
    {
        $this->travel(24 * 60 + $minutes)->minutes();
        $this->artisan('invites:process')->assertSuccessful();
    }

    public function test_supplier_invited_24_hours_ago_expires_and_gets_one_email(): void
    {
        $s = $this->supplier();
        $this->run24hLater();

        $this->assertSame(SupplierStatus::Expired, $s->fresh()->status);
        Mail::assertSent(InvitationExpiredMail::class, 1);
        $this->assertSame(1, EmailLog::where('type', 'expired')->where('status', 'sent')->count());

        $h = StatusHistory::where('supplier_id', $s->id)->latest('id')->first();
        $this->assertSame([SupplierStatus::Invited, SupplierStatus::Expired, 'system'], [$h->from_status, $h->to_status, $h->actor]);
    }

    public function test_not_expired_before_the_deadline(): void
    {
        $s = $this->supplier();
        $this->travel(23 * 60 + 59)->minutes();
        $this->artisan('invites:process');

        $this->assertSame(SupplierStatus::Invited, $s->fresh()->status);
        Mail::assertNotSent(InvitationExpiredMail::class);
    }

    public function test_running_the_command_twice_sends_no_duplicate_emails(): void
    {
        $this->supplier();
        $this->run24hLater();
        $this->artisan('invites:process');
        $this->artisan('invites:process');

        Mail::assertSent(InvitationExpiredMail::class, 1);
        $this->assertSame(1, StatusHistory::where('to_status', 'expired')->count());
    }

    public function test_all_unpaid_statuses_expire_and_other_statuses_are_left_alone(): void
    {
        $unpaid = [SupplierStatus::Invited, SupplierStatus::DetailsSubmitted, SupplierStatus::PaymentPending];
        $kept = [SupplierStatus::Paid, SupplierStatus::Building, SupplierStatus::Delivered];

        $expire = collect($unpaid)->map(fn ($st) => $this->supplier($st));
        $stay = collect($kept)->map(fn ($st) => $this->supplier($st));

        $this->run24hLater();

        $expire->each(fn ($s) => $this->assertSame(SupplierStatus::Expired, $s->fresh()->status));
        $stay->each(fn ($s) => $this->assertSame($s->status, $s->fresh()->status));
        Mail::assertSent(InvitationExpiredMail::class, 3);
    }

    public function test_pending_payment_started_before_deadline_is_protected_until_grace_ends(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->payment($s, 'pending', $s->invite_expires_at->copy()->subMinutes(5));

        $this->run24hLater(10); // 10 minutes after the deadline, grace is 30
        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);
        Mail::assertNotSent(InvitationExpiredMail::class);

        $this->travel(20)->minutes(); // 30 minutes after the deadline: grace over
        $this->assertSame(SupplierStatus::PaymentPending, $s->fresh()->status);
        $this->artisan('invites:process');
        $this->assertSame(SupplierStatus::Expired, $s->fresh()->status);
        Mail::assertSent(InvitationExpiredMail::class, 1);
    }

    public function test_a_failed_or_completed_payment_does_not_protect_from_expiry(): void
    {
        $s = $this->supplier(SupplierStatus::DetailsSubmitted);
        $this->payment($s, 'failed', $s->invite_expires_at->copy()->subMinutes(40));

        $this->run24hLater(1);

        $this->assertSame(SupplierStatus::Expired, $s->fresh()->status);
    }

    public function test_expiry_never_frees_a_slot_or_promotes_the_waitlist(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->supplier();
        }
        $waitlisted = $this->supplier();
        $this->assertSame(SupplierStatus::Waitlisted, $waitlisted->status);

        $this->run24hLater();

        $this->assertSame(11, Campaign::first()->slots_taken);
        $this->assertSame(SupplierStatus::Waitlisted, $waitlisted->fresh()->status);
        $this->assertNull($waitlisted->fresh()->invite_token);
        Mail::assertSent(InvitationExpiredMail::class, 10);
    }

    public function test_reminder_is_sent_once_about_two_hours_before_the_deadline(): void
    {
        $s = $this->supplier();

        $this->travel(21)->hours(); // 3 hours before the deadline
        $this->artisan('invites:process');
        Mail::assertNotSent(PaymentReminderMail::class);

        $this->travel(60)->minutes(); // exactly 2 hours before
        $this->artisan('invites:process');
        Mail::assertSent(PaymentReminderMail::class, 1);

        $this->travel(30)->minutes();
        $this->artisan('invites:process');
        $this->artisan('invites:process');
        Mail::assertSent(PaymentReminderMail::class, 1);
        $this->assertSame(1, EmailLog::where('supplier_id', $s->id)->where('type', 'reminder')->count());
    }

    public function test_reminder_goes_only_to_unpaid_suppliers(): void
    {
        $unpaid = $this->supplier(SupplierStatus::PaymentPending);
        $this->supplier(SupplierStatus::Paid);
        $this->supplier(SupplierStatus::Building);

        $this->travel(22)->hours();
        $this->artisan('invites:process');

        Mail::assertSent(PaymentReminderMail::class, 1);
        Mail::assertSent(PaymentReminderMail::class, fn ($m) => $m->hasTo($unpaid->email));
    }

    public function test_no_reminder_after_the_deadline(): void
    {
        $this->supplier();
        $this->run24hLater();

        Mail::assertNotSent(PaymentReminderMail::class);
        Mail::assertSent(InvitationExpiredMail::class, 1);
    }

    public function test_expired_supplier_sees_the_expired_card_and_cannot_continue(): void
    {
        $s = $this->supplier();
        $this->run24hLater();

        $this->get('/invite/'.$s->invite_token)->assertOk()->assertInertia(fn ($p) => $p
            ->where('status', 'expired')
            ->where('chat', null));

        $this->assertFalse(app(InviteExpiryService::class)->inviteIsValid($s->fresh())); // no payment link can be created (rule 6)
    }

    public function test_expiry_is_enforced_even_before_the_scheduler_runs(): void
    {
        $s = $this->supplier();
        $this->travel(24 * 60 + 1)->minutes(); // command has not run yet

        $this->assertSame(SupplierStatus::Invited, $s->fresh()->status);
        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p
            ->where('status', 'expired')
            ->where('chat', null));
        $this->assertFalse(app(InviteExpiryService::class)->inviteIsValid($s->fresh()));
    }

    public function test_valid_supplier_can_continue_and_a_paid_one_is_never_shown_as_expired(): void
    {
        $open = $this->supplier();
        $paid = $this->supplier(SupplierStatus::Paid);
        $this->travel(25)->hours();

        $this->get('/invite/'.$paid->invite_token)->assertRedirect('/invite/'.$paid->invite_token.'/my-website');
        $this->get('/invite/'.$paid->invite_token.'/my-website')->assertInertia(fn ($p) => $p->where('status', 'paid'));

        $this->travelBack();
        $this->get('/invite/'.$open->invite_token)->assertInertia(fn ($p) => $p->where('status', 'invited')->has('chat.questions'));
    }

    public function test_grace_protected_supplier_cannot_start_a_new_payment(): void
    {
        $s = $this->supplier(SupplierStatus::PaymentPending);
        $this->payment($s, 'pending', $s->invite_expires_at->copy()->subMinutes(5));
        $this->travel(24 * 60 + 5)->minutes();

        $service = app(InviteExpiryService::class);
        $this->assertTrue($service->isGraceProtected($s->fresh()));
        $this->assertFalse($service->inviteIsValid($s->fresh()));
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'invites:process'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_reminder_and_expired_emails_render_the_template_content(): void
    {
        $s = $this->supplier();
        $r = (new PaymentReminderMail($s))->render();
        $e = (new InvitationExpiredMail($s))->render();

        $this->assertStringContainsString('Your slot is still waiting for you', $r);
        $this->assertStringContainsString($s->invite_expires_at->format('M j, Y · g:i A'), $r);
        $this->assertStringContainsString('Not yet paid', $r);
        $this->assertStringContainsString('Pay ₱10,000', $r);
        $this->assertStringContainsString('Your invitation has expired', $e);
        $this->assertStringContainsString('do not reopen', $e);
        $this->assertStringNotContainsString('next supplier', $e);
    }
}
