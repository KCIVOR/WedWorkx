<?php

namespace App\Services;

use App\Enums\SupplierStatus;
use App\Models\Campaign;
use App\Models\StatusHistory;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place slots are assigned (decisions D1, D9; business rules 1 to 4).
 * A slot is never freed or re-assigned, and waitlisted suppliers are never promoted.
 */
class SlotService
{
    public const INVITED = 'invited';
    public const WAITLISTED = 'waitlisted';
    public const ALREADY_REGISTERED = 'already_registered';
    public const PENDING = 'pending_review';
    public const FULL = 'full';
    public const NOT_PENDING = 'not_pending';
    public const APPROVED = 'approved';

    /**
     * An admin picks a winner: assigns the next slot, issues the invite token and starts the
     * deadline now (not at registration). The caller sends the invitation email afterwards.
     * Never goes past the campaign's slot limit.
     *
     * @return array{outcome:string,supplier:Supplier}
     */
    public function approve(Supplier $supplier, string $actor = 'admin'): array
    {
        return DB::transaction(function () use ($supplier, $actor) {
            $campaign = Campaign::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);

            if (! in_array($locked->status, [SupplierStatus::PendingReview, SupplierStatus::Waitlisted], true)) {
                return ['outcome' => self::NOT_PENDING, 'supplier' => $locked];
            }

            $winners = Supplier::query()->where('campaign_id', $campaign->id)->whereNotNull('slot_number');
            if ($winners->count() >= $campaign->slot_limit) {
                return ['outcome' => self::FULL, 'supplier' => $locked];
            }

            $from = $locked->status;
            $now = now();
            $locked->forceFill([
                'status' => SupplierStatus::Invited,
                'slot_number' => ((int) $winners->max('slot_number')) + 1,
                'waitlist_number' => null,
                'invite_token' => Str::random(48),
                'invited_at' => $now,
                'invite_expires_at' => $now->copy()->addHours($campaign->invite_expiry_hours),
            ])->save();

            StatusHistory::create([
                'supplier_id' => $locked->id,
                'from_status' => $from,
                'to_status' => SupplierStatus::Invited,
                'actor' => $actor,
            ]);

            return ['outcome' => self::APPROVED, 'supplier' => $locked];
        });
    }

    /**
     * @param  array{name:string,email:string,mobile:string,business_name:string,business_type:string,location:string,social_link?:?string}  $data
     *                                                                                                                                      email is expected lower-cased and mobile normalized (see RegisterSupplierRequest)
     * @return array{outcome:string,supplier:?Supplier}
     */
    public function register(array $data, bool $autoInvite = true): array
    {
        try {
            return DB::transaction(function () use ($data, $autoInvite) {
                // Row lock: concurrent submissions queue here, so no two can take the same slot.
                $campaign = Campaign::query()->orderBy('id')->lockForUpdate()->firstOrFail();

                $duplicate = Supplier::query()
                    ->where('campaign_id', $campaign->id)
                    ->where(fn ($q) => $q->where('email', $data['email'])->orWhere('mobile', $data['mobile']))
                    ->exists();

                if ($duplicate) {
                    return ['outcome' => self::ALREADY_REGISTERED, 'supplier' => null];
                }

                $campaign->slots_taken += 1;
                $campaign->save();

                [$city, $province] = $this->splitLocation($data['location']);

                $attributes = [
                    'campaign_id' => $campaign->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'mobile' => $data['mobile'],
                    'business_name' => $data['business_name'],
                    'business_type' => $data['business_type'],
                    'city' => $city,
                    'province' => $province,
                    'social_link' => $data['social_link'] ?? null,
                    'extra_answers' => $data['extra_answers'] ?? null,
                ];

                if (! $autoInvite) {
                    // Approval guardrail: nobody gets a slot, a token or the Bot 2 link until an admin picks them.
                    $supplier = Supplier::create($attributes + ['status' => SupplierStatus::PendingReview]);
                    $outcome = self::PENDING;
                } elseif ($campaign->slots_taken <= $campaign->slot_limit) {
                    $now = now();
                    $supplier = Supplier::create($attributes + [
                        'status' => SupplierStatus::Invited,
                        'slot_number' => $campaign->slots_taken,
                        'invite_token' => Str::random(48),
                        'invited_at' => $now,
                        'invite_expires_at' => $now->copy()->addHours($campaign->invite_expiry_hours),
                    ]);
                    $outcome = self::INVITED;
                } else {
                    $supplier = Supplier::create($attributes + [
                        'status' => SupplierStatus::Waitlisted,
                        'waitlist_number' => $campaign->slots_taken - $campaign->slot_limit,
                    ]);
                    $outcome = self::WAITLISTED;
                }

                StatusHistory::create([
                    'supplier_id' => $supplier->id,
                    'from_status' => null,
                    'to_status' => $supplier->status,
                    'actor' => 'system',
                ]);

                return ['outcome' => $outcome, 'supplier' => $supplier];
            });
        } catch (QueryException $e) {
            // Safety net: the unique indexes caught a duplicate the check above did not see.
            // The transaction rolled back, so no slot was used.
            if (($e->errorInfo[0] ?? null) === '23000' && str_contains($e->getMessage(), 'suppliers_campaign_id_')) {
                return ['outcome' => self::ALREADY_REGISTERED, 'supplier' => null];
            }
            throw $e;
        }
    }

    /**
     * The chat asks one question: "Which city or province". "Tagaytay City, Cavite" becomes
     * city + province; a single value fills both columns (both are required).
     *
     * @return array{0:string,1:string}
     */
    private function splitLocation(string $location): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $location)), fn ($p) => $p !== ''));

        if (count($parts) >= 2) {
            return [$parts[0], implode(', ', array_slice($parts, 1))];
        }

        return [$location, $location];
    }
}
