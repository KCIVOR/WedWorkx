<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'supplier_id', 'hitpay_request_id', 'amount', 'checkout_url', 'status',
        'started_at', 'expires_at', 'paid_at', 'paid_late', 'webhook_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'paid_late' => 'boolean',
            'webhook_payload' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
