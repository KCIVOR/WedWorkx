<?php

namespace App\Services;

use App\Enums\SupplierStatus;
use App\Exceptions\HermesException;
use App\Mail\WebsiteLiveMail;
use App\Models\HermesStageLog;
use App\Models\Order;
use App\Models\StatusHistory;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everything Hermes may do (business rules 11 and 12): see paid-or-later orders and write
 * the stage number, `building` and `delivered`. Nothing else.
 */
class HermesOrderService
{
    public const LAST_STAGE = 6;

    public function __construct(private EmailService $emails)
    {
    }

    /** Orders whose supplier is paid or later. Anything unpaid is simply not in this query. */
    public function visible(): Builder
    {
        return Order::query()->whereHas('supplier', fn ($q) => $q->whereIn('status', config('hermes.visible_statuses')));
    }

    public function find(string $reference): Order
    {
        $order = $this->visible()->where('reference', $reference)->with('supplier.campaign')->first();

        if (! $order) {
            throw new HermesException('No order with that reference.', 'order_not_found', 404);
        }

        return $order;
    }

    /** Sets the production stage. The same stage or the next one only; the first stage moves the supplier to `building`. */
    public function setStage(string $reference, int $stage, ?string $note): Order
    {
        $this->find($reference); // 404 for unknown or unpaid orders before taking any lock

        return DB::transaction(function () use ($reference, $stage, $note) {
            $order = Order::where('reference', $reference)->lockForUpdate()->firstOrFail();
            $supplier = Supplier::whereKey($order->supplier_id)->lockForUpdate()->firstOrFail();

            if (! in_array($supplier->status->value, config('hermes.visible_statuses'), true)) {
                throw new HermesException('No order with that reference.', 'order_not_found', 404);
            }
            if ($supplier->status === SupplierStatus::Delivered) {
                throw new HermesException('This order is already delivered, so its stage can no longer change.', 'already_delivered');
            }

            $current = $order->current_stage;
            if ($stage !== $current && $stage !== $current + 1) {
                throw new HermesException(
                    $stage < $current
                        ? "Stage cannot go backwards (current stage is {$current}, you sent {$stage})."
                        : "Stages go one at a time (current stage is {$current}, the next is ".($current + 1).", you sent {$stage}).",
                    $stage < $current ? 'stage_backwards' : 'stage_skipped',
                );
            }

            $order->update(['current_stage' => $stage]);
            HermesStageLog::create(['order_id' => $order->id, 'stage' => $stage, 'note' => $note]);

            if ($supplier->status === SupplierStatus::Paid) {
                $supplier->update(['status' => SupplierStatus::Building]);
                StatusHistory::create([
                    'supplier_id' => $supplier->id,
                    'from_status' => SupplierStatus::Paid,
                    'to_status' => SupplierStatus::Building,
                    'actor' => 'hermes',
                ]);
            }

            return $order->fresh(['supplier.campaign']);
        });
    }

    /** Marks the website delivered. Only from stage 6, only once. */
    public function deliver(string $reference, string $websiteUrl): Order
    {
        $this->find($reference);

        $delivered = DB::transaction(function () use ($reference, $websiteUrl) {
            $order = Order::where('reference', $reference)->lockForUpdate()->firstOrFail();
            $supplier = Supplier::whereKey($order->supplier_id)->lockForUpdate()->firstOrFail();

            if (! in_array($supplier->status->value, config('hermes.visible_statuses'), true)) {
                throw new HermesException('No order with that reference.', 'order_not_found', 404);
            }
            if ($supplier->status === SupplierStatus::Delivered) {
                throw new HermesException('This order is already delivered.', 'already_delivered');
            }
            if ($order->current_stage !== self::LAST_STAGE || $supplier->status !== SupplierStatus::Building) {
                throw new HermesException(
                    'Delivery is only allowed at stage '.self::LAST_STAGE." (current stage is {$order->current_stage}).",
                    'not_ready_to_deliver',
                );
            }

            $order->update(['website_url' => $websiteUrl, 'delivered_at' => now()]);
            $supplier->update(['status' => SupplierStatus::Delivered]);
            StatusHistory::create([
                'supplier_id' => $supplier->id,
                'from_status' => SupplierStatus::Building,
                'to_status' => SupplierStatus::Delivered,
                'actor' => 'hermes',
            ]);

            return $order->fresh(['supplier.campaign']);
        });

        // After the commit. Sent once (email_logs), and a mail failure never breaks the delivery.
        $this->emails->send($delivered->supplier, 'live', new WebsiteLiveMail($delivered));

        return $delivered;
    }
}
