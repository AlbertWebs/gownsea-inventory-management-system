<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomOrderEvent extends Model
{
    protected $fillable = ['custom_order_id', 'actor_id', 'event_type', 'reason', 'before_state', 'after_state', 'occurred_at'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'occurred_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class, 'custom_order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
