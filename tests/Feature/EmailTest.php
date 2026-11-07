<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Mail\WaitlistMail;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\Supplier;
use App\Services\EmailService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
    }

    private function register(int $n): void
    {
        $this->post('/register', [
            'name' => "Maria Santos $n",
            'email' => "supplier$n@example.com",
            'mobile' => '0917'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'business_name' => "Rosal Catering $n",
            'business_type' => 'caterer',
            'location' => 'Tagaytay City, Cavite',
        ])->assertRedirect('/');
    }

    private function breakMail(): void
    {
        // A real SMTP transport pointed at a closed port: sending throws, like a down mail server would.
        config([
            'mail.default' => 'broken',
            'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2],
        ]);
    }

    public function test_each_supplier_gets_exactly_one_email_of_the_right_type(): void
    {
        Mail::fake();

        for ($n = 1; $n <= 12; $n++) {
            $this->register($n);
        }

        Mail::assertSent(InvitationMail::class, 10);
        Mail::assertSent(WaitlistMail::class, 2);
        $this->assertSame(12, EmailLog::where('status', 'sent')->count());
        $this->assertSame(10, EmailLog::where('type', 'invite')->count());
        $this->assertSame(2, EmailLog::where('type', 'waitlist')->count());
    }

    public function test_retriggering_does_not_send_a_second_email(): void
    {
        Mail::fake();
        $this->register(1);
        $supplier = Supplier::first();

        $emails = app(EmailService::class);
        $emails->sendRegistrationOutcome($supplier);
        $emails->sendRegistrationOutcome($supplier);

        Mail::assertSent(InvitationMail::class, 1);
        $this->assertSame(1, EmailLog::count());
    }

    public function test_duplicate_registration_sends_nothing(): void
    {
        Mail::fake();
        $this->register(1);
        $this->register(1);

        Mail::assertSent(InvitationMail::class, 1);
        $this->assertSame(1, EmailLog::count());
    }

    public function test_failed_send_is_logged_and_does_not_break_registration(): void
    {
        $this->breakMail();

        $this->register(1);

        $this->assertSame(1, Supplier::count());
        $this->assertSame(1, Campaign::first()->slots_taken);
        $log = EmailLog::sole();
        $this->assertSame(['invite', 'failed'], [$log->type, $log->status]);
    }

    public function test_a_failed_email_can_be_retried_but_a_sent_one_never_is(): void
    {
        $this->breakMail();
        $this->register(1);
        $supplier = Supplier::first();
        $this->assertSame('failed', EmailLog::sole()->status);

        config(['mail.default' => 'array']);
        $emails = app(EmailService::class);
        $this->assertTrue($emails->send($supplier, 'invite', new InvitationMail($supplier)));
        $this->assertSame('sent', EmailLog::sole()->status);

        $this->assertFalse($emails->send($supplier, 'invite', new InvitationMail($supplier)));
        $this->assertSame(1, EmailLog::count());
    }

    public function test_failed_payment_emails_may_repeat(): void
    {
        Mail::fake();
        $this->register(1);
        $supplier = Supplier::first();
        $emails = app(EmailService::class);

        $this->assertTrue($emails->send($supplier, 'failed', new InvitationMail($supplier)));
        $this->assertTrue($emails->send($supplier, 'failed', new InvitationMail($supplier)));

        $this->assertSame(2, EmailLog::where('type', 'failed')->count());
    }

    public function test_invitation_shows_deadline_price_and_private_link(): void
    {
        $this->register(1);
        $supplier = Supplier::first();

        $html = (new InvitationMail($supplier))->render();
        $deadline = $supplier->invite_expires_at->format('M j, Y · g:i A');

        $this->assertStringContainsString($deadline, $html);
        $this->assertStringContainsString('₱10,000', $html);
        $this->assertStringContainsString('href="'.url('/invite/'.$supplier->invite_token).'"', $html);
        $this->assertStringContainsString('Continue to Website Chatbot', $html);
        $this->assertStringContainsString('Congratulations, Maria!', $html);
        $this->assertStringContainsString('bgcolor="#2f5bea"', $html); // royal blue header from the template
    }

    public function test_waitlist_email_has_number_and_says_slots_do_not_reopen(): void
    {
        Campaign::first()->update(['slots_taken' => 10]);
        $this->register(11);
        $supplier = Supplier::first();

        $html = (new WaitlistMail($supplier))->render();

        $this->assertStringContainsString('Waiting list &middot; #1', $html);
        $this->assertStringContainsString('number 1 on the waiting list', $html);
        $this->assertStringContainsString('slots do not reopen', $html);
        $this->assertStringNotContainsString('/invite/', $html);
    }

    public function test_invitation_goes_to_the_supplier_address(): void
    {
        Mail::fake();
        $this->register(1);

        Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('supplier1@example.com') && $m->envelope()->subject === 'You’re in! Your 10-10-10 website invitation');
    }

    public function test_invite_link_shows_status_and_unknown_token_is_404(): void
    {
        Mail::fake();
        $this->register(1);
        $supplier = Supplier::first();

        $this->get('/invite/'.$supplier->invite_token)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Invite')
            ->where('status', 'invited')
            ->where('businessName', 'Rosal Catering 1')
            ->missing('email')
            ->missing('mobile'));

        $this->get('/invite/not-a-real-token')->assertNotFound();
    }

    public function test_waitlisted_supplier_has_no_invite_page(): void
    {
        Mail::fake();
        Campaign::first()->update(['slots_taken' => 10]);
        $this->register(11);

        $this->assertNull(Supplier::first()->invite_token);
        $this->get('/invite/')->assertNotFound();
    }
}
