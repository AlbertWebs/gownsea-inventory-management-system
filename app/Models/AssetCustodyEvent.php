<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCustodyEvent extends Model
{
    protected $fillable = ['physical_asset_id', 'actor_id', 'from_location_id', 'to_location_id', 'customer_id', 'user_custodian_id', 'stock_transfer_id', 'event_type', 'from_status', 'to_status', 'from_custody_type', 'to_custody_type', 'idempotency_key', 'notes', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PhysicalAsset::class, 'physical_asset_id');
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
}
