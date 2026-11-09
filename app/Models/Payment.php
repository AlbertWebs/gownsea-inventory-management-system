<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Payment extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'customer_id', 'hire_id', 'custom_order_id', 'bulk_sale_id', 'location_id', 'recorded_by', 'amount_minor', 'currency', 'method', 'purpose', 'status', 'provider_reference', 'received_at'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recorded receipts are immutable. Record a refund or correction instead.'));
        static::deleting(fn () => throw new LogicException('Recorded receipts cannot be deleted. Record a refund or correction instead.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function hire(): BelongsTo
    {
        return $this->belongsTo(Hire::class);
    }

    public function customOrder(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class);
    }

    public function bulkSale(): BelongsTo
    {
        return $this->belongsTo(BulkSale::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function hireDepositApplications(): HasMany
    {
        return $this->hasMany(HireDepositApplication::class);
    }
}
