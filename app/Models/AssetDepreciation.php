<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciation extends Model
{
    protected $fillable = ['physical_asset_id', 'period', 'amount_minor', 'accumulated_minor', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'accumulated_minor' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PhysicalAsset::class, 'physical_asset_id');
    }
}
