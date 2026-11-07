<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class PaymentController extends Controller
{
    /** "Pay" and "Try again": returns the HitPay checkout link (the live one, or a fresh one). */
    public function start(string $token, PaymentService $payments): JsonResponse
    {
        $supplier = Supplier::where('invite_token', $token)->with('campaign')->firstOrFail();

        try {
            $payment = $payments->startPayment($supplier);
        } catch (Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $e; // 403: the invitation is not payable
            }
            report($e);

            return response()->json(['message' => 'We could not start the payment right now. Please try again in a moment.'], 502);
        }

        return response()->json(['url' => $payment->checkout_url]);
    }

    /**
     * Where HitPay sends the browser afterwards. It only shows status: anyone can open this URL, so it never
     * marks anything paid. Payment is confirmed only by the signed webhook (rule 7).
     */
    public function back(string $token): RedirectResponse
    {
        Supplier::where('invite_token', $token)->firstOrFail();

        return redirect()->route('invite.my-website', $token);
    }
}
