<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['agent_chat_id', 'role', 'body'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
