<?php

namespace Tests\Feature;

use App\Mail\WebsiteLiveMail;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\SlotService;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bugs found by the T11 end-to-end QA, pinned so they cannot come back. */
class UiRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function jsx(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (in_array($f->getExtension(), ['jsx', 'js'], true)) {
                $files[$f->getPathname()] = file_get_contents($f->getPathname());
            }
        }

        return $files;
    }

    /**
     * Our radius tokens are named r-sm and r-md. Tailwind already has `rounded-r-sm` and `rounded-r-md`
     * meaning "right corners only, 2px and 6px", so those two classes silently gave the wrong corners.
     */
    public function test_radius_token_names_that_collide_with_tailwind_right_side_utilities_are_never_used(): void
    {
        $offenders = [];
        foreach ($this->jsx() as $path => $code) {
            if (preg_match('/\brounded-r-(sm|md)\b/', $code)) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, 'Use rounded-[var(--r-sm)] / rounded-[var(--r-md)] for the design tokens r-sm and r-md');
    }

    public function test_status_cards_keep_their_content_inside_the_card_on_a_phone(): void
    {
        $wb = file_get_contents(resource_path('js/Components/wb/index.jsx'));
        $page = file_get_contents(resource_path('js/Pages/MyWebsite.jsx'));

        // The inner column is capped to the card width, and the pills in the grid may wrap.
        $this->assertStringContainsString('grid-cols-[minmax(0,1fr)]', $wb);
        $this->assertMatchesRegularExpression('/export function Pill\(\{[^}]*wrap/', $wb);
        $this->assertStringContainsString('<Pill tone={tone} wrap>', $page);
    }

    public function test_email_value_cells_wrap_long_text_such_as_a_website_address(): void
    {
        $this->seed(CampaignSeeder::class);
        $s = app(SlotService::class)->register([
            'name' => 'Gia Ong', 'email' => 'gia@example.com', 'mobile' => '+639171234567',
            'business_name' => 'Ong Venue', 'business_type' => 'venue', 'location' => 'Tagaytay',
        ])['supplier'];
        $order = Order::create(['supplier_id' => $s->id, 'reference' => 'KT10-LONG', 'current_stage' => 6, 'paid_at' => now(), 'delivered_at' => now(),
            'website_url' => 'https://www.very-long-business-name-for-a-wedding-supplier-in-tagaytay.example.ph/gallery/portfolio-2026']);

        $html = (new WebsiteLiveMail($order->fresh()))->render();

        $this->assertStringContainsString('very-long-business-name-for-a-wedding-supplier-in-tagaytay.example.ph', $html);
        $this->assertMatchesRegularExpression('/class="rv"[^>]*word-break:break-word;overflow-wrap:anywhere/', $html);
    }
}
