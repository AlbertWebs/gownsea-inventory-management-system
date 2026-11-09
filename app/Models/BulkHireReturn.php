<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkHireReturn extends Model
{
    protected $fillable = ['hire_item_id', 'location_id', 'actor_id', 'quantity', 'inspected_quantity', 'penalty_assessed_minor', 'condition', 'idempotency_key', 'notes', 'returned_at'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'inspected_quantity' => 'integer', 'penalty_assessed_minor' => 'integer', 'returned_at' => 'datetime'];
    }

    public function hireItem(): BelongsTo
    {
        return $this->belongsTo(HireItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
