<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('slot_number')->nullable();
            $table->unsignedInteger('waitlist_number')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('mobile', 20);
            $table->string('business_name');
            $table->string('business_type');
            $table->string('city');
            $table->string('province');
            $table->string('social_link', 500)->nullable();
            $table->string('status', 32);
            $table->string('invite_token', 64)->nullable()->unique();
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('invite_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'email']);
            $table->unique(['campaign_id', 'mobile']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
