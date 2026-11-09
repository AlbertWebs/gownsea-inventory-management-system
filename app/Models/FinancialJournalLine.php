<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FinancialJournalLine extends Model
{
    protected $fillable = ['financial_journal_entry_id', 'chart_account_id', 'location_id', 'line_number', 'debit_minor', 'credit_minor', 'description'];

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'debit_minor' => 'integer', 'credit_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted journal lines are immutable. Post a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Posted journal lines cannot be deleted. Post a reversal instead.'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FinancialJournalEntry::class, 'financial_journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'chart_account_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
