<?php

namespace App\Support;

use App\Enums\SupplierStatus;
use App\Models\Supplier;

/** Turns suppliers into the plain arrays the admin pages and the CSV export use. */
final class AdminPresenter
{
    public const STATUS = [
        'pending_review' => ['Pending review', 'warning'],
        'waitlisted' => ['Waitlisted', 'muted'],
        'invited' => ['Invited', 'info'],
        'expired' => ['Expired', 'danger'],
        'details_submitted' => ['Answers confirmed', 'info'],
        'payment_pending' => ['Payment started', 'warning'],
        'paid' => ['Paid', 'success'],
        'building' => ['Building', 'info'],
        'delivered' => ['Delivered', 'success'],
    ];

    public static function statusLabel(SupplierStatus $s): string
    {
        return self::STATUS[$s->value][0];
    }

    /** Eager loads needed by row(). */
    public static function with(): array
    {
        return ['order', 'payments', 'websiteAnswer', 'emailLogs'];
    }

    /** @return array{state:string,amount:?float,paid_at:?string,late:bool,needs_review:bool} */
    public static function payment(Supplier $s): array
    {
        $paid = $s->payments->firstWhere('status', 'completed');
        if ($paid) {
            return [
                'state' => 'paid', 'amount' => (float) $paid->amount, 'paid_at' => $paid->paid_at?->toIso8601String(),
                'late' => (bool) $paid->paid_late,
                // Money arrived but no order exists: a late or repeated payment that needs a manual refund.
                'needs_review' => $s->order === null,
            ];
        }

        $latest = $s->payments->sortByDesc('id')->first();

        return [
            'state' => $latest?->status ?? 'none', 'amount' => $latest ? (float) $latest->amount : null,
            'paid_at' => null, 'late' => false, 'needs_review' => false,
        ];
    }

    public static function row(Supplier $s): array
    {
        $payment = self::payment($s);

        return [
            'id' => $s->id,
            'slot' => $s->slot_number,
            'waitlist' => $s->waitlist_number,
            'name' => $s->name,
            'email' => $s->email,
            'mobile' => $s->mobile,
            'business_name' => $s->business_name,
            'business_type' => BusinessTypes::ALL[$s->business_type] ?? $s->business_type,
            'city' => $s->city,
            'province' => $s->province,
            'social_link' => $s->social_link,
            'status' => $s->status->value,
            'status_label' => self::STATUS[$s->status->value][0],
            'status_tone' => self::STATUS[$s->status->value][1],
            'registered_at' => $s->created_at?->toIso8601String(),
            'invite_expires_at' => $s->invite_expires_at?->toIso8601String(),
            'answers_confirmed_at' => $s->websiteAnswer?->submitted_at?->toIso8601String(),
            'payment' => $payment,
            'reference' => $s->order?->reference,
            'stage' => $s->order?->current_stage,
            'website_url' => $s->order?->website_url,
            'emails_sent' => $s->emailLogs->where('status', 'sent')->count(),
            'emails_failed' => $s->emailLogs->where('status', 'failed')->count(),
        ];
    }
}
