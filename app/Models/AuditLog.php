<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = ['actor_id', 'location_id', 'action', 'subject_type', 'subject_id', 'before_state', 'after_state', 'reason', 'ip_address', 'user_agent', 'occurred_at'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'occurred_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
