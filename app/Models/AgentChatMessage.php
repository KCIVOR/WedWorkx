<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AgentChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['agent_chat_id', 'role', 'body'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** MySQL TIMESTAMP is converted to the connection timezone when read. Its epoch is unambiguous. */
    public function scopeWithActualTime(Builder $query): Builder
    {
        return $query->addSelect($this->qualifyColumn('*'))
            ->selectRaw('UNIX_TIMESTAMP('.$this->qualifyColumn('created_at').') AS sent_at_epoch');
    }

    public function sentAtIso(): string
    {
        $epoch = $this->getAttribute('sent_at_epoch');
        if ($epoch === null) {
            // A newly inserted model does not yet contain the database-generated timestamp.
            $epoch = $this->getConnection()->table($this->getTable())->where($this->getKeyName(), $this->getKey())
                ->selectRaw('UNIX_TIMESTAMP(created_at) AS epoch')->value('epoch');
        }
        if ($epoch === null) throw new \RuntimeException('The chat message has no saved timestamp.');

        return Carbon::createFromTimestamp((int) $epoch, 'UTC')->toIso8601String();
    }
}
