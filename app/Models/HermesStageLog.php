<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HermesStageLog extends Model
{
    protected $table = 'hermes_stage_log';

    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'stage', 'note'];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
