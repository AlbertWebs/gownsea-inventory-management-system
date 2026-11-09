<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkSaleReturnLine extends Model
{
    protected $fillable = ['bulk_sale_return_id', 'product_id', 'quantity', 'unit_price_minor', 'line_total_minor', 'cogs_minor', 'condition'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer', 'cogs_minor' => 'integer'];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(BulkSaleReturn::class, 'bulk_sale_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
