<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkSaleLine extends Model
{
    protected $fillable = ['bulk_sale_id', 'product_id', 'product_name_snapshot', 'sku_snapshot', 'quantity', 'unit_price_minor', 'line_total_minor', 'cogs_minor'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer', 'cogs_minor' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(BulkSale::class, 'bulk_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
