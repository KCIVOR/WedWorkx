<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_chats', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique(); // the secret the browser holds
            $table->string('bot', 20); // registration | website
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open'); // open | review | done | fallback
            $table->json('answers')->nullable(); // what Hermes sent and the system accepted, in the form's own shape
            $table->string('widget', 20)->nullable(); // upload: Hermes asked the supplier for photos
            $table->dateTime('waiting_since')->nullable(); // set while the supplier is waiting for Hermes
            $table->timestamps();
            $table->index(['status', 'waiting_since']);
        });

        Schema::create('agent_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_chat_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10); // supplier | hermes
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_chat_messages');
        Schema::dropIfExists('agent_chats');
    }
};
