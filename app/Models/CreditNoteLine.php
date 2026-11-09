<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CreditNoteLine extends Model
{
    protected $fillable = [
        'credit_note_id', 'description', 'quantity', 'total_minor', 'net_minor', 'tax_minor',
        'tax_code', 'tax_treatment', 'tax_rate', 'tax_account_code', 'revenue_account_code',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'total_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued credit note lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Issued credit note lines cannot be deleted.'));
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
