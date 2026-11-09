<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Models\Campaign;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Services\SlotService;
use App\Support\PhoneNumber;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
    }

    private function payload(int $n = 1, array $overrides = []): array
    {
        return array_merge([
            'name' => "Supplier $n",
            'email' => "supplier$n@example.com",
            'mobile' => '0917'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'business_name' => "Business $n",
            'business_type' => 'photographer',
            'location' => 'Tagaytay City, Cavite',
            'social_link' => '',
        ], $overrides);
    }

    public function test_landing_page_renders_with_the_twelve_business_types(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Register')
            ->has('businessTypes', 12)
            ->where('businessTypes.0.value', 'venue'));
    }

    public function test_twelve_registrations_all_wait_for_review_with_no_slot_or_token(): void
    {
        for ($n = 1; $n <= 12; $n++) {
            $this->post('/register', $this->payload($n))->assertRedirect('/');
        }

        $this->assertSame(12, Supplier::where('status', SupplierStatus::PendingReview)->count());
        $this->assertSame(0, Supplier::whereNotNull('slot_number')->count());
        $this->assertSame(0, Supplier::whereNotNull('invite_token')->count());
        $this->assertSame(12, Campaign::first()->slots_taken);
        $this->assertSame(12, StatusHistory::where('actor', 'system')->whereNull('from_status')->count());
    }

    public function test_service_first_come_first_served_still_gives_ten_invited_and_two_waitlisted(): void
    {
        $service = app(SlotService::class);
        for ($n = 1; $n <= 12; $n++) {
            $service->register(['name' => "S$n", 'email' => "s$n@example.com", 'mobile' => '+6391'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
                'business_name' => "B$n", 'business_type' => 'venue', 'location' => 'Tagaytay']);
        }

        $this->assertSame(10, Supplier::where('status', SupplierStatus::Invited)->count());
        $this->assertSame(2, Supplier::where('status', SupplierStatus::Waitlisted)->count());
        $this->assertSame(range(1, 10), Supplier::whereNotNull('slot_number')->orderBy('slot_number')->pluck('slot_number')->all());
        $this->assertSame([1, 2], Supplier::whereNotNull('waitlist_number')->orderBy('waitlist_number')->pluck('waitlist_number')->all());
    }

    public function test_invited_supplier_gets_token_and_deadline_from_campaign_hours(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->post('/register', $this->payload(1));
        app(SlotService::class)->approve(Supplier::first());

        $s = Supplier::first();
        $this->assertSame(SupplierStatus::Invited, $s->status);
        $this->assertSame(1, $s->slot_number);
        $this->assertNull($s->waitlist_number);
        $this->assertSame(48, strlen($s->invite_token));
        $this->assertTrue($s->invite_expires_at->equalTo($s->invited_at->copy()->addHours(24)));
    }

    public function test_pending_supplier_has_no_token_or_deadline(): void
    {
        $this->post('/register', $this->payload(11));

        $s = Supplier::first();
        $this->assertSame(SupplierStatus::PendingReview, $s->status);
        $this->assertNull($s->waitlist_number);
        $this->assertNull($s->slot_number);
        $this->assertNull($s->invite_token);
        $this->assertNull($s->invite_expires_at);
    }

    public function test_result_does_not_reveal_invited_versus_waitlisted(): void
    {
        $this->post('/register', $this->payload(1))->assertSessionHas('registration', ['outcome' => 'registered', 'name' => 'Supplier 1']);

        Campaign::first()->update(['slots_taken' => 10]);
        $this->post('/register', $this->payload(2))->assertSessionHas('registration', ['outcome' => 'registered', 'name' => 'Supplier 2']);
    }

    public function test_duplicate_email_creates_no_supplier_and_uses_no_slot(): void
    {
        $this->post('/register', $this->payload(1));
        $this->post('/register', $this->payload(2, ['email' => ' SUPPLIER1@Example.com ']))
            ->assertSessionHas('registration.outcome', 'already_registered');

        $this->assertSame(1, Supplier::count());
        $this->assertSame(1, Campaign::first()->slots_taken);
    }

    public function test_duplicate_mobile_in_any_format_creates_no_supplier_and_uses_no_slot(): void
    {
        $this->post('/register', $this->payload(1, ['mobile' => '09171234567']));
        $this->post('/register', $this->payload(2, ['mobile' => '+63 917 123 4567']))
            ->assertSessionHas('registration.outcome', 'already_registered');

        $this->assertSame(1, Supplier::count());
        $this->assertSame(1, Campaign::first()->slots_taken);
        $this->assertSame('+639171234567', Supplier::first()->mobile);
    }

    public function test_service_reports_already_registered(): void
    {
        $service = app(SlotService::class);
        $data = ['name' => 'A', 'email' => 'a@example.com', 'mobile' => '+639171234567', 'business_name' => 'AA',
            'business_type' => 'venue', 'location' => 'Tagaytay'];
        $this->assertSame('invited', $service->register($data)['outcome']);
        $this->assertSame('already_registered', $service->register($data)['outcome']);
        $this->assertSame(1, Campaign::first()->slots_taken);
    }

    public function test_location_is_split_into_city_and_province(): void
    {
        $this->post('/register', $this->payload(1, ['location' => 'Tagaytay City, Cavite']));
        $this->post('/register', $this->payload(2, ['location' => 'Batangas']));

        $a = Supplier::where('email', 'supplier1@example.com')->first();
        $b = Supplier::where('email', 'supplier2@example.com')->first();
        $this->assertSame(['Tagaytay City', 'Cavite'], [$a->city, $a->province]);
        $this->assertSame(['Batangas', 'Batangas'], [$b->city, $b->province]);
    }

    /** @dataProvider invalidInputs */
    public function test_server_rejects_invalid_input(string $field, string $value): void
    {
        $this->from('/')->post('/register', $this->payload(1, [$field => $value]))->assertSessionHasErrors($field);

        $this->assertSame(0, Supplier::count());
        $this->assertSame(0, Campaign::first()->slots_taken);
    }

    public static function invalidInputs(): array
    {
        return [
            'email without domain' => ['email', 'maria@'],
            'email with spaces' => ['email', 'ma ria@example.com'],
            'mobile too short' => ['mobile', '0917123456'],
            'mobile too long' => ['mobile', '091712345678'],
            'mobile wrong prefix' => ['mobile', '08171234567'],
            'mobile letters' => ['mobile', '0917abcdefg'],
            'business type not in list' => ['business_type', 'dj'],
            'business type empty' => ['business_type', ''],
            'name empty' => ['name', ''],
            'social link not a url' => ['social_link', 'not a link'],
            'social link bad scheme' => ['social_link', 'javascript:alert(1)'],
        ];
    }

    public function test_optional_social_link_may_be_empty_or_a_bare_domain(): void
    {
        $this->post('/register', $this->payload(1, ['social_link' => '']))->assertSessionHasNoErrors();
        $this->post('/register', $this->payload(2, ['social_link' => 'facebook.com/mybiz']))->assertSessionHasNoErrors();

        $this->assertNull(Supplier::where('email', 'supplier1@example.com')->first()->social_link);
        $this->assertSame('https://facebook.com/mybiz', Supplier::where('email', 'supplier2@example.com')->first()->social_link);
    }

    public function test_phone_normalizer_accepts_both_forms_only(): void
    {
        $this->assertSame('+639171234567', PhoneNumber::normalize('09171234567'));
        $this->assertSame('+639171234567', PhoneNumber::normalize('+639171234567'));
        $this->assertSame('+639171234567', PhoneNumber::normalize('0917-123-4567'));
        $this->assertNull(PhoneNumber::normalize('639171234567'));
        $this->assertNull(PhoneNumber::normalize('9171234567'));
    }
}
