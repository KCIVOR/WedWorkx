<?php

namespace App\Http\Controllers;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Services\InviteExpiryService;
use App\Services\PaymentService;
use App\Services\WebsiteDetailsService;
use App\Support\WebsiteQuestions;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** "My Website": the supplier's private status page, reached with the token from their emails. */
class MyWebsiteController extends Controller
{
    public function show(string $token, InviteExpiryService $expiry, PaymentService $payments, WebsiteDetailsService $details): Response
    {
        // An unknown token is a plain 404 and reveals nothing about any supplier.
        $supplier = Supplier::where('invite_token', $token)
            ->with(['campaign', 'websiteAnswer', 'order', 'uploads'])
            ->firstOrFail();

        $status = $expiry->effectiveStatus($supplier);
        $order = $supplier->order;
        $paid = $order && in_array($status, [SupplierStatus::Paid, SupplierStatus::Building, SupplierStatus::Delivered], true);
        $tz = config('app.timezone');
        $fmt = fn ($d) => $d?->timezone($tz)->format('M j, Y · g:i A');

        $props = [
            'token' => $token,
            'firstName' => explode(' ', trim($supplier->name))[0],
            'businessName' => $supplier->business_name,
            'status' => $status->value,
            'slot' => ['number' => $supplier->slot_number, 'total' => $supplier->campaign->slot_limit],
            'deadline' => $fmt($supplier->invite_expires_at),
            'expiresAt' => $supplier->invite_expires_at?->toIso8601String(),
            'serverNow' => now()->toIso8601String(),
            'detailsSubmitted' => (bool) $supplier->websiteAnswer?->submitted_at,
            'canEditDetails' => $status !== SupplierStatus::Expired && $details->editable($supplier),
            'payment' => [
                'price' => (float) $supplier->campaign->price,
                'canPay' => $status !== SupplierStatus::Expired && $payments->canPay($supplier),
                'pending' => $status === SupplierStatus::PaymentPending,
                'lastFailed' => $status === SupplierStatus::DetailsSubmitted
                    && $supplier->payments()->latest('id')->value('status') === 'failed',
            ],
            'order' => null,
            'review' => null,
        ];

        // Paid order details and locked answers; internal production stages stay private.
        if ($paid) {
            $props['order'] = [
                'reference' => $order->reference,
                'amount' => (float) ($supplier->payments()->where('status', 'completed')->latest('id')->value('amount') ?? $supplier->campaign->price),
                'paidOn' => $fmt($order->paid_at),
                'websiteUrl' => $order->website_url,
                'deliveredOn' => $fmt($order->delivered_at),
            ];

            $disk = Storage::disk(config('uploads.disk'));
            $props['review'] = [
                'questions' => WebsiteQuestions::forAnswer($supplier->websiteAnswer, $supplier),
                'answers' => $supplier->websiteAnswer?->answers ?? (object) [],
                'uploads' => $supplier->uploads->map(fn ($u) => ['id' => $u->id, 'kind' => $u->kind, 'name' => $u->original_name, 'url' => $disk->url($u->path)])->values(),
            ];
        }

        return Inertia::render('MyWebsite', $props);
    }
}
