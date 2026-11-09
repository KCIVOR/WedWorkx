<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Mail\InvitationMail;
use App\Mail\WaitlistMail;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Finishing Bot 1 sends no Bot 2 link. An admin picks the winners (max = the slot limit, 10). */
class ApprovalGuardrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
    }

    private function register(int $n): void
    {
        $this->post('/register', [
            'name' => "Maria $n", 'email' => "m$n@example.com", 'mobile' => '0917'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'business_name' => "Biz $n", 'business_type' => 'caterer', 'location' => 'Tagaytay City, Cavite',
        ])->assertRedirect('/');
    }

    private function admin(): static
    {
        $user = User::where('email', 'boss@example.com')->first()
            ?? User::factory()->create(['email' => 'boss@example.com', 'password' => 'a-long-test-password-1']);

        return $this->actingAs($user);
    }

    private function approve(array $ids)
    {
        return $this->admin()->post('/'.config('admin.path').'/suppliers/approve', ['ids' => $ids]);
    }

    public function test_finishing_bot_1_sends_no_email_and_no_link(): void
    {
        $this->register(1);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailLog::count());
        $s = Supplier::sole();
        $this->assertSame(SupplierStatus::PendingReview, $s->status);
        $this->assertNull($s->invite_token);
        $this->assertNull($s->slot_number);
    }

    public function test_approving_a_winner_assigns_a_slot_and_emails_the_bot_2_link(): void
    {
        $this->register(1);
        $s = Supplier::sole();

        $this->approve([$s->id])->assertRedirect()->assertSessionHas('admin_flash.tone', 'success');

        $s->refresh();
        $this->assertSame(SupplierStatus::Invited, $s->status);
        $this->assertSame(1, $s->slot_number);
        $this->assertSame(48, strlen($s->invite_token));
        Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('m1@example.com'));
        $this->assertSame(['invite', 'sent'], [EmailLog::sole()->type, EmailLog::sole()->status]);
        $this->assertSame(1, StatusHistory::where('actor', 'admin')->where('supplier_id', $s->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'approve_winner')->count());
        $this->get('/invite/'.$s->invite_token)->assertOk();
    }

    public function test_the_deadline_starts_at_approval_not_at_registration(): void
    {
        $this->register(1);
        $this->travelTo(now()->addHours(30));
        $s = Supplier::sole();

        $this->approve([$s->id]);

        $s->refresh();
        $this->assertTrue($s->invite_expires_at->isFuture());
        $this->assertTrue($s->invite_expires_at->equalTo($s->invited_at->copy()->addHours(24)));
    }

    public function test_only_the_selected_suppliers_get_the_link(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->register($n);
        }
        $picked = Supplier::where('email', 'm2@example.com')->value('id');

        $this->approve([$picked]);

        Mail::assertSent(InvitationMail::class, 1);
        $this->assertSame(SupplierStatus::Invited, Supplier::find($picked)->status);
        $this->assertSame(2, Supplier::where('status', SupplierStatus::PendingReview)->count());
        Mail::assertNotSent(WaitlistMail::class);
    }

    public function test_never_more_than_ten_winners(): void
    {
        foreach (range(1, 12) as $n) {
            $this->register($n);
        }

        $this->approve(Supplier::pluck('id')->all())->assertSessionHas('admin_flash.tone', 'warning');

        $this->assertSame(10, Supplier::whereNotNull('slot_number')->count());
        $this->assertSame(range(1, 10), Supplier::whereNotNull('slot_number')->orderBy('slot_number')->pluck('slot_number')->all());
        $this->assertSame(2, Supplier::where('status', SupplierStatus::PendingReview)->count());
        Mail::assertSent(InvitationMail::class, 10);

        // Even a later, separate attempt cannot go past the limit.
        $left = Supplier::where('status', SupplierStatus::PendingReview)->pluck('id')->all();
        $this->approve($left);
        $this->assertSame(10, Supplier::whereNotNull('slot_number')->count());
        Mail::assertSent(InvitationMail::class, 10);
    }

    public function test_approving_twice_sends_one_email_and_keeps_the_slot(): void
    {
        $this->register(1);
        $id = Supplier::sole()->id;

        $this->approve([$id]);
        $token = Supplier::find($id)->invite_token;
        $this->approve([$id]);

        Mail::assertSent(InvitationMail::class, 1);
        $this->assertSame($token, Supplier::find($id)->invite_token);
        $this->assertSame(1, Supplier::find($id)->slot_number);
    }

    public function test_only_a_signed_in_admin_can_approve(): void
    {
        $this->register(1);

        $this->post('/'.config('admin.path').'/suppliers/approve', ['ids' => [Supplier::sole()->id]])->assertRedirect();

        $this->assertSame(SupplierStatus::PendingReview, Supplier::sole()->status);
        Mail::assertNothingSent();
    }

    public function test_dashboard_counts_only_approved_winners_as_slots_used(): void
    {
        foreach (range(1, 3) as $n) {
            $this->register($n);
        }
        $this->approve([Supplier::first()->id]);

        $this->admin()->get('/'.config('admin.path'))->assertOk()->assertInertia(fn ($p) => $p
            ->where('campaign.slots_taken', 1)->where('campaign.slot_limit', 10)->where('registered', 3));
        $this->assertSame(3, Campaign::first()->slots_taken);
    }
}
