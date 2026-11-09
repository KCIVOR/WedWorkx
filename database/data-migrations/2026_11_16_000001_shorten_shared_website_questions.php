<?php

use App\Models\WebsiteQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ARCHIVED = [
        'q_77963408' => 'What Makes Your Business Different?',
        'website_voice' => 'Website Tone of Voice',
        'q_19a5f097' => 'Style References (Links)',
        'social_proof' => 'Testimonials & Reviews',
        'policies' => 'Booking Policies & Legal Content',
        'domain_platform' => 'Existing Domain & Platform',
    ];

    public function up(): void
    {
        $this->archiveQuestions(true);
    }

    public function down(): void
    {
        $this->archiveQuestions(false);
    }

    private function archiveQuestions(bool $archive): void
    {
        DB::transaction(function () use ($archive) {
            foreach (self::ARCHIVED as $key => $label) {
                $query = $archive ? WebsiteQuestion::query() : WebsiteQuestion::onlyTrashed();
                $question = $query->whereNull('applies_to')->whereNotNull('intake_schema')
                    ->where(fn ($query) => $query->where('key', $key)->orWhere('label', $label))
                    ->lockForUpdate()->get()->sole();

                if ($archive) {
                    $question->delete();
                } else {
                    $question->restore();
                }
            }
        });
    }
};
