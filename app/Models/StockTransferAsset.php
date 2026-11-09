<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferAsset extends Model
{
    protected $fillable = ['stock_transfer_id', 'physical_asset_id', 'dispatched_by', 'received_by', 'receipt_condition', 'dispatched_at', 'received_at'];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PhysicalAsset::class, 'physical_asset_id');
    }
}
