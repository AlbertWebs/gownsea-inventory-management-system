<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HireDepositApplication extends Model
{
    protected $fillable = ['hire_id', 'payment_id', 'applied_by', 'reference', 'idempotency_key', 'amount_minor', 'applied_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Deposit applications are immutable. Record a correcting transaction instead.'));
        static::deleting(fn () => throw new LogicException('Deposit applications cannot be deleted. Record a correcting transaction instead.'));
    }

    public function hire(): BelongsTo
    {
        return $this->belongsTo(Hire::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
