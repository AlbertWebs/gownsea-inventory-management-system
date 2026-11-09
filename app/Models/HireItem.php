<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HireItem extends Model
{
    use HasFactory;

    protected $fillable = ['hire_id', 'product_id', 'physical_asset_id', 'product_name_snapshot', 'classification_code_snapshot', 'qualification_snapshot', 'quantity', 'returned_quantity', 'unit_rate_minor', 'penalty_assessed_minor', 'penalty_policy_snapshot', 'returned_at', 'return_condition', 'status'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime', 'penalty_policy_snapshot' => 'array'];
    }

    public function hire(): BelongsTo
    {
        return $this->belongsTo(Hire::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PhysicalAsset::class, 'physical_asset_id');
    }

    public function bulkReturns(): HasMany
    {
        return $this->hasMany(BulkHireReturn::class);
    }
}
