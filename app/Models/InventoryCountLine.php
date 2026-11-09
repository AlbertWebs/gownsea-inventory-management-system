<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['inventory_count_id', 'physical_asset_id', 'expected_status', 'was_counted', 'counted_at'];

    protected function casts(): array
    {
        return ['was_counted' => 'boolean', 'counted_at' => 'datetime'];
    }

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PhysicalAsset::class, 'physical_asset_id');
    }
}
