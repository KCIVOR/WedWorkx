<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplierStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\Payment;
use App\Models\Supplier;
use App\Support\AdminPresenter;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $campaign = Campaign::first();

        $counts = Supplier::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $statuses = collect(SupplierStatus::cases())->map(fn ($s) => [
            'value' => $s->value,
            'label' => AdminPresenter::STATUS[$s->value][0],
            'tone' => AdminPresenter::STATUS[$s->value][1],
            'count' => (int) ($counts[$s->value] ?? 0),
        ])->values();

        $completed = Payment::where('status', 'completed')->with('supplier.order')->get();
        $applied = $completed->filter(fn ($p) => $p->supplier->order !== null);
        $review = $completed->filter(fn ($p) => $p->supplier->order === null);

        $soonLimit = now()->addHours(2);
        $expiringSoon = Supplier::whereIn('status', ['invited', 'details_submitted', 'payment_pending'])
            ->whereNotNull('invite_expires_at')->where('invite_expires_at', '>', now())->where('invite_expires_at', '<=', $soonLimit)
            ->orderBy('invite_expires_at')->get(['id', 'business_name', 'invite_expires_at']);

        return Inertia::render('Admin/Dashboard', [
            'campaign' => $campaign ? [
                'name' => $campaign->name,
                'slot_limit' => $campaign->slot_limit,
                // Slots used are the approved winners; registrations waiting for review do not take one.
                'slots_taken' => Supplier::whereNotNull('slot_number')->count(),
                'price' => (float) $campaign->price,
            ] : null,
            'waitlist_count' => (int) ($counts['waitlisted'] ?? 0),
            'registered' => (int) $counts->sum(),
            'statuses' => $statuses,
            'money' => [
                'collected' => (float) $applied->sum('amount'),
                'orders' => $applied->count(),
            ],
            'attention' => [
                'refunds' => $review->map(fn ($p) => [
                    'supplier_id' => $p->supplier_id, 'business_name' => $p->supplier->business_name,
                    'amount' => (float) $p->amount, 'late' => (bool) $p->paid_late,
                ])->values(),
                'failed_emails' => EmailLog::where('status', 'failed')->count(),
                'expiring_soon' => $expiringSoon->map(fn ($s) => [
                    'supplier_id' => $s->id, 'business_name' => $s->business_name, 'expires_at' => $s->invite_expires_at->toIso8601String(),
                ])->values(),
            ],
            'now' => now()->toIso8601String(),
        ]);
    }
}
