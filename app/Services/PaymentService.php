<?php

namespace App\Services;

use App\Enums\SupplierStatus;
use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceiptMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StatusHistory;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Payment links and the HitPay webhook outcome (business rules 5 to 10).
 * Lock order is always supplier first, then payment, so the webhook and a new link can never deadlock.
 */
class PaymentService
{
    public const WEBHOOK_COMPLETED = 'completed';
    public const WEBHOOK_LATE_REJECTED = 'late_rejected';
    public const WEBHOOK_ALREADY = 'already_processed';
    public const WEBHOOK_FAILED = 'failed';
    public const WEBHOOK_IGNORED = 'ignored';

    public function __construct(
        private HitPayService $hitpay,
        private InviteExpiryService $expiry,
        private EmailService $emails,
    ) {
    }

    /** Only suppliers who confirmed their details may pay, and only while the invite is valid (rule 6). */
    public function canPay(Supplier $supplier): bool
    {
        return in_array($supplier->status, [SupplierStatus::DetailsSubmitted, SupplierStatus::PaymentPending], true)
            && $supplier->websiteAnswer()->whereNotNull('submitted_at')->exists()
            && $this->expiry->inviteIsValid($supplier);
    }

    /** The live link, if the supplier already has one that has not run out. */
    public function liveLink(Supplier $supplier): ?Payment
    {
        return $supplier->payments()->where('status', 'pending')->where('expires_at', '>', now())->latest('id')->first();
    }

    /**
     * Returns the supplier's live link, or creates a fresh one (an older pending link is then marked expired, rule 5).
     * Throws 403 when the invite is not valid (rule 6).
     */
    public function startPayment(Supplier $supplier): Payment
    {
        $this->authorizePay($supplier);

        if ($live = $this->liveLink($supplier)) {
            return $live;
        }

        $request = $this->hitpay->createPaymentRequest(
            $supplier,
            number_format((float) $supplier->campaign->price, 2, '.', ''),
            url("/invite/{$supplier->invite_token}/payment/return"),
            url('/webhooks/hitpay'),
        );

        return DB::transaction(function () use ($supplier, $request) {
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $this->authorizePay($locked); // re-check: the window may have closed while HitPay was called

            $locked->payments()->where('status', 'pending')->update(['status' => 'expired']);

            $now = now();
            $payment = $locked->payments()->create([
                'hitpay_request_id' => $request['id'],
                'amount' => $locked->campaign->price,
                'checkout_url' => $request['url'],
                'status' => 'pending',
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes(config('hitpay.link_minutes')),
            ]);

            if ($locked->status === SupplierStatus::DetailsSubmitted) {
                $this->transition($locked, SupplierStatus::PaymentPending, 'supplier');
            }

            return $payment;
        });
    }

    private function authorizePay(Supplier $supplier): void
    {
        if (! $this->canPay($supplier)) {
            throw new HttpException(403, 'Payment is not available for this invitation.');
        }
    }

    /**
     * Applies a verified webhook. Safe to call any number of times with the same payload (rule 8).
     *
     * @param  array{request_id:?string,status:string,amount:?string,raw:array}  $event
     */
    public function handleWebhook(array $event): string
    {
        if (! $event['request_id'] || $event['status'] === 'ignored') {
            return self::WEBHOOK_IGNORED;
        }

        $payment = Payment::where('hitpay_request_id', $event['request_id'])->first();
        if (! $payment) {
            Log::warning('HitPay webhook for an unknown payment request', ['request_id' => $event['request_id']]);

            return self::WEBHOOK_IGNORED;
        }

        $receipt = null;
        $failedFor = null;

        $outcome = DB::transaction(function () use ($payment, $event, &$receipt, &$failedFor) {
            $supplier = Supplier::with('campaign')->whereKey($payment->supplier_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'completed') {
                return self::WEBHOOK_ALREADY;
            }

            if ($event['amount'] !== null && abs((float) $event['amount'] - (float) $payment->amount) > 0.001) {
                Log::error('HitPay webhook amount does not match the payment', ['payment_id' => $payment->id, 'amount' => $event['amount']]);

                return self::WEBHOOK_IGNORED;
            }

            return $event['status'] === 'completed'
                ? $this->applyCompleted($supplier, $payment, $event, $receipt)
                : $this->applyFailed($supplier, $payment, $event, $failedFor);
        });

        // Emails go out after the transaction has committed. EmailService never throws.
        if ($receipt) {
            $this->emails->send($receipt->supplier, 'receipt', new PaymentReceiptMail($receipt));
        }
        if ($failedFor) {
            $this->emails->send($failedFor, 'failed', new PaymentFailedMail($failedFor));
        }

        return $outcome;
    }

    private function applyCompleted(Supplier $supplier, Payment $payment, array $event, ?Order &$receipt): string
    {
        $now = now();
        $deadline = $supplier->invite_expires_at;
        $late = $deadline !== null && $now->gt($deadline);
        $graceEnds = $deadline?->copy()->addMinutes($supplier->campaign->grace_minutes);

        // Rule 9 (D6): late is fine only when the payment was started before the deadline and lands inside the grace window.
        $withinRules = ! $late || ($payment->started_at->lt($deadline) && $now->lte($graceEnds));
        $payable = in_array($supplier->status, [SupplierStatus::DetailsSubmitted, SupplierStatus::PaymentPending], true);

        $payment->update([
            'status' => 'completed',
            'paid_at' => $now,
            'paid_late' => $late,
            'webhook_payload' => $event['raw'],
        ]);

        if (! $payable || ! $withinRules) {
            // Money arrived but the order cannot be created. No refund system: flagged in the log only.
            Log::warning('HitPay payment received but not applied', [
                'payment_id' => $payment->id,
                'supplier_id' => $supplier->id,
                'supplier_status' => $supplier->status->value,
                'late' => $late,
                'within_rules' => $withinRules,
            ]);

            return self::WEBHOOK_LATE_REJECTED;
        }

        $this->transition($supplier, SupplierStatus::Paid, 'hitpay');

        $receipt = $this->createOrder($supplier, $now);
        $supplier->websiteAnswer()->update(['locked_at' => $now]); // rule 10: answers are locked after payment
        $receipt->setRelation('supplier', $supplier);

        return self::WEBHOOK_COMPLETED;
    }

    private function applyFailed(Supplier $supplier, Payment $payment, array $event, ?Supplier &$failedFor): string
    {
        // Only a link that is still the live one can fail. A replaced (expired) link is ignored.
        if ($payment->status !== 'pending') {
            return self::WEBHOOK_IGNORED;
        }

        $payment->update(['status' => 'failed', 'webhook_payload' => $event['raw']]);

        if ($supplier->status === SupplierStatus::PaymentPending && ! $supplier->payments()->where('status', 'pending')->exists()) {
            $this->transition($supplier, SupplierStatus::DetailsSubmitted, 'hitpay');
        }

        if ($this->expiry->inviteIsValid($supplier->fresh())) {
            $failedFor = $supplier;
        }

        return self::WEBHOOK_FAILED;
    }

    /** Links that ran out (30 minutes) without a result: payment `expired`, supplier back to details_submitted so they can retry. */
    public function expireStaleLinks(): int
    {
        $count = 0;

        Payment::where('status', 'pending')->where('expires_at', '<=', now())->orderBy('id')->each(function (Payment $p) use (&$count) {
            DB::transaction(function () use ($p, &$count) {
                $supplier = Supplier::whereKey($p->supplier_id)->lockForUpdate()->first();
                $payment = Payment::whereKey($p->id)->lockForUpdate()->first();
                if (! $supplier || ! $payment || $payment->status !== 'pending' || $payment->expires_at->gt(now())) {
                    return;
                }

                $payment->update(['status' => 'expired']);
                $count++;

                if ($supplier->status === SupplierStatus::PaymentPending
                    && ! $supplier->payments()->where('status', 'pending')->exists()
                    && $this->expiry->inviteIsValid($supplier)) {
                    $this->transition($supplier, SupplierStatus::DetailsSubmitted, 'system');
                }
            });
        });

        return $count;
    }

    private function transition(Supplier $supplier, SupplierStatus $to, string $actor): void
    {
        $from = $supplier->status;
        $supplier->update(['status' => $to]);
        StatusHistory::create(['supplier_id' => $supplier->id, 'from_status' => $from, 'to_status' => $to, 'actor' => $actor]);
    }

    /** Creates the order with a unique KT10-xxxx reference (the unique index is the final guard). */
    private function createOrder(Supplier $supplier, $paidAt): Order
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

        for ($try = 0; $try < 20; $try++) {
            $reference = 'KT10-'.collect(range(1, 4))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');

            if (Order::where('reference', $reference)->exists()) {
                continue;
            }

            try {
                return Order::create([
                    'supplier_id' => $supplier->id,
                    'reference' => $reference,
                    'current_stage' => 0,
                    'paid_at' => $paidAt,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) !== '23000' || ! str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not generate a unique order reference.');
    }
}
