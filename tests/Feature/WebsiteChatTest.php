<?php

namespace Tests\Feature;

use App\Enums\SupplierStatus;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Models\Upload;
use App\Models\WebsiteAnswer;
use App\Services\SlotService;
use App\Support\BusinessTypes;
use App\Support\WebsiteQuestions;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebsiteChatTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
        Mail::fake();
        Storage::fake('public');
    }

    private function supplier(string $type = 'photographer', ?SupplierStatus $status = null): Supplier
    {
        $this->n++;
        $s = app(SlotService::class)->register([
            'name' => "Maria Santos $this->n",
            'email' => "s$this->n@example.com",
            'mobile' => '+6391700'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'business_name' => "Rosal Studio $this->n",
            'business_type' => $type,
            'location' => 'Tagaytay City, Cavite',
        ])['supplier'];
        if ($status) {
            $s->update(['status' => $status]);
        }

        return $s->fresh();
    }

    private function answers(array $o = []): array
    {
        return $o + [
            'description' => 'Candid, warm wedding photography for garden weddings.',
            'services' => [['name' => 'Full day', 'price' => '₱25,000'], ['name' => 'Half day', 'price' => '']],
            'service_areas' => ['Tagaytay', 'Silang'],
            'booking' => 'Facebook Messenger',
            'colors' => 'Blush pink and gold',
            'style' => 'romantic',
            'additional' => '',
            'extra1' => 'Full-day coverage',
            'extra2' => 'Both photos and videos',
        ];
    }

    private function png(string $name = 'a.png', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->image($name, 200, 200)->size($kb);
    }

    // ---------- the chat page ----------

    public function test_valid_supplier_gets_the_chat_with_eight_questions(): void
    {
        $s = $this->supplier('photographer');

        $this->get('/invite/'.$s->invite_token)->assertOk()->assertInertia(fn ($p) => $p
            ->component('Invite')
            ->where('status', 'invited')
            ->has('chat.questions', 8)
            ->where('chat.questions.0.text', "Let's start with your business. In a sentence or two, what makes Rosal Studio 1 special to couples getting married?")
            ->where('chat.questions.6.label', 'Coverage Type')
            ->where('chat.questions.7.label', 'Photo or Video')
            ->where('chat.saved', null)
            ->where('chat.limits.maxPhotos', 10)
            ->has('chat.expiresAt')
            ->missing('chat.email'));
    }

    public function test_every_business_type_has_six_common_and_two_dedicated_questions(): void
    {
        foreach (BusinessTypes::keys() as $type) {
            $q = WebsiteQuestions::for($type, 'Biz');
            $this->assertCount(8, $q, $type);
            $this->assertSame(['description', 'services', 'areas', 'media', 'style', 'additional', 'extra1', 'extra2'], array_column($q, 'key'), $type);
        }
        $this->assertSame('Guest Capacity', WebsiteQuestions::for('venue', 'B')[6]['label']);
        $this->assertSame('Menu Options', WebsiteQuestions::for('caterer', 'B')[6]['label']);
        $this->assertSame('Business Details', WebsiteQuestions::for('other', 'B')[6]['label']);
    }

    public function test_unknown_token_is_404_everywhere(): void
    {
        $this->get('/invite/nope')->assertNotFound();
        $this->post('/invite/nope/confirm', $this->answers())->assertNotFound();
        $this->post('/invite/nope/uploads', ['kind' => 'photo', 'file' => $this->png()])->assertNotFound();
    }

    // ---------- confirm ----------

    public function test_valid_supplier_completes_all_answers_and_reaches_details_submitted(): void
    {
        $s = $this->supplier();

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers())->assertRedirect("/invite/{$s->invite_token}");

        $s->refresh();
        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->status);
        $a = WebsiteAnswer::where('supplier_id', $s->id)->sole();
        $this->assertNotNull($a->submitted_at);
        $this->assertNull($a->locked_at);
        $this->assertSame('romantic', $a->answers['style']);
        $this->assertSame('Full day', $a->answers['services'][0]['name']);
        $this->assertSame(['Tagaytay', 'Silang'], $a->answers['service_areas']);

        $h = StatusHistory::where('supplier_id', $s->id)->latest('id')->first();
        $this->assertSame([SupplierStatus::Invited, SupplierStatus::DetailsSubmitted, 'supplier'], [$h->from_status, $h->to_status, $h->actor]);
    }

    public function test_returning_supplier_sees_their_saved_answers_and_can_change_one(): void
    {
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers());

        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p
            ->where('status', 'details_submitted')
            ->where('chat.saved.style', 'romantic')
            ->where('chat.saved.booking', 'Facebook Messenger'));

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers(['style' => 'modern']))->assertRedirect();

        $this->assertSame('modern', WebsiteAnswer::sole()->answers['style']);
        $this->assertSame('romantic', $this->answers()['style']); // other answers untouched by the edit payload
        $this->assertSame('Facebook Messenger', WebsiteAnswer::sole()->answers['booking']);
        $this->assertSame(2, StatusHistory::where('supplier_id', $s->id)->count()); // no extra history on re-confirm
    }

    public function test_invalid_answers_are_rejected_and_change_nothing(): void
    {
        $s = $this->supplier();
        $cases = [
            'description' => $this->answers(['description' => 'no']),
            'style' => $this->answers(['style' => 'neon']),
            'extra1' => $this->answers(['extra1' => 'Not an option']),
            'extra2' => $this->answers(['extra2' => '']),
            'services.0.name' => $this->answers(['services' => [['name' => str_repeat('x', 200), 'price' => '']]]),
        ];

        foreach ($cases as $field => $payload) {
            $this->from('/x')->post("/invite/{$s->invite_token}/confirm", $payload)->assertSessionHasErrors($field);
        }

        $this->assertSame(SupplierStatus::Invited, $s->fresh()->status);
        $this->assertSame(0, WebsiteAnswer::count());
    }

    public function test_optional_questions_may_be_skipped(): void
    {
        $s = $this->supplier();
        $skipped = ['services' => [], 'service_areas' => [], 'booking' => '', 'colors' => '', 'additional' => ''];

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers($skipped))->assertSessionHasNoErrors();

        $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status);
    }

    public function test_each_business_type_accepts_its_own_valid_extra_answers(): void
    {
        \App\Models\Campaign::first()->update(['slot_limit' => 50]);
        foreach (BusinessTypes::keys() as $type) {
            $s = $this->supplier($type);
            $payload = $this->answers();
            foreach (WebsiteQuestions::for($type, 'B') as $q) {
                if (str_starts_with($q['key'], 'extra')) {
                    $payload[$q['key']] = match ($q['type']) {
                        'choice' => $q['options'][0]['value'],
                        'number' => '150',
                        default => 'Some text',
                    };
                }
            }
            $this->post("/invite/{$s->invite_token}/confirm", $payload)->assertSessionHasNoErrors();
            $this->assertSame(SupplierStatus::DetailsSubmitted, $s->fresh()->status, $type);
        }
    }

    // ---------- expiry and locking ----------

    public function test_expired_or_lapsed_supplier_cannot_open_the_chat_or_change_anything(): void
    {
        $s = $this->supplier();
        $this->travel(25)->hours(); // lapsed, scheduler has not run

        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p->where('status', 'expired')->where('chat', null));
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers())->assertForbidden();
        $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => $this->png()])->assertForbidden();

        $this->assertSame(0, WebsiteAnswer::count());
        $this->assertSame(0, Upload::count());
    }

    public function test_locked_answers_cannot_be_edited_or_uploaded_to(): void
    {
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/confirm", $this->answers());
        $up = $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => $this->png()])->json('id');
        WebsiteAnswer::sole()->update(['locked_at' => now()]);

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers(['style' => 'modern']))->assertForbidden();
        $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => $this->png()])->assertForbidden();
        $this->delete("/invite/{$s->invite_token}/uploads/$up")->assertForbidden();

        $this->assertSame('romantic', WebsiteAnswer::sole()->answers['style']);
        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p->where('chat', null));
    }

    public function test_paid_supplier_cannot_edit(): void
    {
        $s = $this->supplier('photographer', SupplierStatus::Paid);

        $this->post("/invite/{$s->invite_token}/confirm", $this->answers())->assertForbidden();
        $this->get('/invite/'.$s->invite_token)->assertRedirect('/invite/'.$s->invite_token.'/my-website');
    }

    // ---------- uploads ----------

    public function test_valid_uploads_are_stored_in_the_suppliers_folder_with_matching_rows(): void
    {
        $s = $this->supplier();

        $logo = $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'logo', 'file' => $this->png('logo.png')])->assertCreated();
        $photo = $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => UploadedFile::fake()->image('p.jpg')->size(200)])->assertCreated();
        $webp = $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => UploadedFile::fake()->image('p.webp')->size(50)])->assertCreated();

        $this->assertSame(3, Upload::where('supplier_id', $s->id)->count());
        foreach (Upload::all() as $u) {
            $this->assertStringStartsWith("uploads/{$s->id}/", $u->path);
            Storage::disk('public')->assertExists($u->path);
        }
        $this->assertSame(['logo', 'photo', 'photo'], Upload::orderBy('id')->pluck('kind')->all());
        $this->assertSame('logo.png', $logo->json('name'));
        $this->assertSame('image/jpeg', Upload::find($photo->json('id'))->mime);
    }

    public function test_non_image_oversized_and_svg_files_are_rejected(): void
    {
        $s = $this->supplier();
        $url = "/invite/{$s->invite_token}/uploads";

        $this->postJson($url, ['kind' => 'photo', 'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])->assertJsonValidationErrors('file');
        $this->postJson($url, ['kind' => 'photo', 'file' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])->assertJsonValidationErrors('file');
        $this->postJson($url, ['kind' => 'photo', 'file' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')])->assertJsonValidationErrors('file');
        $this->postJson($url, ['kind' => 'photo', 'file' => $this->png('big.png', 6000)])->assertJsonValidationErrors('file'); // 5 MB limit
        $this->postJson($url, ['kind' => 'video', 'file' => $this->png()])->assertJsonValidationErrors('kind');
        $this->postJson($url, ['kind' => 'photo'])->assertJsonValidationErrors('file');

        $this->assertSame(0, Upload::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_file_exactly_at_the_size_limit_is_accepted(): void
    {
        $s = $this->supplier();

        $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => $this->png('edge.png', 5120)])->assertCreated();
    }

    public function test_eleventh_photo_and_second_logo_are_rejected(): void
    {
        $s = $this->supplier();
        $url = "/invite/{$s->invite_token}/uploads";

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson($url, ['kind' => 'photo', 'file' => $this->png("p$i.png")])->assertCreated();
        }
        $this->postJson($url, ['kind' => 'photo', 'file' => $this->png('p11.png')])->assertJsonValidationErrors('file');

        $this->postJson($url, ['kind' => 'logo', 'file' => $this->png('l1.png')])->assertCreated();
        $this->postJson($url, ['kind' => 'logo', 'file' => $this->png('l2.png')])->assertJsonValidationErrors('file');

        $this->assertSame(10, Upload::where('kind', 'photo')->count());
        $this->assertSame(1, Upload::where('kind', 'logo')->count());
    }

    public function test_limits_come_from_config(): void
    {
        config(['uploads.max_photos' => 2, 'uploads.max_kb' => 50]);
        $s = $this->supplier();
        $url = "/invite/{$s->invite_token}/uploads";

        $this->postJson($url, ['kind' => 'photo', 'file' => $this->png('a.png', 10)])->assertCreated();
        $this->postJson($url, ['kind' => 'photo', 'file' => $this->png('b.png', 10)])->assertCreated();
        $this->postJson($url, ['kind' => 'photo', 'file' => $this->png('c.png', 10)])->assertJsonValidationErrors('file');
        $this->postJson($url, ['kind' => 'logo', 'file' => $this->png('d.png', 100)])->assertJsonValidationErrors('file');
    }

    public function test_supplier_can_remove_an_upload_but_not_someone_elses(): void
    {
        $a = $this->supplier();
        $b = $this->supplier();
        $mine = $this->post("/invite/{$a->invite_token}/uploads", ['kind' => 'photo', 'file' => $this->png()])->json('id');
        $theirs = $this->post("/invite/{$b->invite_token}/uploads", ['kind' => 'photo', 'file' => $this->png()])->json('id');

        $this->delete("/invite/{$a->invite_token}/uploads/$theirs")->assertNotFound();
        $this->assertSame(2, Upload::count());

        $path = Upload::find($mine)->path;
        $this->delete("/invite/{$a->invite_token}/uploads/$mine")->assertOk();
        $this->assertNull(Upload::find($mine));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_uploaded_files_are_listed_for_a_returning_supplier(): void
    {
        $s = $this->supplier();
        $this->post("/invite/{$s->invite_token}/uploads", ['kind' => 'photo', 'file' => $this->png('mine.png')]);

        $this->get('/invite/'.$s->invite_token)->assertInertia(fn ($p) => $p
            ->has('chat.uploads', 1)
            ->where('chat.uploads.0.name', 'mine.png')
            ->where('chat.uploads.0.kind', 'photo'));
    }
}
