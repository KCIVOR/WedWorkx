<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reword the live Bot 1 greeting and the campaign name from "10-10-10 Campaign" to Kasalang Tagaytay's
 * 10th Anniversary. Each row is changed only while it still has the original wording, so anything an
 * admin has already edited is left alone.
 */
return new class extends Migration
{
    private const OLD_GREETING = "Hello! Welcome to our 10\u{2011}10\u{2011}10 Campaign! We're celebrating Kasalang Tagaytay's 10th anniversary with a special website offer for wedding suppliers. I'd love to know more about your business. Let's get started! 🎉\n\nMay I know your name?";
    private const NEW_GREETING = "Hello! Welcome to WedWorx's celebration of Kasalang Tagaytay's 10th Anniversary! We have a special website offer for wedding suppliers, and I'd love to know more about your business. Let's get started! 🎉\n\nMay I know your name?";
    private const OLD_NAME = 'Kasalang Tagaytay 10-10-10';
    private const NEW_NAME = 'Kasalang Tagaytay 10th Anniversary';

    public function up(): void
    {
        $this->swap(self::OLD_GREETING, self::NEW_GREETING, self::OLD_NAME, self::NEW_NAME);
    }

    public function down(): void
    {
        $this->swap(self::NEW_GREETING, self::OLD_GREETING, self::NEW_NAME, self::OLD_NAME);
    }

    private function swap(string $fromGreeting, string $toGreeting, string $fromName, string $toName): void
    {
        DB::transaction(function () use ($fromGreeting, $toGreeting, $fromName, $toName) {
            DB::table('registration_questions')->where('key', 'name')->where('text', $fromGreeting)
                ->update(['text' => $toGreeting, 'updated_at' => now()]);
            DB::table('campaigns')->where('name', $fromName)->update(['name' => $toName, 'updated_at' => now()]);
        });
    }
};
