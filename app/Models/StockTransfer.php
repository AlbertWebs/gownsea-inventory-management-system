<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'source_location_id', 'destination_location_id', 'requested_by', 'approved_by', 'dispatched_by', 'received_by', 'status', 'carrier', 'waybill', 'notes', 'dispatched_at', 'received_at'];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(StockTransferAsset::class);
    }
}
