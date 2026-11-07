<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PaymentHistoryController extends Controller
{
    private const STATUSES = [
        'pending' => 'Pending',
        'completed' => 'Paid',
        'failed' => 'Failed',
        'expired' => 'Link expired',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', Rule::in(array_keys(self::STATUSES))],
            'date_from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'string', 'date_format:Y-m-d', Rule::when(
                is_string($request->input('date_from')) && $request->input('date_from') !== '',
                ['after_or_equal:date_from'],
            )],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = [];
        foreach (['q', 'status', 'date_from', 'date_to'] as $name) {
            $filters[$name] = $validated[$name] ?? '';
        }
        $filters['q'] = trim($filters['q']);
        $serverNow = now();

        $query = Payment::query()->with('supplier:id,name,business_name');
        if ($filters['q'] !== '') {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(function ($query) use ($like) {
                $query->where('hitpay_request_id', 'like', $like)
                    ->orWhereHas('supplier', fn ($supplier) => $supplier
                        ->where(fn ($names) => $names->where('name', 'like', $like)
                            ->orWhere('business_name', 'like', $like)->orWhere('email', 'like', $like)));
            });
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['date_from'] !== '') {
            $query->where('started_at', '>=', Carbon::createFromFormat('!Y-m-d', $filters['date_from'], config('app.timezone')));
        }
        if ($filters['date_to'] !== '') {
            $query->where('started_at', '<', Carbon::createFromFormat('!Y-m-d', $filters['date_to'], config('app.timezone'))->addDay());
        }
        $page = $query->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(config('admin.per_page', 25), ['*'], 'page', (int) ($validated['page'] ?? 1));

        if ($page->currentPage() > $page->lastPage()) {
            return redirect()->route('admin.payments', [
                ...array_filter($filters, fn ($value) => $value !== ''),
                'page' => $page->lastPage(),
            ]);
        }

        return Inertia::render('Admin/Payments', [
            'payments' => [
                'data' => $page->getCollection()->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'supplier' => $payment->supplier ? [
                        'id' => $payment->supplier->id,
                        'name' => $payment->supplier->name,
                        'business_name' => $payment->supplier->business_name,
                    ] : null,
                    'request_id' => $payment->hitpay_request_id,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'started_at' => $payment->started_at->toIso8601String(),
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'expires_at' => $payment->expires_at->toIso8601String(),
                    'late' => (bool) $payment->paid_late,
                    'simulated' => str_starts_with($payment->hitpay_request_id, 'dev_'),
                    'awaiting_expiry_update' => $payment->status === 'pending' && $payment->expires_at->lte($serverNow),
                ])->values(),
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'filters' => $filters,
            'options' => ['statuses' => collect(self::STATUSES)->map(fn ($label, $value) => compact('value', 'label'))->values()],
            'server_now' => $serverNow->toIso8601String(),
        ]);
    }
}
