<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_questions', function (Blueprint $table) {
            $table->json('intake_schema')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('website_questions', function (Blueprint $table) {
            $table->dropColumn('intake_schema');
        });
    }
};
