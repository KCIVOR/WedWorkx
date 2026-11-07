<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per admin action. `detail` holds names and counts only, never a secret value. */
class AuditLog extends Model
{
    protected $table = 'admin_audit_logs';

    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'detail', 'ip'];

    protected function casts(): array
    {
        return ['detail' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, array $detail = [], ?int $userId = null): void
    {
        $request = request();

        static::create([
            'user_id' => $userId ?? $request?->user()?->id,
            'action' => $action,
            'detail' => $detail ?: null,
            'ip' => $request?->ip(),
        ]);
    }
}
