<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CustomOrderDepositApplication extends Model
{
    protected $fillable = ['custom_order_id', 'payment_id', 'applied_by', 'idempotency_key', 'amount_minor', 'applied_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Deposit applications are immutable. Record a correcting transaction instead.'));
        static::deleting(fn () => throw new LogicException('Deposit applications cannot be deleted. Record a correcting transaction instead.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class, 'custom_order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
