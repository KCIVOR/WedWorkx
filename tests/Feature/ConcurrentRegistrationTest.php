<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Supplier;
use Database\Seeders\CampaignSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Real parallel processes against the real (test) MySQL database, so the row lock is actually exercised.
 * DatabaseMigrations (not RefreshDatabase) because the children must see committed data.
 */
class ConcurrentRegistrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_submissions_never_duplicate_or_skip_a_slot(): void
    {
        $this->seed(CampaignSeeder::class);
        $workers = 16; // 10 slots + 6 waitlist
        $startAt = microtime(true) + 4.0; // time for every child to boot

        $procs = [];
        for ($i = 1; $i <= $workers; $i++) {
            $cmd = [PHP_BINARY, base_path('tests/Support/register_worker.php'), (string) $i, (string) $startAt];
            $env = array_merge(getenv(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database')]);
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $procs[$i] = [$proc, $pipes];
        }

        $outputs = [];
        foreach ($procs as $i => [$proc, $pipes]) {
            $outputs[$i] = [stream_get_contents($pipes[1]), stream_get_contents($pipes[2])];
            proc_close($proc);
        }

        foreach ($outputs as $i => [$out, $err]) {
            $this->assertJson($out, "worker $i failed: $err");
        }

        $slots = Supplier::whereNotNull('slot_number')->orderBy('slot_number')->pluck('slot_number')->all();
        $waitlist = Supplier::whereNotNull('waitlist_number')->orderBy('waitlist_number')->pluck('waitlist_number')->all();

        $this->assertSame(range(1, 10), $slots, 'slots must be exactly 1..10 with no duplicates or gaps');
        $this->assertSame(range(1, 6), $waitlist);
        $this->assertSame(16, Campaign::first()->slots_taken);
        $this->assertSame(16, Supplier::count());
    }
}
