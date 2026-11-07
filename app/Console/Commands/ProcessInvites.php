<?php

namespace App\Console\Commands;

use App\Services\InviteExpiryService;
use App\Services\PaymentService;
use Illuminate\Console\Command;

class ProcessInvites extends Command
{
    protected $signature = 'invites:process';

    protected $description = 'Expire stale payment links and lapsed invitations, and send payment reminders';

    public function handle(InviteExpiryService $invites, PaymentService $payments): int
    {
        $links = $payments->expireStaleLinks();
        $expired = $invites->expireLapsed();
        $reminders = $invites->sendReminders();

        $this->info("Expired {$links} payment link(s), {$expired} invitation(s), sent {$reminders} reminder(s).");

        return self::SUCCESS;
    }
}
