<?php

namespace App\Services;

use App\Enums\SupplierStatus;
use App\Mail\InvitationMail;
use App\Mail\RegistrationReceivedMail;
use App\Mail\WaitlistMail;
use App\Models\EmailLog;
use App\Models\Supplier;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The only way emails leave the app (business rule 14).
 *
 * - One email of each type per supplier (the `failed` type may repeat).
 * - Every attempt is logged in email_logs: `sent` or `failed`.
 * - A failed send never throws to the caller. A log row left as `failed` may be retried later;
 *   a `sent` row never is.
 */
class EmailService
{
    public const SENT = 'sent';
    public const FAILED = 'failed';
    private const SENDING = 'sending';

    /** The acknowledgement when Bot 1 is finished. Never says whether the supplier is a winner. */
    public function sendRegistrationReceived(Supplier $supplier): bool
    {
        return $this->send($supplier, 'registered', new RegistrationReceivedMail($supplier));
    }

    /** Sends the right email right after SlotService assigned a status. */
    public function sendRegistrationOutcome(Supplier $supplier): void
    {
        match ($supplier->status) {
            SupplierStatus::Invited => $this->send($supplier, 'invite', new InvitationMail($supplier)),
            SupplierStatus::Waitlisted => $this->send($supplier, 'waitlist', new WaitlistMail($supplier)),
            default => null,
        };
    }

    /** @return bool true when an email went out now, false when skipped or failed */
    public function send(Supplier $supplier, string $type, Mailable $mailable): bool
    {
        $logId = $this->claim($supplier, $type);

        if ($logId === null) {
            return false; // already sent (or being sent) for this supplier and type
        }

        try {
            Mail::to($supplier->email, $supplier->name)->send($mailable);
            $status = self::SENT;
        } catch (Throwable $e) {
            report($e);
            $status = self::FAILED;
        }

        EmailLog::whereKey($logId)->update(['status' => $status, 'sent_at' => now()]);

        return $status === self::SENT;
    }

    /**
     * Reserve the (supplier, type) slot in email_logs before sending, so two concurrent triggers
     * can never both send. Returns the log id, or null when this email must not be sent.
     */
    private function claim(Supplier $supplier, string $type): ?int
    {
        if ($type === 'failed') {
            // Payment-failure emails may repeat, so every attempt gets its own row.
            return EmailLog::create(['supplier_id' => $supplier->id, 'type' => $type, 'status' => self::SENDING, 'sent_at' => now()])->id;
        }

        try {
            return EmailLog::create(['supplier_id' => $supplier->id, 'type' => $type, 'status' => self::SENDING, 'sent_at' => now()])->id;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
        }

        // A row exists. Only a previously failed one may be taken over for a retry.
        $row = EmailLog::where('supplier_id', $supplier->id)->where('type', $type)->first();
        if ($row && EmailLog::whereKey($row->id)->where('status', self::FAILED)->update(['status' => self::SENDING]) === 1) {
            return $row->id;
        }

        return null;
    }
}
