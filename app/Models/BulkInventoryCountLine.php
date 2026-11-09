<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkInventoryCountLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['bulk_inventory_count_id', 'product_id', 'expected_on_hand', 'expected_reserved', 'expected_pending_inspection', 'expected_damaged', 'counted_on_hand'];

    protected function casts(): array
    {
        return ['expected_on_hand' => 'integer', 'expected_reserved' => 'integer', 'expected_pending_inspection' => 'integer', 'expected_damaged' => 'integer', 'counted_on_hand' => 'integer'];
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(BulkInventoryCount::class, 'bulk_inventory_count_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
