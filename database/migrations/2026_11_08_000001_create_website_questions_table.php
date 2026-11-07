<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The website (Chatbot 2) questions, editable from the admin. The rows inserted here are the original
 * questions, copied word for word from the first version of App\Support\WebsiteQuestions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_questions', function (Blueprint $table) {
            $table->id();
            // Where the answer is stored. Unique among the questions that can apply to one business type (checked in code).
            $table->string('key', 40)->index();
            $table->string('type', 20); // text | textarea | number | choice | services | areas | media
            $table->string('label', 80);
            $table->string('text', 500);
            $table->string('placeholder', 160)->nullable();
            $table->json('options')->nullable(); // list of {value, label}
            $table->boolean('required')->default(false);
            $table->json('applies_to')->nullable(); // null = every business type
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('locked')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        $now = now();
        $rows = [];
        $opt = fn (array $values) => json_encode(array_map(fn ($v) => ['value' => $v, 'label' => $v], $values), JSON_UNESCAPED_UNICODE);

        $styles = [
            'elegant' => 'Elegant & Luxurious',
            'modern' => 'Modern & Simple',
            'romantic' => 'Romantic & Soft',
            'classic' => 'Classic & Timeless',
            'colorful' => 'Colorful & Creative',
            'surprise' => 'Surprise Me!',
        ];

        $common = [
            ['description', 'textarea', 'Business Description', "Let's start with your business. In a sentence or two, what makes {business_name} special to couples getting married?", "e.g. We've been in the wedding industry for 8 years...", null, true, true],
            ['services', 'services', 'Services / Packages', "What services or packages would you like to list on your website? Add each one, and the price if you'd like to show it.", null, null, false, true],
            ['areas', 'areas', 'Service Areas & Booking', 'Which towns or cities do you serve? And how do couples usually get in touch to book you — Messenger, phone call, or something else?', null, null, false, true],
            ['media', 'media', 'Photos, Logo & Colors', "Do you have a logo, photos of your work, or favorite colors you'd like us to use? This is optional — skip it if you don't have anything ready yet.", 'Favorite colors, e.g. Blush pink and gold', null, false, true],
            ['style', 'choice', 'Website Style', 'What kind of look would you like for your website? This is just a preference — our design team creates the final look for you.', null,
                json_encode(collect($styles)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all(), JSON_UNESCAPED_UNICODE), true, true],
            ['additional', 'textarea', 'Additional Info', 'Anything else couples should know? For example reviews, ongoing promos, or how far in advance they should book. This is optional.', 'Optional — leave blank to skip', null, false, false],
        ];
        foreach ($common as $i => [$key, $type, $label, $text, $placeholder, $options, $required, $locked]) {
            $rows[] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'text' => $text, 'placeholder' => $placeholder,
                'options' => $options, 'required' => $required, 'applies_to' => null, 'sort_order' => ($i + 1) * 10,
                'locked' => $locked, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // Two questions per business type: [label, text, type, placeholder, options]
        $extras = [
            'venue' => [
                ['Guest Capacity', 'About how many guests can your venue fit comfortably?', 'number', 'e.g. 150 guests', null],
                ["What's Included", 'Besides the venue space, what else is included — like catering, tables and chairs, or decorations?', 'choice', null, ['Venue space only', 'Venue + basic setup (tables & chairs)', 'Venue + full package (catering, decor, etc.)', 'It depends on the package']],
            ],
            'coordinator' => [
                ['Planning Service', 'Which of these do you offer?', 'choice', null, ['Full wedding planning, start to finish', 'Day-of coordination only', 'Both — depending on what the couple needs']],
                ['Why Couples Choose You', "What's something couples often say they loved about working with you?", 'textarea', 'Optional — e.g. attention to detail, staying calm under pressure...', null],
            ],
            'photographer' => [
                ['Coverage Type', 'What kind of coverage do you usually offer?', 'choice', null, ['Full-day coverage', 'Half-day coverage', 'Ceremony only', 'Pre-wedding + wedding day', 'Depends on the package']],
                ['Photo or Video', 'Do you do photos, videos, or both?', 'choice', null, ['Photos only', 'Videos only', 'Both photos and videos']],
            ],
            'caterer' => [
                ['Menu Options', 'What food packages or menu styles do you offer?', 'textarea', 'e.g. Buffet, plated meals, food stations...', null],
                ['Special Requests', 'Can you prepare special meals, like vegetarian or halal, if couples ask?', 'choice', null, ['Yes, we can accommodate special requests', 'We can try — just ask us first', 'We only offer our standard menu']],
            ],
            'florist' => [
                ['Style', 'What flower or decoration styles do you usually do?', 'textarea', 'e.g. Garden-style, minimalist, tropical...', null],
                ['Other Décor', 'Do you only do flowers, or do you also handle other décor like backdrops or lights?', 'choice', null, ['Flowers only', 'Flowers plus some décor', 'Full styling and décor']],
            ],
            'makeup' => [
                ['Service Location', 'Where do you usually do the makeup — at your own studio, or do you travel to the venue?', 'choice', null, ['At my own studio', 'I travel to the venue', 'Both, depending on the booking']],
                ['Trial Session', 'Do you offer a trial session before the wedding day?', 'choice', null, ['Yes, trials are included', 'Trials available for an extra fee', 'No trials — just the wedding day']],
            ],
            'attire' => [
                ['Offering', 'What do you offer?', 'choice', null, ['Gown or suit rentals', 'Custom-made outfits', 'Ready-made (off-the-rack)', 'A mix of these']],
                ['Fittings & Alterations', 'Do you also offer fitting or alteration services?', 'choice', null, ['Yes, included', 'Available for a fee', 'No alteration services']],
            ],
            'entertainment' => [
                ["What's Included", 'What do you provide? For example a live band, DJ, host, or sound system.', 'textarea', 'e.g. Live band, DJ, host/emcee, sound & lights...', null],
                ['Duration', 'How long do your services usually run on the wedding day?', 'choice', null, ['A few hours', 'The whole event, ceremony to reception', 'Depends on the package']],
            ],
            'cake' => [
                ['Flavors & Designs', 'What flavors or cake designs do you specialize in?', 'textarea', 'Optional — e.g. classic vanilla, custom themed designs...', null],
                ['Tasting', 'Do you offer a free tasting before the wedding?', 'choice', null, ['Yes, free tasting', 'Tasting available for a fee', 'No tasting offered']],
            ],
            'invitations' => [
                ['What You Make', 'What do you make — invitations, souvenirs, or both?', 'choice', null, ['Invitations only', 'Souvenirs or giveaways only', 'Both invitations and souvenirs']],
                ['Custom Designs', 'Can couples ask for a custom design, or do you only offer ready-made designs?', 'choice', null, ['Yes, custom designs available', 'Mostly ready-made designs', 'Both options available']],
            ],
            'transportation' => [
                ['Offering', 'What do you offer?', 'choice', null, ['Wedding car or transportation only', 'Accommodation only', 'Both transportation and accommodation']],
                ['Vehicles & Packages', 'What vehicles or rooms/packages do you have available?', 'textarea', 'Optional — e.g. bridal car, van for guests, honeymoon suite...', null],
            ],
            'other' => [
                ['Business Details', 'Tell us a bit more about what your business offers.', 'textarea', 'Optional', null],
                ['Additional Notes', 'Is there anything else couples should know before booking you?', 'textarea', 'Optional', null],
            ],
        ];

        $typeIndex = 0;
        foreach ($extras as $type => $pair) {
            foreach ($pair as $n => [$label, $text, $qType, $placeholder, $options]) {
                $rows[] = [
                    'key' => 'extra'.($n + 1), 'type' => $qType, 'label' => $label, 'text' => $text, 'placeholder' => $placeholder,
                    'options' => $options ? $opt($options) : null,
                    'required' => $qType === 'choice', // the same as before: choice answers are required, the rest optional
                    'applies_to' => json_encode([$type]),
                    'sort_order' => 100 + $typeIndex * 10 + $n,
                    'locked' => false, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $typeIndex++;
        }

        DB::table('website_questions')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_questions');
    }
};
