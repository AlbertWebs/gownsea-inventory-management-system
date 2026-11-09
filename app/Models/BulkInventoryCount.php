<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkInventoryCount extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'approval_key', 'cancellation_key', 'location_id', 'started_by', 'approved_by', 'cancelled_by', 'status', 'expected_count', 'counted_count', 'notes', 'approval_reason', 'cancellation_reason', 'approved_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['expected_count' => 'integer', 'counted_count' => 'integer', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
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
        return $this->hasMany(BulkInventoryCountLine::class);
    }
}
