<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CustomOrderInvoice extends Model
{
    protected $fillable = ['number', 'custom_order_id', 'customer_id', 'location_id', 'currency', 'total_minor', 'subtotal_minor', 'tax_minor', 'tax_configuration_id', 'issued_on', 'due_on', 'posted_at'];

    protected function casts(): array
    {
        return ['total_minor' => 'integer', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'tax_configuration_id' => 'integer', 'issued_on' => 'date', 'due_on' => 'date', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued custom order invoices are immutable.'));
        static::deleting(fn () => throw new LogicException('Issued custom order invoices cannot be deleted.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class, 'custom_order_id');
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
        return $this->hasMany(CustomOrderInvoiceLine::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomOrderInvoiceAllocation::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'invoice_id')->where('invoice_type', 'custom_order');
    }
}
