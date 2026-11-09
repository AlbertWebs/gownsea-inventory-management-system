<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quotation extends Model
{
    protected $fillable = ['reference', 'lead_id', 'customer_id', 'location_id', 'created_by', 'approved_by', 'revision', 'status', 'approval_status', 'approved_at', 'approval_reason', 'valid_until', 'subtotal_minor', 'tax_minor', 'total_minor', 'notes'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'approved_at' => 'datetime', 'revision' => 'integer', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(QuotationAttachment::class);
    }

    public function currentLines(): HasMany
    {
        return $this->lines()->where('revision', $this->revision);
    }

    public function convertedCustomOrder(): HasOne
    {
        return $this->hasOne(CustomOrder::class, 'origin_quotation_id');
    }
}
