<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One conversation between a supplier (in the website chat) and the Hermes agent, relayed by the system. */
class AgentChat extends Model
{
    protected $fillable = ['public_id', 'bot', 'supplier_id', 'status', 'answers', 'widget', 'waiting_since'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'waiting_since' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentChatMessage::class)->orderBy('id');
    }
}
