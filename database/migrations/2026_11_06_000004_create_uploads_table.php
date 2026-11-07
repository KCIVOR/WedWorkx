<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['logo', 'photo']);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_kb');
            $table->timestamps();

            $table->index(['supplier_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
