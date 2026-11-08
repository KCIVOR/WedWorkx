<?php

namespace App\Http\Controllers;

use App\Enums\SupplierStatus;
use App\Http\Requests\ConfirmWebsiteAnswersRequest;
use App\Models\Supplier;
use App\Models\Upload;
use App\Services\InviteExpiryService;
use App\Services\PaymentService;
use App\Services\WebsiteDetailsService;
use App\Support\WebsiteQuestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** The private page behind the link in the invitation email: Chatbot 2, or the supplier's status. */
class InviteController extends Controller
{
    public function show(string $token, InviteExpiryService $expiry, WebsiteDetailsService $details, PaymentService $payments): Response|RedirectResponse
    {
        // Unknown tokens get a plain 404 that reveals nothing about any supplier.
        $supplier = Supplier::where('invite_token', $token)->with(['campaign', 'websiteAnswer', 'order'])->firstOrFail();

        $status = $expiry->effectiveStatus($supplier);

        // Once a payment is under way or done there is nothing to fill in: My Website shows the rest.
        if (in_array($status, [SupplierStatus::PaymentPending, SupplierStatus::Paid, SupplierStatus::Building, SupplierStatus::Delivered], true)) {
            return redirect()->route('invite.my-website', $token);
        }

        $base = [
            'token' => $token,
            'firstName' => explode(' ', trim($supplier->name))[0],
            'businessName' => $supplier->business_name,
            'status' => $status->value,
            'deadline' => $supplier->invite_expires_at?->timezone(config('app.timezone'))->format('M j, Y · g:i A'),
            'chat' => null,
            'expiresAt' => $supplier->invite_expires_at?->toIso8601String(),
            'serverNow' => now()->toIso8601String(),
            'payment' => [
                'price' => (float) $supplier->campaign->price,
                'canPay' => $status !== SupplierStatus::Expired && $payments->canPay($supplier),
                'pending' => $status === SupplierStatus::PaymentPending,
                'lastFailed' => $status === SupplierStatus::DetailsSubmitted
                    && $supplier->payments()->latest('id')->value('status') === 'failed',
            ],
        ];

        if ($status === SupplierStatus::Expired || ! $details->editable($supplier)) {
            return Inertia::render('Invite', $base);
        }

        return Inertia::render('Invite', array_merge($base, ['chat' => [
            'registrationContacts' => ['email' => $supplier->email, 'phone' => $supplier->mobile, 'socials' => $supplier->social_link, 'location' => trim($supplier->city.', '.$supplier->province, ', ')],
            'token' => $token,
            'ai' => ['enabled' => (bool) config('ai.enabled')],
            'agent' => [
                'enabled' => app(\App\Services\AgentChatService::class)->mode('website') === 'hermes',
                'resume' => app(\App\Services\AgentChatService::class)->resumable('website', $supplier) !== null,
            ],
            'questions' => WebsiteQuestions::forAnswer($supplier->websiteAnswer?->submitted_at ? $supplier->websiteAnswer : null, $supplier),
            'saved' => $supplier->websiteAnswer?->submitted_at ? $supplier->websiteAnswer->answers : null,
            'defaultArea' => $supplier->city,
            'uploads' => $supplier->uploads()->orderBy('id')->get()
                ->map(fn (Upload $u) => $this->uploadPayload($u))->all(),
            'limits' => [
                'maxKb' => config('uploads.max_kb'),
                'maxLogo' => config('uploads.max_logo'),
                'maxPhotos' => config('uploads.max_photos'),
                'extensions' => config('uploads.extensions'),
                'mimetypes' => config('uploads.mimetypes'),
            ],
            'expiresAt' => $supplier->invite_expires_at->toIso8601String(),
            'serverNow' => now()->toIso8601String(),
            'slot' => ['number' => $supplier->slot_number, 'total' => $supplier->campaign->slot_limit],
        ]]));
    }

    public function confirm(string $token, ConfirmWebsiteAnswersRequest $request, WebsiteDetailsService $details): RedirectResponse
    {
        $supplier = $request->supplier();
        $details->authorizeEdit($supplier);

        $details->confirm($supplier, $request->answers());
        \App\Models\AgentChat::where('bot', 'website')->where('supplier_id', $supplier->id)
            ->whereIn('status', ['open', 'review'])->update(['status' => 'done', 'waiting_since' => null]);

        return redirect()->route('invite.show', $token);
    }

    public static function uploadPayload(Upload $u): array
    {
        return [
            'id' => $u->id,
            'kind' => $u->kind,
            'name' => $u->original_name,
            'url' => Storage::disk(config('uploads.disk'))->url($u->path),
        ];
    }
}
