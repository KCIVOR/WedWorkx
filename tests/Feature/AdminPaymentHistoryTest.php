<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WebsiteAnswer;
use App\Services\PaymentService;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminPaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([]);
        config(['app.timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'Asia/Manila'));
    }

    private function path(array $query = []): string
    {
        return '/'.config('admin.path').'/payments'.($query ? '?'.http_build_query($query) : '');
    }

    private function signIn(): static
    {
        return $this->actingAs(User::factory()->create());
    }

    private function supplier(array $attributes = []): Supplier
    {
        $n = ++$this->sequence;
        return app(SlotService::class)->register($attributes + [
            'name' => "History Person $n", 'email' => "history$n@example.test",
            'mobile' => '+6391800'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'business_name' => "History Business $n", 'business_type' => 'caterer', 'location' => 'Tagaytay',
        ])['supplier']->fresh();
    }

    private function payment(Supplier $supplier, array $attributes = []): Payment
    {
        return Payment::create($attributes + [
            'supplier_id' => $supplier->id, 'hitpay_request_id' => 'history_'.(++$this->sequence),
            'amount' => '10000.00', 'checkout_url' => 'https://checkout.example.test/private',
            'status' => 'pending', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function props(array $query = []): array
    {
        return $this->get($this->path($query))->assertOk()->viewData('page')['props'];
    }

    private function ids(array $query = []): array
    {
        return array_column($this->props($query)['payments']['data'], 'id');
    }

    public function test_route_uses_configured_admin_prefix_name_and_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admin.payments');
        $this->assertNotNull($route, 'Payment History needs the named admin.payments route.');
        $this->assertSame(config('admin.path').'/payments', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('admin', $route->gatherMiddleware());
    }

    public function test_guests_and_invite_only_visitors_cannot_view_history(): void
    {
        $supplier = $this->supplier();
        $this->get($this->path())->assertRedirect('/'.config('admin.path').'/login');
        $this->getJson($this->path())->assertUnauthorized();
        $this->get('/invite/'.$supplier->invite_token)->assertOk();
        $this->get($this->path())->assertRedirect('/'.config('admin.path').'/login');
    }

    public function test_idle_admin_session_expires(): void
    {
        $this->signIn()->withSession(['admin_last_seen' => now()->subMinutes(config('admin.idle_minutes') + 1)->timestamp])
            ->get($this->path())->assertRedirect('/'.config('admin.path').'/login');
        $this->assertGuest();
    }

    public function test_empty_history_has_explicit_contract_and_private_headers(): void
    {
        $this->signIn()->get($this->path())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page->component('Admin/Payments')
                ->where('payments.data', [])->where('payments.total', 0)->where('payments.current_page', 1)
                ->where('payments.last_page', 1)->where('payments.from', null)->where('payments.to', null)
                ->where('filters', ['q' => '', 'status' => '', 'date_from' => '', 'date_to' => ''])
                ->where('options.statuses', fn ($items) => collect($items)->pluck('label', 'value')->all() === [
                    'pending' => 'Pending', 'completed' => 'Paid', 'failed' => 'Failed', 'expired' => 'Link expired',
                ])->where('server_now', fn ($value) => Carbon::parse($value)->equalTo(now())));
    }

    public function test_all_attempts_are_sorted_by_start_then_id_with_safe_explicit_rows(): void
    {
        $a = $this->supplier();
        $b = $this->supplier();
        $old = $this->payment($a, ['status' => 'failed', 'started_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);
        $paid = $this->payment($a, ['status' => 'completed', 'amount' => '10000.50', 'paid_at' => now(), 'paid_late' => true,
            'webhook_payload' => ['secret' => 'synthetic-payload-private']]);
        $dev = $this->payment($b, ['hitpay_request_id' => 'dev_history', 'expires_at' => now()->subSecond()]);
        $this->signIn();
        $props = $this->props();
        $this->assertSame([$dev->id, $paid->id, $old->id], array_column($props['payments']['data'], 'id'));
        $rows = collect($props['payments']['data'])->keyBy('id');
        $this->assertEqualsCanonicalizing(['id', 'supplier', 'request_id', 'amount', 'status', 'started_at', 'paid_at', 'expires_at', 'late', 'simulated', 'awaiting_expiry_update'], array_keys($rows[$paid->id]));
        $this->assertSame(['id' => $a->id, 'name' => $a->name, 'business_name' => $a->business_name], $rows[$paid->id]['supplier']);
        $this->assertSame('10000.50', $rows[$paid->id]['amount']);
        $this->assertTrue($rows[$paid->id]['late']);
        $this->assertFalse($rows[$paid->id]['simulated']);
        $this->assertFalse($rows[$paid->id]['awaiting_expiry_update']);
        $this->assertFalse($rows[$old->id]['awaiting_expiry_update']);
        $this->assertTrue($rows[$dev->id]['simulated']);
        $this->assertTrue($rows[$dev->id]['awaiting_expiry_update']);
        $this->assertSame('pending', $rows[$dev->id]['status']);
        $this->assertNull($rows[$dev->id]['paid_at']);
        foreach (['started_at', 'expires_at', 'paid_at'] as $field) {
            $this->assertTrue(Carbon::parse($rows[$paid->id][$field])->equalTo($paid->$field));
        }
        $json = json_encode($props);
        foreach ([$a->invite_token, $a->mobile, 'synthetic-payload-private', 'checkout.example.test', 'webhook_payload', 'checkout_url', 'invite_token', 'answers'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_search_is_trimmed_partial_literal_and_grouped_with_status_and_dates(): void
    {
        $supplier = $this->supplier(['name' => 'Needle Person', 'business_name' => 'Literal %_\\ business', 'email' => 'needle@example.test']);
        $match = $this->payment($supplier, ['hitpay_request_id' => 'request-needle', 'status' => 'completed', 'started_at' => now()->startOfDay()]);
        $this->payment($supplier, ['status' => 'failed', 'expires_at' => now()->subMinute()]);
        $this->payment($supplier, ['status' => 'completed', 'started_at' => now()->subDays(2)]);
        $control = $this->payment($this->supplier(['name' => 'Control Customer', 'business_name' => 'Plain Business', 'email' => 'control@example.test']), ['status' => 'completed', 'hitpay_request_id' => 'control-reference']);
        $future = $this->payment($this->supplier());
        $this->signIn();
        foreach ([' Person ', 'Literal', 'needle@example', 'request-needle', '%', '_', '\\'] as $q) {
            $this->assertSame([$match->id], $this->ids(['q' => $q, 'status' => 'completed', 'date_from' => '2026-11-08', 'date_to' => '2026-11-08']));
        }
        $this->assertSame('Person', $this->props(['q' => ' Person '])['filters']['q']);
        $this->assertSame([], $this->ids(['q' => 'no-synthetic-match']));
        $this->assertFalse(collect($this->props()['payments']['data'])->firstWhere('id', $future->id)['awaiting_expiry_update']);
        $this->assertSame([$control->id, $match->id], $this->ids(['status' => 'completed', 'date_from' => '2026-11-08', 'date_to' => '2026-11-08']));
        $this->assertCount(5, $this->ids(['q' => '', 'status' => '', 'date_from' => '', 'date_to' => '']));
    }

    public function test_date_range_includes_both_manila_days_and_excludes_next_midnight(): void
    {
        $supplier = $this->supplier();
        $before = $this->payment($supplier, ['started_at' => Carbon::parse('2026-11-06 23:59:59', 'Asia/Manila')]);
        $first = $this->payment($supplier, ['started_at' => Carbon::parse('2026-11-07 00:00:00', 'Asia/Manila')]);
        $last = $this->payment($supplier, ['started_at' => Carbon::parse('2026-11-08 23:59:59', 'Asia/Manila')]);
        $after = $this->payment($supplier, ['started_at' => Carbon::parse('2026-11-09 00:00:00', 'Asia/Manila')]);
        $this->signIn();
        $this->assertSame([$last->id, $first->id], $this->ids(['date_from' => '2026-11-07', 'date_to' => '2026-11-08']));
        $this->assertSame([$after->id, $last->id], $this->ids(['date_from' => '2026-11-08']));
        $this->assertSame([$first->id, $before->id], $this->ids(['date_to' => '2026-11-07']));
    }

    public function test_invalid_filters_return_json_validation_errors_and_normal_session_errors(): void
    {
        $this->signIn();
        foreach ([
            ['q' => str_repeat('x', 161)], ['q' => ['x']], ['status' => 'paid'], ['status' => ['pending']],
            ['date_from' => '2026-02-30'], ['date_to' => '2026-2-1'], ['date_to' => ['2026-11-08']],
            ['date_from' => '2026-11-09', 'date_to' => '2026-11-08'],
            ['page' => '0'], ['page' => '-1'], ['page' => '1.5'], ['page' => ['1']], ['page' => 'abc'],
        ] as $query) {
            $this->getJson($this->path($query))->assertUnprocessable()->assertJsonStructure(['errors']);
        }
        foreach (['q', 'status', 'date_from', 'date_to'] as $field) {
            foreach ([123, true, 1.5] as $value) {
                $this->call('GET', $this->path(), [$field => $value], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->from($this->path())->get($this->path(['status' => 'invalid']))->assertRedirect($this->path())->assertSessionHasErrors('status');
        $this->getJson($this->path(['q' => str_repeat('x', 160)]))->assertOk();
    }

    public function test_pagination_uses_config_and_redirects_past_end_retaining_filters(): void
    {
        $this->assertSame(25, config('admin.per_page'));
        config(['admin.per_page' => 2]);
        $supplier = $this->supplier();
        foreach (range(1, 3) as $i) {
            $attempts[] = $this->payment($supplier, ['status' => 'failed']);
        }
        $this->signIn();
        $props = $this->props(['page' => 2, 'status' => 'failed']);
        $this->assertSame(3, $props['payments']['total']);
        $this->assertSame(2, $props['payments']['current_page']);
        $this->assertSame(2, $props['payments']['last_page']);
        $this->assertSame(3, $props['payments']['from']);
        $this->assertSame(3, $props['payments']['to']);
        $this->assertCount(1, $props['payments']['data']);
        $this->assertSame([$attempts[0]->id], array_column($props['payments']['data'], 'id'));
        $this->assertSame([$attempts[2]->id, $attempts[1]->id], $this->ids(['status' => 'failed']));
        $redirect = $this->get($this->path(['page' => 99, 'q' => 'History', 'status' => 'failed', 'date_from' => '2026-11-08', 'date_to' => '2026-11-08']))->assertRedirect();
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertEquals(['page' => '2', 'q' => 'History', 'status' => 'failed', 'date_from' => '2026-11-08', 'date_to' => '2026-11-08'], $query);
        $this->get($this->path(['page' => 99, 'q' => 'nothing']))->assertRedirect($this->path(['q' => 'nothing', 'page' => 1]));
    }

    public function test_default_page_size_is_twenty_five_with_stable_ties(): void
    {
        $supplier = $this->supplier();
        foreach (range(1, 26) as $_) {
            $attempts[] = $this->payment($supplier);
        }
        $this->signIn();
        $descending = array_reverse(array_map(fn ($payment) => $payment->id, $attempts));
        $this->assertSame(array_slice($descending, 0, 25), $this->ids());
        $this->assertSame(array_slice($descending, 25), $this->ids(['page' => 2]));
    }

    public function test_history_reads_do_not_mutate_business_tables_or_contact_provider(): void
    {
        $this->payment($this->supplier(), ['expires_at' => now()->subMinute()]);
        $this->signIn();
        $tables = ['campaigns', 'suppliers', 'payments', 'orders', 'website_answers', 'uploads', 'email_logs', 'status_history', 'settings', 'admin_audit_logs'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $this->props();
        $this->props(['status' => 'pending']);
        $this->assertSame($before, $snapshot());
        Http::assertNothingSent();
    }

    public function test_service_writers_remain_visible_as_current_attempt_state_without_event_duplicates(): void
    {
        $supplier = $this->supplier();
        $supplier->update(['status' => SupplierStatus::DetailsSubmitted]);
        WebsiteAnswer::create(['supplier_id' => $supplier->id, 'answers' => ['description' => 'Synthetic private answer'], 'submitted_at' => now()]);
        config(['dev.enabled' => false, 'hitpay.api_key' => 'synthetic-test-key']);
        Http::fake(['*/payment-requests' => Http::response(['id' => 'future-provider-request', 'url' => 'https://checkout.example.test/future', 'status' => 'pending'], 201)]);
        $service = app(PaymentService::class);
        $payment = $service->startPayment($supplier->fresh());
        $this->signIn();
        $this->assertSame([$payment->id], $this->ids());
        $event = ['request_id' => $payment->hitpay_request_id, 'amount' => '10000.00', 'raw' => []];
        $this->assertSame('failed', $service->handleWebhook($event + ['status' => 'failed']));
        $this->assertSame('failed', $this->props()['payments']['data'][0]['status']);
        $this->assertSame('completed', $service->handleWebhook($event + ['status' => 'completed']));
        $this->assertSame('already_processed', $service->handleWebhook($event + ['status' => 'completed']));
        $tables = ['campaigns', 'suppliers', 'payments', 'orders', 'website_answers', 'uploads', 'email_logs', 'status_history', 'settings', 'admin_audit_logs'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $mailCount = Mail::sent(\App\Mail\PaymentReceiptMail::class)->count();
        $httpCount = Http::recorded()->count();
        $props = $this->props();
        $this->props();
        $this->assertSame($before, $snapshot());
        $this->assertSame($mailCount, Mail::sent(\App\Mail\PaymentReceiptMail::class)->count());
        $this->assertSame($httpCount, Http::recorded()->count());
        $this->assertSame(1, $props['payments']['total']);
        $this->assertSame($payment->id, $props['payments']['data'][0]['id']);
        $this->assertSame('completed', $props['payments']['data'][0]['status']);
        $this->assertNotNull($props['payments']['data'][0]['paid_at']);
        $stale = $this->payment($this->supplier(), ['expires_at' => now()->subMinute()]);
        $service->expireStaleLinks();
        $this->assertSame([$stale->id], $this->ids(['status' => 'expired']));
    }
}
