<?php

namespace Tests\Feature;

use App\Models\AgentChat;
use App\Models\Supplier;
use App\Models\Upload;
use App\Models\WebsiteAnswer;
use App\Models\WebsiteQuestion;
use App\Services\AgentChatService;
use App\Services\SlotService;
use App\Support\BusinessTypes;
use App\Support\WebsiteQuestions;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteIntakeV2Test extends TestCase
{
    use RefreshDatabase;
    private int $sequence = 0;

    public function test_invalid_agent_phone_never_replaces_a_valid_collected_contact(): void
    {
        $this->upgrade();
        $s = $this->supplier();
        $chat = AgentChat::create(['public_id' => Str::random(40), 'bot' => 'website', 'supplier_id' => $s->id, 'status' => 'open',
            'answers' => ['public_contact_mode' => 'different', 'public_contact_permission' => 'approved', 'public_phone' => '+639171234567']]);
        $result = app(AgentChatService::class)->checkAnswers($chat, ['public_phone' => '+6393154', 'colors' => 'gold']);
        $this->assertArrayHasKey('public_phone', $result['errors']);
        $this->assertSame('+639171234567', $chat->fresh()->answers['public_phone']);
        $this->assertSame('gold', $chat->fresh()->answers['colors']);
    }

    public function test_guided_services_keep_supplied_package_inclusions(): void
    {
        $this->upgrade();
        $normalized = WebsiteQuestions::normalize(['services' => [['name' => 'Essential', 'details' => 'Eight hours of photography and an album.']]], WebsiteQuestions::for('photographer', ''), true);
        $this->assertSame('Eight hours of photography and an album.', $normalized['services'][0]['details']);
    }

    public function test_public_phone_rejects_masked_and_incomplete_numbers_and_normalizes_real_input(): void
    {
        $this->upgrade();
        $s = $this->supplier();
        $base = array_merge($this->answers($s), ['public_contact_mode' => 'different', 'public_contact_permission' => 'approved',
            'public_email' => null, 'public_socials' => null, 'public_address_mode' => 'none']);
        foreach (['+639**3154', '+6393154', '1234', 'not a number'] as $phone) {
            $input = array_merge($base, ['public_phone' => $phone]);
            $this->assertTrue(Validator::make($input, WebsiteQuestions::rules($s->business_type, $input, $s))->fails(), $phone);
        }
        $normalized = WebsiteQuestions::normalize(array_merge($base, ['public_phone' => '0917 123 4567']), WebsiteQuestions::for($s->business_type, ''), false);
        $this->assertSame('+639171234567', $normalized['public_phone']);
        $this->assertFalse(Validator::make($normalized, WebsiteQuestions::rules($s->business_type, $normalized, $s))->fails());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
    }

    private function upgrade(): void
    {
        (require database_path('data-migrations/2026_11_14_000001_apply_approved_website_intake_v2.php'))->up();
    }

    private function supplier(string $type = 'coordinator'): Supplier
    {
        $n = ++$this->sequence;
        return app(SlotService::class)->register(['name' => 'Intake Client '.$n, 'email' => "intake$n@example.test", 'mobile' => '+6391800'.str_pad((string)$n, 5, '0', STR_PAD_LEFT),
            'business_name' => 'Intake Studio '.$n, 'business_type' => $type, 'location' => 'Tagaytay'])['supplier'];
    }

    private function answers(Supplier $supplier): array
    {
        $answers = [
            'description' => 'We provide wedding services.', 'business_experience' => null,
            'q_7be6711e' => 'Couples planning a wedding', 'q_c074a772' => 'inquiry', 'cta_destination' => null,
            'differentiator_status' => 'help', 'differentiator_help' => null,
            'services_status' => 'later', 'services_followup' => 'Wedding planning', 'pricing_display' => 'inquire',
            'service_area_status' => 'later', 'booking' => 'help', 'public_contact_mode' => 'later',
            'website_voice' => 'suggest', 'style' => 'surprise', 'reference_use' => null,
            'asset_plan' => 'later', 'colors' => null, 'brand_fonts' => null, 'brand_guidelines' => null,
            'social_proof' => 'none', 'scope_mode' => 'suggest', 'existing_content_mode' => 'none',
            'policies' => 'none', 'privacy_status' => 'none', 'domain_platform' => null,
            'q_702f0901' => null, 'additional' => null,
        ];
        foreach (WebsiteQuestions::for($supplier->business_type, $supplier->business_name) as $q) {
            if (empty($q['intake'])) $answers[$q['fields'][0]] = ($q['optional'] ?? false) ? null : ($q['options'][0]['value'] ?? 'Actual service details');
        }
        return WebsiteQuestions::normalize($answers, WebsiteQuestions::for($supplier->business_type, $supplier->business_name));
    }

    public function test_upgrade_creates_twenty_steps_for_all_types_and_preserves_existing_rows_and_snapshots(): void
    {
        $s = $this->supplier();
        $snapshot = WebsiteQuestions::snapshot($s);
        $answer = WebsiteAnswer::create(['supplier_id' => $s->id, 'answers' => ['description' => 'Original description'], 'question_snapshot' => $snapshot, 'submitted_at' => now()]);
        $old = WebsiteQuestion::get()->keyBy('id')->map->getAttributes();
        $this->upgrade();
        $this->assertSame(42, WebsiteQuestion::count());
        foreach (BusinessTypes::keys() as $type) $this->assertCount(20, WebsiteQuestions::for($type, 'Business'));
        foreach ($old as $id => $row) {
            $current = WebsiteQuestion::findOrFail($id);
            foreach (['key','type','options','applies_to'] as $field) $this->assertSame($row[$field], $current->getRawOriginal($field));
        }
        $this->assertSame($snapshot, $answer->fresh()->question_snapshot);
        $this->assertSame(['description' => 'Original description'], $answer->fresh()->answers);
        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p->has('chat.questions', 8));
        $definitions = WebsiteQuestion::orderBy('id')->get()->toJson();
        $this->travel(1)->minutes();
        $this->upgrade();
        $this->assertSame($definitions, WebsiteQuestion::orderBy('id')->get()->toJson());
    }

    public function test_truthful_no_materials_and_later_decisions_can_complete_without_fake_reviews_or_contacts(): void
    {
        $this->upgrade();
        $s = $this->supplier();
        $answers = $this->answers($s);
        $v = Validator::make($answers, WebsiteQuestions::rules($s->business_type, $answers, $s));
        $this->assertFalse($v->fails(), json_encode($v->errors()->toArray()));
        $this->postJson('/invite/'.$s->invite_token.'/confirm', $answers)->assertRedirect();
        $saved = $s->fresh()->websiteAnswer;
        $this->assertCount(20, $saved->question_snapshot);
        $this->assertSame('none', $saved->answers['social_proof']);
        $this->assertNull($saved->answers['public_email'] ?? null);
    }

    public function test_conditional_requirements_and_owned_photo_ids_are_enforced(): void
    {
        $this->upgrade();
        $s = $this->supplier();
        $other = $this->supplier();
        $photo = Upload::create(['supplier_id' => $other->id, 'kind' => 'photo', 'path' => 'uploads/photo.jpg', 'original_name' => 'photo.jpg', 'mime' => 'image/jpeg', 'size_kb' => 1]);
        $answers = array_merge($this->answers($s), ['pricing_display' => 'exact', 'asset_plan' => 'now', 'photo_ids' => [$photo->id], 'asset_permission' => 'approved']);
        $v = Validator::make($answers, WebsiteQuestions::rules($s->business_type, $answers, $s));
        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('approved_prices', $v->errors()->toArray());
        $this->assertArrayHasKey('photo_ids.0', $v->errors()->toArray());
        $photo->update(['supplier_id' => $s->id]);
        $answers['approved_prices'] = 'Coordination: PHP 25000';
        $answers['pricing_currency'] = 'PHP';
        $this->assertFalse(Validator::make($answers, WebsiteQuestions::rules($s->business_type, $answers, $s))->fails());
    }

    public function test_hidden_answers_are_removed_and_unapproved_contact_values_are_not_saved_for_publication(): void
    {
        $this->upgrade();
        $s = $this->supplier();
        $answers = array_merge($this->answers($s), ['services' => [['name' => 'Old package', 'price' => '9000']],
            'public_contact_mode' => 'different', 'public_email' => 'private@example.test', 'public_phone' => null, 'public_socials' => null,
            'public_address_mode' => 'none', 'public_address' => 'Private home', 'public_contact_permission' => 'review']);
        $this->postJson('/invite/'.$s->invite_token.'/confirm', $answers)->assertRedirect();
        $saved = $s->fresh()->websiteAnswer->answers;
        $this->assertSame([], $saved['services']);
        $this->assertNull($saved['public_email']);
        $this->assertNull($saved['public_address']);
        $this->assertSame('review', $saved['public_contact_permission']);
        $this->postJson('/invite/'.$s->invite_token.'/confirm', $saved)->assertRedirect();
    }

    public function test_hermes_uses_conditional_next_fields_and_preserves_partial_answers(): void
    {
        $this->upgrade();
        $s = $this->supplier();
        $chat = AgentChat::create(['public_id' => Str::random(40), 'bot' => 'website', 'supplier_id' => $s->id, 'status' => 'open']);
        $service = app(AgentChatService::class);
        $result = $service->checkAnswers($chat, ['description' => 'Wedding coordination', 'business_experience' => null, 'pricing_display' => 'inquire']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('inquire', $chat->fresh()->answers['pricing_display']);
        $chat->update(['answers' => $this->answers($s)]);
        $this->assertSame([], $service->unansweredQuestions($chat));
        $this->assertTrue($service->ready($chat)['ok']);
    }

    public function test_all_supplier_types_accept_their_own_twenty_step_brief(): void
    {
        $this->upgrade();
        foreach (BusinessTypes::keys() as $type) {
            $s = $this->supplier($type);
            $answers = $this->answers($s);
            $v = Validator::make($answers, WebsiteQuestions::rules($type, $answers, $s));
            $this->assertFalse($v->fails(), $type.': '.json_encode($v->errors()->toArray()));
        }
    }

    public function test_exceeding_the_cap_rolls_back_without_replacing_custom_questions(): void
    {
        WebsiteQuestion::create(['key' => 'custom_extra', 'type' => 'textarea', 'label' => 'Existing custom', 'text' => 'Existing custom question', 'required' => false]);
        $before = WebsiteQuestion::orderBy('id')->get()->toJson();
        try {
            $this->upgrade();
            $this->fail('A twenty-one-step result must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cap', $e->getMessage());
        }
        $this->assertSame($before, WebsiteQuestion::orderBy('id')->get()->toJson());
    }

    public function test_logo_only_upload_does_not_require_a_universal_photo_minimum(): void
    {
        $this->upgrade();
        $s = $this->supplier();
        $answers = array_merge($this->answers($s), ['asset_plan' => 'now', 'photo_ids' => [], 'asset_permission' => 'approved']);
        $this->assertTrue(Validator::make($answers, WebsiteQuestions::rules($s->business_type, $answers, $s))->fails());
        Upload::create(['supplier_id' => $s->id, 'kind' => 'logo', 'path' => 'uploads/logo.png', 'original_name' => 'logo.png', 'mime' => 'image/png', 'size_kb' => 1]);
        $v = Validator::make($answers, WebsiteQuestions::rules($s->business_type, $answers, $s));
        $this->assertFalse($v->fails(), json_encode($v->errors()->toArray()));
        $this->postJson('/invite/'.$s->invite_token.'/confirm', $answers)->assertRedirect();
    }
}
