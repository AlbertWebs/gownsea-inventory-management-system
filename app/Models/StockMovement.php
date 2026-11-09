<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockMovement extends Model
{
    protected $fillable = ['product_id', 'physical_asset_id', 'from_location_id', 'to_location_id', 'actor_id', 'stock_transfer_id', 'movement_type', 'from_status', 'to_status', 'quantity', 'cost_minor', 'valuation_total_minor', 'idempotency_key', 'notes', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'quantity' => 'integer', 'cost_minor' => 'integer', 'valuation_total_minor' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PhysicalAsset::class, 'physical_asset_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function vendorBill(): HasOne
    {
        return $this->hasOne(VendorBill::class);
    }
}
