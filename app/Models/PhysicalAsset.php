<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhysicalAsset extends Model
{
    use HasFactory;

    protected $fillable = ['asset_code', 'product_id', 'home_location_id', 'current_location_id', 'custodian_user_id', 'custodian_customer_id', 'custody_type', 'status', 'condition', 'current_transfer_id', 'last_verified_at', 'acquisition_cost_minor', 'acquired_on', 'accumulated_depreciation_minor'];

    protected function casts(): array
    {
        return ['last_verified_at' => 'datetime', 'acquisition_cost_minor' => 'integer', 'accumulated_depreciation_minor' => 'integer', 'acquired_on' => 'date'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function homeLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'home_location_id');
    }

    public function customerCustodian(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'custodian_customer_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'current_transfer_id');
    }

    public function custodyEvents(): HasMany
    {
        return $this->hasMany(AssetCustodyEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'physical_asset_id')->orderBy('occurred_at')->orderBy('id');
    }

    public function vendorBills(): BelongsToMany
    {
        return $this->belongsToMany(VendorBill::class, 'vendor_bill_assets')->withPivot('acquisition_cost_minor')->withTimestamps();
    }

    public function hireItems(): HasMany
    {
        return $this->hasMany(HireItem::class, 'physical_asset_id');
    }
}
