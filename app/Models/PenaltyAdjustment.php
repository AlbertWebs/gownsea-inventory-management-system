<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenaltyAdjustment extends Model
{
    protected $fillable = ['idempotency_key', 'hire_id', 'location_id', 'created_by', 'amount_minor', 'kind', 'reason', 'adjusted_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'adjusted_at' => 'datetime'];
    }

    public function hire(): BelongsTo
    {
        return $this->belongsTo(Hire::class);
    }
}
