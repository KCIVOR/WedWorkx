<?php

namespace App\Services;

use App\Enums\SupplierStatus;
use App\Models\StatusHistory;
use App\Models\Supplier;
use App\Models\WebsiteAnswer;
use App\Support\WebsiteQuestions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Chatbot 2 data: who may edit, saving the confirmed answers, and the status change (rule 10). */
class WebsiteDetailsService
{
    public function __construct(private InviteExpiryService $expiry)
    {
    }

    /** Answers and uploads can change only while the invite is valid, before payment, and before they are locked. */
    public function editable(Supplier $supplier): bool
    {
        if (! in_array($supplier->status, [SupplierStatus::Invited, SupplierStatus::DetailsSubmitted], true)) {
            return false;
        }

        return $this->expiry->inviteIsValid($supplier)
            && ! $supplier->websiteAnswer()->whereNotNull('locked_at')->exists();
    }

    public function authorizeEdit(Supplier $supplier): void
    {
        if (! $this->editable($supplier)) {
            throw new HttpException(403, 'These details can no longer be changed.');
        }
    }

    /** Confirm: store the answers, mark them submitted and move invited -> details_submitted. */
    public function confirm(Supplier $supplier, array $answers): void
    {
        DB::transaction(function () use ($supplier, $answers) {
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $this->authorizeEdit($locked);

            WebsiteAnswer::updateOrCreate(
                ['supplier_id' => $locked->id],
                ['answers' => $answers, 'question_snapshot' => WebsiteQuestions::snapshot($locked), 'submitted_at' => now()],
            );

            if ($locked->status === SupplierStatus::Invited) {
                $locked->update(['status' => SupplierStatus::DetailsSubmitted]);
                StatusHistory::create([
                    'supplier_id' => $locked->id,
                    'from_status' => SupplierStatus::Invited,
                    'to_status' => SupplierStatus::DetailsSubmitted,
                    'actor' => 'supplier',
                ]);
            }
        });
    }
}
