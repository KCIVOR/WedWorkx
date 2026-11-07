<?php

namespace App\Models;

use App\Enums\SupplierStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusHistory extends Model
{
    protected $table = 'status_history';

    public const UPDATED_AT = null;

    protected $fillable = ['supplier_id', 'from_status', 'to_status', 'actor'];

    protected function casts(): array
    {
        return [
            'from_status' => SupplierStatus::class,
            'to_status' => SupplierStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
