<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WebsiteAnswer;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['email' => 'boss@example.com', 'password' => 'a-long-test-password-1']);
    }

    private function signedIn(): static
    {
        return $this->actingAs($this->admin());
    }

    private function supplier(array $over = []): Supplier
    {
        $this->n++;
        $s = app(SlotService::class)->register($over + [
            'name' => "Person $this->n", 'email' => "p$this->n@example.com", 'mobile' => '+6391800'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Biz $this->n", 'business_type' => 'caterer', 'location' => 'Tagaytay',
        ])['supplier'];

        return $s->fresh();
    }

    private function pay(Supplier $s, string $status = 'completed'): Payment
    {
        return Payment::create(['supplier_id' => $s->id, 'hitpay_request_id' => 'req_'.$s->id.'_'.$status, 'amount' => 10000, 'checkout_url' => 'https://x',
            'status' => $status, 'started_at' => now(), 'expires_at' => now()->addMinutes(30), 'paid_at' => $status === 'completed' ? now() : null]);
    }

    private function paidOrder(Supplier $s): Order
    {
        $s->update(['status' => SupplierStatus::Paid]);
        $this->pay($s);

        return Order::create(['supplier_id' => $s->id, 'reference' => 'KT10-T'.str_pad((string) $s->id, 3, '0', STR_PAD_LEFT), 'current_stage' => 0, 'paid_at' => now()]);
    }

    // ---------- access ----------

    public function test_every_admin_page_sends_guests_to_the_login(): void
    {
        $s = $this->supplier();
        foreach (['', '/suppliers', '/suppliers/export', "/suppliers/{$s->id}", '/settings', '/activity'] as $path) {
            $this->get('/admin'.$path)->assertRedirect('/admin/login');
        }
        foreach (['/settings/email', '/settings/campaign', '/settings/hermes/token', '/settings/test/mail', '/logout'] as $path) {
            $this->post('/admin'.$path)->assertRedirect('/admin/login');
        }
        $this->delete('/admin/settings/hermes/token')->assertRedirect('/admin/login');
        $this->post("/admin/suppliers/{$s->id}/emails/1/resend")->assertRedirect('/admin/login');

        // A guest cannot tell a real supplier id from an unused one.
        $this->get('/admin/suppliers/999999')->assertRedirect('/admin/login');
    }

    public function test_login_works_and_a_wrong_password_gets_one_vague_message(): void
    {
        $this->admin();

        $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'That email and password do not match.']);
        $this->assertGuest();

        $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'a-long-test-password-1'])->assertRedirect('/admin');
        $this->assertAuthenticated();
        $this->get('/admin')->assertOk();

        $actions = AuditLog::pluck('action')->all();
        $this->assertContains('login_failed', $actions);
        $this->assertContains('login', $actions);
        $this->assertStringNotContainsString('wrong', json_encode(AuditLog::all()->toArray())); // passwords are never logged
    }

    public function test_sign_in_is_throttled_after_too_many_wrong_passwords(): void
    {
        $this->admin();
        RateLimiter::clear('boss@example.com|127.0.0.1');

        for ($i = 0; $i < config('admin.login_attempts'); $i++) {
            $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'nope']);
        }
        // Even the right password is refused while blocked.
        $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'a-long-test-password-1'])
            ->assertSessionHasErrors(['email' => 'Too many tries. Please wait a minute and try again.']);
        $this->assertGuest();
    }

    public function test_logout_and_idle_timeout_end_the_session(): void
    {
        $this->signedIn()->get('/admin')->assertOk();
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();

        $user = $this->admin2();
        $this->actingAs($user)->withSession(['admin_last_seen' => now()->subMinutes(config('admin.idle_minutes') + 1)->timestamp])
            ->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    private function admin2(): User
    {
        return User::factory()->create(['email' => 'second@example.com']);
    }

    public function test_admin_pages_are_never_cached_or_indexed(): void
    {
        $this->signedIn()->get('/admin')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_admin_path_is_configurable_through_the_config_file_only(): void
    {
        $this->assertSame('admin', config('admin.path'));
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_starts_with($u, 'admin'));
        $this->assertGreaterThan(10, $routes->count());
    }

    // ---------- dashboard ----------

    public function test_dashboard_counts_slots_waitlist_money_and_flags_money_without_an_order(): void
    {
        foreach (range(1, 10) as $_) {
            $this->supplier();
        }
        $this->supplier(); // 11th: waitlist 1
        $applied = Supplier::where('slot_number', 1)->first();
        $this->paidOrder($applied);
        $stray = Supplier::where('slot_number', 2)->first(); // paid, but no order: a late or repeated payment
        $this->pay($stray);

        $this->signedIn()->get('/admin')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Admin/Dashboard')
            ->where('campaign.slots_taken', 10)->where('campaign.slot_limit', 10)
            ->where('waitlist_count', 1)->where('registered', 11)
            ->where('money.collected', 10000)->where('money.orders', 1)
            ->has('attention.refunds', 1)->where('attention.refunds.0.supplier_id', $stray->id)
            ->where('statuses', fn ($s) => collect($s)->firstWhere('value', 'invited')['count'] === 9
                && collect($s)->firstWhere('value', 'waitlisted')['count'] === 1
                && collect($s)->firstWhere('value', 'paid')['count'] === 1));
    }

    public function test_dashboard_lists_failed_emails_and_invitations_about_to_expire(): void
    {
        $s = $this->supplier();
        EmailLog::create(['supplier_id' => $s->id, 'type' => 'reminder', 'status' => 'failed', 'sent_at' => now()]);
        $s->update(['invite_expires_at' => now()->addMinutes(90)]);
        $late = $this->supplier();
        $late->update(['invite_expires_at' => now()->addHours(10)]);

        $this->signedIn()->get('/admin')->assertInertia(fn ($p) => $p
            ->where('attention.failed_emails', 1)
            ->has('attention.expiring_soon', 1)->where('attention.expiring_soon.0.supplier_id', $s->id));
    }

    // ---------- suppliers list ----------

    public function test_suppliers_are_listed_winners_first_then_the_waiting_list_with_their_progress(): void
    {
        foreach (range(1, 11) as $_) {
            $this->supplier();
        }
        $this->paidOrder(Supplier::where('slot_number', 3)->first());

        $this->signedIn()->get('/admin/suppliers')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Admin/Suppliers')->where('suppliers.total', 11)
            ->where('suppliers.data.0.slot', 1)->where('suppliers.data.9.slot', 10)
            ->where('suppliers.data.10.waitlist', 1)->where('suppliers.data.10.status', 'waitlisted')
            ->where('suppliers.data.2.status', 'paid')->where('suppliers.data.2.payment.state', 'paid')
            ->where('suppliers.data.2.reference', fn ($r) => str_starts_with($r, 'KT10-'))
            ->where('suppliers.data.0.emails_sent', 0));
    }

    public function test_filters_and_search_narrow_the_list(): void
    {
        $a = $this->supplier(['business_name' => 'Rosal Catering', 'business_type' => 'caterer', 'name' => 'Maria Santos']);
        $b = $this->supplier(['business_name' => 'Lens Studio', 'business_type' => 'photographer', 'email' => 'lens@example.com']);
        foreach (range(1, 8) as $_) {
            $this->supplier();
        }
        $w = $this->supplier(['business_name' => 'Late Bloom', 'business_type' => 'florist']); // waitlisted
        $this->paidOrder($a);
        $this->signedIn();

        $ids = fn (string $q) => collect($this->get("/admin/suppliers?$q")->viewData('page')['props']['suppliers']['data'])->pluck('id')->all();

        $this->assertSame([$a->id], $ids('q=rosal'));
        $this->assertSame([$a->id], $ids('q=Maria'));
        $this->assertSame([$b->id], $ids('q=lens@example'));
        $this->assertSame([$w->id], $ids('group=waitlist'));
        $this->assertCount(10, $ids('group=winners'));
        $this->assertSame([$a->id], $ids('status=paid'));
        $this->assertSame([$b->id], $ids('type=photographer'));
        $this->assertSame([], $ids('q=zzzz'));
        $this->assertCount(11, $ids('status=nonsense')); // an unknown status is ignored, not an error
        $this->assertSame([], $ids('q='.urlencode('%'))); // wildcards are matched literally
    }

    public function test_the_list_is_paged(): void
    {
        config(['admin.per_page' => 4]);
        foreach (range(1, 6) as $_) {
            $this->supplier();
        }

        $this->signedIn()->get('/admin/suppliers?page=2')->assertInertia(fn ($p) => $p
            ->where('suppliers.current_page', 2)->where('suppliers.last_page', 2)->has('suppliers.data', 2));
    }

    // ---------- detail ----------

    public function test_the_detail_page_shows_everything_and_never_the_invite_link(): void
    {
        $s = $this->supplier();
        WebsiteAnswer::create(['supplier_id' => $s->id, 'submitted_at' => now(), 'answers' => [
            'description' => 'Garden menus', 'services' => [['name' => 'Buffet', 'price' => 'P650']], 'service_areas' => ['Tagaytay', 'Silang'],
            'booking' => 'Messenger', 'colors' => 'Pink', 'style' => 'romantic', 'extra1' => 'Buffet', 'extra2' => 'Yes, we can accommodate special requests',
        ]]);
        $order = $this->paidOrder($s);
        $order->stageLogs()->create(['stage' => 1, 'note' => 'Brief reviewed']);
        $s->uploads()->create(['kind' => 'logo', 'path' => 'uploads/1/logo.png', 'original_name' => 'logo.png', 'mime' => 'image/png', 'size_kb' => 14]);

        $token = $s->invite_token;
        $response = $this->signedIn()->get("/admin/suppliers/{$s->id}")->assertOk();
        $response->assertInertia(fn ($p) => $p
            ->component('Admin/SupplierShow')
            ->where('supplier.business_name', $s->business_name)
            ->where('answers.0.label', 'Business Description')->where('answers.0.value', 'Garden menus')
            ->where('answers.1.value', 'Buffet (P650)')->where('answers.2.value', 'Tagaytay, Silang · Messenger')
            ->where('answers.4.value', 'Romantic & Soft')
            ->has('uploads', 1)->where('uploads.0.image', true)
            ->has('payments', 1)->has('timeline')->where('stages.0.note', 'Brief reviewed'));

        $this->assertStringNotContainsString($token, $response->getContent());
    }

    public function test_a_supplier_who_has_not_answered_has_no_answers_and_an_unknown_id_is_404(): void
    {
        $s = $this->supplier();

        $this->signedIn()->get("/admin/suppliers/{$s->id}")->assertInertia(fn ($p) => $p->where('answers', []));
        $this->get('/admin/suppliers/99999')->assertNotFound();
    }

    // ---------- export ----------

    public function test_csv_export_follows_the_filters_protects_spreadsheets_and_is_logged(): void
    {
        $this->supplier(['business_name' => '=HYPERLINK("http://evil","x")']);
        $this->supplier(['business_name' => 'Plain Biz']);

        $response = $this->signedIn()->get('/admin/suppliers/export?q=biz');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Plain Biz', $csv);
        $this->assertStringNotContainsString('=HYPERLINK', str_replace("'=HYPERLINK", '', $csv)); // only appears with the protecting quote
        $this->assertStringContainsString('Slot,Waitlist,Name,Business', $csv);
        $this->assertSame(1, substr_count(trim($csv), "\n")); // header + 1 matching row

        $log = AuditLog::where('action', 'export_suppliers')->sole();
        $this->assertSame('biz', $log->detail['q']);
    }

    public function test_csv_export_with_a_formula_cell_is_prefixed(): void
    {
        $this->supplier(['business_name' => '=cmd|calc']);

        $csv = $this->signedIn()->get('/admin/suppliers/export')->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
    }

    // ---------- email resend ----------

    public function test_a_failed_email_can_be_sent_again_once(): void
    {
        $s = $this->supplier();
        $log = EmailLog::create(['supplier_id' => $s->id, 'type' => 'invite', 'status' => 'failed', 'sent_at' => now()]);

        $this->signedIn()->post("/admin/suppliers/{$s->id}/emails/{$log->id}/resend")->assertRedirect();

        Mail::assertSent(InvitationMail::class, 1);
        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame('resend_email', AuditLog::latest('id')->first()->action);

        // already sent: pressing again does nothing
        $this->post("/admin/suppliers/{$s->id}/emails/{$log->id}/resend")->assertRedirect();
        Mail::assertSent(InvitationMail::class, 1);
    }

    public function test_resend_refuses_other_suppliers_emails_and_makes_no_sense_for_waitlisted_invites(): void
    {
        $a = $this->supplier();
        foreach (range(1, 9) as $_) {
            $this->supplier();
        }
        $w = $this->supplier(); // waitlisted
        $aLog = EmailLog::create(['supplier_id' => $a->id, 'type' => 'invite', 'status' => 'failed', 'sent_at' => now()]);
        $this->signedIn();

        $this->post("/admin/suppliers/{$w->id}/emails/{$aLog->id}/resend")->assertNotFound();

        $a->update(['status' => SupplierStatus::Expired]); // an invitation to an expired supplier would be a dead link
        $this->post("/admin/suppliers/{$a->id}/emails/{$aLog->id}/resend");
        Mail::assertNothingSent();
    }

    // ---------- settings ----------

    public function test_settings_page_never_contains_a_secret(): void
    {
        $this->signedIn();
        $this->post('/admin/settings/email', ['mail_host' => 'smtp.example.com', 'mail_port' => 465, 'mail_encryption' => 'ssl',
            'mail_username' => 'me@example.com', 'mail_password' => 'SUPER-SECRET-PASSWORD']);
        $this->post('/admin/settings/hitpay', ['hitpay_api_key' => 'KEY-SECRET-123', 'hitpay_salt' => 'SALT-SECRET-456']);

        $body = $this->get('/admin/settings')->assertOk()->getContent();

        foreach (['SUPER-SECRET-PASSWORD', 'KEY-SECRET-123', 'SALT-SECRET-456'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->get('/admin/settings')->assertInertia(fn ($p) => $p
            ->where('fields.mail_password.source', 'admin')->where('fields.mail_password.value', null)
            ->where('fields.hitpay_api_key.source', 'admin')->where('fields.mail_host.value', 'smtp.example.com'));
    }

    public function test_secrets_are_stored_encrypted_and_applied_over_env(): void
    {
        $this->signedIn();
        config(['hitpay.api_key' => 'from-env']);

        $this->post('/admin/settings/hitpay', ['hitpay_api_key' => 'KEY-SECRET-123', 'hitpay_salt' => 'S', 'hitpay_base_url' => 'https://api.hit-pay.com/v1'])
            ->assertSessionHasNoErrors();

        $stored = DB::table('settings')->where('key', 'hitpay_api_key')->value('value');
        $this->assertStringNotContainsString('KEY-SECRET-123', $stored);
        $this->assertSame('KEY-SECRET-123', decrypt($stored, false));
        $this->assertTrue((bool) DB::table('settings')->where('key', 'hitpay_api_key')->value('is_secret'));
        $this->assertSame('KEY-SECRET-123', config('hitpay.api_key'));
        $this->assertSame('https://api.hit-pay.com/v1', config('hitpay.base_url'));

        // a fresh boot (next request) reads it back
        config(['hitpay.api_key' => 'from-env']);
        app(\App\Services\SettingsService::class)->apply();
        $this->assertSame('KEY-SECRET-123', config('hitpay.api_key'));
    }

    public function test_a_blank_secret_keeps_the_saved_one_and_clear_removes_it_back_to_env(): void
    {
        $this->signedIn();
        config(['hitpay.api_key' => 'from-env']);
        $this->post('/admin/settings/hitpay', ['hitpay_api_key' => 'KEY-1']);

        $this->post('/admin/settings/hitpay', ['hitpay_api_key' => '']); // saving the form again without retyping it
        $this->assertSame('KEY-1', config('hitpay.api_key'));

        $this->post('/admin/settings/hitpay', ['hitpay_api_key' => '', 'clear_hitpay_api_key' => true]);
        $this->assertDatabaseMissing('settings', ['key' => 'hitpay_api_key']);
        $this->get('/admin/settings')->assertInertia(fn ($p) => $p->where('fields.hitpay_api_key.source', 'env'));
    }

    public function test_settings_are_validated(): void
    {
        $this->signedIn();

        $this->post('/admin/settings/email', ['mail_port' => 'abc'])->assertSessionHasErrors('mail_port');
        $this->post('/admin/settings/email', ['mail_port' => 70000])->assertSessionHasErrors('mail_port');
        $this->post('/admin/settings/email', ['mail_from_address' => 'not-an-email'])->assertSessionHasErrors('mail_from_address');
        $this->post('/admin/settings/email', ['mail_encryption' => 'plain'])->assertSessionHasErrors('mail_encryption');
        $this->post('/admin/settings/hitpay', ['hitpay_base_url' => 'http://insecure.example.com'])->assertSessionHasErrors('hitpay_base_url');
        $this->post('/admin/settings/nonsense', [])->assertNotFound();
        $this->post('/admin/settings/hermes', [])->assertNotFound(); // the token is only ever generated, never typed
        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_saving_smtp_host_switches_the_mailer_and_blank_plain_fields_fall_back_to_env(): void
    {
        $this->signedIn();
        config(['mail.mailers.smtp.host' => 'env-host']);

        $this->post('/admin/settings/email', ['mail_host' => 'smtp.gmail.com', 'mail_encryption' => 'ssl', 'mail_port' => 465]);
        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtp', config('mail.default'));

        $this->post('/admin/settings/email', ['mail_host' => '', 'mail_encryption' => 'tls']);
        $this->assertDatabaseMissing('settings', ['key' => 'mail_host']);
    }

    public function test_the_audit_log_names_changed_fields_but_never_values(): void
    {
        $this->signedIn();
        $this->post('/admin/settings/hitpay', ['hitpay_api_key' => 'KEY-SECRET-123', 'hitpay_base_url' => 'https://api.hit-pay.com/v1']);

        $log = AuditLog::where('action', 'settings_saved')->sole();
        $this->assertSame(['hitpay_api_key', 'hitpay_base_url'], $log->detail['fields']);
        $this->assertStringNotContainsString('KEY-SECRET-123', json_encode(AuditLog::all()));
        $this->assertStringNotContainsString('hit-pay.com', json_encode(AuditLog::all()));

        $this->get('/admin/activity')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/Audit')->has('logs.data'));
    }

    public function test_the_hermes_token_is_made_once_shown_once_and_then_works_on_the_api(): void
    {
        $this->signedIn();

        $this->post('/admin/settings/hermes/token')->assertRedirect();
        $token = session('admin_token');
        $this->assertSame(64, strlen($token));
        $this->assertNotSame($token, DB::table('settings')->where('key', 'hermes_token')->value('value')); // stored encrypted

        $shown = $this->get('/admin/settings');
        $this->assertStringContainsString($token, $shown->getContent()); // the one time
        $this->assertStringNotContainsString($token, $this->get('/admin/settings')->getContent()); // never again

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/hermes/orders')->assertOk();
        $this->withHeader('Authorization', 'Bearer wrong')->getJson('/api/hermes/orders')->assertUnauthorized();

        // a new token replaces it at once
        $this->post('/admin/settings/hermes/token');
        $new = session('admin_token');
        $this->assertNotSame($token, $new);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/hermes/orders')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$new)->getJson('/api/hermes/orders')->assertOk();

        $this->delete('/admin/settings/hermes/token');
        $this->withHeader('Authorization', 'Bearer '.$new)->getJson('/api/hermes/orders')->assertUnauthorized();
    }

    public function test_a_saved_secret_that_cannot_be_decrypted_counts_as_not_set(): void
    {
        $this->signedIn();
        DB::table('settings')->insert(['key' => 'hitpay_api_key', 'value' => 'garbage-not-encrypted', 'is_secret' => true, 'created_at' => now(), 'updated_at' => now()]);
        config(['hitpay.api_key' => null]);

        app(\App\Services\SettingsService::class)->apply();

        $this->assertNull(config('hitpay.api_key'));
        $this->get('/admin/settings')->assertOk()->assertInertia(fn ($p) => $p->where('fields.hitpay_api_key.source', 'unset'));
    }

    // ---------- campaign ----------

    public function test_campaign_values_can_change_before_anyone_registers_and_lock_after(): void
    {
        $this->signedIn();

        $this->post('/admin/settings/campaign', ['price' => 9500, 'invite_expiry_hours' => 48, 'grace_minutes' => 20])->assertSessionHasNoErrors();
        $c = Campaign::first();
        $this->assertSame([9500.0, 48, 20], [(float) $c->price, $c->invite_expiry_hours, $c->grace_minutes]);
        $this->get('/admin/settings')->assertInertia(fn ($p) => $p->where('campaign.editable', true)->where('campaign.slot_limit', 10));

        $this->supplier();
        $this->post('/admin/settings/campaign', ['price' => 1, 'invite_expiry_hours' => 1, 'grace_minutes' => 0]);
        $this->assertSame(9500.0, (float) Campaign::first()->price); // unchanged
        $this->get('/admin/settings')->assertInertia(fn ($p) => $p->where('campaign.editable', false));
    }

    public function test_campaign_values_are_validated(): void
    {
        $this->signedIn();

        $this->post('/admin/settings/campaign', ['price' => 0, 'invite_expiry_hours' => 0, 'grace_minutes' => -1])
            ->assertSessionHasErrors(['price', 'invite_expiry_hours', 'grace_minutes']);
        $this->post('/admin/settings/campaign', ['price' => 'free', 'invite_expiry_hours' => 999, 'grace_minutes' => 999])
            ->assertSessionHasErrors(['price', 'invite_expiry_hours', 'grace_minutes']);
    }

    // ---------- connection tests ----------

    public function test_the_test_email_goes_to_the_signed_in_admin_only(): void
    {
        $this->signedIn()->post('/admin/settings/test/mail')->assertRedirect()->assertSessionHas('admin_test', fn ($t) => $t['ok'] === true && $t['what'] === 'mail');

        Mail::assertSent(\App\Mail\AdminTestMail::class, fn ($m) => $m->hasTo('boss@example.com'));
        Mail::assertSentCount(1);
    }

    public function test_hitpay_check_reports_ok_rejected_and_missing(): void
    {
        $this->signedIn();

        $this->post('/admin/settings/test/hitpay')->assertSessionHas('admin_test', fn ($t) => $t['ok'] === false && str_contains($t['message'], 'No HitPay API key'));

        config(['hitpay.api_key' => 'k']);
        Http::fake(['*' => Http::sequence()->push(['data' => []], 200)->push([], 401)]);
        $this->post('/admin/settings/test/hitpay')->assertSessionHas('admin_test', fn ($t) => $t['ok'] === true);
        Http::assertSent(fn ($r) => $r->hasHeader('X-BUSINESS-API-KEY', 'k') && $r->method() === 'GET');

        $this->post('/admin/settings/test/hitpay')->assertSessionHas('admin_test', fn ($t) => $t['ok'] === false && str_contains($t['message'], 'rejected'));
    }

    public function test_ai_check_needs_a_key_and_reports_the_provider_answer(): void
    {
        $this->signedIn();
        $this->post('/admin/settings/test/ai')->assertSessionHas('admin_test', fn ($t) => $t['ok'] === false);

        config(['ai.api_key' => 'k']);
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']]], 200)]);
        $this->post('/admin/settings/test/ai')->assertSessionHas('admin_test', fn ($t) => $t['ok'] === true);
        $this->post('/admin/settings/test/other')->assertNotFound();
    }

    // ---------- the command ----------

    public function test_admin_create_makes_an_admin_with_a_hashed_password_and_resets_it(): void
    {
        $this->artisan('admin:create', ['email' => 'New@Example.com', '--name' => 'Rovick'])
            ->expectsQuestion('Password (at least 12 characters)', 'correct-horse-battery')
            ->expectsQuestion('Type it again', 'correct-horse-battery')
            ->assertSuccessful();

        $u = User::where('email', 'new@example.com')->sole();
        $this->assertNotSame('correct-horse-battery', $u->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('correct-horse-battery', $u->password));
        $this->assertSame('admin_created', AuditLog::latest('id')->first()->action);

        $this->artisan('admin:create', ['email' => 'new@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'another-long-password')
            ->expectsQuestion('Type it again', 'another-long-password')
            ->assertSuccessful();
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('another-long-password', $u->fresh()->password));
    }

    public function test_admin_create_rejects_a_short_or_mismatched_password(): void
    {
        $this->artisan('admin:create', ['email' => 'x@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'short')->assertFailed();
        $this->artisan('admin:create', ['email' => 'x@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'long-enough-password')
            ->expectsQuestion('Type it again', 'different-password-xx')->assertFailed();
        $this->assertSame(0, User::count());
    }
}
