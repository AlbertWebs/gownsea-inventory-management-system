<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'LOC-'.strtoupper($this->faker->unique()->bothify('??###')),
            'name' => $this->faker->company().' Branch',
            'type' => 'branch',
            'address' => $this->faker->address(),
            'timezone' => 'Africa/Nairobi',
            'is_active' => true,
        ];
    }
}
