<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\PhysicalAsset;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalAsset>
 */
class PhysicalAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_code' => 'GWN-'.strtoupper($this->faker->unique()->bothify('??#####')),
            'product_id' => Product::factory(),
            'home_location_id' => Location::factory(),
            'current_location_id' => fn (array $attributes): int => $attributes['home_location_id'],
            'custody_type' => 'branch',
            'status' => 'available',
            'condition' => 'good',
            'last_verified_at' => now(),
        ];
    }
}
