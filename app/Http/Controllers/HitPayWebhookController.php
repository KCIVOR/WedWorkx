<?php

namespace App\Http\Controllers;

use App\Services\HitPayService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HitPayWebhookController extends Controller
{
    public function __invoke(Request $request, HitPayService $hitpay, PaymentService $payments): JsonResponse
    {
        // Bad or missing signature: 401 and nothing else happens (rule 7).
        if (! $hitpay->verify($request)) {
            Log::warning('HitPay webhook rejected: invalid signature', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $outcome = $payments->handleWebhook($hitpay->normalize($request));

        return response()->json(['result' => $outcome]);
    }
}
