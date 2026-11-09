<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartAccount extends Model
{
    protected $fillable = ['code', 'name', 'account_type', 'normal_balance', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(FinancialJournalLine::class);
    }
}
