<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PaymentRefund extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'payment_id', 'location_id', 'recorded_by', 'amount_minor', 'method', 'provider_reference', 'reason', 'refunded_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'refunded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recorded refunds are immutable. Record a correction instead.'));
        static::deleting(fn () => throw new LogicException('Recorded refunds cannot be deleted. Record a correction instead.'));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function bulkSaleReturn(): HasOne
    {
        return $this->hasOne(BulkSaleReturn::class);
    }
}
