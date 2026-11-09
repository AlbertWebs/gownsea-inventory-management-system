<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.strtoupper($this->faker->unique()->bothify('??####')),
            'name' => $this->faker->randomElement(['Bachelor Gown', 'Master Gown', 'Graduation Cap', 'Academic Hood']),
            'category' => 'gown',
            'tracking_type' => 'serialized',
            'academic_classification_id' => null,
            'qualification_level' => null,
            'institution_id' => null,
            'size' => $this->faker->randomElement(['S', 'M', 'L', 'XL']),
            'color' => $this->faker->safeColorName(),
            'purchase_cost_minor' => 0,
            'sale_price_minor' => 0,
            'hire_rate_minor' => 150000,
            'reorder_threshold' => 0,
            'is_active' => true,
        ];
    }
}
