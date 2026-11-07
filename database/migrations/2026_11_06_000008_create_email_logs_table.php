<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['registered', 'invite', 'reminder', 'receipt', 'failed', 'updated', 'waitlist', 'expired', 'live']);
            $table->dateTime('sent_at')->useCurrent();
            $table->string('status', 20);

            // Unique(supplier_id, type) for every type except `failed`.
            // MySQL has no partial unique index, so a generated column is NULL for `failed`
            // (NULLs never collide in a unique index) and equals `type` for everything else.
            $table->string('unique_type', 20)->nullable()->virtualAs("if(`type` = 'failed', null, `type`)");
            $table->unique(['supplier_id', 'unique_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
