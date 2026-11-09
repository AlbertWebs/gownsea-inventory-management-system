<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomOrder extends Model
{
    protected $fillable = ['reference', 'location_id', 'customer_id', 'institution_id', 'created_by', 'owner_id', 'origin_quotation_id', 'status', 'item_type', 'size', 'color', 'quantity', 'measurements', 'design_specs', 'special_requirements', 'agreed_total_minor', 'deposit_required_minor', 'order_date', 'expected_completion_date', 'priority', 'delivery_method', 'delivery_address', 'courier', 'tracking_number', 'dispatched_at', 'delivered_at', 'completed_at', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'measurements' => 'array', 'agreed_total_minor' => 'integer', 'deposit_required_minor' => 'integer', 'order_date' => 'date', 'expected_completion_date' => 'date', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomOrderEvent::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CustomOrderTask::class);
    }

    public function fittings(): HasMany
    {
        return $this->hasMany(CustomOrderFitting::class);
    }

    public function shipmentEvents(): HasMany
    {
        return $this->hasMany(CustomOrderShipmentEvent::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(CustomOrderCost::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function depositApplications(): HasMany
    {
        return $this->hasMany(CustomOrderDepositApplication::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(CustomOrderInvoice::class);
    }

    public function originQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'origin_quotation_id');
    }
}
