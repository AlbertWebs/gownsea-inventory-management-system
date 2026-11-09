<?php

namespace Database\Factories;

use App\Models\Hire;
use App\Models\HireItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HireItem>
 */
class HireItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hire_id' => Hire::factory(),
            'product_id' => Product::factory(),
            'physical_asset_id' => null,
            'product_name_snapshot' => 'Bachelor Gown',
            'classification_code_snapshot' => 'BACHELORS',
            'qualification_snapshot' => 'BACHELORS',
            'quantity' => 1,
            'returned_quantity' => 0,
            'unit_rate_minor' => 150000,
            'penalty_assessed_minor' => 0,
            'status' => 'reserved',
        ];
    }
}
