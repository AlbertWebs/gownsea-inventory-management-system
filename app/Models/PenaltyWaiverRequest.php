<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenaltyWaiverRequest extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'hire_id', 'location_id', 'requested_by', 'approved_by', 'amount_minor', 'reason', 'status', 'decision_reason', 'decided_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'decided_at' => 'datetime'];
    }

    public function hire(): BelongsTo
    {
        return $this->belongsTo(Hire::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
