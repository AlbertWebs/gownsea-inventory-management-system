<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CreditNote extends Model
{
    protected $fillable = [
        'number', 'payment_refund_id', 'invoice_type', 'invoice_id', 'invoice_number', 'customer_id',
        'location_id', 'currency', 'total_minor', 'subtotal_minor', 'tax_minor', 'issued_on', 'reason', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer', 'total_minor' => 'integer', 'subtotal_minor' => 'integer',
            'tax_minor' => 'integer', 'issued_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued credit notes are immutable.'));
        static::deleting(fn () => throw new LogicException('Issued credit notes cannot be deleted.'));
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class, 'payment_refund_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}
