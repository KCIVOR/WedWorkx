<?php

namespace App\Http\Controllers;

use App\Enums\SupplierStatus;
use App\Exceptions\HermesException;
use App\Models\Payment;
use App\Models\Supplier;
use App\Services\HermesOrderService;
use App\Services\InviteExpiryService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dev mode only (see EnsureDevMode). A stand-in for the outside world, going through the very same
 * services the real HitPay webhook, Hermes API and scheduler use, so every rule still applies.
 */
class DevController extends Controller
{
    /** The simulated HitPay checkout page that "Pay" opens in dev mode. */
    public function checkout(string $requestId): Response
    {
        $payment = $this->devPayment($requestId);
        $supplier = $payment->supplier;

        return Inertia::render('DevCheckout', [
            'requestId' => $requestId,
            'businessName' => $supplier->business_name,
            'amount' => (float) $payment->amount,
            'status' => $payment->status,
            'myWebsite' => route('invite.my-website', $supplier->invite_token),
        ]);
    }

    /** "Pay" or "fail" on the simulated checkout: the same code path as a verified HitPay webhook. */
    public function complete(string $requestId, string $outcome, PaymentService $payments): RedirectResponse
    {
        abort_unless(in_array($outcome, ['completed', 'failed'], true), 404);
        $payment = $this->devPayment($requestId);

        $payments->handleWebhook([
            'request_id' => $requestId,
            'status' => $outcome,
            'amount' => (string) $payment->amount,
            'raw' => ['dev_simulated' => true, 'status' => $outcome, 'payment_request_id' => $requestId],
        ]);

        return redirect()->route('invite.my-website', $payment->supplier->invite_token);
    }

    /** Hermes stand-in: the next production stage, and on stage 6 the delivery. Same rules as the Hermes API. */
    public function advance(string $token, HermesOrderService $hermes): RedirectResponse
    {
        $order = $this->supplier($token)->order;
        abort_unless($order, 409, 'Nothing to build yet: this supplier has not paid.');

        try {
            if ($order->current_stage < HermesOrderService::LAST_STAGE) {
                $hermes->setStage($order->reference, $order->current_stage + 1, 'Dev mode: advanced by hand');
            } else {
                $hermes->deliver($order->reference, 'https://dev.example/'.strtolower($order->reference));
            }
        } catch (HermesException $e) {
            return back()->with('dev_message', $e->getMessage());
        }

        return redirect()->route('invite.my-website', $token);
    }

    /** Scheduler stand-in: make the invitation lapse right now and run the expiry job. */
    public function expire(string $token, InviteExpiryService $expiry): RedirectResponse
    {
        $supplier = $this->supplier($token);

        if (in_array($supplier->status, InviteExpiryService::UNPAID, true)) {
            $supplier->update(['invite_expires_at' => now()->subMinute(), 'invited_at' => now()->subHours($supplier->campaign->invite_expiry_hours)->subMinute()]);
            $expiry->expireLapsed();
        }

        return redirect()->route('invite.my-website', $token);
    }

    /** Scheduler stand-in: move the deadline to 90 minutes away and send the reminder email (once). */
    public function remind(string $token, InviteExpiryService $expiry): RedirectResponse
    {
        $supplier = $this->supplier($token);

        if (in_array($supplier->status, InviteExpiryService::UNPAID, true)) {
            $supplier->update(['invite_expires_at' => now()->addMinutes(90), 'invited_at' => now()->addMinutes(90)->subHours($supplier->campaign->invite_expiry_hours)]);
            $expiry->sendReminders();
        }

        return redirect()->route('invite.my-website', $token);
    }

    private function supplier(string $token): Supplier
    {
        return Supplier::where('invite_token', $token)->with(['campaign', 'order'])->firstOrFail();
    }

    /** Only simulated payments can be completed here, never a real HitPay one. */
    private function devPayment(string $requestId): Payment
    {
        abort_unless(str_starts_with($requestId, 'dev_'), 404);

        return Payment::where('hitpay_request_id', $requestId)->with('supplier')->firstOrFail();
    }
}
