<?php

namespace App\Services;

use App\Enums\SupplierStatus;
use App\Mail\InvitationExpiredMail;
use App\Mail\PaymentReminderMail;
use App\Models\StatusHistory;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Invite window rules (business rules 4, 6, 9). Slots are never freed or re-assigned (D9):
 * expiring a supplier only changes their own status.
 */
class InviteExpiryService
{
    /** Statuses in which a supplier is still working towards payment. */
    public const UNPAID = [
        SupplierStatus::Invited,
        SupplierStatus::DetailsSubmitted,
        SupplierStatus::PaymentPending,
    ];

    public function __construct(private EmailService $emails)
    {
    }

    /**
     * D6: a payment started before the deadline and still pending may land up to `grace_minutes`
     * after the deadline, so that supplier must not be expired yet.
     */
    public function isGraceProtected(Supplier $supplier, ?Carbon $now = null): bool
    {
        $now ??= now();
        if (! $supplier->invite_expires_at) {
            return false;
        }

        $graceEnds = $supplier->invite_expires_at->copy()->addMinutes($supplier->campaign->grace_minutes);

        return $now->lt($graceEnds)
            && $supplier->payments()
                ->where('status', 'pending')
                ->where('started_at', '<', $supplier->invite_expires_at)
                ->exists();
    }

    /** The deadline has passed for an unpaid supplier and nothing protects them. */
    public function hasLapsed(Supplier $supplier, ?Carbon $now = null): bool
    {
        $now ??= now();

        return in_array($supplier->status, self::UNPAID, true)
            && $supplier->invite_expires_at !== null
            && $now->gte($supplier->invite_expires_at)
            && ! $this->isGraceProtected($supplier, $now);
    }

    /** Status to show and enforce: `expired` as soon as the window is over, even before the scheduler runs. */
    public function effectiveStatus(Supplier $supplier): SupplierStatus
    {
        return $supplier->status === SupplierStatus::Expired || $this->hasLapsed($supplier)
            ? SupplierStatus::Expired
            : $supplier->status;
    }

    /** True while the invite window is open: the supplier may continue the chat and may start a payment (rule 6). */
    public function inviteIsValid(Supplier $supplier, ?Carbon $now = null): bool
    {
        $now ??= now();

        return in_array($supplier->status, self::UNPAID, true)
            && $supplier->invite_expires_at !== null
            && $now->lt($supplier->invite_expires_at);
    }

    /** Expires every lapsed supplier and sends the expired email once. @return int how many were expired */
    public function expireLapsed(): int
    {
        $expired = 0;

        Supplier::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, self::UNPAID))
            ->where('invite_expires_at', '<=', now())
            ->with('campaign')
            ->orderBy('id')
            ->chunkById(100, function ($suppliers) use (&$expired) {
                foreach ($suppliers as $candidate) {
                    $supplier = DB::transaction(function () use ($candidate) {
                        // Lock the row and re-check, so a payment or another run cannot race us.
                        $supplier = Supplier::whereKey($candidate->id)->lockForUpdate()->first();
                        if (! $supplier || ! $this->hasLapsed($supplier)) {
                            return null;
                        }

                        $from = $supplier->status;
                        $supplier->update(['status' => SupplierStatus::Expired]);
                        StatusHistory::create([
                            'supplier_id' => $supplier->id,
                            'from_status' => $from,
                            'to_status' => SupplierStatus::Expired,
                            'actor' => 'system',
                        ]);

                        return $supplier;
                    });

                    if ($supplier) {
                        $expired++;
                        $this->emails->send($supplier, 'expired', new InvitationExpiredMail($supplier));
                    }
                }
            });

        return $expired;
    }

    /** Sends the reminder (once) to unpaid suppliers whose deadline is within the reminder window. @return int emails sent now */
    public function sendReminders(): int
    {
        $sent = 0;
        $now = now();

        Supplier::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, self::UNPAID))
            ->where('invite_expires_at', '>', $now)
            ->where('invite_expires_at', '<=', $now->copy()->addHours(config('campaign.reminder_hours_before')))
            ->with('campaign')
            ->orderBy('id')
            ->chunkById(100, function ($suppliers) use (&$sent) {
                foreach ($suppliers as $supplier) {
                    if ($this->emails->send($supplier, 'reminder', new PaymentReminderMail($supplier))) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
