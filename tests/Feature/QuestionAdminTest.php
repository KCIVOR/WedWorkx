<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WebsiteAnswer;
use App\Models\WebsiteQuestion;
use App\Services\SlotService;
use App\Support\BusinessTypes;
use App\Support\WebsiteQuestions;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Admin > Questions: the website questions are rows an admin can add, edit, delete, restore and reorder. */
class QuestionAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
    }

    private function signedIn(): static
    {
        $this->admin ??= User::factory()->create(['email' => 'boss@example.com', 'password' => 'a-long-test-password-1']);

        return $this->actingAs($this->admin);
    }

    private function supplier(string $type = 'photographer'): Supplier
    {
        $this->n++;
        $s = app(SlotService::class)->register([
            'name' => "Maria Santos $this->n", 'email' => "s$this->n@example.com", 'mobile' => '+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Rosal Studio $this->n", 'business_type' => $type, 'location' => 'Tagaytay City, Cavite',
        ])['supplier'];

        return $s->fresh();
    }

    private function answers(array $o = []): array
    {
        return $o + [
            'description' => 'Candid, warm wedding photography for garden weddings.',
            'services' => [['name' => 'Full day', 'price' => '₱25,000']], 'service_areas' => ['Tagaytay'], 'booking' => 'Messenger',
            'colors' => 'Pink', 'style' => 'romantic', 'additional' => '',
            'extra1' => 'Full-day coverage', 'extra2' => 'Both photos and videos',
        ];
    }

    private function payload(array $o = []): array
    {
        return $o + ['label' => 'Years in business', 'text' => 'How long have you been in business?', 'type' => 'text', 'required' => true, 'scope' => 'all'];
    }

    private function make(array $o = []): WebsiteQuestion
    {
        $this->signedIn()->post('/admin/questions', $this->payload($o))->assertSessionHasNoErrors();

        return WebsiteQuestion::orderByDesc('id')->firstOrFail();
    }

    private function q(string $key, ?string $type = null): WebsiteQuestion
    {
        return WebsiteQuestion::all()->first(fn ($q) => $q->key === $key && ($type === null || $q->appliesTo($type)));
    }

    /** An edit payload that repeats the question's current values, with overrides. */
    private function editPayload(WebsiteQuestion $q, array $o = []): array
    {
        return $o + [
            'label' => $q->label, 'text' => $q->text, 'type' => $q->type, 'placeholder' => $q->placeholder, 'required' => $q->required,
            'scope' => $q->applies_to === null ? 'all' : 'types', 'types' => $q->applies_to ?? [],
            'options' => $q->options ?? [],
        ];
    }

    // ---------- the defaults ----------

    public function test_default_questions_and_rules_match_the_original_for_every_type(): void
    {
        $golden = json_decode(file_get_contents(base_path('tests/Support/website_questions_golden.json')), true);
        $clean = fn (array $qs) => array_map(fn ($q) => array_filter($q, fn ($v) => $v !== null), $qs);

        foreach (BusinessTypes::keys() as $type) {
            $this->assertEquals($clean($golden[$type]['questions']), $clean(WebsiteQuestions::for($type, 'Biz')), $type);
            $rules = array_map(fn ($r) => array_map('strval', $r), WebsiteQuestions::rules($type));
            $this->assertEquals($golden[$type]['rules'], $rules, $type);
        }
        $this->assertSame(30, WebsiteQuestion::count());
    }

    // ---------- add ----------

    public function test_a_new_question_appears_last_in_every_chat_and_is_validated(): void
    {
        $this->signedIn()->post('/admin/questions', $this->payload())->assertRedirect();

        $key = WebsiteQuestion::where('label', 'Years in business')->value('key');
        $this->assertMatchesRegularExpression('/^q_[0-9a-f]{8}$/', $key);

        $s = $this->supplier();
        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p
            ->has('chat.questions', 9)->where('chat.questions.8.key', $key));

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$key => '']))->assertSessionHasErrors($key);
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$key => '5 years']))->assertSessionHasNoErrors();
        $this->assertSame('5 years', WebsiteAnswer::sole()->answers[$key]);
    }

    public function test_an_optional_text_question_may_be_left_empty_and_is_saved_as_null(): void
    {
        $q = $this->make(['required' => false]);
        $s = $this->supplier();

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$q->key => '  ']))->assertSessionHasNoErrors();

        $this->assertNull(WebsiteAnswer::sole()->answers[$q->key]);
    }

    public function test_choice_and_number_questions_check_their_answers(): void
    {
        $c = $this->make(['label' => 'Travel', 'text' => 'Do you travel?', 'type' => 'choice', 'options' => ['Yes', 'No']]);
        $n = $this->make(['label' => 'Staff', 'text' => 'How many staff?', 'type' => 'number', 'required' => false]);
        $s = $this->supplier();
        $url = "/invite/{$s->invite_token}/confirm";

        $this->post($url, $this->answers([$c->key => 'Maybe', $n->key => '4']))->assertSessionHasErrors($c->key);
        $this->post($url, $this->answers([$c->key => 'Yes', $n->key => 'many']))->assertSessionHasErrors($n->key);
        $this->post($url, $this->answers([$c->key => 'Yes', $n->key => '4']))->assertSessionHasNoErrors();
        $this->assertSame('Yes', WebsiteAnswer::sole()->answers[$c->key]);
    }

    public function test_bad_input_is_rejected_and_nothing_is_created(): void
    {
        $before = WebsiteQuestion::count();
        $bad = [
            'options' => $this->payload(['type' => 'choice', 'options' => ['Yes', 'yes']]),
            'options (only one)' => $this->payload(['type' => 'choice', 'options' => ['Only']]),
            'text' => $this->payload(['text' => 'Hello {name}']),
            'type' => $this->payload(['type' => 'services']),
            'label' => $this->payload(['label' => '']),
            'types' => $this->payload(['scope' => 'types', 'types' => []]),
        ];

        foreach ($bad as $field => $payload) {
            $this->from('/admin/questions')->signedIn()->post('/admin/questions', $payload)->assertSessionHasErrors(explode(' ', $field)[0]);
        }
        $this->signedIn()->post('/admin/questions', $this->payload(['text' => 'Hi {business_name}!']))->assertSessionHasNoErrors();

        $this->assertSame($before + 1, WebsiteQuestion::count());
    }

    public function test_the_cap_per_business_type_is_enforced(): void
    {
        config(['admin.max_questions' => 9]); // a photographer has 8 now

        $this->make();
        $this->signedIn()->post('/admin/questions', $this->payload(['label' => 'One too many']))->assertSessionHasErrors('question');
        $this->assertSame(31, WebsiteQuestion::count());

        // Limited to venues, a 10th would still break the cap for venues only.
        $this->signedIn()->post('/admin/questions', $this->payload(['label' => 'Venue only', 'scope' => 'types', 'types' => ['venue']]))->assertSessionHasErrors('question');
    }

    public function test_a_question_for_some_types_is_only_asked_to_those_types(): void
    {
        $q = $this->make(['scope' => 'types', 'types' => ['venue']]);
        $venue = $this->supplier('venue');
        $cake = $this->supplier('cake');

        $this->get('/invite/'.$venue->invite_token)->assertInertia(fn ($p) => $p->has('chat.questions', 9)->where('chat.questions.8.key', $q->key));
        $this->get('/invite/'.$cake->invite_token)->assertInertia(fn ($p) => $p->has('chat.questions', 8));

        // A cake supplier posting that key anyway has it dropped.
        $this->post("/invite/{$cake->invite_token}/confirm", $this->answers(['extra1' => 'x', 'extra2' => 'Yes, free tasting', $q->key => 'sneaky']));
        $this->assertArrayNotHasKey($q->key, WebsiteAnswer::where('supplier_id', $cake->id)->sole()->answers);
    }

    // ---------- edit ----------

    public function test_wording_can_change_and_a_confirmed_supplier_keeps_what_they_saw(): void
    {
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers())->assertSessionHasNoErrors();

        $d = $this->q('description');
        $this->signedIn()->put("/admin/questions/{$d->id}", $this->editPayload($d, ['label' => 'About your business']))->assertSessionHasNoErrors();
        $this->assertSame('About your business', $d->fresh()->label);

        // New suppliers see the new wording; the confirmed one keeps the saved wording.
        $this->get('/invite/'.$this->supplier()->invite_token)->assertInertia(fn ($p) => $p->where('chat.questions.0.label', 'About your business'));
        $this->signedIn()->get("/admin/suppliers/{$s->id}")->assertInertia(fn ($p) => $p->where('answers.0.label', 'Business Description'));
        $this->assertSame('Business Description', WebsiteAnswer::sole()->question_snapshot[0]['label']);
    }

    public function test_confirming_again_before_paying_saves_the_current_questions(): void
    {
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers());
        $d = $this->q('description');
        $this->signedIn()->put("/admin/questions/{$d->id}", $this->editPayload($d, ['label' => 'About your business']));

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers())->assertSessionHasNoErrors();

        $this->assertSame('About your business', WebsiteAnswer::sole()->question_snapshot[0]['label']);
    }

    public function test_answers_saved_before_this_feature_still_show_with_todays_labels(): void
    {
        $s = $this->supplier();
        WebsiteAnswer::create(['supplier_id' => $s->id, 'answers' => ['description' => 'Old row', 'style' => 'modern'], 'submitted_at' => now()]);

        $this->signedIn()->get("/admin/suppliers/{$s->id}")->assertOk()->assertInertia(fn ($p) => $p
            ->where('answers.0.label', 'Business Description')->where('answers.0.value', 'Old row')->where('answers.4.value', 'Modern & Simple'));
    }

    public function test_the_type_of_a_question_that_was_answered_cannot_change(): void
    {
        $q = $this->make();
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$q->key => 'ten']));

        $this->signedIn()->put("/admin/questions/{$q->id}", $this->editPayload($q, ['type' => 'number']))->assertSessionHasErrors('type');
        $this->signedIn()->put("/admin/questions/{$q->id}", $this->editPayload($q, ['text' => 'Reworded?']))->assertSessionHasNoErrors();

        $this->assertSame('text', $q->fresh()->type);
        $this->assertSame('Reworded?', $q->fresh()->text);
    }

    public function test_an_option_a_supplier_chose_cannot_be_removed_but_others_can(): void
    {
        $q = $this->make(['type' => 'choice', 'options' => ['Yes', 'No', 'Sometimes']]);
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$q->key => 'Yes']));

        $this->signedIn()->put("/admin/questions/{$q->id}", $this->editPayload($q->fresh(), ['options' => [['value' => 'No', 'label' => 'No'], ['value' => 'Sometimes', 'label' => 'Sometimes']]]))
            ->assertSessionHasErrors('options');
        $this->signedIn()->put("/admin/questions/{$q->id}", $this->editPayload($q->fresh(), ['options' => [['value' => 'Yes', 'label' => 'Yes, always'], ['value' => 'No', 'label' => 'No']]]))
            ->assertSessionHasNoErrors();

        $options = $q->fresh()->options;
        $this->assertSame([['value' => 'Yes', 'label' => 'Yes, always'], ['value' => 'No', 'label' => 'No']], $options);
    }

    public function test_locked_questions_keep_their_structure_but_their_wording_can_change(): void
    {
        $style = $this->q('style');
        $this->assertTrue($style->locked);

        $this->signedIn()->delete("/admin/questions/{$style->id}")->assertSessionHasErrors('question');
        $this->signedIn()->put("/admin/questions/{$style->id}", $this->editPayload($style, ['required' => false]))->assertSessionHasErrors('question');
        $this->signedIn()->put("/admin/questions/{$style->id}", $this->editPayload($style, ['scope' => 'types', 'types' => ['venue']]))->assertSessionHasErrors('question');
        $this->signedIn()->put("/admin/questions/{$style->id}", $this->editPayload($style, ['options' => [['value' => 'elegant', 'label' => 'Elegant'], ['value' => 'modern', 'label' => 'Modern']]]))
            ->assertSessionHasErrors('question');
        foreach (['description', 'services', 'areas', 'media'] as $key) {
            $this->signedIn()->delete('/admin/questions/'.$this->q($key)->id)->assertSessionHasErrors('question');
        }
        $this->assertTrue(WebsiteQuestion::where('key', 'style')->exists());

        $labels = collect($style->options)->map(fn ($o) => $o['value'] === 'elegant' ? ['value' => 'elegant', 'label' => 'Elegant & Grand'] : $o)->all();
        $this->signedIn()->put("/admin/questions/{$style->id}", $this->editPayload($style, ['label' => 'Look and feel', 'options' => $labels]))->assertSessionHasNoErrors();
        $fresh = $style->fresh();
        $this->assertSame('Look and feel', $fresh->label);
        $this->assertSame('Elegant & Grand', $fresh->options[0]['label']);
        $this->assertSame('elegant', $fresh->options[0]['value']);

        $services = $this->q('services');
        $this->signedIn()->put("/admin/questions/{$services->id}", $this->editPayload($services, ['text' => 'List your packages.']))->assertSessionHasNoErrors();
        $this->assertSame('List your packages.', $services->fresh()->text);
    }

    public function test_a_question_cannot_share_its_answer_slot_with_another_one_for_the_same_type(): void
    {
        $venueExtra1 = $this->q('extra1', 'venue');
        $this->signedIn()->put("/admin/questions/{$venueExtra1->id}", $this->editPayload($venueExtra1, ['scope' => 'types', 'types' => ['venue', 'cake']]))
            ->assertSessionHasErrors('applies_to');

        $this->assertSame(['venue'], $venueExtra1->fresh()->applies_to);
    }

    // ---------- delete, restore, reorder ----------

    public function test_deleting_hides_a_question_but_keeps_what_suppliers_answered_and_it_can_be_restored(): void
    {
        $q = $this->make();
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers([$q->key => 'ten']));

        $this->signedIn()->delete("/admin/questions/{$q->id}")->assertSessionHas('admin_flash', fn ($f) => str_contains($f['message'], '1 supplier'));

        $this->assertSoftDeleted('website_questions', ['id' => $q->id]);
        $this->assertSame('ten', WebsiteAnswer::sole()->answers[$q->key]);
        $this->assertArrayNotHasKey($q->key, WebsiteQuestions::rules('photographer'));
        $this->signedIn()->get("/admin/suppliers/{$s->id}")->assertInertia(fn ($p) => $p->where('answers.8.label', 'Years in business')->where('answers.8.value', 'ten'));
        $this->get('/invite/'.$this->supplier()->invite_token)->assertInertia(fn ($p) => $p->has('chat.questions', 8));

        $this->signedIn()->post("/admin/questions/{$q->id}/restore")->assertSessionHasNoErrors();
        $this->assertNull($q->fresh()->deleted_at);
        $this->assertSame($q->key, collect(WebsiteQuestions::for('cake', 'B'))->last()['key']); // back at the end
    }

    public function test_restoring_is_refused_when_it_would_break_the_cap(): void
    {
        $q = $this->make();
        $this->signedIn()->delete("/admin/questions/{$q->id}");
        config(['admin.max_questions' => 8]);

        $this->signedIn()->post("/admin/questions/{$q->id}/restore")->assertSessionHasErrors('question');
        $this->assertNotNull($q->fresh()->deleted_at);
    }

    public function test_reordering_changes_the_chat_order_and_rejects_a_bad_list(): void
    {
        $s = $this->supplier();
        $ids = WebsiteQuestion::orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $swapped = $ids;
        [$swapped[0], $swapped[1]] = [$swapped[1], $swapped[0]]; // services before description

        $this->signedIn()->post('/admin/questions/reorder', ['order' => $swapped])->assertSessionHasNoErrors();
        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p->where('chat.questions.0.key', 'services')->where('chat.questions.1.key', 'description'));
        $this->assertSame([10, 20, 30], WebsiteQuestion::orderBy('sort_order')->limit(3)->pluck('sort_order')->all());

        foreach ([array_slice($ids, 1), array_merge($ids, [999999]), array_merge(array_slice($ids, 1), [$ids[1]])] as $bad) {
            $this->signedIn()->post('/admin/questions/reorder', ['order' => $bad])->assertSessionHasErrors('order');
        }
        $this->assertSame($swapped, WebsiteQuestion::orderBy('sort_order')->orderBy('id')->pluck('id')->all());
    }

    // ---------- access and records ----------

    public function test_guests_are_sent_to_the_login_from_every_questions_route(): void
    {
        $this->get('/admin/questions')->assertRedirect('/admin/login');
        foreach (['post' => ['/admin/questions', '/admin/questions/reorder', '/admin/questions/1/restore'], 'put' => ['/admin/questions/1'], 'delete' => ['/admin/questions/1']] as $verb => $paths) {
            foreach ($paths as $path) {
                $this->{$verb}($path)->assertRedirect('/admin/login');
            }
        }
        $this->assertSame(30, WebsiteQuestion::count());
    }

    public function test_the_page_lists_the_questions_with_how_many_suppliers_answered(): void
    {
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers());

        $this->signedIn()->get('/admin/questions')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Admin/Questions')->has('questions', 30)->where('questions.0.key', 'description')
            ->where('questions.0.used_count', 1)->where('questions.0.locked', true)->has('types', 12)->where('max', 20));
    }

    public function test_changes_are_logged_with_names_only(): void
    {
        $q = $this->make(['text' => 'A secret-looking question text?']);
        $this->signedIn()->put("/admin/questions/{$q->id}", $this->editPayload($q, ['label' => 'Renamed']));
        $this->signedIn()->delete("/admin/questions/{$q->id}");

        $this->assertSame(['question_created', 'question_updated', 'question_deleted'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('secret-looking', AuditLog::all()->toJson());
        $this->assertSame(['key' => $q->key, 'fields' => ['label']], AuditLog::where('action', 'question_updated')->value('detail'));
    }
}
