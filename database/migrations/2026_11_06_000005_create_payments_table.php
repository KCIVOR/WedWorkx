<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('hitpay_request_id')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('checkout_url', 1000);
            $table->enum('status', ['pending', 'completed', 'failed', 'expired']);
            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->dateTime('paid_at')->nullable();
            $table->boolean('paid_late')->default(false);
            $table->json('webhook_payload')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
