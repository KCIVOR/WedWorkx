<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'name', 'slot_limit', 'slots_taken', 'invite_expiry_hours', 'grace_minutes', 'price',
    ];

    protected function casts(): array
    {
        return [
            'slot_limit' => 'integer',
            'slots_taken' => 'integer',
            'invite_expiry_hours' => 'integer',
            'grace_minutes' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
