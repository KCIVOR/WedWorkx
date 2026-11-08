<?php

namespace Tests\Feature;

use App\Models\WebsiteQuestion;
use App\Support\BusinessTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteBriefDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function upgrade(): void
    {
        (require database_path('data-migrations/2026_11_12_000001_update_website_brief_questions.php'))->up();
    }

    public function test_upgrade_adds_five_questions_and_orders_every_business_type_without_replacing_existing_keys(): void
    {
        $before = WebsiteQuestion::get()->keyBy('id')->map->getAttributes()->all();
        $this->upgrade();
        $this->assertSame(35, WebsiteQuestion::count());
        foreach ($before as $id => $row) {
            $after = WebsiteQuestion::findOrFail($id);
            foreach (['key', 'type', 'options', 'applies_to', 'required', 'locked'] as $field) {
                $this->assertSame($row[$field], $after->getRawOriginal($field));
            }
        }
        foreach (BusinessTypes::keys() as $type) {
            $questions = WebsiteQuestion::forType($type);
            $this->assertCount(13, $questions);
            $this->assertSame(['description', 'q_7be6711e', 'q_c074a772', 'q_77963408', 'services', 'extra1', 'extra2', 'areas', 'style', 'q_19a5f097', 'media', 'q_702f0901', 'additional'], $questions->pluck('key')->all());
        }
        $this->assertTrue(WebsiteQuestion::where('key', 'q_7be6711e')->first()->required);
        $this->assertFalse(WebsiteQuestion::where('key', 'q_19a5f097')->first()->required);
        $snapshot = WebsiteQuestion::orderBy('id')->get()->toJson();
        $this->travel(1)->minutes();
        $this->upgrade();
        $this->assertSame($snapshot, WebsiteQuestion::orderBy('id')->get()->toJson());
    }

    public function test_manually_added_question_is_reused_with_its_original_key(): void
    {
        $q = WebsiteQuestion::create(['key' => 'q_manual', 'type' => 'textarea', 'label' => 'Target Audience', 'text' => 'Who?', 'required' => true, 'sort_order' => 999]);
        $this->upgrade();
        $this->assertSame('q_manual', $q->fresh()->key);
        $this->assertSame(35, WebsiteQuestion::count());
        $this->assertSame(0, WebsiteQuestion::where('key', 'q_7be6711e')->count());
    }

    public function test_conflicting_question_stops_the_whole_upgrade_without_partial_changes(): void
    {
        WebsiteQuestion::create(['key' => 'q_conflict', 'type' => 'number', 'label' => 'Target Audience', 'text' => 'Who?', 'required' => false]);
        $before = WebsiteQuestion::orderBy('id')->get()->toJson();
        try {
            $this->upgrade();
            $this->fail('Expected an incompatible question to stop the upgrade.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Target Audience', $e->getMessage());
        }
        $this->assertSame($before, WebsiteQuestion::orderBy('id')->get()->toJson());
    }

    public function test_explicit_artisan_path_records_the_upgrade_and_does_not_run_it_twice(): void
    {
        $this->artisan('migrate', ['--path' => 'database/data-migrations', '--force' => true])->assertExitCode(0);
        $this->assertDatabaseHas('migrations', ['migration' => '2026_11_12_000001_update_website_brief_questions']);
        $before = WebsiteQuestion::orderBy('id')->get()->toJson();
        $this->artisan('migrate', ['--path' => 'database/data-migrations', '--force' => true])->assertExitCode(0);
        $this->assertSame($before, WebsiteQuestion::orderBy('id')->get()->toJson());
    }

    public function test_submitted_answers_and_snapshots_are_preserved_and_missing_snapshots_block_changes(): void
    {
        $this->seed(\Database\Seeders\CampaignSeeder::class);
        \Illuminate\Support\Facades\Mail::fake();
        $supplier = app(\App\Services\SlotService::class)->register([
            'name' => 'Migration Preview', 'email' => 'migration@example.test', 'mobile' => '+639171234567',
            'business_name' => 'Migration Preview', 'business_type' => 'cake', 'location' => 'Tagaytay',
        ])['supplier'];
        $answer = \App\Models\WebsiteAnswer::create([
            'supplier_id' => $supplier->id, 'answers' => ['description' => 'Existing description', 'additional' => 'Existing notes'],
            'question_snapshot' => [['key' => 'description', 'label' => 'Business Description']],
            'submitted_at' => now(), 'locked_at' => now(),
        ]);
        $before = $answer->fresh()->getAttributes();
        $supplierBefore = $supplier->fresh()->getAttributes();
        $this->upgrade();
        $this->assertSame($before, $answer->fresh()->getAttributes());
        $this->assertSame($supplierBefore, $supplier->fresh()->getAttributes());
        $questions = WebsiteQuestion::orderBy('id')->get()->toJson();
        foreach ([null, '[]', 'null'] as $missingSnapshot) {
            DB::table('website_answers')->where('id', $answer->id)->update(['question_snapshot' => $missingSnapshot]);
            try {
                $this->upgrade();
                $this->fail('Missing snapshots must block this upgrade.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('missing snapshots', $e->getMessage());
            }
            $this->assertSame($questions, WebsiteQuestion::orderBy('id')->get()->toJson());
        }
    }
}
