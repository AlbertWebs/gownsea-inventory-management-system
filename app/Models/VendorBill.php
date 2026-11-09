<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorBill extends Model
{
    protected $fillable = ['reference', 'vendor_name', 'invoice_number', 'location_id', 'stock_movement_id', 'created_by', 'expense_account_code', 'amount_minor', 'invoice_date', 'due_date', 'description'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'invoice_date' => 'date', 'due_date' => 'date'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorBillPayment::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(PhysicalAsset::class, 'vendor_bill_assets')->withPivot('acquisition_cost_minor')->withTimestamps();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
