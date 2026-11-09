<?php

namespace Database\Seeders;

use App\Models\Campaign;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        // Safe to re-run: never resets slots_taken on an existing campaign.
        Campaign::firstOrCreate(
            ['name' => 'Kasalang Tagaytay 10th Anniversary'],
            [
                'slot_limit' => 10,
                'slots_taken' => 0,
                'invite_expiry_hours' => 24,
                'grace_minutes' => 30,
                'price' => 10000,
            ],
        );
    }
}
