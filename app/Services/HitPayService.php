<?php

namespace App\Services;

use App\Models\Supplier;
use App\Support\DevMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Talks to HitPay: creates payment requests and verifies webhook signatures. */
class HitPayService
{
    /** @return array{id:string,url:string} */
    public function createPaymentRequest(Supplier $supplier, string $amount, string $redirectUrl, string $webhookUrl): array
    {
        // Dev mode: no call to HitPay. "Pay" opens a simulated checkout page that uses the same webhook code path.
        if (DevMode::enabled()) {
            $id = 'dev_'.Str::lower(Str::random(12));

            return ['id' => $id, 'url' => url('/dev/checkout/'.$id)];
        }

        $key = config('hitpay.api_key');
        if (! $key) {
            throw new RuntimeException('HitPay is not configured (HITPAY_API_KEY is empty).');
        }

        $response = Http::asForm()
            ->withHeaders(['X-BUSINESS-API-KEY' => $key, 'X-Requested-With' => 'XMLHttpRequest'])
            ->timeout(config('hitpay.timeout'))
            ->post(config('hitpay.base_url').'/payment-requests', [
                'amount' => $amount,
                'currency' => config('hitpay.currency'),
                'email' => $supplier->email,
                'name' => $supplier->name,
                'purpose' => 'WedWorks 10-10-10 website package: '.$supplier->business_name,
                'reference_number' => 'S'.$supplier->id.'-'.$supplier->slot_number,
                'redirect_url' => $redirectUrl,
                'webhook' => $webhookUrl,
                'send_email' => 'false',
                'allow_repeated_payments' => 'false',
            ]);

        $json = $response->json();
        if (! $response->successful() || empty($json['id']) || empty($json['url'])) {
            throw new RuntimeException('HitPay could not create the payment request (HTTP '.$response->status().').');
        }

        return ['id' => (string) $json['id'], 'url' => (string) $json['url']];
    }

    /**
     * Verifies the webhook signature with the salt (business rule 7). Two HitPay formats exist:
     *  - JSON body + `Hitpay-Signature` header: HMAC-SHA256 of the raw body.
     *  - Form body with an `hmac` field: HMAC-SHA256 of the other fields, sorted by key, as key+value pairs.
     */
    public function verify(Request $request): bool
    {
        $salt = config('hitpay.salt');
        if (! $salt) {
            return false; // never accept anything when no salt is configured
        }

        $header = $request->header('Hitpay-Signature');
        if ($header) {
            return hash_equals(hash_hmac('sha256', $request->getContent(), $salt), strtolower(trim($header)));
        }

        $data = $this->formFields($request);
        $given = $data['hmac'] ?? null;
        if (! is_string($given) || $given === '') {
            return false;
        }

        unset($data['hmac']);
        ksort($data);
        $message = '';
        foreach ($data as $k => $v) {
            $message .= $k.(is_null($v) ? '' : (is_scalar($v) ? $v : json_encode($v)));
        }

        return hash_equals(hash_hmac('sha256', $message, $salt), strtolower($given));
    }

    /**
     * Reads the fields we need from either webhook format.
     *
     * @return array{request_id:?string,status:string,amount:?string,raw:array}
     */
    public function normalize(Request $request): array
    {
        $data = $request->isJson() ? $request->json()->all() : $this->formFields($request);
        unset($data['hmac']);

        $status = strtolower((string) ($data['status'] ?? ''));
        $status = match (true) {
            in_array($status, ['completed', 'succeeded', 'paid'], true) => 'completed',
            in_array($status, ['failed', 'canceled', 'cancelled'], true) => 'failed',
            default => 'ignored',
        };

        $id = $data['payment_request_id'] ?? data_get($data, 'payment_request.id') ?? $data['id'] ?? null;

        return [
            'request_id' => is_scalar($id) ? (string) $id : null,
            'status' => $status,
            'amount' => isset($data['amount']) && is_scalar($data['amount']) ? (string) $data['amount'] : null,
            'raw' => $data,
        ];
    }

    /**
     * Form fields exactly as HitPay sent them. Laravel's middleware trims strings and turns empty ones into null,
     * which would change what was signed, so the raw body is read when there is one.
     */
    private function formFields(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw !== '') {
            parse_str($raw, $data);

            return $data;
        }

        return $request->post();
    }
}
