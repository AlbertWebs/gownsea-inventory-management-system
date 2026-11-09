<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    protected $fillable = ['quotation_id', 'revision', 'description', 'fulfillment_type', 'product_id', 'quantity', 'unit_price_minor', 'line_total_minor'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
