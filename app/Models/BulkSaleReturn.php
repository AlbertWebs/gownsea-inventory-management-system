<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BulkSaleReturn extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'request_hash', 'bulk_sale_id', 'payment_refund_id', 'recorded_by', 'total_minor', 'reason', 'returned_at'];

    protected function casts(): array
    {
        return ['total_minor' => 'integer', 'returned_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(BulkSale::class, 'bulk_sale_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class, 'payment_refund_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BulkSaleReturnLine::class);
    }
}
