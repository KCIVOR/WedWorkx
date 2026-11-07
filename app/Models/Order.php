<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'supplier_id', 'reference', 'current_stage', 'website_url', 'paid_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'current_stage' => 'integer',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stageLogs(): HasMany
    {
        return $this->hasMany(HermesStageLog::class);
    }
}
