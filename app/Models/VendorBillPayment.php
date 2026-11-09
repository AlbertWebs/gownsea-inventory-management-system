<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBillPayment extends Model
{
    protected $fillable = ['vendor_bill_id', 'recorded_by', 'idempotency_key', 'amount_minor', 'method', 'payment_reference', 'paid_on'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_on' => 'date'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }
}
