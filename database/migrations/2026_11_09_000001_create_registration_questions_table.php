<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The registration (Chatbot 1) questions, editable from the admin. The 7 core rows are the original questions,
 * word for word. Core questions keep their field (name, email...) and can only be reworded. Admins can add
 * extra questions, which are saved with the supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_questions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('type', 20); // core | text | number | choice
            $table->string('label', 80);
            $table->string('text', 500);
            $table->string('placeholder', 160)->nullable();
            $table->json('options')->nullable(); // list of {value, label}
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('locked')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        $now = now();
        $core = [
            ['name', 'Name', "Hello! Welcome to our 10‑10‑10 Campaign! We're celebrating Kasalang Tagaytay's 10th anniversary with a special website offer for wedding suppliers. I'd love to know more about your business. Let's get started! 🎉\n\nMay I know your name?", 'Your full name', true],
            ['email', 'Email', "Nice to meet you, {name}! What's your email address? We'll use this to send you updates about the website offer.", 'you@example.com', true],
            ['mobile', 'Mobile', "What's the best mobile number to reach you?", '09xx xxx xxxx', true],
            ['business_name', 'Business Name', "What's the name of your wedding business?", 'Business name', true],
            ['business_type', 'Business Type', 'What type of wedding service do you offer?', null, true],
            ['location', 'Location', 'Which city or province is your business based in?', 'e.g. Tagaytay City, Cavite', true],
            ['social_link', 'Social Media', "Last one for now — do you have a Facebook page, Instagram account, or existing website you'd like to share? This is optional.", 'Facebook / Instagram / website link', false],
        ];

        DB::table('registration_questions')->insert(array_map(fn ($c, $i) => [
            'key' => $c[0], 'type' => 'core', 'label' => $c[1], 'text' => $c[2], 'placeholder' => $c[3], 'options' => null,
            'required' => $c[4], 'sort_order' => ($i + 1) * 10, 'locked' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $core, array_keys($core)));
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_questions');
    }
};
