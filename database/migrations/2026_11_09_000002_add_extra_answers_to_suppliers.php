<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Answers to the extra registration questions an admin added: [{key, label, type, value, answer}].
            // The label is saved with the answer, so later edits to a question never change what was asked.
            $table->json('extra_answers')->nullable()->after('social_link');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('extra_answers');
        });
    }
};
