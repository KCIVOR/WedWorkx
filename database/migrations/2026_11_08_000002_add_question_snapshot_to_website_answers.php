<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_answers', function (Blueprint $table) {
            // The questions as the supplier saw them when they confirmed. Null for answers saved before this existed.
            $table->json('question_snapshot')->nullable()->after('answers');
        });
    }

    public function down(): void
    {
        Schema::table('website_answers', function (Blueprint $table) {
            $table->dropColumn('question_snapshot');
        });
    }
};
