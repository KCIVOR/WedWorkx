<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Models\Campaign;
use App\Models\Supplier;
use Database\Seeders\CampaignSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(Campaign $campaign, array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'campaign_id' => $campaign->id,
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'mobile' => '+639171234567',
            'business_name' => 'Maria Photo',
            'business_type' => 'Photographer',
            'city' => 'Tagaytay',
            'province' => 'Cavite',
            'status' => SupplierStatus::Invited,
        ], $overrides));
    }

    public function test_seeder_creates_the_campaign(): void
    {
        $this->seed(CampaignSeeder::class);
        $this->seed(CampaignSeeder::class); // safe to re-run

        $this->assertSame(1, Campaign::count());
        $c = Campaign::first();
        $this->assertSame('Kasalang Tagaytay 10-10-10', $c->name);
        $this->assertSame([10, 0, 24, 30, '10000.00'], [$c->slot_limit, $c->slots_taken, $c->invite_expiry_hours, $c->grace_minutes, $c->price]);
    }

    public function test_status_enum_has_exactly_the_eight_statuses(): void
    {
        $this->assertSame(
            ['waitlisted', 'invited', 'expired', 'details_submitted', 'payment_pending', 'paid', 'building', 'delivered'],
            array_map(fn ($c) => $c->value, SupplierStatus::cases()),
        );
    }

    public function test_duplicate_email_in_same_campaign_is_rejected(): void
    {
        $this->seed(CampaignSeeder::class);
        $c = Campaign::first();
        $this->supplier($c);

        $this->expectException(QueryException::class);
        $this->supplier($c, ['mobile' => '+639170000000']);
    }

    public function test_duplicate_mobile_in_same_campaign_is_rejected(): void
    {
        $this->seed(CampaignSeeder::class);
        $c = Campaign::first();
        $this->supplier($c);

        $this->expectException(QueryException::class);
        $this->supplier($c, ['email' => 'other@example.com']);
    }

    public function test_same_email_is_allowed_in_a_different_campaign(): void
    {
        $this->seed(CampaignSeeder::class);
        $a = Campaign::first();
        $b = Campaign::create(['name' => 'Other', 'slot_limit' => 10, 'invite_expiry_hours' => 24, 'grace_minutes' => 30, 'price' => 10000]);

        $this->supplier($a);
        $this->supplier($b);

        $this->assertSame(2, Supplier::count());
    }

    public function test_email_log_is_unique_per_supplier_and_type_except_failed(): void
    {
        $this->seed(CampaignSeeder::class);
        $s = $this->supplier(Campaign::first());
        $log = fn (string $type) => DB::table('email_logs')->insert(['supplier_id' => $s->id, 'type' => $type, 'status' => 'sent']);

        $log('failed');
        $log('failed'); // more than one failed-payment email is allowed
        $log('invite');
        $this->assertSame(3, DB::table('email_logs')->count());

        $this->expectException(QueryException::class);
        $log('invite');
    }
}
