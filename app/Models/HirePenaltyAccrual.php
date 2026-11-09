<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HirePenaltyAccrual extends Model
{
    protected $fillable = ['reference', 'hire_id', 'location_id', 'business_configuration_id', 'posted_by', 'amount_minor', 'occurred_on', 'recognition_basis'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_on' => 'date'];
    }

    public function hire(): BelongsTo
    {
        return $this->belongsTo(Hire::class);
    }
}
