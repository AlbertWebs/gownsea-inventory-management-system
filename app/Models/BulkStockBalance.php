<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkStockBalance extends Model
{
    protected $fillable = ['product_id', 'location_id', 'on_hand', 'inventory_value_minor', 'valuation_complete', 'reserved', 'pending_inspection', 'damaged'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'inventory_value_minor' => 'integer', 'valuation_complete' => 'boolean', 'reserved' => 'integer', 'pending_inspection' => 'integer', 'damaged' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function getAvailableAttribute(): int
    {
        return max(0, $this->on_hand - $this->reserved - $this->pending_inspection - $this->damaged);
    }
}
