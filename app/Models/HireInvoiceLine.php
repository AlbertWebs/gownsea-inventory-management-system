<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HireInvoiceLine extends Model
{
    protected $fillable = ['hire_invoice_id', 'hire_item_id', 'description', 'quantity', 'unit_rate_minor', 'line_total_minor', 'net_minor', 'tax_minor', 'tax_code', 'tax_treatment', 'tax_rate', 'tax_account_code', 'revenue_account_code'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_rate_minor' => 'integer', 'line_total_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued invoice lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Issued invoice lines cannot be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(HireInvoice::class, 'hire_invoice_id');
    }
}
