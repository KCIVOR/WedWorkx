<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Mail\RegistrationReceivedMail;
use App\Mail\WaitlistMail;
use App\Models\Campaign;
use App\Models\Supplier;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The offer is presented as Kasalang Tagaytay's 10th Anniversary, signed by WedWorx. */
class AnniversaryWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CampaignSeeder::class);
    }

    private function supplier(): Supplier
    {
        return app(SlotService::class)->register([
            'name' => 'Maria Santos', 'email' => 'maria@example.com', 'mobile' => '+639171234567',
            'business_name' => 'Rosal Catering', 'business_type' => 'caterer', 'location' => 'Tagaytay',
        ])['supplier'];
    }

    public function test_emails_use_the_anniversary_wording_wedworx_signoff_and_startuplab_footer(): void
    {
        $s = $this->supplier();

        foreach ([new RegistrationReceivedMail($s), new WaitlistMail($s), new InvitationMail($s)] as $mail) {
            $html = $mail->render();

            $this->assertStringNotContainsString('10-10-10', $html);
            $this->assertStringNotContainsString('Kasalang Tagaytay Team', $html);
            $this->assertStringContainsString('10th Anniversary', $html);
            $this->assertStringContainsString('&mdash; WedWorx', $html);
            $this->assertStringContainsString('Powered by StartupLab Business Center', $html);
            $this->assertStringContainsString('2nd Floor, Pearl Plaza Building, 7001 Felix F. Manalo Rd, Navarro, General Trias, 4107 Cavite', $html);
        }
    }

    public function test_data_migration_rewords_the_greeting_and_campaign_but_keeps_admin_edits(): void
    {
        $migration = require database_path('data-migrations/2026_11_17_000001_rename_campaign_to_kasalang_anniversary.php');
        $old = "Hello! Welcome to our 10\u{2011}10\u{2011}10 Campaign! We're celebrating Kasalang Tagaytay's 10th anniversary with a special website offer for wedding suppliers. I'd love to know more about your business. Let's get started! 🎉\n\nMay I know your name?";
        DB::table('registration_questions')->where('key', 'name')->update(['text' => $old]);
        DB::table('registration_questions')->where('key', 'email')->update(['text' => 'My own wording, 10-10-10 included']);
        Campaign::query()->update(['name' => 'Kasalang Tagaytay 10-10-10']);

        $migration->up();

        $greeting = DB::table('registration_questions')->where('key', 'name')->value('text');
        $this->assertStringContainsString("WedWorx's celebration of Kasalang Tagaytay's 10th Anniversary", $greeting);
        $this->assertStringNotContainsString('10', explode('10th', $greeting)[0]);
        $this->assertSame('My own wording, 10-10-10 included', DB::table('registration_questions')->where('key', 'email')->value('text'));
        $this->assertSame('Kasalang Tagaytay 10th Anniversary', Campaign::first()->name);

        $migration->down();

        $this->assertSame($old, DB::table('registration_questions')->where('key', 'name')->value('text'));
        $this->assertSame('Kasalang Tagaytay 10-10-10', Campaign::first()->name);
    }
}
