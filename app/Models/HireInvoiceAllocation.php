<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HireInvoiceAllocation extends Model
{
    protected $fillable = ['hire_invoice_id', 'source_type', 'source_id', 'kind', 'amount_minor', 'allocated_on', 'recorded_by'];

    protected function casts(): array
    {
        return ['allocated_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice allocations are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice allocations cannot be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(HireInvoice::class, 'hire_invoice_id');
    }
}
