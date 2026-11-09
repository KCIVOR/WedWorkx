<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplierStatus;
use App\Http\Controllers\Controller;
use App\Mail\InvitationExpiredMail;
use App\Mail\InvitationMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\PaymentReminderMail;
use App\Mail\WaitlistMail;
use App\Mail\WebsiteLiveMail;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\EmailLog;
use App\Models\Supplier;
use App\Services\EmailService;
use App\Services\SlotService;
use App\Support\AdminPresenter;
use App\Support\BusinessTypes;
use App\Support\WebsiteQuestions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends Controller
{
    /** Email types the admin may resend when the first attempt failed. */
    private const RESENDABLE = ['invite', 'waitlist', 'reminder', 'expired', 'receipt', 'live'];

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $page = $this->query($filters)->with(AdminPresenter::with())->paginate(config('admin.per_page'))->withQueryString();

        return Inertia::render('Admin/Suppliers', [
            'suppliers' => [
                'data' => $page->getCollection()->map(fn ($s) => AdminPresenter::row($s))->values(),
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'filters' => $filters,
            'slots' => [
                'limit' => (int) Campaign::value('slot_limit'),
                'used' => Supplier::whereNotNull('slot_number')->count(),
            ],
            'options' => [
                'statuses' => collect(AdminPresenter::STATUS)->map(fn ($v, $k) => ['value' => $k, 'label' => $v[0]])->values(),
                'types' => collect(BusinessTypes::ALL)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
            ],
        ]);
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['order.stageLogs', 'payments', 'websiteAnswer', 'emailLogs', 'statusHistory', 'uploads']);
        $disk = Storage::disk(config('uploads.disk'));

        return Inertia::render('Admin/SupplierShow', [
            'supplier' => AdminPresenter::row($supplier),
            'answers' => $this->answers($supplier),
            // Answers to the extra registration questions an admin added (each saved with the wording that was asked).
            'registration_answers' => collect($supplier->extra_answers ?? [])->map(fn ($a) => ['label' => $a['label'], 'value' => $a['answer']])->values(),
            'uploads' => $supplier->uploads->map(fn ($u) => [
                'id' => $u->id, 'kind' => $u->kind, 'name' => $u->original_name, 'size_kb' => $u->size_kb, 'url' => $disk->url($u->path),
                'image' => str_starts_with((string) $u->mime, 'image/'),
            ])->values(),
            'timeline' => $supplier->statusHistory->sortBy('created_at')->map(fn ($h) => [
                'from' => $h->from_status ? AdminPresenter::statusLabel($h->from_status) : null,
                'to' => AdminPresenter::statusLabel($h->to_status),
                'actor' => $h->actor,
                'at' => $h->created_at->toIso8601String(),
            ])->values(),
            'payments' => $supplier->payments->sortByDesc('id')->map(fn ($p) => [
                'id' => $p->id, 'status' => $p->status, 'amount' => (float) $p->amount, 'late' => (bool) $p->paid_late,
                'started_at' => $p->started_at->toIso8601String(), 'paid_at' => $p->paid_at?->toIso8601String(),
                'request_id' => $p->hitpay_request_id,
            ])->values(),
            'stages' => $supplier->order?->stageLogs->sortBy('id')->map(fn ($l) => [
                'stage' => $l->stage, 'note' => $l->note, 'at' => $l->created_at->toIso8601String(),
            ])->values() ?? [],
            'emails' => $supplier->emailLogs->sortByDesc('id')->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'status' => $e->status, 'at' => $e->sent_at?->toIso8601String(),
                'can_resend' => $e->status === 'failed' && in_array($e->type, self::RESENDABLE, true) && $this->canResend($supplier, $e->type),
            ])->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        AuditLog::record('export_suppliers', array_filter($filters));

        $columns = ['Slot', 'Waitlist', 'Name', 'Business', 'Type', 'City', 'Province', 'Email', 'Mobile', 'Registered', 'Status',
            'Invite deadline', 'Answers confirmed', 'Payment', 'Amount', 'Paid at', 'Reference', 'Stage', 'Website', 'Emails sent', 'Emails failed'];
        $tz = config('app.timezone');
        $fmt = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->timezone($tz)->format('Y-m-d H:i') : '';

        return response()->streamDownload(function () use ($filters, $columns, $fmt) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the UTF-8 names correctly
            fputcsv($out, $columns);

            $this->query($filters)->with(AdminPresenter::with())->chunk(200, function ($chunk) use ($out, $fmt) {
                foreach ($chunk as $s) {
                    $r = AdminPresenter::row($s);
                    fputcsv($out, array_map([$this, 'safeCell'], [
                        $r['slot'], $r['waitlist'], $r['name'], $r['business_name'], $r['business_type'], $r['city'], $r['province'],
                        $r['email'], $r['mobile'], $fmt($r['registered_at']), $r['status_label'], $fmt($r['invite_expires_at']),
                        $fmt($r['answers_confirmed_at']), $r['payment']['state'], $r['payment']['amount'], $fmt($r['payment']['paid_at']),
                        $r['reference'], $r['stage'], $r['website_url'], $r['emails_sent'], $r['emails_failed'],
                    ]));
                }
            });
            fclose($out);
        }, 'wedworks-suppliers-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Approval guardrail: the admin picks the winners. Each one gets a slot, a fresh deadline and the
     * invitation email with the Bot 2 link. Never more winners than the campaign's slot limit.
     */
    public function approve(Request $request, SlotService $slots, EmailService $emails)
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        $approved = 0;
        $full = false;
        $skipped = 0;

        foreach (Supplier::whereIn('id', $ids)->orderBy('id')->get() as $supplier) {
            $result = $slots->approve($supplier);

            if ($result['outcome'] === SlotService::FULL) {
                $full = true;
                break;
            }
            if ($result['outcome'] !== SlotService::APPROVED) {
                $skipped++;
                continue;
            }

            $approved++;
            $emails->send($result['supplier'], 'invite', new InvitationMail($result['supplier']));
            AuditLog::record('approve_winner', ['supplier_id' => $supplier->id, 'slot' => $result['supplier']->slot_number]);
        }

        $message = $approved === 1 ? '1 winner approved and the Bot 2 link was emailed.' : "$approved winners approved and the Bot 2 link was emailed.";
        if ($full) {
            $message .= ' All '.Campaign::first()->slot_limit.' slots are taken, so the rest were not approved.';
        }
        if ($skipped > 0) {
            $message .= " $skipped could not be approved (already invited or not waiting for review).";
        }

        return back()->with('admin_flash', ['tone' => $approved > 0 && ! $full ? 'success' : 'warning', 'message' => $message]);
    }

    public function resendEmail(Supplier $supplier, EmailLog $emailLog, EmailService $emails)
    {
        abort_unless($emailLog->supplier_id === $supplier->id, 404);

        if ($emailLog->status !== 'failed' || ! in_array($emailLog->type, self::RESENDABLE, true) || ! $this->canResend($supplier, $emailLog->type)) {
            return back()->with('admin_flash', ['tone' => 'warning', 'message' => 'That email cannot be resent.']);
        }

        $supplier->load('order.supplier');
        $mailable = match ($emailLog->type) {
            'invite' => new InvitationMail($supplier),
            'waitlist' => new WaitlistMail($supplier),
            'reminder' => new PaymentReminderMail($supplier),
            'expired' => new InvitationExpiredMail($supplier),
            'receipt' => new PaymentReceiptMail($supplier->order),
            'live' => new WebsiteLiveMail($supplier->order),
        };

        $sent = $emails->send($supplier, $emailLog->type, $mailable);
        AuditLog::record('resend_email', ['supplier_id' => $supplier->id, 'type' => $emailLog->type, 'sent' => $sent]);

        return back()->with('admin_flash', $sent
            ? ['tone' => 'success', 'message' => 'The email was sent again.']
            : ['tone' => 'danger', 'message' => 'The email could not be sent. Check the email settings and try again.']);
    }

    private function canResend(Supplier $supplier, string $type): bool
    {
        return match ($type) {
            'invite', 'reminder' => in_array($supplier->status, [SupplierStatus::Invited, SupplierStatus::DetailsSubmitted, SupplierStatus::PaymentPending], true),
            'waitlist' => $supplier->status === SupplierStatus::Waitlisted,
            'expired' => $supplier->status === SupplierStatus::Expired,
            'receipt', 'live' => $supplier->order !== null,
            default => false,
        };
    }

    /** @return array{q:string,status:string,group:string,type:string} */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'group' => (string) $request->query('group', ''),
            'type' => (string) $request->query('type', ''),
        ];
    }

    private function query(array $f): Builder
    {
        return Supplier::query()
            ->when($f['q'] !== '', function (Builder $q) use ($f) {
                $like = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('business_name', 'like', $like)
                    ->orWhere('email', 'like', $like)->orWhere('mobile', 'like', $like));
            })
            ->when(array_key_exists($f['status'], AdminPresenter::STATUS), fn ($q) => $q->where('status', $f['status']))
            ->when($f['group'] === 'winners', fn ($q) => $q->whereNotNull('slot_number'))
            ->when($f['group'] === 'waitlist', fn ($q) => $q->whereNotNull('waitlist_number'))
            ->when(array_key_exists($f['type'], BusinessTypes::ALL), fn ($q) => $q->where('business_type', $f['type']))
            ->orderByRaw('slot_number is null, slot_number, waitlist_number, id');
    }

    /** The answers with their question labels. */
    private function answers(Supplier $supplier): array
    {
        $stored = $supplier->websiteAnswer?->answers;
        if (! $stored) {
            return [];
        }

        // The questions this supplier actually saw (saved when they confirmed), not today's wording.
        return collect(WebsiteQuestions::readable(WebsiteQuestions::forAnswer($supplier->websiteAnswer, $supplier), $stored))
            ->map(fn ($r) => ['label' => $r['label'], 'value' => $r['value']])->all();
    }

    /** Stops a spreadsheet from running a cell that starts with = + - or @. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
