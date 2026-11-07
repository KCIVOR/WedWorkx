<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteAnswer extends Model
{
    protected $fillable = ['supplier_id', 'answers', 'question_snapshot', 'submitted_at', 'locked_at'];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'question_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
