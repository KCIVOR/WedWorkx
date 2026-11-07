<?php

namespace App\Models;

use App\Enums\SupplierStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Supplier extends Model
{
    protected $fillable = [
        'campaign_id', 'slot_number', 'waitlist_number', 'name', 'email', 'mobile',
        'business_name', 'business_type', 'city', 'province', 'social_link', 'extra_answers',
        'status', 'invite_token', 'invited_at', 'invite_expires_at',
    ];

    protected $hidden = ['invite_token'];

    protected function casts(): array
    {
        return [
            'status' => SupplierStatus::class,
            'extra_answers' => 'array',
            'invited_at' => 'datetime',
            'invite_expires_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function websiteAnswer(): HasOne
    {
        return $this->hasOne(WebsiteAnswer::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(StatusHistory::class);
    }
}
