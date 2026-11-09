<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomOrderCost extends Model
{
    protected $fillable = ['custom_order_id', 'recorded_by', 'idempotency_key', 'category', 'description', 'amount_minor', 'incurred_on'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'incurred_on' => 'date'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class, 'custom_order_id');
    }
}
