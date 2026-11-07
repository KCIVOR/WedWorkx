<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->unique(); // KT10-xxxx
            $table->unsignedTinyInteger('current_stage')->default(0); // 0 to 6
            $table->string('website_url', 500)->nullable();
            $table->dateTime('paid_at');
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
