<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\RegistrationQuestion;
use App\Models\Supplier;
use App\Models\User;
use App\Support\RegistrationQuestions;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Admin > Questions > Registration chat (Chatbot 1). */
class RegistrationQuestionAdminTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private int $n = 0;

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

    private function payload(array $o = []): array
    {
        return $o + ['label' => 'How did you hear about us?', 'text' => 'How did you hear about us?', 'type' => 'text', 'required' => false];
    }

    private function make(array $o = []): RegistrationQuestion
    {
        $this->signedIn()->post('/admin/questions/registration', $this->payload($o))->assertSessionHasNoErrors();

        return RegistrationQuestion::orderByDesc('id')->firstOrFail();
    }

    private function q(string $key): RegistrationQuestion
    {
        return RegistrationQuestion::where('key', $key)->firstOrFail();
    }

    private function edit(RegistrationQuestion $q, array $o = []): array
    {
        return $o + ['label' => $q->label, 'text' => $q->text, 'type' => $q->type, 'placeholder' => $q->placeholder, 'required' => $q->required, 'options' => $q->options ?? []];
    }

    private function register(array $o = []): \Illuminate\Testing\TestResponse
    {
        $this->n++;

        return $this->post('/register', $o + [
            'name' => "Maria Santos $this->n", 'email' => "r$this->n@example.com", 'mobile' => '+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Rosal Studio $this->n", 'business_type' => 'photographer', 'location' => 'Tagaytay City, Cavite',
        ]);
    }

    // ---------- the defaults ----------

    public function test_the_seven_core_questions_are_the_original_ones_in_the_original_order(): void
    {
        $golden = json_decode(file_get_contents(base_path('tests/Support/registration_questions_golden.json')), true);
        $questions = RegistrationQuestions::for();

        $this->assertSame(array_keys($golden), array_column($questions, 'key'));
        $this->assertSame(array_values($golden), array_column($questions, 'text'));
        $this->assertSame([false, false, false, false, false, false, true], array_column($questions, 'optional'));
        $this->assertSame(['Your full name', 'you@example.com', '09xx xxx xxxx', 'Business name', null, 'e.g. Tagaytay City, Cavite', 'Facebook / Instagram / website link'], array_column($questions, 'placeholder'));
        $this->assertSame(7, collect($questions)->where('core', true)->count());
    }

    public function test_the_registration_page_gets_its_questions_from_the_table(): void
    {
        $this->get('/')->assertInertia(fn ($p) => $p->component('Register')->has('questions', 7)->where('questions.0.key', 'name')->where('questions.6.optional', true));
    }

    // ---------- adding questions ----------

    public function test_an_added_question_is_asked_checked_and_saved_with_its_wording(): void
    {
        $q = $this->make(['label' => 'Heard about us', 'required' => true]);
        $this->assertMatchesRegularExpression('/^q_[0-9a-f]{8}$/', $q->key);

        $this->get('/')->assertInertia(fn ($p) => $p->has('questions', 8)->where('questions.7.key', $q->key)->where('questions.7.optional', false));

        $this->register([$q->key => ''])->assertSessionHasErrors($q->key);
        $this->assertSame(0, Supplier::count());

        $this->register([$q->key => 'A friend'])->assertSessionHasNoErrors();
        $s = Supplier::sole();
        $this->assertSame([['key' => $q->key, 'label' => 'Heard about us', 'type' => 'text', 'value' => 'A friend', 'answer' => 'A friend']], $s->extra_answers);

        // Rewording the question later does not change what this supplier was asked.
        $this->signedIn()->put("/admin/questions/registration/{$q->id}", $this->edit($q, ['label' => 'Source']))->assertSessionHasNoErrors();
        $this->assertSame('Heard about us', $s->fresh()->extra_answers[0]['label']);
        $this->signedIn()->get("/admin/suppliers/{$s->id}")->assertInertia(fn ($p) => $p->where('registration_answers.0.label', 'Heard about us')->where('registration_answers.0.value', 'A friend'));
    }

    public function test_an_optional_added_question_can_be_skipped_and_nothing_is_stored(): void
    {
        $q = $this->make();

        $this->register([$q->key => '  '])->assertSessionHasNoErrors();

        $this->assertNull(Supplier::sole()->extra_answers);
    }

    public function test_choice_and_number_questions_check_their_answers(): void
    {
        $c = $this->make(['type' => 'choice', 'label' => 'Booths', 'text' => 'Do you want a booth?', 'options' => ['Yes', 'No']]);
        $n = $this->make(['type' => 'number', 'label' => 'Years', 'text' => 'Years in business?']);

        $this->register([$c->key => 'Maybe', $n->key => '4'])->assertSessionHasErrors($c->key);
        $this->register([$c->key => 'Yes', $n->key => 'ten'])->assertSessionHasErrors($n->key);
        $this->register([$c->key => 'Yes', $n->key => '4'])->assertSessionHasNoErrors();

        $this->assertSame(['Yes', '4'], array_column(Supplier::sole()->extra_answers, 'answer'));
    }

    public function test_answers_to_unknown_questions_are_ignored(): void
    {
        $this->register(['q_deadbeef' => 'sneaky'])->assertSessionHasNoErrors();

        $this->assertNull(Supplier::sole()->extra_answers);
    }

    public function test_bad_input_is_rejected_and_nothing_is_created(): void
    {
        $before = RegistrationQuestion::count();
        $bad = [
            'type' => $this->payload(['type' => 'textarea']),
            'text' => $this->payload(['text' => 'Hi {business_name}']),
            'options' => $this->payload(['type' => 'choice', 'options' => ['Yes', 'yes']]),
            'label' => $this->payload(['label' => '']),
        ];
        foreach ($bad as $field => $payload) {
            $this->signedIn()->post('/admin/questions/registration', $payload)->assertSessionHasErrors($field);
        }
        $this->signedIn()->post('/admin/questions/registration', $this->payload(['text' => 'Hello {name}, how did you hear?']))->assertSessionHasNoErrors();

        $this->assertSame($before + 1, RegistrationQuestion::count());
    }

    public function test_the_registration_chat_cannot_grow_past_its_limit(): void
    {
        config(['admin.max_registration_questions' => 8]);

        $this->make();
        $this->signedIn()->post('/admin/questions/registration', $this->payload(['label' => 'One too many']))->assertSessionHasErrors('question');
        $this->assertSame(8, RegistrationQuestion::count());
    }

    // ---------- core questions ----------

    public function test_core_questions_can_be_reworded_but_not_removed_retyped_or_made_optional(): void
    {
        $email = $this->q('email');

        $this->signedIn()->delete("/admin/questions/registration/{$email->id}")->assertSessionHasErrors('question');
        $this->signedIn()->put("/admin/questions/registration/{$email->id}", $this->edit($email, ['required' => false]))->assertSessionHasErrors('question');
        $this->signedIn()->put("/admin/questions/registration/{$email->id}", $this->edit($email, ['type' => 'text']))->assertSessionHasErrors('question');
        $this->assertTrue($email->fresh()->required);

        $this->signedIn()->put("/admin/questions/registration/{$email->id}", $this->edit($email, ['text' => 'Hi {name}! Your email, please?', 'label' => 'Email address', 'placeholder' => 'name@mail.com']))
            ->assertSessionHasNoErrors();
        $fresh = $email->fresh();
        $this->assertSame(['Email address', 'name@mail.com'], [$fresh->label, $fresh->placeholder]);
        $this->get('/')->assertInertia(fn ($p) => $p->where('questions.1.text', 'Hi {name}! Your email, please?'));

        // The registration itself still works the same.
        $this->register()->assertSessionHasNoErrors();
        $this->assertSame(1, Supplier::count());
    }

    public function test_the_ai_wording_endpoint_accepts_only_current_questions(): void
    {
        $q = $this->make();
        $this->postJson('/ai/reword', ['key' => $q->key])->assertOk();
        $this->postJson('/ai/reword', ['key' => 'nope'])->assertStatus(422);

        $this->signedIn()->delete("/admin/questions/registration/{$q->id}");
        $this->postJson('/ai/reword', ['key' => $q->key])->assertStatus(422);
    }

    // ---------- editing, deleting, order ----------

    public function test_a_question_that_was_answered_keeps_its_type_and_chosen_options(): void
    {
        $q = $this->make(['type' => 'choice', 'options' => ['Yes', 'No', 'Sometimes']]);
        $this->register([$q->key => 'Yes']);

        $this->signedIn()->put("/admin/questions/registration/{$q->id}", $this->edit($q->fresh(), ['type' => 'text']))->assertSessionHasErrors('type');
        $this->signedIn()->put("/admin/questions/registration/{$q->id}", $this->edit($q->fresh(), ['options' => [['value' => 'No', 'label' => 'No'], ['value' => 'Sometimes', 'label' => 'Sometimes']]]))
            ->assertSessionHasErrors('options');
        $this->signedIn()->put("/admin/questions/registration/{$q->id}", $this->edit($q->fresh(), ['options' => [['value' => 'Yes', 'label' => 'Yes, please'], ['value' => 'No', 'label' => 'No']]]))
            ->assertSessionHasNoErrors();

        $this->assertSame([['value' => 'Yes', 'label' => 'Yes, please'], ['value' => 'No', 'label' => 'No']], $q->fresh()->options);
    }

    public function test_deleting_hides_a_question_keeps_the_answers_and_it_can_be_restored(): void
    {
        $q = $this->make();
        $this->register([$q->key => 'Facebook']);

        $this->signedIn()->delete("/admin/questions/registration/{$q->id}")->assertSessionHas('admin_flash', fn ($f) => str_contains($f['message'], '1 supplier'));

        $this->assertSoftDeleted('registration_questions', ['id' => $q->id]);
        $this->get('/')->assertInertia(fn ($p) => $p->has('questions', 7));
        $this->assertSame('Facebook', Supplier::sole()->extra_answers[0]['answer']);

        $this->signedIn()->post("/admin/questions/registration/{$q->id}/restore")->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn ($p) => $p->has('questions', 8)->where('questions.7.key', $q->key));
    }

    public function test_restoring_is_refused_past_the_limit(): void
    {
        $q = $this->make();
        $this->signedIn()->delete("/admin/questions/registration/{$q->id}");
        $this->make(['label' => 'Another']);
        config(['admin.max_registration_questions' => 8]);

        $this->signedIn()->post("/admin/questions/registration/{$q->id}/restore")->assertSessionHasErrors('question');
        $this->assertNotNull($q->fresh()->deleted_at);
    }

    public function test_reordering_changes_the_chat_order_and_rejects_a_bad_list(): void
    {
        $ids = RegistrationQuestion::ordered()->pluck('id')->all();
        $swapped = $ids;
        [$swapped[2], $swapped[3]] = [$swapped[3], $swapped[2]]; // business name before mobile

        $this->signedIn()->post('/admin/questions/registration/reorder', ['order' => $swapped])->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn ($p) => $p->where('questions.2.key', 'business_name')->where('questions.3.key', 'mobile'));

        foreach ([array_slice($ids, 1), array_merge($ids, [999999]), array_merge(array_slice($ids, 1), [$ids[1]])] as $bad) {
            $this->signedIn()->post('/admin/questions/registration/reorder', ['order' => $bad])->assertSessionHasErrors('order');
        }
        $this->assertSame($swapped, RegistrationQuestion::ordered()->pluck('id')->all());
    }

    // ---------- the admin page, access and records ----------

    public function test_the_page_has_both_chatbots_organized_separately(): void
    {
        $this->make();
        $this->signedIn()->get('/admin/questions')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Admin/Questions')
            ->has('registration.questions', 8)->where('registration.questions.0.key', 'name')->where('registration.questions.0.locked', true)
            ->where('registration.max', 12)->where('registration.creatable', ['text', 'number', 'choice'])
            ->has('questions', 30)->where('max', 20));
    }

    public function test_guests_are_sent_to_the_login_from_every_registration_question_route(): void
    {
        foreach (['post' => ['/admin/questions/registration', '/admin/questions/registration/reorder', '/admin/questions/registration/1/restore'], 'put' => ['/admin/questions/registration/1'], 'delete' => ['/admin/questions/registration/1']] as $verb => $paths) {
            foreach ($paths as $path) {
                $this->{$verb}($path)->assertRedirect('/admin/login');
            }
        }
        $this->assertSame(7, RegistrationQuestion::count());
    }

    public function test_changes_are_logged_with_names_only(): void
    {
        $q = $this->make(['text' => 'A secret-looking question text?']);
        $this->signedIn()->put("/admin/questions/registration/{$q->id}", $this->edit($q, ['label' => 'Renamed']));
        $this->signedIn()->delete("/admin/questions/registration/{$q->id}");

        $this->assertSame(['registration_question_created', 'registration_question_updated', 'registration_question_deleted'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('secret-looking', AuditLog::all()->toJson());
    }
}
