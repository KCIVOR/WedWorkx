<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Admin approvals of winners are recorded in the status history with the actor "admin". */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE status_history MODIFY actor ENUM('supplier','system','hitpay','hermes','admin') NOT NULL");
    }

    public function down(): void
    {
        DB::table('status_history')->where('actor', 'admin')->update(['actor' => 'system']);
        DB::statement("ALTER TABLE status_history MODIFY actor ENUM('supplier','system','hitpay','hermes') NOT NULL");
    }
};
