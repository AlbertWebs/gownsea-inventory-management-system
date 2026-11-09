<?php

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'type' => $this->faker->randomElement(['university', 'college', 'tvet', 'school']),
            'email' => $this->faker->safeEmail(),
            'phone' => '+2547'.$this->faker->numerify('########'),
            'county' => $this->faker->city(),
            'is_active' => true,
        ];
    }
}
