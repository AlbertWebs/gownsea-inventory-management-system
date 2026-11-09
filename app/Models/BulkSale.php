<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BulkSale extends Model
{
    protected $fillable = ['reference', 'idempotency_key', 'request_hash', 'location_id', 'customer_id', 'sold_by', 'total_minor', 'status', 'sold_at', 'notes'];

    protected function casts(): array
    {
        return ['total_minor' => 'integer', 'sold_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BulkSaleLine::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(BulkSaleReturn::class);
    }
}
