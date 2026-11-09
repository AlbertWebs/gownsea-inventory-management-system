<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['sku', 'name', 'category', 'tracking_type', 'academic_classification_id', 'qualification_level', 'institution_id', 'size', 'color', 'faculty', 'description', 'image_path', 'purchase_cost_minor', 'sale_price_minor', 'hire_rate_minor', 'reorder_threshold', 'useful_life_months', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'purchase_cost_minor' => 'integer', 'useful_life_months' => 'integer'];
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(AcademicClassification::class, 'academic_classification_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(PhysicalAsset::class);
    }

    public function bulkBalances(): HasMany
    {
        return $this->hasMany(BulkStockBalance::class);
    }
}
