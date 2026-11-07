<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->enum('actor', ['supplier', 'system', 'hitpay', 'hermes']);
            $table->dateTime('created_at')->useCurrent();

            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_history');
    }
};
