<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'approval_key', 'cancellation_key', 'location_id', 'started_by', 'approved_by', 'cancelled_by', 'status', 'expected_count', 'counted_count', 'notes', 'approval_reason', 'cancellation_reason', 'unexpected_codes', 'approved_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'unexpected_codes' => 'array'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class);
    }
}
