<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class FinancialJournalEntry extends Model
{
    protected $fillable = ['reference', 'source_type', 'source_id', 'source_reference', 'entry_type', 'location_id', 'posted_by', 'reversal_of_id', 'reversal_reason', 'occurred_on', 'description', 'debit_total_minor', 'credit_total_minor', 'posted_at'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'debit_total_minor' => 'integer', 'credit_total_minor' => 'integer', 'occurred_on' => 'date', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted journal entries are immutable. Post a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Posted journal entries cannot be deleted. Post a reversal instead.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinancialJournalLine::class)->orderBy('line_number');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
